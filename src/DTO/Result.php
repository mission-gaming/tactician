<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Serialization\PlainData;

/**
 * The outcome of an event: who won, or that nobody did, and optionally a
 * score per participant.
 *
 * The winner is what the library counts. Scores are recorded beside it and
 * are never used to work out who won: a result without a winner is a draw
 * whatever its scores say. An event with no Result has no outcome yet:
 * there is no result that says "not played and not decided". Results are
 * immutable.
 *
 * A result is of an event that was played, unless it carries an Award: then
 * the event was not played and the application gave it this outcome (see
 * isAwarded()). An awarded result is a result in every other respect. It
 * counts for the winner it names and against the other participants of the
 * event, or as a draw when it names none. It is the one result of its
 * event: an event has a played result or an awarded one, never both. And
 * where a result settles something (who advances from a knockout tie,
 * whether an event of a round still awaits its outcome) an awarded result
 * settles it as a played one does. Two things a standings calculator does
 * with an awarded result are the caller's choice
 * (Standings\AwardedResultPolicy): whether its scores count, and whether a
 * tiebreaker computed between the tied participants only reads it.
 *
 * @api
 */
readonly class Result
{
    /**
     * The winner and the scores are not checked against each other, and
     * scores need not cover every participant of the event.
     *
     * @param Event $event The event the result is of. Pass the event the scheduler or engine
     *                     produced, or one equal to it: an event is recognised by its round
     *                     number, its participants and its tie leg, and one with no round by
     *                     the object alone
     * @param Participant|null $winner The winning participant, or null for a draw
     * @param array<int|string, int|float> $scores Optional numeric scores keyed by participant ID
     *                                             (numeric-string IDs become int keys in PHP)
     * @param array<string, mixed> $metadata Free-form annotations. The library reads one key,
     *                                       'tie_winner': the ID of the participant who advances
     *                                       from an elimination tie that finished level (see
     *                                       Stage\TieDecision)
     * @param Award|null $award The mark of an awarded result: the event was not played. The
     *                          scores are then the nominal score of the award, where the
     *                          application gives one. Null for a result of an event that
     *                          was played
     *
     * @throws InvalidInputException When the winner or a score references a participant not in the event
     */
    public function __construct(
        private Event $event,
        private ?Participant $winner = null,
        private array $scores = [],
        private array $metadata = [],
        private ?Award $award = null
    ) {
        if ($winner !== null && !$event->hasParticipant($winner)) {
            throw new InvalidInputException('Winner must be a participant in the event');
        }

        $participantIds = array_map(
            fn(Participant $participant) => $participant->getId(),
            $event->getParticipants()
        );
        foreach (array_keys($scores) as $participantId) {
            // PHP canonicalizes numeric-string array keys to ints, so cast
            // back before comparing against the (string) participant IDs
            if (!in_array((string) $participantId, $participantIds, true)) {
                throw new InvalidInputException(
                    "Score references participant {$participantId} who is not in the event"
                );
            }
        }
    }

    /**
     * The event that was played, the same object the constructor was given.
     */
    public function getEvent(): Event
    {
        return $this->event;
    }

    /**
     * The winning participant, or null when the event was drawn.
     */
    public function getWinner(): ?Participant
    {
        return $this->winner;
    }

    /**
     * Whether the result has no winner. The scores are not consulted.
     */
    public function isDraw(): bool
    {
        return $this->winner === null;
    }

    /**
     * Whether the participant with this ID is the winner. False for a draw
     * and for a participant who is not in the event.
     */
    public function isWinFor(Participant $participant): bool
    {
        return $this->winner !== null && $this->winner->getId() === $participant->getId();
    }

    /**
     * The recorded scores keyed by participant ID, as given: empty when none
     * were recorded, and an ID that is a numeric string is an int key.
     *
     * @return array<int|string, int|float>
     */
    public function getScores(): array
    {
        return $this->scores;
    }

    /**
     * The recorded score of the participant with this ID, or null when none
     * was recorded for it (including a participant who is not in the event).
     */
    public function getScoreFor(Participant $participant): int|float|null
    {
        return $this->scores[$participant->getId()] ?? null;
    }

    /**
     * Whether the result was awarded: the event was not played, and the
     * application gave it this outcome. The library records that and never
     * decides it.
     */
    public function isAwarded(): bool
    {
        return $this->award instanceof Award;
    }

    /**
     * The mark of an awarded result, with the label and the reason the
     * application gave it, or null for a result of an event that was played.
     */
    public function getAward(): ?Award
    {
        return $this->award;
    }

    /**
     * The metadata as given to the constructor.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Whether the metadata has the key, including a key whose value is null.
     */
    public function hasMetadata(string $key): bool
    {
        return array_key_exists($key, $this->metadata);
    }

    /**
     * The metadata value under the key, or the default.
     *
     * The default is also returned for a key that exists with the value
     * null; use hasMetadata() to tell the two apart.
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Convert this result to a serializable array.
     *
     * The event is embedded in its own array form (participants referenced
     * by ID); pair with a participant registry to rehydrate. The first key
     * is `format_version`, the version of this form (1); the event is
     * written without one.
     *
     * The `award` key is present only for an awarded result (see
     * isAwarded()), so the array of a played result has no key but the
     * version and the four it has always had. The award is written without
     * a version of its own.
     *
     * @return array{format_version: int, event: array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}, winner: string|null, scores: array<int|string, int|float>, metadata: array<string, mixed>, award?: array{label: string|null, reason: string|null}}
     */
    public function toArray(): array
    {
        $event = $this->event->toArray();
        unset($event[PlainData::VERSION_KEY]);

        $data = [
            PlainData::VERSION_KEY => PlainData::VERSION,
            'event' => $event,
            'winner' => $this->winner?->getId(),
            'scores' => $this->scores,
            'metadata' => $this->metadata,
        ];

        if ($this->award instanceof Award) {
            $award = $this->award->toArray();
            unset($award[PlainData::VERSION_KEY]);
            $data['award'] = $award;
        }

        return $data;
    }

    /**
     * Recreate a result from the array form toArray() produces.
     *
     * The event is rebuilt from the data, so the result holds a new Event
     * object that is equal to the original and is not the same object. A
     * missing 'winner' is a draw; missing 'scores' and 'metadata' are empty.
     * A missing 'award', or one that is null, is a result of an event that
     * was played, which is every result stored before the key existed; an
     * array under it, the empty one included, is an awarded result.
     * Any other key is refused, so that a misspelt 'winner' is not silently
     * read as a draw, nor a misspelt 'award' as a result that was played.
     *
     * @param array<string, mixed> $data
     * @param array<string, Participant> $participantsById Every participant the data may reference, keyed by ID
     *
     * @throws InvalidInputException When the format version is not one this release reads,
     *                               a key is unknown, a field has the wrong type, a score is not an int
     *                               or a float, a participant ID is not in the registry,
     *                               the winner or a score names a participant who is
     *                               not in the event, or the award is not an array of a
     *                               label and a reason
     */
    public static function fromArray(array $data, array $participantsById): self
    {
        PlainData::checkFields($data, ['event', 'winner', 'scores', 'metadata', 'award'], 'result data');

        $eventData = $data['event'] ?? null;
        if (!is_array($eventData)) {
            throw new InvalidInputException('Result data requires an event array');
        }
        /** @var array<string, mixed> $eventData */
        $event = Event::fromArray($eventData, $participantsById);

        $winnerId = $data['winner'] ?? null;
        if ($winnerId !== null && !is_string($winnerId)) {
            throw new InvalidInputException('Result winner must be a participant ID or null');
        }
        $winner = null;
        if ($winnerId !== null) {
            if (!isset($participantsById[$winnerId])) {
                throw new InvalidInputException("Result references unknown winner {$winnerId}");
            }
            $winner = $participantsById[$winnerId];
        }

        $rawScores = $data['scores'] ?? [];
        if (!is_array($rawScores)) {
            throw new InvalidInputException('Result scores must be an array');
        }
        $scores = [];
        foreach ($rawScores as $participantId => $score) {
            if (!is_int($score) && !is_float($score)) {
                throw new InvalidInputException('Result scores must be numeric');
            }
            $scores[$participantId] = $score;
        }

        $rawMetadata = $data['metadata'] ?? [];
        if (!is_array($rawMetadata)) {
            throw new InvalidInputException('Result metadata must be an array');
        }
        $metadata = [];
        foreach ($rawMetadata as $key => $value) {
            $metadata[(string) $key] = $value;
        }

        $rawAward = $data['award'] ?? null;
        if ($rawAward !== null && !is_array($rawAward)) {
            throw new InvalidInputException('Result award must be an array or null');
        }

        return new self($event, $winner, $scores, $metadata, $rawAward === null ? null : Award::fromArray($rawAward));
    }
}
