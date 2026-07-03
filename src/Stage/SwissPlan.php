<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use Override;

/**
 * Shape declaration for a Swiss stage.
 *
 * Swiss has rounds but no legs: pairings depend on results, so only the
 * round count and per-round event count are knowable up front. Pairwise
 * meeting counts are not — this plan is deliberately not a PairwisePlan.
 *
 * The round count is null for an open-ended Swiss stage driven round by
 * round without a configured length (the results-driven engine allows
 * this); whole-schedule generation always knows its rounds.
 */
final readonly class SwissPlan implements StagePlan
{
    /** @var array<Participant> */
    private array $participants;

    /**
     * @param array<Participant> $participants
     * @param int|null $rounds Configured Swiss rounds, or null when the stage length is not fixed up front
     * @throws InvalidConfigurationException When the configuration cannot form a Swiss stage
     */
    public function __construct(
        array $participants,
        private ?int $rounds
    ) {
        if ($rounds !== null && $rounds < 1) {
            throw new InvalidConfigurationException(
                'Rounds must be a positive integer',
                ['rounds' => $rounds, 'minimum_required' => 1]
            );
        }

        if (count($participants) < 2) {
            throw new InvalidConfigurationException(
                'Swiss scheduling requires at least 2 participants',
                ['participant_count' => count($participants), 'minimum_required' => 2]
            );
        }

        $this->participants = array_values($participants);
    }

    #[Override]
    public function getAlgorithm(): string
    {
        return 'swiss';
    }

    #[Override]
    public function getTotalRounds(): ?int
    {
        return $this->rounds;
    }

    /**
     * Swiss has no legs concept; always null.
     */
    #[Override]
    public function getLegs(): ?int
    {
        return null;
    }

    /**
     * Swiss has no legs concept; always null.
     */
    #[Override]
    public function getRoundsPerLeg(): ?int
    {
        return null;
    }

    /**
     * Events in one round: half the field, rounded down (an odd field
     * gives one participant a bye).
     */
    public function getEventsPerRound(): int
    {
        return intdiv(count($this->participants), 2);
    }

    #[Override]
    public function getExpectedEventCount(): ?int
    {
        if ($this->rounds === null) {
            return null;
        }

        return $this->getEventsPerRound() * $this->rounds;
    }

    /**
     * @return array<Participant>
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    #[Override]
    public function validateIntegrity(Schedule $schedule): array
    {
        $violations = [];
        $participantIds = array_fill_keys(
            array_map(fn (Participant $participant) => $participant->getId(), $this->participants),
            true
        );
        $roundParticipantIds = [];
        $pairingCounts = [];

        foreach ($schedule->getEvents() as $index => $event) {
            $eventParticipants = $event->getParticipants();
            if (count($eventParticipants) !== 2) {
                $violations[] = sprintf(
                    'Event %d has %d participants; Swiss events must have exactly 2 participants.',
                    $index + 1,
                    count($eventParticipants)
                );
                continue;
            }

            $roundNumber = $event->getRound()?->getNumber();
            if ($roundNumber === null || $roundNumber < 1 || ($this->rounds !== null && $roundNumber > $this->rounds)) {
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
                    'Pairing %s appears %d time(s); Swiss pairings may not repeat.',
                    str_replace('|', ' vs ', $pairingKey),
                    $pairingCounts[$pairingKey]
                );
            }
        }

        if ($this->rounds !== null) {
            $expectedSlots = $this->getEventsPerRound() * 2;
            for ($round = 1; $round <= $this->rounds; ++$round) {
                $actualParticipants = count($roundParticipantIds[$round] ?? []);
                if ($actualParticipants !== $expectedSlots) {
                    $violations[] = sprintf(
                        'Round %d has %d scheduled participant slot(s), expected %d.',
                        $round,
                        $actualParticipants,
                        $expectedSlots
                    );
                }
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
