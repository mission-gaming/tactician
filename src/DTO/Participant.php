<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

use MissionGaming\Tactician\Exceptions\InvalidInputException;

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
     * Nothing is validated here. The schedulers and StageState::start()
     * reject a field in which two participants share an ID.
     *
     * @param string $id The identity. Use a non-empty string: fromArray() refuses an empty one
     * @param string $label Display name; also orders level entries of a standings table
     * @param int|null $seed Seeding number, lower is better; null for unseeded. Stages seed
     *                       entrants from their position in the list, not from this
     * @param array<string, mixed> $metadata Free-form data; MetadataConstraint reads the key it is given
     */
    public function __construct(
        private string $id,
        private string $label,
        private ?int $seed = null,
        private array $metadata = []
    ) {}

    /**
     * The ID that identifies this participant.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * The display name.
     */
    public function getLabel(): string
    {
        return $this->label;
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
     * Useful when reseeding for a new tournament stage (e.g. group qualifiers
     * entering a knockout bracket). Identity is preserved: the copy has the
     * same ID, so existing events and results still match it.
     */
    public function withSeed(?int $seed): self
    {
        return new self($this->id, $this->label, $seed, $this->metadata);
    }

    /**
     * The plain-data form fromArray() accepts.
     *
     * The metadata is included as it is; whether it can be encoded (as
     * JSON, say) is up to the values in it.
     *
     * @return array{id: string, label: string, seed: int|null, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'seed' => $this->seed,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Recreate a participant from the array form toArray() produces.
     *
     * 'id' and 'label' are required; a missing 'seed' is null and a missing
     * 'metadata' is empty. Other keys are ignored.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidInputException When the ID is not a non-empty string, the label is not
     *                               a string, the seed is neither an integer nor null, or
     *                               the metadata is not an array
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $label = $data['label'] ?? null;
        if (!is_string($id) || $id === '' || !is_string($label)) {
            throw new InvalidInputException('Participant data requires a non-empty string id and a string label');
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
