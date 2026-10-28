<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\CallableConstraint;
use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\NoRepeatPairings;
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
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentContext;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentInterface;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use MissionGaming\Tactician\Scheduling\LegRoleSearch;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Tests\Support\RoleCounts;
use MissionGaming\Tactician\Tests\Support\RoleScheduleOracle;
use Random\Engine\Mt19937;
use Random\Randomizer;

// What the round-robin scheduler does with the roles of the legs after the
// first when a constraint rejects them: which roles it may change, what it
// says when it finds none, and that nothing changes where nothing is
// rejected. The sweep against an exhaustive oracle is in
// tests/Feature/RoleConstraintsAcrossLegsTest.php.
//
// Where a test is about a first leg whose roles are not searched, it names
// the round-parity role assignment, which states no rule: under the
// balanced role assignment of the default the scheduler searches the roles
// of the first leg as well, and says other things when it finds none (the
// last group of this file).

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
    public function assignRoles(array $rounds, RoleAssignmentContext $context): array
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

    #[Override]
    public function rule(): ?ConstraintInterface
    {
        return null;
    }
}

/**
 * A role assignment of the caller's with a rule of its own: one participant
 * hosts, which is to say it is first-named in every event it plays. Every
 * other seating is left as it is, so any roles of the other pairings are
 * roles it keeps. It reads nothing of its context.
 *
 * The rule can be made to say less than the assignment keeps (`honest:
 * false`): it then accepts every event, and only asking the assignment
 * about a whole leg shows which legs it would change. Or to say nothing
 * (`statesARule: false`), which is the twin a test holds its premise with:
 * the same roles, and no search of them.
 */
final readonly class HostingRoleAssignment implements RoleAssignmentInterface
{
    public function __construct(private string $host, private bool $honest = true, private bool $statesARule = true) {}

    #[Override]
    public function assignRoles(array $rounds, RoleAssignmentContext $context): array
    {
        foreach ($rounds as $round => $seatings) {
            foreach ($seatings as $position => [$one, $other]) {
                if ($one !== null && $other?->getId() === $this->host) {
                    $rounds[$round][$position] = [$other, $one];
                }
            }
        }

        return $rounds;
    }

    #[Override]
    public function rule(): ?ConstraintInterface
    {
        if (!$this->statesARule) {
            return null;
        }
        $host = $this->host;
        $honest = $this->honest;

        return new CallableConstraint(
            static fn(Event $event): bool => !$honest || $event->getParticipants()[1]->getId() !== $host,
            'The host is first-named'
        );
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

        expect(new LegPlanContribution(false, false)->rolesArePreferences)->toBeFalse()
            ->and(new MirroredLegStrategy()->planLegs([], 2, $none)->rolesArePreferences)->toBeFalse()
            ->and(new RepeatedLegStrategy()->planLegs([], 2, $none)->rolesArePreferences)->toBeFalse()
            ->and(new ShuffledLegStrategy(seed: 1)->planLegs([], 2, $none)->rolesArePreferences)->toBeTrue()
            ->and(new ShuffledLegStrategy(new Randomizer(new Mt19937(1)))->planLegs([], 2, $none)->rolesArePreferences)->toBeTrue();
    });

    // Two legs of a strategy that keeps the roles of the first leg, and a
    // rule that wants every pair to meet the other way round the second
    // time. Every event of the second leg breaks it as the strategy gives
    // it, in whatever order the participants are laid out, and the reverse
    // of every one of them keeps it.
    $otherWayRound = static fn(): ConstraintSet => ConstraintSet::create()->custom(
        static function (Event $event, SchedulingContext $context): bool {
            [$first, $second] = $event->getParticipants();

            return array_all($context->getEventsBetween($first, $second), fn($earlier) => !($earlier->getParticipants()[0]->getId() === $first->getId()));
        },
        'The other way round'
    )->build();

    it('never reverses an event of a strategy whose roles are rules', function () use ($otherWayRound): void {
        $failure = roleSearchFailure(
            new RoundRobinScheduler($otherWayRound()),
            roleSearchSchedulerField(4),
            new RoundRobinOptions(2, new KeepingLegStrategy(false), false, new RoundParityRoleAssignment())
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

        $failure = roleSearchFailure($scheduler, $field, new RoundRobinOptions(2, $strategy, false, new RoundParityRoleAssignment()));

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
        $schedule = new RoundRobinScheduler($otherWayRound())->schedule($field, new RoundRobinOptions(2, new KeepingLegStrategy(true)));

        // Every event of the second leg reversed is the mirrored schedule
        $mirrored = new RoundRobinScheduler()->schedule($field, new RoundRobinOptions(2, new MirroredLegStrategy()));

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
        $kept = new RoundRobinScheduler()->schedule($field, $options())->getEvents();

        // Rounds 6 and 7 open the second leg, three events each
        $deciding = $kept[17];
        $dependent = array_find([$kept[18], $kept[19], $kept[20]], fn($candidate) => array_intersect(
            array_map(static fn(Participant $participant): string => $participant->getId(), $candidate->getParticipants()),
            array_map(static fn(Participant $participant): string => $participant->getId(), $deciding->getParticipants())
        ) === []);

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

        $schedule = new RoundRobinScheduler($constraints)->schedule($field, $options())->getEvents();

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
                        $plain = new RoundRobinScheduler()->schedule($field, new RoundRobinOptions($legs, $strategy()))->toJson();

                        foreach ($accepting as $name => $constraints) {
                            foreach ([false, true] as $backtracking) {
                                $schedule = new RoundRobinScheduler($constraints)
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
            new RoundRobinOptions(15, new ShuffledLegStrategy(seed: 1), false, new RoundParityRoleAssignment())
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
            new RoundRobinOptions(16, new ShuffledLegStrategy(seed: 1), false, new RoundParityRoleAssignment())
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
            new RoundRobinOptions(17, new ShuffledLegStrategy(seed: 1), true, new RoundParityRoleAssignment())
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

        $circle = new RoundRobinScheduler()->schedule($field, new RoundRobinOptions(3, new RepeatedLegStrategy()));
        $balanced = new RoundRobinScheduler($constraints)
            ->schedule($field, new RoundRobinOptions(3, new RepeatedLegStrategy(), false, new BalancedRoleAssignment()));

        expect(RoleCounts::structure($balanced))->toBe(RoleCounts::structure($circle))
            ->and(RoleCounts::worstRunningImbalance($balanced))->toBeLessThanOrEqual(3);

        $failure = roleSearchFailure(
            new RoundRobinScheduler($constraints),
            $field,
            new RoundRobinOptions(3, new RepeatedLegStrategy(), true, new RoundParityRoleAssignment())
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
        $schedule = new RoundRobinScheduler(roleSearchPinned('p2', 'p1', 4))->schedule(
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

// A role assignment that states a rule has the roles of its first leg
// searched within that rule when the constraints reject the ones it gave,
// without backtracking (issue #51). The balanced role assignment of the
// default is the one the library ships; these hold the mechanism to any.
describe('a first leg whose roles the scheduler searches within the rule of the role assignment', function (): void {
    /**
     * "$first is first-named whenever it meets $second", for each pair given.
     *
     * @param list<array{string, string}> $pinned
     */
    $pins = static fn(array $pinned): ConstraintSet => ConstraintSet::create()->custom(
        static function (Event $event) use ($pinned): bool {
            $pair = $event->getParticipants();

            return !in_array([$pair[1]->getId(), $pair[0]->getId()], $pinned, true);
        },
        'Pinned roles'
    )->build();

    /**
     * @return list<string> Each event as "first > second"
     */
    $roles = static fn(iterable $events): array => array_map(
        static fn(array $event): string => "{$event[1]} > {$event[2]}",
        RoleScheduleOracle::read($events)
    );

    // Three pairings of the other participants are pinned in a cycle that
    // the roles the generator proposes do not have in any rotated order
    // (the premise, held by the twin that states no rule: it fails). With
    // the rule, the scheduler fixes the seatings the pins reject and asks
    // again; the assignment does not read what is fixed, and need not, since
    // the pins leave its host alone.
    it('returns roles a custom role assignment keeps and the constraints accept', function () use ($pins, $roles): void {
        $field = roleSearchSchedulerField(6);
        $pinned = [['p3', 'p2'], ['p4', 'p3'], ['p2', 'p4'], ['p6', 'p5'], ['p5', 'p3'], ['p4', 'p6']];
        $hosting = new HostingRoleAssignment('p1');
        $withoutARule = new HostingRoleAssignment('p1', statesARule: false);

        expect(roleSearchFailure(new RoundRobinScheduler($pins($pinned)), $field, new RoundRobinOptions(roleAssignment: $withoutARule)))
            ->toBeInstanceOf(IncompleteScheduleException::class);

        $schedule = new RoundRobinScheduler($pins($pinned))->schedule($field, new RoundRobinOptions(roleAssignment: $hosting));
        $unconstrained = new RoundRobinScheduler()->schedule($field, new RoundRobinOptions(roleAssignment: $hosting));

        expect(RoleCounts::structure($schedule))->toBe(RoleCounts::structure($unconstrained))
            ->and($schedule->getMetadata())->toBe($unconstrained->getMetadata())
            // The host hosts: first-named in all five of its events
            ->and(RoleCounts::differences($schedule)['p1'])->toBe(5);
        foreach ($pinned as [$first, $second]) {
            expect($roles($schedule))->toContain("{$first} > {$second}");
        }
    });

    // The same, with rules that turn on the roles before an event. No
    // seating is fixed by such a rule, so the roles are searched.
    it('searches the roles within the rule where a limit on the roles before an event rejects them', function (): void {
        $field = roleSearchSchedulerField(6);
        $limit = ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway(2))->build();
        $hosting = new HostingRoleAssignment('p2');

        // Nobody plays three events in a row in one role: the host is
        // first-named five times in a row whatever the roles of the others,
        // so no roles the assignment keeps have the limit
        $failure = roleSearchFailure(new RoundRobinScheduler($limit), $field, new RoundRobinOptions(roleAssignment: $hosting));
        expect($failure?->getMessage())->toEndWith(
            'The roles of the first leg were then searched within what HostingRoleAssignment keeps, in each of the 6 layouts tried, and no choice of them has a schedule the constraints accept.'
            . ' The pairings and their rounds were not searched: RoundRobinOptions(backtracking: true) searches them.'
            . ' Roles that HostingRoleAssignment does not keep were not searched either:'
            . ' RoundRobinOptions(backtracking: true, roleAssignment: new RoundParityRoleAssignment()) searches every choice of roles.'
        );

        // A limit the host is left out of: the role counts of everybody else
        // are never more than 2 apart
        $looser = ConstraintSet::create()->custom(
            static function (Event $event, SchedulingContext $context): bool {
                foreach ($event->getParticipants() as $role => $participant) {
                    if ($participant->getId() === 'p2') {
                        continue;
                    }
                    $difference = $role === 0 ? 1 : -1;
                    foreach ($context->getEventsForParticipant($participant) as $earlier) {
                        $difference += $earlier->getParticipants()[0]->getId() === $participant->getId() ? 1 : -1;
                    }
                    if (abs($difference) > 2) {
                        return false;
                    }
                }

                return true;
            },
            'Never more than 2 apart, but for the host'
        )->build();

        // The premise: with the roles as given, no rotated order has it
        expect(roleSearchFailure(
            new RoundRobinScheduler($looser),
            $field,
            new RoundRobinOptions(roleAssignment: new HostingRoleAssignment('p2', statesARule: false))
        ))->toBeInstanceOf(IncompleteScheduleException::class);

        $schedule = new RoundRobinScheduler($looser)->schedule($field, new RoundRobinOptions(roleAssignment: $hosting));

        // Every pair once, three events in each of five rounds, the host hosting
        $pairs = [];
        $perRound = [];
        foreach (RoleCounts::structure($schedule) as $entry) {
            [$round, $pair] = explode(': ', $entry);
            $pairs[] = $pair;
            $perRound[$round] = ($perRound[$round] ?? 0) + 1;
        }
        expect(array_unique($pairs))->toHaveCount(15)
            ->and($perRound)->toBe([1 => 3, 2 => 3, 3 => 3, 4 => 3, 5 => 3])
            ->and(RoleCounts::differences($schedule)['p2'])->toBe(5);

        // The running totals of everybody but the host
        $difference = [];
        foreach ($schedule as $event) {
            foreach ($event->getParticipants() as $role => $participant) {
                $id = $participant->getId();
                $difference[$id] = ($difference[$id] ?? 0) + ($role === 0 ? 1 : -1);
                if ($id !== 'p2') {
                    expect(abs($difference[$id]))->toBeLessThanOrEqual(2);
                }
            }
        }
    });

    // A rule may say less than the assignment keeps. The scheduler does not
    // take its word for a whole leg: the leg a search arrives at is put to
    // the assignment, and one it would change is not returned. Here the
    // rule accepts everything, and the one pinned role is the host's.
    it('never returns a first leg the role assignment would change, whatever its rule says', function () use ($pins): void {
        $field = roleSearchSchedulerField(4);
        $careless = new HostingRoleAssignment('p1', honest: false);

        $failure = roleSearchFailure(
            new RoundRobinScheduler($pins([['p3', 'p1']])),
            $field,
            new RoundRobinOptions(roleAssignment: $careless)
        );

        expect($failure)->toBeInstanceOf(IncompleteScheduleException::class)
            ->and($failure?->getMessage())->toContain('searched within what HostingRoleAssignment keeps, in each of the 4 layouts tried, and no choice of them has a schedule the constraints accept')
            ->and($failure?->getPrevious())->toBeInstanceOf(IncompleteScheduleException::class);

        // The same with backtracking: every way to play the leg, and none kept
        $failure = roleSearchFailure(
            new RoundRobinScheduler($pins([['p3', 'p1']])),
            $field,
            new RoundRobinOptions(backtracking: true, roleAssignment: $careless)
        );
        expect($failure?->getMessage())->toStartWith('Backtracking search exhausted the search space: ')
            ->toContain('whatever roles HostingRoleAssignment keeps for the first leg');
    });
});

describe('what the scheduler says when it finds no roles for the first leg within a rule', function (): void {
    // BalancedRoleAssignment is named throughout: it is balanced or refused,
    // and these are its refusals. The default of the options is balanced
    // where possible and does not fail here unless round parity fails too,
    // with the message of round parity
    // (tests/Feature/BalancedWherePossibleTest.php).
    // Four participants and a rule that names p1 first against all three
    // others: no balanced leg has that, and the search within the balance
    // goes through every choice in each of the four layouts.
    it('says that no choice of roles has a schedule, after the message of the last layout, and names the constraint', function (): void {
        $pinned = ConstraintSet::create()->custom(
            static fn(Event $event): bool => $event->getParticipants()[1]->getId() !== 'p1',
            'Participant 1 is first-named'
        )->build();

        $failure = roleSearchFailure(new RoundRobinScheduler($pinned), roleSearchSchedulerField(4), new RoundRobinOptions(roleAssignment: new BalancedRoleAssignment()));

        expect($failure?->getMessage())->toMatch(
            '/^Failed to generate complete schedule for leg 1\. Generated \d events, expected 6\. Constraints may be preventing complete schedule generation\.'
            . ' The roles of the first leg were then searched within what MissionGaming\\\\Tactician\\\\RoleAssignment\\\\BalancedRoleAssignment keeps,'
            . ' in each of the 4 layouts tried, and no choice of them has a schedule the constraints accept\.'
            . ' The pairings and their rounds were not searched: RoundRobinOptions\(backtracking: true\) searches them\.'
            . ' Roles that MissionGaming\\\\Tactician\\\\RoleAssignment\\\\BalancedRoleAssignment does not keep were not searched either:'
            . ' RoundRobinOptions\(backtracking: true, roleAssignment: new RoundParityRoleAssignment\(\)\) searches every choice of roles\.$/'
        )
            ->and(array_keys($failure?->getViolationCollector()->getViolationCountsByConstraint() ?? []))->toBe(['Participant 1 is first-named'])
            ->and($failure?->getAnalysis())->not->toBeNull()
            ->and($failure?->getExpectedEventCount())->toBe(6)
            // The failure of the last layout with the roles as given
            ->and($failure?->getPrevious()?->getMessage())->toStartWith('Failed to generate complete schedule for leg 1. Generated ')
            ->toEndWith('Constraints may be preventing complete schedule generation.');
    });

    // Two mirrored legs of eight and a role pinned in every leg: the second
    // leg has the meeting the other way round, so no schedule exists, and a
    // closure tells the search nothing about what it reads. The search goes
    // back one event at a time, stops at its bound, and says so; it does not
    // say that no roles exist.
    it('says that a bound stopped the search when one did', function (): void {
        $pinned = ConstraintSet::create()->custom(
            static function (Event $event): bool {
                $pair = $event->getParticipants();

                return !($pair[0]->getId() === 'p1' && $pair[1]->getId() === 'p2');
            },
            'Pinned role'
        )->build();

        $failure = roleSearchFailure(new RoundRobinScheduler($pinned), roleSearchSchedulerField(8), new RoundRobinOptions(legs: 2, roleAssignment: new BalancedRoleAssignment()));

        expect($failure?->getMessage())->toEndWith(
            ' The roles of the first leg were then searched within what ' . BalancedRoleAssignment::class
            . ' keeps, and the search stopped at a bound (20000 rejected roles, or 200000 events put to the constraints, over all the layouts).'
            . ' Roles that satisfy the constraints may exist beyond it; the pairings and their rounds were not searched, and RoundRobinOptions(backtracking: true) searches them.'
            . ' Roles that ' . BalancedRoleAssignment::class . ' does not keep were not searched either:'
            . ' RoundRobinOptions(backtracking: true, roleAssignment: new RoundParityRoleAssignment()) searches every choice of roles.'
        )
            ->and(str_contains($failure?->getMessage() ?? '', 'no choice of them'))->toBeFalse()
            ->and(array_keys($failure?->getViolationCollector()->getViolationCountsByConstraint() ?? []))->toBe(['Pinned role']);
    });

    // The pass that fixes the seatings a constraint rejects puts the leg to
    // the constraints again for every seating it fixes, and those events
    // count against the allowance of the call like the events of the two
    // searches. Twenty-six participants and a closure that wants the lower
    // number first-named in every pairing: no balanced leg has that, since
    // participant 1 would be first-named in all 25 of its events, and each
    // of the 25 layouts fixes seatings by the hundred before it shows. With
    // the pass left out of the count the closure was asked 330,000 times
    // more than under round parity, which makes no search, and the failure
    // claimed that every choice had been tried.
    it('counts the events of the pass that fixes seatings against the allowance of the call', function (): void {
        $field = roleSearchSchedulerField(26);
        $asked = 0;
        $lowerNumberFirst = static function () use (&$asked): ConstraintSet {
            return ConstraintSet::create()->custom(
                static function (Event $event) use (&$asked): bool {
                    ++$asked;
                    $pair = $event->getParticipants();

                    return (int) substr($pair[0]->getId(), 1) < (int) substr($pair[1]->getId(), 1);
                },
                'Lower number first-named'
            )->build();
        };

        $parity = roleSearchFailure(new RoundRobinScheduler($lowerNumberFirst()), $field, new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment()));
        $withoutASearch = $asked;

        $asked = 0;
        $failure = roleSearchFailure(new RoundRobinScheduler($lowerNumberFirst()), $field, new RoundRobinOptions(roleAssignment: new BalancedRoleAssignment()));

        expect($parity)->toBeInstanceOf(IncompleteScheduleException::class)
            // The allowance of 200,000 events, and a margin for what the
            // rotations ask differently under the two role assignments
            ->and($asked - $withoutASearch)->toBeLessThanOrEqual(220_000)
            ->and($failure?->getMessage())->toContain('the search stopped at a bound (20000 rejected roles, or 200000 events put to the constraints, over all the layouts)')
            ->and(str_contains($failure?->getMessage() ?? '', 'no choice of them'))->toBeFalse()
            ->and(array_keys($failure?->getViolationCollector()->getViolationCountsByConstraint() ?? []))->toBe(['Lower number first-named']);
    });

    // Two legs of eight in which no pair may meet twice: every second leg
    // breaks it, whatever the roles, because the constraint reads who meets
    // whom and no position. The scheduler knows that from the rotation that
    // failed, searches nothing, and says so. The closure beside the
    // constraint accepts everything and counts what it is asked: exactly
    // what it is asked under round parity, which has no search of roles to
    // make.
    //
    // A scheduler with a randomizer lays every layout out from a new
    // shuffle, so it has to look at each: one pass that puts the roles as
    // given to the constraints, which asks the closure once for each of the
    // 28 events of the first leg in each of the eight layouts and for
    // nothing after the pairing that is rejected.
    it('does not search the roles where a constraint that reads no position rejects', function (?int $seed, int $askedForTheSearch): void {
        $field = roleSearchSchedulerField(8);
        $asked = 0;
        $scheduler = static function () use (&$asked, $seed): RoundRobinScheduler {
            return new RoundRobinScheduler(
                ConstraintSet::create()
                    ->add(new NoRepeatPairings(acrossLegs: true))
                    ->custom(static function () use (&$asked): bool {
                        ++$asked;

                        return true;
                    }, 'Accepts everything')
                    ->build(),
                $seed === null ? null : new Randomizer(new Mt19937($seed))
            );
        };

        $parity = roleSearchFailure($scheduler(), $field, new RoundRobinOptions(legs: 2, roleAssignment: new RoundParityRoleAssignment()));
        $withoutASearch = $asked;

        $asked = 0;
        $failure = roleSearchFailure($scheduler(), $field, new RoundRobinOptions(legs: 2, roleAssignment: new BalancedRoleAssignment()));

        expect($parity)->toBeInstanceOf(IncompleteScheduleException::class)
            ->and($asked - $withoutASearch)->toBe($askedForTheSearch)
            ->and($failure?->getMessage())->toBe(
                $parity?->getMessage()
                . ' No choice of roles for the first leg changes that: in each of the 8 layouts tried the constraints reject an event'
                . ' for who meets whom and when, whichever participant is first-named.'
                . ' The pairings and their rounds were not searched: RoundRobinOptions(backtracking: true) searches them.'
            )
            ->and(array_keys($failure?->getViolationCollector()->getViolationCountsByConstraint() ?? []))->toBe(['No Repeat Pairings'])
            ->and($failure?->getAnalysis())->not->toBeNull();
    })->with([
        'without a randomizer' => [null, 0],
        'with a randomizer' => [7, 8 * 28],
    ]);

    // The rotations that fail before a search of roles are not analysed
    // one by one where the constraints are the library's role limits: the
    // analysis is built once, when the search has failed too, and not at all
    // when it finds the roles. Counted in the role extractions of the limit,
    // which an analysis makes by the hundred thousand.
    it('analyses a failure once, after the search, and not at all when the search finds the roles', function (): void {
        $field = roleSearchSchedulerField(12);
        $extractions = 0;
        $limit = static function (int $most) use (&$extractions): ConstraintSet {
            return ConstraintSet::create()->add(new ConsecutiveRoleConstraint(
                $most,
                static function (Event $event, Participant $participant) use (&$extractions): string {
                    ++$extractions;

                    return $event->getParticipants()[0]->getId() === $participant->getId() ? 'first' : 'second';
                },
                'Streak'
            ))->build();
        };

        // Two in a row over two mirrored legs: the roles as given break it in
        // every one of the twelve rotated orders, and the search finds others
        $schedule = new RoundRobinScheduler($limit(2))->schedule($field, new RoundRobinOptions(legs: 2, roleAssignment: new BalancedRoleAssignment()));
        $found = $extractions;

        expect(RoleCounts::worstStreak($schedule))->toBe(2);

        // The same field under round parity, which fails in every rotated
        // order and has no search to follow: an analysis for each
        $extractions = 0;
        $failure = roleSearchFailure(
            new RoundRobinScheduler($limit(1)),
            $field,
            new RoundRobinOptions(legs: 2, roleAssignment: new RoundParityRoleAssignment())
        );
        $analysedEveryOrder = $extractions;
        $underRoundParity = $failure;

        // One in a row under the balanced role assignment: no roles have it, the search says
        // so, and the failure carries the one analysis
        $extractions = 0;
        $failure = roleSearchFailure(new RoundRobinScheduler($limit(1)), $field, new RoundRobinOptions(legs: 2, roleAssignment: new BalancedRoleAssignment()));
        $analysedOnce = $extractions;

        expect($failure?->getAnalysis())->not->toBeNull()
            ->and($failure?->getPrevious())->toBeInstanceOf(IncompleteScheduleException::class)
            // Left to the failure of the search, which is the one a caller sees
            ->and($failure?->getPrevious() instanceof IncompleteScheduleException ? $failure->getPrevious()->getAnalysis() : false)->toBeNull()
            ->and($analysedOnce)->toBeLessThan(intdiv($analysedEveryOrder, 4))
            ->and($found)->toBeLessThan(intdiv($analysedEveryOrder, 4));

        // The default makes that search and then generates with the roles of
        // round parity, whose failure it reports: the same message and an
        // analysis, built once for the last rotated order and not for each,
        // since the limit is one the library knows to read a participant's
        // own events only
        $extractions = 0;
        $failure = roleSearchFailure(new RoundRobinScheduler($limit(1)), $field, new RoundRobinOptions(legs: 2));

        expect($failure?->getAnalysis())->not->toBeNull()
            ->and($failure?->getPrevious())->toBeNull()
            ->and($failure?->getMessage())->toBe($underRoundParity?->getMessage())
            ->and($failure?->getAnalysis()?->toString())->toBe($underRoundParity?->getAnalysis()?->toString())
            ->and($extractions)->toBeLessThan(intdiv($analysedEveryOrder, 2));
    });
});
