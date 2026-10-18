<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Serialization\PlainData;

/**
 * An amount added to, or taken from, one participant's ranking value for a
 * reason that is no event: a decision of the application, which the library
 * records and never makes.
 *
 * The amount is an amount of the ranking value, on whatever scale the
 * ranking strategy of the table has. Under a WinDrawLossRanking that is
 * points: -3.0 takes three points away and 1.0 adds one. Under any other
 * RankingStrategy it is that strategy's unit, and because a ranking value is
 * always higher-is-better, a positive amount always places the participant
 * better and a negative one worse, also where the strategy turns a quantity
 * round in which lower is better (a penalty of two strokes under a strategy
 * that ranks by strokes taken, negated, is -2.0). An adjustment needs no
 * knowledge of the scale, so it is accepted under every ranking strategy.
 *
 * It is attached to the participant by ID and to nothing else: it names no
 * event and no opponent, and changes no record and no score. A table shows
 * the sum of a participant's adjustments as a figure of its own
 * (StandingEntry::getAdjustment()) beside the ranking value its results
 * earned, and is ordered by the two together
 * (StandingsCalculator::withAdjustments()). The label and the reason are
 * opaque to the library, which stores them, hands them back and never
 * reads them.
 *
 * An adjustment recorded on a stage can say when it was made: after which
 * recorded round (getAfterRound()). A stage state says it for every
 * adjustment recorded with StageState::withAdjustment(), and an engine
 * then ranks a round from the adjustments made before that round was
 * paired and from no later one. A table calculated on its own
 * (StandingsCalculator::withAdjustments()) has no rounds and does not read
 * it.
 *
 * @experimental
 */
final readonly class Adjustment
{
    /**
     * @param string $participantId The ID of the participant the amount is for
     * @param float $amount The signed amount of ranking value: above zero places the participant
     *                      better, below zero worse. Zero is accepted and changes nothing
     * @param string|null $label A short name the application gives the kind of adjustment, in
     *                           its own words; null when it gives none
     * @param string|null $reason Why the adjustment was made, as free text; null when none is given
     * @param int|null $afterRound The number of the last round that was recorded on the stage
     *                             when the adjustment was made, 0 when none was; null when that
     *                             is not known or the adjustment belongs to no stage (see
     *                             getAfterRound())
     *
     * @throws InvalidInputException When the participant ID is empty, the amount is not a
     *                               finite number (NAN, INF), or the round is below 0
     */
    public function __construct(
        private string $participantId,
        private float $amount,
        private ?string $label = null,
        private ?string $reason = null,
        private ?int $afterRound = null
    ) {
        if ($participantId === '') {
            throw new InvalidInputException('An adjustment needs the ID of the participant it is for');
        }

        if (!is_finite($amount)) {
            throw new InvalidInputException(
                "The amount of the adjustment for participant {$participantId} must be a finite number"
            );
        }

        if ($afterRound !== null && $afterRound < 0) {
            throw new InvalidInputException(
                "The adjustment for participant {$participantId} cannot be made after round {$afterRound}:"
                    . ' the round is 0, for an adjustment made before the first round was recorded, or the number of a round'
            );
        }
    }

    /**
     * The ID of the participant the amount is for.
     */
    public function getParticipantId(): string
    {
        return $this->participantId;
    }

    /**
     * The signed amount of ranking value.
     */
    public function getAmount(): float
    {
        return $this->amount;
    }

    /**
     * The label as given, or null.
     */
    public function getLabel(): ?string
    {
        return $this->label;
    }

    /**
     * The reason as given, or null.
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * When the adjustment was made, in the rounds of its stage: the number
     * of the last round that was recorded on the stage at that moment, 0
     * when no round was, or null when it is not known.
     *
     * An adjustment made after round 2 was recorded, whether round 2 was
     * still being played or was finished, has 2: it was known when round 3
     * was paired and not when round 2 was. An engine reads it that way: a
     * round is ranked from the adjustments made before it was paired
     * (ADR 0001). The tables of the stage, and the round paired next, have
     * every adjustment, whatever its round.
     *
     * To a stage state a round is paired at the moment it is recorded. An
     * application that records round 3 only once it has been played
     * records an adjustment made during round 3 with 2, and it is then
     * read as known when round 3 was paired, which it was not. Record a
     * round when it is paired (StageState::withRoundPlayed() with no
     * results, then withAdditionalResults()) where adjustments are made while a round is
     * played.
     *
     * Null says that the time is not known. An adjustment of a stage state
     * is never null: StageState::withAdjustment() records the round, and a
     * history that holds rounds states it (StageState::fromHistory()
     * refuses one without it). Null is for an adjustment that belongs to no
     * stage, given to a table calculated on its own.
     */
    public function getAfterRound(): ?int
    {
        return $this->afterRound;
    }

    /**
     * The same adjustment, said to have been made after the given round
     * (see getAfterRound()); the participant, the amount, the label and
     * the reason are kept.
     *
     * This is how an application that builds a stage from its own rows
     * (StageState::fromHistory()) says when each adjustment was made.
     *
     * @param int $round The number of the last round recorded when the adjustment was made, or 0
     *
     * @throws InvalidInputException When the round is below 0
     */
    public function madeAfterRound(int $round): self
    {
        return new self($this->participantId, $this->amount, $this->label, $this->reason, $round);
    }

    /**
     * Convert this adjustment to a serializable array: `format_version`,
     * the version of this form (1), then the four keys `participant`,
     * `amount`, `label` and `reason`, always, and `after_round` when the
     * adjustment says after which round it was made (see getAfterRound()),
     * so that an adjustment that does not say it is written as it always
     * was. A stage state writes its adjustments without the version, as it
     * writes every part it holds.
     *
     * @return array{format_version: int, participant: string, amount: float, label: string|null, reason: string|null, after_round?: int}
     */
    public function toArray(): array
    {
        $data = [
            PlainData::VERSION_KEY => PlainData::VERSION,
            'participant' => $this->participantId,
            'amount' => $this->amount,
            'label' => $this->label,
            'reason' => $this->reason,
        ];

        if ($this->afterRound !== null) {
            $data['after_round'] = $this->afterRound;
        }

        return $data;
    }

    /**
     * Recreate an adjustment from the array form toArray() produces:
     * ['participant' => 'p1', 'amount' => -3, 'label' => ..., 'reason' => ...].
     *
     * 'participant' and 'amount' are required. A missing 'label' or
     * 'reason' is null. The amount may be an int or a float; a numeric
     * string is refused. 'after_round' is optional: an integer of 0 or
     * above, and where it is left out, or null, the adjustment does not say
     * when it was made (getAfterRound() is null). A stage state requires it
     * of every adjustment of a state that holds a round
     * (StageState::fromArray()). Any other key is
     * refused. That the participant exists is checked where the adjustment
     * is used, against the table or the stage it is given to.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws InvalidInputException When the format version is not one this release reads, a
     *                               key is unknown, the participant is not a non-empty
     *                               string, the amount is not a finite int or float, a
     *                               label or reason is neither a string nor null, or the
     *                               round is neither null nor an integer of 0 or above
     */
    public static function fromArray(array $data): self
    {
        PlainData::checkFields($data, ['participant', 'amount', 'label', 'reason', 'after_round'], 'adjustment data');

        $participantId = $data['participant'] ?? null;
        if (!is_string($participantId)) {
            throw new InvalidInputException('Adjustment data requires a participant ID');
        }

        $amount = $data['amount'] ?? null;
        if (!is_int($amount) && !is_float($amount)) {
            throw new InvalidInputException('Adjustment data requires an amount that is an int or a float');
        }

        $label = $data['label'] ?? null;
        $reason = $data['reason'] ?? null;
        if (($label !== null && !is_string($label)) || ($reason !== null && !is_string($reason))) {
            throw new InvalidInputException('Adjustment label and reason must each be a string or null');
        }

        $afterRound = $data['after_round'] ?? null;
        if ($afterRound !== null && !is_int($afterRound)) {
            throw new InvalidInputException('Adjustment data requires a round that is an integer or null');
        }

        return new self($participantId, (float) $amount, $label, $reason, $afterRound);
    }
}
