<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Award;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\UnresolvedRoundException;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use MissionGaming\Tactician\Scheduling\RoundRobinEngine;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Stage\RecordedRound;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\Withdrawal;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\RankingStrategy;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use Override;
use PHPUnit\Framework\Assert;
use Random\Engine\Mt19937;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

// The round-robin engine is the state-driven view of the schedule
// RoundRobinScheduler generates. What these tests hold it to:
//
// - every round it pairs is the scheduler's round of that number for the
//   entrant list, options, constraints and seed, events and byes alike,
//   whatever is recorded on the state: results, adjustments, withdrawals,
//   rounds drawn by hand;
// - its table is the standings calculator's table of what is recorded, and
//   a table computed here by hand from the points;
// - a withdrawal draws nothing again and decides nothing: the withdrawn
//   entrant's fixtures are still paired, await no result and count for
//   nobody unless the application records a result for them.
//
// The rules a state built from a history follows for every engine (rules 1
// to 3, 6 and 7 of issue #57) are swept in StageHistoryTest.php, which has
// this engine among its engines.

/**
 * @return list<Participant>
 */
function leagueField(int $size): array
{
    $field = [];
    for ($position = 1; $position <= $size; ++$position) {
        $field[] = new Participant("p{$position}", "Participant {$position}");
    }

    return $field;
}

/**
 * The configurations swept: every option that changes the fixtures, with
 * and without a seed and constraints.
 *
 * @return array<string, array{RoundRobinOptions|null, ConstraintSet|null, int|null}>
 *
 * @throws InvalidConfigurationException Not expected
 */
function leagueConfigurations(): array
{
    // The first two entrants do not meet in the first round: a layout the
    // scheduler has to rotate away from
    $notOneAndThreeFirst = ConstraintSet::create()->custom(
        static fn(Event $event): bool => $event->getRoundNumber() !== 1
            || array_diff(['p1', 'p3'], array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants())) !== [],
        'p1 and p3 do not meet in round 1'
    )->build();

    return [
        'one leg' => [null, null, null],
        'two mirrored legs' => [new RoundRobinOptions(legs: 2), null, null],
        'three repeated legs' => [new RoundRobinOptions(legs: 3, strategy: new RepeatedLegStrategy()), null, null],
        'two shuffled legs' => [new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy(seed: 11)), null, null],
        'round-parity roles' => [new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment()), null, null],
        'a seed' => [null, null, 42],
        'two legs, a seed and a constraint' => [new RoundRobinOptions(legs: 2), $notOneAndThreeFirst, 7],
    ];
}

/**
 * The schedule the scheduler gives for the configuration.
 *
 * @param list<Participant> $field
 *
 * @throws InvalidConfigurationException|IncompleteScheduleException Not expected
 */
function leagueSchedule(array $field, ?RoundRobinOptions $options, ?ConstraintSet $constraints, ?int $seed): Schedule
{
    $randomizer = $seed === null ? null : new Randomizer(new Xoshiro256StarStar($seed));

    return (new RoundRobinScheduler($constraints, $randomizer))->schedule($field, $options);
}

/**
 * A result for every event of a round: a win for either participant or a
 * draw, with scores.
 *
 * @param array<Event> $events
 *
 * @return list<Result>
 */
function leagueResults(array $events, Randomizer $rng): array
{
    $results = [];
    foreach ($events as $event) {
        [$one, $other] = $event->getParticipants();
        $scores = [$one->getId() => $rng->getInt(0, 3), $other->getId() => $rng->getInt(0, 3)];
        $winner = match ($scores[$one->getId()] <=> $scores[$other->getId()]) {
            1 => $one,
            -1 => $other,
            default => null,
        };
        $results[] = new Result($event, $winner, $scores);
    }

    return $results;
}

/**
 * The participants of every event, as written: roles and order kept.
 *
 * @param array<Event> $events
 *
 * @return list<array<mixed>>
 */
function leagueEvents(array $events): array
{
    return array_values(array_map(static fn(Event $event): array => $event->toArray(), $events));
}

/**
 * @param array<Participant> $participants
 *
 * @return list<string>
 */
function leagueIds(array $participants): array
{
    return array_values(array_map(static fn(Participant $participant): string => $participant->getId(), $participants));
}

/**
 * The table of a league worked out here, without the standings calculator:
 * three points for a win, one for a draw, nothing for a round sat out, and
 * the adjustments; the entries in order of points, score difference, scores
 * for and then the entrant list.
 *
 * @param list<Participant> $field
 *
 * @return list<string> Each entry as "ID points"
 */
function leagueTableByHand(array $field, StageState $state): array
{
    $points = [];
    $difference = [];
    $for = [];
    foreach ($field as $entrant) {
        $points[$entrant->getId()] = 0.0;
        $difference[$entrant->getId()] = 0;
        $for[$entrant->getId()] = 0;
    }
    foreach ($state->getResults() as $result) {
        $scores = $result->getScores();
        foreach ($result->getEvent()->getParticipants() as $participant) {
            $id = $participant->getId();
            $points[$id] += match (true) {
                $result->isDraw() => 1.0,
                $result->isWinFor($participant) => 3.0,
                default => 0.0,
            };
            foreach ($scores as $scorer => $score) {
                $difference[$id] += (string) $scorer === $id ? (int) $score : -(int) $score;
            }
            $for[$id] += (int) ($scores[$id] ?? 0);
        }
    }
    // A round sat out earns nothing (ADR 0017): no line for it
    foreach ($state->getAdjustments() as $adjustment) {
        $points[$adjustment->getParticipantId()] += $adjustment->getAmount();
    }

    $positions = array_flip(leagueIds($field));
    $ids = array_keys($points);
    usort($ids, static fn(string $one, string $other): int => [$points[$other], $difference[$other], $for[$other], $positions[$one]]
        <=> [$points[$one], $difference[$one], $for[$one], $positions[$other]]);

    return array_map(static fn(string $id): string => $id . ' ' . $points[$id], $ids);
}

/**
 * @return list<string>
 */
function leagueTable(Standings $standings): array
{
    return array_values(array_map(
        static fn(StandingEntry $entry): string => $entry->getParticipant()->getId() . ' ' . $entry->getRankingValue(),
        $standings->getEntries()
    ));
}

/**
 * What a call is refused with; the test fails where it is not refused.
 *
 * @param Closure(): mixed $call
 *
 * @throws PHPUnit\Framework\AssertionFailedError When the call is not refused
 */
function leagueRefusal(Closure $call): InvalidConfigurationException
{
    try {
        $call();
    } catch (InvalidConfigurationException $exception) {
        return $exception;
    }

    Assert::fail('The call was not refused.');
}

describe('a league paired round by round', function (): void {
    it('pairs every round as the scheduler has it, events and byes, whatever is recorded', function (string $name): void {
        [$options, $constraints, $seed] = leagueConfigurations()[$name];
        $engine = new RoundRobinEngine($options, $constraints, $seed);
        $rounds = 0;

        foreach ([2, 3, 4, 5, 6, 7, 8] as $size) {
            $field = leagueField($size);
            $schedule = leagueSchedule($field, $options, $constraints, $seed);
            $byes = $schedule->getByesByRound();
            $rng = new Randomizer(new Mt19937($size));
            $state = StageState::start($field);

            foreach ($schedule->getEventsByRound() as $number => $events) {
                expect($engine->isComplete($state))->toBeFalse()
                    ->and($engine->getReadiness($state)->isReady())->toBeTrue()
                    ->and($engine->getReadiness($state)->getRoundNumber())->toBe($number);

                $pairing = $engine->pairNextRound($state);

                expect($pairing->getRoundNumber())->toBe($number)
                    ->and(leagueEvents($pairing->getEvents()))->toBe(leagueEvents($events), "{$name}, {$size} entrants, round {$number}")
                    ->and(leagueIds($pairing->getByes()))->toBe(leagueIds($byes[$number]))
                    ->and($pairing->isPairedOnIncompleteResults())->toBeFalse();

                // Recorded when it is paired, the results as they come, and
                // what an application records beside them
                $state = $state->withRoundPaired($pairing)
                    ->withAdditionalResults(leagueResults($pairing->getEvents(), $rng));
                if ($rng->getInt(0, 2) === 0) {
                    $state = $state->withAdjustment(new Adjustment($field[$rng->getInt(0, $size - 1)]->getId(), -1.0));
                }
                ++$rounds;
            }

            $refusal = leagueRefusal(fn() => $engine->pairNextRound($state));

            expect($engine->isComplete($state))->toBeTrue()
                ->and($engine->getReadiness($state)->isComplete())->toBeTrue()
                ->and($engine->getOutcome($state))->toBeInstanceOf(StageOutcome::class)
                ->and($refusal->getReason())->toBe(InvalidConfigurationReason::PlannedRoundsPlayed)
                ->and($refusal->getContext())->toBe(['planned_rounds' => count($schedule->getEventsByRound()), 'next_round' => $state->getNextRoundNumber()])
                ->and(array_map(static fn(RecordedRound $round): bool => $round->isManual(), $engine->getRecordedRounds($state)))
                ->each->toBeFalse();
        }

        expect($rounds)->toBeGreaterThan(30);
    })->with(array_keys(leagueConfigurations()));

    it('has the plan of the scheduler for the entrant list', function (): void {
        $options = new RoundRobinOptions(legs: 2);
        $field = leagueField(5);

        expect((new RoundRobinEngine($options))->getPlan(StageState::start($field)))
            ->toEqual((new RoundRobinScheduler())->getPlan($field, $options));
    });

    // A round drawn by hand moves no later fixture, and the engine never
    // pairs a meeting more often than the league has legs: the organiser
    // plays round 2 of the schedule in week 1, the engine refuses to pair
    // round 2 again, and once week 2 is drawn by hand as well (round 1 of
    // the schedule), the rest of the schedule follows and every two
    // entrants meet once.
    it('refuses to pair a meeting a round drawn by hand has already played, and pairs on from the schedule once that round is drawn by hand too', function (): void {
        $engine = new RoundRobinEngine();
        $field = leagueField(6);
        $schedule = leagueSchedule($field, null, null, null)->getEventsByRound();
        $rng = new Randomizer(new Mt19937(1));
        $renumbered = static fn(int $number, array $events): RoundPairing => new RoundPairing($number, null, array_map(
            static fn(Event $event): Event => new Event($event->getParticipants(), new Round($number)),
            $events
        ));

        $byHand = $renumbered(1, $schedule[2]);
        $state = StageState::start($field)->withRoundPlayed($byHand, leagueResults($byHand->getEvents(), $rng));

        $read = $engine->getRecordedRounds($state)[0];
        $derivation = $read->getDerivation();
        assert($derivation instanceof RoundPairing);
        $refusal = leagueRefusal(fn() => $engine->pairNextRound($state));
        $readiness = leagueRefusal(fn() => $engine->getReadiness($state));
        $scheduled = array_map(static fn(Event $event): array => leagueIds($event->getParticipants()), $schedule[2]);

        expect($read->isManual())->toBeTrue()
            ->and($read->getPairing())->toBe($byHand)
            ->and(leagueEvents($derivation->getEvents()))->toBe(leagueEvents($schedule[1]))
            ->and($refusal->getReason())->toBe(InvalidConfigurationReason::RecordedRoundMismatch)
            ->and($refusal->getContext())->toBe(['round' => 2, 'legs' => 1, 'met_already' => $scheduled, 'scheduled' => $scheduled])
            ->and(leagueRefusal(fn() => $engine->pairNextRoundOnIncompleteResults($state))->getReason())->toBe(InvalidConfigurationReason::RecordedRoundMismatch)
            // The state is not called ready: the readiness refuses the
            // round as the pairing does. Every other read reads the state
            ->and($readiness->getReason())->toBe(InvalidConfigurationReason::RecordedRoundMismatch)
            ->and($readiness->getContext())->toBe($refusal->getContext())
            ->and($engine->isComplete($state))->toBeFalse()
            ->and($engine->getOutcome($state))->toBeNull()
            ->and($engine->getStandings($state)->getEntries())->toHaveCount(6);

        $weekTwo = $renumbered(2, $schedule[1]);
        $state = $state->withRoundPlayed($weekTwo, leagueResults($weekTwo->getEvents(), $rng));
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            expect(leagueEvents($pairing->getEvents()))->toBe(leagueEvents($schedule[$pairing->getRoundNumber()]));
            $state = $state->withRoundPlayed($pairing, leagueResults($pairing->getEvents(), $rng));
        }

        $meetings = array_count_values(array_map(static function (Event $event): string {
            $ids = leagueIds($event->getParticipants());
            sort($ids);

            return implode('-', $ids);
        }, $state->getPlayedEvents()));

        expect($meetings)->toHaveCount(15)
            ->and(array_values($meetings))->each->toBe(1)
            ->and(array_map(static fn(RecordedRound $round): bool => $round->isManual(), $engine->getRecordedRounds($state)))
            ->toBe([true, true, false, false, false]);
    });

    // Over several legs a meeting played early is paired again while it has
    // been played fewer times than the league has legs: the organiser plays
    // round 2 of the schedule in week 1, the engine pairs round 2 as the
    // schedule has it, and every later round is paired and called ready
    // until the first one that would play a meeting of round 2 once more
    // than the league has legs. That round is refused, by the readiness as
    // by the pairing, and the context names only those meetings. Whatever
    // the leg strategy.
    it('pairs a meeting again while it has been played fewer times than the league has legs, and refuses the round that would play it once more', function (RoundRobinOptions $options): void {
        $engine = new RoundRobinEngine($options);
        $field = leagueField(6);
        $schedule = leagueSchedule($field, $options, null, null)->getEventsByRound();
        $rng = new Randomizer(new Mt19937(3));
        $key = static function (Event $event): string {
            $ids = leagueIds($event->getParticipants());
            sort($ids);

            return implode('-', $ids);
        };
        $roundTwo = array_map($key, $schedule[2]);

        // Counted by hand: the round of the schedule, after round 1 played
        // as round 2, that holds a meeting already played as often as the
        // league has legs
        $met = array_fill_keys($roundTwo, 1);
        $expected = null;
        foreach ($schedule as $number => $events) {
            if ($number < 2) {
                continue;
            }
            foreach (array_map($key, $events) as $meeting) {
                if (($met[$meeting] ?? 0) >= $options->legs) {
                    $expected = $number;
                    break 2;
                }
            }
            foreach (array_map($key, $events) as $meeting) {
                $met[$meeting] = ($met[$meeting] ?? 0) + 1;
            }
        }
        assert(is_int($expected));

        $byHand = new RoundPairing(1, null, array_map(
            static fn(Event $event): Event => new Event($event->getParticipants(), new Round(1)),
            $schedule[2]
        ));
        $state = StageState::start($field)->withRoundPlayed($byHand, leagueResults($byHand->getEvents(), $rng));
        for ($number = 2; $number < $expected; ++$number) {
            expect($engine->getReadiness($state)->isReady())->toBeTrue();
            $pairing = $engine->pairNextRound($state);
            expect(leagueEvents($pairing->getEvents()))->toBe(leagueEvents($schedule[$number]));
            $state = $state->withRoundPlayed($pairing, leagueResults($pairing->getEvents(), $rng));
        }

        $refusal = leagueRefusal(fn() => $engine->pairNextRound($state));
        $again = array_values(array_map(
            static fn(Event $event): array => leagueIds($event->getParticipants()),
            array_filter($schedule[$expected], static fn(Event $event): bool => ($met[$key($event)] ?? 0) >= $options->legs)
        ));

        expect($refusal->getReason())->toBe(InvalidConfigurationReason::RecordedRoundMismatch)
            ->and($refusal->getContext())->toBe([
                'round' => $expected,
                'legs' => $options->legs,
                'met_already' => $again,
                'scheduled' => array_map(static fn(Event $event): array => leagueIds($event->getParticipants()), $schedule[$expected]),
            ])
            ->and(leagueRefusal(fn() => $engine->getReadiness($state))->getContext())->toBe($refusal->getContext())
            ->and($engine->getStandings($state)->getEntries())->toHaveCount(6)
            ->and($engine->isComplete($state))->toBeFalse();
    })->with([
        'two mirrored legs' => [new RoundRobinOptions(legs: 2)],
        'three repeated legs' => [new RoundRobinOptions(legs: 3, strategy: new RepeatedLegStrategy())],
        'two shuffled legs' => [new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy(seed: 11))],
    ]);

    // The constraints are not part of the fingerprint, so an engine built
    // with other constraints than the ones that paired the recorded rounds
    // is not refused by the stamp. Its schedule either continues the rounds
    // recorded (the same meetings in them, in whatever order), and the
    // league ends with every meeting played as often as it has legs, or it
    // does not, and then a round of it pairs a meeting again before the
    // league ends and is refused. Never a league that ends with a meeting
    // played twice and another not at all.
    it('is never silently paired on under other constraints than the ones that paired the recorded rounds', function (): void {
        $refused = 0;
        $continued = 0;

        foreach ([4, 5, 6, 7, 8] as $size) {
            foreach ([1, 2] as $legs) {
                foreach ([1, 2, 3] as $recorded) {
                    $field = leagueField($size);
                    $options = new RoundRobinOptions(legs: $legs);
                    $before = new RoundRobinEngine($options);
                    $rng = new Randomizer(new Mt19937($size * 10 + $legs + $recorded * 100));
                    $state = StageState::start($field);
                    for ($round = 1; $round <= $recorded; ++$round) {
                        $pairing = $before->pairNextRound($state);
                        $state = $state->withRoundPlayed($pairing, leagueResults($pairing->getEvents(), $rng));
                    }

                    // The first meeting of round 1 is not allowed in round 1
                    $first = leagueIds($state->getRoundsPlayed()[0]->getEvents()[0]->getParticipants());
                    $after = new RoundRobinEngine($options, ConstraintSet::create()->custom(
                        static fn(Event $event): bool => $event->getRoundNumber() !== 1 || array_diff($first, leagueIds($event->getParticipants())) !== [],
                        'not the first meeting in round 1'
                    )->build());
                    expect($after->getFingerprint())->toBe($before->getFingerprint());

                    try {
                        while (!$after->isComplete($state)) {
                            $pairing = $after->pairNextRound($state);
                            $state = $state->withRoundPlayed($pairing, leagueResults($pairing->getEvents(), $rng));
                        }
                        ++$continued;
                    } catch (InvalidConfigurationException $exception) {
                        expect($exception->getReason())->toBe(InvalidConfigurationReason::RecordedRoundMismatch);
                        ++$refused;
                    }

                    $meetings = array_count_values(array_map(static function (Event $event): string {
                        $ids = leagueIds($event->getParticipants());
                        sort($ids);

                        return implode('-', $ids);
                    }, $state->getPlayedEvents()));
                    expect(array_filter($meetings, static fn(int $count): bool => $count > $legs))->toBe([], "{$size} entrants, {$legs} legs, {$recorded} rounds recorded");
                    if ($after->isComplete($state)) {
                        expect($meetings)->toHaveCount(intdiv($size * ($size - 1), 2))
                            ->and(array_values($meetings))->each->toBe($legs);
                    }
                }
            }
        }

        expect($refused)->toBeGreaterThan(10);
    });

    it('does not call a round manual for its roles, the order of its events or its byes', function (): void {
        $engine = new RoundRobinEngine();
        $field = leagueField(5);
        $own = $engine->pairNextRound(StageState::start($field));

        $rewritten = new RoundPairing(1, 'Matchday 1', array_reverse(array_map(
            static fn(Event $event): Event => new Event(array_reverse($event->getParticipants()), new Round(1)),
            $own->getEvents()
        )));

        expect($engine->getRecordedRounds(StageState::start($field)->withRoundPaired($rewritten))[0]->isManual())->toBeFalse();
    });

    it('is told by its fingerprint from an engine with other options, and not by its constraints', function (): void {
        $field = leagueField(4);
        $engine = new RoundRobinEngine(new RoundRobinOptions(legs: 2));
        $state = StageState::start($field)->withEngineFingerprint($engine->getFingerprint());

        expect(leagueRefusal(fn() => (new RoundRobinEngine())->pairNextRound($state))->getReason())->toBe(InvalidConfigurationReason::EngineFingerprintMismatch)
            ->and((new RoundRobinEngine(new RoundRobinOptions(legs: 2), ConstraintSet::create()->noRepeatPairings()->build()))->getFingerprint())
            ->toBe($engine->getFingerprint())
            ->and((new RoundRobinEngine(new RoundRobinOptions(legs: 2), seed: 3))->getFingerprint())->not->toBe($engine->getFingerprint())
            ->and((new RoundRobinEngine(standingsCalculator: new StandingsCalculator(WinDrawLossRanking::oneHalfZero())))->getFingerprint())
            ->toBe((new RoundRobinEngine())->getFingerprint());
    });

    it('refuses a shuffled leg strategy that draws from a randomizer: the same round could not be paired twice', function (): void {
        expect(leagueRefusal(fn() => new RoundRobinEngine(new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy(new Randomizer(new Mt19937(1))))))->getReason())
            ->toBe(InvalidConfigurationReason::IncompatibleOptions);
    });

    it('refuses a stage of fewer than two entrants as the scheduler does, in every call', function (): void {
        $engine = new RoundRobinEngine();
        $state = StageState::start(leagueField(1));

        foreach ([
            fn() => $engine->pairNextRound($state),
            fn() => $engine->pairNextRoundOnIncompleteResults($state),
            fn() => $engine->isComplete($state),
            fn() => $engine->getOutcome($state),
            fn() => $engine->getReadiness($state),
            fn() => $engine->getRecordedRounds($state),
            fn() => $engine->getPlan($state),
        ] as $call) {
            expect(leagueRefusal($call)->getReason())->toBe(InvalidConfigurationReason::TooFewParticipants);
        }
    });

    it('refuses a state that names a participant outside its entrant list: it cannot draw the league again', function (): void {
        $engine = new RoundRobinEngine();
        [$one, $two] = $field = leagueField(4);
        $stranger = new Participant('z', 'Not entered');

        // The verbs record a round as given; a history would refuse it
        $state = StageState::start($field)->withRoundPaired(new RoundPairing(1, null, [new Event([$one, $stranger], new Round(1))]));

        foreach ([
            fn() => $engine->pairNextRound($state),
            fn() => $engine->getStandings($state),
            fn() => $engine->getRecordedRounds($state),
            fn() => $engine->getReadiness($state),
        ] as $call) {
            $refusal = leagueRefusal($call);
            expect($refusal->getReason())->toBe(InvalidConfigurationReason::RecordedRoundMismatch)
                ->and($refusal->getContext())->toBe(['participant' => 'z']);
        }

        // Data stored before the entrant list was kept, in which an entrant
        // has withdrawn, is such a state
        $played = StageState::start($field)->withRoundPaired($engine->pairNextRound(StageState::start($field)))->withoutParticipant($two);
        $stored = $played->toArray();
        unset($stored['entrants'], $stored['withdrawals']);

        $refusal = leagueRefusal(fn() => $engine->pairNextRound(StageState::fromArray($stored)));
        expect($refusal->getReason())->toBe(InvalidConfigurationReason::RecordedRoundMismatch)
            ->and($refusal->getContext())->toBe(['participant' => 'p2']);
    });

    it('refuses a state with an event of other than two participants, never reading it as a pairing', function (): void {
        $engine = new RoundRobinEngine();
        [$one, $two, $three] = $field = leagueField(4);
        $state = StageState::start($field)->withRoundPaired(new RoundPairing(1, null, [new Event([$one, $two, $three], new Round(1))]));

        foreach ([
            fn() => $engine->pairNextRound($state),
            fn() => $engine->getStandings($state),
            fn() => $engine->getRecordedRounds($state),
            fn() => $engine->isComplete($state),
        ] as $call) {
            $refusal = leagueRefusal($call);
            expect($refusal->getReason())->toBe(InvalidConfigurationReason::RecordedRoundMismatch)
                ->and($refusal->getContext())->toBe(['round' => 1, 'participants' => ['p1', 'p2', 'p3']]);
        }
    });

    it('gives the scheduler\'s refusal where the constraints leave it no schedule, and reads recorded rounds without a derivation', function (): void {
        $engine = new RoundRobinEngine(constraints: ConstraintSet::create()->custom(static fn(): bool => false, 'Nothing')->build());
        [$one, $two, $three, $four] = $field = leagueField(4);
        $state = StageState::start($field);

        expect(fn() => $engine->pairNextRound($state))->toThrow(IncompleteScheduleException::class)
            ->and($engine->getReadiness($state)->isReady())->toBeTrue();

        $byHand = new RoundPairing(1, null, [new Event([$one, $two], new Round(1)), new Event([$three, $four], new Round(1))]);
        $read = $engine->getRecordedRounds($state->withRoundPaired($byHand))[0];

        expect($read->getDerivation())->toBeNull()
            ->and($read->isManual())->toBeFalse()
            ->and($read->getUnresolvedEvents())->toBe($byHand->getEvents());
    });

    it('refuses a state stamped by an engine with other options in every call', function (): void {
        $state = StageState::start(leagueField(4))->withEngineFingerprint((new RoundRobinEngine(new RoundRobinOptions(legs: 2)))->getFingerprint());
        $engine = new RoundRobinEngine();

        foreach ([
            fn() => $engine->pairNextRound($state),
            fn() => $engine->pairNextRoundOnIncompleteResults($state),
            fn() => $engine->isComplete($state),
            fn() => $engine->getOutcome($state),
            fn() => $engine->getReadiness($state),
            fn() => $engine->getRecordedRounds($state),
            fn() => $engine->getStandings($state),
            fn() => $engine->getPlan($state),
        ] as $call) {
            expect(leagueRefusal($call)->getReason())->toBe(InvalidConfigurationReason::EngineFingerprintMismatch);
        }
    });

    // The verbs accept the next round after the last of the schedule, paired
    // by hand; it is read as recorded, with no derivation to compare it
    // with, and asks for no further round
    it('reads a round recorded beyond the last round of the schedule without a derivation', function (): void {
        $engine = new RoundRobinEngine();
        [$one, $two, $three] = $field = leagueField(3);
        $state = StageState::start($field);
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, array_map(static fn(Event $event): Result => new Result($event), $pairing->getEvents()));
        }
        $extra = new RoundPairing(4, null, [new Event([$one, $two], new Round(4))], [$three]);
        $state = $state->withRoundPlayed($extra, [new Result($extra->getEvents()[0])]);

        $read = $engine->getRecordedRounds($state)[3];

        expect($read->getDerivation())->toBeNull()
            ->and($read->isManual())->toBeFalse()
            ->and($engine->isComplete($state))->toBeTrue()
            ->and(leagueRefusal(fn() => $engine->pairNextRound($state))->getReason())->toBe(InvalidConfigurationReason::PlannedRoundsPlayed);
    });
});

describe('the table of a league', function (): void {
    it('is the standings calculator\'s table of what is recorded, and the table worked out by hand', function (string $name): void {
        [$options, $constraints, $seed] = leagueConfigurations()[$name];
        $engine = new RoundRobinEngine($options, $constraints, $seed);
        $calculator = new StandingsCalculator();
        $points = 0;

        foreach ([3, 4, 5, 7] as $size) {
            $field = leagueField($size);
            $rng = new Randomizer(new Mt19937(31 * $size));
            $state = StageState::start($field);

            while (!$engine->isComplete($state)) {
                $pairing = $engine->pairNextRound($state);
                $results = leagueResults($pairing->getEvents(), $rng);
                $state = $state->withRoundPaired($pairing);

                foreach ([array_slice($results, 0, 1), array_slice($results, 1)] as $some) {
                    $state = $state->withAdditionalResults($some);
                    if ($rng->getInt(0, 1) === 0) {
                        $state = $state->withAdjustment(new Adjustment($field[$rng->getInt(0, $size - 1)]->getId(), (float) $rng->getInt(-3, 3)));
                    }

                    $table = $engine->getStandings($state);
                    expect($table)->toEqual(
                        $calculator->calculateWith($field, $state->getResults(), $state->getByeCounts(), $state->getAdjustments(), 0)
                            ->withTiedSetsOrderedBy($field)
                    )
                        ->and(leagueTable($table))->toBe(leagueTableByHand($field, $state), "{$name}, {$size} entrants");
                    ++$points;
                }
            }

            $outcome = $engine->getOutcome($state);
            assert($outcome instanceof StageOutcome);
            expect($outcome->getStandings())->toEqual($engine->getStandings($state))
                ->and($outcome->getResults())->toBe($state->getResults())
                ->and($outcome->getByes())->toBe($state->getByeCounts())
                ->and($outcome->getFinalRound())->toBe($state->getLastRound())
                ->and(leagueIds($outcome->getEntrants()))->toBe(leagueIds($field));
        }

        expect($points)->toBeGreaterThan(30);
    })->with(array_keys(leagueConfigurations()));

    // A rest is a gap in a fixture list drawn once, not a round the format
    // let a participant through (ADR 0017): it is counted on the entry and
    // earns nothing under any ranking strategy, a strategy whose bye is
    // worth a win included. Fields of odd size of one and two legs, the
    // table read after every round and at the end.
    it('counts a round sat out and credits it with nothing, mid-season and at the end, whatever a bye is worth', function (int $legs): void {
        $calculators = [
            'a bye worth a win' => new StandingsCalculator(),
            'a bye worth a draw' => new StandingsCalculator(WinDrawLossRanking::threeOneZero()->withByeValue(1.0)),
            'one, a half and nothing' => new StandingsCalculator(WinDrawLossRanking::oneHalfZero()),
            'a strategy that says nothing of byes' => new StandingsCalculator(new readonly class implements RankingStrategy {
                /**
                 * @param array<Result> $results
                 */
                #[Override]
                public function rank(Participant $participant, array $results): float
                {
                    return (float) count(array_filter($results, static fn(Result $result): bool => $result->isWinFor($participant)));
                }
            }),
        ];
        $rests = 0;

        foreach ([3, 5, 7] as $size) {
            $field = leagueField($size);
            $options = new RoundRobinOptions(legs: $legs);
            $engines = array_map(static fn(StandingsCalculator $calculator): RoundRobinEngine => new RoundRobinEngine($options, standingsCalculator: $calculator), $calculators);
            $rng = new Randomizer(new Mt19937($size * $legs));
            $state = StageState::start($field);
            $restsOf = array_fill_keys(leagueIds($field), 0);

            while (!$engines['a bye worth a win']->isComplete($state)) {
                $before = array_map(static fn(RoundRobinEngine $engine): Standings => $engine->getStandings($state), $engines);
                $pairing = $engines['a bye worth a win']->pairNextRound($state);
                [$resting] = $pairing->getByes();
                $state = $state->withRoundPaired($pairing)->withAdditionalResults(leagueResults($pairing->getEvents(), $rng));
                ++$restsOf[$resting->getId()];
                ++$rests;

                foreach ($engines as $name => $engine) {
                    $table = $engine->getStandings($state);
                    $entry = $table->getEntryFor($resting);
                    $earlier = $before[$name]->getEntryFor($resting);
                    assert($entry instanceof StandingEntry && $earlier instanceof StandingEntry);

                    // The round it rested adds nothing to the participant
                    expect($entry->getRankingValue())->toBe($earlier->getRankingValue(), "{$name}, {$size} entrants")
                        ->and($entry->getEarnedRankingValue())->toBe($entry->getRankingValueOfResults())
                        ->and(array_map(static fn(StandingEntry $row): int => $row->getByes(), $table->getEntries()))
                        ->toBe(array_map(static fn(StandingEntry $row): int => $restsOf[$row->getParticipant()->getId()], $table->getEntries()));
                }
                expect(leagueTable($engines['a bye worth a win']->getStandings($state)))->toBe(leagueTableByHand($field, $state));
            }

            // Everybody has rested once a leg, and the final table is the
            // table of the results
            $outcome = $engines['a bye worth a win']->getOutcome($state);
            assert($outcome instanceof StageOutcome);
            expect(array_values($restsOf))->each->toBe($legs)
                ->and(leagueTable($outcome->getStandings()))->toBe(leagueTableByHand($field, $state))
                ->and(array_map(static fn(StandingEntry $row): int => $row->getByes(), $outcome->getStandings()->getEntries()))->each->toBe($legs);
        }

        expect($rests)->toBe(15 * $legs)
            // Nothing is paired from the table, so the bye value is not in the fingerprint
            ->and((new RoundRobinEngine(standingsCalculator: $calculators['a bye worth a draw']))->getFingerprint())
            ->toBe((new RoundRobinEngine())->getFingerprint());
    })->with([1, 2]);
});

describe('a withdrawal from a league', function (): void {
    // Any number of entrants withdrawing at any point: the fixtures are
    // those of the draw to the end, nothing the withdrawn entrant played is
    // taken back, and nothing it did not play is given to anybody.
    it('draws nothing again, awaits no result of the withdrawn entrant and credits nobody for its fixtures', function (string $name): void {
        [$options, $constraints, $seed] = leagueConfigurations()[$name];
        $engine = new RoundRobinEngine($options, $constraints, $seed);
        $uncontested = 0;

        foreach ([4, 5, 7] as $size) {
            foreach ([1, 2] as $draw) {
                $field = leagueField($size);
                $schedule = leagueSchedule($field, $options, $constraints, $seed)->getEventsByRound();
                $rng = new Randomizer(new Mt19937(100 * $size + $draw));
                $state = StageState::start($field);

                while (!$engine->isComplete($state)) {
                    $pairing = $engine->pairNextRound($state);
                    expect(leagueEvents($pairing->getEvents()))->toBe(leagueEvents($schedule[$pairing->getRoundNumber()]), "{$name}, {$size} entrants");

                    $state = $state->withRoundPaired($pairing);
                    if ($rng->getInt(0, 3) === 0) {
                        $state = $state->withoutParticipant($field[$rng->getInt(0, $size - 1)]);
                    }

                    // The events of an entrant who has withdrawn are not awaited
                    $active = leagueIds($state->getParticipants());
                    $awaited = $engine->getReadiness($state)->getUnresolvedEvents();
                    $read = $engine->getRecordedRounds($state);
                    $last = $read[count($read) - 1];
                    foreach ($pairing->getEvents() as $event) {
                        $contested = array_diff(leagueIds($event->getParticipants()), $active) === [];
                        expect(in_array($event, $awaited, true))->toBe($contested)
                            ->and(in_array($event, $last->getUncontestedEvents(), true))->toBe(!$contested);
                        $uncontested += $contested ? 0 : 1;
                    }

                    $state = $state->withAdditionalResults(leagueResults($awaited, $rng));
                }

                // Every result is one the application recorded, and the
                // table is worked out from them alone
                $outcome = $engine->getOutcome($state);
                assert($outcome instanceof StageOutcome);
                $rebuilt = StageState::fromHistory($field, $state->getRoundsPlayed(), $state->getResults(), $state->getWithdrawals(), $state->getAdjustments());

                expect($state->getRoundsPlayed())->toHaveCount(count($schedule))
                    ->and(leagueTable($outcome->getStandings()))->toBe(leagueTableByHand($field, $state))
                    ->and($engine->getStandings($rebuilt))->toEqual($outcome->getStandings())
                    ->and(array_map(static fn(RecordedRound $round): bool => $round->isManual(), $engine->getRecordedRounds($rebuilt)))->each->toBeFalse();
            }
        }

        expect($uncontested)->toBeGreaterThan(5);
    })->with(array_keys(leagueConfigurations()));

    it('keeps the results the withdrawn entrant played, and counts an awarded result the application records for a fixture it will not play', function (): void {
        $engine = new RoundRobinEngine();
        $field = leagueField(4);
        $state = StageState::start($field);

        // Round 1 is played; p1 wins its event
        $first = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($first, array_map(
            static fn(Event $event): Result => new Result($event, $event->getParticipants()[0]),
            $first->getEvents()
        ));
        [$p1] = $field;
        $winsOfRoundOne = leagueIds(array_map(static fn(Event $event): Participant => $event->getParticipants()[0], $first->getEvents()));

        // p1 withdraws; round 2 still has its fixture, which awaits nothing
        $state = $state->withoutParticipant($p1);
        $second = $engine->pairNextRound($state);
        $fixture = array_values(array_filter($second->getEvents(), static fn(Event $event): bool => $event->hasParticipant($p1)))[0];
        $other = array_values(array_filter($second->getEvents(), static fn(Event $event): bool => !$event->hasParticipant($p1)))[0];
        $state = $state->withRoundPaired($second);

        expect($engine->getReadiness($state)->getUnresolvedEvents())->toBe([$other])
            ->and($engine->getRecordedRounds($state)[1]->getUncontestedEvents())->toBe([$fixture]);

        // Left as it is, the fixture counts for nobody
        $opponent = array_values(array_filter($fixture->getParticipants(), static fn(Participant $participant): bool => $participant !== $p1))[0];
        $state = $state->withAdditionalResults([new Result($other, null)]);
        $valueOf = static fn(StageState $state, string $id): float => array_values(array_filter(
            $engine->getStandings($state)->getEntries(),
            static fn(StandingEntry $entry): bool => $entry->getParticipant()->getId() === $id
        ))[0]->getRankingValue();
        $before = $valueOf($state, $opponent->getId());

        // Awarded by the application, it counts as the result it is
        $awarded = $state->withAdditionalResults([new Result($fixture, $opponent, award: new Award('walkover'))]);

        expect($valueOf($state, 'p1'))->toBe(in_array('p1', $winsOfRoundOne, true) ? 3.0 : 0.0)
            ->and($valueOf($awarded, $opponent->getId()))->toBe($before + 3.0)
            ->and($engine->getRecordedRounds($awarded)[1]->getUncontestedEvents())->toBe([]);
    });

    it('is not a manual round where the application leaves out the fixtures of an entrant who had withdrawn', function (): void {
        $engine = new RoundRobinEngine();
        [$p1] = $field = leagueField(6);
        $first = $engine->pairNextRound(StageState::start($field));
        $state = StageState::start($field)
            ->withRoundPlayed($first, leagueResults($first->getEvents(), new Randomizer(new Mt19937(3))))
            ->withoutParticipant($p1);

        $own = $engine->pairNextRound($state);
        $without = new RoundPairing(2, null, array_values(array_filter($own->getEvents(), static fn(Event $event): bool => !$event->hasParticipant($p1))));

        expect($without->getEvents())->toHaveCount(2)
            ->and($engine->getRecordedRounds($state->withRoundPaired($without))[1]->isManual())->toBeFalse()
            ->and($engine->getRecordedRounds($state->withRoundPaired($own))[1]->isManual())->toBeFalse();
    });

    it('draws the league from those who remain when the withdrawal comes before the first round', function (): void {
        $engine = new RoundRobinEngine();
        [, $p2] = $field = leagueField(5);
        $remaining = array_values(array_filter($field, static fn(Participant $entrant): bool => $entrant !== $p2));

        $state = StageState::fromHistory($field, [], [], [new Withdrawal($p2, 0)]);

        expect(leagueEvents($engine->pairNextRound($state)->getEvents()))
            ->toBe(leagueEvents(leagueSchedule($remaining, null, null, null)->getEventsByRound()[1]));
    });

    // An adjustment made before the draw (a deduction carried over from a
    // season before, say) to an entrant who then withdraws before the first
    // round: the participant is out of the draw, and the adjustment is still
    // recorded on the state. The league is drawn from those who remain, and
    // the table shows the adjustment as every engine's table does, after the
    // entrants where results do not separate them; so does the state stored
    // and read back. (A history cannot hold such an adjustment:
    // fromHistory() refuses one for a participant out of the draw.)
    it('draws the league from those who remain when an entrant given an adjustment withdraws before the first round', function (): void {
        $engine = new RoundRobinEngine();
        [, $p2] = $field = leagueField(5);
        $remaining = array_values(array_filter($field, static fn(Participant $entrant): bool => $entrant !== $p2));
        $draw = leagueSchedule($remaining, null, null, null)->getEventsByRound();

        $byVerbs = StageState::start($field)->withAdjustment(new Adjustment('p2', -3.0))->withoutParticipant($p2);
        $first = $engine->pairNextRound($byVerbs);
        $byVerbs = $byVerbs->withRoundPlayed($first, array_map(static fn(Event $event): Result => new Result($event), $first->getEvents()));

        foreach ([$byVerbs, StageState::fromJson($byVerbs->toJson())] as $state) {
            $entries = $engine->getStandings($state)->getEntries();

            expect(leagueEvents($first->getEvents()))->toBe(leagueEvents($draw[1]))
                ->and(leagueEvents($engine->pairNextRound($state)->getEvents()))->toBe(leagueEvents($draw[2]))
                ->and(leagueIds($state->getEntrants()))->toBe(leagueIds($remaining))
                ->and($engine->getRecordedRounds($state)[0]->isManual())->toBeFalse()
                ->and($entries)->toHaveCount(5)
                ->and($entries[4]->getParticipant()->getId())->toBe('p2')
                ->and($entries[4]->getRankingValue())->toBe(-3.0);
        }
    });

    // Round 1 was paired from every entrant, the entrant withdrew before it
    // was recorded, and round 1 is recorded as it was paired: the entrant
    // was in the draw after all, keeps its place, and the league is the
    // one drawn from every entrant
    it('keeps the draw of every entrant when the first round, recorded after a withdrawal, names the entrant', function (): void {
        $engine = new RoundRobinEngine();
        [, $p2] = $field = leagueField(5);
        $schedule = leagueSchedule($field, null, null, null)->getEventsByRound();
        $first = $engine->pairNextRound(StageState::start($field));
        $state = StageState::start($field)->withoutParticipant($p2)->withRoundPaired($first);
        $own = array_values(array_filter($first->getEvents(), static fn(Event $event): bool => $event->hasParticipant($p2)));
        $read = $engine->getRecordedRounds($state)[0];

        expect(leagueIds($state->getEntrants()))->toBe(leagueIds($field))
            ->and($read->isManual())->toBeFalse()
            ->and($read->getUncontestedEvents())->toBe($own)
            ->and($engine->getReadiness($state)->getUnresolvedEvents())->toBe(array_values(array_filter(
                $first->getEvents(),
                static fn(Event $event): bool => !$event->hasParticipant($p2)
            )))
            ->and(leagueEvents($engine->pairNextRoundOnIncompleteResults($state)->getEvents()))->toBe(leagueEvents($schedule[2]));
    });

    // A fixture is postponed, and one of its two entrants withdraws before
    // it is played: it will not be played, so it awaits nothing from then on
    it('awaits no postponed fixture once one of its entrants has withdrawn', function (): void {
        $engine = new RoundRobinEngine();
        $field = leagueField(4);
        $rng = new Randomizer(new Mt19937(9));
        $first = $engine->pairNextRound(StageState::start($field));
        [$postponed, $played] = $first->getEvents();
        $state = StageState::start($field)->withRoundPlayed($first, leagueResults([$played], $rng));

        expect($engine->getReadiness($state)->getUnresolvedEvents())->toBe([$postponed]);

        $state = $state->withoutParticipant($postponed->getParticipants()[0]);

        expect($engine->getReadiness($state)->isReady())->toBeTrue()
            ->and($engine->getRecordedRounds($state)[0]->getUncontestedEvents())->toBe([$postponed])
            ->and($engine->getRecordedRounds($state)[0]->getUnresolvedEvents())->toBe([])
            ->and($engine->pairNextRound($state)->isPairedOnIncompleteResults())->toBeFalse();
    });
});

describe('a league that plays on while a fixture is postponed', function (): void {
    it('pairs the next round only when asked to in so many words, marks it, and ends only once the fixture has its result', function (): void {
        $engine = new RoundRobinEngine();
        $field = leagueField(4);
        $schedule = leagueSchedule($field, null, null, null)->getEventsByRound();
        $state = StageState::start($field);
        $rng = new Randomizer(new Mt19937(5));

        $first = $engine->pairNextRound($state);
        [$postponed, $played] = $first->getEvents();
        $state = $state->withRoundPlayed($first, leagueResults([$played], $rng));

        $refusal = leagueRefusal(fn() => $engine->pairNextRound($state));
        assert($refusal instanceof UnresolvedRoundException);
        expect($refusal->getUnresolvedEvents())->toBe([$postponed]);

        $rounds = [$first];
        $results = $state->getResults();
        for ($number = 2; $number <= 3; ++$number) {
            $early = $engine->pairNextRoundOnIncompleteResults($state);
            expect($early->isPairedOnIncompleteResults())->toBeTrue()
                // The mark says which fixture awaited its result, as the Swiss engine's does
                ->and($early->getUnresolvedWhenPaired())->toBe([$postponed])
                ->and(leagueEvents($early->getEvents()))->toBe(leagueEvents($schedule[$number]));
            $rounds[] = $early;
            $played = leagueResults($early->getEvents(), $rng);
            $results = [...$results, ...$played];
            $byVerbs = $state->withRoundPlayed($early, $played);
            $state = StageState::fromHistory($field, $rounds, $results);

            // The history reads as the verbs recorded it, the mark included
            expect($state)->toEqual($byVerbs)
                ->and($state->getLastRound()?->isPairedOnIncompleteResults())->toBeTrue();
        }

        expect($engine->isComplete($state))->toBeFalse()
            ->and($engine->getOutcome($state))->toBeNull()
            ->and($engine->getReadiness($state)->getUnresolvedEvents())->toBe([$postponed]);

        // The postponed fixture is played; it arrives with the history
        $finished = StageState::fromHistory($field, $rounds, [...$results, ...leagueResults([$postponed], $rng)]);

        expect($engine->isComplete($finished))->toBeTrue()
            // No result changes a fixture: a round paired early is not manual once the late result is in
            ->and(array_map(static fn(RecordedRound $round): bool => $round->isManual(), $engine->getRecordedRounds($finished)))->toBe([false, false, false])
            ->and($engine->pairNextRoundOnIncompleteResults(StageState::start($field))->isPairedOnIncompleteResults())->toBeFalse();
    });
});
