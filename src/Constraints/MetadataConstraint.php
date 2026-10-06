<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * A rule on one metadata key of the participants of an event, decided by a
 * callable. The factory methods cover the common rules.
 *
 * @experimental
 */
readonly class MetadataConstraint implements ConstraintInterface
{
    /**
     * @param string $metadataKey The participant metadata key the rule reads
     * @param mixed $validator A `callable(array $values, array $participants, Event $event,
     *                         SchedulingContext $context): bool`. `$values` holds the value of the
     *                         key for each participant of the candidate event, in the event's
     *                         order, with null for a participant that has no such key or holds
     *                         null under it. It must return a bool: any other type is a
     *                         `\TypeError` when the constraint is asked
     * @param string $name The name the constraint is reported under
     *
     * @throws InvalidInputException When the validator is not callable
     */
    public function __construct(
        private string $metadataKey,
        private mixed $validator,
        private string $name = 'Metadata Constraint'
    ) {
        if (!is_callable($validator)) {
            throw new InvalidInputException('Validator must be callable');
        }
    }

    /**
     * The validator's answer for the metadata values of the event's
     * participants. What the validator throws is not caught.
     */
    #[\Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        $participants = $event->getParticipants();
        $metadataValues = array_map(fn($p) => $p->getMetadataValue($this->metadataKey), $participants);

        // The constructor rejects a validator that is not callable; the
        // property stays `mixed` so that the constructor accepts what it
        // always has.
        $validator = $this->validator;
        assert(is_callable($validator));

        return $validator($metadataValues, $participants, $event, $context);
    }

    /**
     * The name given to the constructor, or the one a factory method built.
     */
    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Accepts an event when its participants that have a value under the
     * key all have the same one. Participants without a value (no such key,
     * or null) are left out, so an event with at most one valued
     * participant is always accepted.
     *
     * Values are compared as strings (`1` and `"1"` are the same value), so
     * they should be scalars.
     *
     * @param string|null $name The name to report under; null for `Same <key>`
     */
    public static function requireSameValue(string $metadataKey, ?string $name = null): self
    {
        return new self(
            $metadataKey,
            fn(array $values) => count(array_unique(array_filter($values, fn($v) => $v !== null))) <= 1,
            $name ?? "Same {$metadataKey}"
        );
    }

    /**
     * Accepts an event when no two of its participants that have a value
     * under the key have the same one. Participants without a value (no
     * such key, or null) are left out, so two of them may meet.
     *
     * Values are compared as strings (`1` and `"1"` are the same value), so
     * they should be scalars.
     *
     * @param string|null $name The name to report under; null for `Different <key>`
     */
    public static function requireDifferentValues(string $metadataKey, ?string $name = null): self
    {
        return new self(
            $metadataKey,
            fn(array $values) => count(array_unique(array_filter($values, fn($v) => $v !== null))) === count(array_filter($values, fn($v) => $v !== null)),
            $name ?? "Different {$metadataKey}"
        );
    }

    /**
     * Accepts an event when its participants hold at most `$maxUnique`
     * distinct values under the key, participants without a value left out.
     * The limit is not validated, and one at or above the number of
     * participants in an event rejects nothing.
     *
     * Values are compared as strings, so they should be scalars.
     *
     * @param string|null $name The name to report under; null for `Max <limit> <key> types`
     */
    public static function maxUniqueValues(string $metadataKey, int $maxUnique, ?string $name = null): self
    {
        return new self(
            $metadataKey,
            fn(array $values) => count(array_unique(array_filter($values, fn($v) => $v !== null))) <= $maxUnique,
            $name ?? "Max {$maxUnique} {$metadataKey} types"
        );
    }

    /**
     * Accepts an event when the numeric values its participants hold under
     * the key lie within 1 of each other: the largest minus the smallest is
     * at most 1, so equal values are accepted too. A value that is not
     * numeric (`is_numeric()`), a missing one included, is left out.
     *
     * @param string|null $name The name to report under; null for `Adjacent <key>`
     */
    public static function requireAdjacentValues(string $metadataKey, ?string $name = null): self
    {
        return new self(
            $metadataKey,
            function (array $values) {
                $numericValues = array_filter($values, is_numeric(...));
                if ($numericValues === []) {
                    return true;
                }

                return abs(max($numericValues) - min($numericValues)) <= 1;
            },
            $name ?? "Adjacent {$metadataKey}"
        );
    }
}
