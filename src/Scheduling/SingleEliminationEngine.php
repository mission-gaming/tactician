<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

/**
 * Pairs single elimination rounds incrementally from recorded results.
 *
 * Later rounds depend on earlier winners, so the bracket is resolved from
 * results on every call. Round 1 uses fold seeding (in an eight-slot bracket:
 * 1 vs 8, 4 vs 5, 2 vs 7, 3 vs 6), placing seeds 1 and 2 in opposite halves
 * and seeds 1-4 in distinct quarters so top seeds can only meet in the
 * latest possible round. Fields that are not a power of two give byes to
 * the top seeds. Unseeded participants keep their input order behind seeded
 * ones.
 */
readonly class SingleEliminationEngine
{
    /**
     * Pair the next unresolved round of the bracket.
     *
     * @param array<Participant> $participants
     * @param array<Result> $results Results of every elimination event played so far
     *
     * @throws InvalidConfigurationException When inputs are malformed, a round is partially
     *                                       resolved, a result is drawn, or the tournament is complete
     */
    public function pairNextRound(array $participants, array $results): EliminationRoundPairing
    {
        $resolution = $this->resolveBracket($participants, $results);

        $champion = $resolution['champion'];
        if ($champion !== null) {
            throw new InvalidConfigurationException(
                "Tournament is complete; champion is {$champion->getLabel()}",
                ['champion' => $champion->getId()]
            );
        }

        $roundNumber = $resolution['round'];
        $totalRounds = $resolution['total_rounds'];
        $stage = $this->stageName($roundNumber, $totalRounds);
        $round = new Round($roundNumber, ['stage' => $stage]);

        $events = [];
        $byes = [];
        foreach (array_chunk($resolution['slots'], 2) as $pair) {
            [$first, $second] = [$pair[0], $pair[1] ?? null];
            if ($first !== null && $second !== null) {
                $events[] = new Event([$first, $second], $round);
                continue;
            }

            $advancer = $first ?? $second;
            if ($advancer !== null) {
                $byes[] = $advancer;
            }
        }

        return new EliminationRoundPairing($roundNumber, $stage, $events, $byes);
    }

    /**
     * Get the champion, or null while the bracket is still unresolved.
     *
     * @param array<Participant> $participants
     * @param array<Result> $results
     *
     * @throws InvalidConfigurationException When inputs are malformed, a round is partially
     *                                       resolved, or a result is drawn
     */
    public function getChampion(array $participants, array $results): ?Participant
    {
        return $this->resolveBracket($participants, $results)['champion'];
    }

    /**
     * Get the total number of rounds the bracket needs.
     *
     * @param array<Participant> $participants
     *
     * @throws InvalidConfigurationException When fewer than 2 participants are given
     */
    public function getTotalRounds(array $participants): int
    {
        $this->validateParticipants(array_values($participants));

        return (int) log($this->bracketSize(count($participants)), 2);
    }

    /**
     * Advance the bracket through fully resolved rounds.
     *
     * @param array<Participant> $participants
     * @param array<Result> $results
     * @return array{round: int, total_rounds: int, slots: array<Participant|null>, champion: Participant|null}
     *
     * @throws InvalidConfigurationException
     */
    private function resolveBracket(array $participants, array $results): array
    {
        $participants = array_values($participants);
        $this->validateParticipants($participants);

        $slots = $this->buildInitialSlots($participants);
        $totalRounds = (int) log(count($slots), 2);
        $resultIndex = $this->indexResults($results);

        for ($round = 1; $round <= $totalRounds; ++$round) {
            $pairs = array_chunk($slots, 2);
            $playable = [];
            $resolved = 0;

            foreach ($pairs as $pair) {
                if ($pair[0] !== null && ($pair[1] ?? null) !== null) {
                    $playable[] = $pair;
                    if ($this->lookupWinner($resultIndex, $round, $pair[0], $pair[1]) !== null) {
                        ++$resolved;
                    }
                }
            }

            if ($resolved < count($playable)) {
                if ($resolved > 0) {
                    throw new InvalidConfigurationException(
                        "Round {$round} is partially resolved: {$resolved} of " . count($playable)
                            . ' events have results. Record the remaining results before pairing the next round.',
                        ['round' => $round, 'resolved' => $resolved, 'playable' => count($playable)]
                    );
                }

                return [
                    'round' => $round,
                    'total_rounds' => $totalRounds,
                    'slots' => $slots,
                    'champion' => null,
                ];
            }

            $nextSlots = [];
            foreach ($pairs as $pair) {
                if ($pair[0] !== null && ($pair[1] ?? null) !== null) {
                    $nextSlots[] = $this->lookupWinner($resultIndex, $round, $pair[0], $pair[1]);
                } else {
                    $nextSlots[] = $pair[0] ?? $pair[1] ?? null;
                }
            }

            $slots = $nextSlots;
        }

        return [
            'round' => $totalRounds,
            'total_rounds' => $totalRounds,
            'slots' => $slots,
            'champion' => $slots[0],
        ];
    }

    /**
     * @param array<Participant> $participants
     *
     * @throws InvalidConfigurationException
     */
    private function validateParticipants(array $participants): void
    {
        if (count($participants) < 2) {
            throw new InvalidConfigurationException(
                'Single elimination requires at least 2 participants',
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
     */
    private function indexResults(array $results): array
    {
        $index = [];
        foreach ($results as $result) {
            $event = $result->getEvent();
            $eventParticipants = $event->getParticipants();
            $round = $event->getRound()?->getNumber();
            if ($round === null || count($eventParticipants) !== 2) {
                continue;
            }

            $ids = [$eventParticipants[0]->getId(), $eventParticipants[1]->getId()];
            sort($ids);
            $index[$round . ':' . implode('|', $ids)] = $result;
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

    private function stageName(int $roundNumber, int $totalRounds): string
    {
        return match ($totalRounds - $roundNumber) {
            0 => 'final',
            1 => 'semifinal',
            2 => 'quarterfinal',
            default => 'round of ' . 2 ** ($totalRounds - $roundNumber + 1),
        };
    }
}
