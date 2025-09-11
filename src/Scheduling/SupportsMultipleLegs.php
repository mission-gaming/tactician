<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use Random\Randomizer;

/**
 * Trait providing multi-leg support to tournament schedulers.
 *
 * This trait handles the common logic for expanding a single-leg schedule
 * into multiple legs using pluggable strategies, while maintaining proper
 * round numbering continuity across legs.
 */
trait SupportsMultipleLegs
{
    /**
     * Expand a single-leg schedule into multiple legs using the strategy.
     *
     * @param array<Event> $baseEvents The events from the first leg
     * @param int $legs The total number of legs to generate
     * @param LegStrategyInterface $legStrategy The strategy for generating additional legs
     * @param Randomizer|null $randomizer Optional randomizer for strategies that need it
     *
     * @return array<Event> All events across all legs with continuous round numbering
     * @throws \DivisionByZeroError When roundsPerLeg is zero in createEventsFromPairings
     */
    private function expandScheduleForLegs(
        array $baseEvents,
        int $legs,
        LegStrategyInterface $legStrategy,
        ?Randomizer $randomizer
    ): array {
        if ($legs === 1) {
            return $baseEvents;
        }

        $allEvents = $baseEvents;
        $roundsPerLeg = $this->calculateRoundsPerLeg($baseEvents);

        // Extract pairings from base events
        $basePairings = $this->extractPairings($baseEvents);

        for ($leg = 2; $leg <= $legs; ++$leg) {
            // Generate pairings for this leg using the strategy
            $legPairings = $legStrategy->generateLegPairings(
                $basePairings,
                $leg,
                $legs,
                $randomizer
            );

            // Convert pairings back to events with proper round numbering
            $legEvents = $this->createEventsFromPairings(
                $legPairings,
                $roundsPerLeg,
                $leg
            );

            $allEvents = [...$allEvents, ...$legEvents];
        }

        return $allEvents;
    }

    /**
     * Calculate how many rounds are in a single leg.
     *
     * @param array<Event> $events The events from the first leg
     *
     * @return int The number of rounds in one leg
     */
    private function calculateRoundsPerLeg(array $events): int
    {
        $rounds = array_unique(array_map(
            fn (Event $event) => $event->getRound()?->getNumber() ?? 0,
            $events
        ));

        return count(array_filter($rounds, fn ($r) => $r > 0));
    }

    /**
     * Extract participant pairings from events.
     *
     * @param array<Event> $events The events to extract pairings from
     *
     * @return array<array{0: \MissionGaming\Tactician\DTO\Participant, 1: \MissionGaming\Tactician\DTO\Participant}>
     */
    private function extractPairings(array $events): array
    {
        return array_map(function (Event $event) {
            $participants = $event->getParticipants();

            return [$participants[0], $participants[1]];
        }, $events);
    }

    /**
     * Create events from participant pairings with proper round numbering.
     *
     * @param array<array<\MissionGaming\Tactician\DTO\Participant>> $pairings The participant pairings
     * @param int $roundsPerLeg The number of rounds in each leg
     * @param int $leg The leg number (for round offset calculation)
     *
     * @return array<Event> The events for this leg
     * @throws \DivisionByZeroError When roundsPerLeg is zero
     */
    private function createEventsFromPairings(
        array $pairings,
        int $roundsPerLeg,
        int $leg
    ): array {
        $roundOffset = ($leg - 1) * $roundsPerLeg;
        $events = [];

        // Group pairings by their original round (based on index pattern)
        $pairingsPerRound = count($pairings) / $roundsPerLeg;

        for ($roundIndex = 0; $roundIndex < $roundsPerLeg; ++$roundIndex) {
            $roundNumber = $roundIndex + 1 + $roundOffset;
            $round = new Round($roundNumber);

            $startIndex = $roundIndex * (int) $pairingsPerRound;
            $endIndex = $startIndex + (int) $pairingsPerRound;

            for ($i = $startIndex; $i < $endIndex; ++$i) {
                if (isset($pairings[$i])) {
                    $events[] = new Event($pairings[$i], $round);
                }
            }
        }

        return $events;
    }
}
