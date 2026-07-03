<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

/**
 * A time-aware rule validated over an assigned timeline.
 *
 * Assignment is deterministic slot arithmetic, so a violated time rule
 * cannot be routed around — it can only be reported, loudly. Rules judge
 * the already-determined mapping of events to kickoffs; they are
 * deliberately not generation constraints (ConstraintInterface), which
 * filter pairings during a search before any time exists.
 *
 * Rules validate any ScheduledSchedule: the assigner's whole-schedule
 * output, or a view the application accumulates round by round when
 * driving a results-driven stage.
 */
interface TimelineRule
{
    /**
     * Human-readable rule name for diagnostics.
     */
    public function getName(): string;

    /**
     * Validate an assigned timeline.
     *
     * @return array<string> Human-readable violation descriptions; empty means the rule holds
     */
    public function validate(ScheduledSchedule $scheduled): array;
}
