<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

use MissionGaming\Tactician\Exceptions\InvalidInputException;

/**
 * A round: a set of events played concurrently, identified by its number.
 *
 * Round numbers are 1-based and, in a schedule of several legs, continue
 * across the legs. Two rounds are the same round when their numbers are
 * equal; metadata plays no part in the comparison. Rounds are immutable.
 *
 * @api
 */
readonly class Round implements \Stringable
{
    /**
     * @param int $number The round number, 1 or higher
     * @param array<string, mixed> $metadata Free-form data. The library reads none of it; the
     *                                       elimination engines set 'label' to the name of the
     *                                       bracket stage
     *
     * @throws InvalidInputException When the round number is below 1
     */
    public function __construct(
        private int $number,
        private array $metadata = []
    ) {
        if ($number <= 0) {
            throw new InvalidInputException('Round number must be positive');
        }
    }

    /**
     * The 1-based round number.
     *
     * @return int Always 1 or higher
     */
    public function getNumber(): int
    {
        return $this->number;
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
     * The metadata value under the key, or the default when the key is
     * absent.
     *
     * A key that exists with the value null returns null, not the default.
     * (Event, Participant, Result and Schedule return the default there.)
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->metadata) ? $this->metadata[$key] : $default;
    }

    /**
     * Whether the two rounds have the same number. Metadata is not compared.
     */
    public function equals(Round $other): bool
    {
        return $this->number === $other->number;
    }

    /**
     * Whether this round's number is lower than the other's.
     */
    public function isBefore(Round $other): bool
    {
        return $this->number < $other->number;
    }

    /**
     * Whether this round's number is higher than the other's.
     */
    public function isAfter(Round $other): bool
    {
        return $this->number > $other->number;
    }

    /**
     * "Round" and the number, for example "Round 3". The metadata, a label
     * included, is not part of it.
     */
    #[\Override]
    public function __toString(): string
    {
        return "Round {$this->number}";
    }

    /**
     * The plain-data form fromArray() accepts.
     *
     * @return array{number: int, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Recreate a round from the array form toArray() produces.
     *
     * 'number' is required; a missing 'metadata' is empty.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidInputException When the number is missing, not an integer or below 1,
     *                               or the metadata is not an array
     */
    public static function fromArray(array $data): self
    {
        $number = $data['number'] ?? null;
        if (!is_int($number)) {
            throw new InvalidInputException('Round data requires an integer number');
        }

        $rawMetadata = $data['metadata'] ?? [];
        if (!is_array($rawMetadata)) {
            throw new InvalidInputException('Round metadata must be an array');
        }
        $metadata = [];
        foreach ($rawMetadata as $key => $value) {
            $metadata[(string) $key] = $value;
        }

        return new self($number, $metadata);
    }
}
