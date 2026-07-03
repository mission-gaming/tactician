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

        // Build the plan first: the strategy contributes its facts, an
        // unsatisfiable configuration fails here with diagnostics, and
        // generation, validation, and diagnostics all read shape facts
        // from the resulting plan.
        $plan = $this->buildPlan($participants, $legs, $strategy);

        // Generate complete schedule using integrated approach, retrying with
        // rotated participant orderings when constraints reject the pairings
        // implied by a particular circle-method order.
        $allEvents = $this->generateScheduleWithRetries($participants, $participantsPerEvent, $strategy, $plan);
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
     * Build the round-robin stage plan for the given configuration.
     *
     * The strategy-specific facts (role mirroring, randomization) reflect
     * the default MirroredLegStrategy; shape facts (rounds, event counts)
     * are strategy-independent. schedule() builds its plan from the
     * strategy actually in use.
     *
     * @param array<Participant> $participants
     * @throws InvalidConfigurationException When the configuration is unsatisfiable
     */
    #[Override]
    public function getPlan(
        array $participants,
        int $legs,
        int $participantsPerEvent = 2
    ): RoundRobinPlan {
        $this->validateInputs($participants, $participantsPerEvent, $legs);

        return $this->buildPlan($participants, $legs, new MirroredLegStrategy());
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
                return $this->generateIntegratedSchedule($ordered, $participantsPerEvent, $strategy, $plan);
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
        LegStrategyInterface $strategy,
        RoundRobinPlan $plan
    ): array {
        $allEvents = [];
        $this->roundByes = [];
        $legs = $plan->getLegs();
        $context = new SchedulingContext($participants, $plan, [], 1, $participantsPerEvent);

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
            $context = new SchedulingContext($participants, $plan, $allEvents, $leg + 1, $participantsPerEvent);
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
}
