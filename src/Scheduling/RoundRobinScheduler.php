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
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\Validation\ConstraintViolation;
use MissionGaming\Tactician\Validation\ExpectedEventCalculator;
use MissionGaming\Tactician\Validation\RoundRobinEventCalculator;
use MissionGaming\Tactician\Validation\ScheduleValidationContext;
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
     * @param int $participantsPerEvent Number of participants per event
     * @param int $legs Number of legs in the tournament
     * @param LegStrategyInterface|null $strategy Strategy for multi-leg generation
     *
     * @throws InvalidConfigurationException When configuration is invalid
     * @throws IncompleteScheduleException When constraints prevent complete schedule generation
     */
    #[Override]
    public function schedule(
        array $participants,
        int $participantsPerEvent = 2,
        int $legs = 1,
        mixed $strategy = null
    ): Schedule {
        $this->validateInputs($participants, $participantsPerEvent, $legs);

        if ($strategy !== null && !$strategy instanceof LegStrategyInterface) {
            throw new InvalidConfigurationException(
                'Round-robin scheduling options must be a leg strategy',
                ['strategy' => is_object($strategy) ? $strategy::class : gettype($strategy)]
            );
        }

        $strategy ??= new MirroredLegStrategy();

        // Preflight: let the strategy reject configurations it cannot satisfy
        // before any events are generated.
        $satisfiabilityReport = $strategy->canSatisfyConstraints(
            $participants,
            $legs,
            $participantsPerEvent,
            $this->constraints ?? ConstraintSet::create()->build()
        );
        if (!$satisfiabilityReport->canSatisfyConstraints()) {
            throw new InvalidConfigurationException(
                'Leg strategy cannot satisfy the requested configuration: ' . $satisfiabilityReport->getSummary(),
                [
                    'strategy' => $strategy::class,
                    'unsatisfiable_constraints' => $satisfiabilityReport->getUnsatisfiableConstraints(),
                    'conflicting_constraints' => $satisfiabilityReport->getConflictingConstraints(),
                    'suggestions' => $satisfiabilityReport->getSuggestions(),
                ]
            );
        }

        // Generate complete schedule using integrated approach, retrying with
        // rotated participant orderings when constraints reject the pairings
        // implied by a particular circle-method order.
        $allEvents = $this->generateScheduleWithRetries($participants, $participantsPerEvent, $legs, $strategy);

        $roundsPerLeg = $this->calculateRoundsPerLeg($participants);
        $totalRounds = $roundsPerLeg * $legs;

        $schedule = new Schedule($allEvents, [
            'algorithm' => 'round-robin',
            'participant_count' => count($participants),
            'legs' => $legs,
            'rounds_per_leg' => $roundsPerLeg,
            'total_rounds' => $totalRounds,
            'expected_event_count' => $this->getExpectedEventCalculator()->calculateExpectedEvents($participants, $legs),
        ]);

        // Validate schedule completeness with all-or-nothing guarantee
        $this->validateGeneratedSchedule(
            $schedule,
            $participants,
            ScheduleValidationContext::forRoundRobin($legs, $totalRounds, $participantsPerEvent),
            $this->getExpectedEventCalculator()->calculateExpectedEvents($participants, $legs)
        );

        return $schedule;
    }

    /**
     * Validate input parameters for scheduling.
     *
     * @param array<Participant> $participants
     * @throws InvalidConfigurationException
     */
    private function validateInputs(array $participants, int $participantsPerEvent, int $legs): void
    {
        if (count($participants) < 2) {
            throw new InvalidConfigurationException(
                'Round-robin scheduling requires at least 2 participants',
                ['participant_count' => count($participants), 'minimum_required' => 2]
            );
        }

        if ($participantsPerEvent !== 2) {
            throw new InvalidConfigurationException(
                'Round-robin scheduling currently only supports 2 participants per event',
                ['participants_per_event' => $participantsPerEvent, 'supported' => 2]
            );
        }

        if ($legs < 1) {
            throw new InvalidConfigurationException(
                'Legs must be a positive integer',
                ['legs' => $legs, 'minimum_required' => 1]
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
        int $participantsPerEvent,
        int $legs,
        LegStrategyInterface $strategy
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
                return $this->generateIntegratedSchedule($ordered, $participantsPerEvent, $legs, $strategy);
            } catch (IncompleteScheduleException $exception) {
                if ($attempt === $maxAttempts - 1) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Schedule generation loop must return or throw');
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
        int $participantsPerEvent,
        int $legs,
        LegStrategyInterface $strategy
    ): array {
        $allEvents = [];
        $metadata = $this->createSchedulingMetadata($participants, $legs);
        $context = new SchedulingContext($participants, [], 1, $legs, $participantsPerEvent, $metadata);

        // Calculate expected events per leg
        $expectedEventsPerLeg = (int) (count($participants) * (count($participants) - 1) / 2);

        for ($leg = 1; $leg <= $legs; ++$leg) {
            $legEvents = $this->generateLegWithFullContext($participants, $participantsPerEvent, $leg, $strategy, $context);

            // Check if we generated the expected number of events for this leg
            if (count($legEvents) < $expectedEventsPerLeg) {
                throw new IncompleteScheduleException(
                    $expectedEventsPerLeg * $legs, // Total expected events for all legs
                    count($allEvents) + count($legEvents), // Total events generated so far
                    $this->violationCollector,
                    $this->getExpectedEventCalculator(),
                    $participants,
                    ScheduleValidationContext::forRoundRobin(
                        $legs,
                        $this->calculateRoundsPerLeg($participants) * $legs,
                        $participantsPerEvent
                    ),
                    "Failed to generate complete schedule for leg {$leg}. Generated " . count($legEvents) . " events, expected {$expectedEventsPerLeg}. Constraints may be preventing complete schedule generation."
                );
            }

            $allEvents = [...$allEvents, ...$legEvents];
            $context = new SchedulingContext($participants, $allEvents, $leg + 1, $legs, $participantsPerEvent, $metadata);
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
        int $participantsPerEvent,
        int $leg,
        LegStrategyInterface $strategy,
        SchedulingContext $context
    ): array {
        if ($leg === 1) {
            // For the first leg, use traditional round-robin generation but validate completeness
            $legEvents = $this->generateRoundRobinEvents($participants, $context);

            // Validate that we got the expected number of events for the first leg
            $expectedEventsPerLeg = (int) (count($participants) * (count($participants) - 1) / 2);
            if (count($legEvents) < $expectedEventsPerLeg) {
                // Get total legs from context to report correct expected events
                $totalLegs = $context->getTotalLegs();
                throw new IncompleteScheduleException(
                    $expectedEventsPerLeg * $totalLegs, // Total expected events for all legs
                    count($legEvents), // Events generated for first leg only
                    $this->violationCollector,
                    $this->getExpectedEventCalculator(),
                    $participants,
                    ScheduleValidationContext::forRoundRobin(
                        $totalLegs,
                        $this->calculateRoundsPerLeg($participants) * $totalLegs,
                        $participantsPerEvent
                    ),
                    "Failed to generate complete schedule for leg {$leg}. Generated " . count($legEvents) . " events, expected {$expectedEventsPerLeg}. Constraints may be preventing complete schedule generation."
                );
            }

            return $legEvents;
        }

        // For subsequent legs, use the strategy to generate events
        $legEvents = [];
        $roundsPerLeg = $this->calculateRoundsPerLeg($participants);
        $roundOffset = ($leg - 1) * $roundsPerLeg;

        // Maintain the circle-method ordering across rounds so each round only
        // needs a single rotation instead of re-rotating from scratch.
        $participantList = array_values($participants);
        if (count($participantList) % 2 !== 0) {
            $participantList[] = null; // null represents "bye"
        }

        for ($round = 1; $round <= $roundsPerLeg; ++$round) {
            $globalRound = $round + $roundOffset;

            // Get all participants that need to play in this round
            $participantPairs = $this->getPairsFromParticipantList($participantList);

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
     * Get participant pairs from the current circle-method ordering.
     *
     * @param array<Participant|null> $participantList Participants in circle order, including any "bye" (null)
     * @return array<array<Participant>>
     */
    private function getPairsFromParticipantList(array $participantList): array
    {
        $participantCount = count($participantList);
        $pairingsPerRound = intdiv($participantCount, 2);
        $pairs = [];

        for ($pair = 0; $pair < $pairingsPerRound; ++$pair) {
            $participant1 = $participantList[$pair];
            $participant2 = $participantList[$participantCount - 1 - $pair];

            // Skip if one participant is "bye" (null)
            if ($participant1 !== null && $participant2 !== null) {
                $pairs[] = [$participant1, $participant2];
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
    private function generateRoundRobinEvents(array $participants, ?SchedulingContext $baseContext = null): array
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
            $context = $baseContext === null
                ? new SchedulingContext($participants, $events, 1, 1, 2, $this->createSchedulingMetadata($participants, 1))
                : new SchedulingContext(
                    $participants,
                    $events,
                    $baseContext->getCurrentLeg(),
                    $baseContext->getTotalLegs(),
                    $baseContext->getParticipantsPerEvent(),
                    $baseContext->getMetadata()
                );

            for ($pair = 0; $pair < $pairingsPerRound; ++$pair) {
                $participant1 = $participantList[$pair];
                $participant2 = $participantList[$participantCount - 1 - $pair];

                // Skip if one participant is "bye" (null)
                if ($participant1 === null || $participant2 === null) {
                    continue;
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
     * @param array<Participant> $participants
     * @return array<string, int|string>
     */
    private function createSchedulingMetadata(array $participants, int $legs): array
    {
        $roundsPerLeg = $this->calculateRoundsPerLeg($participants);

        return [
            'algorithm' => 'round-robin',
            'rounds_per_leg' => $roundsPerLeg,
            'total_rounds' => $roundsPerLeg * $legs,
            'expected_event_count' => $this->getExpectedEventCalculator()->calculateExpectedEvents($participants, $legs),
        ];
    }

    /**
     * @param array<Participant> $participants
     */
    private function calculateRoundsPerLeg(array $participants): int
    {
        $participantCount = count($participants);

        return $participantCount % 2 === 0 ? $participantCount - 1 : $participantCount;
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

    /**
     * Get the expected event calculator for Round Robin scheduling.
     */
    #[Override]
    public function getExpectedEventCalculator(): ExpectedEventCalculator
    {
        return new RoundRobinEventCalculator();
    }

    /**
     * Validate constraints before scheduling begins.
     *
     * @param array<Participant> $participants
     * @throws InvalidConfigurationException
     */
    #[Override]
    public function validateConstraints(array $participants, int $legs): void
    {
        // Validate basic configuration
        if (count($participants) < 2) {
            throw new InvalidConfigurationException(
                'Round-robin scheduling requires at least 2 participants',
                ['participant_count' => count($participants), 'minimum_required' => 2]
            );
        }

        if ($legs < 1) {
            throw new InvalidConfigurationException(
                'Legs must be a positive integer',
                ['legs' => $legs, 'minimum_required' => 1]
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

        // TODO: Add advanced constraint validation here
        // This could include checking for mathematically impossible constraints
        // For now, we'll let the scheduling process detect violations
    }

    /**
     * Get the expected number of events for a complete schedule.
     *
     * @param array<Participant> $participants
     */
    #[Override]
    public function getExpectedEventCount(
        array $participants,
        int $legs,
        int $participantsPerEvent = 2
    ): int {
        return $this->getExpectedEventCalculator()->calculateExpectedEvents($participants, $legs);
    }
}
