<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

class ConstraintSetBuilder
{
    /** @var array<ConstraintInterface> */
    private array $constraints = [];

    public function add(ConstraintInterface $constraint): self
    {
        $this->constraints[] = $constraint;

        return $this;
    }

    public function noRepeatPairings(): self
    {
        return $this->add(new NoRepeatPairings());
    }

    /**
     * @param callable(\MissionGaming\Tactician\DTO\Event, \MissionGaming\Tactician\Scheduling\SchedulingContext): bool $predicate
     */
    public function custom(callable $predicate, string $name = 'Custom Constraint'): self
    {
        return $this->add(new CallableConstraint($predicate, $name));
    }

    public function build(): ConstraintSet
    {
        return new ConstraintSet($this->constraints);
    }
}
