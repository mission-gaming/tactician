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
     */
    public function __construct(
        public array $sessionByEvent,
        public array $unplaced,
        public array $violations
    ) {
    }
}
