<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Positioning;

/**
 * Represents a position reference in a tournament.
 *
 * Positions are abstract references (e.g., "Seed 1", "Standing 3") that
 * are later resolved to actual participants based on tournament context.
 *
 * This enables tournament structures to be defined independently of
 * participant assignment, supporting both predetermined schedules and
 * dynamic scheduling based on results.
 */
readonly class Position
{
    /**
     * @param PositionType $type The type of position (seed, standing, etc.)
     * @param int $value The position value (1-based)
     * @param int|null $roundContext For STANDING_AFTER_ROUND, which round's standings to use
     */
    public function __construct(
        private PositionType $type,
        private int $value,
        private ?int $roundContext = null
    ) {
        if ($value < 1) {
            throw new \InvalidArgumentException('Position value must be at least 1');
        }

        if ($type === PositionType::STANDING_AFTER_ROUND && $roundContext === null) {
            throw new \InvalidArgumentException('Round context required for STANDING_AFTER_ROUND position type');
        }
    }

    public function getType(): PositionType
    {
        return $this->type;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function getRoundContext(): ?int
    {
        return $this->roundContext;
    }

    /**
     * Get a human-readable string representation of this position.
     */
    #[\Override]
    public function __toString(): string
    {
        return match ($this->type) {
            PositionType::SEED => "Seed {$this->value}",
            PositionType::STANDING => "Standing {$this->value}",
            PositionType::STANDING_AFTER_ROUND => "Standing {$this->value} (after round {$this->roundContext})",
        };
    }

    /**
     * Check if this position can be resolved at tournament start.
     * SEED positions can be resolved immediately.
     * STANDING positions require results/standings data.
     */
    public function isStaticallyResolvable(): bool
    {
        return $this->type === PositionType::SEED;
    }
}
