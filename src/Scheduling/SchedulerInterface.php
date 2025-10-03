<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\RoundSchedule;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Positioning\PositionalSchedule;
use MissionGaming\Tactician\Positioning\PositionResolver;
use MissionGaming\Tactician\Validation\ExpectedEventCalculator;

/**
 * Interface for tournament schedulers using position-based generation.
 *
 * This unified interface supports both static scheduling (all events
 * generated upfront) and dynamic scheduling (round-by-round generation
 * based on standings).
 *
 * Key concepts:
 * - Positional Structure: The tournament blueprint (who plays whom in positional terms)
 * - Schedule: Resolved events with actual participants assigned
 * - Round Schedule: Events for a single round
 */
interface SchedulerInterface
{
    /**
     * Generate the positional structure of the tournament.
     *
     * This returns the tournament blueprint showing which positions will
     * play against each other, independent of actual participant assignment.
     * This method ALWAYS works regardless of algorithm type.
     *
     * Use cases:
     * - Inspect tournament structure before play begins
     * - Understand pairing patterns
     * - Validate tournament configuration
     *
     * @param int $participantCount Number of participants in the tournament
     * @return PositionalSchedule The tournament structure as positional pairings
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When configuration is invalid
     */
    public function generateStructure(int $participantCount): PositionalSchedule;

    /**
     * Generate a complete schedule with all events resolved to actual participants.
     *
     * For static algorithms (round-robin, seeded Swiss):
     *   - Generates all events upfront
     *   - Returns fully resolved schedule
     *
     * For dynamic algorithms (standings-based Swiss):
     *   - May only work for round 1
     *   - Subsequent rounds must use generateRound()
     *   - Check supportsCompleteGeneration() first
     *
     * @param array<Participant> $participants Tournament participants in seed order
     * @return Schedule The complete tournament schedule
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When configuration is invalid
     * @throws \MissionGaming\Tactician\Exceptions\IncompleteScheduleException When constraints prevent complete generation
     * @throws \MissionGaming\Tactician\Exceptions\UnsupportedOperationException When algorithm requires dynamic generation
     */
    public function generateSchedule(array $participants): Schedule;

    /**
     * Generate events for a single round.
     *
     * This method supports both static and dynamic scheduling:
     * - Static algorithms: Can generate any round independently
     * - Dynamic algorithms: Requires standings for rounds after the first
     *
     * @param array<Participant> $participants All tournament participants
     * @param int $roundNumber Round to generate (1-based)
     * @param PositionResolver|null $resolver Resolver for positions (required for dynamic algorithms after round 1)
     * @return RoundSchedule The events for this round
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When configuration is invalid
     * @throws \InvalidArgumentException When round number is invalid or resolver is required but missing
     */
    public function generateRound(
        array $participants,
        int $roundNumber,
        ?PositionResolver $resolver = null
    ): RoundSchedule;

    /**
     * Check if this scheduler can generate a complete schedule upfront.
     *
     * Returns true for:
     * - Round-robin (all pairings known in advance)
     * - Seeded Swiss (pre-determined pairings like UEFA CL)
     *
     * Returns false for:
     * - Standings-based Swiss (requires results between rounds)
     * - Dynamic knockout tournaments
     *
     * @return bool True if generateSchedule() will work, false if round-by-round required
     */
    public function supportsCompleteGeneration(): bool;

    /**
     * Validate constraints before scheduling begins.
     *
     * @param array<Participant> $participants Tournament participants
     * @throws \MissionGaming\Tactician\Exceptions\ImpossibleConstraintsException When constraints are mathematically impossible
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When configuration is invalid
     */
    public function validateConstraints(array $participants): void;

    /**
     * Get the expected number of events for a complete tournament.
     *
     * @param int $participantCount Number of participants
     * @return int Expected total number of events
     */
    public function getExpectedEventCount(int $participantCount): int;

    /**
     * Get the expected event calculator for this scheduling algorithm.
     *
     * @return ExpectedEventCalculator The calculator for this algorithm
     */
    public function getExpectedEventCalculator(): ExpectedEventCalculator;
}
