<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use LogicException;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Exceptions\RepackViolationsException;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\ScheduleRepacker;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageState;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The golden-output matrix: every pinned case, defined once.
 *
 * all() maps a fixture path (relative to tests/Fixtures/golden/) to a
 * closure producing that file's canonical text. The golden test compares
 * each closure's text with the stored file, and writes the file instead
 * when regenerating (`composer golden-update`), so the cases and the
 * fixtures cannot drift apart.
 *
 * Everything here goes through the public API only. Each case builds its
 * own scheduler, engine and randomizer, so no case depends on the state
 * another one left behind.
 *
 * The cases under `examples/` are different in kind: each is the exact
 * standard output of one plain-text script in examples/, run in a PHP
 * process of its own. Running an example only proves it does not crash;
 * pinning what it prints catches one that prints a wrong or empty result.
 */
final class GoldenCases
{
    public const WIRE_SCHEDULE = 'wire/schedule.json';

    public const WIRE_STAGE_STATE = 'wire/stage-state.json';

    private const SEEDS = [1, 42, 1337];

    private const ROUND_ROBIN_SIZES = [2, 3, 4, 5, 6, 7, 8, 14, 17, 20];

    private const ROUND_ROBIN_LEGS = [1, 2, 3, 4];

    private const ELIMINATION_SIZES = [5, 8, 12];

    private const REPACK_SIZES = [12, 16, 24];

    /**
     * The scripts in examples/ that print plain text, by file name without
     * the extension. Each prints the same text on every run: none reads
     * the clock, the environment or an unseeded randomizer.
     *
     * The HTML examples (01 to 12 and index.php) are not pinned: they are
     * pages for a browser, and several print measured timings and memory
     * figures that differ on every run.
     */
    public const PLAIN_TEXT_EXAMPLES = [
        '13-swiss-stage-engine',
        '14-groups-to-knockout',
        '15-timeline-assignment',
        '16-backtracking-generation',
        '17-schedule-optimization',
        '18-stateless-web-flow',
        '19-repacking-a-season',
    ];

    /** Upper bound on driver-loop rounds, so a broken engine fails instead of hanging. */
    private const MAX_ENGINE_ROUNDS = 64;

    private const EXPLANATION = [
        '',
        'A difference from this file is a change to generated output. It must be',
        'explained in the changelog; regenerate with `composer golden-update`.',
    ];

    /**
     * @return array<string, Closure(): string> Fixture path relative to the golden directory => producer
     */
    public static function all(): array
    {
        $cases = [];

        foreach (['mirrored', 'repeated'] as $strategy) {
            foreach ([null, ...self::SEEDS] as $seed) {
                $cases['round-robin/' . $strategy . '-' . self::seedSlug($seed) . '.txt']
                    = static fn (): string => self::roundRobin($strategy, $seed);
            }
        }

        $cases['round-robin/constrained.txt'] = self::constrainedRoundRobin(...);
        $cases['swiss.txt'] = self::swiss(...);
        $cases['single-elimination.txt'] = self::singleElimination(...);
        $cases['double-elimination.txt'] = self::doubleElimination(...);
        $cases['repack/scenario.txt'] = self::repackScenario(...);
        $cases['repack/round-robin.txt'] = self::repackRoundRobin(...);
        $cases[self::WIRE_SCHEDULE] = static fn (): string => self::wireSchedule() . "\n";
        $cases[self::WIRE_STAGE_STATE] = static fn (): string => self::wireStageState() . "\n";

        foreach (self::PLAIN_TEXT_EXAMPLES as $example) {
            $cases[self::exampleFixture($example)] = static fn (): string => self::exampleOutput($example);
        }

        return $cases;
    }

    /**
     * The fixture path, relative to the golden directory, that pins an
     * example's output.
     *
     * @param string $example The script's file name without the extension
     */
    public static function exampleFixture(string $example): string
    {
        return 'examples/' . $example . '.txt';
    }

    /**
     * Everything an example script prints, byte for byte. Standard error
     * is folded into the text, so a diagnostic an example starts to emit
     * shows up as a difference from the fixture.
     *
     * @throws LogicException When the script cannot be run or exits with a failure status
     */
    private static function exampleOutput(string $example): string
    {
        $script = dirname(__DIR__, 2) . '/examples/' . $example . '.php';

        $captured = tempnam(sys_get_temp_dir(), 'example');
        if ($captured === false) {
            throw new LogicException('Could not create a temporary file in ' . sys_get_temp_dir());
        }

        try {
            // Both streams append to one file, so they interleave as they were written
            $process = proc_open(
                [PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=1', '-d', 'html_errors=0', $script],
                [1 => ['file', $captured, 'a'], 2 => ['file', $captured, 'a']],
                $pipes
            );
            if ($process === false) {
                throw new LogicException("Could not start PHP to run examples/{$example}.php");
            }

            $exitCode = proc_close($process);
            $output = (string) file_get_contents($captured);
        } finally {
            unlink($captured);
        }

        if ($exitCode !== 0) {
            throw new LogicException("examples/{$example}.php exited with code {$exitCode}:\n{$output}");
        }

        return $output;
    }

    /**
     * Participants with ids and labels "1".."n" in list order. Seeds are
     * the list position when requested, absent otherwise.
     *
     * @return list<Participant>
     */
    public static function field(int $count, bool $seeded = false): array
    {
        $participants = [];
        for ($i = 1; $i <= $count; ++$i) {
            $participants[] = new Participant((string) $i, (string) $i, $seeded ? $i : null);
        }

        return $participants;
    }

    /**
     * One strategy and seed across every field size and leg count.
     *
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException
     * @throws JsonException
     */
    private static function roundRobin(string $strategy, ?int $seed): string
    {
        $sections = [];
        foreach (self::ROUND_ROBIN_SIZES as $size) {
            foreach (self::ROUND_ROBIN_LEGS as $legs) {
                $schedule = (new RoundRobinScheduler(null, self::randomizer($seed)))->schedule(
                    self::field($size),
                    new RoundRobinOptions($legs, self::legStrategy($strategy))
                );
                $sections["n={$size} legs={$legs}"] = GoldenText::schedule($schedule);
            }
        }

        return GoldenText::document([
            "Round robin, {$strategy} legs, " . self::seedLabel($seed) . '.',
            'Participants are "1".."n" in list order. One section per field size and',
            'leg count; `meta:` is the schedule metadata; `R<round>: a-b c-d` lists',
            'the round\'s events in generated order, first-named participant first;',
            '`byes:` names who sits out each round.',
            ...self::EXPLANATION,
        ], $sections);
    }

    /**
     * The two generation paths beyond the plain circle method: a
     * constraint the first participant ordering violates (so only a
     * rotated retry succeeds) and constraints no rotation satisfies (so
     * only the backtracking search succeeds). Each case asserts its own
     * premise, so it cannot quietly degrade into a plain schedule.
     *
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException
     * @throws JsonException
     */
    private static function constrainedRoundRobin(): string
    {
        $sections = [];

        // Seed protection keeps seeds 1 and 2 apart for the first quarter
        // of 10 rounds; the unrotated circle order pairs them in round 2.
        $seeded = self::field(6, true);
        $options = new RoundRobinOptions(legs: 2);
        $protected = ConstraintSet::create()->add(new SeedProtectionConstraint(2, 0.25))->build();
        $retried = GoldenText::schedule((new RoundRobinScheduler($protected))->schedule($seeded, $options));
        if ($retried === GoldenText::schedule((new RoundRobinScheduler())->schedule($seeded, $options))) {
            throw new LogicException('The rotation-retry case no longer needs a retry: its first ordering satisfies the constraint.');
        }
        $sections['rotation retry: n=6 legs=2, seed protection (top 2, first 25% of rounds)'] = $retried;

        // Participant 1 must meet 2, 3, 4 in leg rounds 1, 2, 3: satisfiable,
        // but by no rotation of the circle method.
        $placement = self::placementConstraints(['1|2' => 1, '1|3' => 2, '1|4' => 3]);
        $sections['backtracking: n=4 legs=1, participant 1 meets 2, 3, 4 in rounds 1, 2, 3']
            = self::backtracked($placement, 4);

        // An odd field routes the search through its bye assignment.
        $oddPlacement = self::placementConstraints(['1|2' => 5, '1|3' => 4]);
        $sections['backtracking: n=5 legs=1, participant 1 meets 2 in round 5 and 3 in round 4']
            = self::backtracked($oddPlacement, 5);

        return GoldenText::document([
            'Round robin under constraints, unseeded.',
            'Participants are "1".."n" in list order, seeded by position. The first',
            'section succeeds only on a rotated retry of the participant order; the',
            'backtracking sections succeed only with RoundRobinOptions(backtracking: true).',
            '`meta:` is the schedule metadata.',
            ...self::EXPLANATION,
        ], $sections);
    }

    /**
     * @return list<string>
     *
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException
     * @throws JsonException
     */
    private static function backtracked(ConstraintSet $constraints, int $size): array
    {
        try {
            (new RoundRobinScheduler($constraints))->schedule(self::field($size, true));
        } catch (IncompleteScheduleException) {
            return GoldenText::schedule((new RoundRobinScheduler($constraints))->schedule(
                self::field($size, true),
                new RoundRobinOptions(backtracking: true)
            ));
        }

        throw new LogicException('The backtracking case no longer needs the search: greedy generation satisfies the constraints.');
    }

    /**
     * Pin pairings to a round: each `a|b` key (ids ascending) may only be
     * played in the round it maps to.
     *
     * @param array<string, int> $roundByPair
     */
    private static function placementConstraints(array $roundByPair): ConstraintSet
    {
        return ConstraintSet::create()->custom(static function (Event $event) use ($roundByPair): bool {
            $ids = array_map(
                static fn (Participant $participant): string => $participant->getId(),
                $event->getParticipants()
            );
            sort($ids);
            $required = $roundByPair[implode('|', $ids)] ?? null;

            return $required === null || $event->getRound()?->getNumber() === $required;
        }, 'Fixture Placement')->build();
    }

    /**
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException
     * @throws JsonException
     */
    private static function swiss(): string
    {
        $sections = [];
        foreach ([[8, 3], [9, 4]] as [$size, $rounds]) {
            foreach ([null, ...self::SEEDS] as $seed) {
                $schedule = (new SwissScheduler(null, self::randomizer($seed)))->schedule(
                    self::field($size),
                    new SwissOptions($rounds)
                );
                $sections["n={$size} rounds={$rounds} " . self::seedLabel($seed)]
                    = GoldenText::schedule($schedule);
            }
        }

        return GoldenText::document([
            'Swiss whole-schedule preset (SwissScheduler): no results recorded.',
            'Participants are "1".."n" in list order. `meta:` is the schedule',
            'metadata; `R<round>: a-b c-d` lists the round\'s events in generated',
            'order, first-named participant first; `byes:` names who sits out each',
            'round of an odd field.',
            ...self::EXPLANATION,
        ], $sections);
    }

    /**
     * @throws InvalidConfigurationException
     * @throws JsonException
     * @throws NoValidPairingException
     */
    private static function singleElimination(): string
    {
        $firstNamed = self::firstNamedWins(...);
        $lastNamed = self::lastNamedWins(...);

        $sections = [];
        foreach (self::ELIMINATION_SIZES as $size) {
            $sections["n={$size}, first-named wins"]
                = self::bracket(new SingleEliminationEngine(), $size, $firstNamed);
        }

        // Upsets are what separate the two path behaviours: with every
        // favourite winning, re-seeding reproduces the fixed bracket.
        $reseeded = new EliminationOptions(reseedEachRound: true);
        $sections['n=8, last-named wins'] = self::bracket(new SingleEliminationEngine(), 8, $lastNamed);
        $sections['n=8 reseedEachRound, first-named wins']
            = self::bracket(new SingleEliminationEngine($reseeded), 8, $firstNamed);
        $sections['n=8 reseedEachRound, last-named wins']
            = self::bracket(new SingleEliminationEngine($reseeded), 8, $lastNamed);
        $sections['n=12 reseedEachRound, last-named wins']
            = self::bracket(new SingleEliminationEngine($reseeded), 12, $lastNamed);

        $sections['n=5 legsPerTie=2, the first-named of leg 1 wins both legs'] = self::bracket(
            new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2)),
            5,
            self::tieFirstNamedWins(...)
        );

        return GoldenText::document([
            'Single elimination, driven round by round to completion.',
            ...self::bracketHeader(),
        ], $sections);
    }

    /**
     * @throws InvalidConfigurationException
     * @throws JsonException
     * @throws NoValidPairingException
     */
    private static function doubleElimination(): string
    {
        $sections = [];
        foreach (self::ELIMINATION_SIZES as $size) {
            $sections["n={$size}, first-named wins"]
                = self::bracket(new DoubleEliminationEngine(), $size, self::firstNamedWins(...));
        }
        $sections['n=8, last-named wins']
            = self::bracket(new DoubleEliminationEngine(), 8, self::lastNamedWins(...));

        return GoldenText::document([
            'Double elimination, driven round by round to completion.',
            ...self::bracketHeader(),
        ], $sections);
    }

    /**
     * @return list<string>
     */
    private static function bracketHeader(): array
    {
        return [
            'Entrants are "1".."n" in list order (position is the seeding). Each',
            'section names the rule that decides every event. `R<round> [<label>]:',
            'a-b c-d | byes: e f` lists the round\'s events in generated order,',
            'first-named participant first, then the participants advancing without',
            'playing; `standings:` is the final ranking as id(wins-losses).',
            ...self::EXPLANATION,
        ];
    }

    /**
     * Drive a whole bracket through the standard stage loop, deciding
     * every event with the given rule.
     *
     * @param Closure(Event): Result $decide
     * @return list<string>
     *
     * @throws InvalidConfigurationException
     * @throws JsonException
     * @throws NoValidPairingException
     */
    private static function bracket(StageEngineInterface $engine, int $size, Closure $decide): array
    {
        $state = StageState::start(self::field($size));
        $lines = [];

        while (!$engine->isComplete($state)) {
            if (count($lines) >= self::MAX_ENGINE_ROUNDS) {
                throw new LogicException('The bracket did not complete within ' . self::MAX_ENGINE_ROUNDS . ' rounds.');
            }
            $pairing = $engine->pairNextRound($state);
            $lines[] = GoldenText::pairing($pairing);
            $state = $state->withRoundPlayed($pairing, array_map($decide, $pairing->getEvents()));
        }

        $outcome = $engine->getOutcome($state);
        if ($outcome === null) {
            throw new LogicException('A complete bracket must have an outcome.');
        }
        $lines[] = GoldenText::standings($outcome->getStandings());

        return $lines;
    }

    private static function firstNamedWins(Event $event): Result
    {
        return new Result($event, $event->getParticipants()[0]);
    }

    private static function lastNamedWins(Event $event): Result
    {
        return new Result($event, $event->getParticipants()[1]);
    }

    /**
     * Two-legged ties mirror the roles in leg 2, so the tie's first-named
     * participant is leg 2's second-named one.
     */
    private static function tieFirstNamedWins(Event $event): Result
    {
        return $event->getMetadataValue('tie_leg') === 2
            ? self::lastNamedWins($event)
            : self::firstNamedWins($event);
    }

    /**
     * The repack scenario fixture, as it stands (clean) and with its
     * evacuated events wrongly held as pins. Only the grid, the event ids
     * and their participant ids are read from the fixture, to keep this
     * case independent of the rest of its shape.
     *
     * @throws InvalidConfigurationException
     * @throws JsonException
     * @throws RepackViolationsException
     */
    private static function repackScenario(): string
    {
        /** @var array{grid: array{sessions: list<string>, slots: list<string>, timezone: string}, movableEvents: list<array{eventId: int, participants: array{int, int}}>, evacuatedDefaultedEvents: list<array{eventId: int, participants: array{int, int}, session: int, slot: int}>} $data */
        $data = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/Fixtures/repack-scenario.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $sessions = [];
        foreach ($data['grid']['sessions'] as $date) {
            $sessions[] = $date . ' ' . $data['grid']['slots'][0];
        }
        // The same grid the scenario test builds: 25-minute slots, and at
        // most 7 concurrent events for a field of 14.
        $grid = SessionGrid::fromArray([
            'sessions' => $sessions,
            'timezone' => $data['grid']['timezone'],
            'slot_interval' => 'PT25M',
            'slots_per_session' => count($data['grid']['slots']),
            'capacity_per_slot' => 7,
        ]);

        /** @var array<int, Participant> $participants */
        $participants = [];
        $participant = static function (int $id) use (&$participants): Participant {
            return $participants[$id] ??= new Participant((string) $id, (string) $id);
        };

        $movable = [];
        foreach ($data['movableEvents'] as $entry) {
            $movable[] = new MovableEvent(
                (string) $entry['eventId'],
                $participant($entry['participants'][0]),
                $participant($entry['participants'][1])
            );
        }

        $pinned = [];
        foreach ($data['evacuatedDefaultedEvents'] as $entry) {
            $pinned[] = new PinnedEvent(
                (string) $entry['eventId'],
                $participant($entry['participants'][0]),
                $participant($entry['participants'][1]),
                $entry['session'],
                $entry['slot']
            );
        }

        // Without either list the two sections below would be the same
        // (or empty) repack, and the mis-pinned case would pin nothing.
        if ($movable === [] || $pinned === []) {
            throw new LogicException('The repack scenario fixture no longer provides both movable and evacuated events.');
        }

        $repacker = new ScheduleRepacker();

        return GoldenText::document([
            'Schedule repack of tests/Fixtures/repack-scenario.json.',
            ...self::repackHeader(),
        ], [
            'clean: movable events only' => GoldenText::repackOutcome(
                $repacker->repack(new RepackRequest($movable, [], $grid))
            ),
            'mis-pinned: evacuated events held as pins' => GoldenText::repackOutcome(
                $repacker->repack(new RepackRequest($movable, $pinned, $grid))
            ),
        ]);
    }

    /**
     * Complete single round robins repacked onto exactly enough grid:
     * n/4 weekly sessions of four slots, n/2 events per slot.
     *
     * @throws InvalidConfigurationException
     * @throws JsonException
     * @throws RepackViolationsException
     */
    private static function repackRoundRobin(): string
    {
        $sections = [];
        foreach (self::REPACK_SIZES as $size) {
            $participants = self::field($size);
            $movable = [];
            for ($a = 0; $a < $size; ++$a) {
                for ($b = $a + 1; $b < $size; ++$b) {
                    $movable[] = new MovableEvent((string) (count($movable) + 1), $participants[$a], $participants[$b]);
                }
            }

            $sessionCount = intdiv($size, 4);
            $capacity = intdiv($size, 2);
            $start = new DateTimeImmutable('2026-01-07 19:00:00', new DateTimeZone('UTC'));
            $sessionStarts = [];
            for ($session = 0; $session < $sessionCount; ++$session) {
                $sessionStarts[] = $start;
                $start = $start->add(new DateInterval('P7D'));
            }
            $grid = new SessionGrid($sessionStarts, new DateInterval('PT30M'), 4, [], $capacity);

            $sections["n={$size}: " . count($movable) . " events, {$sessionCount} sessions x 4 slots, capacityPerSlot={$capacity}"]
                = GoldenText::repackOutcome((new ScheduleRepacker())->repack(new RepackRequest($movable, [], $grid)));
        }

        return GoldenText::document([
            'Schedule repack of complete single round robins.',
            'Participants are "1".."n"; movable event ids "1".."E" are assigned in',
            'pair order (1v2, 1v3, ..., 2v3, ...). Sessions are weekly from',
            '2026-01-07 19:00 UTC with 30-minute slots.',
            ...self::repackHeader(),
        ], $sections);
    }

    /**
     * @return list<string>
     */
    private static function repackHeader(): array
    {
        return [
            'Assignments are listed in outcome order as `<event id> => session,',
            'slot, kickoff` (sessions and slots are 0-based, kickoffs UTC), then the',
            'unplaced events with their reason, then each violation as its kind',
            'followed by its fields.',
            ...self::EXPLANATION,
        ];
    }

    /**
     * Schedule::toJson() of a small unseeded round robin with a bye.
     *
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException
     * @throws JsonException
     */
    private static function wireSchedule(): string
    {
        return (new RoundRobinScheduler())->schedule(self::field(3, true))->toJson();
    }

    /**
     * StageState::toJson() of a five-entrant single elimination after its
     * first round: one played event with a scored result, three byes.
     *
     * @throws InvalidConfigurationException
     * @throws JsonException
     * @throws NoValidPairingException
     */
    private static function wireStageState(): string
    {
        $state = StageState::start(self::field(5, true));
        $pairing = (new SingleEliminationEngine())->pairNextRound($state);

        $results = [];
        foreach ($pairing->getEvents() as $event) {
            [$first, $second] = $event->getParticipants();
            $results[] = new Result($event, $first, [$first->getId() => 2, $second->getId() => 1]);
        }

        return $state->withRoundPlayed($pairing, $results)->toJson();
    }

    private static function randomizer(?int $seed): ?Randomizer
    {
        return $seed === null ? null : new Randomizer(new Mt19937($seed));
    }

    private static function legStrategy(string $strategy): LegStrategyInterface
    {
        return match ($strategy) {
            'mirrored' => new MirroredLegStrategy(),
            'repeated' => new RepeatedLegStrategy(),
            default => throw new LogicException("Unknown leg strategy {$strategy}."),
        };
    }

    private static function seedSlug(?int $seed): string
    {
        return $seed === null ? 'unseeded' : 'seed-' . $seed;
    }

    private static function seedLabel(?int $seed): string
    {
        return $seed === null ? 'unseeded' : 'Mt19937 seed ' . $seed;
    }
}
