<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * The constraints one generation runs under, checked together: an event is
 * acceptable when every constraint in the set is satisfied.
 *
 * @experimental
 */
readonly class ConstraintSet
{
    /**
     * @param array<ConstraintInterface> $constraints The constraints, in the order they are asked.
     *                                                The same constraint may be given twice; it is
     *                                                then asked twice
     */
    public function __construct(private array $constraints = []) {}

    /**
     * A new, empty builder: `ConstraintSet::create()->noRepeatPairings()->build()`.
     */
    public static function create(): ConstraintSetBuilder
    {
        return new ConstraintSetBuilder();
    }

    /**
     * Whether every constraint of the set accepts the event. An empty set
     * accepts every event.
     *
     * The constraints are asked in the order of the set, and the first one
     * that rejects the event ends the question: the ones after it are not
     * asked. What a constraint throws is not caught.
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
     * The constraints, in the order they are asked and under the keys of
     * the array the set was built from.
     *
     * @return array<ConstraintInterface>
     */
    public function getConstraints(): array
    {
        return $this->constraints;
    }

    /**
     * Whether the set holds no constraint.
     */
    public function isEmpty(): bool
    {
        return $this->constraints === [];
    }

    /**
     * How many constraints the set holds, one given twice counted twice.
     */
    public function count(): int
    {
        return count($this->constraints);
    }
}
