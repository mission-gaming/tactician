<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

class CallableConstraint implements ConstraintInterface
{
    /**
     * @param callable(Event, SchedulingContext): bool $predicate
     */
    public function __construct(
        private $predicate,
        private string $name
    ) {
    }

    #[\Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        return (bool) ($this->predicate)($event, $context);
    }

    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }
}
