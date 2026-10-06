<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\CallableConstraint;
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
 * Counts how often the labels of a field's participants were read.
 */
final class LabelReadCounter
{
    public int $reads = 0;
}

/**
 * A participant that counts the reads of its label.
 *
 * Generating a round robin never reads a label. A failure analysis reads
 * four for every pairing it reports missing (two to list it and two to
 * attribute it), and the completeness check of a schedule that succeeded
 * reads each participant's once. So the number of label reads says how many
 * analyses were built. That counts them without touching the constraints, which
 * matters: the scheduler skips the analysis of a discarded ordering only
 * for constraints it knows, and a constraint or a constraint set that
 * counted its own calls would not be one of them.
 */
final readonly class LabelCountingParticipant extends Participant
{
    public function __construct(string $id, string $label, int $seed, private LabelReadCounter $counter)
    {
        parent::__construct($id, $label, $seed);
    }

    #[Override]
    public function getLabel(): string
    {
        ++$this->counter->reads;

        return parent::getLabel();
    }
}

/**
 * A constraint set that is not ConstraintSet itself, and changes nothing.
 */
final readonly class UnknownConstraintSet extends ConstraintSet {}

/**
 * A constraint that is not NoRepeatPairings itself, and changes nothing.
 */
final readonly class UnknownNoRepeatPairings extends NoRepeatPairings {}

/**
 * @return list<Participant>
 */
function analysisCostField(int $count, ?LabelReadCounter $counter = null): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = $counter === null
            ? new Participant(sprintf('p%02d', $i), "Participant {$i}", $i)
            : new LabelCountingParticipant(sprintf('p%02d', $i), "Participant {$i}", $i, $counter);
    }

    return $participants;
}

describe('The cost of a round robin that fails or retries', function (): void {
    // Acceptance: an unsatisfiable 24-participant, 2-leg round robin fails
    // promptly. What made it slow was one full failure analysis per rotated
    // ordering (24 of them here), all but the last thrown away. The test
    // counts work, not seconds, so that it holds on a slow or busy machine.
    it('analyses an unsatisfiable round robin once, for the attempt it reports', function (): void {
        $counter = new LabelReadCounter();
        // Forbidding every repeat makes a second leg impossible by design.
        $constraints = new ConstraintSet([new NoRepeatPairings(acrossLegs: true)]);

        $failure = null;
        try {
            (new RoundRobinScheduler($constraints))->schedule(analysisCostField(24, $counter), new RoundRobinOptions(legs: 2));
        } catch (IncompleteScheduleException $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(IncompleteScheduleException::class);
        expect($failure?->getAnalysis())->not->toBeNull();
        // The 276 pairings of leg 2 are all missing, at four label reads
        // each: one analysis reads 1,104. Twenty-four analyses read 26,496.
        expect($failure?->getAnalysis()?->getMissingPairings())->toHaveCount(276);
        expect($counter->reads)->toBe(1104);
    });

    it('analyses nothing when a later ordering succeeds', function (): void {
        $counter = new LabelReadCounter();
        // The input order pairs the top two seeds inside the protected
        // window; the third rotation does not.
        $constraints = new ConstraintSet([new SeedProtectionConstraint(2, 0.25)]);

        $schedule = (new RoundRobinScheduler($constraints))->schedule(analysisCostField(32, $counter), new RoundRobinOptions(legs: 2));

        expect(count($schedule))->toBe(992);
        // The completeness check of the finished schedule reads the 32
        // labels once. An analysis of each of the two orderings that failed
        // read 3,972 more.
        expect($counter->reads)->toBe(32);
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

describe('A round robin under constraints that could tell how often they are asked', function (): void {
    // An analysis asks the constraints about pairings the attempt never
    // tried. A constraint of the caller's may keep state or throw, so what
    // it was asked before the next ordering decides what that ordering
    // gets. For such constraints every ordering is analysed, as before.
    it('still analyses every ordering', function (ConstraintSet $constraints): void {
        $counter = new LabelReadCounter();

        $failure = null;
        try {
            (new RoundRobinScheduler($constraints))->schedule(analysisCostField(6, $counter), new RoundRobinOptions(legs: 2));
        } catch (IncompleteScheduleException $exception) {
            $failure = $exception;
        }

        expect($failure?->getAnalysis()?->getMissingPairings())->toHaveCount(15);
        // Six orderings, each missing the 15 pairings of leg 2 at four
        // label reads a pairing: 6 x 60.
        expect($counter->reads)->toBe(360);
    })->with([
        'a callable' => [fn(): ConstraintSet => new ConstraintSet([new CallableConstraint(
            static fn(Event $event, SchedulingContext $context): bool => (new NoRepeatPairings(acrossLegs: true))->isSatisfied($event, $context),
            'No repeats, by a callable'
        )])],
        'a subclass of the constraint set' => [fn(): ConstraintSet => new UnknownConstraintSet([new NoRepeatPairings(acrossLegs: true)])],
        'a subclass of a built-in constraint' => [fn(): ConstraintSet => new ConstraintSet([new UnknownNoRepeatPairings(acrossLegs: true)])],
        'a built-in constraint beside a callable' => [fn(): ConstraintSet => new ConstraintSet([
            new NoRepeatPairings(acrossLegs: true),
            new CallableConstraint(static fn(): bool => true, 'Accept Everything'),
        ])],
    ]);

    it('analyses the same six-participant failure once for the built-in constraint itself', function (): void {
        $counter = new LabelReadCounter();

        try {
            (new RoundRobinScheduler(new ConstraintSet([new NoRepeatPairings(acrossLegs: true)])))
                ->schedule(analysisCostField(6, $counter), new RoundRobinOptions(legs: 2));
        } catch (IncompleteScheduleException) {
        }

        expect($counter->reads)->toBe(60);
    });

    it('gives a constraint that keeps state the schedule it got when every ordering was analysed', function (): void {
        // The constraint keeps the first two participants apart until it
        // has been asked 150 times. The first ordering fails on that. Its
        // analysis asks more than 100 questions, which takes the count past
        // 150, so the second ordering is free to pair them and succeeds.
        // Without that analysis the count stays low and all six orderings
        // fail.
        $asked = 0;
        $constraints = new ConstraintSet([new CallableConstraint(
            static function (Event $event) use (&$asked): bool {
                ++$asked;
                $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
                sort($ids);

                return $asked > 150 || $ids !== ['p01', 'p02'];
            },
            'Apart for 150 questions'
        )]);

        $schedule = (new RoundRobinScheduler($constraints))->schedule(analysisCostField(6));

        expect(count($schedule))->toBe(15);
        expect($asked)->toBe(185);
    });
});
