<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\RoleBalanceConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\LegStrategies\LegPlanContribution;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentInterface;
use MissionGaming\Tactician\Scheduling\LegRoleSearch;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Tests\Support\RoleCounts;
use Random\Engine\Mt19937;
use Random\Randomizer;

// What the round-robin scheduler does with the roles of the legs after the
// first when a constraint rejects them: which roles it may change, what it
// says when it finds none, and that nothing changes where nothing is
// rejected. The sweep against an exhaustive oracle is in
// tests/Feature/RoleConstraintsAcrossLegsTest.php.

/**
 * A leg strategy of the caller's that keeps every pairing as given, and
 * says of its roles what it is told to say. It returns no event for the
 * rounds it is told to be silent in.
 */
final readonly class KeepingLegStrategy implements LegStrategyInterface
{
    /**
     * @param list<int> $silentRounds
     */
    public function __construct(private bool $rolesArePreferences, private array $silentRounds = []) {}

    #[Override]
    public function planLegs(array $participants, int $legs, ConstraintSet $constraints): LegPlanContribution
    {
        return new LegPlanContribution(
            rolesMirrorAcrossLegs: false,
            requiresRandomization: false,
            rolesArePreferences: $this->rolesArePreferences
        );
    }

    #[Override]
    public function generateEventForLeg(array $participants, int $leg, int $round, SchedulingContext $context): ?Event
    {
        return in_array($round, $this->silentRounds, true) ? null : new Event($participants, new Round($round));
    }
}

/**
 * A role assignment that names one participant first whenever it meets
 * another, and leaves every other seating as it is.
 */
final readonly class PinningRoleAssignment implements RoleAssignmentInterface
{
    public function __construct(private string $first, private string $second) {}

    #[Override]
    public function assignRoles(array $rounds): array
    {
        foreach ($rounds as $round => $seatings) {
            foreach ($seatings as $position => [$one, $other]) {
                if ($one?->getId() === $this->second && $other?->getId() === $this->first) {
                    $rounds[$round][$position] = [$other, $one];
                }
            }
        }

        return $rounds;
    }
}

/**
 * @return list<Participant>
 */
function roleSearchSchedulerField(int $size): array
{
    $field = [];
    for ($number = 1; $number <= $size; ++$number) {
        $field[] = new Participant("p{$number}", "P{$number}");
    }

    return $field;
}

/**
 * "$second is never first-named against $first from round $fromRound on.".
 */
function roleSearchPinned(string $first, string $second, int $fromRound): ConstraintSet
{
    return ConstraintSet::create()->custom(
        static function (Event $event) use ($first, $second, $fromRound): bool {
            $pair = $event->getParticipants();

            return !($pair[0]->getId() === $second && $pair[1]->getId() === $first
                && ($event->getRound()?->getNumber() ?? 0) >= $fromRound);
        },
        'Pinned role'
    )->build();
}

/**
 * The failure of a schedule that is expected to have none, or null when it
 * was generated.
 *
 * @param list<Participant> $field
 * @throws InvalidConfigurationException
 */
function roleSearchFailure(RoundRobinScheduler $scheduler, array $field, RoundRobinOptions $options): ?IncompleteScheduleException
{
    try {
        $scheduler->schedule($field, $options);
    } catch (IncompleteScheduleException $failure) {
        return $failure;
    }

    return null;
}

describe('what a leg strategy says about its roles', function (): void {
    it('is a rule unless the strategy says otherwise, and only the shuffled strategy says otherwise', function (): void {
        $none = ConstraintSet::create()->build();

        expect((new LegPlanContribution(false, false))->rolesArePreferences)->toBeFalse()
            ->and((new MirroredLegStrategy())->planLegs([], 2, $none)->rolesArePreferences)->toBeFalse()
            ->and((new RepeatedLegStrategy())->planLegs([], 2, $none)->rolesArePreferences)->toBeFalse()
            ->and((new ShuffledLegStrategy(seed: 1))->planLegs([], 2, $none)->rolesArePreferences)->toBeTrue()
            ->and((new ShuffledLegStrategy(new Randomizer(new Mt19937(1))))->planLegs([], 2, $none)->rolesArePreferences)->toBeTrue();
    });

    // Two legs of a strategy that keeps the roles of the first leg, and a
    // rule that wants every pair to meet the other way round the second
    // time. Every event of the second leg breaks it as the strategy gives
    // it, in whatever order the participants are laid out, and the reverse
    // of every one of them keeps it.
    $otherWayRound = static fn(): ConstraintSet => ConstraintSet::create()->custom(
        static function (Event $event, SchedulingContext $context): bool {
            [$first, $second] = $event->getParticipants();
            foreach ($context->getEventsBetween($first, $second) as $earlier) {
                if ($earlier->getParticipants()[0]->getId() === $first->getId()) {
                    return false;
                }
            }

            return true;
        },
        'The other way round'
    )->build();

    it('never reverses an event of a strategy whose roles are rules', function () use ($otherWayRound): void {
        $failure = roleSearchFailure(
            new RoundRobinScheduler($otherWayRound()),
            roleSearchSchedulerField(4),
            new RoundRobinOptions(2, new KeepingLegStrategy(false))
        );

        expect($failure)->toBeInstanceOf(IncompleteScheduleException::class)
            ->and($failure?->getMessage())->toBe(
                'Failed to generate complete schedule for leg 2. Generated 0 events, expected 6. Constraints may be preventing complete schedule generation.'
            );
    });

    // The roles of a repeated or mirrored leg follow from the first leg,
    // and the first leg is searched with backtracking only. A caller who
    // hits that boundary is told which option crosses it, when the
    // rejection came from a constraint the library knows to read roles.
    // The test above holds the message where it did not: a closure says
    // nothing about what it reads, so nothing is advised.
    it('names the option that searches the first leg when a role constraint rejects a leg that follows a rule', function (LegStrategyInterface $strategy): void {
        $field = roleSearchSchedulerField(8);
        $scheduler = new RoundRobinScheduler(ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway(2))->build());

        $failure = roleSearchFailure($scheduler, $field, new RoundRobinOptions(2, $strategy));

        expect($failure)->toBeInstanceOf(IncompleteScheduleException::class)
            ->and($failure?->getMessage())->toStartWith('Failed to generate complete schedule for leg 2. Generated ')
            ->toEndWith(sprintf(
                ' Constraints may be preventing complete schedule generation. A constraint on roles (Home/Away consecutive limit (2)) rejected an event of leg 2 in the roles %s gives it, which follow from the roles of leg 1 by a rule. The roles of leg 1 were not searched: RoundRobinOptions(backtracking: true) searches them.',
                $strategy::class
            ));
    })->with([
        'repeated legs' => [new RepeatedLegStrategy()],
        'a strategy of the caller that states a rule' => [new KeepingLegStrategy(false)],
    ]);

    it('reverses the events the constraints reject when the strategy states a preference', function () use ($otherWayRound): void {
        $field = roleSearchSchedulerField(4);
        $schedule = (new RoundRobinScheduler($otherWayRound()))->schedule($field, new RoundRobinOptions(2, new KeepingLegStrategy(true)));

        // Every event of the second leg reversed is the mirrored schedule
        $mirrored = (new RoundRobinScheduler())->schedule($field, new RoundRobinOptions(2, new MirroredLegStrategy()));

        expect($schedule->toJson())->toBe($mirrored->toJson());
    });

    // A constraint of the caller's may read any event. Here the verdict on
    // one event of the second leg depends on the role of an event that
    // shares no participant with it: the last pairing of the leg's first
    // round must be played the other way round than in the first leg
    // before the pairing after it, of two other participants, is accepted
    // in either role. A search that went straight back to an event of the
    // rejected event's own participants would pass over the one event that
    // matters and report that no roles exist; for a set that holds a
    // closure the search goes back one event at a time, and finds it.
    it('finds roles that depend on an event of other participants when the set holds a constraint of the caller', function (): void {
        $field = roleSearchSchedulerField(6);
        $options = static fn(): RoundRobinOptions => new RoundRobinOptions(2, new KeepingLegStrategy(true));
        $kept = (new RoundRobinScheduler())->schedule($field, $options())->getEvents();

        // Rounds 6 and 7 open the second leg, three events each
        $deciding = $kept[17];
        $dependent = null;
        foreach ([$kept[18], $kept[19], $kept[20]] as $candidate) {
            if (array_intersect(
                array_map(static fn(Participant $participant): string => $participant->getId(), $candidate->getParticipants()),
                array_map(static fn(Participant $participant): string => $participant->getId(), $deciding->getParticipants())
            ) === []) {
                $dependent = $candidate;
                break;
            }
        }

        expect($deciding->getRound()?->getNumber())->toBe(6)
            ->and($dependent)->toBeInstanceOf(Event::class)
            ->and($dependent?->getRound()?->getNumber())->toBe(7);

        [$one, $other] = $deciding->getParticipants();
        $dependentIds = array_map(static fn(Participant $participant): string => $participant->getId(), $dependent?->getParticipants() ?? []);
        sort($dependentIds);

        $constraints = ConstraintSet::create()->custom(
            static function (Event $event, SchedulingContext $context) use ($one, $other, $dependentIds): bool {
                $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
                sort($ids);
                if ($ids !== $dependentIds || $event->getRound()?->getNumber() !== 7) {
                    return true;
                }

                $meetings = array_values($context->getEventsBetween($one, $other));

                return count($meetings) === 2
                    && $meetings[0]->getParticipants()[0]->getId() !== $meetings[1]->getParticipants()[0]->getId();
            },
            'Depends on another pairing'
        )->build();

        $schedule = (new RoundRobinScheduler($constraints))->schedule($field, $options())->getEvents();

        // One event changed, and it is not the one the constraint rejected
        $changed = [];
        foreach ($schedule as $index => $event) {
            if ($event->getParticipants() !== $kept[$index]->getParticipants()) {
                $changed[] = $index;
            }
        }

        expect($changed)->toBe([17])
            ->and($schedule[17]->getParticipants())->toBe([$other, $one]);
    });
});

describe('where no constraint rejects a role', function (): void {
    // The draws are the schedule: a constraint set that accepts everything
    // must give, event for event, what no constraint set gives. This is
    // what holds the output of every configuration that succeeded before
    // the scheduler searched roles.
    it('gives the shuffled legs their draws, with any constraint set and from a seed or a randomizer', function (): void {
        $accepting = [
            'a closure' => ConstraintSet::create()->custom(static fn(): bool => true, 'Accepts everything')->build(),
            'a streak nobody reaches' => ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway(100))->build(),
        ];

        foreach ([3, 4, 5, 8, 9] as $size) {
            $field = roleSearchSchedulerField($size);
            foreach ([2, 3, 4] as $legs) {
                foreach ([1, 2, 3] as $seed) {
                    $sources = [
                        'seed' => static fn(): ShuffledLegStrategy => new ShuffledLegStrategy(seed: $seed),
                        'randomizer' => static fn(): ShuffledLegStrategy => new ShuffledLegStrategy(new Randomizer(new Mt19937($seed))),
                    ];
                    foreach ($sources as $source => $strategy) {
                        $plain = (new RoundRobinScheduler())->schedule($field, new RoundRobinOptions($legs, $strategy()))->toJson();

                        foreach ($accepting as $name => $constraints) {
                            foreach ([false, true] as $backtracking) {
                                $schedule = (new RoundRobinScheduler($constraints))
                                    ->schedule($field, new RoundRobinOptions($legs, $strategy(), $backtracking));

                                expect($schedule->toJson())->toBe($plain, "{$size} participants, {$legs} legs, {$source} {$seed}, {$name}");
                            }
                        }
                    }
                }
            }
        }
    });
});

describe('what the scheduler says when it finds no roles', function (): void {
    // Two participants meet once a leg, so the schedule is one event after
    // another and the roles of the later legs are the whole search. The
    // last round is closed to them in either role, and a closure tells the
    // search nothing about which earlier event could matter, so it goes
    // back one event at a time: 2^(legs - 2) ways to reach the last event.
    $lastRoundClosed = static fn(int $legs): ConstraintSet => ConstraintSet::create()
        ->custom(static fn(Event $event): bool => $event->getRound()?->getNumber() !== $legs, 'Last round closed')
        ->build();

    it('says that no roles exist when it tried them all', function () use ($lastRoundClosed): void {
        // 2^13 ways, two rejections each: 16,384, within the bound
        $failure = roleSearchFailure(
            new RoundRobinScheduler($lastRoundClosed(15)),
            roleSearchSchedulerField(2),
            new RoundRobinOptions(15, new ShuffledLegStrategy(seed: 1))
        );

        expect(2 ** 14)->toBeLessThan(LegRoleSearch::REJECTION_LIMIT)
            ->and($failure?->getMessage())->toBe(
                'Failed to generate complete schedule for legs 2 to 15: the constraints reject every choice of roles for the events of legs 2 to 15 in this layout. The pairings, their rounds and the roles of leg 1 were not searched: RoundRobinOptions(backtracking: true) searches them.'
            )
            ->and($failure?->getActualEventCount())->toBe(14)
            ->and($failure?->getViolationCollector()->getViolationCount())->toBe(2);
    });

    it('says that a bound stopped it when one did, and does not say that no roles exist', function () use ($lastRoundClosed): void {
        // 2^14 ways, two rejections each: 32,768, beyond the bound
        $failure = roleSearchFailure(
            new RoundRobinScheduler($lastRoundClosed(16)),
            roleSearchSchedulerField(2),
            new RoundRobinOptions(16, new ShuffledLegStrategy(seed: 1))
        );

        expect(2 ** 15)->toBeGreaterThan(LegRoleSearch::REJECTION_LIMIT)
            ->and($failure?->getMessage())->toBe(
                'Failed to generate complete schedule for legs 2 to 16: the search for roles the constraints accept stopped at its bound of 20000 rejected roles. Roles that satisfy the constraints may exist beyond it; the pairings, their rounds and the roles of leg 1 were not searched, and RoundRobinOptions(backtracking: true) searches them.'
            )
            ->and($failure?->getActualEventCount())->toBe(15);
    });

    it('says the same of a backtracking search that a bound stopped', function () use ($lastRoundClosed): void {
        $failure = roleSearchFailure(
            new RoundRobinScheduler($lastRoundClosed(17)),
            roleSearchSchedulerField(2),
            new RoundRobinOptions(17, new ShuffledLegStrategy(seed: 1), backtracking: true)
        );

        expect($failure?->getMessage())->toBe(
            'Backtracking search tried every round decomposition of the first leg without finding a complete schedule, but for 1 of the 1 it tried the search for roles stopped at its bound of 20000 rejected roles. Roles that satisfy the constraints may exist beyond that bound.'
        )
            ->and($failure?->getPrevious())->toBeInstanceOf(IncompleteScheduleException::class);
    });

    // Three repeated legs of twelve with role counts at most 3 apart. The
    // roles exist in the layout of the circle method: the balanced role
    // assignment gives them, without a search. The backtracking search
    // starts from the round-parity roles, runs into its bounds in both
    // first legs it tries (the one the generator found and the circle
    // layout) and spends its budget. What it then says must not be more
    // than it showed: of neither first leg did it show that the constraints
    // reject the later legs whatever the roles, and for one of them that is
    // false.
    it('does not say the constraints reject every role of a first leg whose role search a bound stopped', function (): void {
        $field = roleSearchSchedulerField(12);
        $constraints = ConstraintSet::create()->add(RoleBalanceConstraint::homeAway(3))->build();

        $circle = (new RoundRobinScheduler())->schedule($field, new RoundRobinOptions(3, new RepeatedLegStrategy()));
        $balanced = (new RoundRobinScheduler($constraints))
            ->schedule($field, new RoundRobinOptions(3, new RepeatedLegStrategy(), false, new BalancedRoleAssignment()));

        expect(RoleCounts::structure($balanced))->toBe(RoleCounts::structure($circle))
            ->and(RoleCounts::worstRunningImbalance($balanced))->toBeLessThanOrEqual(3);

        $failure = roleSearchFailure(
            new RoundRobinScheduler($constraints),
            $field,
            new RoundRobinOptions(3, new RepeatedLegStrategy(), backtracking: true)
        );

        expect($failure?->getMessage())->toBe(
            'Backtracking search exhausted its step budget (200000 pairing attempts) without finding a complete schedule. It tried 2 round decompositions of the first leg, and for none of them did it get through every choice of roles: for 2 the search for roles stopped at a bound (20000 rejected roles, or the step budget). The configuration may be unsatisfiable, or roles beyond a bound or a first leg beyond the budget may have later legs the constraints accept.'
        );
    });

    it('names the pairing a strategy of preferences returns no event for', function (bool $backtracking, string $message): void {
        // A constraint, so that the later legs go through the role search;
        // round 5 is the second round of the second leg, after two events
        $constraints = ConstraintSet::create()->custom(static fn(): bool => true, 'Accepts everything')->build();
        $failure = roleSearchFailure(
            new RoundRobinScheduler($constraints),
            roleSearchSchedulerField(4),
            new RoundRobinOptions(2, new KeepingLegStrategy(true, [5]), $backtracking)
        );

        expect($failure?->getMessage())->toMatch($message)
            ->and($failure?->getActualEventCount())->toBe(8);
    })->with([
        'without backtracking' => [false, '/^Failed to generate complete schedule for leg 2: KeepingLegStrategy returned no event for p\d vs p\d in round 5\.$/'],
        'with backtracking' => [true, '/^Backtracking found a first leg, but KeepingLegStrategy returned no event for p\d vs p\d in round 5 of leg 2\.$/'],
    ]);
});

describe('a first leg whose roles the backtracking search changes', function (): void {
    // The role assignment names p1 first against p2. Two repeated legs and
    // a rule that wants p2 first against p1 in the second: the only roles
    // that satisfy the rule have p2 first in the first leg too, which the
    // role assignment would change back.
    it('is not returned when the role assignment would change it again', function (): void {
        $field = roleSearchSchedulerField(4);
        $failure = roleSearchFailure(
            new RoundRobinScheduler(roleSearchPinned('p2', 'p1', 4)),
            $field,
            new RoundRobinOptions(2, new RepeatedLegStrategy(), true, new PinningRoleAssignment('p1', 'p2'))
        );

        expect($failure)->toBeInstanceOf(IncompleteScheduleException::class)
            ->and($failure?->getMessage())->toContain('without finding a complete schedule that ' . PinningRoleAssignment::class . ' keeps')
            ->toContain('for 6 of the 6 it tried, roles exist that satisfy the constraints in every leg')
            ->and(str_contains($failure?->getMessage() ?? '', 'unsatisfiable'))->toBeFalse();
    });

    it('is returned when the role assignment keeps it', function (): void {
        $field = roleSearchSchedulerField(4);
        // The same rule with a role assignment that agrees with it
        $schedule = (new RoundRobinScheduler(roleSearchPinned('p2', 'p1', 4)))->schedule(
            $field,
            new RoundRobinOptions(2, new RepeatedLegStrategy(), true, new PinningRoleAssignment('p2', 'p1'))
        );

        $meetings = array_values(array_filter(
            $schedule->getEvents(),
            static fn(Event $event): bool => $event->hasParticipant($field[0]) && $event->hasParticipant($field[1])
        ));

        expect($schedule)->toHaveCount(12)
            ->and($meetings)->toHaveCount(2)
            ->and($meetings[0]->getParticipants()[0]->getId())->toBe('p2')
            ->and($meetings[1]->getParticipants()[0]->getId())->toBe('p2');
    });
});
