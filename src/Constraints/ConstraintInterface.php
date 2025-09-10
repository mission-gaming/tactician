<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

interface ConstraintInterface
{
    /**
     * Validate if an event satisfies this constraint
     * 
     * @return bool True if constraint is satisfied, false otherwise
     */
    public function isSatisfied(Event $event, SchedulingContext $context): bool;
    
    /**
     * Get a descriptive name for this constraint
     */
    public function getName(): string;
}
