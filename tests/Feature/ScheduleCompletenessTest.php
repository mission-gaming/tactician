<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\RoleBalanceConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\LegStrategies\LegPlanContribution;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentInterface;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Tests\Support\AwkwardIds;
use MissionGaming\Tactician\Tests\Support\RoleCounts;
use MissionGaming\Tactician\Tests\Support\RoundRobinAudit;
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

// Mirrored legs alternate. The odd-numbered legs have the roles of leg 1
// and the even-numbered legs have them reversed, so over any number of legs:
//
// - a pair's meetings are split as evenly as their number allows: equally
//   over an even number of legs, one apart over an odd number;
// - an even number of legs ends with every participant first-named as often
//   as second-named, and an odd number ends with every participant where
//   leg 1 left it.
//
// Everything is counted from the events (tests/Support/RoleCounts.php).
// Before 0.3.0 every leg after the first was reversed, which split a pair
// 1:2 over three legs and 1:3 over four.

/**
 * The events of one leg as "round of the leg: first-named > second-named",
 * in generated order, so that two legs can be compared whatever rounds
 * they are in.
 *
 * @return list<string>
 */
function mirroredLegRoles(Schedule $schedule, int $leg, int $roundsPerLeg, bool $reversed = false): array
{
    $roles = [];
    foreach ($schedule as $event) {
        $round = $event->getRound()?->getNumber() ?? 0;
        if (intdiv($round - 1, $roundsPerLeg) + 1 !== $leg) {
            continue;
        }

        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
        if ($reversed) {
            $ids = array_reverse($ids);
        }
        $roles[] = (($round - 1) % $roundsPerLeg + 1) . ': ' . implode(' > ', $ids);
    }

    return $roles;
}

/**
 * A constraint that holds each named pairing to one round of every leg and
 * says nothing about roles.
 *
 * @param array<string, int> $placement "lower id|higher id" => round of the leg
 */
function mirroredLegPlacement(array $placement, int $roundsPerLeg): ConstraintSet
{
    return ConstraintSet::create()->custom(static function (Event $event) use ($placement, $roundsPerLeg): bool {
        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
        sort($ids);
        $legRound = (($event->getRound()?->getNumber() ?? 1) - 1) % $roundsPerLeg + 1;

        return ($placement[implode('|', $ids)] ?? $legRound) === $legRound;
    }, 'Fixture Placement')->build();
}

it('splits every pairing of mirrored legs as evenly as the leg count allows, for every field size from 2 to 30', function (
    int $legs,
    string $roleAssignmentName
): void {
    for ($size = 2; $size <= 30; ++$size) {
        $label = "{$legs} leg(s), {$size} participants";
        $participants = completenessParticipants($size);
        $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;

        $schedule = (new RoundRobinScheduler())->schedule($participants, new RoundRobinOptions(
            legs: $legs,
            strategy: new MirroredLegStrategy(),
            roleAssignment: completenessRoleAssignment($roleAssignmentName)
        ));

        expect(RoundRobinAudit::faults($schedule, $participants, $legs))->toBe([], $label);

        // Every pairing: level over an even number of legs, one apart over
        // an odd number
        expect(array_values(array_unique(RoleCounts::pairingSplits($schedule))))->toBe([$legs % 2], $label);

        // Every participant: balanced after an even number of legs, and
        // after an odd number where the first leg alone leaves it
        $firstLeg = RoleCounts::differences(RoleCounts::leg($schedule, 1, $roundsPerLeg));
        ksort($firstLeg);
        $whole = RoleCounts::differences($schedule);
        ksort($whole);
        expect($whole)->toBe($legs % 2 === 0 ? array_fill_keys(array_keys($firstLeg), 0) : $firstLeg, $label);
        if ($legs % 2 === 0) {
            expect(RoleCounts::worstEndImbalance($schedule))->toBe(0, $label);
        }

        // Leg by leg: the first leg again, or the first leg reversed
        $first = mirroredLegRoles($schedule, 1, $roundsPerLeg);
        for ($leg = 2; $leg <= $legs; ++$leg) {
            expect(mirroredLegRoles($schedule, $leg, $roundsPerLeg, reversed: $leg % 2 === 0))
                ->toBe($first, "{$label}, leg {$leg}");
        }
    }
})
    ->with([[1], [2], [3], [4], [5], [6]])
    ->with([['round_parity'], ['balanced']]);

// A scheduler with a randomizer shuffles the field for the first leg only,
// so its later legs are not derived from its first (docs/USAGE.md, "Legs
// after the first"). The later legs alternate among themselves all the
// same: leg 3 is leg 2 reversed, leg 4 is leg 2 again. A pairing is then
// one apart over an odd number of legs, and level or two apart over an even
// number, where leg 1 decides which.
it('alternates the legs after the first of a mirrored schedule from a scheduler that shuffles the field', function (
    int $legs,
    string $roleAssignmentName
): void {
    for ($size = 2; $size <= 30; ++$size) {
        $label = "{$legs} legs, {$size} participants";
        $participants = completenessParticipants($size);
        $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;

        $schedule = (new RoundRobinScheduler(null, new Randomizer(new Mt19937(300 + $size))))->schedule(
            $participants,
            new RoundRobinOptions(
                legs: $legs,
                strategy: new MirroredLegStrategy(),
                roleAssignment: completenessRoleAssignment($roleAssignmentName)
            )
        );

        expect(RoundRobinAudit::faults($schedule, $participants, $legs))->toBe([], $label);

        $second = mirroredLegRoles($schedule, 2, $roundsPerLeg);
        for ($leg = 3; $leg <= $legs; ++$leg) {
            expect(mirroredLegRoles($schedule, $leg, $roundsPerLeg, reversed: $leg % 2 === 1))
                ->toBe($second, "{$label}, leg {$leg}");
        }

        $splits = array_unique(RoleCounts::pairingSplits($schedule));
        expect(array_values(array_diff($splits, $legs % 2 === 1 ? [1] : [0, 2])))->toBe([], $label);
    }
})
    ->with([[3], [4], [5], [6]])
    ->with([['round_parity'], ['balanced']]);

/**
 * The leg strategy the 0.3.0 changelog gives to keep the roles of 0.2.x:
 * every leg after the first reversed.
 */
function everyLaterLegReversed(): LegStrategyInterface
{
    return new class implements LegStrategyInterface {
        #[Override]
        public function planLegs(array $participants, int $legs, ConstraintSet $constraints): LegPlanContribution
        {
            return new LegPlanContribution(rolesMirrorAcrossLegs: true, requiresRandomization: false);
        }

        #[Override]
        public function generateEventForLeg(array $participants, int $leg, int $round, SchedulingContext $context): Event
        {
            return new Event($leg === 1 ? $participants : array_reverse($participants), new Round($round));
        }
    };
}

/**
 * The events of the given rounds as the changelog prints them: "R7: 1-4 2-3".
 *
 * @param list<int> $rounds
 * @return list<string>
 */
function mirroredRoundsAsPrinted(Schedule $schedule, array $rounds): array
{
    $lines = [];
    foreach ($schedule as $event) {
        $round = $event->getRound()?->getNumber() ?? 0;
        if (in_array($round, $rounds, true)) {
            $ids = array_map(static fn(Participant $participant): string => substr($participant->getId(), 1), $event->getParticipants());
            $lines[$round] = ($lines[$round] ?? "R{$round}:") . ' ' . implode('-', $ids);
        }
    }

    return array_values($lines);
}

// What the 0.3.0 changelog entry for mirrored legs tells a reader to expect.
// The changelog is not one of the documents whose snippets the suite runs,
// so its table and its migration note are held here:
//
// - the third leg of four participants, before and after;
// - a leg strategy of the caller's own that reverses every leg after the
//   first gives the roles of 0.2.x (the "before" column, and the splits of
//   1:2 and 1:3);
// - from a scheduler without a randomizer no role limit that held is known
//   to be refused now, and from one with a randomizer one is.
it('gives the third leg of four participants as the 0.3.0 changelog prints it, before and after', function (): void {
    $participants = completenessParticipants(4);
    $schedule = static fn(LegStrategyInterface $strategy, int $legs): Schedule => (new RoundRobinScheduler())
        ->schedule($participants, new RoundRobinOptions(legs: $legs, strategy: $strategy));

    $after = $schedule(new MirroredLegStrategy(), 3);
    $before = $schedule(everyLaterLegReversed(), 3);

    expect(mirroredRoundsAsPrinted($after, [7, 8, 9]))->toBe(['R7: 1-4 2-3', 'R8: 2-1 4-3', 'R9: 1-3 4-2'])
        ->and(mirroredRoundsAsPrinted($before, [7, 8, 9]))->toBe(['R7: 4-1 3-2', 'R8: 1-2 3-4', 'R9: 3-1 2-4'])
        // "rounds 1 to 6, the first two legs, are the same in both"
        ->and(mirroredRoundsAsPrinted($before, [1, 2, 3, 4, 5, 6]))->toBe(mirroredRoundsAsPrinted($after, [1, 2, 3, 4, 5, 6]));

    // "The same field over four legs had one participant first-named in nine
    // events in a row and ended 6 apart; it now repeats a role three times
    // at most and ends level"
    expect(RoleCounts::worstStreak($schedule(everyLaterLegReversed(), 4)))->toBe(9)
        ->and(RoleCounts::worstEndImbalance($schedule(everyLaterLegReversed(), 4)))->toBe(6)
        ->and(RoleCounts::worstStreak($schedule(new MirroredLegStrategy(), 4)))->toBe(3)
        ->and(RoleCounts::worstEndImbalance($schedule(new MirroredLegStrategy(), 4)))->toBe(0);

    // The caller's own strategy splits every pair 1:2 and 1:3, as 0.2.x did
    expect(array_values(array_unique(RoleCounts::pairingSplits($before))))->toBe([1])
        ->and(array_values(array_unique(RoleCounts::pairingSplits($schedule(everyLaterLegReversed(), 4)))))->toBe([2]);
});

// The alternation can refuse a role limit that the roles of 0.2.x satisfied,
// and the changelog says where: a scheduler with a randomizer. Its second leg
// is not its first reversed, so where legs 1 and 2 meet is a matter of the
// seed. Where legs 2 and 3 meet is not: they are laid out from the same
// order, so 0.2.x repeated leg 2 there and a third leg now meets the second
// as two mirrored legs of a scheduler without a randomizer meet. In a field
// of five that is a run of four in one role (the figure pinned in
// DefaultRoleAssignmentTest), so a limit of 3 is refused whatever the seed.
//
// This records what the roles reach, like the rows for repeated legs under
// "Documented values": it is the cost of the change, not a promise to keep.
it('refuses over three mirrored legs from a scheduler that shuffles the field a streak limit that the roles of 0.2.x satisfied', function (
    int $seed
): void {
    $participants = completenessParticipants(5);
    $limit = ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway(3))->build();
    $generate = static fn(LegStrategyInterface $strategy, int $legs): Schedule => (new RoundRobinScheduler(
        $limit,
        new Randomizer(new Mt19937($seed))
    ))->schedule($participants, new RoundRobinOptions(legs: $legs, strategy: $strategy));

    // Two legs are what they were, and complete
    expect($generate(new MirroredLegStrategy(), 2)->toArray())->toBe($generate(everyLaterLegReversed(), 2)->toArray());

    expect(RoundRobinAudit::faults($generate(everyLaterLegReversed(), 3), $participants, 3))->toBe([]);
    expect(fn() => $generate(new MirroredLegStrategy(), 3))->toThrow(IncompleteScheduleException::class);
})->with([[1], [2], [3], [4], [5]]);

// Without a randomizer nothing of the kind is known, and for the two role
// constraints there is a reason: every boundary between two alternating legs
// is the boundary between legs 1 and 2 or that one with the roles exchanged,
// which 0.2.x had as well, and its other boundary (a leg repeated) is gone.
// A limit that 0.2.x satisfied over three legs or more is therefore still
// satisfied. Held here for each constraint at every limit from 1 to 5.
it('completes with alternating mirrored legs every role limit that completed with the roles of 0.2.x, without a randomizer', function (
    int $legs,
    string $roleAssignmentName
): void {
    $completedBefore = 0;

    for ($size = 2; $size <= 8; ++$size) {
        $participants = completenessParticipants($size);

        foreach (['consecutive roles', 'role balance'] as $constraintName) {
            for ($limit = 1; $limit <= 5; ++$limit) {
                $completes = static function (LegStrategyInterface $strategy) use ($participants, $constraintName, $limit, $legs, $roleAssignmentName): bool {
                    $constraints = ConstraintSet::create()->add($constraintName === 'role balance'
                        ? RoleBalanceConstraint::homeAway($limit)
                        : ConsecutiveRoleConstraint::homeAway($limit))->build();

                    try {
                        (new RoundRobinScheduler($constraints))->schedule($participants, new RoundRobinOptions(
                            legs: $legs,
                            strategy: $strategy,
                            roleAssignment: completenessRoleAssignment($roleAssignmentName)
                        ));
                    } catch (IncompleteScheduleException) {
                        return false;
                    }

                    return true;
                };

                if (!$completes(everyLaterLegReversed())) {
                    continue;
                }

                ++$completedBefore;
                expect($completes(new MirroredLegStrategy()))
                    ->toBeTrue("{$constraintName} at {$limit}, {$legs} legs, {$size} participants");
            }
        }
    }

    // The premise: there were configurations to compare
    expect($completedBefore)->toBeGreaterThan(10);
})
    ->with([[3], [4]])
    ->with([['round_parity'], ['balanced']]);

// The backtracking search finds the first leg and the scheduler derives the
// later legs from that leg's events through the leg strategy, on a path of
// its own. The placements are ones no rotation of the circle method
// satisfies, held in every leg; they say nothing about roles.
it('alternates the mirrored legs derived from a first leg that only the backtracking search finds', function (
    int $size,
    array $placement,
    int $legs,
    string $roleAssignmentName
): void {
    $participants = completenessParticipants($size);
    $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
    $constraints = mirroredLegPlacement($placement, $roundsPerLeg);
    $options = static fn(bool $backtracking): RoundRobinOptions => new RoundRobinOptions(
        $legs,
        new MirroredLegStrategy(),
        $backtracking,
        completenessRoleAssignment($roleAssignmentName)
    );

    // The premise: without the search there is no schedule
    expect(fn() => (new RoundRobinScheduler($constraints))->schedule($participants, $options(false)))
        ->toThrow(IncompleteScheduleException::class);

    $schedule = (new RoundRobinScheduler($constraints))->schedule($participants, $options(true));

    expect(RoundRobinAudit::faults($schedule, $participants, $legs))->toBe([]);
    expect(array_values(array_unique(RoleCounts::pairingSplits($schedule))))->toBe([$legs % 2]);
    if ($legs % 2 === 0) {
        expect(RoleCounts::worstEndImbalance($schedule))->toBe(0);
    }

    $first = mirroredLegRoles($schedule, 1, $roundsPerLeg);
    for ($leg = 2; $leg <= $legs; ++$leg) {
        expect(mirroredLegRoles($schedule, $leg, $roundsPerLeg, reversed: $leg % 2 === 0))->toBe($first, "leg {$leg}");
    }
})
    ->with([
        'four participants' => [4, ['p1|p3' => 1, 'p1|p2' => 2, 'p1|p4' => 3]],
        'five participants, with byes' => [5, ['p1|p2' => 5, 'p1|p3' => 4]],
        'six participants' => [6, ['p1|p2' => 1, 'p1|p3' => 2, 'p1|p4' => 3, 'p1|p6' => 4, 'p1|p5' => 5]],
    ])
    ->with([[2], [3], [4], [5], [6]])
    ->with([['round_parity'], ['balanced']]);

// The constraints on roles read the roles a leg derives. With alternating
// legs, what two mirrored legs satisfy any number of them satisfies:
//
// - ConsecutiveRoleConstraint: every boundary between two legs is the one
//   between legs 1 and 2, or that one with both roles exchanged, so the
//   longest run of one role over two legs is the longest over any number.
// - RoleBalanceConstraint: every two legs end level, so the running totals
//   of legs 3 and 4 are those of legs 1 and 2 again.
//
// The limit is the one two legs need, counted in the events of the
// unconstrained schedule. A constraint that accepts every event changes
// nothing, so the schedule must be the unconstrained one. Before 0.3.0 a
// third leg repeated the roles of the second: four participants reached a
// run of 6 against a limit of 3, and four legs drifted 6 apart.
it('satisfies over any number of mirrored legs the role limits that two mirrored legs satisfy', function (
    string $constraintName,
    string $roleAssignmentName
): void {
    for ($size = 2; $size <= 30; ++$size) {
        $participants = completenessParticipants($size);
        $options = static fn(int $legs): RoundRobinOptions => new RoundRobinOptions(
            legs: $legs,
            strategy: new MirroredLegStrategy(),
            roleAssignment: completenessRoleAssignment($roleAssignmentName)
        );

        $twoLegs = (new RoundRobinScheduler())->schedule($participants, $options(2));
        $constraints = ConstraintSet::create()->add(match ($constraintName) {
            'consecutive roles' => ConsecutiveRoleConstraint::homeAway(RoleCounts::worstStreak($twoLegs)),
            'role balance' => RoleBalanceConstraint::homeAway(RoleCounts::worstRunningImbalance($twoLegs)),
            default => throw new UnexpectedValueException($constraintName),
        })->build();

        for ($legs = 2; $legs <= 6; ++$legs) {
            $label = "{$legs} legs, {$size} participants";

            $unconstrained = (new RoundRobinScheduler())->schedule($participants, $options($legs));

            // The constraints are put to three and four legs, the smallest
            // odd and even numbers the alternation changes, in fields of up
            // to 20: a role constraint reads a participant's whole history
            // for every event, so the 30 by 6 sweep takes most of a minute.
            // The counts below cover every size and leg count
            if ($size <= 20 && ($legs === 3 || $legs === 4)) {
                $constrained = (new RoundRobinScheduler($constraints))->schedule($participants, $options($legs));

                expect($constrained->toArray())->toBe($unconstrained->toArray(), $label);
            }

            expect(RoleCounts::worstStreak($unconstrained))->toBe(RoleCounts::worstStreak($twoLegs), $label)
                ->and(RoleCounts::worstRunningImbalance($unconstrained))
                ->toBe(RoleCounts::worstRunningImbalance($twoLegs), $label);

            // The limit the usage guide gives for the default roles
            // ("Role-Based Constraints") is therefore enough for any number
            if ($roleAssignmentName === 'round_parity') {
                expect(RoleCounts::worstRunningImbalance($unconstrained))
                    ->toBeLessThanOrEqual($size % 2 === 0 ? 3 : 4, $label);
            }
        }
    }
})
    ->with([['consecutive roles'], ['role balance']])
    ->with([['round_parity'], ['balanced']]);

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
