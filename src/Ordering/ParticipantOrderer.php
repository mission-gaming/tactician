<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Ordering;

use MissionGaming\Tactician\DTO\Participant;

/**
 * Strategy for ordering participants within individual events.
 *
 * This interface separates participant ordering from leg strategies and scheduling algorithms,
 * allowing flexible control over home/away assignment and participant positions in events.
 *
 * Participant orderers work universally across all tournament types (round-robin, Swiss, etc.)
 * and can be composed with any leg strategy.
 */
interface ParticipantOrderer
{
    /**
     * Order the participants for a specific event.
     *
     * @param array<Participant> $participants The participants to order (typically 2)
     * @param EventOrderingContext $context Context about the event being ordered
     * @return array<Participant> The participants in the desired order
     */
    public function order(array $participants, EventOrderingContext $context): array;
}
