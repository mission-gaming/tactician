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
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use MissionGaming\Tactician\Scheduling\ConstraintPurity;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

// Gap left knowingly: how many failure analyses a run builds is not counted
// here. Until 0.3.0 these tests counted them through a subclass of
// Participant whose getLabel() counted its calls (an analysis reads four
// labels for every pairing it reports missing, and generation reads none).
// Participant is final from 0.3.0, and under the constraints the scheduler
// knows no code of the caller's runs during an analysis, so nothing the
// test could hand the scheduler sees one being built. What is pinned is
// what the analyses produce, and the decision that lets the scheduler skip
// them (ConstraintPurity::isKnown(), tests/Unit/Scheduling/ConstraintPurityTest.php).

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
    // ordering (24 of them here), all but the last thrown away. The number
    // of analyses is not counted (see the note at the top of the file).
    it('analyses an unsatisfiable round robin once, for the attempt it reports', function (): void {
        // Forbidding every repeat makes a second leg impossible by design.
        $constraints = new ConstraintSet([new NoRepeatPairings(acrossLegs: true)]);
        expect(ConstraintPurity::isKnown($constraints))->toBeTrue();

        $failure = null;
        try {
            new RoundRobinScheduler($constraints)->schedule(analysisCostField(24), new RoundRobinOptions(legs: 2));
        } catch (IncompleteScheduleException $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(IncompleteScheduleException::class);
        expect($failure?->getAnalysis())->not->toBeNull();
        // The 276 pairings of leg 2 are all missing
        expect($failure?->getAnalysis()?->getMissingPairings())->toHaveCount(276);
    });

    it('analyses nothing when a later ordering succeeds', function (): void {
        // The input order pairs the top two seeds inside the protected
        // window; the third rotation does not.
        $constraints = new ConstraintSet([new SeedProtectionConstraint(2, 0.25)]);
        expect(ConstraintPurity::isKnown($constraints))->toBeTrue();

        $schedule = new RoundRobinScheduler($constraints)->schedule(analysisCostField(32), new RoundRobinOptions(legs: 2));

        expect(count($schedule))->toBe(992);
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
        $expected = new SchedulingDiagnostics()->analyzeSchedulingFailure(
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
        expect(ConstraintPurity::isKnown($constraints))->toBeFalse();

        $failure = null;
        try {
            new RoundRobinScheduler($constraints)->schedule(analysisCostField(6), new RoundRobinOptions(legs: 2));
        } catch (IncompleteScheduleException $exception) {
            $failure = $exception;
        }

        // The analysis reported is of the last of the six orderings, missing
        // the 15 pairings of leg 2
        expect($failure?->getAnalysis()?->getMissingPairings())->toHaveCount(15);
    })->with([
        'a callable' => [fn(): ConstraintSet => new ConstraintSet([new CallableConstraint(
            static fn(Event $event, SchedulingContext $context): bool => new NoRepeatPairings(acrossLegs: true)->isSatisfied($event, $context),
            'No repeats, by a callable'
        )])],
        'a built-in constraint beside a callable' => [fn(): ConstraintSet => new ConstraintSet([
            new NoRepeatPairings(acrossLegs: true),
            new CallableConstraint(static fn(): bool => true, 'Accept Everything'),
        ])],
    ]);

    it('analyses the same six-participant failure once for the built-in constraint itself', function (): void {
        $constraints = new ConstraintSet([new NoRepeatPairings(acrossLegs: true)]);
        expect(ConstraintPurity::isKnown($constraints))->toBeTrue();

        $failure = null;
        try {
            new RoundRobinScheduler($constraints)->schedule(analysisCostField(6), new RoundRobinOptions(legs: 2));
        } catch (IncompleteScheduleException $exception) {
            $failure = $exception;
        }

        expect($failure?->getAnalysis()?->getMissingPairings())->toHaveCount(15);
    });

    it('gives a constraint that keeps state the schedule it got when every ordering was analysed', function (): void {
        // The constraint keeps the first two participants apart until it
        // has been asked 150 times. The first ordering fails on that. Its
        // analysis asks more than 100 questions, which takes the count past
        // 150, so the second ordering is free to pair them and succeeds.
        // Without that analysis the count stays low and all six orderings
        // fail.
        //
        // Round parity is named: it is asked exactly as the default of
        // 0.2.x was. The default of 0.3.0 tries balanced roles as well,
        // before and after, so it asks such a constraint other questions
        // and more of them; what it then gets is a schedule here too, and
        // nothing about how often it asks is promised (docs/USAGE.md, "What
        // a constraint may rely on").
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

        $schedule = new RoundRobinScheduler($constraints)->schedule(
            analysisCostField(6),
            new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment())
        );

        expect(count($schedule))->toBe(15);
        expect($asked)->toBe(185);

        $asked = 0;
        expect(count(new RoundRobinScheduler($constraints)->schedule(analysisCostField(6))))->toBe(15);
    });
});
