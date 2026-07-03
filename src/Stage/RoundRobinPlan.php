<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use Override;

/**
 * Shape declaration for a round-robin stage.
 *
 * Every pair of participants meets exactly once per leg, so the plan knows
 * everything up front: rounds per leg (n-1 for even fields, n for odd
 * fields, whose bye adds a round to the rotation), total rounds, expected
 * event counts, and pairwise meeting multiplicities. Generation, validation,
 * and diagnostics all read these facts from here — this class is the single
 * home of the round-robin arithmetic.
 */
final readonly class RoundRobinPlan implements PairwisePlan
{
    /** @var array<Participant> */
    private array $participants;

    /** @var array<string, true> Participant IDs for membership checks */
    private array $participantIds;

    /**
     * @param array<Participant> $participants
     * @param int $legs How many times each participant meets each other participant
     * @param bool $rolesMirrorAcrossLegs Whether the leg strategy reverses event roles in later legs
     * @param bool $requiresRandomization Whether the leg strategy needs a randomizer during generation
     * @param array<string> $warnings Non-fatal notes from plan construction
     * @throws InvalidConfigurationException When the configuration cannot form a round robin
     */
    public function __construct(
        array $participants,
        private int $legs,
        private bool $rolesMirrorAcrossLegs = false,
        private bool $requiresRandomization = false,
        private array $warnings = []
    ) {
        if ($legs < 1) {
            throw new InvalidConfigurationException(
                'Legs must be a positive integer',
                ['legs' => $legs, 'minimum_required' => 1]
            );
        }

        if (count($participants) < 2) {
            throw new InvalidConfigurationException(
                'Round-robin scheduling requires at least 2 participants',
                ['participant_count' => count($participants), 'minimum_required' => 2]
            );
        }

        $this->participants = array_values($participants);
        $ids = [];
        foreach ($this->participants as $participant) {
            $ids[$participant->getId()] = true;
        }
        $this->participantIds = $ids;
    }

    #[Override]
    public function getAlgorithm(): string
    {
        return 'round-robin';
    }

    #[Override]
    public function getTotalRounds(): int
    {
        return $this->getRoundsPerLeg() * $this->legs;
    }

    #[Override]
    public function getLegs(): int
    {
        return $this->legs;
    }

    #[Override]
    public function getRoundsPerLeg(): int
    {
        $participantCount = count($this->participants);

        return $participantCount % 2 === 0 ? $participantCount - 1 : $participantCount;
    }

    /**
     * Events in one leg: every pair meets once, n(n-1)/2.
     */
    public function getEventsPerLeg(): int
    {
        $participantCount = count($this->participants);

        return intdiv($participantCount * ($participantCount - 1), 2);
    }

    #[Override]
    public function getExpectedEventCount(): int
    {
        return $this->getEventsPerLeg() * $this->legs;
    }

    #[Override]
    public function getExpectedMeetings(Participant $a, Participant $b): int
    {
        if ($a->getId() === $b->getId()) {
            return 0;
        }

        if (!isset($this->participantIds[$a->getId()]) || !isset($this->participantIds[$b->getId()])) {
            return 0;
        }

        return $this->legs;
    }

    /**
     * @return array<Participant>
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    /**
     * Whether the leg strategy mirrors event roles across legs (home/away
     * style reversal in even-numbered legs).
     */
    public function rolesMirrorAcrossLegs(): bool
    {
        return $this->rolesMirrorAcrossLegs;
    }

    /**
     * Whether the leg strategy needs randomization during generation.
     */
    public function requiresRandomization(): bool
    {
        return $this->requiresRandomization;
    }

    /**
     * @return array<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    #[Override]
    public function validateIntegrity(Schedule $schedule): array
    {
        $violations = [];
        $participantLabels = [];
        foreach ($this->participants as $participant) {
            $participantLabels[$participant->getId()] = $participant->getLabel();
        }

        $expectedPairings = $this->buildExpectedPairingCounts();
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
     * @return array<string, int>
     */
    private function buildExpectedPairingCounts(): array
    {
        $expectedPairings = [];
        $participantCount = count($this->participants);

        for ($i = 0; $i < $participantCount - 1; ++$i) {
            for ($j = $i + 1; $j < $participantCount; ++$j) {
                $expectedPairings[$this->pairingKey(
                    $this->participants[$i]->getId(),
                    $this->participants[$j]->getId()
                )] = $this->legs;
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
