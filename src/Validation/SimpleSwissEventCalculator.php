<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Validation;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;

/**
 * Calculates and validates event counts for simple Swiss scheduling.
 */
readonly class SimpleSwissEventCalculator implements ExpectedEventCalculator, ScheduleIntegrityValidator
{
    #[\Override]
    public function calculateExpectedEvents(array $participants, int $rounds = 1, array $algorithmSpecificParams = []): int
    {
        if ($rounds < 1) {
            return 0;
        }

        return intdiv(count($participants), 2) * $rounds;
    }

    #[\Override]
    public function getAlgorithmName(): string
    {
        return 'Simple Swiss';
    }

    #[\Override]
    public function validateScheduleIntegrity(
        Schedule $schedule,
        array $participants,
        ScheduleValidationContext $context
    ): array {
        $violations = [];
        $participantIds = array_fill_keys(
            array_map(fn (Participant $participant) => $participant->getId(), $participants),
            true
        );
        $roundParticipantIds = [];
        $pairingCounts = [];

        foreach ($schedule->getEvents() as $index => $event) {
            $eventParticipants = $event->getParticipants();
            if (count($eventParticipants) !== 2) {
                $violations[] = sprintf(
                    'Event %d has %d participants; simple Swiss events must have exactly 2 participants.',
                    $index + 1,
                    count($eventParticipants)
                );
                continue;
            }

            $roundNumber = $event->getRound()?->getNumber();
            if ($roundNumber === null || $roundNumber < 1 || $roundNumber > $context->getRounds()) {
                $violations[] = sprintf('Event %d has an invalid round number.', $index + 1);
                continue;
            }

            $firstId = $eventParticipants[0]->getId();
            $secondId = $eventParticipants[1]->getId();

            if ($firstId === $secondId) {
                $violations[] = sprintf('Event %d contains participant %s twice.', $index + 1, $firstId);
                continue;
            }

            if (!isset($participantIds[$firstId]) || !isset($participantIds[$secondId])) {
                $violations[] = sprintf('Event %d contains a participant that is not in the tournament.', $index + 1);
                continue;
            }

            foreach ([$firstId, $secondId] as $participantId) {
                if (isset($roundParticipantIds[$roundNumber][$participantId])) {
                    $violations[] = sprintf(
                        'Participant %s appears more than once in round %d.',
                        $participantId,
                        $roundNumber
                    );
                }

                $roundParticipantIds[$roundNumber][$participantId] = true;
            }

            $pairingKey = $this->pairingKey($firstId, $secondId);
            $pairingCounts[$pairingKey] = ($pairingCounts[$pairingKey] ?? 0) + 1;
            if ($pairingCounts[$pairingKey] > 1) {
                $violations[] = sprintf(
                    'Pairing %s appears %d time(s); simple Swiss pairings may not repeat.',
                    str_replace('|', ' vs ', $pairingKey),
                    $pairingCounts[$pairingKey]
                );
            }
        }

        $eventsPerRound = intdiv(count($participants), 2);
        for ($round = 1; $round <= $context->getRounds(); ++$round) {
            $actualParticipants = count($roundParticipantIds[$round] ?? []);
            if ($actualParticipants !== $eventsPerRound * 2) {
                $violations[] = sprintf(
                    'Round %d has %d scheduled participant slot(s), expected %d.',
                    $round,
                    $actualParticipants,
                    $eventsPerRound * 2
                );
            }
        }

        return $violations;
    }

    private function pairingKey(string $firstId, string $secondId): string
    {
        $ids = [$firstId, $secondId];
        sort($ids);

        return implode('|', $ids);
    }
}
