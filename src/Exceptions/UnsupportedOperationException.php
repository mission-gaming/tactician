<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

/**
 * Exception thrown when an operation is not supported by a scheduler.
 *
 * For example, when attempting to generate a complete schedule for a
 * standings-based Swiss tournament that requires round-by-round generation.
 */
class UnsupportedOperationException extends SchedulingException
{
    public function __construct(
        string $message = 'This operation is not supported by this scheduler',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, [], $previous);
    }
}
