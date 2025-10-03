<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\RoundSchedule;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\Ordering\ParticipantOrderer;
use MissionGaming\Tactician\Ordering\StaticParticipantOrderer;
use MissionGaming\Tactician\Positioning\Position;
use MissionGaming\Tactician\Positioning\PositionalPairing;
use MissionGaming\Tactician\Positioning\PositionalRound;
use MissionGaming\Tactician\Positioning\PositionalSchedule;
use MissionGaming\Tactician\Positioning\PositionResolver;
use MissionGaming\Tactician\Positioning\PositionType;
use MissionGaming\Tactician\Positioning\SeedBasedPositionResolver;
use MissionGaming\Tactician\Validation\ConstraintViolation;
use MissionGaming\Tactician\Validation\ExpectedEventCalculator;
use MissionGaming\Tactician\Validation\RoundRobinEventCalculator;
use MissionGaming\Tactician\Validation\ValidatesScheduleCompleteness;
use Override;
use Random\Randomizer;

/**
 * Round-robin tournament scheduler using the circle method.
 *
 * This scheduler generates tournaments where every participant plays every
 * other participant. Supports:
 * - Single and multi-leg tournaments
 * - Home/away leg variations (via LegStrategy)
 * - Constraint validation
 * - Deterministic or randomized initial seeding
 */
class RoundRobinScheduler implements SchedulerInterface
{
    use ValidatesScheduleCompleteness;

    public function __construct(
        private ?ConstraintSet $constraints = null,
        private ?Randomizer $randomizer = null,
        private ?ParticipantOrderer $participantOrderer = null
    ) {
        $this->initializeValidation();
        $this->participantOrderer ??= new StaticParticipantOrderer();
    }

    /**
     * Generate the positional structure of a round-robin tournament.
     *
     * Returns the tournament blueprint showing which seed positions will
     * play against each other. This always uses seed-based positions since
     * round-robin pairings are predetermined.
     */
    #[Override]
    public function generateStructure(int $participantCount): PositionalSchedule
    {
        $this->validateParticipantCount($participantCount);

        $rounds = [];
        // For odd participants, we need participant_count rounds (due to bye)
        // For even participants, we need participant_count - 1 rounds
        $roundCount = $participantCount % 2 === 0 ? $participantCount - 1 : $participantCount;

        // Generate pairings for each round using circle method
        for ($roundNumber = 1; $roundNumber <= $roundCount; ++$roundNumber) {
            $pairings = $this->generatePositionalPairingsForRound($participantCount, $roundNumber);
            $rounds[] = new PositionalRound($roundNumber, $pairings);
        }

        return new PositionalSchedule($rounds, [
            'algorithm' => 'round-robin',
            'participant_count' => $participantCount,
            'rounds' => $roundCount,
        ]);
    }

    /**
     * Generate a complete single-leg round-robin schedule.
     *
     * Creates all events for a complete round-robin tournament where each
     * participant plays every other participant once.
     */
    #[Override]
    public function generateSchedule(array $participants): Schedule
    {
        $this->validateParticipants($participants);
        $this->clearViolations();

        // Apply random shuffling if randomizer is provided
        $orderedParticipants = $this->randomizer !== null
            ? $this->shuffleParticipants($participants)
            : $participants;

        // Generate positional structure
        $structure = $this->generateStructure(count($orderedParticipants));

        // Resolve positions to participants
        $resolver = new SeedBasedPositionResolver(array_values($orderedParticipants));

        // Get metadata from structure and add single-leg specific metadata
        $metadata = $structure->getMetadata();
        $metadata['total_rounds'] = $metadata['rounds'];
        $metadata['legs'] = 1;
        $metadata['rounds_per_leg'] = $metadata['rounds'];

        // Resolve with updated metadata
        $allEvents = [];
        $context = new SchedulingContext($orderedParticipants, [], 1, 1, 2);

        foreach ($structure->getRounds() as $positionalRound) {
            $resolvedEvents = $positionalRound->resolve(
                $resolver,
                $this->participantOrderer,
                $context,
                null // single-leg, so leg is null
            );
            $allEvents = [...$allEvents, ...$resolvedEvents];
            $context = $context->withEvents($resolvedEvents);
        }

        $schedule = new Schedule($allEvents, [
            ...$metadata,
            'fully_resolved' => true,
            'positional_structure' => $structure,
        ]);

        // Apply constraints if provided
        if ($this->constraints !== null) {
            $schedule = $this->filterEventsByConstraints($schedule, $orderedParticipants);
        }

        // Validate completeness
        $this->validateGeneratedSchedule($schedule, $orderedParticipants, 1);

        return $schedule;
    }

    /**
     * Generate a multi-leg round-robin schedule with leg-specific variations.
     *
     * This is a round-robin-specific method that supports home/away fixtures
     * and other multi-leg tournament formats.
     *
     * @param array<Participant> $participants Tournament participants
     * @param int $legs Number of legs in the tournament
     * @param LegStrategyInterface|null $legStrategy Strategy for handling multiple legs
     *
     * @throws InvalidConfigurationException When configuration is invalid
     * @throws IncompleteScheduleException When constraints prevent complete schedule generation
     */
    public function generateMultiLegSchedule(
        array $participants,
        int $legs,
        ?LegStrategyInterface $legStrategy = null
    ): Schedule {
        $this->validateParticipants($participants);

        if ($legs < 1) {
            throw new InvalidConfigurationException(
                'Legs must be a positive integer',
                ['legs' => $legs, 'minimum_required' => 1]
            );
        }

        $this->clearViolations();

        $legStrategy ??= new MirroredLegStrategy();

        // Apply random shuffling if randomizer is provided
        $orderedParticipants = $this->randomizer !== null
            ? $this->shuffleParticipants($participants)
            : $participants;

        // Generate all events using leg strategy
        $allEvents = $this->generateMultiLegEvents($orderedParticipants, $legs, $legStrategy);

        $participantCount = count($orderedParticipants);
        $roundsPerLeg = $participantCount % 2 === 0 ? $participantCount - 1 : $participantCount;
        $totalRounds = $roundsPerLeg * $legs;

        $schedule = new Schedule($allEvents, [
            'algorithm' => 'round-robin',
            'participant_count' => count($orderedParticipants),
            'legs' => $legs,
            'rounds_per_leg' => $roundsPerLeg,
            'total_rounds' => $totalRounds,
        ]);

        // Validate schedule completeness
        $this->validateGeneratedSchedule($schedule, $orderedParticipants, $legs);

        return $schedule;
    }

    /**
     * Generate events for a single round of round-robin.
     *
     * Supports both independent round generation and resolver-based generation
     * for consistency with the unified scheduler interface.
     */
    #[Override]
    public function generateRound(
        array $participants,
        int $roundNumber,
        ?PositionResolver $resolver = null
    ): RoundSchedule {
        $this->validateParticipants($participants);

        $participantCount = count($participants);
        $roundCount = $participantCount % 2 === 0 ? $participantCount - 1 : $participantCount;

        if ($roundNumber < 1 || $roundNumber > $roundCount) {
            throw new \InvalidArgumentException(
                "Round number must be between 1 and {$roundCount} for {$participantCount} participants"
            );
        }

        // If no resolver provided, use seed-based resolver with given participants
        $resolver ??= new SeedBasedPositionResolver(array_values($participants));

        // Generate positional pairings for this round
        $pairings = $this->generatePositionalPairingsForRound($participantCount, $roundNumber);
        $positionalRound = new PositionalRound($roundNumber, $pairings);

        // Resolve to actual events with participant ordering
        $context = new SchedulingContext($participants, [], 1, 1, 2);
        $events = $positionalRound->resolve($resolver, $this->participantOrderer, $context, null);

        // Apply constraints if provided
        if ($this->constraints !== null) {
            $events = $this->filterEventArrayByConstraints($events, $participants, $roundNumber);
        }

        return new RoundSchedule($roundNumber, $events);
    }

    /**
     * Round-robin always supports complete generation since all pairings are predetermined.
     */
    #[Override]
    public function supportsCompleteGeneration(): bool
    {
        return true;
    }

    /**
     * Validate constraints before scheduling.
     */
    #[Override]
    public function validateConstraints(array $participants): void
    {
        $this->validateParticipants($participants);

        // Additional constraint validation could go here
        // For now, participant validation is sufficient
    }

    /**
     * Get the expected number of events for a complete round-robin tournament.
     *
     * Formula: n * (n-1) / 2 where n is the number of participants
     */
    #[Override]
    public function getExpectedEventCount(int $participantCount): int
    {
        if ($participantCount < 2) {
            return 0;
        }

        return (int) ($participantCount * ($participantCount - 1) / 2);
    }

    /**
     * Get the expected event calculator for round-robin scheduling.
     */
    #[Override]
    public function getExpectedEventCalculator(): ExpectedEventCalculator
    {
        return new RoundRobinEventCalculator();
    }

    /**
     * Generate positional pairings for a specific round using circle method.
     *
     * @return array<PositionalPairing>
     */
    private function generatePositionalPairingsForRound(int $participantCount, int $roundNumber): array
    {
        $pairings = [];

        // Handle odd number of participants by adding a "bye"
        $hasBye = $participantCount % 2 !== 0;
        $adjustedCount = $hasBye ? $participantCount + 1 : $participantCount;

        // Create position array for circle method
        $positions = range(1, $adjustedCount);

        // Apply rotations to get to the correct round
        for ($r = 1; $r < $roundNumber; ++$r) {
            $this->rotatePositions($positions);
        }

        $pairingsPerRound = $adjustedCount / 2;

        for ($pair = 0; $pair < $pairingsPerRound; ++$pair) {
            $position1 = $positions[$pair];
            $position2 = $positions[$adjustedCount - 1 - $pair];

            // Skip if one position is the "bye" (beyond actual participant count)
            if ($position1 > $participantCount || $position2 > $participantCount) {
                continue;
            }

            $pairings[] = new PositionalPairing(
                new Position(PositionType::SEED, $position1),
                new Position(PositionType::SEED, $position2)
            );
        }

        return $pairings;
    }

    /**
     * Rotate positions for circle method (keep first fixed, rotate others).
     *
     * @param array<int> $positions
     */
    private function rotatePositions(array &$positions): void
    {
        if (count($positions) <= 2) {
            return;
        }

        $temp = $positions[1];
        for ($i = 1; $i < count($positions) - 1; ++$i) {
            $positions[$i] = $positions[$i + 1];
        }
        $positions[count($positions) - 1] = $temp;
    }

    /**
     * Generate all events for a multi-leg tournament using leg strategy.
     *
     * @param array<Participant> $participants
     * @return array<Event>
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException
     */
    private function generateMultiLegEvents(
        array $participants,
        int $legs,
        LegStrategyInterface $legStrategy
    ): array {
        $allEvents = [];
        $context = new SchedulingContext($participants, [], 1, $legs, 2);

        $expectedEventsPerLeg = $this->getExpectedEventCount(count($participants));

        for ($leg = 1; $leg <= $legs; ++$leg) {
            $legEvents = $this->generateLegWithFullContext($participants, $leg, $legStrategy, $context);

            // Check if we generated the expected number of events for this leg
            if (count($legEvents) < $expectedEventsPerLeg) {
                throw new IncompleteScheduleException(
                    $expectedEventsPerLeg * $legs,
                    count($allEvents) + count($legEvents),
                    $this->violationCollector,
                    $this->getExpectedEventCalculator(),
                    $participants,
                    $legs,
                    "Failed to generate complete schedule for leg {$leg}. Generated " . count($legEvents) . " events, expected {$expectedEventsPerLeg}."
                );
            }

            $allEvents = [...$allEvents, ...$legEvents];
            $context = new SchedulingContext($participants, $allEvents, $leg + 1, $legs, 2);
        }

        return $allEvents;
    }

    /**
     * Generate events for a specific leg with full tournament context.
     *
     * @param array<Participant> $participants
     * @return array<Event>
     * @throws InvalidConfigurationException
     */
    private function generateLegWithFullContext(
        array $participants,
        int $leg,
        LegStrategyInterface $legStrategy,
        SchedulingContext $context
    ): array {
        if ($leg === 1) {
            // For the first leg, use standard round-robin generation
            return $this->generateRoundRobinEvents($participants, $leg);
        }

        // For subsequent legs, use the strategy to generate events
        $legEvents = [];
        $participantCount = count($participants);
        $roundsPerLeg = $participantCount % 2 === 0 ? $participantCount - 1 : $participantCount;
        $roundOffset = ($leg - 1) * $roundsPerLeg;

        for ($round = 1; $round <= $roundsPerLeg; ++$round) {
            $globalRound = $round + $roundOffset;

            // Get all participants that need to play in this round
            $participantPairs = $this->getParticipantPairsForRound($participants, $round);

            foreach ($participantPairs as $pair) {
                $event = $legStrategy->generateEventForLeg($pair, $leg, $globalRound, $context);

                if ($event !== null) {
                    // Check constraints with full tournament context
                    if ($this->shouldAddEventWithFullConstraints($event, $context)) {
                        $legEvents[] = $event;
                        // Update context for subsequent events
                        $context = $context->withEvents([$event]);
                    } else {
                        // Record violation for diagnostics
                        $this->recordConstraintViolation($event, $context);
                    }
                }
            }
        }

        return $legEvents;
    }

    /**
     * Generate all round-robin events using circle method.
     *
     * @param array<Participant> $participants
     * @param int $leg The current leg number (for orderer context)
     * @return array<Event>
     * @throws InvalidConfigurationException
     */
    private function generateRoundRobinEvents(array $participants, int $leg = 1): array
    {
        $participantCount = count($participants);
        $structure = $this->generateStructure($participantCount);
        $resolver = new SeedBasedPositionResolver(array_values($participants));

        $allEvents = [];
        $context = new SchedulingContext($participants, [], $leg, 1, 2);

        foreach ($structure->getRounds() as $positionalRound) {
            $resolvedEvents = $positionalRound->resolve(
                $resolver,
                $this->participantOrderer,
                $context,
                $leg
            );

            foreach ($resolvedEvents as $event) {
                // Check constraints if provided
                if ($this->constraints === null) {
                    $allEvents[] = $event;
                    $context = $context->withEvents([$event]);
                } else {
                    if ($this->constraints->isSatisfied($event, $context)) {
                        $allEvents[] = $event;
                        $context = $context->withEvents([$event]);
                    } else {
                        // Record constraint violations for diagnostic reporting
                        foreach ($this->constraints->getConstraints() as $constraint) {
                            if (!$constraint->isSatisfied($event, $context)) {
                                $eventParticipants = $event->getParticipants();
                                $violation = new ConstraintViolation(
                                    $constraint,
                                    $event,
                                    sprintf(
                                        'Event %s vs %s rejected by constraint',
                                        $eventParticipants[0]->getId(),
                                        $eventParticipants[1]->getId()
                                    ),
                                    $eventParticipants,
                                    $event->getRound()?->getNumber() ?? 0
                                );
                                $this->recordViolation($violation);
                            }
                        }
                    }
                }
            }
        }

        return $allEvents;
    }

    /**
     * Get participant pairs for a specific round.
     *
     * @param array<Participant> $participants
     * @return array<array<Participant>>
     */
    private function getParticipantPairsForRound(array $participants, int $round): array
    {
        $participantList = array_values($participants);
        $participantCount = count($participantList);

        // Handle odd number of participants by adding a "bye"
        if ($participantCount % 2 !== 0) {
            $participantList[] = null;
            ++$participantCount;
        }

        $pairingsPerRound = $participantCount / 2;
        $pairs = [];

        // Apply rotations to get to the correct round
        for ($r = 1; $r < $round; ++$r) {
            $this->rotateParticipants($participantList);
        }

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
     * Rotate participants for circle method (keep first fixed, rotate others).
     *
     * @param array<Participant|null> $participants
     */
    private function rotateParticipants(array &$participants): void
    {
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
     * Shuffle participants using the provided randomizer.
     *
     * @param array<Participant> $participants
     * @return array<Participant>
     */
    private function shuffleParticipants(array $participants): array
    {
        $shuffled = array_values($participants);
        $this->randomizer?->shuffleArray($shuffled);

        return $shuffled;
    }

    /**
     * Check if an event should be added based on constraints with full tournament context.
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
     */
    private function recordConstraintViolation(Event $event, SchedulingContext $context): void
    {
        if ($this->constraints === null) {
            return;
        }

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
     * Filter schedule events by constraints, keeping only valid events.
     *
     * @param array<Participant> $participants
     */
    private function filterEventsByConstraints(Schedule $schedule, array $participants): Schedule
    {
        $filteredEvents = [];
        $context = new SchedulingContext($participants, [], 1, 1, 2);

        foreach ($schedule->getEvents() as $event) {
            if ($this->constraints === null || $this->constraints->isSatisfied($event, $context)) {
                $filteredEvents[] = $event;
                $context = $context->withEvents([$event]);
            } else {
                // Record violations
                foreach ($this->constraints->getConstraints() as $constraint) {
                    if (!$constraint->isSatisfied($event, $context)) {
                        $eventParticipants = $event->getParticipants();
                        $violation = new ConstraintViolation(
                            $constraint,
                            $event,
                            sprintf(
                                'Event %s vs %s rejected by constraint',
                                $eventParticipants[0]->getId(),
                                $eventParticipants[1]->getId()
                            ),
                            $eventParticipants,
                            $event->getRound()?->getNumber() ?? 0
                        );
                        $this->recordViolation($violation);
                    }
                }
            }
        }

        return new Schedule($filteredEvents, $schedule->getMetadata());
    }

    /**
     * Filter event array by constraints.
     *
     * @param array<Event> $events
     * @param array<Participant> $participants
     * @return array<Event>
     */
    private function filterEventArrayByConstraints(array $events, array $participants, int $round): array
    {
        $filteredEvents = [];
        $context = new SchedulingContext($participants, [], 1, 1, 2);

        foreach ($events as $event) {
            if ($this->constraints === null || $this->constraints->isSatisfied($event, $context)) {
                $filteredEvents[] = $event;
            }
        }

        return $filteredEvents;
    }

    /**
     * Validate participant array.
     *
     * @param array<Participant> $participants
     * @throws InvalidConfigurationException
     */
    private function validateParticipants(array $participants): void
    {
        $participantCount = count($participants);

        if ($participantCount < 2) {
            throw new InvalidConfigurationException(
                'Round-robin scheduling requires at least 2 participants',
                ['participant_count' => $participantCount, 'minimum_required' => 2]
            );
        }

        // Check for duplicate participant IDs
        $ids = array_map(fn (Participant $p) => $p->getId(), $participants);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidConfigurationException(
                'All participants must have unique IDs',
                ['participant_count' => $participantCount, 'unique_ids' => count(array_unique($ids))]
            );
        }
    }

    /**
     * Validate participant count for structure generation.
     *
     * @throws InvalidConfigurationException
     */
    private function validateParticipantCount(int $participantCount): void
    {
        if ($participantCount < 2) {
            throw new InvalidConfigurationException(
                'Round-robin scheduling requires at least 2 participants',
                ['participant_count' => $participantCount, 'minimum_required' => 2]
            );
        }
    }
}
