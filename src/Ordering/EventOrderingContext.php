<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Ordering;

use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Context information for ordering participants within a specific event.
 *
 * This value object provides all the information a ParticipantOrderer needs to make
 * deterministic ordering decisions, including the round, event position, leg information,
 * and full tournament context.
 */
readonly class EventOrderingContext
{
    /**
     * Create a new event ordering context.
     *
     * @param int $roundNumber The round number this event belongs to (1-based)
     * @param int $eventIndexInRound The index of this event within its round (0-based)
     * @param int|null $leg The leg number for multi-leg tournaments (null for single-leg)
     * @param SchedulingContext $schedulingContext The full tournament scheduling context
     */
    public function __construct(
        public int $roundNumber,
        public int $eventIndexInRound,
        public ?int $leg,
        public SchedulingContext $schedulingContext
    ) {
    }
}
