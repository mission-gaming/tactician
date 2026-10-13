<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\UnresolvedRoundException;
use MissionGaming\Tactician\Stage\EliminationPlan;
use MissionGaming\Tactician\Stage\EngineFingerprint;
use MissionGaming\Tactician\Stage\FingerprintedEngine;
use MissionGaming\Tactician\Stage\HistoryReadingEngine;
use MissionGaming\Tactician\Stage\PairingReadiness;
use MissionGaming\Tactician\Stage\RecordedRound;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\Standings;
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
 * final or its reset, is decided by the tie decision recorded on its
 * result (see TieDecision), and not advancing from it is the loss that
 * drops or eliminates the other participant. Re-seeding and the
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
final readonly class DoubleEliminationEngine implements StageEngineInterface, FingerprintedEngine, HistoryReadingEngine
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
     * The bracket is read from the state on every call, so the same state
     * gives the same pairing: the stages that are recorded are read as
     * recorded, and the next one is derived from them. A recorded stage
     * that pairs its entrants otherwise than this engine would have is not
     * refused where it is still a pairing of that stage (see
     * getRecordedRounds()). The call does not record anything: pass the
     * pairing and its results to StageState::withRoundPlayed().
     *
     * A stage that is recorded and not finished is not paired again: the
     * call is refused with the events that await a result, whether the
     * stage has some of its results or none. Ask getReadiness() to learn
     * the same without catching anything.
     *
     * An entrant who has withdrawn from the live bracket
     * (StageState::withoutParticipant()) keeps its slot. A tie it is in
     * and has no complete result for is not played: the other entrant
     * advances and is listed among the byes of the stage, and the
     * withdrawn entrant takes the loss, so it drops to the losers bracket
     * from the winners bracket and gives the next tie away there. Nothing
     * is drawn again.
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
     * @throws UnresolvedRoundException When the last recorded stage has a tie without a complete
     *                                  result (reason `RoundPartiallyResolved`; record the
     *                                  missing results via StageState::withAdditionalResults())
     * @throws InvalidConfigurationException When the state has fewer than 2 entrants or is
     *                                       stamped with another engine's fingerprint, a result
     *                                       has no round number, is not of a two-participant
     *                                       event or is recorded twice, a completed tie
     *                                       is level without a usable tie decision, a recorded
     *                                       stage leaves another number of entrants in the
     *                                       bracket than the stage has places for (reason
     *                                       `RecordedRoundMismatch`), or the bracket is complete
     */
    #[Override]
    public function pairNextRound(StageState $state): RoundPairing
    {
        $reading = $this->resolveState($state);

        if ($reading->pending instanceof RoundPairing) {
            return $reading->pending;
        }

        if ($reading->awaitingRound !== null) {
            throw new UnresolvedRoundException($reading->awaitingRound, $reading->unresolved);
        }

        throw new InvalidConfigurationException(
            'Bracket is complete; no further rounds exist',
            [],
            reason: InvalidConfigurationReason::BracketComplete
        );
    }

    /**
     * Whether the title is decided: the grand final has a result and no
     * reset is due, or the reset has one. No reset is due when the winners
     * champion won the grand final or grandFinalReset is off. A stage
     * that has some of its results, or none, is a bracket that is not
     * complete, and nothing is thrown for it.
     *
     * @throws InvalidConfigurationException When the recorded state is malformed: every case
     *                                       pairNextRound() names but the unfinished stage and
     *                                       the complete bracket
     */
    #[Override]
    public function isComplete(StageState $state): bool
    {
        return $this->resolveState($state)->isComplete();
    }

    /**
     * Whether the next stage can be paired now and, when it cannot, the
     * events that await a result (see HistoryReadingEngine).
     *
     * @throws InvalidConfigurationException When the recorded state is malformed (see isComplete())
     */
    #[Override]
    public function getReadiness(StageState $state): PairingReadiness
    {
        $reading = $this->resolveState($state);

        return match (true) {
            $reading->pending instanceof RoundPairing => PairingReadiness::ready($reading->pending->getRoundNumber()),
            $reading->awaitingRound !== null => PairingReadiness::awaitingResults($reading->awaitingRound, $reading->unresolved),
            default => PairingReadiness::complete(),
        };
    }

    /**
     * Every recorded stage as this engine reads it (see RecordedRound), up
     * to and including the one the bracket is waiting for.
     *
     * The derivation of a stage is the pairing this engine makes of it
     * from the stages recorded before. A recorded stage that pairs other
     * entrants is manual, and the stages after it are derived from it as
     * recorded: each of its ties stands where the earlier of its two
     * entrants stood. The later stages take their entrants by place
     * (which dropper meets which survivor), so a recorded stage has to
     * leave as many places as the stage has: one that leaves more or
     * fewer entrants in the bracket is refused (`RecordedRoundMismatch`).
     *
     * @return list<RecordedRound>
     *
     * @throws InvalidConfigurationException When the recorded state is malformed (see isComplete())
     */
    #[Override]
    public function getRecordedRounds(StageState $state): array
    {
        return $this->resolveState($state)->rounds;
    }

    /**
     * The table of the bracket as recorded so far, in placement order.
     *
     * For a finished bracket it is the table of the outcome (see
     * getOutcome()). Before that, the entrants who have not been
     * eliminated share the first tier, and those who have follow by the
     * stage that put them out, later first.
     *
     * @throws InvalidConfigurationException When the recorded state is malformed (see isComplete())
     */
    #[Override]
    public function getStandings(StageState $state): Standings
    {
        $reading = $this->resolveState($state);

        return $this->placementStandings(
            $this->standingsCalculator,
            $this->entrantsOf($state),
            $this->resultsForStandings($state->getResults(), $reading->levelAdvancers),
            $reading->placement,
            $state->getAdjustments()
        );
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
        $reading = $this->resolveState($state);
        if (!$reading->isComplete()) {
            return null;
        }

        $entrants = $this->entrantsOf($state);

        return new StageOutcome(
            $this->placementStandings(
                $this->standingsCalculator,
                $entrants,
                $this->resultsForStandings($state->getResults(), $reading->levelAdvancers),
                $reading->placement,
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
     * Walk the fixed stage sequence, advancing through the stages that are
     * finished: recorded with every tie settled, or with no tie to play.
     *
     * @throws InvalidConfigurationException When a tie is broken, a recorded stage does not fit
     *                                       the stage it is read as, or the state is stamped
     *                                       with another engine's fingerprint
     */
    private function resolveState(StageState $state): BracketReading
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        $entrants = $state->getEntrants();
        $this->validateParticipants($entrants);

        $reading = $this->beginReading($state);
        $slots = $this->buildInitialSlots($entrants);
        $winnersRounds = (int) log(count($slots), 2);

        // Who went out, and after how many of the stages that put an
        // entrant out: a loss in the winners bracket only drops an entrant,
        // so those stages are not counted.
        $wentOutAt = [];
        $exits = 0;
        $noteExits = static function (array $out) use (&$wentOutAt, &$exits): void {
            ++$exits;
            foreach ($out as $entrant) {
                if ($entrant instanceof Participant) {
                    $wentOutAt[$entrant->getId()] = $exits;
                }
            }
        };
        // Of a bracket that is not finished, the entrants who are not out
        // are level in the first tier, ahead of those who are.
        $unfinished = static function () use ($reading, $entrants, &$wentOutAt, &$exits): BracketReading {
            foreach ($entrants as $entrant) {
                $reading->placement[$entrant->getId()] = 0;
            }
            foreach ($wentOutAt as $id => $exit) {
                $reading->placement[$id] = $exits - $exit + 1;
            }

            return $reading;
        };

        // Winners round 1
        $stage = $this->resolveStage($slots, $this->winnersStageName(1, $winnersRounds), $reading);
        if ($stage['stopped']) {
            return $unfinished();
        }
        $winnersSlots = $stage['winners'];

        if ($winnersRounds === 1) {
            $losersChampion = $stage['losers'][0] ?? null;
        } else {
            // Losers round 1: winners-round-1 losers pair up
            $losersStructuralRound = 1;
            $stage = $this->resolveStage(
                $stage['losers'],
                $this->losersStageName($losersStructuralRound, $winnersRounds),
                $reading
            );
            if ($stage['stopped']) {
                return $unfinished();
            }
            $noteExits($stage['out']);
            $losersSurvivors = $stage['winners'];
            $losersChampion = null;

            for ($winnersRound = 2; $winnersRound <= $winnersRounds; ++$winnersRound) {
                $stage = $this->resolveStage(
                    $winnersSlots,
                    $this->winnersStageName($winnersRound, $winnersRounds),
                    $reading
                );
                if ($stage['stopped']) {
                    return $unfinished();
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
                    $reading
                );
                if ($stage['stopped']) {
                    return $unfinished();
                }
                $noteExits($stage['out']);

                if ($winnersRound < $winnersRounds) {
                    // Minor round: major winners pair up
                    ++$losersStructuralRound;
                    $stage = $this->resolveStage(
                        $stage['winners'],
                        $this->losersStageName($losersStructuralRound, $winnersRounds),
                        $reading
                    );
                    if ($stage['stopped']) {
                        return $unfinished();
                    }
                    $noteExits($stage['out']);
                    $losersSurvivors = $stage['winners'];
                } else {
                    $losersChampion = $stage['winners'][0] ?? null;
                }
            }
        }

        // Each bracket gives a champion unless every entrant left in it has
        // withdrawn; the grand final is then a stage with an empty slot,
        // and the other champion advances from it without playing.
        $winnersChampion = $winnersSlots[0] ?? null;

        // Grand final
        $stage = $this->resolveStage([$winnersChampion, $losersChampion], 'grand final', $reading);
        if ($stage['stopped']) {
            return $unfinished();
        }
        $grandFinalWinner = $stage['winners'][0] ?? null;

        if (
            $this->options->grandFinalReset
            && $grandFinalWinner instanceof Participant
            && $winnersChampion instanceof Participant
            && $losersChampion instanceof Participant
            && $grandFinalWinner->getId() !== $winnersChampion->getId()
        ) {
            // The losers champion won: both finalists now have one loss,
            // so a reset match decides the title.
            $stage = $this->resolveStage([$winnersChampion, $losersChampion], 'grand final reset', $reading);
            if ($stage['stopped']) {
                return $unfinished();
            }
        }

        // The last tie played (the grand final, or its reset) puts its
        // loser out and leaves the entrant who advanced from it first.
        // Everyone else is placed by the stage that put them out, the
        // latest first.
        $noteExits($stage['out']);
        foreach ($wentOutAt as $id => $exit) {
            $reading->placement[$id] = $exits - $exit + 1;
        }
        foreach ($stage['winners'] as $titleHolder) {
            if ($titleHolder !== null) {
                $reading->placement[$titleHolder->getId()] = 0;
            }
        }

        return $reading;
    }

    /**
     * Resolve one stage of the bracket from its slots.
     *
     * A stage that is recorded is read as recorded (see readRound()), and
     * the walk stops at it while a tie of it awaits a result. A stage that
     * is not recorded and has a tie to play is the stage to pair, and the
     * walk stops there. A stage with no tie to play (bye propagation, or
     * ties an entrant has withdrawn from) advances its entrants without
     * consuming a round number, unless it is recorded: it was recorded
     * when the entrants withdrew after it was paired.
     *
     * @param array<Participant|null> $slots
     * @return array{stopped: bool, winners: list<Participant|null>, losers: list<Participant|null>, out: list<Participant>}
     *                                                                                                                         The winners and the losers are by place,
     *                                                                                                                         one for each pair of slots; `out` lists
     *                                                                                                                         everyone who did not advance
     *
     * @throws InvalidConfigurationException When a tie is broken, or the recorded stage leaves
     *                                       another number of entrants than the stage has places
     */
    private function resolveStage(array $slots, string $stageName, BracketReading $reading): array
    {
        $slots = array_values($slots);
        $pairs = array_chunk($slots, 2);

        $ties = [];
        $toPlay = false;
        foreach ($pairs as $pair) {
            $first = $pair[0] ?? null;
            $second = $pair[1] ?? null;
            if ($first !== null && $second !== null) {
                $ties[] = [$first, $second];
                $toPlay = $toPlay || (!isset($reading->withdrawn[$first->getId()]) && !isset($reading->withdrawn[$second->getId()]));
            }
        }

        $recorded = $reading->recorded[$reading->number + 1] ?? null;
        $claimed = $recorded instanceof RoundPairing && ($toPlay || $this->holdsOneOf($recorded, $ties));
        $derivation = $this->buildStagePairing($reading->number + 1, $stageName, $pairs, $reading->withdrawn);
        $stopped = ['stopped' => true, 'winners' => [], 'losers' => [], 'out' => []];

        if (!$claimed && $toPlay) {
            $reading->pending = $derivation;

            return $stopped;
        }

        if ($claimed) {
            ++$reading->number;
        }
        $read = $this->readRound(
            $reading,
            $slots,
            $claimed ? $recorded : null,
            $claimed ? $reading->number : $reading->number + 1,
            $this->options->legsPerTie
        );

        if ($claimed && $recorded instanceof RoundPairing) {
            if (count($read['units']) !== count($pairs)) {
                throw new InvalidConfigurationException(
                    "The recorded round {$reading->number} does not fit the stage '{$stageName}': it leaves "
                        . count($read['units']) . ' places in the bracket where the stage has ' . count($pairs)
                        . '. A stage of a double elimination bracket may be paired otherwise than this engine pairs it,'
                        . ' and has to pair every entrant of the stage that the engine pairs.',
                    ['round' => $reading->number, 'stage' => $stageName, 'places' => count($pairs), 'recorded_places' => count($read['units'])],
                    reason: InvalidConfigurationReason::RecordedRoundMismatch
                );
            }

            $reading->rounds[] = new RecordedRound($recorded, $derivation, $read['manual'], $read['unresolved'], $read['uncontested']);

            if ($read['unresolved'] !== []) {
                $reading->awaitingRound = $reading->number;
                $reading->unresolved = $read['unresolved'];

                return $stopped;
            }
        }

        $winners = [];
        $losers = [];
        $out = [];
        foreach ($read['units'] as $unit) {
            $winners[] = $unit['winner'];
            $losers[] = $unit['losers'][0] ?? null;
            foreach ($unit['losers'] as $loser) {
                $out[] = $loser;
            }
        }

        return ['stopped' => false, 'winners' => $winners, 'losers' => $losers, 'out' => $out];
    }

    /**
     * The pairing of a stage: an event for each tie to play, in bracket
     * order, and among the byes everyone who advances without playing,
     * the other entrant of a tie an entrant has withdrawn from included.
     *
     * @param array<array<Participant|null>> $pairs
     * @param array<int|string, true> $withdrawn The IDs of the entrants who have withdrawn
     */
    private function buildStagePairing(int $roundNumber, string $stageName, array $pairs, array $withdrawn): RoundPairing
    {
        $round = new Round($roundNumber, ['label' => $stageName]);

        $events = [];
        $byes = [];
        foreach ($pairs as $pair) {
            [$first, $second] = [$pair[0] ?? null, $pair[1] ?? null];
            if ($first !== null && $second !== null) {
                $firstOut = isset($withdrawn[$first->getId()]);
                $secondOut = isset($withdrawn[$second->getId()]);
                if (!$firstOut && !$secondOut) {
                    foreach ($this->buildTieEvents($first, $second, $round, $this->options->legsPerTie) as $event) {
                        $events[] = $event;
                    }
                } elseif (!$firstOut || !$secondOut) {
                    $byes[] = $firstOut ? $second : $first;
                }
                continue;
            }

            $advancer = $first ?? $second;
            if ($advancer !== null && !isset($withdrawn[$advancer->getId()])) {
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
