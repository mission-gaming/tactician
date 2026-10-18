<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use Stringable;

/**
 * The label of a bracket round as typed data: its kind, and the number the
 * kind names where it names one.
 *
 * The elimination engines label every round they pair from where the round
 * stands in the bracket (ofBracketRound()), and so does the bracket view:
 * RoundPairing::getRoundLabel() and BracketRound::getRoundLabel() give the
 * same label for the same round. An application maps a label to its own
 * wording or translation by its kind and number, without parsing text.
 *
 * toString() is the English text the engines write: the label of a
 * RoundPairing (getLabel()) and the `label` of the metadata of its
 * DTO\Round (FormatMetadataKey::ROUND_LABEL). fromString() and
 * ofRound() read that text back, for a label that was stored or recorded
 * as text.
 *
 * @experimental
 */
final readonly class RoundLabel implements Stringable
{
    private function __construct(
        private RoundLabelKind $kind,
        private ?int $number
    ) {}

    /**
     * The label of a kind, with its number where the kind names one.
     *
     * @param int|null $number The number a numbered kind names (RoundLabelKind::isNumbered()):
     *                         the places of a 'round of', the round of a winners or losers
     *                         round. Null for any other kind
     * @throws InvalidInputException When a numbered kind has no number or one below 1, or a
     *                               kind that names none is given one (unchecked: a mistake in
     *                               the calling code)
     */
    public static function of(RoundLabelKind $kind, ?int $number = null): self
    {
        if ($kind->isNumbered() && ($number === null || $number < 1)) {
            throw new InvalidInputException(
                "A round label of the kind '{$kind->value}' names a number of at least 1"
            );
        }

        if (!$kind->isNumbered() && $number !== null) {
            throw new InvalidInputException(
                "A round label of the kind '{$kind->value}' names no number"
            );
        }

        return new self($kind, $number);
    }

    /**
     * The label of a round of a bracket, from where the round stands in it.
     *
     * - Main side (single elimination), by the number of slots the round is
     *   entered with, as the next power of two: 2 a final, 4 a semifinal,
     *   8 a quarterfinal, and otherwise a 'round of' that power.
     * - Winners side: the winners final for its last round, a winners round
     *   numbered by the structural round before it.
     * - Losers side: the losers final for its last round, a losers round
     *   numbered by the structural round before it.
     * - Grand final: the grand final for round 1, the grand final reset for
     *   any later round.
     *
     * @param int $round The structural round on its side, 1-based (read for the winners, losers
     *                   and grand final sides only)
     * @param int $roundsOnSide How many rounds the side has (read for the winners and losers
     *                          sides only)
     * @param int $slotCount How many slots the round is entered with (read for the main side
     *                       only)
     * @throws InvalidInputException When a winners or losers round before the last is below 1
     *                               (unchecked: a mistake in the calling code)
     */
    public static function ofBracketRound(BracketSide $side, int $round, int $roundsOnSide, int $slotCount): self
    {
        return match ($side) {
            BracketSide::Main => self::ofMainRound($slotCount),
            BracketSide::Winners => $round === $roundsOnSide
                ? self::of(RoundLabelKind::WinnersFinal)
                : self::of(RoundLabelKind::WinnersRound, $round),
            BracketSide::Losers => $round === $roundsOnSide
                ? self::of(RoundLabelKind::LosersFinal)
                : self::of(RoundLabelKind::LosersRound, $round),
            BracketSide::GrandFinal => self::of($round === 1 ? RoundLabelKind::GrandFinal : RoundLabelKind::GrandFinalReset),
        };
    }

    /**
     * Read the text of a label as an engine writes it: a kind's text alone,
     * or a numbered kind's text, one space and the number in decimal without
     * leading zeros. Null for any other text, a label of the application's
     * own included: the library does not guess.
     */
    public static function fromString(string $label): ?self
    {
        $kind = RoundLabelKind::tryFrom($label);
        if ($kind !== null) {
            return $kind->isNumbered() ? null : new self($kind, null);
        }

        if (preg_match('/^(?<text>[a-z ]+) (?<number>[1-9][0-9]{0,17})$/', $label, $matches) !== 1) {
            return null;
        }

        $kind = RoundLabelKind::tryFrom($matches['text']);
        if ($kind === null || !$kind->isNumbered()) {
            return null;
        }

        return new self($kind, (int) $matches['number']);
    }

    /**
     * The label a round carries in its metadata
     * (FormatMetadataKey::ROUND_LABEL), read by fromString(). Null for a
     * round without one, with one that is not a string, or with one
     * fromString() does not read.
     */
    public static function ofRound(Round $round): ?self
    {
        $label = $round->getMetadataValue(FormatMetadataKey::ROUND_LABEL);

        return is_string($label) ? self::fromString($label) : null;
    }

    /**
     * The kind of the label.
     */
    public function getKind(): RoundLabelKind
    {
        return $this->kind;
    }

    /**
     * The number the label names, for a numbered kind; null for any other.
     */
    public function getNumber(): ?int
    {
        return $this->number;
    }

    /**
     * The text of the label, as an engine writes it: 'semifinal',
     * 'round of 16'.
     */
    public function toString(): string
    {
        return $this->number === null ? $this->kind->value : "{$this->kind->value} {$this->number}";
    }

    /**
     * The label of a round of the main side, by the number of slots it is
     * entered with, as the next power of two.
     */
    private static function ofMainRound(int $slotCount): self
    {
        return match ($size = BracketFold::size($slotCount)) {
            2 => self::of(RoundLabelKind::Final),
            4 => self::of(RoundLabelKind::Semifinal),
            8 => self::of(RoundLabelKind::Quarterfinal),
            default => self::of(RoundLabelKind::RoundOf, $size),
        };
    }

    /**
     * The text of toString().
     */
    #[\Override]
    public function __toString(): string
    {
        return $this->toString();
    }
}
