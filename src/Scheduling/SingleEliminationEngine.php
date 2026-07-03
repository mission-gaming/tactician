<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Stage\RoundPairing;

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
    use EliminationBracketSupport;

    /**
     * Pair the next unresolved round of the bracket.
     *
     * @param array<Participant> $participants
     * @param array<Result> $results Results of every elimination event played so far
     *
     * @throws InvalidConfigurationException When inputs are malformed, a round is partially
     *                                       resolved, a result is drawn, or the tournament is complete
     */
    public function pairNextRound(array $participants, array $results): RoundPairing
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

        return new RoundPairing($roundNumber, $stage, $events, $byes);
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
