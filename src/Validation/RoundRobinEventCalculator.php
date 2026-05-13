<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Validation;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;

/**
 * Calculates expected event count for Round Robin scheduling algorithm.
 *
 * Round Robin formula: For n participants, each leg requires n*(n-1)/2 events.
 * This is because each participant must play every other participant exactly once.
 */
readonly class RoundRobinEventCalculator implements ExpectedEventCalculator, ScheduleIntegrityValidator
{
    #[\Override]
    public function calculateExpectedEvents(array $participants, int $legs = 1, array $algorithmSpecificParams = []): int
    {
        $participantCount = count($participants);

        if ($participantCount < 2) {
            return 0;
        }

        // Round robin formula: n*(n-1)/2 per leg
        $eventsPerLeg = intval($participantCount * ($participantCount - 1) / 2);

        return $eventsPerLeg * $legs;
    }

    #[\Override]
    public function getAlgorithmName(): string
    {
        return 'Round Robin';
    }

    public function getDescription(): string
    {
        return 'Each participant plays every other participant exactly once per leg. Formula: n*(n-1)/2 * legs';
    }

    #[\Override]
    public function validateScheduleIntegrity(
        Schedule $schedule,
        array $participants,
        ScheduleValidationContext $context
    ): array {
        $legs = (int) $context->getParameter('legs', $context->getRounds());

        if ($legs < 1 || count($participants) < 2) {
            return [];
        }

        $violations = [];
        $participantLabels = [];
        foreach ($participants as $participant) {
            $participantLabels[$participant->getId()] = $participant->getLabel();
        }

        $expectedPairings = $this->buildExpectedPairingCounts($participants, $legs);
        $actualPairings = [];

        foreach ($schedule->getEvents() as $index => $event) {
            $eventParticipants = $event->getParticipants();
            if (count($eventParticipants) !== 2) {
                $violations[] = sprintf(
                    'Event %d has %d participants; round robin events must have exactly 2 participants.',
                    $index + 1,
                    count($eventParticipants)
                );
                continue;
            }

            $firstId = $eventParticipants[0]->getId();
            $secondId = $eventParticipants[1]->getId();

            if ($firstId === $secondId) {
                $violations[] = sprintf('Event %d contains participant %s twice.', $index + 1, $firstId);
                continue;
            }

            if (!isset($participantLabels[$firstId]) || !isset($participantLabels[$secondId])) {
                $violations[] = sprintf(
                    'Event %d contains a participant that is not in the tournament.',
                    $index + 1
                );
                continue;
            }

            $pairingKey = $this->pairingKey($firstId, $secondId);
            $actualPairings[$pairingKey] = ($actualPairings[$pairingKey] ?? 0) + 1;
        }

        foreach ($expectedPairings as $pairingKey => $expectedCount) {
            $actualCount = $actualPairings[$pairingKey] ?? 0;
            if ($actualCount !== $expectedCount) {
                [$firstId, $secondId] = explode('|', $pairingKey);
                $violations[] = sprintf(
                    'Pairing %s vs %s appears %d time(s), expected %d.',
                    $participantLabels[$firstId],
                    $participantLabels[$secondId],
                    $actualCount,
                    $expectedCount
                );
            }
        }

        return $violations;
    }

    /**
     * @param array<Participant> $participants
     * @return array<string, int>
     */
    private function buildExpectedPairingCounts(array $participants, int $legs): array
    {
        $expectedPairings = [];
        $participantCount = count($participants);

        for ($i = 0; $i < $participantCount - 1; ++$i) {
            for ($j = $i + 1; $j < $participantCount; ++$j) {
                $expectedPairings[$this->pairingKey(
                    $participants[$i]->getId(),
                    $participants[$j]->getId()
                )] = $legs;
            }
        }

        return $expectedPairings;
    }

    private function pairingKey(string $firstId, string $secondId): string
    {
        $ids = [$firstId, $secondId];
        sort($ids);

        return implode('|', $ids);
    }
}
