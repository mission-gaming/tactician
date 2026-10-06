<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

/**
 * Fluent builder of a {@see ConstraintSet}, returned by
 * `ConstraintSet::create()`.
 *
 * @experimental
 */
class ConstraintSetBuilder
{
    /** @var array<ConstraintInterface> */
    private array $constraints = [];

    /**
     * Append a constraint. Constraints are asked in the order they were
     * added, and nothing is deduplicated: one added twice is asked twice.
     *
     * @return $this The same builder, changed
     */
    public function add(ConstraintInterface $constraint): self
    {
        $this->constraints[] = $constraint;

        return $this;
    }

    /**
     * Append a {@see NoRepeatPairings} constraint.
     *
     * @param bool $acrossLegs False forbids a pairing twice within one leg; true forbids it twice
     *                         anywhere, which no round robin of two or more legs can satisfy
     *
     * @return $this The same builder, changed
     */
    public function noRepeatPairings(bool $acrossLegs = false): self
    {
        return $this->add(new NoRepeatPairings($acrossLegs));
    }

    /**
     * Append a {@see CallableConstraint} around a predicate of your own,
     * which returns true to accept the candidate event and is held to the
     * contract of {@see ConstraintInterface}.
     *
     * Constraints added this way with the default name are all reported
     * under that one name, so name each one that has to be told apart in a
     * failure report.
     *
     * @param callable(\MissionGaming\Tactician\DTO\Event, \MissionGaming\Tactician\Scheduling\SchedulingContext): bool $predicate
     *
     * @return $this The same builder, changed
     */
    public function custom(callable $predicate, string $name = 'Custom Constraint'): self
    {
        return $this->add(new CallableConstraint($predicate, $name));
    }

    /**
     * A set of the constraints added so far, in the order they were added.
     *
     * The builder can go on being used: a constraint added afterwards is in
     * the next set built, not in this one.
     */
    public function build(): ConstraintSet
    {
        return new ConstraintSet($this->constraints);
    }
}
