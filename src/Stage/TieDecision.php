<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;

/**
 * Resolves who advances from an elimination tie, shared by the bracket
 * engines and the match-outcome selector.
 *
 * Single-leg ties advance the event's winner. Two-legged ties advance
 * whoever won more legs. When that does not decide (a single event that
 * finished level; two legs split 1-1, or drawn), who advances is the
 * application's to resolve under its own rules, which Tactician never
 * owns and never asks about: it records the decision as 'tie_winner'
 * metadata (the advancing participant's ID) on the event's result, or on
 * either leg's result. A level tie with no recorded decision is an error:
 * an elimination tie must send one participant on.
 *
 * The decision is read only when the results leave the tie level. Where
 * the event's winner or the leg wins decide, 'tie_winner' is not read at
 * all: it cannot overturn a decisive result, and a value that names
 * nobody in the tie goes unnoticed there.
 *
 * @experimental
 */
final readonly class TieDecision
{
    public const TIE_WINNER_KEY = 'tie_winner';

    /**
     * Resolve the advancer from a tie's leg results.
     *
     * The caller passes the results of this tie's events and no others:
     * they are not matched against the two participants. A single-leg
     * tie returns the winner its first result records, whoever that is;
     * only a two-legged tie checks that each leg's winner is one of the
     * two.
     *
     * @param array<Result> $legResults The recorded results of the tie's legs, any order
     * @param Participant $first One side of the tie
     * @param Participant $second The other side
     * @param int $legsPerTie How many legs the tie is played over
     *
     * @return Participant|null The advancer, or null while there are fewer results than legs
     * @throws InvalidConfigurationException When a completed tie is level and carries no
     *                                       tie_winner decision (reason `UndecidedTie`), when that
     *                                       decision names a participant outside the tie, or when
     *                                       a leg of a two-legged tie has a winner outside the tie
     *                                       (both `InvalidResult`)
     */
    public static function advancer(
        array $legResults,
        Participant $first,
        Participant $second,
        int $legsPerTie
    ): ?Participant {
        if (count($legResults) < $legsPerTie) {
            return null; // Legs still unplayed
        }

        if ($legsPerTie === 1) {
            $winner = $legResults[0]->getWinner();
            if ($winner !== null) {
                return $winner;
            }

            // The event finished level: the application resolves it under
            // its own rules and records the decision.
            $decided = self::recordedDecision($legResults, $first, $second);
            if ($decided instanceof Participant) {
                return $decided;
            }

            throw new InvalidConfigurationException(
                "Elimination events cannot end in a draw ({$first->getLabel()} vs {$second->getLabel()}): the event is level, so record who advances as '" . self::TIE_WINNER_KEY . "' metadata on its result",
                ['participants' => [$first->getId(), $second->getId()]],
                reason: InvalidConfigurationReason::UndecidedTie
            );
        }

        $legWins = [$first->getId() => 0, $second->getId() => 0];
        foreach ($legResults as $result) {
            $winner = $result->getWinner();
            if ($winner === null) {
                continue;
            }

            if (!isset($legWins[$winner->getId()])) {
                throw new InvalidConfigurationException(
                    'Leg result names a winner who is not in the tie',
                    ['winner' => $winner->getId(), 'participants' => [$first->getId(), $second->getId()]],
                    reason: InvalidConfigurationReason::InvalidResult
                );
            }

            ++$legWins[$winner->getId()];
        }

        if ($legWins[$first->getId()] !== $legWins[$second->getId()]) {
            return $legWins[$first->getId()] > $legWins[$second->getId()] ? $first : $second;
        }

        // The legs did not decide: the application resolves the aggregate
        // under its own rules and records the decision.
        $decided = self::recordedDecision($legResults, $first, $second);
        if ($decided instanceof Participant) {
            return $decided;
        }

        throw new InvalidConfigurationException(
            "Two-legged tie between {$first->getLabel()} and {$second->getLabel()} is undecided: the legs are level, so record the aggregate decision as '" . self::TIE_WINNER_KEY . "' metadata on one leg's result",
            ['participants' => [$first->getId(), $second->getId()]],
            reason: InvalidConfigurationReason::UndecidedTie
        );
    }

    /**
     * The participant a level tie's results name as advancing: the first
     * 'tie_winner' decision found on them, or null when none carries one.
     *
     * @param array<Result> $legResults
     *
     * @throws InvalidConfigurationException When the decision names a participant outside the tie
     */
    private static function recordedDecision(array $legResults, Participant $first, Participant $second): ?Participant
    {
        foreach ($legResults as $result) {
            $decision = $result->getMetadataValue(self::TIE_WINNER_KEY);
            if ($decision !== null) {
                if ($decision === $first->getId()) {
                    return $first;
                }
                if ($decision === $second->getId()) {
                    return $second;
                }

                throw new InvalidConfigurationException(
                    'Tie decision names a participant who is not in the tie',
                    ['tie_winner' => $decision, 'participants' => [$first->getId(), $second->getId()]],
                    reason: InvalidConfigurationReason::InvalidResult
                );
            }
        }

        return null;
    }
}
