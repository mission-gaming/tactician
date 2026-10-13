<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\RoleBalanceConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\SchedulingException;
use MissionGaming\Tactician\LegStrategies\LegPlanContribution;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use MissionGaming\Tactician\Scheduling\LegRoleSearch;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Stage\RoundRobinPlan;
use MissionGaming\Tactician\Tests\Support\RoleCounts;
use MissionGaming\Tactician\Tests\Support\RoleScheduleOracle;

/**
 * A leg strategy that keeps the pairing as given and writes down every
 * question it is asked.
 */
final class RecordingLegStrategy implements LegStrategyInterface
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param list<int> $silentRounds Rounds it returns no event for
     */
    public function __construct(private readonly array $silentRounds = []) {}

    #[Override]
    public function planLegs(array $participants, int $legs, ConstraintSet $constraints): LegPlanContribution
    {
        return new LegPlanContribution(false, false);
    }

    #[Override]
    public function generateEventForLeg(array $participants, int $leg, int $round, SchedulingContext $context): ?Event
    {
        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $participants);
        $this->asked[] = "leg {$leg}, round {$round}: " . implode(' v ', $ids) . ' after ' . $context->getEventCount();

        return in_array($round, $this->silentRounds, true) ? null : new Event($participants, new Round($round));
    }
}

/**
 * @return list<Participant>
 */
function roleSearchField(int $size): array
{
    $field = [];
    for ($number = 1; $number <= $size; ++$number) {
        $field[] = new Participant("p{$number}", "P{$number}");
    }

    return $field;
}

/**
 * The first leg of the field as the scheduler lays it out without
 * constraints, and the slots of the legs after it: the same pairings in
 * the same rounds of the leg, in the roles of the first leg. The roles are
 * the ones the generator proposes (round parity): they are what leaves the
 * search something to do, where the balanced roles of the default would not.
 *
 * @param list<Participant> $field
 * @return array{list<Event>, list<array{leg: int, round: int, pairing: array<Participant>}>}
 * @throws SchedulingException
 */
function roleSearchLaterSlots(array $field, int $legs): array
{
    $legOne = (new RoundRobinScheduler())
        ->schedule($field, new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment()))
        ->getEvents();
    $roundsPerLeg = count($field) % 2 === 0 ? count($field) - 1 : count($field);

    $slots = [];
    for ($leg = 2; $leg <= $legs; ++$leg) {
        foreach ($legOne as $event) {
            $slots[] = [
                'leg' => $leg,
                'round' => ($event->getRound()?->getNumber() ?? 0) + ($leg - 1) * $roundsPerLeg,
                'pairing' => $event->getParticipants(),
            ];
        }
    }

    return [array_values($legOne), $slots];
}

describe('LegRoleSearch', function (): void {
    // A limit of two in a row, once as the library's constraint, whose
    // verdict depends on the events of the two participants alone, and
    // once as a closure, about which the search can assume nothing.
    $streakOfTwo = [
        'the constraint class' => [static fn(): ConstraintSet => ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway(2))->build()],
        'a closure' => [static fn(): ConstraintSet => ConstraintSet::create()->custom(
            static function (Event $event, SchedulingContext $context): bool {
                foreach ($event->getParticipants() as $role => $participant) {
                    $run = 1;
                    foreach (array_reverse($context->getEventsForParticipant($participant)) as $earlier) {
                        if ($earlier->getParticipants()[$role]->getId() !== $participant->getId()) {
                            break;
                        }
                        ++$run;
                    }
                    if ($run > 2) {
                        return false;
                    }
                }

                return true;
            },
            'Two in a row'
        )->build()],
    ];

    it('finds roles for shuffled legs that the draws alone do not give', function (Closure $constraints): void {
        $field = roleSearchField(8);
        $plan = new RoundRobinPlan($field, 3);
        [$legOne, $slots] = roleSearchLaterSlots($field, 3);

        // What the draws give when nothing is rejected
        $drawn = (new LegRoleSearch(null, new ShuffledLegStrategy(seed: 4), true, false))
            ->search(new SchedulingContext($field, $plan, $legOne, 2), $slots, PHP_INT_MAX, PHP_INT_MAX);

        $search = new LegRoleSearch($constraints(), new ShuffledLegStrategy(seed: 4), true, false);
        $events = $search->search(new SchedulingContext($field, $plan, $legOne, 2), $slots, PHP_INT_MAX, LegRoleSearch::REJECTION_LIMIT);

        expect($drawn)->toHaveCount(56)
            ->and(RoleCounts::worstStreak([...$legOne, ...$drawn ?? []]))->toBeGreaterThan(2)
            ->and($events)->toHaveCount(56)
            ->and(RoleCounts::worstStreak([...$legOne, ...$events ?? []]))->toBe(2)
            // Roles only: the pairings and their rounds are the slots'
            ->and(RoleCounts::structure($events ?? []))->toBe(RoleCounts::structure($drawn ?? []))
            ->and($search->wasStoppedByABound())->toBeFalse()
            ->and($search->attempts())->toBeGreaterThan(56);
    })->with($streakOfTwo);

    it('goes straight back to an event of the same participants when the constraints read no others', function (): void {
        $field = roleSearchField(12);
        $plan = new RoundRobinPlan($field, 3);
        [$legOne, $slots] = roleSearchLaterSlots($field, 3);
        $context = new SchedulingContext($field, $plan, $legOne, 2);

        $attempts = [];
        foreach ($streakOfTwoSets = [
            'directed' => ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway(2))->build(),
            // The same constraint in a set the search knows nothing about
            'one at a time' => ConstraintSet::create()
                ->add(ConsecutiveRoleConstraint::homeAway(2))
                ->custom(static fn(): bool => true, 'Accepts everything')
                ->build(),
        ] as $name => $constraints) {
            $search = new LegRoleSearch($constraints, new ShuffledLegStrategy(seed: 9), true, false);
            $events = $search->search($context, $slots, PHP_INT_MAX, PHP_INT_MAX);

            expect($events)->toHaveCount(132, $name)
                ->and(RoleCounts::worstStreak([...$legOne, ...$events ?? []]))->toBe(2);
            $attempts[$name] = $search->attempts();
        }

        expect(count($streakOfTwoSets))->toBe(2)
            ->and($attempts['directed'])->toBeLessThan($attempts['one at a time']);
    });

    it('asks a strategy of preferences once for each event, in order, however far it goes back', function (): void {
        $field = roleSearchField(4);
        $plan = new RoundRobinPlan($field, 2);
        [$legOne, $slots] = roleSearchLaterSlots($field, 2);
        $strategy = new RecordingLegStrategy();

        // The last event of the leg is refused as the strategy gives it
        // and as its reverse until the first event of the leg is reversed
        $firstSlot = $slots[0]['pairing'];
        $constraints = ConstraintSet::create()->custom(
            static function (Event $event, SchedulingContext $context) use ($firstSlot): bool {
                if ($context->getEventCount() < 11) {
                    return true;
                }
                $first = $context->getExistingEvents()[6]->getParticipants();

                return $first[0]->getId() === $firstSlot[1]->getId();
            },
            'Needs the first event reversed'
        )->build();

        $search = new LegRoleSearch($constraints, $strategy, true, true);
        $events = $search->search(new SchedulingContext($field, $plan, $legOne, 2), $slots, PHP_INT_MAX, PHP_INT_MAX);

        expect($events)->toHaveCount(6)
            ->and(($events ?? [])[0]->getParticipants()[0]->getId())->toBe($firstSlot[1]->getId())
            // Six questions for six events, each with the events before it
            ->and($strategy->asked)->toHaveCount(6)
            ->and(array_map(static fn(string $question): string => explode(' after ', $question)[1] ?? '', $strategy->asked))
            ->toBe(['6', '7', '8', '9', '10', '11'])
            ->and($search->attempts())->toBeGreaterThan(12);
    });

    it('ends at the first rejected event of a strategy whose roles are rules', function (): void {
        $field = roleSearchField(4);
        $plan = new RoundRobinPlan($field, 2);
        [$legOne, $slots] = roleSearchLaterSlots($field, 2);
        $strategy = new RecordingLegStrategy();
        $constraints = ConstraintSet::create()
            ->custom(static fn(Event $event): bool => $event->getRound()?->getNumber() !== 5, 'Round 5 closed')
            ->build();

        $search = new LegRoleSearch($constraints, $strategy, false, false);
        $events = $search->search(new SchedulingContext($field, $plan, $legOne, 2), $slots, PHP_INT_MAX, PHP_INT_MAX);

        expect($events)->toBeNull()
            ->and($search->wasStoppedByABound())->toBeFalse()
            ->and($search->slotWithoutEvent())->toBeNull()
            // Two events of round 4 accepted, the first of round 5 refused
            ->and($search->attempts())->toBe(3)
            ->and($search->furthestEvents())->toHaveCount(2)
            ->and($search->furthestRejections())->toHaveCount(1)
            ->and($search->furthestRejections()[0][0]->getRound()?->getNumber())->toBe(5)
            ->and($strategy->asked)->toHaveCount(3);
    });

    it('says that no roles exist only when it has tried them all', function (): void {
        $field = roleSearchField(4);
        $plan = new RoundRobinPlan($field, 2);
        [$legOne, $slots] = roleSearchLaterSlots($field, 2);
        $closed = ConstraintSet::create()
            ->custom(static fn(Event $event): bool => $event->getRound()?->getNumber() !== 6, 'Round 6 closed')
            ->build();
        $context = new SchedulingContext($field, $plan, $legOne, 2);

        // Four events before round 6, each in two roles: 16 ways to reach
        // the event that is refused in both of its roles
        $whole = new LegRoleSearch($closed, new ShuffledLegStrategy(seed: 1), true, false);
        expect($whole->search($context, $slots, PHP_INT_MAX, PHP_INT_MAX))->toBeNull()
            ->and($whole->wasStoppedByABound())->toBeFalse()
            ->and($whole->furthestEvents())->toHaveCount(4)
            ->and($whole->furthestRejections())->toHaveCount(2)
            ->and($whole->attempts())->toBe(2 + 4 + 8 + 16 + 32);

        $rejectionBound = new LegRoleSearch($closed, new ShuffledLegStrategy(seed: 1), true, false);
        expect($rejectionBound->search($context, $slots, PHP_INT_MAX, 10))->toBeNull()
            ->and($rejectionBound->wasStoppedByABound())->toBeTrue();

        $attemptBound = new LegRoleSearch($closed, new ShuffledLegStrategy(seed: 1), true, false);
        expect($attemptBound->search($context, $slots, 20, PHP_INT_MAX))->toBeNull()
            ->and($attemptBound->wasStoppedByABound())->toBeTrue()
            ->and($attemptBound->attempts())->toBe(20);

        // A bound that is not reached stops nothing
        $roomy = new LegRoleSearch($closed, new ShuffledLegStrategy(seed: 1), true, false);
        expect($roomy->search($context, $slots, 62, 32))->toBeNull()
            ->and($roomy->wasStoppedByABound())->toBeFalse();
    });

    it('accepts every event at once without constraints, and is not stopped by a rejection limit of none', function (): void {
        $field = roleSearchField(6);
        $plan = new RoundRobinPlan($field, 4);
        [$legOne, $slots] = roleSearchLaterSlots($field, 4);

        $search = new LegRoleSearch(null, new RepeatedLegStrategy(), false, false);
        $events = $search->search(new SchedulingContext($field, $plan, $legOne, 2), $slots, PHP_INT_MAX, 0);

        expect($events)->toHaveCount(45)
            ->and($search->attempts())->toBe(45)
            ->and($search->wasStoppedByABound())->toBeFalse();
    });

    it('names the slot a strategy returns no event for', function (): void {
        $field = roleSearchField(4);
        $plan = new RoundRobinPlan($field, 2);
        [$legOne, $slots] = roleSearchLaterSlots($field, 2);

        $search = new LegRoleSearch(null, new RecordingLegStrategy([5]), true, false);

        expect($search->search(new SchedulingContext($field, $plan, $legOne, 2), $slots, PHP_INT_MAX, PHP_INT_MAX))->toBeNull()
            ->and($search->slotWithoutEvent())->toBe($slots[2])
            ->and($search->furthestEvents())->toHaveCount(2)
            ->and($search->wasStoppedByABound())->toBeFalse();
    });

    it('shows a constraint the earlier rounds, and the event\'s own round only when told to', function (bool $ownRound, array $expected): void {
        $field = roleSearchField(4);
        $plan = new RoundRobinPlan($field, 3);
        [$legOne, $slots] = roleSearchLaterSlots($field, 3);

        $seen = [];
        $constraints = ConstraintSet::create()->custom(
            static function (Event $event, SchedulingContext $context) use (&$seen): bool {
                $seen[] = $context->getCurrentLeg() . ':' . $context->getEventCount();

                return true;
            },
            'Watches'
        )->build();

        (new LegRoleSearch($constraints, new RepeatedLegStrategy(), false, $ownRound))
            ->search(new SchedulingContext($field, $plan, $legOne, 2), $slots, PHP_INT_MAX, PHP_INT_MAX);

        expect($seen)->toBe($expected);
    })->with([
        'earlier rounds only' => [false, ['2:6', '2:6', '2:8', '2:8', '2:10', '2:10', '3:12', '3:12', '3:14', '3:14', '3:16', '3:16']],
        'its own round too' => [true, ['2:6', '2:7', '2:8', '2:9', '2:10', '2:11', '3:12', '3:13', '3:14', '3:15', '3:16', '3:17']],
    ]);

    // With the first leg among the slots, a later leg that is a rule can
    // change after all: by the first-leg event it is derived from.
    it('reverses an event of the first leg when a repeated leg needs it', function (Closure $constraints): void {
        $field = roleSearchField(8);
        $plan = new RoundRobinPlan($field, 2);
        [$legOne] = roleSearchLaterSlots($field, 1);

        $slots = [];
        foreach ($legOne as $event) {
            $slots[] = ['leg' => 1, 'round' => $event->getRound()?->getNumber() ?? 0, 'pairing' => $event->getParticipants(), 'event' => $event];
        }
        foreach ($legOne as $source => $event) {
            $slots[] = ['leg' => 2, 'round' => ($event->getRound()?->getNumber() ?? 0) + 7, 'pairing' => $event->getParticipants(), 'source' => $source];
        }

        $search = new LegRoleSearch($constraints(), new RepeatedLegStrategy(), false, true);
        $events = $search->search(new SchedulingContext($field, $plan, [], 1), $slots, PHP_INT_MAX, LegRoleSearch::REJECTION_LIMIT);

        // The leg as laid out, played twice, has three in a row
        expect(RoleCounts::worstStreak([...$legOne, ...$legOne]))->toBe(3)
            ->and($events)->toHaveCount(56)
            ->and(RoleCounts::worstStreak($events ?? []))->toBe(2);

        $first = array_slice($events ?? [], 0, 28);
        $second = array_slice($events ?? [], 28);
        expect(RoleCounts::structure($first))->toBe(RoleCounts::structure($legOne))
            ->and($first)->not->toEqual($legOne);
        foreach ($second as $index => $event) {
            expect($event->getParticipants())->toBe($first[$index]->getParticipants())
                ->and($event->getRound()?->getNumber())->toBe(($first[$index]->getRound()?->getNumber() ?? 0) + 7);
        }
    })->with($streakOfTwo);
});

// The first leg under a role assignment that states a rule (issue #51):
// fixed events, the rule itself, and a first leg the caller does not keep.

/**
 * The slots of a schedule whose first leg is among them: its events, free
 * or fixed, and after them every later leg derived from it.
 *
 * @param list<Event> $legOne
 * @param array<int, true>|bool $fixed The first-leg events that keep their role: all, none or those at these indexes
 * @return list<array{leg: int, round: int, pairing: array<Participant>, event?: Event, source?: int, fixed?: bool}>
 */
function roleSearchWholeSlots(array $legOne, int $legs, int $roundsPerLeg, array|bool $fixed = false): array
{
    $slots = [];
    foreach ($legOne as $index => $event) {
        $slot = ['leg' => 1, 'round' => $event->getRound()?->getNumber() ?? 0, 'pairing' => $event->getParticipants(), 'event' => $event];
        if (is_array($fixed) ? isset($fixed[$index]) : $fixed) {
            $slot['fixed'] = true;
        }
        $slots[] = $slot;
    }
    for ($leg = 2; $leg <= $legs; ++$leg) {
        foreach ($legOne as $source => $event) {
            $slots[] = [
                'leg' => $leg,
                'round' => ($event->getRound()?->getNumber() ?? 0) + ($leg - 1) * $roundsPerLeg,
                'pairing' => $event->getParticipants(),
                'source' => $source,
            ];
        }
    }

    return $slots;
}

/**
 * "$first is first-named whenever it meets $second", as a closure: a rule
 * about one pairing that tells the search nothing about what it reads.
 */
function roleSearchPin(string $first, string $second): ConstraintSet
{
    return ConstraintSet::create()->custom(
        static function (Event $event) use ($first, $second): bool {
            $pair = $event->getParticipants();

            return !($pair[0]->getId() === $second && $pair[1]->getId() === $first);
        },
        'Pinned role'
    )->build();
}

describe('LegRoleSearch over a first leg', function (): void {
    // A replay: every event of the first leg has the one role it is given,
    // so the search says whether these roles stand, and where they first do
    // not. Six participants, one leg; the rule wants the pairing of the
    // leg's tenth event the other way round.
    it('keeps a fixed event of the first leg as it is, and says where the roles first fail', function (): void {
        $field = roleSearchField(6);
        $plan = new RoundRobinPlan($field, 1);
        [$legOne] = roleSearchLaterSlots($field, 1);
        [$first, $second] = $legOne[9]->getParticipants();
        $constraints = roleSearchPin($second->getId(), $first->getId());
        $start = new SchedulingContext($field, $plan, [], 1);

        $replay = new LegRoleSearch($constraints, new RepeatedLegStrategy(), false, false);
        $events = $replay->search($start, roleSearchWholeSlots($legOne, 1, 5, true), PHP_INT_MAX, LegRoleSearch::REJECTION_LIMIT);

        expect($events)->toBeNull()
            ->and($replay->wasStoppedByABound())->toBeFalse()
            ->and($replay->furthestSlot())->toBe(9)
            ->and($replay->furthestEvents())->toBe(array_slice($legOne, 0, 9))
            ->and($replay->furthestRejections())->toHaveCount(1)
            ->and($replay->furthestRejections()[0][0])->toBe($legOne[9])
            // One attempt for each event up to the rejected one, and nothing reversed
            ->and($replay->attempts())->toBe(10)
            ->and($replay->rejections())->toBe(1);

        // The same slots with that one event free: it is reversed, and no other
        $free = new LegRoleSearch($constraints, new RepeatedLegStrategy(), false, false);
        $fixedButOne = array_fill_keys(array_diff(range(0, 14), [9]), true);
        $events = $free->search($start, roleSearchWholeSlots($legOne, 1, 5, $fixedButOne), PHP_INT_MAX, LegRoleSearch::REJECTION_LIMIT);

        expect($events)->toHaveCount(15)
            ->and($free->furthestSlot())->toBeNull();
        foreach ($events ?? [] as $index => $event) {
            expect($event->getParticipants())->toBe($index === 9 ? [$second, $first] : $legOne[$index]->getParticipants());
        }
    });

    // The rule of the balanced role assignment, with a pinned role that the
    // roles it gives break: the search reverses that event and goes on
    // reversing others until the leg is balanced again. Without the rule it
    // reverses the one event and returns a leg that is out of balance.
    it('holds every choice of roles for the first leg to the rule it is given', function (int $size): void {
        $field = roleSearchField($size);
        $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
        $plan = new RoundRobinPlan($field, 1);
        $legOne = array_values((new RoundRobinScheduler())->schedule($field)->getEvents());
        $start = new SchedulingContext($field, $plan, [], 1);
        $bound = $size % 2 === 0 ? 1 : 0;

        // A pairing whose reversal leaves the leg out of balance
        $pinned = null;
        foreach ($legOne as $index => $event) {
            $reversed = $legOne;
            $reversed[$index] = new Event(array_reverse($event->getParticipants()), $event->getRound());
            if (RoleCounts::worstEndImbalance($reversed) > $bound) {
                $pinned = $event;
                break;
            }
        }
        expect($pinned)->toBeInstanceOf(Event::class);
        [$first, $second] = $pinned?->getParticipants() ?? [];
        $constraints = roleSearchPin($second->getId(), $first->getId());
        $slots = roleSearchWholeSlots($legOne, 1, $roundsPerLeg);

        $unruled = (new LegRoleSearch($constraints, new RepeatedLegStrategy(), false, false))
            ->search($start, $slots, PHP_INT_MAX, LegRoleSearch::REJECTION_LIMIT);
        expect(RoleCounts::worstEndImbalance($unruled ?? []))->toBeGreaterThan($bound);

        $search = new LegRoleSearch($constraints, new RepeatedLegStrategy(), false, false, (new BalancedRoleAssignment())->rule());
        $events = $search->search($start, $slots, PHP_INT_MAX, LegRoleSearch::REJECTION_LIMIT);

        expect($events)->toHaveCount(count($legOne))
            ->and(RoleCounts::structure($events ?? []))->toBe(RoleCounts::structure($legOne))
            ->and(RoleCounts::worstEndImbalance($events ?? []))->toBe($bound);
        foreach ($events ?? [] as $event) {
            expect($event->getParticipants())->not->toBe([$first, $second]);
        }
    })->with([4, 5, 8, 9]);

    // A first leg the search changed is put to the caller once it is whole.
    // One that is not kept is a dead end, and the search goes on to the next
    // choice of roles; one it did not change is not asked about.
    it('does not return a first leg the caller does not keep, and goes on to one it does', function (): void {
        $field = roleSearchField(4);
        $plan = new RoundRobinPlan($field, 1);
        [$legOne] = roleSearchLaterSlots($field, 1);
        [$first, $second] = $legOne[0]->getParticipants();
        $constraints = roleSearchPin($second->getId(), $first->getId());
        $start = new SchedulingContext($field, $plan, [], 1);
        $slots = roleSearchWholeSlots($legOne, 1, 3);

        // Kept only with the last event of the leg reversed as well
        $asked = [];
        $keeps = static function (array $legEvents) use (&$asked, $legOne): bool {
            $asked[] = RoleScheduleOracle::read($legEvents);

            return $legEvents[5]->getParticipants() !== $legOne[5]->getParticipants();
        };

        $search = new LegRoleSearch($constraints, new RepeatedLegStrategy(), false, false, null, $keeps);
        $events = $search->search($start, $slots, PHP_INT_MAX, LegRoleSearch::REJECTION_LIMIT);

        expect($events)->toHaveCount(6)
            ->and(($events[0] ?? null)?->getParticipants())->toBe([$second, $first])
            ->and(($events[5] ?? null)?->getParticipants())->toBe(array_reverse($legOne[5]->getParticipants()))
            ->and($search->firstLegsNotKept())->toBe(1)
            // The leg that was refused, then the one that was returned
            ->and($asked)->toHaveCount(2)
            ->and($asked[1])->toBe(RoleScheduleOracle::read($events ?? []));

        // Nothing rejected: the leg as given is returned, and nobody is asked
        $asked = [];
        $untouched = new LegRoleSearch(null, new RepeatedLegStrategy(), false, false, null, $keeps);
        expect($untouched->search($start, $slots, PHP_INT_MAX, LegRoleSearch::REJECTION_LIMIT))->toBe($legOne)
            ->and($asked)->toBe([])
            ->and($untouched->firstLegsNotKept())->toBe(0);

        // A caller that keeps no leg but the one given: every other choice
        // of roles is refused, and the search ends without a bound stopping it
        $none = new LegRoleSearch($constraints, new RepeatedLegStrategy(), false, false, null, static fn(): bool => false);
        expect($none->search($start, $slots, PHP_INT_MAX, LegRoleSearch::REJECTION_LIMIT))->toBeNull()
            ->and($none->wasStoppedByABound())->toBeFalse()
            // The first event is held to one role, and each of the 2^5
            // choices for the five others is a whole leg that is refused
            ->and($none->firstLegsNotKept())->toBe(32);
    });

    // A first leg that is not kept counts against the limit of rejected
    // roles, so a caller that keeps nothing cannot keep the search going.
    it('counts a first leg that is not kept against its limit of rejections', function (): void {
        $field = roleSearchField(4);
        [$legOne] = roleSearchLaterSlots($field, 1);
        [$first, $second] = $legOne[0]->getParticipants();

        $search = new LegRoleSearch(
            roleSearchPin($second->getId(), $first->getId()),
            new RepeatedLegStrategy(),
            false,
            false,
            null,
            static fn(): bool => false
        );
        $events = $search->search(new SchedulingContext($field, new RoundRobinPlan($field, 1), [], 1), roleSearchWholeSlots($legOne, 1, 3), PHP_INT_MAX, 5);

        expect($events)->toBeNull()
            ->and($search->wasStoppedByABound())->toBeTrue()
            ->and($search->rejections())->toBe(6);
    });

    // Two mirrored legs of twelve under a limit of two in a row, which the
    // balanced roles of the circle rule break where the legs meet: the
    // search reverses two events of the first leg and has a balanced leg
    // that keeps the limit.
    it('finds a balanced first leg whose mirror keeps a limit the roles it was handed break', function (): void {
        $field = roleSearchField(12);
        $plan = new RoundRobinPlan($field, 2, true);
        $legOne = array_values((new RoundRobinScheduler())->schedule($field)->getEvents());
        $constraints = ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway(2))->build();

        // The premise: mirrored as they are, the legs break the limit
        $mirrored = [];
        foreach ($legOne as $event) {
            $mirrored[] = new Event(array_reverse($event->getParticipants()), new Round(($event->getRound()?->getNumber() ?? 0) + 11));
        }
        expect(RoleCounts::worstStreak([...$legOne, ...$mirrored]))->toBe(3);

        $search = new LegRoleSearch($constraints, new MirroredLegStrategy(), false, false, (new BalancedRoleAssignment())->rule());
        $events = $search->search(new SchedulingContext($field, $plan, [], 1), roleSearchWholeSlots($legOne, 2, 11), PHP_INT_MAX, LegRoleSearch::REJECTION_LIMIT);

        $reversed = 0;
        foreach ($legOne as $index => $event) {
            $reversed += ($events[$index] ?? null)?->getParticipants() === $event->getParticipants() ? 0 : 1;
        }

        expect($events)->toHaveCount(132)
            ->and(RoleCounts::worstStreak($events ?? []))->toBe(2)
            ->and(RoleCounts::worstEndImbalance(array_slice($events ?? [], 0, 66)))->toBe(1)
            ->and($reversed)->toBe(2)
            ->and($search->rejections())->toBeLessThan(100);
    });

    // Two mirrored legs of fourteen with the role counts never more than 1
    // apart: no balanced first leg has it, and the search shows that in a
    // few thousand rejections, because every rejection is one that only the
    // two participants' own events can lift. Beside a closure the set as a
    // whole is one whose verdict may turn on any earlier event. With a rule
    // for the first leg the search asks which constraint rejected, finds it
    // is the limit every time, and goes back exactly as it does without the
    // closure. Asked as a whole, the set sends it back one event at a time,
    // and it stops at its bound without an answer.
    it('goes straight back for a rejection that a constraint on the own events explains, beside a closure', function (): void {
        $field = roleSearchField(14);
        $plan = new RoundRobinPlan($field, 2, true);
        $legOne = array_values((new RoundRobinScheduler())->schedule($field)->getEvents());
        $slots = roleSearchWholeSlots($legOne, 2, 13);
        $start = new SchedulingContext($field, $plan, [], 1);
        $rule = (new BalancedRoleAssignment())->rule();
        $limit = static fn(): ConstraintSet => ConstraintSet::create()->add(RoleBalanceConstraint::homeAway(1))->build();
        $besideAClosure = static fn(): ConstraintSet => ConstraintSet::create()
            ->add(RoleBalanceConstraint::homeAway(1))
            ->custom(static fn(): bool => true, 'Accepts everything')
            ->build();

        $alone = new LegRoleSearch($limit(), new MirroredLegStrategy(), false, false, $rule);
        expect($alone->search($start, $slots, PHP_INT_MAX, LegRoleSearch::REJECTION_LIMIT))->toBeNull()
            ->and($alone->wasStoppedByABound())->toBeFalse();

        $explained = new LegRoleSearch($besideAClosure(), new MirroredLegStrategy(), false, false, $rule);
        expect($explained->search($start, $slots, PHP_INT_MAX, LegRoleSearch::REJECTION_LIMIT))->toBeNull()
            ->and($explained->wasStoppedByABound())->toBeFalse()
            ->and($explained->rejections())->toBe($alone->rejections())
            ->and($explained->attempts())->toBe($alone->attempts());

        // Without a rule the set is asked as a whole, as it always was
        $whole = new LegRoleSearch($besideAClosure(), new MirroredLegStrategy(), false, false);
        expect($whole->search($start, $slots, PHP_INT_MAX, LegRoleSearch::REJECTION_LIMIT))->toBeNull()
            ->and($whole->wasStoppedByABound())->toBeTrue();
    });
});
