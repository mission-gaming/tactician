<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Validation;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;

/**
 * Optional algorithm-specific validation beyond simple event counts.
 */
interface ScheduleIntegrityValidator
{
    /**
     * @param array<Participant> $participants
     * @return array<string>
     */
    public function validateScheduleIntegrity(
        Schedule $schedule,
        array $participants,
        ScheduleValidationContext $context
    ): array;
}
