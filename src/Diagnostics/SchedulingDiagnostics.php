<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Diagnostics;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Stage\PairKey;
use MissionGaming\Tactician\Stage\PairwisePlan;
use MissionGaming\Tactician\Stage\StagePlan;

/**
 * Comprehensive diagnostics for scheduling failures.
 *
 * This class analyzes scheduling failures and provides detailed reports
 * with actionable suggestions for resolving tournament configuration
 * issues. All shape facts (expected events, legs, pairwise meetings) are
 * read from the stage plan rather than recomputed, so the analysis is
 * correct for whatever format the plan describes.
 *
 * @internal Not public API: the scheduler runs it when generation fails, and
 *           the exception it throws carries the resulting
 *           {@see DiagnosticReport}.
 */
class SchedulingDiagnostics
{
    /**
     * Analyze a scheduling failure and provide comprehensive diagnostics.
     *
     * @param array<Participant> $participants Tournament participants
     * @param ConstraintSet $constraints Tournament constraints
     * @param array<Event> $partialEvents Events generated before failure
     * @param StagePlan $plan The plan for the stage that failed to generate
     * @param array<string, mixed> $context Additional context about the failure
     */
    public function analyzeSchedulingFailure(
        array $participants,
        ConstraintSet $constraints,
        array $partialEvents,
        StagePlan $plan,
        array $context = []
    ): DiagnosticReport {
        $participantCount = count($participants);
        $eventsGenerated = count($partialEvents);
        $expectedEvents = $plan->getExpectedEventCount() ?? 0;

        $missingEvents = max(0, $expectedEvents - $eventsGenerated);
        $missing = $this->identifyMissingPairings($participants, $partialEvents, $plan);
        $attribution = $this->attributeMissingPairings($participants, $constraints, $partialEvents, $plan);
        $suggestions = [
            ...$this->generateSuggestions($participants, $partialEvents, $plan),
            ...$attribution['structural'],
        ];

        return new DiagnosticReport(
            participantCount: $participantCount,
            expectedEvents: $expectedEvents,
            generatedEvents: $eventsGenerated,
            missingEvents: $missingEvents,
            missingPairings: $missing['lines'],
            constraintViolations: $attribution['attribution'],
            impossiblePairings: $attribution['impossible'],
            suggestions: $suggestions,
            analysisContext: $context,
            missingPairingDetails: $missing['details'],
            constraintRejections: $attribution['rejections'],
            blockedPairings: $attribution['blocked'],
            pairingsAtCapacity: $attribution['atCapacity']
        );
    }

    /**
     * Identify constraint conflicts before scheduling begins.
     *
     * @deprecated since 0.2.2, removed in 1.0.0. Nothing in the library calls
     *             it, and it never reads the constraints it is given. There
     *             is no replacement.
     *
     * @param array<Participant> $participants
     * @return array<string>
     */
    #[\Deprecated(message: 'there is no replacement', since: '0.2.2')]
    public function identifyConstraintConflicts(
        array $participants,
        ConstraintSet $constraints,
        StagePlan $plan
    ): array {
        $conflicts = [];
        $participantCount = count($participants);
        $expectedEvents = $plan->getExpectedEventCount() ?? 0;

        // Check if constraints make scheduling mathematically impossible
        if ($expectedEvents === 0 && $plan->getExpectedEventCount() !== null) {
            $conflicts[] = 'Insufficient participants for tournament generation';
        }

        // Check for constraint conflicts that would prevent complete schedules
        if (($plan->getLegs() ?? 1) > 1 && $participantCount < 4) {
            $conflicts[] = 'Multi-leg tournaments require at least 4 participants for meaningful scheduling';
        }

        return $conflicts;
    }

    /**
     * Suggest constraint adjustments to resolve scheduling issues.
     *
     * @deprecated since 0.2.2, removed in 1.0.0. Nothing in the library calls
     *             it. There is no replacement: the suggestions of an
     *             analysis are `DiagnosticReport::getSuggestions()`.
     *
     * @return array<string>
     */
    #[\Deprecated(message: 'there is no replacement; read DiagnosticReport::getSuggestions() for the suggestions of an analysis', since: '0.2.2')]
    public function suggestConstraintAdjustments(DiagnosticReport $report): array
    {
        $suggestions = [];

        if ($report->getMissingEvents() > 0) {
            $suggestions[] = 'Consider relaxing constraints that may be preventing event generation';
        }

        if ($report->getImpossiblePairings() !== []) {
            $suggestions[] = 'Some participant pairings cannot be satisfied with current constraints';
        }

        if ($report->getConstraintViolations() !== []) {
            $suggestions[] = 'Review constraint configuration for potential conflicts';
        }

        // Add specific suggestions based on context
        $context = $report->getAnalysisContext();
        if (isset($context['leg']) && $context['leg'] > 1) {
            $suggestions[] = 'Multi-leg constraint validation may require different strategy';
        }

        return $suggestions;
    }

    /**
     * Identify which participant pairings are missing from generated events.
     *
     * Only pairwise plans (round-robin family) guarantee meeting counts up
     * front, so other formats yield no missing-pairing analysis. Meetings
     * are counted per unordered pairing (so reversed role orders still
     * match) and attributed to legs via their round numbers, so a duplicate
     * meeting in one leg cannot mask a missing meeting in another. Events
     * without a round count toward leg 1.
     *
     * @param array<Participant> $participants
     * @param array<Event> $events
     * @return array{lines: list<string>, details: list<MissingPairing>} The same findings as
     *                                                                   lines of text and as
     *                                                                   typed data
     */
    private function identifyMissingPairings(array $participants, array $events, StagePlan $plan): array
    {
        $none = ['lines' => [], 'details' => []];
        if (!$plan instanceof PairwisePlan) {
            return $none;
        }

        $participants = array_values($participants);
        $participantCount = count($participants);
        if ($participantCount < 2) {
            return $none;
        }

        $legs = $plan->getLegs() ?? 1;
        $roundsPerLeg = $plan->getRoundsPerLeg() ?? $plan->getTotalRounds() ?? 1;
        if ($roundsPerLeg < 1) {
            $roundsPerLeg = 1;
        }

        /** @var array<string, array<int, int>> $legMeetingCounts */
        $legMeetingCounts = [];
        foreach ($events as $event) {
            $eventParticipants = $event->getParticipants();
            if (count($eventParticipants) !== 2) {
                continue;
            }

            $round = $event->getRound()?->getNumber();
            $leg = $round === null ? 1 : min($legs, intdiv($round - 1, $roundsPerLeg) + 1);

            $key = PairKey::of($eventParticipants[0]->getId(), $eventParticipants[1]->getId());
            $legMeetingCounts[$key][$leg] = ($legMeetingCounts[$key][$leg] ?? 0) + 1;
        }

        $missingPairings = [];
        $details = [];
        for ($i = 0; $i < $participantCount; ++$i) {
            for ($j = $i + 1; $j < $participantCount; ++$j) {
                if ($plan->getExpectedMeetings($participants[$i], $participants[$j]) === 0) {
                    continue;
                }

                $key = PairKey::of($participants[$i]->getId(), $participants[$j]->getId());

                for ($leg = 1; $leg <= $legs; ++$leg) {
                    if (($legMeetingCounts[$key][$leg] ?? 0) === 0) {
                        $missingPairings[] = $participants[$i]->getLabel() . ' vs ' . $participants[$j]->getLabel()
                            . " (Leg {$leg})";
                        $details[] = new MissingPairing($participants[$i]->getId(), $participants[$j]->getId(), $leg);
                    }
                }
            }
        }

        return ['lines' => $missingPairings, 'details' => $details];
    }

    /**
     * Generate actionable suggestions for resolving scheduling issues.
     *
     * @param array<Participant> $participants
     * @param array<Event> $partialEvents
     * @return array<string>
     */
    private function generateSuggestions(
        array $participants,
        array $partialEvents,
        StagePlan $plan
    ): array {
        $suggestions = [];

        $participantCount = count($participants);
        $eventsGenerated = count($partialEvents);
        $expectedEvents = $plan->getExpectedEventCount() ?? 0;

        if ($eventsGenerated === 0) {
            $suggestions[] = 'No events were generated - check participant count and constraint configuration';
        } elseif ($eventsGenerated < $expectedEvents) {
            $percentage = (int) (($eventsGenerated / $expectedEvents) * 100);
            $suggestions[] = "Only {$percentage}% of expected events were generated - constraints may be too restrictive";
        }

        if (($plan->getLegs() ?? 1) > 1 && $participantCount % 2 !== 0) {
            $suggestions[] = 'Odd participant count in multi-leg tournaments may cause scheduling challenges';
        }

        return $suggestions;
    }

    /**
     * Attribute missing pairings to the constraints that block them, by
     * probing rather than guessing: a constraint is expected to answer from
     * the event and the context alone (the contract on ConstraintInterface;
     * nothing enforces it), so each missing pairing is tested against
     * each constraint in every candidate round and both orientations,
     * against the schedule that was actually generated.
     *
     * Three findings come out:
     * - impossible: pairings no round and orientation will accept, with
     *   the constraints rejecting everywhere named as culprits;
     * - attribution: per constraint, which pairings it rejects and in
     *   how many of the candidate rounds (a constraint is only charged
     *   with a round it rejects in both orientations);
     * - structural: pairings whose allowed rounds are all already at
     *   capacity - blocked by arithmetic, not by any constraint.
     *
     * Probing answers "could this pairing join what was built?"; it does
     * not claim global unsatisfiability.
     *
     * @param array<Participant> $participants
     * @param array<Event> $events
     * Each finding comes out twice, as a line of text and as typed data:
     * blocked beside impossible, rejections beside attribution, atCapacity
     * beside structural.
     *
     * @return array{impossible: list<string>, attribution: list<string>, structural: list<string>, blocked: list<BlockedPairing>, rejections: list<ConstraintRejection>, atCapacity: list<PairingAtCapacity>}
     */
    private function attributeMissingPairings(
        array $participants,
        ConstraintSet $constraints,
        array $events,
        StagePlan $plan
    ): array {
        $empty = ['impossible' => [], 'attribution' => [], 'structural' => [], 'blocked' => [], 'rejections' => [], 'atCapacity' => []];
        if (!$plan instanceof PairwisePlan || $constraints->count() === 0) {
            return $empty;
        }

        $totalRounds = $plan->getTotalRounds() ?? 0;
        if ($totalRounds < 1) {
            return $empty;
        }

        $missingPairs = $this->findMissingPairs($participants, $events, $plan);
        if ($missingPairs === []) {
            return $empty;
        }

        // Leg-sensitive constraints (NoRepeatPairings checks the current
        // leg's events by default) must be probed with the leg the
        // candidate round belongs to, or multi-leg plans mis-attribute:
        // a pair that met in leg 1 is perfectly schedulable in leg 2.
        $legs = $plan->getLegs() ?? 1;
        $roundsPerLeg = $plan->getRoundsPerLeg() ?? $plan->getTotalRounds() ?? 1;
        if ($roundsPerLeg < 1) {
            $roundsPerLeg = 1;
        }
        /** @var array<int, SchedulingContext> $contextsByLeg */
        $contextsByLeg = [];
        for ($leg = 1; $leg <= $legs; ++$leg) {
            $contextsByLeg[$leg] = new SchedulingContext($participants, $plan, $events, $leg);
        }

        $roundCapacity = intdiv(count($participants), 2);
        $eventsPerRound = [];
        foreach ($events as $event) {
            $round = $event->getRound()?->getNumber();
            if ($round !== null) {
                $eventsPerRound[$round] = ($eventsPerRound[$round] ?? 0) + 1;
            }
        }

        $impossible = [];
        $structural = [];
        $blocked = [];
        $atCapacity = [];
        /** @var array<string, list<string>> $rejectionsByConstraint constraint name => pairing summaries */
        $rejectionsByConstraint = [];
        /** @var array<string, list<ConstraintRejection>> $rejectionDetails constraint name => rejections */
        $rejectionDetails = [];

        foreach ($missingPairs as [$a, $b]) {
            $pairLabel = $a->getLabel() . ' vs ' . $b->getLabel();
            $allowedRounds = [];
            /** @var array<string, int> $roundsRejectedBy rounds each constraint rejects in both orientations */
            $roundsRejectedBy = [];

            for ($round = 1; $round <= $totalRounds; ++$round) {
                $context = $contextsByLeg[min($legs, intdiv($round - 1, $roundsPerLeg) + 1)];
                $orientations = [
                    new Event([$a, $b], new Round($round)),
                    new Event([$b, $a], new Round($round)),
                ];

                $roundAllowed = false;
                foreach ($orientations as $candidate) {
                    if ($constraints->isSatisfied($candidate, $context)) {
                        $roundAllowed = true;
                        break;
                    }
                }
                if ($roundAllowed) {
                    $allowedRounds[] = $round;
                }

                foreach ($constraints->getConstraints() as $constraint) {
                    $rejectsBoth = array_all($orientations, fn($candidate) => !$constraint->isSatisfied($candidate, $context));
                    if ($rejectsBoth) {
                        $roundsRejectedBy[$constraint->getName()] = ($roundsRejectedBy[$constraint->getName()] ?? 0) + 1;
                    }
                }
            }

            foreach ($roundsRejectedBy as $constraintName => $rejectedRounds) {
                $constraintName = (string) $constraintName;
                $rejectionsByConstraint[$constraintName][] = "{$pairLabel} in {$rejectedRounds} of {$totalRounds} rounds";
                $rejectionDetails[$constraintName][] = new ConstraintRejection(
                    $constraintName,
                    $a->getId(),
                    $b->getId(),
                    $rejectedRounds,
                    $totalRounds
                );
            }

            if ($allowedRounds === []) {
                $culprits = array_map(strval(...), array_keys(array_filter(
                    $roundsRejectedBy,
                    fn(int $rejectedRounds) => $rejectedRounds === $totalRounds
                )));
                $blockedBy = $culprits === [] ? 'a combination of constraints' : implode(', ', $culprits);
                $impossible[] = "{$pairLabel} cannot join the generated schedule in any round (blocked by: {$blockedBy})";
                $blocked[] = new BlockedPairing($a->getId(), $b->getId(), $culprits);
                continue;
            }

            $openRounds = array_filter(
                $allowedRounds,
                fn(int $round) => ($eventsPerRound[$round] ?? 0) < $roundCapacity
            );
            if ($openRounds === []) {
                $structural[] = "{$pairLabel} is only allowed in rounds already at capacity (rounds "
                    . implode(', ', $allowedRounds) . ') - the conflict is structural, not any single constraint';
                $atCapacity[] = new PairingAtCapacity($a->getId(), $b->getId(), $allowedRounds);
            }
        }

        $attribution = [];
        $rejections = [];
        foreach ($rejectionsByConstraint as $constraintName => $pairSummaries) {
            $attribution[] = "{$constraintName} rejects " . implode('; ', $pairSummaries);
            array_push($rejections, ...$rejectionDetails[$constraintName]);
        }

        return [
            'impossible' => $impossible,
            'attribution' => $attribution,
            'structural' => $structural,
            'blocked' => $blocked,
            'rejections' => $rejections,
            'atCapacity' => $atCapacity,
        ];
    }

    /**
     * The unordered participant pairs missing at least one expected
     * meeting, as pairs rather than display strings.
     *
     * @param array<Participant> $participants
     * @param array<Event> $events
     * @return array<array{0: Participant, 1: Participant}>
     */
    private function findMissingPairs(array $participants, array $events, PairwisePlan $plan): array
    {
        $met = [];
        foreach ($events as $event) {
            $eventParticipants = $event->getParticipants();
            if (count($eventParticipants) !== 2) {
                continue;
            }
            $key = PairKey::of($eventParticipants[0]->getId(), $eventParticipants[1]->getId());
            $met[$key] = ($met[$key] ?? 0) + 1;
        }

        $missing = [];
        $participantCount = count($participants);
        for ($i = 0; $i < $participantCount; ++$i) {
            for ($j = $i + 1; $j < $participantCount; ++$j) {
                $expected = $plan->getExpectedMeetings($participants[$i], $participants[$j]);
                if ($expected === 0) {
                    continue;
                }

                $key = PairKey::of($participants[$i]->getId(), $participants[$j]->getId());
                if (($met[$key] ?? 0) < $expected) {
                    $missing[] = [$participants[$i], $participants[$j]];
                }
            }
        }

        return $missing;
    }
}
