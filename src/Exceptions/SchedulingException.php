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
 *
 * @api
 */
abstract class SchedulingException extends Exception implements TacticianException
{
    /**
     * The failure as several lines of text for an operator or a log: what
     * was asked for, what went wrong and, where the exception can tell,
     * what to change. The lines are separated by "\n".
     *
     * The text is for people. Its wording and layout are not stable, so
     * code should branch on the exception's class and accessors, not on
     * the report.
     */
    abstract public function getDiagnosticReport(): string;

    /**
     * An `InvalidConfigurationException` whose message states the count
     * and that at least 2 participants are required, whatever the count
     * is. It is returned, not thrown.
     *
     * @deprecated since 0.2.2, removed in 1.0.0. Nothing in the library calls
     *             it. Construct an `InvalidConfigurationException` with
     *             `InvalidConfigurationReason::TooFewParticipants` instead.
     */
    #[\Deprecated(message: 'construct an InvalidConfigurationException with an InvalidConfigurationReason instead', since: '0.2.2')]
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

    /**
     * An `InvalidConfigurationException` that names a violated constraint.
     * It is returned, not thrown.
     *
     * @deprecated since 0.2.2, removed in 1.0.0. Nothing in the library calls
     *             it. Construct an `InvalidConfigurationException` with
     *             `InvalidConfigurationReason::ConstraintViolation` instead.
     */
    #[\Deprecated(message: 'construct an InvalidConfigurationException with an InvalidConfigurationReason instead', since: '0.2.2')]
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

    /**
     * An `InvalidConfigurationException` for a schedule that is not valid,
     * with the reason as text. It is returned, not thrown.
     *
     * @deprecated since 0.2.2, removed in 1.0.0. Nothing in the library calls
     *             it. Construct an `InvalidConfigurationException` with
     *             `InvalidConfigurationReason::InvalidSchedule` instead.
     */
    #[\Deprecated(message: 'construct an InvalidConfigurationException with an InvalidConfigurationReason instead', since: '0.2.2')]
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
