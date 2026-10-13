<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvariantViolationException;
use MissionGaming\Tactician\Stage\EliminationPlan;
use MissionGaming\Tactician\Stage\EngineFingerprint;
use MissionGaming\Tactician\Stage\FingerprintedEngine;
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
 * (legsPerTie); one that finishes level, in either bracket, the grand
 * final or its reset, is decided by the decider recorded on a result of
 * the tie (DTO\Decider; see TieDecision), and not advancing from it is the
 * loss that drops or eliminates the other participant. A level tie without
 * one is refused with the reason `UndecidedTie` and, in the context of the
 * exception, its round and its two participants: the grand final and its
 * reset are the same two, in two rounds. Re-seeding and the
 * third-place tie are single-elimination preset parameters and are
 * rejected here: the losers final already decides third place.
 *
 * There is deliberately no champion accessor. The participant who
 * advances from the last round played (the grand final, or its reset) is
 * rank 1 of the outcome's standings, which are in bracket placement order
 * (see getOutcome()), and is what MatchOutcomeSelector::winners() returns
 * for the outcome's final round. The records in that table do not decide
 * the placement: with 8 entrants and default options, a participant who
 * loses in round 1, wins the losers bracket and the first grand final, and
 * loses the reset has 5 wins and is rank 2; the participant who beat it
 * in the reset has 4 and is rank 1.
 *
 * @experimental
 */
final readonly class DoubleEliminationEngine implements StageEngineInterface, FingerprintedEngine
{
    use EliminationBracketSupport;

    /**
     * @param StandingsCalculator $standingsCalculator Computes the records of the outcome's standings
     *                                                 and orders the entrants who went out at the
     *                                                 same stage; it pairs nothing
     *
     * @throws InvalidConfigurationException When reseedEachRound or thirdPlace is requested
     *                                       (reason `IncompatibleOptions`)
     */
    public function __construct(
        private EliminationOptions $options = new EliminationOptions(),
        private StandingsCalculator $standingsCalculator = new StandingsCalculator()
    ) {
        if ($options->thirdPlace) {
            throw new InvalidConfigurationException(
                'A third-place tie is a single-elimination preset parameter: the losers bracket of double elimination'
                    . ' already decides third place, which is the entrant who goes out in the losers final',
                [],
                reason: InvalidConfigurationReason::IncompatibleOptions
            );
        }

        if ($options->reseedEachRound) {
            throw new InvalidConfigurationException(
                'Re-seeding conflicts with the fixed dropper choreography of double elimination; it is a single-elimination preset parameter',
                [],
                reason: InvalidConfigurationReason::IncompatibleOptions
            );
        }
    }

    /**
     * What this engine stamps a stage state with, and requires of a state
     * that carries a stamp (see FingerprintedEngine for the contract).
     *
     * With default options it names the format and nothing else. Part of
     * it when not at the default: legsPerTie (default 1) and
     * grandFinalReset (default true).
     *
     * Not part of it: reseedEachRound and thirdPlace, which this engine
     * rejects, and the standings calculator, which orders the outcome and
     * pairs nothing.
     */
    #[Override]
    public function getFingerprint(): string
    {
        return EngineFingerprint::of('double-elimination')
            ->with('legs-per-tie', $this->options->legsPerTie, 1)
            ->with('grand-final-reset', $this->options->grandFinalReset, true)
            ->toString();
    }

    /**
     * The shape of the bracket for every participant the state has seen.
     * Whether the grand final is reset depends on its result, so the plan
     * reports null for the number of rounds and of events.
     *
     * @throws InvalidConfigurationException When fewer than 2 participants have been seen, or
     *                                       the state is stamped with another engine's fingerprint
     */
    #[Override]
    public function getPlan(StageState $state): EliminationPlan
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        return new EliminationPlan(
            $state->getAllSeenParticipants(),
            'double-elimination',
            $this->options->legsPerTie
        );
    }

    /**
     * Pair the next unresolved stage of the bracket.
     *
     * The bracket is replayed from the state's participant list and its
     * results on every call, so the same state gives the same pairing, and
     * a state that has the stage's pairing recorded but none of its results
     * gives that stage again. The call does not record anything: pass the
     * pairing and its results to StageState::withRoundPlayed().
     *
     * Round numbers are 1-based and count the stages that have an event to
     * play, in the order they are played; a stage in which every
     * participant advances without playing takes no number. The round
     * carries its label ('winners round 1', 'losers round 2', 'winners
     * final', 'losers final', 'grand final', 'grand final reset'). The
     * events are in bracket order; a two-legged tie is two consecutive
     * events with the roles reversed, marked 'tie_leg' 1 and 2 in their
     * metadata. The byes are the participants who advance from the stage
     * without playing.
     *
     * @throws InvalidConfigurationException When the state has fewer than 2 participants or is
     *                                       stamped with another engine's fingerprint, a result
     *                                       has no round number, is not of a two-participant
     *                                       event or is recorded twice, a stage is partially
     *                                       resolved (record the missing results via
     *                                       StageState::withAdditionalResults()), a completed tie
     *                                       is level without a decider (`UndecidedTie`) or
     *                                       carries a decider TieDecision refuses
     *                                       (`InvalidResult`), or the bracket is complete
     */
    #[Override]
    public function pairNextRound(StageState $state): RoundPairing
    {
        $resolution = $this->resolveState($state);

        if ($resolution['pending'] === null) {
            throw new InvalidConfigurationException(
                'Bracket is complete; no further rounds exist',
                [],
                reason: InvalidConfigurationReason::BracketComplete
            );
        }

        return $resolution['pending'];
    }

    /**
     * Whether the title is decided: the grand final has a result and no
     * reset is due, or the reset has one. No reset is due when the winners
     * champion won the grand final or grandFinalReset is off.
     *
     * @throws InvalidConfigurationException When the recorded state is malformed: every case
     *                                       pairNextRound() names but the complete bracket
     */
    #[Override]
    public function isComplete(StageState $state): bool
    {
        return $this->resolveState($state)['pending'] === null;
    }

    /**
     * The uniform completion product; null while the bracket is unfinished.
     *
     * The standings are in bracket placement order: the entrant who
     * advanced from the last tie played (the grand final, or its reset) is
     * rank 1 and the other finalist rank 2, then the entrant who went out
     * in the losers final, and so on back through the rounds of the losers
     * bracket to its first. Entrants put out by the same round of the
     * losers bracket share a tier (Standings::getTiers()): the standings
     * calculator orders them from their records, and where that leaves
     * them level, their position in the participant list does
     * (Standings::getTiedSets() reports those). No record moves an entrant
     * from one tier to another.
     *
     * The records are win/draw/loss records over every result of both
     * brackets. A single-leg event that finished level and was decided by
     * its tie decision counts in them as a win for the participant who
     * advanced; the legs of a two-legged tie are counted as recorded. The
     * outcome's results are the results as recorded.
     *
     * @throws InvalidConfigurationException When the recorded state is malformed (see isComplete())
     */
    #[Override]
    public function getOutcome(StageState $state): ?StageOutcome
    {
        $resolution = $this->resolveState($state);
        if ($resolution['pending'] !== null) {
            return null;
        }

        $entrants = $state->getAllSeenParticipants();

        return new StageOutcome(
            $this->placementStandings(
                $this->standingsCalculator,
                $entrants,
                $this->resultsForStandings($state->getResults(), $resolution['levelAdvancers']),
                $resolution['placement'],
                $state->getAdjustments()
            ),
            $state->getResults(),
            $state->getByeCounts(),
            $state->getLastRound(),
            [],
            $entrants
        );
    }

    /**
     * Walk the fixed stage sequence, advancing through fully resolved stages.
     *
     * @return array{pending: RoundPairing|null, levelAdvancers: array<string, Participant>, placement: array<int|string, int>}
     *                                                                                                                         The placement is the tier of every
     *                                                                                                                         entrant of a finished bracket, 0 for
     *                                                                                                                         the one who advanced from the last tie
     *
     * @throws InvalidConfigurationException
     */
    private function resolveState(StageState $state): array
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        $participants = array_values($state->getParticipants());
        $this->validateParticipants($participants);

        $slots = $this->buildInitialSlots($participants);
        $winnersRounds = (int) log(count($slots), 2);
        $resultIndex = $this->indexResults($state->getResults());
        $roundNumber = 0;
        $levelAdvancers = [];
        // One deprecation for the replay, however many ties the deprecated
        // 'tie_winner' metadata decides in it
        $tieWinnerDeprecationRaised = false;

        // Who went out, and after how many of the stages that put an
        // entrant out: a loss in the winners bracket only drops an entrant,
        // so those stages are not counted.
        $wentOutAt = [];
        $exits = 0;
        $noteExits = static function (array $losers) use (&$wentOutAt, &$exits): void {
            ++$exits;
            foreach ($losers as $loser) {
                if ($loser instanceof Participant) {
                    $wentOutAt[$loser->getId()] = $exits;
                }
            }
        };
        // Nothing reads the level advancers of a bracket that is not
        // finished: they are for the table of the outcome.
        $unfinished = static fn(RoundPairing $pending): array => [
            'pending' => $pending,
            'levelAdvancers' => [],
            'placement' => [],
        ];

        // Winners round 1
        $stage = $this->resolveStage($slots, $this->winnersStageName(1, $winnersRounds), $roundNumber, $resultIndex, $levelAdvancers, $tieWinnerDeprecationRaised);
        if ($stage['pending'] !== null) {
            return $unfinished($stage['pending']);
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
                $resultIndex,
                $levelAdvancers,
                $tieWinnerDeprecationRaised
            );
            if ($stage['pending'] !== null) {
                return $unfinished($stage['pending']);
            }
            $noteExits($stage['losers']);
            $losersSurvivors = $stage['winners'];
            $losersChampion = null;

            for ($winnersRound = 2; $winnersRound <= $winnersRounds; ++$winnersRound) {
                $stage = $this->resolveStage(
                    $winnersSlots,
                    $this->winnersStageName($winnersRound, $winnersRounds),
                    $roundNumber,
                    $resultIndex,
                    $levelAdvancers,
                    $tieWinnerDeprecationRaised
                );
                if ($stage['pending'] !== null) {
                    return $unfinished($stage['pending']);
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
                    $resultIndex,
                    $levelAdvancers,
                    $tieWinnerDeprecationRaised
                );
                if ($stage['pending'] !== null) {
                    return $unfinished($stage['pending']);
                }
                $noteExits($stage['losers']);

                if ($winnersRound < $winnersRounds) {
                    // Minor round: major winners pair up
                    ++$losersStructuralRound;
                    $stage = $this->resolveStage(
                        $stage['winners'],
                        $this->losersStageName($losersStructuralRound, $winnersRounds),
                        $roundNumber,
                        $resultIndex,
                        $levelAdvancers,
                        $tieWinnerDeprecationRaised
                    );
                    if ($stage['pending'] !== null) {
                        return $unfinished($stage['pending']);
                    }
                    $noteExits($stage['losers']);
                    $losersSurvivors = $stage['winners'];
                } else {
                    $losersChampion = $stage['winners'][0];
                }
            }
        }

        $winnersChampion = $winnersSlots[0];
        if ($winnersChampion === null || $losersChampion === null) {
            // Not reached: every pair of the first winners round holds a
            // participant, so every later winners round is played in full
            // and the winners final gives each bracket its champion
            throw new InvariantViolationException('Bracket resolution lost track of a finalist');
        }

        // Grand final
        $stage = $this->resolveStage(
            [$winnersChampion, $losersChampion],
            'grand final',
            $roundNumber,
            $resultIndex,
            $levelAdvancers,
            $tieWinnerDeprecationRaised
        );
        if ($stage['pending'] !== null) {
            return $unfinished($stage['pending']);
        }
        $grandFinalWinner = $stage['winners'][0];

        if ($grandFinalWinner === null) {
            // Not reached: the grand final is a playable pair, and a stage
            // is only resolved when every playable pair has an advancer
            throw new InvariantViolationException('Grand final resolved without a winner');
        }

        if ($this->options->grandFinalReset && $grandFinalWinner->getId() !== $winnersChampion->getId()) {
            // The losers champion won: both finalists now have one loss,
            // so a reset match decides the title.
            $stage = $this->resolveStage(
                [$winnersChampion, $losersChampion],
                'grand final reset',
                $roundNumber,
                $resultIndex,
                $levelAdvancers,
                $tieWinnerDeprecationRaised
            );
            if ($stage['pending'] !== null) {
                return $unfinished($stage['pending']);
            }
        }

        // The last tie played (the grand final, or its reset) puts its
        // loser out and leaves the entrant who advanced from it first.
        // Everyone else is placed by the stage that put them out, the
        // latest first.
        $noteExits($stage['losers']);
        $placement = [];
        foreach ($wentOutAt as $id => $exit) {
            $placement[$id] = $exits - $exit + 1;
        }
        foreach ($stage['winners'] as $titleHolder) {
            if ($titleHolder !== null) {
                $placement[$titleHolder->getId()] = 0;
            }
        }

        return ['pending' => null, 'levelAdvancers' => $levelAdvancers, 'placement' => $placement];
    }

    /**
     * Resolve one stage of the bracket from its slots.
     *
     * Stages with no playable match (bye propagation) auto-advance without
     * consuming a round number.
     *
     * @param array<Participant|null> $slots
     * @param array<string, \MissionGaming\Tactician\DTO\Result> $resultIndex
     * @param array<string, Participant> $levelAdvancers Collects who advanced from each level
     *                                                   single-leg event (see resultsForStandings())
     * @param bool $tieWinnerDeprecationRaised Whether this replay has raised the deprecation of
     *                                         the 'tie_winner' metadata key (see lookupAdvancer())
     * @return array{pending: RoundPairing|null, winners: array<Participant|null>, losers: array<Participant|null>}
     *
     * @throws InvalidConfigurationException When the stage is partially resolved or a tie is broken
     */
    private function resolveStage(
        array $slots,
        string $stageName,
        int &$roundNumber,
        array $resultIndex,
        array &$levelAdvancers,
        bool &$tieWinnerDeprecationRaised
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
                if ($this->lookupAdvancer($resultIndex, $roundNumber, $pair[0], $pair[1], $this->options->legsPerTie, $tieWinnerDeprecationRaised) !== null) {
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
                    ['round' => $roundNumber, 'stage' => $stageName, 'resolved' => $resolved, 'playable' => count($playable)],
                    reason: InvalidConfigurationReason::RoundPartiallyResolved
                );
            }
        }

        $winners = [];
        $losers = [];
        foreach ($pairs as $pair) {
            [$first, $second] = [$pair[0], $pair[1] ?? null];
            if ($first !== null && $second !== null) {
                $advancer = $this->lookupAdvancer($resultIndex, $roundNumber, $first, $second, $this->options->legsPerTie, $tieWinnerDeprecationRaised);
                $this->noteLevelAdvancer($levelAdvancers, $resultIndex, $roundNumber, $first, $second, $this->options->legsPerTie, $advancer);
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
