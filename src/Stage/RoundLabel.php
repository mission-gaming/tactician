<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\MetadataKey;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use Stringable;

/**
 * A round label as typed data: its kind, and the number the kind names
 * where it names one.
 *
 * The elimination engines label their rounds with text ('semifinal',
 * 'round of 16', 'losers round 2'), and the text is what a RoundPairing and
 * the metadata of a DTO\Round hold. This reads that text, so that an
 * application can map a round to its own wording or translation without
 * parsing it: RoundPairing::getRoundLabel(), RoundLabel::ofRound(), or
 * RoundLabel::fromString() on stored text. toString() gives the text back,
 * as the engine wrote it.
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
     * (DTO\MetadataKey::ROUND_LABEL), read by fromString(). Null for a round
     * without one, with one that is not a string, or with one fromString()
     * does not read.
     */
    public static function ofRound(Round $round): ?self
    {
        $label = $round->getMetadataValue(MetadataKey::ROUND_LABEL);

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
     * The text of toString().
     */
    #[\Override]
    public function __toString(): string
    {
        return $this->toString();
    }
}
