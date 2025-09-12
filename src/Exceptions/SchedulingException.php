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
        $message = "Invalid participant count: {$count}. Must be at least 2.";

        return new InvalidConfigurationException(
            $message,
            ['participant_count' => $count, 'minimum_required' => 2],
            $message
        );
    }

    public static function constraintViolation(string $constraint): self
    {
        $message = "Constraint violation: {$constraint}";

        return new InvalidConfigurationException(
            $message,
            ['constraint' => $constraint],
            $message
        );
    }

    public static function invalidSchedule(string $reason): self
    {
        $message = "Invalid schedule: {$reason}";

        return new InvalidConfigurationException(
            $message,
            ['reason' => $reason],
            $message
        );
    }
}
