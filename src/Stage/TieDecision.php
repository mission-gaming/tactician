<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

/**
 * Resolves who advances from an elimination tie, shared by the bracket
 * engines and the match-outcome selector.
 *
 * Single-leg ties advance the event's winner. Two-legged ties advance
 * whoever won more legs. When that does not decide (a single event that
 * finished level; two legs split 1-1, or drawn), who advances is the
 * application's to resolve under its own rules, which Tactician never
 * owns and never asks about: it records the decision as the decider of a
 * result of the tie (DTO\Decider, on Result). A level tie with no recorded
 * decision is an error: an elimination tie must send one participant on.
 *
 * The decider of a tie:
 *
 * - names the one participant who advances. A tie is between two, so a
 *   decider that names more than one is refused, as is one that names a
 *   participant outside the tie.
 * - is on one result of the tie. In a two-legged tie that is either leg's
 *   result, and the last leg played is the natural place; a decider on
 *   both legs is refused, whether the two agree or not, because a tie has
 *   one decision.
 * - is read only when the results leave the tie level, and is refused
 *   where they do not: a decider on an event that has a winner, or on a
 *   leg of a tie the leg wins decide, contradicts or repeats a result,
 *   and neither is recorded silently.
 * - changes no score and no record. The results stay as recorded.
 *
 * An awarded result (DTO\Result::isAwarded()) is read as any other result:
 * the winner it names advances from a single-leg tie and has won the leg
 * of a two-legged one, and an awarded result that names no winner is level
 * like a draw, so the tie then needs its decider, which the awarded result
 * may carry itself. A two-legged tie needs a result for each leg, so a tie
 * that is not played at all is settled by an awarded result for each.
 *
 * Before 0.3.0 the decision was 'tie_winner' metadata on a result of the
 * tie: the ID of the participant who advances. The key is deprecated and
 * still read until 1.0.0, when the results leave the tie level and none of
 * them carries a decider; the first result that has it decides, and a call
 * that reads it raises one E_USER_DEPRECATED notice, on every PHP version,
 * however many ties it decided in that call. Where the results decide, the
 * key is not read at all, as before: it cannot overturn a decisive result,
 * and a value that names nobody in the tie goes unnoticed there. Where a
 * tie carries a decider, the decider is what is read; metadata beside it
 * that names another participant is refused, and metadata that names the
 * same one raises nothing, so an application can write both while it
 * changes over.
 *
 * @experimental
 */
final readonly class TieDecision
{
    /**
     * The metadata key a tie decision was recorded under before the typed
     * decider existed.
     *
     * @deprecated since 0.3.0, removed in 1.0.0. The key is still read until then; record who advances as the decider of the result instead (DTO\Decider).
     */
    #[\Deprecated(message: 'record who advances as the decider of the result instead (DTO\Decider)', since: '0.3.0')]
    public const TIE_WINNER_KEY = 'tie_winner';

    /**
     * Resolve the advancer from a tie's leg results.
     *
     * The caller passes the results of this tie's events and no others:
     * the events are not matched against the two participants. The winner
     * a result records is: it must be one of the two, by ID, in a
     * single-leg tie as in a two-legged one. A single-leg tie returns the
     * winner its first result records, as the object the result holds; a
     * tie the decider settles returns the side it names, as the object
     * given here.
     *
     * A call that takes the advancer from 'tie_winner' metadata raises
     * the deprecation of the key (see the class).
     *
     * @param array<Result> $legResults The recorded results of the tie's legs, any order
     * @param Participant $first One side of the tie
     * @param Participant $second The other side
     * @param int $legsPerTie How many legs the tie is played over
     *
     * @return Participant|null The advancer, or null while there are fewer results than legs
     * @throws InvalidConfigurationException When a completed tie is level and carries no decider
     *                                       and no tie_winner decision (reason `UndecidedTie`);
     *                                       and, each with the reason `InvalidResult`, when a
     *                                       result has a winner outside the tie, whatever the
     *                                       number of legs, when a decider is on a tie the
     *                                       results decide, names more than one participant or
     *                                       one outside the tie, is one of two on the tie, or
     *                                       disagrees with tie_winner metadata beside it, or
     *                                       when the tie_winner decision that is read names a
     *                                       participant outside the tie
     */
    public static function advancer(
        array $legResults,
        Participant $first,
        Participant $second,
        int $legsPerTie
    ): ?Participant {
        $resolution = TieResolution::of($legResults, $first, $second, $legsPerTie);

        if ($resolution->readTieWinnerMetadata) {
            TieResolution::raiseTieWinnerDeprecation();
        }

        return $resolution->advancer;
    }
}
