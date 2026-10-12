<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentInterface;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Tests\Support\AwkwardIds;
use MissionGaming\Tactician\Tests\Support\RoundRobinAudit;
use Random\Engine\Mt19937;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * @return array<Participant>
 */
function completenessParticipants(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("p{$i}", "Player {$i}");
    }

    return $participants;
}

/**
 * @throws UnexpectedValueException When the name is not one of the two built-in role assignments
 */
function completenessRoleAssignment(string $name): RoleAssignmentInterface
{
    return match ($name) {
        'round_parity' => new RoundParityRoleAssignment(),
        'balanced' => new BalancedRoleAssignment(),
        default => throw new UnexpectedValueException($name),
    };
}

/**
 * Assert every pairing appears exactly $legs times, each participant plays at
 * most once per round, and all rounds fall within the expected range.
 */
function assertCompleteRoundRobin(Schedule $schedule, int $participantCount, int $legs): void
{
    $expectedEvents = intdiv($participantCount * ($participantCount - 1), 2) * $legs;
    expect(count($schedule))->toBe($expectedEvents);

    $roundsPerLeg = $participantCount % 2 === 0 ? $participantCount - 1 : $participantCount;
    $totalRounds = $roundsPerLeg * $legs;

    $pairingCounts = [];
    $participantsByRound = [];
    foreach ($schedule as $event) {
        $ids = array_map(fn(Participant $p) => $p->getId(), $event->getParticipants());
        sort($ids);
        $key = implode('|', $ids);
        $pairingCounts[$key] = ($pairingCounts[$key] ?? 0) + 1;

        $round = $event->getRound()?->getNumber();
        expect($round)->not->toBeNull();
        expect($round)->toBeGreaterThanOrEqual(1);
        expect($round)->toBeLessThanOrEqual($totalRounds);

        foreach ($ids as $id) {
            expect($participantsByRound[$round][$id] ?? false)
                ->toBeFalse("Participant {$id} plays more than once in round {$round}");
            $participantsByRound[$round][$id] = true;
        }
    }

    $expectedPairings = intdiv($participantCount * ($participantCount - 1), 2);
    expect(count($pairingCounts))->toBe($expectedPairings);
    foreach ($pairingCounts as $key => $count) {
        expect($count)->toBe($legs, "Pairing {$key} appears {$count} time(s), expected {$legs}");
    }
}

// Regression: odd participant counts historically dropped the bye round in
// legs after the first, producing incomplete multi-leg schedules.
//
// A role assignment decides roles only, so completeness holds under each.
it('generates complete schedules for every participant count, leg count, strategy, and role assignment', function (
    int $participantCount,
    int $legs,
    string $strategyName,
    string $roleAssignmentName
): void {
    $strategy = match ($strategyName) {
        'mirrored' => new MirroredLegStrategy(),
        'repeated' => new RepeatedLegStrategy(),
        default => throw new UnexpectedValueException($strategyName),
    };

    $schedule = (new RoundRobinScheduler())->schedule(
        completenessParticipants($participantCount),
        new RoundRobinOptions(
            legs: $legs,
            strategy: $strategy,
            roleAssignment: completenessRoleAssignment($roleAssignmentName)
        )
    );

    assertCompleteRoundRobin($schedule, $participantCount, $legs);
})
    ->with([[3], [4], [5], [6], [7]])
    ->with([[1], [2], [3]])
    ->with([['mirrored'], ['repeated']])
    ->with([['round_parity'], ['balanced']]);

// Regression: shuffling with a randomizer historically corrupted the bye
// sentinel for odd participant counts, producing duplicate pairings.
it('generates complete randomized schedules for odd and even participant counts', function (
    int $participantCount,
    int $legs,
    int $seed,
    string $roleAssignmentName
): void {
    $scheduler = new RoundRobinScheduler(null, new Randomizer(new Mt19937($seed)));
    $schedule = $scheduler->schedule(
        completenessParticipants($participantCount),
        new RoundRobinOptions(legs: $legs, roleAssignment: completenessRoleAssignment($roleAssignmentName))
    );

    assertCompleteRoundRobin($schedule, $participantCount, $legs);
})
    ->with([[5], [6]])
    ->with([[1], [2]])
    ->with([[42], [1337]])
    ->with([['round_parity'], ['balanced']]);

it('generates complete schedules with the shuffled leg strategy', function (string $roleAssignmentName): void {
    $strategy = new ShuffledLegStrategy(new Randomizer(new Mt19937(42)));
    $schedule = (new RoundRobinScheduler())->schedule(
        completenessParticipants(5),
        new RoundRobinOptions(legs: 2, strategy: $strategy, roleAssignment: completenessRoleAssignment($roleAssignmentName))
    );

    assertCompleteRoundRobin($schedule, 5, 2);
})->with([['round_parity'], ['balanced']]);

// Awkward-id sweeps. An id is any string and only ever a name: ids that are
// equal as numbers ('01' and '1', '1e3' and '1000'), that contain the `|` or
// `:` of the library's own keys or the `\` that escapes them, or that look
// empty must give the schedule any other six ids give. Each assertion below
// identifies a pairing by the exact bytes of its two ids.

/**
 * Assert a complete round robin over the given participants, whatever their
 * ids: every pairing exactly $legs times, nobody twice in a round, and
 * every round within the plan.
 *
 * @param array<Participant> $participants
 * @throws JsonException When an id is not valid UTF-8
 */
function assertCompleteRoundRobinOverIds(Schedule $schedule, array $participants, int $legs): void
{
    $count = count($participants);
    $pairings = intdiv($count * ($count - 1), 2);
    $totalRounds = ($count % 2 === 0 ? $count - 1 : $count) * $legs;

    expect(count($schedule))->toBe($pairings * $legs);

    $counts = AwkwardIds::pairingCounts($schedule);
    expect($counts)->toHaveCount($pairings);
    expect(array_values(array_unique($counts)))->toBe([$legs]);

    $seenInRound = [];
    foreach ($schedule as $event) {
        $round = $event->getRound()?->getNumber();
        expect($round)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual($totalRounds);

        foreach ($event->getParticipants() as $participant) {
            $key = $round . '/' . bin2hex($participant->getId());
            expect(isset($seenInRound[$key]))->toBeFalse();
            $seenInRound[$key] = true;
        }
    }
}

/**
 * A schedule as seats: each event's round and the list positions of its
 * two participants, so two schedules over different ids can be compared.
 *
 * @param array<Participant> $participants
 * @return array<array{int|null, int|false, int|false}>
 */
function scheduleAsSeats(Schedule $schedule, array $participants): array
{
    $ids = array_values(array_map(fn(Participant $participant) => $participant->getId(), $participants));

    return array_map(
        fn(Event $event): array => [
            $event->getRound()?->getNumber(),
            array_search($event->getParticipants()[0]->getId(), $ids, true),
            array_search($event->getParticipants()[1]->getId(), $ids, true),
        ],
        $schedule->getEvents()
    );
}

it('generates the same complete round robin whatever the ids are', function (
    array $ids,
    int $participantCount,
    int $legs,
    string $strategyName
): void {
    $strategy = match ($strategyName) {
        'mirrored' => new MirroredLegStrategy(),
        'repeated' => new RepeatedLegStrategy(),
        default => throw new UnexpectedValueException($strategyName),
    };
    $options = new RoundRobinOptions(legs: $legs, strategy: $strategy);
    $participants = AwkwardIds::participants($ids, $participantCount);
    $plain = completenessParticipants($participantCount);

    $schedule = (new RoundRobinScheduler())->schedule($participants, $options);

    assertCompleteRoundRobinOverIds($schedule, $participants, $legs);
    expect(scheduleAsSeats($schedule, $participants))
        ->toBe(scheduleAsSeats((new RoundRobinScheduler())->schedule($plain, $options), $plain));
})
    ->with(AwkwardIds::dataset())
    ->with([[4], [5], [6]])
    ->with([[1], [2], [3]])
    ->with([['mirrored'], ['repeated']]);

it('generates the same randomized and shuffled round robin whatever the ids are', function (
    array $ids,
    int $participantCount,
    int $seed
): void {
    $participants = AwkwardIds::participants($ids, $participantCount);
    $plain = completenessParticipants($participantCount);
    $generate = fn(array $field): Schedule => (new RoundRobinScheduler(null, new Randomizer(new Mt19937($seed))))
        ->schedule($field, new RoundRobinOptions(
            legs: 2,
            strategy: new ShuffledLegStrategy(new Randomizer(new Mt19937($seed + 1)))
        ));

    $schedule = $generate($participants);

    assertCompleteRoundRobinOverIds($schedule, $participants, 2);
    expect(scheduleAsSeats($schedule, $participants))->toBe(scheduleAsSeats($generate($plain), $plain));
})
    ->with(AwkwardIds::dataset())
    ->with([[5], [6]])
    ->with([[42], [1337]]);

it('backtracks to the same complete round robin whatever the ids are', function (
    array $ids,
    int $participantCount,
    int $legs
): void {
    // The first participant must meet the others in list order, one per
    // round of each leg: satisfiable, and out of reach of every rotation of
    // the circle method, so the backtracking search builds the leg.
    $constraintsFor = function (array $field): ConstraintSet {
        $ids = array_map(fn(Participant $participant) => $participant->getId(), $field);
        $roundsPerLeg = count($ids) % 2 === 0 ? count($ids) - 1 : count($ids);

        return ConstraintSet::create()->custom(static function (Event $event) use ($ids, $roundsPerLeg): bool {
            $seats = array_map(
                fn(Participant $participant) => array_search($participant->getId(), $ids, true),
                $event->getParticipants()
            );
            $round = $event->getRound()?->getNumber();
            if ($round === null || !in_array(0, $seats, true)) {
                return true;
            }

            return max($seats) === (($round - 1) % $roundsPerLeg) + 1;
        }, 'Fixture Placement')->build();
    };
    $options = new RoundRobinOptions(legs: $legs, backtracking: true);
    $participants = AwkwardIds::participants($ids, $participantCount);
    $plain = completenessParticipants($participantCount);

    $schedule = (new RoundRobinScheduler($constraintsFor($participants)))->schedule($participants, $options);

    assertCompleteRoundRobinOverIds($schedule, $participants, $legs);
    expect(scheduleAsSeats($schedule, $participants))->toBe(scheduleAsSeats(
        (new RoundRobinScheduler($constraintsFor($plain)))->schedule($plain, $options),
        $plain
    ));
})
    ->with(AwkwardIds::dataset())
    ->with([[4], [5], [6]])
    ->with([[1], [2]]);

it('pairs a Swiss stage without a repeat whatever the ids are', function (
    array $ids,
    int $participantCount,
    int $seed
): void {
    // Drive two stages, one over the awkward ids and one over plain ids,
    // with the same outcome by seat in every event; the state goes through
    // JSON after every round, as a platform would store it.
    // Three rounds: a field of four to six always has a third round left.
    $rounds = 3;
    $drive = function (array $field) use ($rounds, $seed): array {
        $randomizer = new Randomizer(new Mt19937($seed));
        $engine = new SwissPairingEngine(plannedRounds: $rounds);
        $state = StageState::start($field);
        $ids = array_map(fn(Participant $participant) => $participant->getId(), $field);

        $seats = [];
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $results = [];
            foreach ($pairing->getEvents() as $event) {
                $seats[] = [
                    $pairing->getRoundNumber(),
                    array_search($event->getParticipants()[0]->getId(), $ids, true),
                    array_search($event->getParticipants()[1]->getId(), $ids, true),
                ];
                $outcome = $randomizer->getInt(0, 2);
                $results[] = $outcome === 2
                    ? new Result($event)
                    : new Result($event, $event->getParticipants()[$outcome]);
            }
            $state = StageState::fromJson($state->withRoundPlayed($pairing, $results)->toJson());
        }

        return [$seats, $state, $engine];
    };

    $participants = AwkwardIds::participants($ids, $participantCount);
    [$seats, $state, $engine] = $drive($participants);

    $events = $state->getPlayedEvents();
    expect($events)->toHaveCount($rounds * intdiv($participantCount, 2));
    expect(array_values(array_unique(AwkwardIds::pairingCounts($events))))->toBe([1]);
    expect($engine->getPlan($state)->validateIntegrity(new Schedule($events)))->toBe([]);
    expect($engine->getOutcome($state)?->getStandings()->getEntries())->toHaveCount($participantCount);

    expect($seats)->toBe($drive(completenessParticipants($participantCount))[0]);
})
    ->with(AwkwardIds::dataset())
    ->with([[4], [5], [6]])
    ->with([[42], [1337]]);

// The property of issue #52: the same seed and options give the same
// schedule on every run, for every leg strategy. The options are plain
// data, which is all a stored configuration has, and every run builds its
// own from that data; a scheduler that shuffles the field gets a randomizer
// freshly seeded for the run. A scheduler without one must also repeat
// itself call by call, on one options object: that is where a strategy
// which kept the draws of an earlier call would show.
//
// One test per variant loops over the field sizes and the seeds.
it('repeats the schedule of a seed and options on every run, for every field size from 2 to 16', function (
    string $strategy,
    int $legs,
    string $field
): void {
    for ($size = 2; $size <= 16; ++$size) {
        $participants = completenessParticipants($size);
        $bySeed = [];

        foreach ([1, 2, 3] as $seed) {
            $data = ['legs' => $legs, 'strategy' => $strategy];
            if ($strategy === 'shuffled') {
                $data['strategy_seed'] = $seed;
            }
            $label = "{$strategy}, {$legs} leg(s), {$size} participants, {$field}, seed {$seed}";
            $run = fn(): Schedule => (new RoundRobinScheduler(
                null,
                $field === 'shuffled field' ? new Randomizer(new Mt19937(1000 + $seed)) : null
            ))->schedule($participants, RoundRobinOptions::fromArray($data));

            $first = $run();

            expect(RoundRobinAudit::faults($first, $participants, $legs))->toBe([], $label)
                ->and($run()->toJson())->toBe($first->toJson(), "{$label}: a second run")
                ->and($run()->toJson())->toBe($first->toJson(), "{$label}: a third run");

            if ($field === 'field as given') {
                $scheduler = new RoundRobinScheduler();
                $options = RoundRobinOptions::fromArray($data);

                expect($scheduler->schedule($participants, $options)->toJson())->toBe($first->toJson(), "{$label}: a first call")
                    ->and($scheduler->schedule($participants, $options)->toJson())->toBe($first->toJson(), "{$label}: a second call");
            }

            $bySeed[$seed] = $first->toJson();
        }

        // A seed that decided nothing would pass everything above. From six
        // participants a later leg has 15 roles or more to draw, and three
        // seeds that drew the same ones would be a seed that is not read.
        if ($strategy === 'shuffled' && $legs > 1 && $size >= 6) {
            expect(array_unique($bySeed))->toHaveCount(3, "{$strategy}, {$legs} leg(s), {$size} participants, {$field}");
        }
    }
})
    ->with([['mirrored'], ['repeated'], ['shuffled']])
    ->with([[1], [2], [3]])
    ->with([['field as given'], ['shuffled field']]);

// A constraint that rejects the first layout sends the scheduler through
// its rotations, and each rotation asks the strategy for the later legs
// again. The draws of the layouts that failed are part of the sequence, so
// the schedule must still be the one the seed names, on every run.
it('repeats the schedule of a seeded shuffled strategy when the first layouts are rejected', function (int $seed): void {
    $participants = [];
    for ($i = 1; $i <= 6; ++$i) {
        $participants[] = new Participant("p{$i}", "Player {$i}", $i);
    }
    $data = ['legs' => 2, 'strategy' => 'shuffled', 'strategy_seed' => $seed];
    $constraints = fn(): ConstraintSet => ConstraintSet::create()->add(new SeedProtectionConstraint(2, 0.25))->build();

    // The premise: the layout of the list as given is one the constraint rejects
    $meetingOfTheTopTwo = null;
    foreach ((new RoundRobinScheduler())->schedule($participants, RoundRobinOptions::fromArray($data)) as $event) {
        $ids = array_map(fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
        sort($ids);
        $meetingOfTheTopTwo ??= $ids === ['p1', 'p2'] ? $event->getRound()?->getNumber() : null;
    }
    expect($meetingOfTheTopTwo)->toBeLessThanOrEqual(2);

    $scheduler = new RoundRobinScheduler($constraints());
    $first = $scheduler->schedule($participants, RoundRobinOptions::fromArray($data));

    expect(RoundRobinAudit::faults($first, $participants, 2))->toBe([])
        ->and($scheduler->schedule($participants, RoundRobinOptions::fromArray($data))->toJson())->toBe($first->toJson())
        ->and((new RoundRobinScheduler($constraints()))->schedule($participants, RoundRobinOptions::fromArray($data))->toJson())
        ->toBe($first->toJson());
})->with([[1], [2], [3], [2026]]);

// A seed is not a randomizer the caller built from it by another name only:
// the two must draw the same roles, so that moving from one to the other
// changes nothing.
it('gives from a seed the schedule of a new Xoshiro256StarStar randomizer of that seed', function (int $size, int $legs, int $seed): void {
    $participants = completenessParticipants($size);

    $fromSeed = (new RoundRobinScheduler())->schedule(
        $participants,
        new RoundRobinOptions(legs: $legs, strategy: new ShuffledLegStrategy(seed: $seed))
    );
    $fromRandomizer = (new RoundRobinScheduler())->schedule(
        $participants,
        new RoundRobinOptions(legs: $legs, strategy: new ShuffledLegStrategy(new Randomizer(new Xoshiro256StarStar($seed))))
    );

    expect($fromSeed->toJson())->toBe($fromRandomizer->toJson());
})
    ->with([[4], [7], [12]])
    ->with([[2], [3]])
    ->with([[0], [42]]);

// The draws of a seed read nothing of the participants, so an id stays a
// name under a seeded shuffled strategy too.
it('generates the same seeded shuffled round robin whatever the ids are', function (
    array $ids,
    int $participantCount,
    int $seed
): void {
    $participants = AwkwardIds::participants($ids, $participantCount);
    $plain = completenessParticipants($participantCount);
    $options = RoundRobinOptions::fromArray(['legs' => 2, 'strategy' => 'shuffled', 'strategy_seed' => $seed]);

    $schedule = (new RoundRobinScheduler())->schedule($participants, $options);

    assertCompleteRoundRobinOverIds($schedule, $participants, 2);
    expect(scheduleAsSeats($schedule, $participants))
        ->toBe(scheduleAsSeats((new RoundRobinScheduler())->schedule($plain, $options), $plain));
})
    ->with(AwkwardIds::dataset())
    ->with([[5], [6]])
    ->with([[42], [1337]]);

// The wide sweep: every field size from 2 to 30, single and multi-leg, each
// leg strategy, and a scheduler that shuffles the field. Each schedule is
// held to everything a complete round robin is
// (tests/Support/RoundRobinAudit.php): every pairing once in every leg,
// nobody twice in a round, every round full, rounds 1-based and continuous
// across legs, and the byes of an odd field recorded for the right
// participant in the right round, one per participant per leg, with none in
// an even field.
//
// One test per variant loops over the field sizes, so the sweep adds a
// dozen tests and not several hundred dataset rows.
it('generates a complete round robin with the right byes for every field size from 2 to 30', function (
    string $variant,
    int $legs
): void {
    for ($size = 2; $size <= 30; ++$size) {
        $participants = completenessParticipants($size);
        $strategy = match ($variant) {
            'mirrored', 'shuffled field' => new MirroredLegStrategy(),
            'repeated' => new RepeatedLegStrategy(),
            'shuffled legs' => new ShuffledLegStrategy(new Randomizer(new Mt19937(100 + $size))),
            default => throw new UnexpectedValueException($variant),
        };
        $scheduler = $variant === 'shuffled field'
            ? new RoundRobinScheduler(null, new Randomizer(new Mt19937(200 + $size)))
            : new RoundRobinScheduler();

        $schedule = $scheduler->schedule($participants, new RoundRobinOptions(legs: $legs, strategy: $strategy));

        expect(RoundRobinAudit::faults($schedule, $participants, $legs))
            ->toBe([], "{$variant}, {$legs} leg(s), {$size} participants");

        $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
        expect($schedule->getMetadataValue('participant_count'))->toBe($size)
            ->and($schedule->getMetadataValue('legs'))->toBe($legs)
            ->and($schedule->getMetadataValue('rounds_per_leg'))->toBe($roundsPerLeg)
            ->and($schedule->getMetadataValue('total_rounds'))->toBe($roundsPerLeg * $legs)
            ->and($schedule->getMetadataValue('expected_event_count'))->toBe(intdiv($size * ($size - 1), 2) * $legs);
    }
})
    ->with([['mirrored'], ['repeated'], ['shuffled legs'], ['shuffled field']])
    ->with([[1], [2], [3]]);

// The audit must see what it is there to see: a schedule with one thing
// wrong is reported for that thing.
it('audits a round robin for each rule it states', function (Closure $spoil, string $expected): void {
    $participants = completenessParticipants(5);
    $schedule = (new RoundRobinScheduler())->schedule($participants, new RoundRobinOptions(legs: 2));
    expect(RoundRobinAudit::faults($schedule, $participants, 2))->toBe([]);

    $faults = RoundRobinAudit::faults($spoil($schedule), $participants, 2);

    expect(implode("\n", $faults))->toContain($expected);
})->with([
    'an event dropped' => [
        fn(Schedule $schedule): Schedule => new Schedule(array_slice($schedule->getEvents(), 1), $schedule->getMetadata()),
        '19 events, expected 20',
    ],
    'a round left short' => [
        fn(Schedule $schedule): Schedule => new Schedule(array_slice($schedule->getEvents(), 1), $schedule->getMetadata()),
        'round 1 has 1 events, expected 2',
    ],
    'a pairing played twice in a leg' => [
        function (Schedule $schedule): Schedule {
            $events = $schedule->getEvents();
            $events[1] = new Event($events[0]->getParticipants(), $events[1]->getRound());

            return new Schedule($events, $schedule->getMetadata());
        },
        'meet 2 times in leg 1',
    ],
    'a participant twice in a round' => [
        function (Schedule $schedule): Schedule {
            $events = $schedule->getEvents();
            $events[1] = new Event($events[0]->getParticipants(), $events[1]->getRound());

            return new Schedule($events, $schedule->getMetadata());
        },
        'is in two events of round 1',
    ],
    'a round past the last' => [
        function (Schedule $schedule): Schedule {
            $events = $schedule->getEvents();
            $events[0] = new Event($events[0]->getParticipants(), new Round(11));

            return new Schedule($events, $schedule->getMetadata());
        },
        'is in round 11, outside 1 to 10',
    ],
    'a round left empty' => [
        fn(Schedule $schedule): Schedule => new Schedule(array_slice($schedule->getEvents(), 2), $schedule->getMetadata()),
        'the rounds used are not exactly 1 to 10',
    ],
    'the byes of two rounds swapped' => [
        function (Schedule $schedule): Schedule {
            $byes = $schedule->getMetadataValue('byes');
            assert(is_array($byes));
            [$byes[1], $byes[2]] = [$byes[2], $byes[1]];

            return new Schedule($schedule->getEvents(), ['byes' => $byes] + $schedule->getMetadata());
        },
        'round 1 records the bye of',
    ],
    'a bye missing' => [
        function (Schedule $schedule): Schedule {
            $byes = $schedule->getMetadataValue('byes');
            assert(is_array($byes));
            unset($byes[10]);

            return new Schedule($schedule->getEvents(), ['byes' => $byes] + $schedule->getMetadata());
        },
        'has 0 byes in leg 2',
    ],
    'no byes recorded' => [
        fn(Schedule $schedule): Schedule => new Schedule($schedule->getEvents(), ['byes' => []] + $schedule->getMetadata()),
        'byes are not recorded for exactly the rounds 1 to 10, in order',
    ],
]);

it('reports a bye recorded in a field of even size', function (): void {
    $participants = completenessParticipants(4);
    $schedule = (new RoundRobinScheduler())->schedule($participants);

    expect(RoundRobinAudit::faults($schedule, $participants, 1))->toBe([])
        ->and(RoundRobinAudit::faults(new Schedule($schedule->getEvents(), ['byes' => [1 => 'p1']]), $participants, 1))
        ->toBe(['1 bye(s) recorded in a field of even size']);
});
