<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

/**
 * Pairs double elimination rounds incrementally from recorded results.
 *
 * Participants drop into a losers bracket after their first loss and are
 * only eliminated after their second. The winners bracket uses the same
 * fold seeding as single elimination; losers-bracket rounds alternate
 * between minor rounds (losers-bracket survivors pair up) and major rounds
 * (survivors meet the latest winners-bracket droppers, with the dropper
 * order reversed on even winners rounds to defer rematches). The winners
 * and losers champions meet in a grand final; when the losers champion
 * wins it, both finalists have one loss, so a reset match decides the
 * title (disable via the constructor for a single grand final).
 *
 * Stages are strictly sequenced and each playable stage takes the next
 * round number, so results must carry the round numbers this engine
 * assigns to its events.
 */
readonly class DoubleEliminationEngine
{
    use EliminationBracketSupport;

    public function __construct(private bool $grandFinalReset = true)
    {
    }

    /**
     * Pair the next unresolved stage of the bracket.
     *
     * @param array<Participant> $participants
     * @param array<Result> $results Results of every event played so far
     *
     * @throws InvalidConfigurationException When inputs are malformed, a stage is partially
     *                                       resolved, a result is drawn, or the tournament is complete
     */
    public function pairNextRound(array $participants, array $results): EliminationRoundPairing
    {
        $state = $this->resolveState($participants, $results);

        $champion = $state['champion'];
        if ($champion !== null) {
            throw new InvalidConfigurationException(
                "Tournament is complete; champion is {$champion->getLabel()}",
                ['champion' => $champion->getId()]
            );
        }

        $pending = $state['pending'];
        if ($pending === null) {
            throw new InvalidConfigurationException('Bracket resolution produced neither a champion nor a pending stage');
        }

        $round = new Round($pending['round'], ['stage' => $pending['stage']]);
        $events = [];
        $byes = [];
        foreach ($pending['pairs'] as $pair) {
            [$first, $second] = [$pair[0] ?? null, $pair[1] ?? null];
            if ($first !== null && $second !== null) {
                $events[] = new Event([$first, $second], $round);
                continue;
            }

            $advancer = $first ?? $second;
            if ($advancer !== null) {
                $byes[] = $advancer;
            }
        }

        return new EliminationRoundPairing($pending['round'], $pending['stage'], $events, $byes);
    }

    /**
     * Get the champion, or null while the bracket is still unresolved.
     *
     * @param array<Participant> $participants
     * @param array<Result> $results
     *
     * @throws InvalidConfigurationException When inputs are malformed, a stage is partially
     *                                       resolved, or a result is drawn
     */
    public function getChampion(array $participants, array $results): ?Participant
    {
        return $this->resolveState($participants, $results)['champion'];
    }

    /**
     * Walk the fixed stage sequence, advancing through fully resolved stages.
     *
     * @param array<Participant> $participants
     * @param array<Result> $results
     * @return array{pending: array{round: int, stage: string, pairs: array<array<Participant|null>>}|null, champion: Participant|null}
     *
     * @throws InvalidConfigurationException
     */
    private function resolveState(array $participants, array $results): array
    {
        $participants = array_values($participants);
        $this->validateParticipants($participants);

        $slots = $this->buildInitialSlots($participants);
        $winnersRounds = (int) log(count($slots), 2);
        $resultIndex = $this->indexResults($results);
        $roundNumber = 0;

        // Winners round 1
        $stage = $this->resolveStage($slots, $this->winnersStageName(1, $winnersRounds), $roundNumber, $resultIndex);
        if ($stage['pending']) {
            return ['pending' => $stage['pendingStage'], 'champion' => null];
        }
        $winnersSlots = $stage['winners'];

        if ($winnersRounds === 1) {
            $losersChampion = $stage['losers'][0];
        } else {
            // Losers round 1: winners-round-1 losers pair up
            $losersStructuralRound = 1;
            $stage = $this->resolveStage(
                $stage['losers'],
                $this->losersStageName($losersStructuralRound, $winnersRounds),
                $roundNumber,
                $resultIndex
            );
            if ($stage['pending']) {
                return ['pending' => $stage['pendingStage'], 'champion' => null];
            }
            $losersSurvivors = $stage['winners'];
            $losersChampion = null;

            for ($winnersRound = 2; $winnersRound <= $winnersRounds; ++$winnersRound) {
                $stage = $this->resolveStage(
                    $winnersSlots,
                    $this->winnersStageName($winnersRound, $winnersRounds),
                    $roundNumber,
                    $resultIndex
                );
                if ($stage['pending']) {
                    return ['pending' => $stage['pendingStage'], 'champion' => null];
                }
                $winnersSlots = $stage['winners'];
                $droppers = $stage['losers'];

                // Major round: survivors meet the latest droppers. Reverse the
                // dropper order on even winners rounds to defer rematches.
                if ($winnersRound % 2 === 0) {
                    $droppers = array_reverse($droppers);
                }
                $majorSlots = [];
                foreach (array_values($losersSurvivors) as $index => $survivor) {
                    $majorSlots[] = $droppers[$index] ?? null;
                    $majorSlots[] = $survivor;
                }

                ++$losersStructuralRound;
                $stage = $this->resolveStage(
                    $majorSlots,
                    $this->losersStageName($losersStructuralRound, $winnersRounds),
                    $roundNumber,
                    $resultIndex
                );
                if ($stage['pending']) {
                    return ['pending' => $stage['pendingStage'], 'champion' => null];
                }

                if ($winnersRound < $winnersRounds) {
                    // Minor round: major winners pair up
                    ++$losersStructuralRound;
                    $stage = $this->resolveStage(
                        $stage['winners'],
                        $this->losersStageName($losersStructuralRound, $winnersRounds),
                        $roundNumber,
                        $resultIndex
                    );
                    if ($stage['pending']) {
                        return ['pending' => $stage['pendingStage'], 'champion' => null];
                    }
                    $losersSurvivors = $stage['winners'];
                } else {
                    $losersChampion = $stage['winners'][0];
                }
            }
        }

        $winnersChampion = $winnersSlots[0];
        if ($winnersChampion === null || $losersChampion === null) {
            throw new \LogicException('Bracket resolution lost track of a finalist');
        }

        // Grand final
        $stage = $this->resolveStage(
            [$winnersChampion, $losersChampion],
            'grand final',
            $roundNumber,
            $resultIndex
        );
        if ($stage['pending']) {
            return ['pending' => $stage['pendingStage'], 'champion' => null];
        }
        $grandFinalWinner = $stage['winners'][0];

        if ($grandFinalWinner === null) {
            throw new \LogicException('Grand final resolved without a winner');
        }

        if (!$this->grandFinalReset || $grandFinalWinner->getId() === $winnersChampion->getId()) {
            return ['pending' => null, 'champion' => $grandFinalWinner];
        }

        // The losers champion won: both finalists now have one loss, so a
        // reset match decides the title.
        $stage = $this->resolveStage(
            [$winnersChampion, $losersChampion],
            'grand final reset',
            $roundNumber,
            $resultIndex
        );
        if ($stage['pending']) {
            return ['pending' => $stage['pendingStage'], 'champion' => null];
        }

        return ['pending' => null, 'champion' => $stage['winners'][0]];
    }

    /**
     * Resolve one stage of the bracket from its slots.
     *
     * Stages with no playable match (bye propagation) auto-advance without
     * consuming a round number.
     *
     * @param array<Participant|null> $slots
     * @param array<string, Result> $resultIndex
     * @return array{pending: bool, pendingStage: array{round: int, stage: string, pairs: array<array<Participant|null>>}|null, winners: array<Participant|null>, losers: array<Participant|null>}
     *
     * @throws InvalidConfigurationException When the stage is partially resolved or a result is drawn
     */
    private function resolveStage(
        array $slots,
        string $stageName,
        int &$roundNumber,
        array $resultIndex
    ): array {
        $pairs = array_chunk($slots, 2);

        $playable = [];
        foreach ($pairs as $pair) {
            if ($pair[0] !== null && ($pair[1] ?? null) !== null) {
                $playable[] = $pair;
            }
        }

        if ($playable !== []) {
            ++$roundNumber;

            $resolved = 0;
            foreach ($playable as $pair) {
                if ($this->lookupWinner($resultIndex, $roundNumber, $pair[0], $pair[1]) !== null) {
                    ++$resolved;
                }
            }

            if ($resolved === 0) {
                return [
                    'pending' => true,
                    'pendingStage' => ['round' => $roundNumber, 'stage' => $stageName, 'pairs' => $pairs],
                    'winners' => [],
                    'losers' => [],
                ];
            }

            if ($resolved < count($playable)) {
                throw new InvalidConfigurationException(
                    "Stage '{$stageName}' (round {$roundNumber}) is partially resolved: {$resolved} of " . count($playable)
                        . ' events have results. Record the remaining results before pairing the next round.',
                    ['round' => $roundNumber, 'stage' => $stageName, 'resolved' => $resolved, 'playable' => count($playable)]
                );
            }
        }

        $winners = [];
        $losers = [];
        foreach ($pairs as $pair) {
            [$first, $second] = [$pair[0], $pair[1] ?? null];
            if ($first !== null && $second !== null) {
                $winner = $this->lookupWinner($resultIndex, $roundNumber, $first, $second);
                $winners[] = $winner;
                $losers[] = $winner?->getId() === $first->getId() ? $second : $first;
            } else {
                $winners[] = $first ?? $second;
                $losers[] = null;
            }
        }

        return ['pending' => false, 'pendingStage' => null, 'winners' => $winners, 'losers' => $losers];
    }

    private function winnersStageName(int $round, int $totalWinnersRounds): string
    {
        return $round === $totalWinnersRounds ? 'winners final' : "winners round {$round}";
    }

    private function losersStageName(int $structuralRound, int $totalWinnersRounds): string
    {
        $totalLosersRounds = 2 * ($totalWinnersRounds - 1);

        return $structuralRound === $totalLosersRounds ? 'losers final' : "losers round {$structuralRound}";
    }
}
