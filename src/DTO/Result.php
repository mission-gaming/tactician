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
     * @param array<string, mixed> $metadata Additional result annotations (e.g. a two-legged
     *                                       tie decision the aggregate rules produced app-side)
     *
     * @throws InvalidArgumentException When the winner or a score references a participant not in the event
     */
    public function __construct(
        private Event $event,
        private ?Participant $winner = null,
        private array $scores = [],
        private array $metadata = []
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

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function hasMetadata(string $key): bool
    {
        return array_key_exists($key, $this->metadata);
    }

    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Convert this result to a serializable array.
     *
     * The event is embedded in its own array form (participants referenced
     * by ID); pair with a participant registry to rehydrate.
     *
     * @return array{event: array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}, winner: string|null, scores: array<int|string, int|float>, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'event' => $this->event->toArray(),
            'winner' => $this->winner?->getId(),
            'scores' => $this->scores,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Recreate a result from its array representation.
     *
     * @param array<string, mixed> $data
     * @param array<string, Participant> $participantsById Registry resolving participant IDs
     *
     * @throws InvalidArgumentException When fields are malformed or a participant ID is unknown
     */
    public static function fromArray(array $data, array $participantsById): self
    {
        $eventData = $data['event'] ?? null;
        if (!is_array($eventData)) {
            throw new InvalidArgumentException('Result data requires an event array');
        }
        /** @var array<string, mixed> $eventData */
        $event = Event::fromArray($eventData, $participantsById);

        $winnerId = $data['winner'] ?? null;
        if ($winnerId !== null && !is_string($winnerId)) {
            throw new InvalidArgumentException('Result winner must be a participant ID or null');
        }
        $winner = null;
        if ($winnerId !== null) {
            if (!isset($participantsById[$winnerId])) {
                throw new InvalidArgumentException("Result references unknown winner {$winnerId}");
            }
            $winner = $participantsById[$winnerId];
        }

        $rawScores = $data['scores'] ?? [];
        if (!is_array($rawScores)) {
            throw new InvalidArgumentException('Result scores must be an array');
        }
        $scores = [];
        foreach ($rawScores as $participantId => $score) {
            if (!is_int($score) && !is_float($score)) {
                throw new InvalidArgumentException('Result scores must be numeric');
            }
            $scores[$participantId] = $score;
        }

        $rawMetadata = $data['metadata'] ?? [];
        if (!is_array($rawMetadata)) {
            throw new InvalidArgumentException('Result metadata must be an array');
        }
        $metadata = [];
        foreach ($rawMetadata as $key => $value) {
            $metadata[(string) $key] = $value;
        }

        return new self($event, $winner, $scores, $metadata);
    }
}
