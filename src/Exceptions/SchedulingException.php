<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

use Exception;

class SchedulingException extends Exception
{
    public static function invalidParticipantCount(int $count): self
    {
        return new self("Invalid participant count: {$count}. Must be at least 2.");
    }

    public static function constraintViolation(string $constraint): self
    {
        return new self("Constraint violation: {$constraint}");
    }

    public static function invalidSchedule(string $reason): self
    {
        return new self("Invalid schedule: {$reason}");
    }
}
