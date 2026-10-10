<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

use MissionGaming\Tactician\Repack\RepackViolation;
use MissionGaming\Tactician\Repack\UnplacedEvent;

/**
 * Phase A's product: which session every placeable movable event plays
 * in, which events were dropped as over capacity, and the capacity
 * violations that explain the drops.
 *
 * @internal
 */
final readonly class LoadPlan
{
    /**
     * @param array<int, int> $sessionByEvent Event index => session index
     * @param array<UnplacedEvent> $unplaced Events no session can hold, with reasons
     * @param array<RepackViolation> $violations Capacity violations detected while planning
     * @param array<int, int> $shortfalls Over-capacity participant index => how many of its
     *                                    events cannot be placed
     * @param int $leftOutAtLeast How many movable events no placement can hold, at least,
     *                            because of the shortfalls: the over-capacity drops when
     *                            they are known to be as few as there can be, and a
     *                            bound that needs no search otherwise
     */
    public function __construct(
        public array $sessionByEvent,
        public array $unplaced,
        public array $violations,
        public array $shortfalls = [],
        public int $leftOutAtLeast = 0
    ) {}
}
