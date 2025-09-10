<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\DTO\Participant;

interface SchedulerInterface
{
    /**
     * Generate a schedule for the given participants
     * 
     * @param array<Participant> $participants
     */
    public function schedule(array $participants): Schedule;
}
