<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\Stage\RoundRobinPlan;
use MissionGaming\Tactician\Validation\ConstraintViolation;
use MissionGaming\Tactician\Validation\ValidatesScheduleCompleteness;
use Override;
use Random\Randomizer;

class RoundRobinScheduler implements SchedulerInterface
{
    use ValidatesScheduleCompleteness;

    /**
     * Upper bound on rotated-ordering attempts when constraints reject the
     * pairings implied by a given participant order.
     */
    private const MAX_GENERATION_ATTEMPTS = 25;

    /** @var array<int, string> Participant IDs receiving a bye, keyed by round number */
    private array $roundByes = [];

    public function __construct(
        private ?ConstraintSet $constraints = null,
        private ?Randomizer $randomizer = null
    ) {
        $this->initializeValidation();
    }

    /**
     * Generate a round-robin schedule for the given participants with integrated multi-leg support.
     *
     * @param array<Participant> $participants Tournament participants
     * @param SchedulerOptions|null $options RoundRobinOptions, or null for a single mirrored leg
     *
     * @throws InvalidConfigurationException When configuration is invalid
     * @throws IncompleteScheduleException When constraints prevent complete schedule generation
     */
    #[Override]
    public function schedule(
        array $participants,
        ?SchedulerOptions $options = null
    ): Schedule {
        $options = $this->resolveOptions($options);
        $this->validateInputs($participants);

        $strategy = $options->strategy;

        // Build the plan first: the strategy contributes its facts, an
        // unsatisfiable configuration fails here with diagnostics, and
        // generation, validation, and diagnostics all read shape facts
        // from the resulting plan.
        $plan = $this->buildPlan($participants, $options->legs, $strategy);

        // Generate complete schedule using integrated approach, retrying with
        // rotated participant orderings when constraints reject the pairings
        // implied by a particular circle-method order. When the rotations are
        // exhausted and backtracking is enabled, search the decompositions the
        // circle method cannot reach before failing loudly.
        try {
            $allEvents = $this->generateScheduleWithRetries($participants, $strategy, $plan);
        } catch (IncompleteScheduleException $greedyFailure) {
            if (!$options->backtracking) {
                throw $greedyFailure;
            }
            $allEvents = $this->generateWithBacktracking($participants, $strategy, $plan, $greedyFailure);
        }
        ksort($this->roundByes);

        $schedule = new Schedule($allEvents, [
            'algorithm' => $plan->getAlgorithm(),
            'participant_count' => count($participants),
            'legs' => $plan->getLegs(),
            'rounds_per_leg' => $plan->getRoundsPerLeg(),
            'total_rounds' => $plan->getTotalRounds(),
            'expected_event_count' => $plan->getExpectedEventCount(),
            'byes' => $this->roundByes,
        ]);

        // Validate schedule completeness with all-or-nothing guarantee
        $this->validateGeneratedSchedule($schedule, $participants, $plan);

        return $schedule;
    }

    /**
     * Build the round-robin stage plan for the given configuration,
     * including the configured strategy's contribution facts.
     *
     * @param array<Participant> $participants
     * @param SchedulerOptions|null $options RoundRobinOptions, or null for a single mirrored leg
     * @throws InvalidConfigurationException When the configuration is unsatisfiable
     */
    #[Override]
    public function getPlan(
        array $participants,
        ?SchedulerOptions $options = null
    ): RoundRobinPlan {
        $options = $this->resolveOptions($options);
        $this->validateInputs($participants);

        return $this->buildPlan($participants, $options->legs, $options->strategy);
    }

    /**
     * Default and type-check the options: this scheduler accepts only
     * RoundRobinOptions.
     *
     * @throws InvalidConfigurationException When another algorithm's options are passed
     */
    private function resolveOptions(?SchedulerOptions $options): RoundRobinOptions
    {
        if ($options === null) {
            return new RoundRobinOptions();
        }

        if (!$options instanceof RoundRobinOptions) {
            throw new InvalidConfigurationException(
                'Round-robin scheduling requires RoundRobinOptions',
                ['options' => $options::class]
            );
        }

        return $options;
    }

    /**
     * Build the plan from the leg strategy's contribution, failing loudly
     * when the strategy reports the configuration unsatisfiable.
     *
     * @param array<Participant> $participants
     * @throws InvalidConfigurationException
     */
    private function buildPlan(
        array $participants,
        int $legs,
        LegStrategyInterface $strategy
    ): RoundRobinPlan {
        $contribution = $strategy->planLegs(
            $participants,
            $legs,
            $this->constraints ?? ConstraintSet::create()->build()
        );

        if ($contribution->unsatisfiableReasons !== []) {
            throw new InvalidConfigurationException(
                'Leg strategy cannot satisfy the requested configuration: '
                    . implode(' | ', $contribution->unsatisfiableReasons),
                [
                    'strategy' => $strategy::class,
                    'unsatisfiable_reasons' => $contribution->unsatisfiableReasons,
                    'warnings' => $contribution->warnings,
                ]
            );
        }

        return new RoundRobinPlan(
            $participants,
            $legs,
            $contribution->rolesMirrorAcrossLegs,
            $contribution->requiresRandomization,
            $contribution->warnings
        );
    }

    /**
     * Validate the participant list (leg validation lives on the options).
     *
     * @param array<Participant> $participants
     * @throws InvalidConfigurationException
     */
    private function validateInputs(array $participants): void
    {
        if (count($participants) < 2) {
            throw new InvalidConfigurationException(
                'Round-robin scheduling requires at least 2 participants',
                ['participant_count' => count($participants), 'minimum_required' => 2]
            );
        }

        // Check for duplicate participant IDs
        $ids = array_map(fn (Participant $p) => $p->getId(), $participants);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidConfigurationException(
                'All participants must have unique IDs',
                ['participant_count' => count($participants), 'unique_ids' => count(array_unique($ids))]
            );
        }
    }

    /**
     * Generate the schedule, retrying with rotated participant orderings when
     * constraints reject the pairings implied by a particular order.
     *
     * The circle method fixes which pairings share a round purely by list
     * order, so a constraint can reject one ordering while another ordering
     * yields a complete schedule (e.g. seed protection failing only because
     * two seeds happen to meet in an early round). Attempts are deterministic
     * rotations of the input order and bounded, so genuinely unsatisfiable
     * configurations still fail fast with the diagnostics of the last attempt.
     *
     * @param array<Participant> $participants
     * @return array<Event>
     * @throws IncompleteScheduleException When no ordering produces a complete schedule
     */
    private function generateScheduleWithRetries(
        array $participants,
        LegStrategyInterface $strategy,
        RoundRobinPlan $plan
    ): array {
        $participants = array_values($participants);
        $maxAttempts = $this->constraints === null
            ? 1
            : min(count($participants), self::MAX_GENERATION_ATTEMPTS);

        for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
            $ordered = $attempt === 0
                ? $participants
                : [...array_slice($participants, $attempt), ...array_slice($participants, 0, $attempt)];

            // Reset diagnostics so a successful retry does not report stale
            // violations, and a failure reports only the final attempt.
            $this->clearViolations();

            try {
                return $this->generateIntegratedSchedule($ordered, $strategy, $plan);
            } catch (IncompleteScheduleException $exception) {
                if ($attempt === $maxAttempts - 1) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Schedule generation loop must return or throw');
    }

    /**
     * Search for a schedule after the greedy rotations failed: leg 1 via
     * backtracking over round decompositions, later legs derived from
     * leg 1's actual rounds through the leg strategy. The search does not
     * cross leg boundaries (docs/design/backtracking-generation.md), so a
     * later leg rejected by constraints fails loudly.
     *
     * @param array<Participant> $participants
     * @return array<Event>
     * @throws IncompleteScheduleException
     */
    private function generateWithBacktracking(
        array $participants,
        LegStrategyInterface $strategy,
        RoundRobinPlan $plan,
        IncompleteScheduleException $greedyFailure
    ): array {
        // The greedy final attempt's violations stay in the collector: if
        // the search also fails, callers still get constraint-level
        // diagnostics alongside the search-level message (the greedy
        // failure itself rides along as the previous exception).
        $this->roundByes = [];

        $field = array_values($participants);
        $field = $this->randomizer?->shuffleArray($field) ?? $field;

        $generator = new BacktrackingRoundRobinGenerator($this->constraints);
        $legOneEvents = $generator->generateFirstLeg($field, $plan);

        if ($legOneEvents === null) {
            throw new IncompleteScheduleException(
                $plan->getExpectedEventCount(),
                0,
                $this->violationCollector,
                $plan,
                $participants,
                $generator->wasBudgetExhausted()
                    ? 'Backtracking search exhausted its step budget ('
                        . BacktrackingRoundRobinGenerator::STEP_BUDGET
                        . ' pairing attempts) without finding a complete schedule. The configuration may be unsatisfiable or may need a different participant order.'
                    : 'Backtracking search exhausted the search space: no round decomposition of the first leg satisfies the constraints, so the configuration is unsatisfiable.',
                0,
                $greedyFailure
            );
        }

        $this->roundByes = $generator->getRoundByes();
        $allEvents = $legOneEvents;
        $roundsPerLeg = $plan->getRoundsPerLeg();

        for ($leg = 2; $leg <= $plan->getLegs(); ++$leg) {
            $legEvents = [];

            foreach ($legOneEvents as $legOneEvent) {
                $legRound = $legOneEvent->getRound()?->getNumber() ?? 0;
                $globalRound = (($leg - 1) * $roundsPerLeg) + $legRound;

                $context = new SchedulingContext($field, $plan, [...$allEvents, ...$legEvents], $leg);
                $event = $strategy->generateEventForLeg(
                    $legOneEvent->getParticipants(),
                    $leg,
                    $globalRound,
                    $context
                );

                if ($event !== null && !$this->shouldAddEventWithFullConstraints($event, $context)) {
                    $this->recordConstraintViolation($event, $context);
                    $event = null;
                }

                if ($event === null) {
                    throw new IncompleteScheduleException(
                        $plan->getExpectedEventCount(),
                        count($allEvents) + count($legEvents),
                        $this->violationCollector,
                        $plan,
                        $participants,
                        "Backtracking found a first leg, but constraints reject its leg {$leg} derivation and the search does not cross leg boundaries. Relax the constraints on later legs or change the leg strategy.",
                        0,
                        $greedyFailure
                    );
                }

                $legEvents[] = $event;
            }

            foreach ($generator->getRoundByes() as $legRound => $participantId) {
                $this->roundByes[(($leg - 1) * $roundsPerLeg) + $legRound] = $participantId;
            }

            $allEvents = [...$allEvents, ...$legEvents];
        }

        // The search succeeded: the greedy attempt's violations would be
        // stale diagnostics on a complete schedule.
        $this->clearViolations();

        return $allEvents;
    }

    /**
     * Generate complete schedule using integrated multi-leg approach.
     *
     * @param array<Participant> $participants
     * @return array<Event>
     * @throws IncompleteScheduleException
     */
    private function generateIntegratedSchedule(
        array $participants,
        LegStrategyInterface $strategy,
        RoundRobinPlan $plan
    ): array {
        $allEvents = [];
        $this->roundByes = [];
        $legs = $plan->getLegs();
        $context = new SchedulingContext($participants, $plan, [], 1);

        $expectedEventsPerLeg = $plan->getEventsPerLeg();

        for ($leg = 1; $leg <= $legs; ++$leg) {
            $legEvents = $this->generateLegWithFullContext($participants, $leg, $strategy, $plan, $context);

            // Check if we generated the expected number of events for this leg
            if (count($legEvents) < $expectedEventsPerLeg) {
                throw new IncompleteScheduleException(
                    $plan->getExpectedEventCount(),
                    count($allEvents) + count($legEvents),
                    $this->violationCollector,
                    $plan,
                    $participants,
                    "Failed to generate complete schedule for leg {$leg}. Generated " . count($legEvents) . " events, expected {$expectedEventsPerLeg}. Constraints may be preventing complete schedule generation."
                );
            }

            $allEvents = [...$allEvents, ...$legEvents];
            $context = new SchedulingContext($participants, $plan, $allEvents, $leg + 1);
        }

        return $allEvents;
    }

    /**
     * Generate events for a specific leg with full tournament context.
     *
     * @param array<Participant> $participants
     * @return array<Event>
     * @throws IncompleteScheduleException
     */
    private function generateLegWithFullContext(
        array $participants,
        int $leg,
        LegStrategyInterface $strategy,
        RoundRobinPlan $plan,
        SchedulingContext $context
    ): array {
        if ($leg === 1) {
            // For the first leg, use traditional round-robin generation but validate completeness
            $legEvents = $this->generateRoundRobinEvents($participants, $context);

            // Validate that we got the expected number of events for the first leg
            $expectedEventsPerLeg = $plan->getEventsPerLeg();
            if (count($legEvents) < $expectedEventsPerLeg) {
                throw new IncompleteScheduleException(
                    $plan->getExpectedEventCount(),
                    count($legEvents), // Events generated for first leg only
                    $this->violationCollector,
                    $plan,
                    $participants,
                    "Failed to generate complete schedule for leg {$leg}. Generated " . count($legEvents) . " events, expected {$expectedEventsPerLeg}. Constraints may be preventing complete schedule generation."
                );
            }

            return $legEvents;
        }

        // For subsequent legs, use the strategy to generate events
        $legEvents = [];
        $roundsPerLeg = $plan->getRoundsPerLeg();
        $roundOffset = ($leg - 1) * $roundsPerLeg;

        // Maintain the circle-method ordering across rounds so each round only
        // needs a single rotation instead of re-rotating from scratch.
        $participantList = array_values($participants);
        if (count($participantList) % 2 !== 0) {
            $participantList[] = null; // null represents "bye"
        }

        for ($round = 1; $round <= $roundsPerLeg; ++$round) {
            $globalRound = $round + $roundOffset;

            $this->recordByeForRound($participantList, $globalRound);

            // Get all participants that need to play in this round
            $participantPairs = $this->getPairsFromParticipantList($participantList, $round);

            $roundEvents = [];
            foreach ($participantPairs as $pair) {
                $event = $strategy->generateEventForLeg($pair, $leg, $globalRound, $context);

                if ($event !== null) {
                    // Check constraints with full tournament context (all previous events)
                    if ($this->shouldAddEventWithFullConstraints($event, $context)) {
                        $legEvents[] = $event;
                        $roundEvents[] = $event;
                    } else {
                        // If constraint fails, record violation for diagnostics
                        $this->recordConstraintViolation($event, $context);
                    }
                }
            }

            // Update context once per round (matching first-leg behaviour) rather
            // than per event, which would copy the full event list every event.
            if ($roundEvents !== []) {
                $context = $context->withEvents($roundEvents);
            }

            // Rotate participants for next round (keep first participant fixed)
            $this->rotateParticipants($participantList);
        }

        return $legEvents;
    }

    /**
     * Record which participant sits out the given round, if the circle
     * ordering contains a "bye" (null) slot.
     *
     * @param array<Participant|null> $participantList Participants in circle order
     */
    private function recordByeForRound(array $participantList, int $round): void
    {
        $byeIndex = array_search(null, $participantList, true);
        if ($byeIndex === false) {
            return;
        }

        // Circle pairing matches seat i with seat (count - 1 - i)
        $sittingOut = $participantList[count($participantList) - 1 - (int) $byeIndex];
        if ($sittingOut !== null) {
            $this->roundByes[$round] = $sittingOut->getId();
        }
    }

    /**
     * Get participant pairs from the current circle-method ordering.
     *
     * Roles alternate with the (leg-local) round parity, matching first-leg
     * generation so leg strategies mirror true roles.
     *
     * @param array<Participant|null> $participantList Participants in circle order, including any "bye" (null)
     * @param int $round Leg-local round number, used for role alternation
     * @return array<array<Participant>>
     */
    private function getPairsFromParticipantList(array $participantList, int $round): array
    {
        $participantCount = count($participantList);
        $pairingsPerRound = intdiv($participantCount, 2);
        $pairs = [];

        for ($pair = 0; $pair < $pairingsPerRound; ++$pair) {
            $participant1 = $participantList[$pair];
            $participant2 = $participantList[$participantCount - 1 - $pair];

            // Skip if one participant is "bye" (null)
            if ($participant1 !== null && $participant2 !== null) {
                $pairs[] = $round % 2 === 0
                    ? [$participant2, $participant1]
                    : [$participant1, $participant2];
            }
        }

        return $pairs;
    }

    /**
     * Generate all round-robin events using circle method.
     *
     * @param array<Participant> $participants
     * @return array<Event>
     */
    private function generateRoundRobinEvents(array $participants, SchedulingContext $baseContext): array
    {
        $participantList = array_values($participants);
        $participantCount = count($participantList);
        $events = [];

        // Handle odd number of participants by adding a "bye"
        $hasBye = $participantCount % 2 !== 0;
        if ($hasBye) {
            $participantList[] = null; // null represents "bye"
            ++$participantCount;
        }

        $rounds = $participantCount - 1;
        $pairingsPerRound = $participantCount / 2;

        // Randomize initial order if randomizer is provided
        if ($this->randomizer !== null) {
            $participantList = $this->shuffleParticipants($participantList);
        }

        // Generate pairings for each round using circle method
        for ($round = 1; $round <= $rounds; ++$round) {
            $context = new SchedulingContext(
                $participants,
                $baseContext->getPlan(),
                $events,
                $baseContext->getCurrentLeg(),
                $baseContext->getParticipantsPerEvent()
            );

            for ($pair = 0; $pair < $pairingsPerRound; ++$pair) {
                $participant1 = $participantList[$pair];
                $participant2 = $participantList[$participantCount - 1 - $pair];

                // Skip if one participant is "bye" (null), recording who sits out
                if ($participant1 === null || $participant2 === null) {
                    $sittingOut = $participant1 ?? $participant2;
                    if ($sittingOut !== null) {
                        $this->roundByes[$round] = $sittingOut->getId();
                    }
                    continue;
                }

                // Alternate every pairing's roles with the round parity;
                // without this the circle method keeps the fixed seat at home
                // all leg and gives rotating players home/away streaks of
                // half the field size (this bounds running imbalance at 3)
                if ($round % 2 === 0) {
                    [$participant1, $participant2] = [$participant2, $participant1];
                }

                $roundObject = new Round($round);
                $event = new Event([$participant1, $participant2], $roundObject);

                // Check constraints if provided
                if ($this->constraints === null) {
                    $events[] = $event;
                } else {
                    if ($this->constraints->isSatisfied($event, $context)) {
                        $events[] = $event;
                    } else {
                        // Record constraint violations for diagnostic reporting
                        foreach ($this->constraints->getConstraints() as $constraint) {
                            if (!$constraint->isSatisfied($event, $context)) {
                                $violation = new ConstraintViolation(
                                    $constraint,
                                    $event,
                                    sprintf(
                                        'Event %s vs %s rejected by constraint',
                                        $participant1->getId(),
                                        $participant2->getId()
                                    ),
                                    [$participant1, $participant2],
                                    $round
                                );
                                $this->recordViolation($violation);
                            }
                        }
                    }
                }
            }

            // Rotate participants for next round (keep first participant fixed)
            $this->rotateParticipants($participantList);
        }

        return $events;
    }

    /**
     * Check if an event should be added based on constraints with full tournament context.
     *
     * @param Event $event The event to validate
     * @param SchedulingContext $context Full tournament context
     *
     * @return bool True if the event should be added
     */
    private function shouldAddEventWithFullConstraints(Event $event, SchedulingContext $context): bool
    {
        if ($this->constraints === null) {
            return true;
        }

        return $this->constraints->isSatisfied($event, $context);
    }

    /**
     * Record a constraint violation for diagnostic purposes.
     *
     * @param Event $event The event that failed constraint validation
     * @param SchedulingContext $context The context when validation failed
     */
    private function recordConstraintViolation(Event $event, SchedulingContext $context): void
    {
        if ($this->constraints === null) {
            return;
        }

        // Record violations for each failing constraint
        foreach ($this->constraints->getConstraints() as $constraint) {
            if (!$constraint->isSatisfied($event, $context)) {
                $participants = $event->getParticipants();
                $violation = new ConstraintViolation(
                    $constraint,
                    $event,
                    sprintf(
                        'Event %s vs %s rejected by constraint in leg %d',
                        $participants[0]->getId(),
                        $participants[1]->getId(),
                        $context->getCurrentLeg()
                    ),
                    $participants,
                    $event->getRound()?->getNumber() ?? 0
                );
                $this->recordViolation($violation);
            }
        }
    }

    /**
     * Shuffle participants using the provided randomizer.
     *
     * @param array<Participant|null> $participants
     * @return array<Participant|null>
     */
    private function shuffleParticipants(array $participants): array
    {
        // Keep the null/"bye" participant at the end if it exists
        $hasBye = end($participants) === null;
        if ($hasBye) {
            array_pop($participants);
        }

        $participants = $this->randomizer?->shuffleArray($participants) ?? $participants;

        if ($hasBye) {
            $participants[] = null;
        }

        return $participants;
    }

    /**
     * Rotate participants for circle method (keep first fixed, rotate others).
     *
     * @param array<Participant|null> $participants
     */
    private function rotateParticipants(array &$participants): void
    {
        // Circle method: fix position 0, rotate positions 1 to n-1
        if (count($participants) <= 2) {
            return;
        }

        $temp = $participants[1];
        for ($i = 1; $i < count($participants) - 1; ++$i) {
            $participants[$i] = $participants[$i + 1];
        }
        $participants[count($participants) - 1] = $temp;
    }
}
