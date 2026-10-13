<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;

/**
 * A single participant's line in the standings table: its record, its
 * primary ranking value, its scores and its tiebreaker values.
 *
 * The record is four counts that add up: played is wins plus draws plus
 * losses. Figures that say something else about the line stand beside the
 * record and are never part of it: the byes the participant had, whose
 * value is in the ranking value (getByes()), how many of the results counted were awarded and not played
 * (getAwarded()), and the adjustment to the ranking value that comes from
 * no event (getAdjustment()).
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
     * @param float $rankingValue The ranking value the participant earned, from its results and
     *                            its credited byes; higher is better. An adjustment is given
     *                            apart, as the last argument, and getRankingValue() is the two
     *                            together
     * @param float $scoreFor The sum of the participant's own recorded scores
     * @param float $scoreAgainst The sum of the recorded scores of its opponents
     * @param array<string, float> $tiebreakers Tiebreaker values keyed by tiebreaker name
     * @param int $byes Byes whose credit is part of the ranking value (see getByes())
     * @param int $awarded How many of the results counted in played were awarded (see getAwarded())
     * @param float $adjustment The sum of the participant's adjustments (see getAdjustment())
     * @param float|null $rankingValueOfResults The ranking value of the results alone, where
     *                                          the ranking value given includes the credit of
     *                                          byes; null when it includes none (see
     *                                          getRankingValueOfResults())
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
        private int $byes = 0,
        private int $awarded = 0,
        private float $adjustment = 0.0,
        private ?float $rankingValueOfResults = null
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
     * and events without a result are not counted. An awarded result is a
     * result and is counted, here and as the win, draw or loss it is, so
     * that the record adds up; getAwarded() says how many of them there
     * are, and played minus that is the number of events that took place.
     */
    public function getPlayed(): int
    {
        return $this->played;
    }

    /**
     * How many of the results counted in getPlayed() were awarded and not
     * played (DTO\Result::isAwarded()): 0 in a table without awarded
     * results. The figure stands beside the record and is not compared when
     * the table is ordered.
     */
    public function getAwarded(): int
    {
        return $this->awarded;
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
     *
     * It is the total the table is ordered by: what the participant earned
     * (getEarnedRankingValue()) plus its adjustment (getAdjustment()). An
     * entry without an adjustment has the earned value here, unchanged.
     */
    public function getRankingValue(): float
    {
        return $this->adjustment === 0.0 ? $this->rankingValue : $this->rankingValue + $this->adjustment;
    }

    /**
     * The ranking value the participant earned: what the ranking strategy
     * computed from its results, plus the credit of its byes in a table
     * that credits them. No adjustment is in it.
     */
    public function getEarnedRankingValue(): float
    {
        return $this->rankingValue;
    }

    /**
     * The sum of the participant's adjustments: ranking value added (above
     * zero) or taken away (below zero) for reasons that are no event
     * (Adjustment). 0.0 in a table without adjustments and for a
     * participant that has none.
     *
     * The figure is shown on its own and is never folded into the earned
     * value: getEarnedRankingValue(), this figure and getRankingValue() are
     * what was earned, what was adjusted and the total the table is ordered
     * by. No other figure of the entry changes with an adjustment: the
     * record, the scores and the tiebreaker values are those of the
     * results.
     */
    public function getAdjustment(): float
    {
        return $this->adjustment;
    }

    /**
     * The ranking value of the participant's results alone: what the
     * ranking strategy computed from them, without the credit of a bye and
     * without an adjustment. In a table that credits no bye it is
     * getEarnedRankingValue().
     *
     * The three values tell a line apart: this one is what the results are
     * worth, getEarnedRankingValue() is this plus the value of each bye
     * (getByes() times what the ranking strategy says a bye is worth), and
     * getRankingValue() is that plus the adjustment.
     */
    public function getRankingValueOfResults(): float
    {
        return $this->rankingValueOfResults ?? $this->rankingValue;
    }

    /**
     * The number of byes the participant had, as the table was given them:
     * 0 in a table calculated from results alone. A bye is a figure of its
     * own. It is no result, so played, wins, draws and losses do not count
     * it; what it is worth is in the ranking value (the ranking strategy
     * says how much, RankingStrategyWithByeValue), and a bye worth nothing
     * is still counted here (StandingsCalculator::calculateWith(),
     * withByesCredited()).
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
            $this->byes,
            $this->awarded,
            $this->adjustment,
            $this->rankingValueOfResults
        );
    }

    /**
     * A copy of this entry with the given number of credited byes and the
     * earned ranking value that includes their credit, in place of those it
     * had. The adjustment is kept and is not part of the value given: pass
     * getEarnedRankingValue() plus the credit. The value of the results
     * (getRankingValueOfResults()) is kept as it was before the credit.
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
            $byes,
            $this->awarded,
            $this->adjustment,
            $this->rankingValueOfResults ?? $this->rankingValue
        );
    }

    /**
     * A copy of this entry with the given adjustment in place of the one it
     * had: getAdjustment() is the amount given, the earned ranking value
     * stays, and getRankingValue() is the two together.
     */
    public function withAdjustment(float $adjustment): self
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
            $this->tiebreakers,
            $this->byes,
            $this->awarded,
            $adjustment,
            $this->rankingValueOfResults
        );
    }
}
