<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Serialization\PlainData;

/**
 * Anything that competes: a player, a club, a squad.
 *
 * A participant is identified by its ID alone. The library compares
 * participants by ID wherever it matches one against another, so two objects
 * with the same ID are the same participant whatever their label, seed or
 * metadata. Participants are immutable.
 *
 * @api
 */
readonly class Participant
{
    /**
     * The ID must not be empty; nothing else is validated here. An ID that
     * is not empty is taken as it is, whitespace and `'0'` included. The
     * schedulers and StageState::start() reject a field in which two
     * participants share an ID.
     *
     * @param string $id The identity: any string but the empty one
     * @param string|null $label Display name; the library orders nothing by it. Null, or left
     *                           out, for a participant with no name of its own: getLabel()
     *                           then gives the ID
     * @param int|null $seed Seeding number, lower is better; null for unseeded. Stages seed
     *                       entrants from their position in the list, not from this
     * @param array<string, mixed> $metadata Free-form data; MetadataConstraint reads the key it is given
     *
     * @throws InvalidInputException When the ID is the empty string
     */
    public function __construct(
        private string $id,
        private ?string $label = null,
        private ?int $seed = null,
        private array $metadata = []
    ) {
        if ($id === '') {
            throw new InvalidInputException('A participant ID cannot be empty');
        }
    }

    /**
     * The ID that identifies this participant.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * The display name: the label the participant was given, or its ID
     * where it was given none (hasLabel()). The library writes it wherever
     * it names a participant for a person to read (diagnostics, messages).
     */
    public function getLabel(): string
    {
        return $this->label ?? $this->id;
    }

    /**
     * Whether the participant was given a label of its own; false where
     * getLabel() gives the ID.
     */
    public function hasLabel(): bool
    {
        return $this->label !== null;
    }

    /**
     * The seeding number (lower is better), or null when unseeded.
     */
    public function getSeed(): ?int
    {
        return $this->seed;
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
     * Create a copy of this participant with a different seed.
     *
     * The copy has the same ID, label and metadata, so whatever matches
     * participants by ID (results, standings, the engines) still matches
     * it, the constraints included.
     *
     * The seed attribute does not place a participant in a stage: a
     * bracket, a pool distribution and a Swiss stage read list position,
     * so to reseed for a new stage, order the list. The attribute is read
     * by SeedProtectionConstraint and, between participants level on
     * everything else, by a standings table calculated on its own, with no
     * entrant list (StandingsCalculator).
     */
    public function withSeed(?int $seed): self
    {
        return new self($this->id, $this->label, $seed, $this->metadata);
    }

    /**
     * The plain-data form fromArray() accepts.
     *
     * The metadata is included as it is; whether it can be encoded (as
     * JSON, say) is up to the values in it. The first key is
     * `format_version`, the version of this form (1). A form that holds
     * participants (a schedule, a stage state) writes them without it: one
     * array has one version, at its top. The key `label` is written only
     * for a participant that was given one (hasLabel()), so a participant
     * with a label has the keys it has always had.
     *
     * @return array{format_version: int, id: string, label?: string, seed: int|null, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        $data = [PlainData::VERSION_KEY => PlainData::VERSION, 'id' => $this->id];
        if ($this->label !== null) {
            $data['label'] = $this->label;
        }
        $data['seed'] = $this->seed;
        $data['metadata'] = $this->metadata;

        return $data;
    }

    /**
     * Recreate a participant from the array form toArray() produces.
     *
     * 'id' is required. A missing or null 'label' is a participant without
     * one (hasLabel() is false), a missing 'seed' is null and a missing
     * 'metadata' is empty. Any other key is refused: data of your own
     * belongs in the metadata.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidInputException When the format version is not one this release reads,
     *                               a key is unknown, the ID is not a non-empty string,
     *                               the label is neither a string nor null, the seed is neither an
     *                               integer nor null, or the metadata is not an array
     */
    public static function fromArray(array $data): self
    {
        PlainData::checkFields($data, ['id', 'label', 'seed', 'metadata'], 'participant data');

        $id = $data['id'] ?? null;
        $label = $data['label'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new InvalidInputException('Participant data requires a non-empty string id, and a string label when it has one');
        }
        if ($label !== null && !is_string($label)) {
            throw new InvalidInputException('Participant label must be a string or null');
        }

        $seed = $data['seed'] ?? null;
        if ($seed !== null && !is_int($seed)) {
            throw new InvalidInputException('Participant seed must be an integer or null');
        }

        $rawMetadata = $data['metadata'] ?? [];
        if (!is_array($rawMetadata)) {
            throw new InvalidInputException('Participant metadata must be an array');
        }
        $metadata = [];
        foreach ($rawMetadata as $key => $value) {
            $metadata[(string) $key] = $value;
        }

        return new self($id, $label, $seed, $metadata);
    }
}
