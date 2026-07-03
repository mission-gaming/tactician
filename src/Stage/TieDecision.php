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
 * Single-leg ties advance the event's winner (a draw is an error —
 * elimination events must decide). Two-legged ties advance whoever won
 * more legs; when the legs do not decide (a 1-1 split, or draws), the
 * aggregate is the application's to resolve under its own rules (away
 * goals, extra time, penalties — rules Tactician must never own), and it
 * records the decision as 'tie_winner' metadata (the advancing
 * participant's ID) on either leg's result.
 */
final readonly class TieDecision
{
    public const TIE_WINNER_KEY = 'tie_winner';

    /**
     * Resolve the advancer from a tie's leg results.
     *
     * @param array<Result> $legResults The recorded results of the tie's legs, any order
     * @param Participant $first One side of the tie
     * @param Participant $second The other side
     * @param int $legsPerTie How many legs the tie is played over
     *
     * @return Participant|null The advancer, or null while legs are missing results
     * @throws InvalidConfigurationException When a single-leg tie is drawn, or a completed
     *                                       two-legged tie is undecided and carries no
     *                                       tie_winner decision
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
            if ($winner === null) {
                throw new InvalidConfigurationException(
                    "Elimination events cannot end in a draw ({$first->getLabel()} vs {$second->getLabel()})",
                    ['participants' => [$first->getId(), $second->getId()]]
                );
            }

            return $winner;
        }

        $legWins = [$first->getId() => 0, $second->getId() => 0];
        foreach ($legResults as $result) {
            $winner = $result->getWinner();
            if ($winner !== null) {
                ++$legWins[$winner->getId()];
            }
        }

        if ($legWins[$first->getId()] !== $legWins[$second->getId()]) {
            return $legWins[$first->getId()] > $legWins[$second->getId()] ? $first : $second;
        }

        // The legs did not decide: the application resolves the aggregate
        // under its own rules and records the decision.
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
                    ['tie_winner' => $decision, 'participants' => [$first->getId(), $second->getId()]]
                );
            }
        }

        throw new InvalidConfigurationException(
            "Two-legged tie between {$first->getLabel()} and {$second->getLabel()} is undecided: the legs are level, so record the aggregate decision as '" . self::TIE_WINNER_KEY . "' metadata on one leg's result",
            ['participants' => [$first->getId(), $second->getId()]]
        );
    }
}
