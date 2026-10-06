<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * A constraint whose rule is a callable the application supplies.
 *
 * @experimental
 */
class CallableConstraint implements ConstraintInterface
{
    /**
     * @param callable(Event, SchedulingContext): bool $predicate Returns true to accept the candidate
     *                                                            event. It is held to the contract of
     *                                                            {@see ConstraintInterface}: it may be
     *                                                            called more than once for an event, and
     *                                                            for events that are never scheduled
     * @param string $name The name the constraint is reported under
     */
    public function __construct(
        private $predicate,
        private readonly string $name
    ) {}

    /**
     * The predicate's answer for the event and the context, cast to bool.
     * What the predicate throws is not caught.
     */
    #[\Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        return (bool) ($this->predicate)($event, $context);
    }

    /**
     * The name given to the constructor.
     */
    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }
}
