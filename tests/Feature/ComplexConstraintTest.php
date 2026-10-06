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
// One case turned out not to be infeasible. It is kept at the end with the
// schedule that shows it, and with the behaviour that is wanted marked as
// not yet there.

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
    // of the 2^28 role choices the shuffled strategy draws from.
    it('is satisfiable: a schedule exists, and its second leg is one the shuffled strategy may draw', function (): void {
        $witness = (new RoundRobinScheduler($this->constraints))
            ->schedule($this->field, new RoundRobinOptions(legs: 2, strategy: new MirroredLegStrategy()));

        // Checked in the events, not taken from the scheduler's word
        expect(RoundRobinAudit::faults($witness, $this->field, 2))->toBe([])
            ->and(RoleCounts::worstStreak($witness))->toBe(2);

        $shuffled = (new RoundRobinScheduler())->schedule(
            $this->field,
            new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy(new Randomizer(new Mt19937(1))))
        );

        expect(RoleCounts::structure($witness))->toBe(RoleCounts::structure($shuffled))
            ->and(RoleCounts::leg($witness, 1, 7))->toEqual(RoleCounts::leg($shuffled, 1, 7));
    });

    // What is wanted. The shuffled strategy draws each role of the second
    // leg once and does not draw again when the constraints reject it, so
    // generation fails although roles that satisfy the constraints exist
    // (the test above). A greedy false negative: reported with issue #42.
    it('schedules the two legs with roles the constraints accept', function (): void {
        $schedule = (new RoundRobinScheduler($this->constraints))->schedule(
            $this->field,
            new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy(new Randomizer(new Mt19937(1))))
        );

        expect(RoundRobinAudit::faults($schedule, $this->field, 2))->toBe([])
            ->and(RoleCounts::worstStreak($schedule))->toBeLessThanOrEqual(2);
    })->todo();

    // What happens until then: the failure is loud and typed, and no
    // incomplete schedule is returned. Seeded, so that the test does not
    // depend on a draw. (None of 200 seeds drew a schedule when this was
    // written; five are run here, because each failure builds its full
    // diagnostic report.)
    it('fails loudly meanwhile, and never returns part of a schedule', function (): void {
        for ($seed = 1; $seed <= 5; ++$seed) {
            $schedule = null;
            $failure = null;

            try {
                $schedule = (new RoundRobinScheduler($this->constraints))->schedule(
                    $this->field,
                    new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy(new Randomizer(new Mt19937($seed))))
                );
            } catch (IncompleteScheduleException $exception) {
                $failure = $exception;
            }

            // A schedule, should a seed ever draw one, must be complete and
            // within the limit; a failure must say how far it got
            if ($schedule instanceof Schedule) {
                expect(RoundRobinAudit::faults($schedule, $this->field, 2))->toBe([], "seed {$seed}")
                    ->and(RoleCounts::worstStreak($schedule))->toBeLessThanOrEqual(2);

                continue;
            }

            expect($failure)->toBeInstanceOf(IncompleteScheduleException::class)
                ->and($failure?->getExpectedEventCount())->toBe(56)
                ->and($failure?->getActualEventCount())->toBeGreaterThanOrEqual(28)->toBeLessThan(56)
                ->and($failure?->getViolationCollector()->hasViolations())->toBeTrue();
        }
    });
});
