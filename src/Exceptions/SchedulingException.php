<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

use Exception;

abstract class SchedulingException extends Exception
{
    /**
     * Get a diagnostic report with detailed information about the scheduling issue.
     * This should provide actionable information to help resolve the problem.
     */
    abstract public function getDiagnosticReport(): string;

    public static function invalidParticipantCount(int $count): self
    {
        return new InvalidConfigurationException(
            "Invalid participant count: {$count}. Must be at least 2.",
            ['participant_count' => $count, 'minimum_required' => 2]
        );
    }

    public static function constraintViolation(string $constraint): self
    {
        return new InvalidConfigurationException(
            "Constraint violation: {$constraint}",
            ['constraint' => $constraint]
        );
    }

    public static function invalidSchedule(string $reason): self
    {
        return new InvalidConfigurationException(
            "Invalid schedule: {$reason}",
            ['reason' => $reason]
        );
    }
}
