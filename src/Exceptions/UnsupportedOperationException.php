<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

use Override;

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
        parent::__construct($message, 0, $previous);
    }

    #[Override]
    public function getDiagnosticReport(): string
    {
        $report = "Unsupported Operation\n";
        $report .= "=====================\n\n";
        $report .= "Error: {$this->getMessage()}\n\n";
        $report .= "Suggestions:\n";
        $report .= "- Check if this scheduler supports the operation you are attempting\n";
        $report .= "- Use supportsCompleteGeneration() to check capabilities before calling generateSchedule()\n";
        $report .= "- Consider using generateRound() for round-by-round generation instead\n";

        return $report;
    }
}
