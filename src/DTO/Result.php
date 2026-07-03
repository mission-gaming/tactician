<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

use InvalidArgumentException;

/**
 * Represents the outcome of a played event.
 *
 * A Result records which participant won (or that the event was drawn) and
 * optionally the numeric scores per participant. Events without a Result are
 * simply not played yet.
 */
readonly class Result
{
    /**
     * Create a new Result for a played event.
     *
     * @param Event $event The event this result belongs to
     * @param Participant|null $winner The winning participant, or null for a draw
     * @param array<int|string, int|float> $scores Optional numeric scores keyed by participant ID
     *                                             (numeric-string IDs become int keys in PHP)
     *
     * @throws InvalidArgumentException When the winner or a score references a participant not in the event
     */
    public function __construct(
        private Event $event,
        private ?Participant $winner = null,
        private array $scores = []
    ) {
        if ($winner !== null && !$event->hasParticipant($winner)) {
            throw new InvalidArgumentException('Winner must be a participant in the event');
        }

        $participantIds = array_map(
            fn (Participant $participant) => $participant->getId(),
            $event->getParticipants()
        );
        foreach (array_keys($scores) as $participantId) {
            // PHP canonicalizes numeric-string array keys to ints, so cast
            // back before comparing against the (string) participant IDs
            if (!in_array((string) $participantId, $participantIds, true)) {
                throw new InvalidArgumentException(
                    "Score references participant {$participantId} who is not in the event"
                );
            }
        }
    }

    /**
     * Get the event this result belongs to.
     */
    public function getEvent(): Event
    {
        return $this->event;
    }

    /**
     * Get the winning participant, or null if the event was drawn.
     */
    public function getWinner(): ?Participant
    {
        return $this->winner;
    }

    /**
     * Check if the event was drawn.
     */
    public function isDraw(): bool
    {
        return $this->winner === null;
    }

    /**
     * Check if the given participant won this event.
     */
    public function isWinFor(Participant $participant): bool
    {
        return $this->winner !== null && $this->winner->getId() === $participant->getId();
    }

    /**
     * Get all recorded scores keyed by participant ID.
     *
     * @return array<int|string, int|float>
     */
    public function getScores(): array
    {
        return $this->scores;
    }

    /**
     * Get the recorded score for a participant, or null if none was recorded.
     */
    public function getScoreFor(Participant $participant): int|float|null
    {
        return $this->scores[$participant->getId()] ?? null;
    }
}
