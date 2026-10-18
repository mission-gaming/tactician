<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * The kinds of round label the elimination engines write.
 *
 * A bracket round carries a label: on its RoundPairing (getLabel()) and in
 * the metadata of its DTO\Round under DTO\MetadataKey::ROUND_LABEL. Some
 * labels are a fixed text ('final', 'grand final'); three name a number as
 * well ('round of 16', 'winners round 2', 'losers round 3'). Each case is
 * backed by the fixed text, or by the text in front of the number. A
 * RoundLabel pairs a kind with its number, and RoundLabel::fromString() reads
 * the label an engine wrote.
 *
 * Swiss rounds and round-robin rounds have no label.
 *
 * @experimental
 */
enum RoundLabelKind: string
{
    /**
     * The last round of a single elimination bracket, entered by 2 (or, with
     * the third-place option, the round that also holds the third-place
     * tie).
     */
    case Final = 'final';

    /**
     * The round of a single elimination bracket entered by 4.
     */
    case Semifinal = 'semifinal';

    /**
     * The round of a single elimination bracket entered by 8.
     */
    case Quarterfinal = 'quarterfinal';

    /**
     * An earlier round of a single elimination bracket, named by the number
     * of places it is entered with: 'round of 16', 'round of 32'.
     */
    case RoundOf = 'round of';

    /**
     * A round of the winners bracket of a double elimination bracket before
     * its last, by its number in that bracket: 'winners round 1'.
     */
    case WinnersRound = 'winners round';

    /**
     * The last round of the winners bracket of a double elimination
     * bracket.
     */
    case WinnersFinal = 'winners final';

    /**
     * A round of the losers bracket of a double elimination bracket before
     * its last, by its number in that bracket: 'losers round 2'.
     */
    case LosersRound = 'losers round';

    /**
     * The last round of the losers bracket of a double elimination bracket.
     */
    case LosersFinal = 'losers final';

    /**
     * The meeting of the winners and losers champions of a double
     * elimination bracket.
     */
    case GrandFinal = 'grand final';

    /**
     * The second grand final, played when the losers champion wins the first
     * and the bracket resets (EliminationOptions::$grandFinalReset).
     */
    case GrandFinalReset = 'grand final reset';

    /**
     * Whether a label of this kind names a number after the text of the
     * case.
     */
    public function isNumbered(): bool
    {
        return match ($this) {
            self::RoundOf, self::WinnersRound, self::LosersRound => true,
            default => false,
        };
    }
}
