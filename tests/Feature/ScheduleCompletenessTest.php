<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
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
use Random\Engine\Mt19937;
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
