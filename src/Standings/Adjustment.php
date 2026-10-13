<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\Exceptions\InvalidInputException;

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
     *
     * @throws InvalidInputException When the participant ID is empty, or the amount is not a
     *                               finite number (NAN, INF)
     */
    public function __construct(
        private string $participantId,
        private float $amount,
        private ?string $label = null,
        private ?string $reason = null
    ) {
        if ($participantId === '') {
            throw new InvalidInputException('An adjustment needs the ID of the participant it is for');
        }

        if (!is_finite($amount)) {
            throw new InvalidInputException(
                "The amount of the adjustment for participant {$participantId} must be a finite number"
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
     * Convert this adjustment to a serializable array: all four keys,
     * always.
     *
     * @return array{participant: string, amount: float, label: string|null, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'participant' => $this->participantId,
            'amount' => $this->amount,
            'label' => $this->label,
            'reason' => $this->reason,
        ];
    }

    /**
     * Recreate an adjustment from the array form toArray() produces:
     * ['participant' => 'p1', 'amount' => -3, 'label' => ..., 'reason' => ...].
     *
     * 'participant' and 'amount' are required. A missing 'label' or
     * 'reason' is null. The amount may be an int or a float; a numeric
     * string is refused. Any other key is refused. That the participant
     * exists is checked where the adjustment is used, against the table or
     * the stage it is given to.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws InvalidInputException When a key is unknown, the participant is not a non-empty
     *                               string, the amount is not a finite int or float, or a
     *                               label or reason is neither a string nor null
     */
    public static function fromArray(array $data): self
    {
        $unknown = array_diff(array_map(strval(...), array_keys($data)), ['participant', 'amount', 'label', 'reason']);
        if ($unknown !== []) {
            throw new InvalidInputException(sprintf(
                "Unknown key in adjustment data: '%s' (the known keys are 'participant', 'amount', 'label', 'reason')",
                implode("', '", $unknown)
            ));
        }

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

        return new self($participantId, (float) $amount, $label, $reason);
    }
}
