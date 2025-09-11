<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Flexible constraint that validates participant metadata using a callable.
 */
readonly class MetadataConstraint implements ConstraintInterface
{
    public function __construct(
        private string $metadataKey,
        private mixed $validator,
        private string $name = 'Metadata Constraint'
    ) {
        if (!is_callable($validator)) {
            throw new \InvalidArgumentException('Validator must be callable');
        }
    }

    #[\Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        $participants = $event->getParticipants();
        $metadataValues = array_map(fn ($p) => $p->getMetadataValue($this->metadataKey), $participants);

        return ($this->validator)($metadataValues, $participants, $event, $context);
    }

    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Factory method for same-value constraint.
     */
    public static function requireSameValue(string $metadataKey, ?string $name = null): self
    {
        return new self(
            $metadataKey,
            fn (array $values) => count(array_unique(array_filter($values, fn ($v) => $v !== null))) <= 1,
            $name ?? "Same {$metadataKey}"
        );
    }

    /**
     * Factory method for different-values constraint.
     */
    public static function requireDifferentValues(string $metadataKey, ?string $name = null): self
    {
        return new self(
            $metadataKey,
            fn (array $values) => count(array_unique(array_filter($values, fn ($v) => $v !== null))) === count(array_filter($values, fn ($v) => $v !== null)),
            $name ?? "Different {$metadataKey}"
        );
    }

    /**
     * Factory method for maximum unique values constraint.
     */
    public static function maxUniqueValues(string $metadataKey, int $maxUnique, ?string $name = null): self
    {
        return new self(
            $metadataKey,
            fn (array $values) => count(array_unique(array_filter($values, fn ($v) => $v !== null))) <= $maxUnique,
            $name ?? "Max {$maxUnique} {$metadataKey} types"
        );
    }

    /**
     * Factory method for adjacent numeric values constraint.
     */
    public static function requireAdjacentValues(string $metadataKey, ?string $name = null): self
    {
        return new self(
            $metadataKey,
            function (array $values) {
                $numericValues = array_filter($values, fn ($v) => is_numeric($v));
                if (empty($numericValues)) {
                    return true;
                }

                return abs(max($numericValues) - min($numericValues)) <= 1;
            },
            $name ?? "Adjacent {$metadataKey}"
        );
    }
}
