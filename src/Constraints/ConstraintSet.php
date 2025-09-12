<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

readonly class ConstraintSet
{
    /**
     * @param array<ConstraintInterface> $constraints
     */
    public function __construct(private array $constraints = [])
    {
    }

    public static function create(): ConstraintSetBuilder
    {
        return new ConstraintSetBuilder();
    }

    /**
     * Check if an event satisfies all constraints.
     */
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        foreach ($this->constraints as $constraint) {
            if (!$constraint->isSatisfied($event, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<ConstraintInterface>
     */
    public function getConstraints(): array
    {
        return $this->constraints;
    }

    public function isEmpty(): bool
    {
        return empty($this->constraints);
    }

    public function count(): int
    {
        return count($this->constraints);
    }
}
