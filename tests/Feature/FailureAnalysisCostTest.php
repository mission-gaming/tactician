<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\CallableConstraint;
use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\NoRepeatPairings;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\Diagnostics\SchedulingDiagnostics;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Counts what a round-robin run asks of its constraints.
 */
final class ConstraintWorkCounter
{
    /** How many times the constraint set was asked for its size. */
    public int $sizeQuestions = 0;

    /** How many times a constraint was evaluated. */
    public int $evaluations = 0;
}

/**
 * A constraint set that counts the failure analyses run against it.
 *
 * SchedulingDiagnostics asks the set for its size once per analysis (to skip
 * the attribution when there is nothing to attribute), and generation never
 * asks: the number of size questions is the number of analyses.
 */
final readonly class AnalysisCountingConstraintSet extends ConstraintSet
{
    /**
     * @param array<ConstraintInterface> $constraints
     */
    public function __construct(array $constraints, private ConstraintWorkCounter $counter)
    {
        parent::__construct($constraints);
    }

    #[Override]
    public function count(): int
    {
        ++$this->counter->sizeQuestions;

        return parent::count();
    }
}

/**
 * Counts the evaluations of the constraint it wraps.
 */
final readonly class EvaluationCountingConstraint implements ConstraintInterface
{
    public function __construct(private ConstraintInterface $inner, private ConstraintWorkCounter $counter) {}

    #[Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        ++$this->counter->evaluations;

        return $this->inner->isSatisfied($event, $context);
    }

    #[Override]
    public function getName(): string
    {
        return $this->inner->getName();
    }
}

/**
 * @return list<Participant>
 */
function analysisCostField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant(sprintf('p%02d', $i), "Participant {$i}", $i);
    }

    return $participants;
}

describe('The cost of a round robin that fails or retries', function (): void {
    // Acceptance: an unsatisfiable 24-participant, 2-leg round robin fails
    // promptly. What made it slow was one full failure analysis per rotated
    // ordering (24 of them here), all but the last thrown away. The test
    // counts work, not seconds, so that it holds on a slow or busy machine.
    it('analyses an unsatisfiable round robin once, for the attempt it reports', function (): void {
        $counter = new ConstraintWorkCounter();
        $constraints = new AnalysisCountingConstraintSet(
            // Forbidding every repeat makes a second leg impossible by design.
            [new EvaluationCountingConstraint(new NoRepeatPairings(acrossLegs: true), $counter)],
            $counter
        );

        $failure = null;
        try {
            (new RoundRobinScheduler($constraints))->schedule(analysisCostField(24), new RoundRobinOptions(legs: 2));
        } catch (IncompleteScheduleException $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(IncompleteScheduleException::class);
        expect($failure?->getAnalysis())->not->toBeNull();
        expect($counter->sizeQuestions)->toBe(1);

        // One attempt evaluates each of the 552 pairings of the two legs once,
        // and each rejected one again to record which constraint rejected it:
        // at most 1,104 evaluations, 26,496 over the 24 attempts. The one
        // analysis probes the 276 pairings missing from leg 2 in each of the
        // 46 rounds, both ways round, against the set and against the
        // constraint: at most 50,784. Twenty-four analyses were over 1.2
        // million.
        expect($counter->evaluations)->toBeLessThanOrEqual(26_496 + 50_784);
    });

    it('analyses nothing when a later ordering succeeds', function (): void {
        $counter = new ConstraintWorkCounter();
        // The input order pairs the top two seeds inside the protected
        // window; the third rotation does not.
        $constraints = new AnalysisCountingConstraintSet([new SeedProtectionConstraint(2, 0.25)], $counter);

        $schedule = (new RoundRobinScheduler($constraints))->schedule(analysisCostField(32), new RoundRobinOptions(legs: 2));

        expect(count($schedule))->toBe(992);
        expect($counter->sizeQuestions)->toBe(0);
    });

    it('reports the analysis of the last ordering tried', function (): void {
        $participants = analysisCostField(6);
        $constraints = new ConstraintSet([new CallableConstraint(static fn(): bool => false, 'Reject Everything')]);
        $scheduler = new RoundRobinScheduler($constraints);

        $failure = null;
        try {
            $scheduler->schedule($participants);
        } catch (IncompleteScheduleException $exception) {
            $failure = $exception;
        }

        // Six participants give six orderings; the last starts at the sixth
        // participant. Nothing is ever generated, so the analysis of that
        // attempt is the analysis of an empty schedule for that ordering,
        // which lists the missing pairings in its order.
        $lastOrdering = [$participants[5], ...array_slice($participants, 0, 5)];
        $expected = (new SchedulingDiagnostics())->analyzeSchedulingFailure(
            $lastOrdering,
            $constraints,
            [],
            $scheduler->getPlan($participants)
        );

        expect($failure?->getAnalysis()?->toString())->toBe($expected->toString());
        expect($failure?->getAnalysis()?->getMissingPairings())->toBe($expected->getMissingPairings());
        expect($failure?->getAnalysis()?->getMissingPairings()[0] ?? null)->toBe('Participant 6 vs Participant 1 (Leg 1)');
    });
});
