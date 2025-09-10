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

    public function custom(callable $predicate, string $name = 'Custom Constraint'): self
    {
        return $this->add(new CallableConstraint($predicate, $name));
    }

    public function build(): ConstraintSet
    {
        return new ConstraintSet($this->constraints);
    }
}

class CallableConstraint implements ConstraintInterface
{
    public function __construct(
        private $predicate,
        private string $name
    ) {
    }

    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        return ($this->predicate)($event, $context);
    }

    public function getName(): string
    {
        return $this->name;
    }
}
