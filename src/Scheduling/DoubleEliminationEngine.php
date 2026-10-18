<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\UnresolvedRoundException;
use MissionGaming\Tactician\Stage\Bracket;
use MissionGaming\Tactician\Stage\BracketRoundLabel;
use MissionGaming\Tactician\Stage\BracketSide;
use MissionGaming\Tactician\Stage\BracketViewEngine;
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
 * final or its reset, is decided by the decider recorded on a result of
 * the tie (DTO\Decider; see TieDecision), and not advancing from it is the
 * loss that drops or eliminates the other participant. A level tie without
 * one awaits that decision as another tie awaits a result: the bracket is
 * not complete, reading it throws nothing, getReadiness() lists the events
 * of the tie (PairingReadiness::getUndecidedEvents()), and pairNextRound()
 * refuses with the reason `UndecidedTie` and, in the context of the
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
final readonly class DoubleEliminationEngine implements StageEngineInterface, FingerprintedEngine, HistoryReadingEngine, BracketViewEngine
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
     * is drawn again. A tie both of whose entrants have withdrawn before
     * either went through it is void (see Withdrawal): it sends nobody on
     * and drops nobody, so the entrant who would have met its winner, or
     * the dropper from it, goes on alone and is listed among the byes,
     * unless it had withdrawn by then: it then goes out there, as an
     * entrant who stands alone and has withdrawn always does. The two
     * entrants of a void winners tie go out with those the next losers
     * round puts out. The grand final, and its reset where it is played,
     * is decided the moment its results or the withdrawal of a finalist
     * decide it, and the bracket is over then: a later withdrawal takes
     * nothing back, the winner's included. Only a state stored by 0.2.x,
     * whose withdrawals carry no time, can hold a grand final both
     * finalists left at times nobody knows: it is void, and the bracket has
     * nobody in its first tier.
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
     * @throws InvalidConfigurationException When a tie of the last recorded stage has every result,
     *                                       is level and carries no decider (`UndecidedTie`, with
     *                                       the round and the two participants in the context;
     *                                       it is thrown before the exception above where the
     *                                       stage has both), the state has fewer than 2 entrants or is
     *                                       stamped with another engine's fingerprint, a result
     *                                       has no round number, is not of a two-participant
     *                                       event or is recorded twice, a tie carries a decider
     *                                       TieDecision refuses (`InvalidResult`), a recorded
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

        if ($reading->undecidedTie !== null) {
            throw new InvalidConfigurationException(
                $reading->undecidedTie['issue'],
                $reading->undecidedTie['context'],
                reason: InvalidConfigurationReason::UndecidedTie
            );
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
     * that has some of its results, or none, or a tie that is level and
     * awaits its decider, is a bracket that is not complete, and nothing is
     * thrown for it.
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
            $reading->awaitingRound !== null => PairingReadiness::awaitingResults($reading->awaitingRound, $reading->unresolved, $reading->undecided),
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
            $entrants,
            null,
            $this->settledTiesOf($reading)
        );
    }

    /**
     * The bracket as this engine reads the state (see Bracket): the winners
     * side, the losers side (for a field of more than 2) and the grand
     * final, whose round 2 is the reset where grandFinalReset is on.
     *
     * The rounds are in the order the engine plays them: winners round 1,
     * losers round 1, then each later winners round followed by the losers
     * rounds it feeds, the grand final and the reset. The first winners
     * round's slots are the entry fold (SlotSource::Draw). A slot of a
     * later winners round is filled from the node in the same place of the
     * winners round before (AdvancerOf). Losers round 1 is filled by the
     * losers of winners round 1, in order (LoserOf). A later losers round
     * that takes droppers holds, in each node, the loser of a node of the
     * winners round (in reverse order after an even winners round, as the
     * engine pairs it) and then the advancer of the node in the same place
     * of the losers round before; one that takes none pairs the advancers
     * of the losers round before. The grand final holds the advancers of
     * the winners final and of the losers final; the reset, the loser of
     * the grand final and then its advancer, which is the order the engine
     * pairs it in when the losers champion won. A reset that is not due is
     * shown with the status NotPlayed.
     *
     * The rounds the engine has read are as it read them, the round it
     * pairs next is isNext(), and the rounds after it are the shape of the
     * bracket from there, as for single elimination (see
     * SingleEliminationEngine::getBracket()). A dropper from a bye is no
     * entrant: the losers slot it would fill holds the placeholder Bye.
     *
     * @throws InvalidConfigurationException When the recorded state is malformed (see isComplete())
     */
    #[Override]
    public function getBracket(StageState $state): Bracket
    {
        $reading = $this->resolveState($state, true);

        return BracketViewBuilder::doubleElimination(
            $reading,
            $this->bracketSize(count($state->getEntrants())),
            $this->options->grandFinalReset
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
    private function resolveState(StageState $state, bool $capture = false): BracketReading
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        $entrants = $state->getEntrants();
        $this->validateParticipants($entrants);

        // The bracket is read as it stood when it was over: a withdrawal
        // after its last tie was decided takes nothing back (see
        // readUntilOver())
        $deprecationRaised = false;

        return $this->readUntilOver(
            $state,
            function (?int $counted, array $heldFrom) use ($state, $entrants, &$deprecationRaised, $capture): BracketReading {
                $reading = $this->walk($state, $entrants, $counted, $heldFrom, $deprecationRaised, $capture);
                $deprecationRaised = $reading->tieWinnerDeprecationRaised;

                return $reading;
            }
        );
    }

    /**
     * One walk through the fixed stage sequence (see resolveState()).
     *
     * A winners tie both of whose entrants have left drops nobody to the
     * losers bracket, also where one of the two went on from it after all
     * (it was still in the stage when its next winners tie was paired):
     * the other had withdrawn by then, and an entrant who stands alone in
     * a losers round after withdrawing goes out there as the two of a void
     * tie do, so the losers slot is read as empty either way, and the
     * dropper goes out with the next losers round's exits.
     *
     * @param list<Participant> $entrants
     * @param int|null $counted How many of the withdrawals of the state count (see beginReading())
     * @param array<int|string, int> $heldFrom Whose withdrawals from a given number on do not count
     * @param bool $deprecationRaised Whether an earlier walk of the call raised the deprecation of
     *                                the 'tie_winner' key
     *
     * @throws InvalidConfigurationException When a tie is broken, or a recorded stage does not fit
     *                                       the stage it is read as
     */
    private function walk(
        StageState $state,
        array $entrants,
        ?int $counted = null,
        array $heldFrom = [],
        bool $deprecationRaised = false,
        bool $capture = false
    ): BracketReading {
        $reading = $this->beginReading($state, $counted, $heldFrom, $deprecationRaised);
        $reading->capture = $capture;
        $slots = $this->buildInitialSlots($entrants);
        $winnersRounds = (int) log(count($slots), 2);

        // Who went out, and after how many of the stages that put an
        // entrant out: a loss in the winners bracket only drops an entrant,
        // so those stages are not counted. The two entrants of a void
        // winners tie drop nobody to the losers bracket: they go out
        // together with those the next stage that puts an entrant out puts
        // out.
        $wentOutAt = [];
        $exits = 0;
        /** @var list<Participant> $voided */
        $voided = [];
        $noteExits = static function (array $out) use (&$wentOutAt, &$exits, &$voided): void {
            ++$exits;
            foreach ([...$voided, ...$out] as $entrant) {
                if ($entrant instanceof Participant) {
                    $wentOutAt[$entrant->getId()] = $exits;
                }
            }
            $voided = [];
        };
        // An entrant read as going out of a tie both entrants had left, who
        // was still in the stage when its next tie was paired, went on
        $revive = static function (array $revived) use (&$wentOutAt, &$voided): void {
            foreach ($revived as $entrant) {
                unset($wentOutAt[$entrant->getId()]);
                $voided = array_values(array_filter(
                    $voided,
                    static fn(Participant $other): bool => $other->getId() !== $entrant->getId()
                ));
            }
        };
        // Of a bracket that is not finished, the entrants who are not out
        // are level in the first tier, ahead of those who are.
        $unfinished = static function () use ($reading, $entrants, &$wentOutAt, &$exits, &$voided, $noteExits): BracketReading {
            if ($voided !== []) {
                $noteExits([]);
            }
            foreach ($entrants as $entrant) {
                $reading->placement[$entrant->getId()] = 0;
            }
            foreach ($wentOutAt as $id => $exit) {
                $reading->placement[$id] = $exits - $exit + 1;
            }

            return $reading;
        };

        // Winners round 1
        $losersRounds = 2 * ($winnersRounds - 1);
        $stage = $this->resolveStage($slots, BracketSide::Winners, 1, $winnersRounds, $reading);
        if ($stage['stopped']) {
            return $unfinished();
        }
        $winnersSlots = $stage['winners'];
        $winnersContended = $stage['contended'];
        $voided = $stage['void'];
        $losersChampionContended = [];

        if ($winnersRounds === 1) {
            $losersChampion = $stage['losers'][0] ?? null;
        } else {
            // Losers round 1: winners-round-1 losers pair up
            $losersStructuralRound = 1;
            $stage = $this->resolveStage(
                $stage['losers'],
                BracketSide::Losers,
                $losersStructuralRound,
                $losersRounds,
                $reading
            );
            if ($stage['stopped']) {
                return $unfinished();
            }
            $noteExits($stage['out']);
            $losersSurvivors = $stage['winners'];
            $losersContended = $stage['contended'];
            $losersChampion = null;

            for ($winnersRound = 2; $winnersRound <= $winnersRounds; ++$winnersRound) {
                $stage = $this->resolveStage($winnersSlots, BracketSide::Winners, $winnersRound, $winnersRounds, $reading, $winnersContended);
                $revive($stage['revived']);
                if ($stage['stopped']) {
                    return $unfinished();
                }
                $winnersSlots = $stage['winners'];
                $winnersContended = $stage['contended'];
                $droppers = $stage['losers'];
                $voided = [...$voided, ...$stage['void']];

                // Major round: survivors meet the latest droppers. Reverse the
                // dropper order on even winners rounds to defer rematches.
                if ($winnersRound % 2 === 0) {
                    $droppers = array_reverse($droppers);
                }
                $majorSlots = [];
                $majorContended = [];
                foreach (array_values($losersSurvivors) as $index => $survivor) {
                    $majorSlots[] = $droppers[$index] ?? null;
                    $majorSlots[] = $survivor;
                    if (isset($losersContended[$index])) {
                        $majorContended[2 * $index + 1] = $losersContended[$index];
                    }
                }

                ++$losersStructuralRound;
                $stage = $this->resolveStage(
                    $majorSlots,
                    BracketSide::Losers,
                    $losersStructuralRound,
                    $losersRounds,
                    $reading,
                    $majorContended
                );
                $revive($stage['revived']);
                if ($stage['stopped']) {
                    return $unfinished();
                }
                $noteExits($stage['out']);

                if ($winnersRound < $winnersRounds) {
                    // Minor round: major winners pair up
                    ++$losersStructuralRound;
                    $stage = $this->resolveStage(
                        $stage['winners'],
                        BracketSide::Losers,
                        $losersStructuralRound,
                        $losersRounds,
                        $reading,
                        $stage['contended']
                    );
                    $revive($stage['revived']);
                    if ($stage['stopped']) {
                        return $unfinished();
                    }
                    $noteExits($stage['out']);
                    $losersSurvivors = $stage['winners'];
                    $losersContended = $stage['contended'];
                } else {
                    $losersChampion = $stage['winners'][0] ?? null;
                    $losersChampionContended = $stage['contended'];
                }
            }
        }

        // Each bracket gives a champion unless every entrant left in it has
        // withdrawn; the grand final is then a stage with an empty slot,
        // and the other champion advances from it without playing.
        $grandFinalContended = [];
        if (isset($winnersContended[0])) {
            $grandFinalContended[0] = $winnersContended[0];
        }
        if (isset($losersChampionContended[0])) {
            $grandFinalContended[1] = $losersChampionContended[0];
        }

        // Grand final
        $reading->lastRoundReached = true;
        $stage = $this->resolveStage([$winnersSlots[0] ?? null, $losersChampion], BracketSide::GrandFinal, 1, 2, $reading, $grandFinalContended);
        $revive($stage['revived']);
        if ($stage['stopped']) {
            return $unfinished();
        }
        [$winnersChampion, $losersChampion] = $stage['entered'];
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
            $stage = $this->resolveStage([$winnersChampion, $losersChampion], BracketSide::GrandFinal, 2, 2, $reading);
            if ($stage['stopped']) {
                return $unfinished();
            }
        }

        // The last tie played (the grand final, or its reset) puts its
        // loser out and leaves the entrant who advanced from it first.
        // Everyone else is placed by the stage that put them out, the
        // latest first. A last tie both finalists left at times the state
        // does not know (0.2.x) is void, and puts both out.
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
     * when the entrants withdrew after it was paired (see claimOf()).
     *
     * A tie both of whose entrants have withdrawn (see settleTie()) sends
     * nobody on from here and has no loser to drop: its place holds nobody
     * in `winners` and in `losers`, its two entrants are in `out` and in
     * `void`, and they are in `contended` by its place. Whether one of them
     * went on after all is read where its next tie is: the stage that
     * place leads to is given them by the place they would fill
     * (`$contended`), and puts the one still in the stage when it was
     * paired there (wentOnFrom()). That one is in `revived`, by its slot
     * here. The slots the stage was entered with, after that, are
     * `entered`.
     *
     * @param array<Participant|null> $slots
     * @param array<int, list<Participant>> $contended The two entrants of a tie of an earlier stage
     *                                                 both of whom have withdrawn, by the slot the
     *                                                 one who went on from it would fill here
     * @return array{stopped: bool, winners: list<Participant|null>, losers: list<Participant|null>, out: list<Participant>, void: list<Participant>, contended: array<int, list<Participant>>, revived: array<int, Participant>, entered: list<Participant|null>}
     *                                                                                                                                                                                                                       The winners and the losers are by place,
     *                                                                                                                                                                                                                       one for each pair of slots; `out` lists
     *                                                                                                                                                                                                                       everyone who did not advance
     *
     * @throws InvalidConfigurationException When a tie is broken, or the recorded stage leaves
     *                                       another number of entrants than the stage has places
     */
    private function resolveStage(
        array $slots,
        BracketSide $side,
        int $structuralRound,
        int $roundsOnSide,
        BracketReading $reading,
        array $contended = []
    ): array {
        $slots = array_values($slots);
        $stageName = BracketRoundLabel::of($side, $structuralRound, $roundsOnSide, count($slots));

        // The stage is read from who was in the bracket when it was paired:
        // for the recorded round with the next number where there is one,
        // and from the state as it stands otherwise.
        $recorded = $reading->recorded[$reading->number + 1] ?? null;
        $pairedAt = $recorded instanceof RoundPairing ? $reading->number + 1 : null;
        $out = $reading->withdrawnWhenPaired($pairedAt);

        $revived = [];
        foreach ($contended as $index => $contenders) {
            $wentOn = $this->wentOnFrom($reading, $contenders, $out, $pairedAt);
            if ($wentOn instanceof Participant) {
                $slots[$index] = $wentOn;
                $revived[$index] = $wentOn;
            }
        }
        $slots = array_values($slots);

        $pairs = array_chunk($slots, 2);

        $ties = [];
        foreach ($pairs as $pair) {
            $first = $pair[0] ?? null;
            $second = $pair[1] ?? null;
            if ($first !== null && $second !== null) {
                $ties[] = [$first, $second];
            }
        }

        [$toPlay, $claimed] = $this->claimOf($recorded, $ties, $out);
        $derivation = $this->buildStagePairing($reading->number + 1, $stageName, $pairs, $out);
        $stopped = ['stopped' => true, 'winners' => [], 'losers' => [], 'out' => [], 'void' => [], 'contended' => [], 'revived' => $revived, 'entered' => $slots];

        if (!$claimed && $toPlay) {
            $reading->pending = $derivation;
            if ($reading->capture) {
                $reading->walked[] = [
                    'side' => $side,
                    'round' => $structuralRound,
                    'slots' => $slots,
                    'number' => null,
                    'manual' => false,
                    'next' => true,
                    'nodes' => $this->pendingNodes($pairs, $out),
                    'extra' => null,
                ];
            }

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
            $this->options->legsPerTie,
            null,
            $pairedAt
        );

        if ($reading->capture) {
            $reading->walked[] = [
                'side' => $side,
                'round' => $structuralRound,
                'slots' => $slots,
                'number' => $claimed ? $reading->number : null,
                'manual' => $claimed && $read['manual'],
                'next' => false,
                'nodes' => $read['nodes'],
                'extra' => null,
            ];
        }

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

            $reading->rounds[] = new RecordedRound(
                $recorded,
                $derivation,
                $read['manual'],
                $read['unresolved'],
                $read['uncontested'],
                $read['undecided']
            );

            if ($read['unresolved'] !== [] || $read['undecided'] !== []) {
                $reading->awaitingRound = $reading->number;
                $reading->unresolved = $read['unresolved'];
                $reading->undecided = $read['undecided'];

                return $stopped;
            }
        }

        $winners = [];
        $losers = [];
        $out = [];
        $void = [];
        $nextContended = [];
        foreach ($read['units'] as $place => $unit) {
            $isVoid = !$unit['winner'] instanceof Participant && count($unit['losers']) === 2;
            $winners[] = $unit['winner'];
            $losers[] = $isVoid ? null : ($unit['losers'][0] ?? null);
            foreach ($unit['losers'] as $loser) {
                $out[] = $loser;
                if ($isVoid) {
                    $void[] = $loser;
                }
            }
            if ($isVoid) {
                $nextContended[$place] = $unit['losers'];
            }
        }

        // The stage read last is the one that decided a finished bracket:
        // the grand final, or its reset
        $reading->decidingTies = $read['units'];

        return [
            'stopped' => false,
            'winners' => $winners,
            'losers' => $losers,
            'out' => $out,
            'void' => $void,
            'contended' => $nextContended,
            'revived' => $revived,
            'entered' => $slots,
        ];
    }

    /**
     * The pairing of a stage: an event for each tie to play, in bracket
     * order, and among the byes everyone who advances without playing,
     * the other entrant of a tie an entrant has withdrawn from included.
     *
     * @param array<array<Participant|null>> $pairs
     * @param array<int|string, true> $withdrawn The IDs of the entrants who had withdrawn when
     *                                           the stage was paired
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
}
