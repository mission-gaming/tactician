<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Validation;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Stage\StagePlan;

/**
 * Trait providing reusable validation functionality for schedulers.
 *
 * This trait adds schedule completeness validation to scheduler classes,
 * ensuring that generated schedules match their stage plan and providing
 * detailed diagnostics when they don't.
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
     * Validate that the generated schedule matches its stage plan.
     *
     * @param array<Participant> $participants
     * @throws IncompleteScheduleException if the schedule is incomplete
     */
    protected function validateGeneratedSchedule(
        Schedule $schedule,
        array $participants,
        StagePlan $plan
    ): void {
        $this->validator->validateScheduleCompleteness(
            $schedule,
            $plan,
            $this->violationCollector,
            $participants
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
}
