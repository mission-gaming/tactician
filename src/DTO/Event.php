<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\UnavailableValueException;
use MissionGaming\Tactician\Serialization\PlainData;

/**
 * One meeting of two or more participants, with the round it belongs to and
 * free-form metadata.
 *
 * The order of the participants is part of the event: it is the role each
 * one has (index 0 is first-named, index 1 second-named). An event is
 * immutable and has no identifier of its own: a result is recorded against
 * the event object a scheduler or an engine produced.
 *
 * @api
 */
readonly class Event
{
    /**
     * The participants are kept as given: the same order and the same keys.
     * They must differ from each other: a participant is not paired with
     * itself, and two objects with one ID are one participant. Pass a list
     * (keys 0, 1, ...): the library reads roles by index.
     *
     * @param array<Participant> $participants At least 2, each with an ID of its own,
     *                                         first-named participant first
     * @param Round|null $round The round the event is played in; null for an event not yet in a round
     * @param array<string, mixed> $metadata Free-form data. The library reads two keys. 'tie_leg'
     *                                       is set by the elimination engines on the two events
     *                                       of a two-legged tie, as the integer 1 or 2; a leg
     *                                       that is no integer, a string such as '2' included,
     *                                       is read as leg 1. 'bracket_tie' is set by the
     *                                       single elimination engine on the events of the last
     *                                       round of a bracket with a third-place tie, and is
     *                                       read through Stage\BracketTie::of(). Both are
     *                                       constants of Stage\FormatMetadataKey
     *
     * @throws InvalidInputException When fewer than 2 participants are provided, or one
     *                               participant ID is listed twice
     */
    public function __construct(
        private array $participants,
        private ?Round $round = null,
        private array $metadata = []
    ) {
        if (count($participants) < 2) {
            throw new InvalidInputException('An event must have at least 2 participants');
        }

        $listed = [];
        foreach ($participants as $participant) {
            $id = $participant->getId();
            if (isset($listed[$id])) {
                throw new InvalidInputException(
                    "An event cannot list participant {$id} twice: a participant is not paired with itself"
                );
            }
            $listed[$id] = true;
        }
    }

    /**
     * The participants in role order, as given to the constructor: the
     * first-named participant first.
     *
     * @return array<Participant>
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    /**
     * Whether a participant with the same ID is in this event.
     *
     * Participants are compared by ID alone, so a copy with another seed,
     * label or metadata still matches.
     */
    public function hasParticipant(Participant $participant): bool
    {
        return array_any($this->participants, fn($eventParticipant) => $eventParticipant->getId() === $participant->getId());
    }

    /**
     * The round the event is played in, or null when it has none.
     *
     * Every event a scheduler or an engine of the library produces has a
     * round; getRoundNumber() reads it without the null.
     */
    public function getRound(): ?Round
    {
        return $this->round;
    }

    /**
     * Whether the event has a round. True for every event a scheduler or an
     * engine of the library produces; false only for one built without a
     * round.
     */
    public function hasRound(): bool
    {
        return $this->round !== null;
    }

    /**
     * The 1-based number of the round the event is played in.
     *
     * The events of a round-robin schedule, and of every other schedule and
     * round pairing the library produces, always have a round, so this is
     * the round number with no null to handle. An event built without a
     * round has none to give: ask hasRound() first where such an event can
     * occur.
     *
     * @throws UnavailableValueException When the event has no round (unchecked: a mistake in
     *                                   the calling code, see hasRound())
     */
    public function getRoundNumber(): int
    {
        if ($this->round === null) {
            throw new UnavailableValueException(
                'The event has no round, so it has no round number: ask hasRound() first'
            );
        }

        return $this->round->getNumber();
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
     * The number of participants, always 2 or more.
     */
    public function getParticipantCount(): int
    {
        return count($this->participants);
    }

    /**
     * Convert this event to a serializable array.
     *
     * Participants are referenced by ID; pair with the participant list from
     * Schedule::toArray() to rehydrate. The first key is `format_version`,
     * the version of this form (1); the round is written without one, and
     * so is the event itself where another form holds it.
     *
     * @return array{format_version: int, participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        $round = null;
        if ($this->round instanceof Round) {
            $round = $this->round->toArray();
            unset($round[PlainData::VERSION_KEY]);
        }

        return [
            PlainData::VERSION_KEY => PlainData::VERSION,
            'participants' => array_map(
                fn(Participant $participant) => $participant->getId(),
                $this->participants
            ),
            'round' => $round,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Recreate an event from the array form toArray() produces.
     *
     * The participants are taken from the registry in the order the data
     * lists their IDs, so roles survive. A missing 'round' gives an event
     * without a round and a missing 'metadata' gives none. Any other key is
     * refused: data of your own belongs in the metadata.
     *
     * @param array<string, mixed> $data
     * @param array<string, Participant> $participantsById Every participant the data may reference, keyed by ID
     *
     * @throws InvalidInputException When the format version is not one this release reads,
     *                               a key is unknown, a field has the wrong type, a
     *                               participant ID is not in the registry, fewer than 2
     *                               participants are listed, or the round number is not a
     *                               positive integer
     */
    public static function fromArray(array $data, array $participantsById): self
    {
        PlainData::checkFields($data, ['participants', 'round', 'metadata'], 'event data');

        $participantIds = $data['participants'] ?? null;
        if (!is_array($participantIds)) {
            throw new InvalidInputException('Event data requires a participants array');
        }

        $participants = [];
        foreach ($participantIds as $participantId) {
            if (!is_string($participantId) || !isset($participantsById[$participantId])) {
                throw new InvalidInputException(
                    'Event references unknown participant ' . var_export($participantId, true)
                );
            }
            $participants[] = $participantsById[$participantId];
        }

        $roundData = $data['round'] ?? null;
        if ($roundData !== null && !is_array($roundData)) {
            throw new InvalidInputException('Event round must be an array or null');
        }
        /** @var array<string, mixed>|null $roundData */
        $round = $roundData === null ? null : Round::fromArray($roundData);

        $rawMetadata = $data['metadata'] ?? [];
        if (!is_array($rawMetadata)) {
            throw new InvalidInputException('Event metadata must be an array');
        }
        $metadata = [];
        foreach ($rawMetadata as $key => $value) {
            $metadata[(string) $key] = $value;
        }

        return new self($participants, $round, $metadata);
    }
}
