<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Diagnostics\DiagnosticReport;
use MissionGaming\Tactician\Diagnostics\SchedulingDiagnostics;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Stage\RoundRobinPlan;
use MissionGaming\Tactician\Stage\StagePlan;

describe('SchedulingDiagnostics', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->carol = new Participant('p3', 'Carol');
        $this->participants = [$this->alice, $this->bob, $this->carol];
        $this->constraints = ConstraintSet::create()->build();
        $this->diagnostics = new SchedulingDiagnostics();
    });

    // Regression: expected pairings were labelled "(Leg N)" and existing
    // pairings "(Round N)", so no pairing ever matched and every pairing was
    // always reported missing - even for complete schedules.
    it('reports no missing pairings for a complete schedule', function (): void {
        $events = [
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->bob, $this->carol], new Round(2)),
            new Event([$this->alice, $this->carol], new Round(3)),
        ];

        $report = $this->diagnostics->analyzeSchedulingFailure(
            $this->participants,
            $this->constraints,
            $events,
            new RoundRobinPlan($this->participants, 1)
        );

        expect($report->getMissingPairings())->toBe([]);
        expect($report->getMissingEvents())->toBe(0);
        expect($report->getCompletionPercentage())->toBe(100.0);
        expect($report->isSuccessful())->toBeTrue();
    });

    it('reports the specific pairing that is missing', function (): void {
        $events = [
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->bob, $this->carol], new Round(2)),
        ];

        $report = $this->diagnostics->analyzeSchedulingFailure(
            $this->participants,
            $this->constraints,
            $events,
            new RoundRobinPlan($this->participants, 1)
        );

        expect($report->getMissingPairings())->toBe(['Alice vs Carol (Leg 1)']);
        expect($report->getMissingEvents())->toBe(1);
    });

    it('matches pairings regardless of home/away order', function (): void {
        // Mirrored legs reverse the participant order; the pairing must
        // still be recognized as played
        $events = [
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->bob, $this->alice], new Round(4)),
        ];

        $report = $this->diagnostics->analyzeSchedulingFailure(
            [$this->alice, $this->bob],
            $this->constraints,
            $events,
            new RoundRobinPlan([$this->alice, $this->bob], 2)
        );

        expect($report->getMissingPairings())->toBe([]);
    });

    it('reports the missing leg occurrences for multi-leg tournaments', function (): void {
        $events = [
            new Event([$this->alice, $this->bob], new Round(1)),
        ];

        $report = $this->diagnostics->analyzeSchedulingFailure(
            [$this->alice, $this->bob],
            $this->constraints,
            $events,
            new RoundRobinPlan([$this->alice, $this->bob], 3)
        );

        expect($report->getMissingPairings())->toBe([
            'Alice vs Bob (Leg 2)',
            'Alice vs Bob (Leg 3)',
        ]);
        expect($report->getGeneratedEvents())->toBe(1);
        expect($report->getExpectedEvents())->toBe(3);
    });

    // Regression: meetings were counted without leg attribution, so a
    // duplicate meeting in leg 1 masked the same pairing missing from leg 2
    it('does not let a duplicate meeting in one leg mask a missing meeting in another', function (): void {
        // 2 participants, 2 legs => rounds per leg is 1; both meetings landed
        // in leg 1 (rounds 1 and... round 1 again) and leg 2 never happened
        $events = [
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->alice, $this->bob], new Round(1)),
        ];

        $report = $this->diagnostics->analyzeSchedulingFailure(
            [$this->alice, $this->bob],
            $this->constraints,
            $events,
            new RoundRobinPlan([$this->alice, $this->bob], 2)
        );

        expect($report->getMissingPairings())->toBe(['Alice vs Bob (Leg 2)']);
    });

    it('flags small multi-leg fields as conflicts', function (): void {
        $conflicts = $this->diagnostics->identifyConstraintConflicts(
            $this->participants,
            $this->constraints,
            new RoundRobinPlan($this->participants, 2)
        );

        expect($conflicts)->toContain('Multi-leg tournaments require at least 4 participants for meaningful scheduling');
    });

    // Library plans refuse to construct for fields that cannot play at all,
    // but a custom plan may still declare zero expected events
    it('flags plans declaring zero expected events as insufficient', function (): void {
        $emptyPlan = new readonly class () implements StagePlan {
            #[Override]
            public function getAlgorithm(): string
            {
                return 'custom';
            }

            #[Override]
            public function getTotalRounds(): ?int
            {
                return null;
            }

            #[Override]
            public function getLegs(): ?int
            {
                return null;
            }

            #[Override]
            public function getRoundsPerLeg(): ?int
            {
                return null;
            }

            #[Override]
            public function getExpectedEventCount(): int
            {
                return 0;
            }

            #[Override]
            public function validateIntegrity(Schedule $schedule): array
            {
                return [];
            }
        };

        $conflicts = $this->diagnostics->identifyConstraintConflicts(
            [$this->alice],
            $this->constraints,
            $emptyPlan
        );

        expect($conflicts)->toContain('Insufficient participants for tournament generation');
    });

    // Missing-pairing analysis is a pairwise-plan capability; other
    // formats yield none
    it('reports no missing pairings for non-pairwise plans', function (): void {
        $report = $this->diagnostics->analyzeSchedulingFailure(
            $this->participants,
            $this->constraints,
            [],
            new MissionGaming\Tactician\Stage\SwissPlan($this->participants, 3)
        );

        expect($report->getMissingPairings())->toBe([]);
    });

    it('skips malformed events in the meeting count', function (): void {
        $events = [
            new Event([$this->alice, $this->bob, $this->carol], new Round(1)), // not pairwise
            new Event([$this->alice, $this->bob], new Round(1)),
        ];

        $report = $this->diagnostics->analyzeSchedulingFailure(
            $this->participants,
            $this->constraints,
            $events,
            new RoundRobinPlan($this->participants, 1)
        );

        // Only the malformed event is ignored; real pairings still count
        expect($report->getMissingPairings())->toBe([
            'Alice vs Carol (Leg 1)',
            'Bob vs Carol (Leg 1)',
        ]);
    });

    it('suggests checking configuration when nothing was generated and flags odd multi-leg fields', function (): void {
        $oddField = [$this->alice, $this->bob, $this->carol];
        $report = $this->diagnostics->analyzeSchedulingFailure(
            $oddField,
            $this->constraints,
            [],
            new RoundRobinPlan($oddField, 2)
        );

        expect($report->getSuggestions())->toContain('No events were generated - check participant count and constraint configuration');
        expect($report->getSuggestions())->toContain('Odd participant count in multi-leg tournaments may cause scheduling challenges');
    });

    it('suggests adjustments for impossible pairings and violations in a report', function (): void {
        $report = new DiagnosticReport(
            participantCount: 4,
            expectedEvents: 6,
            generatedEvents: 4,
            missingEvents: 2,
            constraintViolations: ['seed protection rejected round 1'],
            impossiblePairings: ['Alice vs Bob']
        );

        $suggestions = $this->diagnostics->suggestConstraintAdjustments($report);

        expect($suggestions)->toContain('Some participant pairings cannot be satisfied with current constraints');
        expect($suggestions)->toContain('Review constraint configuration for potential conflicts');
    });

    it('suggests adjustments proportional to the failure', function (): void {
        $report = $this->diagnostics->analyzeSchedulingFailure(
            $this->participants,
            $this->constraints,
            [],
            new RoundRobinPlan($this->participants, 1),
            ['leg' => 2]
        );

        $suggestions = $this->diagnostics->suggestConstraintAdjustments($report);

        expect($suggestions)->toContain('Consider relaxing constraints that may be preventing event generation');
        expect($suggestions)->toContain('Multi-leg constraint validation may require different strategy');
    });

    it('yields no missing pairings for degenerate participant lists', function (): void {
        $report = $this->diagnostics->analyzeSchedulingFailure(
            [$this->alice],
            $this->constraints,
            [],
            new RoundRobinPlan([$this->alice, $this->bob], 1)
        );

        expect($report->getMissingPairings())->toBe([]);
    });

    it('skips pairs the plan expects no meetings for', function (): void {
        $outsider = new Participant('x1', 'Outsider');

        // The outsider is in the analyzed list but not in the plan, so
        // pairs involving them are expected zero times and never reported
        $report = $this->diagnostics->analyzeSchedulingFailure(
            [$this->alice, $this->bob, $outsider],
            $this->constraints,
            [],
            new RoundRobinPlan([$this->alice, $this->bob], 1)
        );

        expect($report->getMissingPairings())->toBe(['Alice vs Bob (Leg 1)']);
    });

    // A pairwise plan violating the shape contract (legs without rounds
    // or totals) must clamp leg attribution rather than divide by zero
    it('clamps leg attribution for plans without knowable rounds', function (): void {
        $plan = new readonly class () implements MissionGaming\Tactician\Stage\PairwisePlan {
            #[Override]
            public function getAlgorithm(): string
            {
                return 'custom-pairwise';
            }

            #[Override]
            public function getTotalRounds(): ?int
            {
                return null;
            }

            #[Override]
            public function getLegs(): ?int
            {
                return null;
            }

            #[Override]
            public function getRoundsPerLeg(): ?int
            {
                return null;
            }

            #[Override]
            public function getExpectedEventCount(): ?int
            {
                return null;
            }

            #[Override]
            public function getExpectedMeetings(Participant $a, Participant $b): int
            {
                return 1;
            }

            #[Override]
            public function validateIntegrity(MissionGaming\Tactician\DTO\Schedule $schedule): array
            {
                return [];
            }
        };

        $report = $this->diagnostics->analyzeSchedulingFailure(
            $this->participants,
            $this->constraints,
            [new Event([$this->alice, $this->bob], new Round(1))],
            $plan
        );

        expect($report->getMissingPairings())->toBe([
            'Alice vs Carol (Leg 1)',
            'Bob vs Carol (Leg 1)',
        ]);
    });

    describe('constraint attribution', function (): void {
        it('names the constraint blocking a pairing everywhere', function (): void {
            $noDerby = ConstraintSet::create()->custom(static function (Event $event): bool {
                $ids = array_map(fn ($p) => $p->getId(), $event->getParticipants());
                sort($ids);

                return implode('|', $ids) !== 'p1|p2';
            }, 'Derby Ban')->build();

            $report = $this->diagnostics->analyzeSchedulingFailure(
                $this->participants,
                $noDerby,
                [new Event([$this->alice, $this->carol], new Round(1))],
                new RoundRobinPlan($this->participants, 1)
            );

            expect($report->getImpossiblePairings())->toBe([
                'Alice vs Bob cannot join the generated schedule in any round (blocked by: Derby Ban)',
            ]);
            expect($report->getConstraintViolations())->toBe([
                'Derby Ban rejects Alice vs Bob in 3 of 3 rounds',
            ]);
        });

        it('attributes partial blocking and probes both orientations', function (): void {
            // Alice may only host: [bob, alice] orientations are rejected,
            // but every round still allows the pairing the other way round
            $aliceHosts = ConstraintSet::create()->custom(static function (Event $event): bool {
                $ids = array_map(fn ($p) => $p->getId(), $event->getParticipants());

                return !in_array('p1', $ids, true) || $event->getParticipants()[0]->getId() === 'p1';
            }, 'Alice Hosts')->build();

            $report = $this->diagnostics->analyzeSchedulingFailure(
                $this->participants,
                $aliceHosts,
                [],
                new RoundRobinPlan($this->participants, 1)
            );

            // No pairing is impossible and no constraint is charged with
            // any round, because one orientation always passes
            expect($report->getImpossiblePairings())->toBe([]);
            expect($report->getConstraintViolations())->toBe([]);
        });

        it('blames a combination when no single constraint rejects everywhere', function (): void {
            $evenRounds = ConstraintSet::create()
                ->custom(static function (Event $event): bool {
                    $ids = array_map(fn ($p) => $p->getId(), $event->getParticipants());
                    sort($ids);
                    if (implode('|', $ids) !== 'p1|p2') {
                        return true;
                    }

                    return $event->getRound()?->getNumber() % 2 === 0;
                }, 'Even Rounds Only')
                ->custom(static function (Event $event): bool {
                    $ids = array_map(fn ($p) => $p->getId(), $event->getParticipants());
                    sort($ids);
                    if (implode('|', $ids) !== 'p1|p2') {
                        return true;
                    }

                    return $event->getRound()?->getNumber() % 2 === 1;
                }, 'Odd Rounds Only')
                ->build();

            $report = $this->diagnostics->analyzeSchedulingFailure(
                $this->participants,
                $evenRounds,
                [],
                new RoundRobinPlan($this->participants, 1)
            );

            expect($report->getImpossiblePairings())->toBe([
                'Alice vs Bob cannot join the generated schedule in any round (blocked by: a combination of constraints)',
            ]);
        });

        it('reports structural conflicts when allowed rounds are full', function (): void {
            $field = [...$this->participants, new Participant('p4', 'Dave')];

            // Alice vs Bob only in round 1, but round 1 is already full
            $placement = ConstraintSet::create()->custom(static function (Event $event): bool {
                $ids = array_map(fn ($p) => $p->getId(), $event->getParticipants());
                sort($ids);

                return implode('|', $ids) !== 'p1|p2' || $event->getRound()?->getNumber() === 1;
            }, 'Round One Derby')->build();

            $dave = $field[3];
            $report = $this->diagnostics->analyzeSchedulingFailure(
                $field,
                $placement,
                [
                    new Event([$this->alice, $this->carol], new Round(1)),
                    new Event([$this->bob, $dave], new Round(1)),
                ],
                new RoundRobinPlan($field, 1)
            );

            expect($report->getImpossiblePairings())->toBe([]);
            $structural = array_filter(
                $report->getSuggestions(),
                fn (string $s) => str_contains($s, 'structural')
            );
            expect(array_values($structural))->toBe([
                'Alice vs Bob is only allowed in rounds already at capacity (rounds 1) - the conflict is structural, not any single constraint',
            ]);
        });

        it('yields no attribution without constraints or missing pairings', function (): void {
            $report = $this->diagnostics->analyzeSchedulingFailure(
                $this->participants,
                $this->constraints, // empty set
                [],
                new RoundRobinPlan($this->participants, 1)
            );

            expect($report->getImpossiblePairings())->toBe([]);
            expect($report->getConstraintViolations())->toBe([]);
        });
    });

    // Regression: the probe must evaluate each candidate round with the
    // leg it belongs to. NoRepeatPairings checks the current leg's
    // events by default, so probing leg-2 rounds with a leg-1 context
    // falsely branded pairs that met in leg 1 as blocked everywhere
    it('probes candidate rounds with their own leg context', function (): void {
        $dave = new Participant('p4', 'Dave');
        $field = [...$this->participants, $dave];
        $plan = new RoundRobinPlan($field, 2); // 6 rounds, 3 per leg

        // A complete first leg; leg 2 is entirely missing
        $legOne = [
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->carol, $dave], new Round(1)),
            new Event([$this->alice, $this->carol], new Round(2)),
            new Event([$this->bob, $dave], new Round(2)),
            new Event([$this->alice, $dave], new Round(3)),
            new Event([$this->bob, $this->carol], new Round(3)),
        ];

        $report = $this->diagnostics->analyzeSchedulingFailure(
            $field,
            ConstraintSet::create()->noRepeatPairings()->build(),
            $legOne,
            $plan
        );

        // Every pair met in leg 1, so leg-1 rounds are rightly rejected -
        // but leg-2 rounds are open, so nothing is impossible and the
        // constraint is only charged with the three leg-1 rounds
        expect($report->getImpossiblePairings())->toBe([]);
        foreach ($report->getConstraintViolations() as $attribution) {
            expect($attribution)->toContain('in 3 of 6 rounds');
        }
    });
});
