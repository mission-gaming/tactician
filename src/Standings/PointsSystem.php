<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

/**
 * Points awarded per win, draw, and loss when calculating standings.
 */
readonly class PointsSystem
{
    public function __construct(
        private float $winPoints = 3.0,
        private float $drawPoints = 1.0,
        private float $lossPoints = 0.0
    ) {
    }

    public function getWinPoints(): float
    {
        return $this->winPoints;
    }

    public function getDrawPoints(): float
    {
        return $this->drawPoints;
    }

    public function getLossPoints(): float
    {
        return $this->lossPoints;
    }

    /**
     * The common football/association points system (3/1/0).
     */
    public static function football(): self
    {
        return new self(3.0, 1.0, 0.0);
    }

    /**
     * The classical chess points system (1/0.5/0).
     */
    public static function chess(): self
    {
        return new self(1.0, 0.5, 0.0);
    }
}
