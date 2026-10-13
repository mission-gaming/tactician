<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;

/**
 * A single participant's line in the standings table: its record, its
 * primary ranking value, its scores and its tiebreaker values.
 *
 * @experimental
 */
readonly class StandingEntry
{
    /**
     * The figures are stored as given and are not checked against each
     * other.
     *
     * @param int $played Results recorded for events the participant is in
     * @param float $rankingValue The primary ranking value; higher is better
     * @param float $scoreFor The sum of the participant's own recorded scores
     * @param float $scoreAgainst The sum of the recorded scores of its opponents
     * @param array<string, float> $tiebreakers Tiebreaker values keyed by tiebreaker name
     * @param int $byes Byes whose credit is part of the ranking value (see getByes())
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
        private array $tiebreakers = [],
        private int $byes = 0
    ) {}

    /**
     * The participant this line belongs to.
     */
    public function getParticipant(): Participant
    {
        return $this->participant;
    }

    /**
     * The number of results recorded for events the participant is in. Byes
     * and events without a result are not counted.
     */
    public function getPlayed(): int
    {
        return $this->played;
    }

    /**
     * The number of results that name the participant as the winner.
     */
    public function getWins(): int
    {
        return $this->wins;
    }

    /**
     * The number of its results that name no winner.
     */
    public function getDraws(): int
    {
        return $this->draws;
    }

    /**
     * The number of its results that name another participant as the winner.
     */
    public function getLosses(): int
    {
        return $this->losses;
    }

    /**
     * The strategy-computed primary ranking value ordering the table.
     *
     * Under the default WinDrawLossRanking this is the familiar points
     * total; other strategies may aggregate different quantities. In a
     * table with byes credited it includes the credit (see getByes()).
     */
    public function getRankingValue(): float
    {
        return $this->rankingValue;
    }

    /**
     * The number of byes credited in the ranking value: 0 in a table
     * calculated from results alone. A Swiss stage counts a bye as a win,
     * so the ranking value of an entry of its table is that of its results
     * plus one win for each bye, while played, wins, draws and losses count
     * results only (StandingsCalculator::withByesCredited()).
     */
    public function getByes(): int
    {
        return $this->byes;
    }

    /**
     * The sum of the participant's own recorded scores; 0.0 when no result
     * recorded one.
     */
    public function getScoreFor(): float
    {
        return $this->scoreFor;
    }

    /**
     * The sum of the scores recorded for the other participants of the
     * events it played.
     */
    public function getScoreAgainst(): float
    {
        return $this->scoreAgainst;
    }

    /**
     * Scores-for minus scores-against.
     */
    public function getScoreDifference(): float
    {
        return $this->scoreFor - $this->scoreAgainst;
    }

    /**
     * The tiebreaker values keyed by tiebreaker name, in the order the
     * calculator applies them; empty when it has no tiebreakers.
     *
     * @return array<string, float>
     */
    public function getTiebreakers(): array
    {
        return $this->tiebreakers;
    }

    /**
     * The value of the tiebreaker with this name (TiebreakerInterface::getName()),
     * or null when the entry carries none under it.
     */
    public function getTiebreakerValue(string $name): ?float
    {
        return $this->tiebreakers[$name] ?? null;
    }

    /**
     * Whether no result separates this entry from the other one.
     *
     * Two entries are level when every figure the calculator compares before
     * its final fallback is equal: the ranking value, each tiebreaker value,
     * the score difference and scores-for. Between level entries no result
     * decides the order of the table: the position in the entrant list does
     * in the table of a stage, and the calculator's fallback (seed, then
     * participant ID) in a table that stands alone. The record (played, wins, draws, losses) and scores-against
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
     * A copy of this entry that carries the given tiebreaker values in place
     * of any it had.
     *
     * @param array<string, float> $tiebreakers Values keyed by tiebreaker name
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
            $tiebreakers,
            $this->byes
        );
    }

    /**
     * A copy of this entry with the given number of credited byes and the
     * ranking value that includes their credit, in place of those it had.
     */
    public function withByes(int $byes, float $rankingValue): self
    {
        return new self(
            $this->participant,
            $this->played,
            $this->wins,
            $this->draws,
            $this->losses,
            $rankingValue,
            $this->scoreFor,
            $this->scoreAgainst,
            $this->tiebreakers,
            $byes
        );
    }
}
