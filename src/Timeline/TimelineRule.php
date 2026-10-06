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
 *
 * @experimental
 */
interface TimelineRule
{
    /**
     * Human-readable rule name for diagnostics. The assigner prefixes each
     * of the rule's violations with it, in square brackets.
     */
    public function getName(): string;

    /**
     * Validate an assigned timeline.
     *
     * An implementation reports and does not throw: the assigner collects
     * the descriptions of every rule and throws once, with all of them.
     * Kickoffs are UTC instants (ScheduledEvent::getKickoff()). The answer
     * should depend on the scheduled events alone, so that the same
     * timeline always gets the same report.
     *
     * @return array<string> Human-readable violation descriptions; empty means the rule holds
     */
    public function validate(ScheduledSchedule $scheduled): array;
}
