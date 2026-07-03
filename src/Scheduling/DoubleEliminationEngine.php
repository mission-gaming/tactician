<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Stage\EliminationPlan;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use Override;

/**
 * Double elimination preset: a winners' route, a losers' route, and a
 * grand final - the same graph an application could compose by hand from
 * single-round stages and outcome selectors, canned behind the standard
 * stage driver loop.
 *
 * Participants drop into the losers bracket after their first loss and
 * are only eliminated after their second. The winners bracket folds by
 * list position (position is authoritative); losers-bracket rounds
 * alternate between minor rounds (losers-bracket survivors pair up) and
 * major rounds (survivors meet the latest winners-bracket droppers, with
 * the dropper order reversed on even winners rounds to defer rematches).
 * The winners and losers champions meet in a grand final; when the losers
 * champion wins it, both finalists have one loss, so a reset match
 * decides the title (disable via EliminationOptions(grandFinalReset:
 * false)).
 *
 * Stages are strictly sequenced and each playable stage takes the next
 * round number. Ties are played over one event or two mirrored legs
 * (legsPerTie); re-seeding is a single-elimination preset parameter and
 * is rejected here. There is deliberately no champion accessor - rank 1
 * of the outcome's standings, or MatchOutcomeSelector::winners() over the
 * final round, is the consumer's derivation.
 */
final readonly class DoubleEliminationEngine implements StageEngineInterface
{
    use EliminationBracketSupport;

    /**
     * @throws InvalidConfigurationException When reseedEachRound is requested
     */
    public function __construct(
        private EliminationOptions $options = new EliminationOptions(),
        private StandingsCalculator $standingsCalculator = new StandingsCalculator()
    ) {
        if ($options->reseedEachRound) {
            throw new InvalidConfigurationException(
                'Re-seeding conflicts with the fixed dropper choreography of double elimination; it is a single-elimination preset parameter',
                []
            );
        }
    }

    /**
     * @throws InvalidConfigurationException When fewer than 2 participants have been seen
     */
    #[Override]
    public function getPlan(StageState $state): EliminationPlan
    {
        return new EliminationPlan(
            $state->getAllSeenParticipants(),
            'double-elimination',
            $this->options->legsPerTie
        );
    }

    /**
     * Pair the next unresolved stage of the bracket.
     *
     * @throws InvalidConfigurationException When inputs are malformed, a stage is partially
     *                                       resolved (record the missing results via
     *                                       StageState::withAdditionalResults()), a tie is
     *                                       undecided, or the bracket is complete
     */
    #[Override]
    public function pairNextRound(StageState $state): RoundPairing
    {
        $resolution = $this->resolveState($state);

        if ($resolution['pending'] === null) {
            throw new InvalidConfigurationException(
                'Bracket is complete; no further rounds exist',
                []
            );
        }

        return $resolution['pending'];
    }

    /**
     * @throws InvalidConfigurationException When the recorded state is malformed
     *                                       (partially resolved stages, undecided ties)
     */
    #[Override]
    public function isComplete(StageState $state): bool
    {
        return $this->resolveState($state)['pending'] === null;
    }

    /**
     * The uniform completion product; null while the bracket is unfinished.
     *
     * @throws InvalidConfigurationException When the recorded state is malformed
     */
    #[Override]
    public function getOutcome(StageState $state): ?StageOutcome
    {
        if (!$this->isComplete($state)) {
            return null;
        }

        $standings = $this->standingsCalculator->calculate(
            $state->getAllSeenParticipants(),
            $state->getResults()
        );

        return new StageOutcome(
            $standings,
            $state->getResults(),
            $state->getByeCounts(),
            $state->getLastRound()
        );
    }

    /**
     * Walk the fixed stage sequence, advancing through fully resolved stages.
     *
     * @return array{pending: RoundPairing|null}
     *
     * @throws InvalidConfigurationException
     */
    private function resolveState(StageState $state): array
    {
        $participants = array_values($state->getParticipants());
        $this->validateParticipants($participants);

        $slots = $this->buildInitialSlots($participants);
        $winnersRounds = (int) log(count($slots), 2);
        $resultIndex = $this->indexResults($state->getResults());
        $roundNumber = 0;

        // Winners round 1
        $stage = $this->resolveStage($slots, $this->winnersStageName(1, $winnersRounds), $roundNumber, $resultIndex);
        if ($stage['pending'] !== null) {
            return ['pending' => $stage['pending']];
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
            if ($stage['pending'] !== null) {
                return ['pending' => $stage['pending']];
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
                if ($stage['pending'] !== null) {
                    return ['pending' => $stage['pending']];
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
                if ($stage['pending'] !== null) {
                    return ['pending' => $stage['pending']];
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
                    if ($stage['pending'] !== null) {
                        return ['pending' => $stage['pending']];
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
        if ($stage['pending'] !== null) {
            return ['pending' => $stage['pending']];
        }
        $grandFinalWinner = $stage['winners'][0];

        if ($grandFinalWinner === null) {
            throw new \LogicException('Grand final resolved without a winner');
        }

        if (!$this->options->grandFinalReset || $grandFinalWinner->getId() === $winnersChampion->getId()) {
            return ['pending' => null];
        }

        // The losers champion won: both finalists now have one loss, so a
        // reset match decides the title.
        $stage = $this->resolveStage(
            [$winnersChampion, $losersChampion],
            'grand final reset',
            $roundNumber,
            $resultIndex
        );

        return ['pending' => $stage['pending']];
    }

    /**
     * Resolve one stage of the bracket from its slots.
     *
     * Stages with no playable match (bye propagation) auto-advance without
     * consuming a round number.
     *
     * @param array<Participant|null> $slots
     * @param array<string, \MissionGaming\Tactician\DTO\Result> $resultIndex
     * @return array{pending: RoundPairing|null, winners: array<Participant|null>, losers: array<Participant|null>}
     *
     * @throws InvalidConfigurationException When the stage is partially resolved or a tie is broken
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
                if ($this->lookupAdvancer($resultIndex, $roundNumber, $pair[0], $pair[1], $this->options->legsPerTie) !== null) {
                    ++$resolved;
                }
            }

            if ($resolved === 0) {
                return [
                    'pending' => $this->buildStagePairing($roundNumber, $stageName, $pairs),
                    'winners' => [],
                    'losers' => [],
                ];
            }

            if ($resolved < count($playable)) {
                throw new InvalidConfigurationException(
                    "Stage '{$stageName}' (round {$roundNumber}) is partially resolved: {$resolved} of " . count($playable)
                        . ' ties have complete results. Record the remaining results before pairing the next round.',
                    ['round' => $roundNumber, 'stage' => $stageName, 'resolved' => $resolved, 'playable' => count($playable)]
                );
            }
        }

        $winners = [];
        $losers = [];
        foreach ($pairs as $pair) {
            [$first, $second] = [$pair[0], $pair[1] ?? null];
            if ($first !== null && $second !== null) {
                $advancer = $this->lookupAdvancer($resultIndex, $roundNumber, $first, $second, $this->options->legsPerTie);
                $winners[] = $advancer;
                $losers[] = $advancer?->getId() === $first->getId() ? $second : $first;
            } else {
                $winners[] = $first ?? $second;
                $losers[] = null;
            }
        }

        return ['pending' => null, 'winners' => $winners, 'losers' => $losers];
    }

    /**
     * @param array<array<Participant|null>> $pairs
     */
    private function buildStagePairing(int $roundNumber, string $stageName, array $pairs): RoundPairing
    {
        $round = new Round($roundNumber, ['label' => $stageName]);

        $events = [];
        $byes = [];
        foreach ($pairs as $pair) {
            [$first, $second] = [$pair[0] ?? null, $pair[1] ?? null];
            if ($first !== null && $second !== null) {
                foreach ($this->buildTieEvents($first, $second, $round, $this->options->legsPerTie) as $event) {
                    $events[] = $event;
                }
                continue;
            }

            $advancer = $first ?? $second;
            if ($advancer !== null) {
                $byes[] = $advancer;
            }
        }

        return new RoundPairing($roundNumber, $stageName, $events, $byes);
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
