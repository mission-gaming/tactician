<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use Override;

/**
 * Shape declaration for an elimination bracket stage.
 *
 * A single elimination bracket knows its shape up front: log2(bracket
 * size) rounds and (n-1) × legsPerTie events (every tie eliminates exactly
 * one participant). Double elimination cannot know either — the grand
 * final may or may not reset — so both report null (unknowable), never a
 * fabricated value.
 *
 * Brackets have no legs: getLegs() is null. Two-legged ties do not change
 * that — legsPerTie is a tie-structure fact exposed as getLegsPerTie(),
 * and conflating the two would recreate the legs/rounds overload this
 * design removed.
 */
final readonly class EliminationPlan implements StagePlan
{
    /** @var array<Participant> */
    private array $participants;

    /** @var array<string, true> */
    private array $participantIds;

    /**
     * @param array<Participant> $participants
     * @param 'single-elimination'|'double-elimination' $algorithm
     * @param int $legsPerTie Events per knockout tie (1 or 2)
     * @throws InvalidConfigurationException When the configuration cannot form a bracket
     */
    public function __construct(
        array $participants,
        private string $algorithm,
        private int $legsPerTie = 1
    ) {
        if (!in_array($algorithm, ['single-elimination', 'double-elimination'], true)) {
            throw new InvalidConfigurationException(
                'Unknown elimination algorithm identifier',
                ['algorithm' => $algorithm, 'known' => ['single-elimination', 'double-elimination']]
            );
        }

        if ($legsPerTie !== 1 && $legsPerTie !== 2) {
            throw new InvalidConfigurationException(
                'Ties are played over 1 or 2 legs',
                ['legs_per_tie' => $legsPerTie]
            );
        }

        if (count($participants) < 2) {
            throw new InvalidConfigurationException(
                'Elimination brackets require at least 2 participants',
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
        return $this->algorithm;
    }

    /**
     * Single elimination knows its rounds (log2 of the bracket size);
     * double elimination cannot (the grand final may reset), so it
     * reports null rather than a fabricated count.
     */
    #[Override]
    public function getTotalRounds(): ?int
    {
        if ($this->algorithm !== 'single-elimination') {
            return null;
        }

        return (int) log($this->getBracketSize(), 2);
    }

    /**
     * Brackets have no legs concept; always null. Two-legged ties are a
     * tie-structure fact — see getLegsPerTie().
     */
    #[Override]
    public function getLegs(): ?int
    {
        return null;
    }

    /**
     * Brackets have no legs concept; always null.
     */
    #[Override]
    public function getRoundsPerLeg(): ?int
    {
        return null;
    }

    /**
     * Events per knockout tie: 1, or 2 for two-legged ties.
     */
    public function getLegsPerTie(): int
    {
        return $this->legsPerTie;
    }

    /**
     * Single elimination: every tie eliminates exactly one participant, so
     * n-1 ties × legsPerTie events. Double elimination: null (the grand
     * final may or may not reset).
     */
    #[Override]
    public function getExpectedEventCount(): ?int
    {
        if ($this->algorithm !== 'single-elimination') {
            return null;
        }

        return (count($this->participants) - 1) * $this->legsPerTie;
    }

    /**
     * @return array<Participant>
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    /**
     * The bracket size: the participant count rounded up to a power of two.
     */
    public function getBracketSize(): int
    {
        $size = 2;
        while ($size < count($this->participants)) {
            $size *= 2;
        }

        return $size;
    }

    #[Override]
    public function validateIntegrity(Schedule $schedule): array
    {
        $violations = [];

        foreach ($schedule->getEvents() as $index => $event) {
            $eventParticipants = $event->getParticipants();
            if (count($eventParticipants) !== 2) {
                $violations[] = sprintf(
                    'Event %d has %d participants; elimination events must have exactly 2 participants.',
                    $index + 1,
                    count($eventParticipants)
                );
                continue;
            }

            foreach ($eventParticipants as $participant) {
                if (!isset($this->participantIds[$participant->getId()])) {
                    $violations[] = sprintf(
                        'Event %d contains a participant that is not in the tournament.',
                        $index + 1
                    );
                    break;
                }
            }
        }

        return $violations;
    }
}
