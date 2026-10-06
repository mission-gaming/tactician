<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;

/**
 * A single participant's line in the standings table.
 *
 * @experimental
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
    ) {}

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
     * Whether no result separates this entry from the other one.
     *
     * Two entries are level when every figure the calculator compares before
     * its final fallback is equal: the ranking value, each tiebreaker value,
     * the score difference and scores-for. Between level entries only the
     * fallback (seed, then label, then participant ID) decides the order of
     * the table. The record (played, wins, draws, losses) and scores-against
     * are not compared, because the table is not ordered by them; add
     * `WinsTiebreaker` to compare wins.
     *
     * "Equal" is the equality of the comparison that orders the table: two
     * floats are level only when they are the same number, with no tolerance.
     * A ranking strategy or a tiebreaker that adds fractions with no exact
     * binary form (0.1 three times against 0.3 once) can therefore produce
     * values that look the same and are not level; the table places one of
     * them above the other for the same reason.
     *
     * The tiebreaker values are those the two entries carry. A value one
     * entry carries and the other does not is read as 0.0, as the calculator
     * reads it. Entries of one table carry the same tiebreakers; comparing
     * entries of tables built with different tiebreakers has no meaning.
     *
     * A figure that is not a number (NAN) is level with nothing, itself
     * included: the calculator's comparison does not return "equal" for it
     * either, and a table that holds one has no defined order. A ranking
     * strategy or a tiebreaker must not return NAN.
     */
    public function isLevelWith(self $other): bool
    {
        // Each step reads the two entries as the calculator's comparison
        // reads them, through the same accessors, so the two cannot disagree
        if (($this->getRankingValue() <=> $other->getRankingValue()) !== 0) {
            return false;
        }

        foreach (array_keys($this->getTiebreakers() + $other->getTiebreakers()) as $name) {
            if ((($this->getTiebreakerValue($name) ?? 0.0) <=> ($other->getTiebreakerValue($name) ?? 0.0)) !== 0) {
                return false;
            }
        }

        return ($this->getScoreDifference() <=> $other->getScoreDifference()) === 0
            && ($this->getScoreFor() <=> $other->getScoreFor()) === 0;
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
