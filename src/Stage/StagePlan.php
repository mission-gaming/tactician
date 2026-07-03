<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Schedule;

/**
 * An algorithm's declaration of the shape of one stage.
 *
 * A stage is Tactician's unit of work: participants in, a schedule (or
 * round-by-round pairings) out. The plan is constructed by the scheduler or
 * engine before generation and carried everywhere the stage's shape is
 * needed — context, validation, diagnostics, and constraints consume this
 * declaration instead of inferring shape from round-robin formulas.
 *
 * Nullability carries two distinct meanings, stated per accessor:
 * "the concept does not apply to this format" (legs for Swiss or brackets)
 * versus "unknowable up front" (total rounds for a double-elimination
 * bracket with a possible grand-final reset). Plans never fabricate shape
 * facts: a fabricated-but-plausible value flowing into downstream
 * arithmetic is exactly the silent-wrongness bug class this abstraction
 * exists to remove. A consumer wanting a display default writes `?? 1` at
 * its own edge, where context justifies it.
 */
interface StagePlan
{
    /**
     * Stable string identifier for the algorithm, e.g. 'round-robin'.
     *
     * Consuming platforms map configuration to library behaviour through
     * these identifiers, so they are part of the public contract and must
     * not change between releases.
     */
    public function getAlgorithm(): string;

    /**
     * Total rounds the stage will contain.
     *
     * Null means unknowable up front, not "no rounds".
     */
    public function getTotalRounds(): ?int;

    /**
     * The number of legs, for formats that have them.
     *
     * Null means the legs concept does not apply to this format (Swiss,
     * brackets). Formats that have legs always return an integer — a
     * single-leg round robin reports 1. When non-null,
     * legs × roundsPerLeg = totalRounds holds.
     */
    public function getLegs(): ?int;

    /**
     * Rounds per leg, for formats that have legs.
     *
     * Null precisely when getLegs() is null: the concept does not apply.
     */
    public function getRoundsPerLeg(): ?int;

    /**
     * Expected total events for the complete stage.
     *
     * Null means unknowable up front, not zero.
     */
    public function getExpectedEventCount(): ?int;

    /**
     * Format-specific integrity validation of a complete schedule.
     *
     * Returns human-readable violation descriptions; an empty array means
     * the schedule matches this plan's declared shape.
     *
     * @return array<string>
     */
    public function validateIntegrity(Schedule $schedule): array;
}
