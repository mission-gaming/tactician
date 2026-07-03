<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;

/**
 * A single participant's line in the standings table.
 */
readonly class StandingEntry
{
    /**
     * @param array<string, float> $tiebreakers Tiebreaker values keyed by tiebreaker name
     */
    public function __construct(
        private Participant $participant,
        private int $played,
        private int $wins,
        private int $draws,
        private int $losses,
        private float $rankingValue,
        private float $scoreFor = 0.0,
        private float $scoreAgainst = 0.0,
        private array $tiebreakers = []
    ) {
    }

    public function getParticipant(): Participant
    {
        return $this->participant;
    }

    public function getPlayed(): int
    {
        return $this->played;
    }

    public function getWins(): int
    {
        return $this->wins;
    }

    public function getDraws(): int
    {
        return $this->draws;
    }

    public function getLosses(): int
    {
        return $this->losses;
    }

    /**
     * The strategy-computed primary ranking value ordering the table.
     *
     * Under the default WinDrawLossRanking this is the familiar points
     * total; other strategies may aggregate different quantities.
     */
    public function getRankingValue(): float
    {
        return $this->rankingValue;
    }

    public function getScoreFor(): float
    {
        return $this->scoreFor;
    }

    public function getScoreAgainst(): float
    {
        return $this->scoreAgainst;
    }

    public function getScoreDifference(): float
    {
        return $this->scoreFor - $this->scoreAgainst;
    }

    /**
     * @return array<string, float>
     */
    public function getTiebreakers(): array
    {
        return $this->tiebreakers;
    }

    public function getTiebreakerValue(string $name): ?float
    {
        return $this->tiebreakers[$name] ?? null;
    }

    /**
     * Create a copy of this entry with the given tiebreaker values attached.
     *
     * @param array<string, float> $tiebreakers
     */
    public function withTiebreakers(array $tiebreakers): self
    {
        return new self(
            $this->participant,
            $this->played,
            $this->wins,
            $this->draws,
            $this->losses,
            $this->rankingValue,
            $this->scoreFor,
            $this->scoreAgainst,
            $tiebreakers
        );
    }
}
