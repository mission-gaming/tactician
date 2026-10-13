<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MetadataConstraint;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentInterface;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Stage\StagePlan;
use MissionGaming\Tactician\Tests\Support\RoleCounts;
use MissionGaming\Tactician\Tests\Support\RoundRobinAudit;
use Random\Engine\Mt19937;
use Random\Randomizer;

// Constraint sets that no schedule satisfies, and what the scheduler does
// with them.
//
// "The scheduler threw IncompleteScheduleException" proves nothing by
// itself: the generator is greedy, and it also throws for constraint sets
// that a schedule does satisfy. So every case here that expects the
// exception first proves, by counting, that no complete round robin can
// satisfy the constraints, and checks each step of that count against the
// constraints themselves. A leg is one complete cycle of pairings, so every
// pairing has an event in every leg, in one of that leg's rounds; a pairing
// with no round open to it in some leg has no schedule.
//
// One case turned out not to be infeasible: a role limit over two shuffled
// legs. It is kept after these with the schedule that shows it, and the
// scheduler now finds one (issue #117); the same limit over two repeated
// legs, and a set that really has no schedule, follow it.

/**
 * @param list<array{string, int|null, array<string, mixed>}> $rows ID suffix, seed and metadata of each participant
 * @return array<string, Participant> Keyed by ID
 */
function complexConstraintField(array $rows): array
{
    $field = [];
    foreach ($rows as [$suffix, $seed, $metadata]) {
        $field["team-{$suffix}"] = new Participant("team-{$suffix}", 'Team ' . strtoupper($suffix), $seed, $metadata);
    }

    return $field;
}

/**
 * The rounds, of those given, in which the constraint accepts an event
 * between the two participants in either order, when nothing has been
 * played yet.
 *
 * Only for constraints that do not read earlier events (seed protection,
 * metadata rules): for those, a round closed here is closed whatever else
 * the schedule holds.
 *
 * @param array<Participant> $field
 * @param list<int> $rounds
 * @return list<int>
 */
function complexConstraintOpenRounds(
    ConstraintInterface $constraint,
    Participant $one,
    Participant $other,
    array $field,
    StagePlan $plan,
    array $rounds
): array {
    $context = new SchedulingContext(array_values($field), $plan, [], 1);

    return array_values(array_filter(
        $rounds,
        static fn(int $round): bool => $constraint->isSatisfied(new Event([$one, $other], new Round($round)), $context)
            || $constraint->isSatisfied(new Event([$other, $one], new Round($round)), $context)
    ));
}

describe('constraint sets that no schedule satisfies', function (): void {
    // Eight participants, three mirrored legs: 21 rounds, leg 1 is rounds 1
    // to 7. Seed protection keeps the top two seeds apart for the first 40%
    // of the rounds, which is rounds 1 to 8: all of leg 1. The two must
    // meet once in every leg, so leg 1 has no round for them. The other
    // three constraints do not matter to that.
    it('cannot hold two seeds apart for longer than a leg lasts: eight participants, three legs, 40%', function (): void {
        $field = complexConstraintField([
            ['a', 1, ['region' => 'North']], ['b', 2, ['region' => 'North']],
            ['c', 3, ['region' => 'South']], ['d', 4, ['region' => 'South']],
            ['e', 5, ['region' => 'East']], ['f', 6, ['region' => 'East']],
            ['g', 7, ['region' => 'West']], ['h', 8, ['region' => 'West']],
        ]);
        $seedProtection = new SeedProtectionConstraint(2, 0.4);
        $constraints = ConstraintSet::create()
            ->add(new MinimumRestPeriodsConstraint(2))
            ->add($seedProtection)
            ->add(MetadataConstraint::maxUniqueValues('region', 2))
            ->add(ConsecutiveRoleConstraint::homeAway(2))
            ->build();
        $options = new RoundRobinOptions(legs: 3, strategy: new MirroredLegStrategy());
        $scheduler = new RoundRobinScheduler($constraints);
        $plan = $scheduler->getPlan(array_values($field), $options);

        // The count: leg 1 is rounds 1 to 7, and none of them is open to
        // the two seeds; the first open round is the ninth
        expect($plan->getRoundsPerLeg())->toBe(7)
            ->and($plan->getTotalRounds())->toBe(21)
            ->and(complexConstraintOpenRounds($seedProtection, $field['team-a'], $field['team-b'], $field, $plan, range(1, 21)))
            ->toBe(range(9, 21));

        expect(fn() => $scheduler->schedule(array_values($field), $options))
            ->toThrow(IncompleteScheduleException::class);
    });

    // Six participants, two mirrored legs: 10 rounds, leg 1 is rounds 1 to
    // 5. Protection for 60% of the rounds covers rounds 1 to 6.
    it('cannot hold two seeds apart for longer than a leg lasts: six participants, two legs, 60%', function (): void {
        $field = complexConstraintField([
            ['a', 1, []], ['b', 2, []], ['c', 3, []], ['d', 4, []], ['e', 5, []], ['f', 6, []],
        ]);
        $seedProtection = new SeedProtectionConstraint(2, 0.6);
        $constraints = ConstraintSet::create()
            ->add($seedProtection)
            ->add(new MinimumRestPeriodsConstraint(1))
            ->build();
        $options = new RoundRobinOptions(legs: 2, strategy: new MirroredLegStrategy());
        $scheduler = new RoundRobinScheduler($constraints);
        $plan = $scheduler->getPlan(array_values($field), $options);

        expect($plan->getRoundsPerLeg())->toBe(5)
            ->and(complexConstraintOpenRounds($seedProtection, $field['team-a'], $field['team-b'], $field, $plan, range(1, 10)))
            ->toBe([7, 8, 9, 10]);

        expect(fn() => $scheduler->schedule(array_values($field), $options))
            ->toThrow(IncompleteScheduleException::class);

        // The control: at 40% the window is rounds 1 to 4, round 5 of leg 1
        // is open to the two seeds, and the same field schedules.
        $shorter = new RoundRobinScheduler(ConstraintSet::create()->add(new SeedProtectionConstraint(2, 0.4))->build());
        $schedule = $shorter->schedule(array_values($field), $options);

        expect(RoundRobinAudit::faults($schedule, array_values($field), 2))->toBe([]);
    });

    // Three rules about a pairing's metadata, none of which reads the round
    // or the other events: adjacent skill levels, the same equipment,
    // different regions. A round robin needs all 15 pairings; the rules
    // allow 5, and Team A may meet nobody but Team B.
    it('cannot play a round robin when metadata rules forbid a pairing', function (): void {
        $field = complexConstraintField([
            ['a', 1, ['skill_level' => 3, 'equipment' => 'A', 'region' => 'North']],
            ['b', 2, ['skill_level' => 3, 'equipment' => 'A', 'region' => 'South']],
            ['c', 3, ['skill_level' => 4, 'equipment' => 'B', 'region' => 'East']],
            ['d', 4, ['skill_level' => 4, 'equipment' => 'B', 'region' => 'West']],
            ['e', 5, ['skill_level' => 2, 'equipment' => 'A', 'region' => 'North']],
            ['f', 6, ['skill_level' => 5, 'equipment' => 'B', 'region' => 'South']],
        ]);
        $rules = [
            MetadataConstraint::requireAdjacentValues('skill_level', 'Adjacent skill levels'),
            MetadataConstraint::requireSameValue('equipment', 'Same equipment'),
            MetadataConstraint::requireDifferentValues('region', 'Different regions'),
        ];
        $constraints = ConstraintSet::create()->add($rules[0])->add($rules[1])->add($rules[2])->build();
        $scheduler = new RoundRobinScheduler($constraints);
        $plan = $scheduler->getPlan(array_values($field));

        // The count: the pairings that all three rules accept in some round
        $allowed = [];
        $ids = array_keys($field);
        foreach ($ids as $index => $one) {
            foreach (array_slice($ids, $index + 1) as $other) {
                $open = range(1, 5);
                foreach ($rules as $rule) {
                    $open = complexConstraintOpenRounds($rule, $field[$one], $field[$other], $field, $plan, $open);
                }
                if ($open !== []) {
                    $allowed[] = "{$one} + {$other}";
                }
            }
        }

        expect($plan->getExpectedEventCount())->toBe(15)
            ->and($allowed)->toBe([
                'team-a + team-b',
                'team-b + team-e',
                'team-c + team-d',
                'team-c + team-f',
                'team-d + team-f',
            ]);

        expect(fn() => $scheduler->schedule(array_values($field)))
            ->toThrow(IncompleteScheduleException::class);
    });

    // Four participants play three rounds a leg, two events a round. A
    // pairing of round 3, the last of leg 1, plays again in leg 2, which is
    // rounds 4 to 6: at most 3 rounds later. A rest of 4 rounds between two
    // meetings of the same pair leaves it no round.
    it('cannot rest a pairing for longer than the next leg lasts', function (): void {
        $field = array_values(complexConstraintField([['a', null, []], ['b', null, []], ['c', null, []], ['d', null, []]]));
        $options = new RoundRobinOptions(legs: 4, strategy: new RepeatedLegStrategy());
        $rest = new MinimumRestPeriodsConstraint(4);
        $scheduler = new RoundRobinScheduler(ConstraintSet::create()->add($rest)->build());
        $plan = $scheduler->getPlan($field, $options);

        // The count, asked of the constraint itself: once a pairing has
        // played in round 3, no round of leg 2 is open to it in either
        // order, and the first that is open is round 7, in leg 3. Whichever
        // pairings a schedule puts in round 3, it has two of them.
        $afterRoundThree = new SchedulingContext($field, $plan, [new Event([$field[0], $field[1]], new Round(3))], 2);
        $open = array_values(array_filter(
            range(4, 12),
            static fn(int $round): bool => $rest->isSatisfied(new Event([$field[0], $field[1]], new Round($round)), $afterRoundThree)
                || $rest->isSatisfied(new Event([$field[1], $field[0]], new Round($round)), $afterRoundThree)
        ));

        expect($plan->getRoundsPerLeg())->toBe(3)
            ->and($plan->getTotalRounds())->toBe(12)
            ->and($open)->toBe(range(7, 12));

        expect(fn() => $scheduler->schedule($field, $options))->toThrow(IncompleteScheduleException::class);
    });
});

describe('constraint sets at the edge of what a schedule satisfies', function (): void {
    // A rest of 3 rounds is the most four participants allow: with repeated
    // legs every pairing comes round again exactly 3 rounds later.
    it('rests every pairing for three rounds over four repeated legs of four participants', function (): void {
        $field = array_values(complexConstraintField([['a', null, []], ['b', null, []], ['c', null, []], ['d', null, []]]));
        $scheduler = new RoundRobinScheduler(ConstraintSet::create()->add(new MinimumRestPeriodsConstraint(3))->build());

        $schedule = $scheduler->schedule($field, new RoundRobinOptions(legs: 4, strategy: new RepeatedLegStrategy()));

        expect(RoundRobinAudit::faults($schedule, $field, 4))->toBe([]);

        // Counted in the events: the rounds each pairing is played in
        $roundsOf = [];
        foreach ($schedule->getEvents() as $event) {
            $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
            sort($ids);
            $roundsOf[implode(' + ', $ids)][] = $event->getRound()?->getNumber() ?? 0;
        }

        expect($roundsOf)->toHaveCount(6);
        foreach ($roundsOf as $pairing => $rounds) {
            expect($rounds)->toHaveCount(4, $pairing);
            for ($meeting = 1; $meeting < 4; ++$meeting) {
                expect($rounds[$meeting] - $rounds[$meeting - 1])->toBe(3, $pairing);
            }
        }
    });
});

// The case that was called "The Role Reversal Trap": eight participants,
// two legs with the shuffled strategy, and no more than two events in a row
// in the same role. The test used to assert that the scheduler throws, with
// an unseeded strategy, and took that for proof that the constraints are too
// restrictive. They are not.
describe('a limit of two same-role events in a row over two shuffled legs of eight', function (): void {
    beforeEach(function (): void {
        $this->field = array_values(complexConstraintField(array_map(
            static fn(string $suffix): array => [$suffix, null, []],
            ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']
        )));
        $this->constraints = ConstraintSet::create()
            ->add(ConsecutiveRoleConstraint::homeAway(2))
            ->add(new MinimumRestPeriodsConstraint(1))
            ->build();
    });

    // The schedule that shows it: the two mirrored legs the library itself
    // generates under the same constraints. Its second leg is the pairings
    // of the first in the same rounds with roles of its own, which is one
    // of the 2^28 role choices the shuffled strategy draws from. The roles
    // of the first leg are the round-parity ones, which two mirrored legs of
    // eight keep the limit with as they are, so the witness has the first
    // leg of the shuffled schedule.
    it('is satisfiable: a schedule exists, and its second leg is one the shuffled strategy may draw', function (): void {
        $witness = (new RoundRobinScheduler($this->constraints))->schedule(
            $this->field,
            new RoundRobinOptions(legs: 2, strategy: new MirroredLegStrategy(), roleAssignment: new RoundParityRoleAssignment())
        );

        // Checked in the events, not taken from the scheduler's word
        expect(RoundRobinAudit::faults($witness, $this->field, 2))->toBe([])
            ->and(RoleCounts::worstStreak($witness))->toBe(2);

        $shuffled = (new RoundRobinScheduler())->schedule(
            $this->field,
            new RoundRobinOptions(
                legs: 2,
                strategy: new ShuffledLegStrategy(new Randomizer(new Mt19937(1))),
                roleAssignment: new RoundParityRoleAssignment()
            )
        );

        expect(RoleCounts::structure($witness))->toBe(RoleCounts::structure($shuffled))
            ->and(RoleCounts::leg($witness, 1, 7))->toEqual(RoleCounts::leg($shuffled, 1, 7));
    });

    // The reproduction of issue #117. The role a shuffled leg draws for an
    // event is a preference: where the constraints reject it, the scheduler
    // takes the other role, and searches the roles of the later legs when
    // they reject both. The pairings, their rounds and the first leg are
    // those of the same field without constraints, under either role
    // assignment: the roles of the later legs are enough here, so the first
    // leg, which is searched only when they are not, is not touched.
    it('schedules the two legs with roles the constraints accept', function (?RoleAssignmentInterface $roleAssignment): void {
        $options = static fn(): RoundRobinOptions => new RoundRobinOptions(
            legs: 2,
            strategy: new ShuffledLegStrategy(new Randomizer(new Mt19937(1))),
            roleAssignment: $roleAssignment
        );
        $schedule = (new RoundRobinScheduler($this->constraints))->schedule($this->field, $options());

        expect(RoundRobinAudit::faults($schedule, $this->field, 2))->toBe([])
            ->and(RoleCounts::worstStreak($schedule))->toBeLessThanOrEqual(2);

        $unconstrained = (new RoundRobinScheduler())->schedule($this->field, $options());

        expect(RoleCounts::structure($schedule))->toBe(RoleCounts::structure($unconstrained))
            ->and(RoleCounts::leg($schedule, 1, 7))->toEqual(RoleCounts::leg($unconstrained, 1, 7))
            // The draws alone break the limit, so roles were changed for it
            ->and(RoleCounts::worstStreak($unconstrained))->toBeGreaterThan(2);
    })->with([
        'round parity' => [new RoundParityRoleAssignment()],
        'the balanced role assignment of the default' => [null],
    ]);

    // Every seed of the issue's forty, held to the limit and to its seed:
    // the same seed gives the same schedule from a new strategy.
    it('schedules them for every seed, and the same schedule for the same seed', function (): void {
        $limit = ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway(2))->build();
        $schedules = [];

        for ($seed = 1; $seed <= 40; ++$seed) {
            $options = static fn(): RoundRobinOptions => new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy(seed: $seed));
            $schedule = (new RoundRobinScheduler($limit))->schedule($this->field, $options());

            expect(RoundRobinAudit::faults($schedule, $this->field, 2))->toBe([], "seed {$seed}")
                ->and(RoleCounts::worstStreak($schedule))->toBeLessThanOrEqual(2)
                ->and((new RoundRobinScheduler($limit))->schedule($this->field, $options())->toJson())->toBe($schedule->toJson());

            $schedules[$schedule->toJson()] = true;
        }

        // The seed still decides the roles: the search does not flatten
        // every draw into one schedule
        expect(count($schedules))->toBeGreaterThan(30);
    });
});

// The same limit over two repeated legs of eight. A repeated leg has the
// roles of the first, so nothing in the second leg is free: what can change
// is the first leg. Under round parity, which states no rule, its roles are
// the role assignment's unless the caller turns the backtracking search on.
// The balanced roles of the default keep the limit as they are.
describe('a limit of two same-role events in a row over two repeated legs of eight', function (): void {
    beforeEach(function (): void {
        $this->field = array_values(complexConstraintField(array_map(
            static fn(string $suffix): array => [$suffix, null, []],
            ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']
        )));
        $this->constraints = ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway(2))->build();
    });

    // The count: the first leg of the round-parity roles keeps the limit by
    // itself, and played twice it does not. The participant order does not
    // matter to that, so no rotation helps.
    it('fails under round parity without backtracking, because the roles of the first leg are not searched', function (): void {
        $parity = static fn(int $legs): RoundRobinOptions => new RoundRobinOptions(
            legs: $legs,
            strategy: new RepeatedLegStrategy(),
            roleAssignment: new RoundParityRoleAssignment()
        );
        $single = (new RoundRobinScheduler($this->constraints))->schedule($this->field, $parity(1));
        $twice = (new RoundRobinScheduler())->schedule($this->field, $parity(2));

        expect(RoleCounts::worstStreak($single))->toBe(2)
            ->and(RoleCounts::leg($twice, 1, 7))->toEqual($single->getEvents())
            ->and(RoleCounts::worstStreak($twice))->toBe(3);

        $failure = null;
        try {
            (new RoundRobinScheduler($this->constraints))->schedule($this->field, $parity(2));
        } catch (IncompleteScheduleException $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(IncompleteScheduleException::class)
            ->and($failure?->getMessage())->toContain('leg 2')
            ->toContain('The roles of leg 1 were not searched: RoundRobinOptions(backtracking: true) searches them.')
            ->and($failure?->getActualEventCount())->toBeGreaterThanOrEqual(28)->toBeLessThan(56)
            ->and($failure?->getViolationCollector()->hasViolations())->toBeTrue();
    });

    // The balanced roles of eight repeat a role at most twice in a leg and
    // do not run on from the last round into the first, so two repeated
    // legs keep the limit as the role assignment gives them: the schedule
    // is the one without the constraint.
    it('schedules the two legs with the options as they come', function (): void {
        $options = new RoundRobinOptions(legs: 2, strategy: new RepeatedLegStrategy());

        $schedule = (new RoundRobinScheduler($this->constraints))->schedule($this->field, $options);

        expect(RoundRobinAudit::faults($schedule, $this->field, 2))->toBe([])
            ->and(RoleCounts::worstStreak($schedule))->toBe(2)
            ->and($schedule->toJson())->toBe((new RoundRobinScheduler())->schedule($this->field, $options)->toJson());
    });

    it('schedules the two legs with backtracking, the second with the roles of the first', function (): void {
        $schedule = (new RoundRobinScheduler($this->constraints))->schedule(
            $this->field,
            new RoundRobinOptions(legs: 2, strategy: new RepeatedLegStrategy(), backtracking: true, roleAssignment: new RoundParityRoleAssignment())
        );

        expect(RoundRobinAudit::faults($schedule, $this->field, 2))->toBe([])
            ->and(RoleCounts::worstStreak($schedule))->toBeLessThanOrEqual(2);

        // Repeated: the second leg is the first again, event for event
        $second = array_map(
            static fn(Event $event): array => [
                ($event->getRound()?->getNumber() ?? 0) - 7,
                $event->getParticipants()[0]->getId(),
                $event->getParticipants()[1]->getId(),
            ],
            RoleCounts::leg($schedule, 2, 7)
        );
        $first = array_map(
            static fn(Event $event): array => [
                $event->getRound()?->getNumber() ?? 0,
                $event->getParticipants()[0]->getId(),
                $event->getParticipants()[1]->getId(),
            ],
            RoleCounts::leg($schedule, 1, 7)
        );

        expect($second)->toBe($first);
    });
});

// A set that no roles satisfy still fails, and says what was searched.
describe('a pairing with no round open to it in the second of two shuffled legs of eight', function (): void {
    beforeEach(function (): void {
        $this->field = array_values(complexConstraintField(array_map(
            static fn(string $suffix): array => [$suffix, null, []],
            ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']
        )));
        // Rounds 8 to 14 are the second leg, and Team A meets Team B once
        // in it whatever the schedule: with every one of those rounds
        // closed to the pairing in either role, no schedule exists
        $this->closed = static function (Event $event): bool {
            $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
            sort($ids);

            return !($ids === ['team-a', 'team-b'] && ($event->getRound()?->getNumber() ?? 0) >= 8);
        };
        $this->constraints = ConstraintSet::create()
            ->add(ConsecutiveRoleConstraint::homeAway(2))
            ->custom($this->closed, 'Closed rounds')
            ->build();
    });

    // Under round parity the roles of the first leg are not searched, and
    // the failure says so. Under the balanced role assignment of the
    // default they are, within what it keeps, and the failure says what
    // that search came to: here it cannot show that no roles exist, since a
    // closure tells it nothing about what it reads, so it stops at a bound.
    it('fails loudly without backtracking, naming the constraint and what was not searched', function (
        ?RoleAssignmentInterface $roleAssignment,
        string $aboutTheFirstLeg
    ): void {
        $failure = null;
        try {
            (new RoundRobinScheduler($this->constraints))->schedule(
                $this->field,
                new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy(seed: 1), roleAssignment: $roleAssignment)
            );
        } catch (IncompleteScheduleException $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(IncompleteScheduleException::class)
            ->and($failure?->getMessage())->toContain('the constraints reject every choice of roles for the events of leg 2')
            ->toContain($aboutTheFirstLeg)
            ->toContain('RoundRobinOptions(backtracking: true) searches them.')
            ->and($failure?->getExpectedEventCount())->toBe(56)
            ->and($failure?->getActualEventCount())->toBeGreaterThanOrEqual(28)->toBeLessThan(56)
            ->and($failure?->getAnalysis())->not->toBeNull();

        // Both roles of the pairing were put to the constraints, and the
        // one that rejected them is recorded for each
        $rejected = array_map(
            static fn($violation): string => $violation->getConstraintName() . ': '
                . implode(' v ', array_map(static fn(Participant $participant): string => $participant->getId(), $violation->affectedParticipants)),
            $failure?->getViolationCollector()->getViolations() ?? []
        );
        sort($rejected);

        expect($rejected)->toBe(['Closed rounds: team-a v team-b', 'Closed rounds: team-b v team-a']);
    })->with([
        'round parity' => [
            new RoundParityRoleAssignment(),
            'in this layout. The pairings, their rounds and the roles of leg 1 were not searched: ',
        ],
        'the balanced role assignment of the default' => [
            null,
            'in this layout, with the roles leg 1 has in it. The roles of the first leg were then searched within what '
                . BalancedRoleAssignment::class . ' keeps, and the search stopped at a bound (20000 rejected roles, or 200000 events put to the constraints, over all the layouts).',
        ],
    ]);

    it('fails loudly with backtracking, and never returns part of a schedule', function (): void {
        $failure = null;
        try {
            (new RoundRobinScheduler($this->constraints))->schedule(
                $this->field,
                new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy(seed: 1), backtracking: true)
            );
        } catch (IncompleteScheduleException $exception) {
            $failure = $exception;
        }

        // Eight participants have more first legs than the budget reaches,
        // so the search cannot call the set unsatisfiable, and does not
        expect($failure)->toBeInstanceOf(IncompleteScheduleException::class)
            ->and($failure?->getMessage())->toContain('exhausted its step budget')
            ->toContain('may be unsatisfiable')
            ->and(str_contains($failure?->getMessage() ?? '', 'so the configuration is unsatisfiable'))->toBeFalse()
            ->and($failure?->getActualEventCount())->toBeGreaterThanOrEqual(28)->toBeLessThan(56)
            ->and($failure?->getPrevious())->toBeInstanceOf(IncompleteScheduleException::class);
    });
});
