<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Diagnostics\BlockedPairing;
use MissionGaming\Tactician\Diagnostics\ConstraintRejection;
use MissionGaming\Tactician\Diagnostics\DiagnosticReport;
use MissionGaming\Tactician\Diagnostics\MissingPairing;
use MissionGaming\Tactician\Diagnostics\PairingAtCapacity;
use MissionGaming\Tactician\Diagnostics\SchedulingDiagnostics;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Stage\RoundRobinPlan;

/**
 * Whether an event pairs the two participants with these IDs, in either
 * role order.
 */
function structuredPairs(Event $event, string $one, string $other): bool
{
    $ids = array_map(fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
    sort($ids);
    $pair = [$one, $other];
    sort($pair);

    return $ids === $pair;
}

describe('The typed findings of a failure analysis', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->carol = new Participant('p3', 'Carol');
        $this->field = [$this->alice, $this->bob, $this->carol];
    });

    it('lists the missing pairings by ID and leg, in the order of the lines', function (): void {
        $report = new SchedulingDiagnostics()->analyzeSchedulingFailure(
            $this->field,
            ConstraintSet::create()->build(),
            [new Event([$this->alice, $this->bob], new Round(1))],
            new RoundRobinPlan($this->field, 2)
        );

        expect($report->getMissingPairings())->toBe([
            'Alice vs Bob (Leg 2)',
            'Alice vs Carol (Leg 1)',
            'Alice vs Carol (Leg 2)',
            'Bob vs Carol (Leg 1)',
            'Bob vs Carol (Leg 2)',
        ])->and($report->getMissingPairingDetails())->toEqual([
            new MissingPairing('p1', 'p2', 2),
            new MissingPairing('p1', 'p3', 1),
            new MissingPairing('p1', 'p3', 2),
            new MissingPairing('p2', 'p3', 1),
            new MissingPairing('p2', 'p3', 2),
        ]);
    });

    it('names a constraint that blocks a pairing everywhere, and what it rejects', function (): void {
        $noDerby = ConstraintSet::create()
            ->custom(static fn(Event $event): bool => !structuredPairs($event, 'p1', 'p2'), 'Derby Ban')
            ->build();

        $report = new SchedulingDiagnostics()->analyzeSchedulingFailure(
            $this->field,
            $noDerby,
            [new Event([$this->alice, $this->carol], new Round(1))],
            new RoundRobinPlan($this->field, 1)
        );

        $blocked = $report->getBlockedPairings();
        $rejections = $report->getConstraintRejections();

        expect($report->getImpossiblePairings())->toBe([
            'Alice vs Bob cannot join the generated schedule in any round (blocked by: Derby Ban)',
        ])
            ->and($blocked)->toEqual([new BlockedPairing('p1', 'p2', ['Derby Ban'])])
            ->and($blocked[0]->isBlockedByCombination())->toBeFalse()
            ->and($report->getConstraintViolations())->toBe(['Derby Ban rejects Alice vs Bob in 3 of 3 rounds'])
            ->and($rejections)->toEqual([new ConstraintRejection('Derby Ban', 'p1', 'p2', 3, 3)])
            ->and($rejections[0]->rejectsEveryRound())->toBeTrue();
    });

    it('says a combination blocks a pairing where no single constraint does', function (): void {
        $alternate = ConstraintSet::create()
            ->custom(static fn(Event $event): bool => !structuredPairs($event, 'p1', 'p2') || $event->getRoundNumber() % 2 === 0, 'Even Rounds Only')
            ->custom(static fn(Event $event): bool => !structuredPairs($event, 'p1', 'p2') || $event->getRoundNumber() % 2 === 1, 'Odd Rounds Only')
            ->build();

        $report = new SchedulingDiagnostics()->analyzeSchedulingFailure($this->field, $alternate, [], new RoundRobinPlan($this->field, 1));

        $blocked = $report->getBlockedPairings();
        expect($blocked)->toEqual([new BlockedPairing('p1', 'p2', [])])
            ->and($blocked[0]->isBlockedByCombination())->toBeTrue()
            ->and($report->getConstraintRejections())->toEqual([
                new ConstraintRejection('Even Rounds Only', 'p1', 'p2', 2, 3),
                new ConstraintRejection('Odd Rounds Only', 'p1', 'p2', 1, 3),
            ])
            ->and($report->getConstraintViolations())->toBe([
                'Even Rounds Only rejects Alice vs Bob in 2 of 3 rounds',
                'Odd Rounds Only rejects Alice vs Bob in 1 of 3 rounds',
            ]);
    });

    it('groups the rejections by constraint as the lines do', function (): void {
        // One constraint charged for two pairings, a second for one: the
        // lines are one per constraint, the typed data one per pairing
        $dave = new Participant('p4', 'Dave');
        $field = [...$this->field, $dave];
        $constraints = ConstraintSet::create()
            ->custom(static fn(Event $event): bool => !$event->hasParticipant(new Participant('p1', 'Alice')) || $event->getRound()?->getNumber() !== 1, 'Alice Rests First')
            ->custom(static fn(Event $event): bool => !structuredPairs($event, 'p2', 'p3'), 'No Bob v Carol')
            ->build();

        $report = new SchedulingDiagnostics()->analyzeSchedulingFailure($field, $constraints, [], new RoundRobinPlan($field, 1));

        expect($report->getConstraintViolations())->toBe([
            'Alice Rests First rejects Alice vs Bob in 1 of 3 rounds; Alice vs Carol in 1 of 3 rounds; Alice vs Dave in 1 of 3 rounds',
            'No Bob v Carol rejects Bob vs Carol in 3 of 3 rounds',
        ])->and($report->getConstraintRejections())->toEqual([
            new ConstraintRejection('Alice Rests First', 'p1', 'p2', 1, 3),
            new ConstraintRejection('Alice Rests First', 'p1', 'p3', 1, 3),
            new ConstraintRejection('Alice Rests First', 'p1', 'p4', 1, 3),
            new ConstraintRejection('No Bob v Carol', 'p2', 'p3', 3, 3),
        ]);
    });

    it('lists a pairing allowed only in full rounds, with those rounds', function (): void {
        $dave = new Participant('p4', 'Dave');
        $field = [...$this->field, $dave];
        $roundOne = ConstraintSet::create()
            ->custom(static fn(Event $event): bool => !structuredPairs($event, 'p1', 'p2') || $event->getRound()?->getNumber() === 1, 'Round One Derby')
            ->build();

        $report = new SchedulingDiagnostics()->analyzeSchedulingFailure(
            $field,
            $roundOne,
            [new Event([$this->alice, $this->carol], new Round(1)), new Event([$this->bob, $dave], new Round(1))],
            new RoundRobinPlan($field, 1)
        );

        expect($report->getPairingsAtCapacity())->toEqual([new PairingAtCapacity('p1', 'p2', [1])])
            ->and(array_values(array_filter($report->getSuggestions(), fn(string $line): bool => str_contains($line, 'at capacity'))))
            ->toBe(['Alice vs Bob is only allowed in rounds already at capacity (rounds 1) - the conflict is structural, not any single constraint']);
    });

    it('is empty where the text is', function (): void {
        $report = new SchedulingDiagnostics()->analyzeSchedulingFailure(
            $this->field,
            ConstraintSet::create()->build(),
            [],
            new RoundRobinPlan($this->field, 1)
        );

        expect($report->getBlockedPairings())->toBe([])
            ->and($report->getConstraintRejections())->toBe([])
            ->and($report->getPairingsAtCapacity())->toBe([])
            ->and(new DiagnosticReport(3, 3, 0, 3)->getMissingPairingDetails())->toBe([]);
    });

    // The acceptance criterion of #58: a consumer renders a generation
    // failure without parsing text. Everything below is read from typed
    // accessors and the participants the application holds by ID.
    it('lets a consumer render a generation failure without parsing text', function (): void {
        $field = [];
        foreach (['ana' => 'Ana', 'ben' => 'Ben', 'cy' => 'Cy', 'dee' => 'Dee'] as $id => $name) {
            $field[$id] = new Participant($id, $name);
        }
        $noAnaBen = ConstraintSet::create()
            ->custom(static fn(Event $event): bool => !structuredPairs($event, 'ana', 'ben'), 'Kept apart')
            ->build();

        try {
            new RoundRobinScheduler($noAnaBen)->schedule(array_values($field), new RoundRobinOptions(legs: 1));
            $this->fail('The schedule was generated');
        } catch (IncompleteScheduleException $exception) {
            $report = $exception->getAnalysis();
            expect($report)->not->toBeNull();

            $lines = [];
            foreach ($report?->getMissingPairingDetails() ?? [] as $missing) {
                $lines[] = sprintf('missing: %s / %s, leg %d', $field[$missing->firstParticipantId]->getLabel(), $field[$missing->secondParticipantId]->getLabel(), $missing->leg);
            }
            foreach ($report?->getBlockedPairings() ?? [] as $blocked) {
                $lines[] = sprintf('blocked: %s / %s by %s', $field[$blocked->firstParticipantId]->getLabel(), $field[$blocked->secondParticipantId]->getLabel(), implode(', ', $blocked->blockingConstraints));
            }

            expect($lines)->toContain('blocked: Ana / Ben by Kept apart')
                ->and(array_filter($lines, fn(string $line): bool => str_starts_with($line, 'missing: ')))->not->toBe([])
                ->and(count($report?->getMissingPairingDetails() ?? []))->toBe(count($report?->getMissingPairings() ?? []));
        }
    });
});
