<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

/**
 * Shared seeding and result-lookup helpers for elimination bracket engines.
 */
trait EliminationBracketSupport
{
    /**
     * @param array<Participant> $participants
     *
     * @throws InvalidConfigurationException
     */
    private function validateParticipants(array $participants): void
    {
        if (count($participants) < 2) {
            throw new InvalidConfigurationException(
                'Elimination brackets require at least 2 participants',
                ['participant_count' => count($participants), 'minimum_required' => 2]
            );
        }

        $ids = array_map(fn (Participant $participant) => $participant->getId(), $participants);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidConfigurationException(
                'All participants must have unique IDs',
                ['participant_count' => count($participants), 'unique_ids' => count(array_unique($ids))]
            );
        }
    }

    /**
     * Place participants into bracket slots using standard fold seeding.
     *
     * @param array<Participant> $participants
     * @return array<Participant|null> Bracket slots in order; null slots are byes
     */
    private function buildInitialSlots(array $participants): array
    {
        $ordered = $this->orderBySeed($participants);
        $size = $this->bracketSize(count($ordered));

        $slots = [];
        foreach ($this->seedPositions($size) as $position) {
            $slots[] = $ordered[$position - 1] ?? null;
        }

        return $slots;
    }

    /**
     * Order participants by seed (unseeded last, keeping input order).
     *
     * @param array<Participant> $participants
     * @return array<Participant>
     */
    private function orderBySeed(array $participants): array
    {
        $indexed = [];
        foreach ($participants as $index => $participant) {
            $indexed[] = ['participant' => $participant, 'index' => $index];
        }

        usort(
            $indexed,
            fn (array $first, array $second): int => (($first['participant']->getSeed() ?? PHP_INT_MAX) <=> ($second['participant']->getSeed() ?? PHP_INT_MAX))
                ?: ($first['index'] <=> $second['index'])
        );

        return array_map(fn (array $entry) => $entry['participant'], $indexed);
    }

    private function bracketSize(int $participantCount): int
    {
        $size = 2;
        while ($size < $participantCount) {
            $size *= 2;
        }

        return $size;
    }

    /**
     * Standard fold seeding positions: consecutive pairs sum to size + 1, so
     * the top seeds can only meet in the latest possible round.
     *
     * @return array<int>
     */
    private function seedPositions(int $size): array
    {
        $positions = [1];
        while (count($positions) < $size) {
            $target = count($positions) * 2 + 1;
            $next = [];
            foreach ($positions as $position) {
                $next[] = $position;
                $next[] = $target - $position;
            }
            $positions = $next;
        }

        return $positions;
    }

    /**
     * Index results by round and normalized pairing for winner lookups.
     *
     * @param array<Result> $results
     * @return array<string, Result>
     *
     * @throws InvalidConfigurationException When a result lacks a round number, does not
     *                                       reference a two-participant event, or duplicates
     *                                       another result for the same match
     */
    private function indexResults(array $results): array
    {
        $index = [];
        foreach ($results as $result) {
            $event = $result->getEvent();
            $eventParticipants = $event->getParticipants();
            $round = $event->getRound()?->getNumber();

            if ($round === null) {
                throw new InvalidConfigurationException(
                    'Elimination results must reference events with a round number; record results against the events produced by the engine',
                    ['participants' => array_map(fn (Participant $p) => $p->getId(), $eventParticipants)]
                );
            }

            if (count($eventParticipants) !== 2) {
                throw new InvalidConfigurationException(
                    'Elimination results must reference two-participant events',
                    ['round' => $round, 'participant_count' => count($eventParticipants)]
                );
            }

            $ids = [$eventParticipants[0]->getId(), $eventParticipants[1]->getId()];
            sort($ids);
            $key = $round . ':' . implode('|', $ids);

            if (isset($index[$key])) {
                throw new InvalidConfigurationException(
                    "Two results reference the same elimination match ({$ids[0]} vs {$ids[1]}, round {$round})",
                    ['round' => $round, 'participants' => $ids]
                );
            }

            $index[$key] = $result;
        }

        return $index;
    }

    /**
     * @param array<string, Result> $resultIndex
     *
     * @throws InvalidConfigurationException When the matching result is a draw
     */
    private function lookupWinner(
        array $resultIndex,
        int $round,
        Participant $first,
        Participant $second
    ): ?Participant {
        $ids = [$first->getId(), $second->getId()];
        sort($ids);
        $result = $resultIndex[$round . ':' . implode('|', $ids)] ?? null;

        if ($result === null) {
            return null;
        }

        $winner = $result->getWinner();
        if ($winner === null) {
            throw new InvalidConfigurationException(
                "Elimination events cannot end in a draw ({$first->getLabel()} vs {$second->getLabel()}, round {$round})",
                ['round' => $round, 'participants' => $ids]
            );
        }

        return $winner;
    }
}
