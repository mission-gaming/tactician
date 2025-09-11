<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Validation;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;

/**
 * Trait providing reusable validation functionality for schedulers.
 *
 * This trait adds schedule completeness validation to scheduler classes,
 * ensuring that generated schedules meet expected event counts and
 * providing detailed diagnostics when they don't.
 */
trait ValidatesScheduleCompleteness
{
    protected ConstraintViolationCollector $violationCollector;
    protected ScheduleValidator $validator;

    /**
     * Initialize the validation components.
     */
    protected function initializeValidation(): void
    {
        $this->violationCollector = new ConstraintViolationCollector();
        $this->validator = new ScheduleValidator();
    }

    /**
     * Get the constraint violation collector.
     */
    public function getViolationCollector(): ConstraintViolationCollector
    {
        return $this->violationCollector;
    }

    /**
     * Validate that the generated schedule is complete.
     *
     * @param array<Participant> $participants
     * @throws IncompleteScheduleException if the schedule is incomplete
     */
    protected function validateGeneratedSchedule(
        Schedule $schedule,
        array $participants,
        int $legs
    ): void {
        $expectedEventCalculator = $this->getExpectedEventCalculator();
        $expectedEventCount = $expectedEventCalculator->calculateExpectedEvents($participants, $legs);

        $this->validator->validateScheduleCompleteness(
            $schedule,
            $expectedEventCount,
            $this->violationCollector,
            $expectedEventCalculator,
            $participants,
            $legs
        );
    }

    /**
     * Record a constraint violation during scheduling.
     */
    protected function recordViolation(ConstraintViolation $violation): void
    {
        $this->violationCollector->recordViolation($violation);
    }

    /**
     * Clear all recorded violations (useful between scheduling attempts).
     */
    protected function clearViolations(): void
    {
        // Reset the violation collector by creating a new instance
        $this->violationCollector = new ConstraintViolationCollector();
    }

    /**
     * Check if any violations have been recorded.
     */
    protected function hasViolations(): bool
    {
        return $this->violationCollector->hasViolations();
    }

    /**
     * Get the count of recorded violations.
     */
    protected function getViolationCount(): int
    {
        return $this->violationCollector->getViolationCount();
    }

    /**
     * Abstract method that must be implemented by classes using this trait.
     * Should return the appropriate event calculator for the scheduling algorithm.
     */
    abstract public function getExpectedEventCalculator(): ExpectedEventCalculator;
}
