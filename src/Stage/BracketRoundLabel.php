<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * The English label of a round of a bracket, rendered from where the round
 * stands in the bracket's structure.
 *
 * It is the label the elimination engines put on every round they pair
 * (RoundPairing::getLabel(), and `label` in the metadata of its DTO\Round)
 * and the label of a round of the bracket view (BracketRound::getLabel()):
 * the engines label a round by calling this, so the label is a rendering of
 * the view and says nothing the view does not. An application that wants
 * other words, or another language, renders its own from the same
 * coordinates.
 *
 * - Main side (single elimination), by the number of slots the round is
 *   entered with, as the next power of two: 2 'final', 4 'semifinal',
 *   8 'quarterfinal', and otherwise 'round of N'.
 * - Winners side: 'winners final' for its last round, 'winners round N'
 *   before it.
 * - Losers side: 'losers final' for its last round, 'losers round N' before
 *   it.
 * - Grand final: 'grand final' for round 1, 'grand final reset' for
 *   round 2.
 *
 * @experimental
 */
final class BracketRoundLabel
{
    /**
     * The label of a round.
     *
     * @param int $round The structural round on its side, 1-based
     * @param int $roundsOnSide How many rounds the side has (read for the winners and losers
     *                          sides only)
     * @param int $slotCount How many slots the round is entered with (read for the main side
     *                       only)
     */
    public static function of(BracketSide $side, int $round, int $roundsOnSide, int $slotCount): string
    {
        return match ($side) {
            BracketSide::Main => self::mainLabel($slotCount),
            BracketSide::Winners => $round === $roundsOnSide ? 'winners final' : "winners round {$round}",
            BracketSide::Losers => $round === $roundsOnSide ? 'losers final' : "losers round {$round}",
            BracketSide::GrandFinal => $round === 1 ? 'grand final' : 'grand final reset',
        };
    }

    private static function mainLabel(int $slotCount): string
    {
        $size = BracketFold::size($slotCount);

        return match ($size) {
            2 => 'final',
            4 => 'semifinal',
            8 => 'quarterfinal',
            default => 'round of ' . $size,
        };
    }
}
