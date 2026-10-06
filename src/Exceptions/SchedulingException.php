<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

use Exception;

/**
 * Base class of the scheduling failures: an invalid configuration, a
 * schedule that cannot be completed, a Swiss round with no valid pairing, a
 * repack that left violations. Each carries a diagnostic report.
 *
 * It is not the base of everything the library throws. A rejected argument
 * or malformed serialized data is an {@see InvalidInputException}, which is
 * not a `SchedulingException`. To catch every library exception with one
 * clause, catch {@see TacticianException}.
 */
abstract class SchedulingException extends Exception implements TacticianException
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
            $message,
            reason: InvalidConfigurationReason::TooFewParticipants,
            requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
        );
    }

    public static function constraintViolation(string $constraint): self
    {
        $message = "Constraint violation: {$constraint}";

        return new InvalidConfigurationException(
            $message,
            ['constraint' => $constraint],
            $message,
            reason: InvalidConfigurationReason::ConstraintViolation,
            requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
        );
    }

    public static function invalidSchedule(string $reason): self
    {
        $message = "Invalid schedule: {$reason}";

        return new InvalidConfigurationException(
            $message,
            ['reason' => $reason],
            $message,
            reason: InvalidConfigurationReason::InvalidSchedule,
            requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
        );
    }
}
