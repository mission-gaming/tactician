<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Positioning;

/**
 * Types of positions that can be referenced in a tournament.
 *
 * Positions are abstract references that are later resolved to actual participants
 * based on tournament context (seeding, standings, etc.).
 */
enum PositionType
{
    /**
     * Position based on initial seeding.
     * Example: "Seed 1", "Seed 2".
     */
    case SEED;

    /**
     * Position based on current standings.
     * Example: "Current leader", "2nd place".
     */
    case STANDING;

    /**
     * Position based on standings after a specific round.
     * Example: "Leader after Round 3", "2nd place after Round 1".
     */
    case STANDING_AFTER_ROUND;
}
