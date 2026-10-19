<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\UnresolvedRoundException;
use MissionGaming\Tactician\Stage\Bracket;
use MissionGaming\Tactician\Stage\BracketSide;
use MissionGaming\Tactician\Stage\BracketTie;
use MissionGaming\Tactician\Stage\BracketViewEngine;
use MissionGaming\Tactician\Stage\EliminationPlan;
use MissionGaming\Tactician\Stage\EngineFingerprint;
use MissionGaming\Tactician\Stage\FingerprintedEngine;
use MissionGaming\Tactician\Stage\FormatMetadataKey;
use MissionGaming\Tactician\Stage\HistoryReadingEngine;
use MissionGaming\Tactician\Stage\PairingReadiness;
use MissionGaming\Tactician\Stage\RecordedRound;
use MissionGaming\Tactician\Stage\RoundLabel;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\RankingStrategyWithByeValue;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use Override;

/**
 * Single elimination preset: a canned composition of single-round
 * knockout stages behind the standard stage driver loop.
 *
 * Entry pairing folds by list position (in an eight-slot bracket:
 * 1 vs 8, 4 vs 5, 2 vs 7, 3 vs 6), placing positions 1 and 2 in opposite
 * halves so the top entrants can only meet in the latest possible round;
 * fields that are not a power of two give byes to the top positions.
 * Position is authoritative - feed the entrant list in seeding order
 * (progression selectors already do).
 *
 * Two path behaviours (EliminationOptions):
 * - Fixed bracket path (default): survivors keep their bracket slots, so
 *   the draw made at entry decides every route to the final.
 * - Re-seeded (reseedEachRound): survivors are re-ranked by standings
 *   after every round and re-folded, so the strongest remaining records
 *   meet as late as possible. A bye counts in that ranking as the tie it
 *   stands for, won in every leg, and survivors who are level are in the
 *   order of the participant list: in a field of five, positions 1 and 2
 *   are in opposite halves of the semifinals.
 *
 * Ties are played over one event or two mirrored legs (legsPerTie). A tie
 * that finishes level - a drawn single event, or two legs that do not
 * decide - is the application's to decide and is recorded as the decider
 * of a result of the tie (DTO\Decider; see TieDecision for the reading). A
 * level tie without one awaits that decision as another tie awaits a
 * result: the bracket is not complete, reading it throws nothing,
 * getReadiness() lists the events of the tie
 * (PairingReadiness::getUndecidedEvents()), and pairNextRound() refuses
 * with the reason `UndecidedTie` and, in the context of the exception, the
 * round of the tie and its two participants.
 *
 * A third-place tie (thirdPlace, off by default) makes the bracket decide
 * places 3 and 4: the last round then holds two ties, the final and a tie
 * between the two entrants who went out in the round before it. It is a
 * tie like every other: the same number of legs, the same reading of a
 * level result, and the round is complete when both ties are decided.
 * Both are in the round labelled 'final', the final's events first, and
 * each event of that round says which tie it belongs to (see BracketTie).
 * On a fixed path the entrant who went out of the tie that fed the final's
 * first-named side is first-named; a re-seeded bracket puts the two in the
 * order of the table it folds the final from. A field of 3 has one entrant
 * who goes out before the final, so no third-place tie is played and that
 * entrant is third; a field of 2 is refused with the option, because it
 * has no third place.
 *
 * An awarded result (DTO\Result::isAwarded()) is the result of its event:
 * the winner it names advances from a single-leg tie, and in a two-legged
 * tie it is a leg won, so a tie that is not played at all is settled by
 * an awarded result for each leg. One that names no winner leaves the tie
 * level, like a draw, and the decider is then needed, which the awarded
 * result may carry. Adjustments
 * recorded on the state (StageState::withAdjustment()) are in the ranking
 * values of every table the engine builds: they order the entrants a round
 * put out together and the survivors of a re-seeded round, and never
 * decide who advances.
 *
 * There is deliberately no champion accessor. The participant who
 * advances from the final is rank 1 of the outcome's standings, which are
 * in bracket placement order (see getOutcome()), and is what
 * MatchOutcomeSelector::winners() returns for the outcome: the outcome
 * carries the final as this engine settled it
 * (StageOutcome::getSettledTies()), also where a finalist withdrew and
 * the final has no result or was never paired. The final is decided the
 * moment its result or the withdrawal of a finalist decides it, and the
 * bracket is over once the final and the third-place tie are: a later
 * withdrawal takes nothing back, the winner's included (see Withdrawal and
 * the usage guide, "Withdrawals from a Bracket"). Only a state stored by
 * 0.2.x, whose withdrawals carry no time, can hold a final both finalists
 * left at times nobody knows: it is void, nobody advances from it, the
 * table has nobody in its first tier, and the selector names nobody.
 *
 * @experimental
 */
final readonly class SingleEliminationEngine implements StageEngineInterface, FingerprintedEngine, HistoryReadingEngine, BracketViewEngine
{
    use EliminationBracketSupport;

    /**
     * Every option is accepted; grandFinalReset is not read.
     *
     * @param StandingsCalculator $standingsCalculator Computes the records of the outcome's standings
     *                                                 and orders the entrants who went out in the
     *                                                 same round; in a re-seeded bracket it also
     *                                                 orders the survivors before every round after
     *                                                 the first. On a fixed path it pairs nothing
     */
    public function __construct(
        private EliminationOptions $options = new EliminationOptions(),
        private StandingsCalculator $standingsCalculator = new StandingsCalculator()
    ) {}

    /**
     * What this engine stamps a stage state with, and requires of a state
     * that carries a stamp (see FingerprintedEngine for the contract).
     *
     * With default options it names the format and nothing else. Part of
     * it when not at the default: legsPerTie (default 1), reseedEachRound
     * (default false) and thirdPlace (default false), so a state stamped
     * by an engine without a third-place tie is refused by one with it,
     * and the other way round. A state that carries no stamp and has its
     * last round recorded is told apart by the round: an engine without
     * the option refuses it, with the reason `RecordedRoundMismatch`,
     * when the round holds an event marked as the tie, and an engine with
     * the option reads a last round that lacks the tie as a round paired
     * by hand, in which places 3 and 4 are not decided. A re-seeded bracket is paired from
     * the table after every round, so there the rules of the standings
     * calculator are part of it as well, when they are not the default:
     * the ranking scale (the three values of a WinDrawLossRanking, default
     * 3/1/0; any other RankingStrategy is stated as custom and not told
     * apart from another of your own), the tiebreakers by name and in
     * order, and each choice of the awarded result policy that is off, or,
     * in their place, that the calculator is a subclass of
     * StandingsCalculator (stated as custom; nothing is read from it, and
     * two subclasses are not told apart).
     *
     * Not part of it: grandFinalReset, which this engine does not read,
     * and the standings calculator of a bracket on a fixed path, which
     * orders the outcome and pairs nothing.
     */
    #[Override]
    public function getFingerprint(): string
    {
        $fingerprint = EngineFingerprint::of('single-elimination')
            ->with('legs-per-tie', $this->options->legsPerTie, 1)
            ->with('reseed-each-round', $this->options->reseedEachRound, false)
            ->with('third-place', $this->options->thirdPlace, false);

        if ($this->options->reseedEachRound) {
            $fingerprint = $fingerprint->withStandingsRules($this->standingsCalculator);
        }

        return $fingerprint->toString();
    }

    /**
     * The shape of the bracket for every participant the state has seen:
     * the bracket size, the number of rounds and the events to expect, the
     * events of a third-place tie included where the bracket has one.
     *
     * @throws InvalidConfigurationException When fewer than 2 participants have been seen, or
     *                                       fewer than 3 with the third-place option, or the
     *                                       state is stamped with another engine's fingerprint
     */
    #[Override]
    public function getPlan(StageState $state): EliminationPlan
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        return new EliminationPlan(
            $state->getAllSeenParticipants(),
            'single-elimination',
            $this->options->legsPerTie,
            $this->options->thirdPlace
        );
    }

    /**
     * Pair the next unresolved round of the bracket.
     *
     * The bracket is read from the state on every call, so the same state
     * gives the same pairing: the rounds that are recorded are read as
     * recorded, and the next round is derived from them. Before the first
     * round that is the fold of the entrant list; after it, the entrants
     * who advanced from the recorded ties, in the order of the slots they
     * stood in. A recorded round that pairs the entrants otherwise than
     * this engine would have is not refused: the next round follows from
     * what was played (see getRecordedRounds()). The call does not record
     * anything: pass the pairing and its results to
     * StageState::withRoundPlayed().
     *
     * A round that is recorded and not finished is not paired again: the
     * call is refused with the events that await a result, whether the
     * round has some of its results or none. Read the round from the
     * state (StageState::getLastRound()), and ask getReadiness() to learn
     * without catching anything whether the next one can be paired.
     *
     * An entrant who has withdrawn from the live bracket
     * (StageState::withoutParticipant()) keeps its slot. A tie it is in
     * and has no complete result for is not played: the other entrant of
     * the tie advances and is listed among the byes of the round, and no
     * event is paired for it. Nothing is drawn again. A tie both of whose
     * entrants have withdrawn before either went through it is void: it
     * sends nobody on, and the entrant who would have met its winner
     * stands alone and is listed among the byes (see Withdrawal). An
     * entrant who stands alone and had withdrawn when the round was paired
     * goes nowhere: it goes out in that round, and a final it stands alone
     * in has no winner.
     *
     * Round numbers are 1-based, and the round carries its label ('round
     * of 16', 'quarterfinal', 'semifinal', 'final'). The events are in
     * bracket order, top of the bracket first; a two-legged tie is two
     * consecutive events with the roles reversed, marked 'tie_leg' 1 and 2
     * in their metadata. The byes are the participants who advance without
     * playing: in the first round those whom the fold gives no opponent,
     * and in any round those whose opponent has withdrawn or whose
     * opponent's slot a void tie left empty. The entrant who takes the
     * third place from a third-place tie the other has withdrawn from is
     * listed after them.
     *
     * With the third-place option the last round, labelled 'final', lists
     * the events of the final and then those of the third-place tie, and
     * each of them carries the tie it belongs to under
     * BracketTie::METADATA_KEY in its metadata ('final' or 'third-place');
     * read it with BracketTie::of().
     *
     * @throws UnresolvedRoundException When the last recorded round has a tie without a complete
     *                                  result (reason `RoundPartiallyResolved`; record the
     *                                  missing results via StageState::withAdditionalResults())
     * @throws InvalidConfigurationException When a tie of the last recorded round has every result,
     *                                       is level and carries no decider (`UndecidedTie`, with
     *                                       the round and the two participants in the context;
     *                                       it is thrown before the exception above where the
     *                                       round has both), the state has fewer than 2 entrants, or
     *                                       fewer than 3 with the third-place option
     *                                       (`TooFewParticipants`), or is
     *                                       stamped with another engine's fingerprint, a result
     *                                       has no round number, is not of a two-participant
     *                                       event or is recorded twice, a tie carries a decider
     *                                       TieDecision refuses (`InvalidResult`), a recorded
     *                                       round holds a third-place tie where this engine
     *                                       plays none (reason `RecordedRoundMismatch`), a re-seeded
     *                                       bracket has a bye and a ranking strategy that does
     *                                       not say what a bye is worth (`IncompatibleOptions`), or the
     *                                       bracket is complete
     */
    #[Override]
    public function pairNextRound(StageState $state): RoundPairing
    {
        $reading = $this->resolveBracket($state);

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
     * Whether the final has been decided: every round of the bracket has a
     * result for each of its ties, or needs none because an entrant of the
     * tie has withdrawn. A bracket with a third-place tie is complete when
     * that tie is decided as well. A round that has some of its results,
     * or none, or a tie that is level and awaits its decider, is a bracket
     * that is not complete, and nothing is thrown for it.
     *
     * @throws InvalidConfigurationException When the recorded state is malformed: every case
     *                                       pairNextRound() names but the unfinished round and
     *                                       the complete bracket
     */
    #[Override]
    public function isComplete(StageState $state): bool
    {
        return $this->resolveBracket($state)->isComplete();
    }

    /**
     * Whether the next round can be paired now and, when it cannot, the
     * events that await a result (see HistoryReadingEngine).
     *
     * @throws InvalidConfigurationException When the recorded state is malformed (see isComplete())
     */
    #[Override]
    public function getReadiness(StageState $state): PairingReadiness
    {
        $reading = $this->resolveBracket($state);

        return match (true) {
            $reading->pending instanceof RoundPairing => PairingReadiness::ready($reading->pending->getRoundNumber()),
            $reading->awaitingRound !== null => PairingReadiness::awaitingResults($reading->awaitingRound, $reading->unresolved, $reading->undecided),
            default => PairingReadiness::complete(),
        };
    }

    /**
     * Every recorded round as this engine reads it (see RecordedRound), up
     * to and including the round the bracket is waiting for.
     *
     * The derivation of a round is the round this engine pairs from the
     * rounds recorded before it: the fold of the entrant list for the
     * first, and for a later one the entrants who advanced, in the order
     * of their slots or, in a re-seeded bracket, of the table of the
     * earlier rounds. A recorded round that pairs other entrants is
     * manual, and the rounds after it are derived from it as recorded. A
     * round recorded after the bracket's last is read by nothing and is
     * not listed.
     *
     * In a re-seeded bracket the derivation of a round is ranked from the
     * adjustments made before the round was paired
     * (Adjustment::getAfterRound()), so a round this engine paired is not
     * reported as manual for an adjustment recorded since.
     *
     * @return list<RecordedRound>
     *
     * @throws InvalidConfigurationException When the recorded state is malformed (see isComplete())
     */
    #[Override]
    public function getRecordedRounds(StageState $state): array
    {
        return $this->resolveBracket($state)->rounds;
    }

    /**
     * The table of the bracket as recorded so far, in placement order.
     *
     * For a finished bracket it is the table of the outcome (see
     * getOutcome()). Before that, the entrants still in the bracket share
     * the first tier, and those who have gone out follow by the round
     * they went out in, later first; inside a tier the records order
     * them, and the entrant list where those are level.
     *
     * @throws InvalidConfigurationException When the recorded state is malformed (see isComplete())
     */
    #[Override]
    public function getStandings(StageState $state): Standings
    {
        $reading = $this->resolveBracket($state);

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
     * The standings are in bracket placement order, whatever the field
     * size, the byes and the legs per tie: the entrant who advanced from
     * the final is rank 1, the other finalist rank 2, then the two who
     * went out in the semifinals, then the four of the quarter-finals, and
     * so on back to the first round. The bracket decides those tiers
     * (Standings::getTiers()), and no record moves an entrant from one to
     * another: a finalist who had a bye, or who advanced from a two-legged
     * final on a tie decision, is still above the entrant it beat.
     *
     * A third-place tie decides two places more: the entrant who advanced
     * from it is rank 3 and the other rank 4, each alone in its tier, and
     * the tiers of the earlier rounds follow. A last tie is decided the
     * moment its results or a withdrawal decide it, and a withdrawal after
     * that moves nobody (see Withdrawal). A last tie that is void (both of
     * its entrants withdrew at times nobody knows, in a state stored by
     * 0.2.x, or had both left by the time the tie had them both, as a
     * third-place tie can) leaves its upper tier empty: the two finalists
     * of a void final share the second tier, and the two of a void
     * third-place tie the fourth. Its results count in the
     * records as those of every tie do. The outcome's final round then
     * holds both ties. MatchOutcomeSelector reads the final and leaves the
     * third-place tie out, which it knows by its mark: winners() gives
     * rank 1 and losers() rank 2, as after a bracket without the tie, and
     * ranks 3 and 4 are a rank selection. A field of 3 with the option has
     * the table it has without it.
     *
     * Entrants who went out in the same round are ordered by the standings
     * calculator from their records (ranking value, tiebreakers, score
     * difference, scores-for), and where that leaves them level, by their
     * position in the participant list. Standings::getTiedSets() reports
     * those. The records are win/draw/loss records over the bracket's
     * results: a bye is no win in them, the legs of a two-legged tie count
     * as recorded, and a single-leg event that finished level and was
     * decided by its tie decision counts as a win for the participant who
     * advanced. The outcome's results are the results as recorded, the
     * draw included.
     *
     * @throws InvalidConfigurationException When the recorded state is malformed (see isComplete())
     */
    #[Override]
    public function getOutcome(StageState $state): ?StageOutcome
    {
        $reading = $this->resolveBracket($state);
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
     * The bracket as this engine reads the state (see Bracket): one side,
     * BracketSide::Main, with a round for every round of the bracket from
     * the first to the final, recorded or not.
     *
     * The first round's slots are the entry fold (SlotSource::Draw, with
     * the position in the entrant list; a position beyond the field is a
     * bye). On a fixed path each slot of a later round is filled from one
     * node of the round before, the node in the same place: slots 1 and 2
     * of round 2 from nodes 1 and 2 of round 1. In a re-seeded bracket
     * every slot of a later round is filled from all the nodes of the round
     * before (BracketSlot::isReseeded(), placeholder Reseeded until the
     * round is reached). A third-place tie is the last node of the last
     * round (kind BracketTie::ThirdPlace), its slots filled from the losers
     * of the two nodes before the final; a field of 3 has none, as the
     * engine plays none.
     *
     * The rounds the engine has read are as it read them: the recorded
     * rounds with their events, and a round nobody played because of
     * withdrawals, with no round number. The round it pairs next is
     * isNext(), its nodes to be paired, settled where an entrant has
     * withdrawn. The rounds after it are the shape of the bracket from
     * there, each slot holding the entrant already known to fill it (the
     * advancer of a node settled early) or its placeholder, and a node
     * an entrant has withdrawn from settled as the pairing of its round
     * will settle it. A round drawn
     * by hand keeps its ties as recorded: a node for each tie, at the slot
     * of the earlier of its two entrants, and for each entrant left alone;
     * the rounds after it are shaped from the nodes it has.
     *
     * @throws InvalidConfigurationException When the recorded state is malformed (see isComplete())
     */
    #[Override]
    public function getBracket(StageState $state): Bracket
    {
        $reading = $this->resolveBracket($state, true);

        return BracketViewBuilder::singleElimination(
            $reading,
            $this->options->reseedEachRound,
            $this->options->thirdPlace,
            $this->bracketSize(count($state->getEntrants()))
        );
    }

    /**
     * Read the bracket from the entry fold through the recorded rounds.
     *
     * The slots of the first round are the fold of the entrant list. Each
     * round is then read as recorded (see readRound()), and the entrants
     * who advance from it are the slots of the next. The walk stops at the
     * first round that is not recorded and has a tie to play, which is the
     * round to pair, or at a recorded round that awaits results.
     *
     * A round in which no tie is to be played, because an entrant of each
     * has withdrawn, takes no round number unless it is recorded: it was
     * recorded when the entrants withdrew after it was paired. Whether a
     * recorded round is that round is told by who was in the bracket when
     * it was paired, which the rounds that name an entrant say before the
     * round of its withdrawal does: it is that round when one of the ties
     * the bracket has there could be played then (see claimOf()).
     *
     * The bracket is read as it stood when it was over, and the final and
     * the third-place tie each as it stood when it was decided: a
     * withdrawal after that takes nothing back (see readUntilOver()).
     *
     * @throws InvalidConfigurationException When a tie is broken, a recorded round holds a
     *                                       third-place tie this engine does not play, a bye
     *                                       cannot be credited, or the state is stamped with
     *                                       another engine's fingerprint
     */
    private function resolveBracket(StageState $state, bool $capture = false): BracketReading
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        $entrants = $state->getEntrants();
        $this->validateParticipants($entrants);
        $this->requireByesToBeCreditable(count($entrants));
        $this->requireAThirdPlaceToDecide(count($entrants));
        $this->requireNoThirdPlaceTieRecorded($state);

        $deprecationRaised = false;

        return $this->readUntilOver(
            $state,
            function (?int $counted, array $heldFrom) use ($state, $entrants, &$deprecationRaised, $capture): BracketReading {
                $reading = $this->walkBracket($state, $entrants, $counted, $heldFrom, $deprecationRaised, $capture);
                $deprecationRaised = $reading->tieWinnerDeprecationRaised;

                return $reading;
            }
        );
    }

    /**
     * One walk through the bracket (see resolveBracket()), counting the
     * withdrawals readUntilOver() says.
     *
     * @param list<Participant> $entrants The entrant list of the state
     * @param array<int|string, int> $heldFrom
     *
     * @throws InvalidConfigurationException When a tie is broken, or a bye cannot be credited
     */
    private function walkBracket(StageState $state, array $entrants, ?int $counted, array $heldFrom, bool $deprecationRaised, bool $capture = false): BracketReading
    {
        $reading = $this->beginReading($state, $counted, $heldFrom, $deprecationRaised);
        $reading->capture = $capture;
        $legsPerTie = $this->options->legsPerTie;

        $slots = array_values($this->buildInitialSlots($entrants));
        $structural = 0;
        $byes = [];
        $wentOutIn = [];
        $previousUnits = [];
        $thirdPlaceSettled = false;
        $thirdPlaceAdvancer = null;

        while (count($slots) > 1) {
            ++$structural;

            // The round is read from what was known when it was paired: for
            // the recorded round with the next number where there is one
            // (this round, or the one this round was skipped before), and
            // from the state as it stands otherwise.
            $recorded = $reading->recorded[$reading->number + 1] ?? null;
            $pairedAt = $recorded instanceof RoundPairing ? $reading->number + 1 : null;
            $out = $reading->withdrawnWhenPaired($pairedAt);

            // A tie of the round before both of whose entrants have
            // withdrawn sent on the one still in the stage when this round
            // was paired, if either was; it is void otherwise, and its slot
            // here is empty
            foreach ($previousUnits as $index => $unit) {
                if ($unit['winner'] !== null || count($unit['losers']) !== 2) {
                    continue;
                }
                $wentOn = $this->wentOnFrom($reading, $unit['losers'], $out, $pairedAt);
                if ($wentOn instanceof Participant) {
                    $slots[$index] = $wentOn;
                    unset($wentOutIn[$wentOn->getId()]);
                    $byes[$wentOn->getId()] = ($byes[$wentOn->getId()] ?? 0) + 1;
                    $previousUnits[$index] = [
                        'winner' => $wentOn,
                        'losers' => array_values(array_filter(
                            $unit['losers'],
                            static fn(Participant $loser): bool => $loser->getId() !== $wentOn->getId()
                        )),
                    ];
                }
            }

            $slots = array_values($slots);

            // The third-place tie is between the losers of the two ties
            // before the final, one from each. A void tie sends the first of
            // its two entrants, who has withdrawn, so that the other tie's
            // loser is third without a tie being played.
            $wentOutInPreviousRound = [];
            foreach ($previousUnits as $unit) {
                if ($unit['losers'] !== []) {
                    $wentOutInPreviousRound[] = $unit['losers'][0];
                }
            }

            // Re-seeded path: after the entry round, survivors are
            // re-ranked by standings and re-folded each round. Only
            // results from earlier rounds, and adjustments made before the
            // round was paired, may inform the ranking - the round is
            // derived the same before and after its own results are
            // recorded, and whatever is recorded later.
            if ($this->options->reseedEachRound && $structural > 1) {
                $survivors = array_values(array_filter($slots, fn(?Participant $slot) => $slot !== null));
                $slots = array_values($this->buildInitialSlots(
                    $this->rankByStandings($survivors, $state, $reading->number + 1, $reading->levelAdvancers, $byes, $pairedAt)
                ));
            }

            $pairs = array_chunk($slots, 2);

            // The third-place tie is a tie of the last round like the
            // final: the round is paired with both, awaits results with
            // one of them decided, and is complete with both.
            $thirdPlaceTie = count($slots) === 2
                ? $this->thirdPlaceTie($wentOutInPreviousRound, $state, $reading->number + 1, $reading->levelAdvancers, $byes, $pairedAt)
                : null;

            $ties = $thirdPlaceTie === null ? [] : [$thirdPlaceTie];
            foreach ($pairs as $pair) {
                $first = $pair[0] ?? null;
                $second = $pair[1] ?? null;
                if ($first !== null && $second !== null) {
                    $ties[] = [$first, $second];
                }
            }

            [$toPlay, $claimed] = $this->claimOf($recorded, $ties, $out);
            $derivation = $this->buildRoundPairing($reading->number + 1, count($slots), $pairs, $thirdPlaceTie, $out);

            $isLastRound = count($slots) === 2;
            $reading->lastRoundReached = $reading->lastRoundReached || $isLastRound;

            if (!$claimed && $toPlay) {
                $reading->pending = $derivation;
                if ($isLastRound) {
                    $reading->lastTies = $this->lastTiesToPair($slots, $thirdPlaceTie, $out);
                }
                if ($reading->capture) {
                    $reading->walked[] = [
                        'side' => BracketSide::Main,
                        'round' => $structural,
                        'slots' => $slots,
                        'number' => null,
                        'manual' => false,
                        'next' => true,
                        'nodes' => $this->pendingNodes($pairs, $out),
                        'extra' => $thirdPlaceTie === null ? null : $this->pendingNode([$thirdPlaceTie[0], $thirdPlaceTie[1]], $out),
                    ];
                }
                break;
            }

            if ($claimed) {
                ++$reading->number;
            }
            $read = $this->readRound(
                $reading,
                $slots,
                $claimed ? $recorded : null,
                $claimed ? $reading->number : $reading->number + 1,
                $legsPerTie,
                $thirdPlaceTie,
                $pairedAt
            );

            if ($isLastRound) {
                $reading->lastTies = $this->lastTiesRead($slots, $thirdPlaceTie, $read);
            }
            if ($reading->capture) {
                $reading->walked[] = [
                    'side' => BracketSide::Main,
                    'round' => $structural,
                    'slots' => $slots,
                    'number' => $claimed ? $reading->number : null,
                    'manual' => $claimed && $read['manual'],
                    'next' => false,
                    'nodes' => $read['nodes'],
                    'extra' => $read['extraNode'],
                ];
            }

            if ($claimed && $recorded instanceof RoundPairing) {
                $reading->rounds[] = new RecordedRound(
                    $recorded,
                    $derivation,
                    $read['manual'],
                    $read['unresolved'],
                    $read['uncontested'],
                    $read['undecided']
                );
            }

            foreach ($read['units'] as $unit) {
                foreach ($unit['losers'] as $wentOut) {
                    $wentOutIn[$wentOut->getId()] = $structural;
                }
            }
            foreach ($read['unplayed'] as $advanced) {
                $byes[$advanced->getId()] = ($byes[$advanced->getId()] ?? 0) + 1;
            }
            if ($read['extra'] !== null) {
                $thirdPlaceSettled = true;
                $thirdPlaceAdvancer = $read['extra']['winner'];
            }

            if ($read['unresolved'] !== [] || $read['undecided'] !== []) {
                $reading->awaitingRound = $reading->number;
                $reading->unresolved = $read['unresolved'];
                $reading->undecided = $read['undecided'];
                break;
            }

            $reading->decidingTies = $read['units'];
            $previousUnits = $read['units'];
            $slots = array_map(static fn(array $unit): ?Participant => $unit['winner'], $read['units']);
        }

        // The entrant who advanced from the final is in the first tier,
        // and everyone else in the tier of the round they went out in:
        // the final's is the second, the semifinals' the third. A
        // third-place tie splits the tier of the semifinals in two, its
        // winner above the other, and every earlier round is a tier lower.
        // A last tie nobody won (both of its entrants withdrew) leaves its
        // upper tier empty: the two of a void final share the second, and
        // those of a void third-place tie the one below the third. In a
        // bracket that is not finished, everyone still in it is level in
        // the first tier.
        $complete = $reading->isComplete();
        foreach ($slots as $slot) {
            if ($slot !== null) {
                $reading->placement[$slot->getId()] = 0;
            }
        }
        foreach ($wentOutIn as $id => $round) {
            if (!$complete && isset($reading->placement[$id]) && $round < $structural) {
                // Back in the bracket: a later recorded round names an
                // entrant who had gone out
                continue;
            }

            $tier = $structural - $round + 1;
            if ($complete && $thirdPlaceSettled && $round < $structural) {
                $tier += $round < $structural - 1 || (string) $id !== $thirdPlaceAdvancer?->getId() ? 1 : 0;
            }
            $reading->placement[$id] = $tier;
        }
        if ($complete) {
            foreach ($slots as $slot) {
                if ($slot !== null) {
                    $reading->placement[$slot->getId()] = 0;
                }
            }
        }

        return $reading;
    }

    /**
     * The last ties of a round that is still to be paired: the final, which
     * is decided where it has an entrant who has withdrawn or fewer than
     * two entrants, and the third-place tie, decided where an entrant of it
     * has withdrawn.
     *
     * @param list<Participant|null> $slots The two slots of the final
     * @param array{Participant, Participant}|null $thirdPlaceTie
     * @param array<int|string, true> $out Who had withdrawn when the round was paired
     *
     * @return list<array{entrants: list<Participant>, settled: bool}>
     */
    private function lastTiesToPair(array $slots, ?array $thirdPlaceTie, array $out): array
    {
        $finalists = array_values(array_filter($slots, static fn(?Participant $slot): bool => $slot instanceof Participant));
        $settled = count($finalists) < 2;
        foreach ($finalists as $finalist) {
            $settled = $settled || isset($out[$finalist->getId()]);
        }
        $ties = [['entrants' => $finalists, 'settled' => $settled]];

        if ($thirdPlaceTie !== null) {
            $ties[] = [
                'entrants' => [$thirdPlaceTie[0], $thirdPlaceTie[1]],
                'settled' => isset($out[$thirdPlaceTie[0]->getId()]) || isset($out[$thirdPlaceTie[1]->getId()]),
            ];
        }

        return $ties;
    }

    /**
     * The last ties of a round that has been read (see readRound()): each
     * is decided where it sends one entrant on, or nobody and somebody out.
     *
     * @param list<Participant|null> $slots The two slots of the final
     * @param array{Participant, Participant}|null $thirdPlaceTie
     * @param array{units: list<array{winner: Participant|null, losers: list<Participant>}>, extra: array{winner: Participant|null, losers: list<Participant>}|null} $read
     *
     * @return list<array{entrants: list<Participant>, settled: bool}>
     */
    private function lastTiesRead(array $slots, ?array $thirdPlaceTie, array $read): array
    {
        $finalists = array_values(array_filter($slots, static fn(?Participant $slot): bool => $slot instanceof Participant));
        $winners = 0;
        $open = false;
        foreach ($read['units'] as $unit) {
            $winners += $unit['winner'] instanceof Participant ? 1 : 0;
            $open = $open || (!$unit['winner'] instanceof Participant && $unit['losers'] === []);
        }
        $ties = [['entrants' => $finalists, 'settled' => count($finalists) < 2 || ($winners < 2 && !$open)]];

        if ($thirdPlaceTie !== null) {
            $extra = $read['extra'];
            $ties[] = [
                'entrants' => [$thirdPlaceTie[0], $thirdPlaceTie[1]],
                'settled' => $extra !== null && ($extra['winner'] instanceof Participant || $extra['losers'] !== []),
            ];
        }

        return $ties;
    }

    /**
     * The third-place tie of the last round, or null when the bracket has
     * none: the option is off, or fewer than two entrants went out in the
     * round before the last (a field of 3, where the top entrant has a bye
     * in that round).
     *
     * The first-named entrant is the one who went out of the tie that fed
     * the final's first-named side, so on a fixed path the tie mirrors the
     * final. A re-seeded bracket folds the final from the table of the
     * earlier rounds, and the two entrants of this tie are in the order of
     * the same table.
     *
     * @param list<Participant> $wentOutInPreviousRound In the order of that round's ties
     * @param array<string, Participant> $levelAdvancers
     * @param array<int|string, int> $byes Byes of the earlier rounds, by participant ID
     * @param int|null $pairedAt The number of the recorded round the tie was paired for, or null
     *                           when it is paired now
     * @return array{Participant, Participant}|null
     *
     * @throws InvalidConfigurationException When the bracket is re-seeded, one of the two had a
     *                                       bye and the ranking strategy does not say what a
     *                                       bye is worth
     */
    private function thirdPlaceTie(
        array $wentOutInPreviousRound,
        StageState $state,
        int $round,
        array $levelAdvancers,
        array $byes,
        ?int $pairedAt
    ): ?array {
        if (!$this->options->thirdPlace || count($wentOutInPreviousRound) !== 2) {
            return null;
        }

        $entrants = $this->options->reseedEachRound
            ? $this->rankByStandings($wentOutInPreviousRound, $state, $round, $levelAdvancers, $byes, $pairedAt)
            : $wentOutInPreviousRound;

        return [$entrants[0], $entrants[1]];
    }

    /**
     * Refuse the third-place option for a field of 2: its one tie decides
     * the two places there are. The entrant list says so before a round is
     * paired, so the bracket is refused then, on every call.
     *
     * A field of 3 is accepted: it has no third-place tie, and the one
     * entrant who goes out before the final is third.
     *
     * @throws InvalidConfigurationException When the option is on and the field has fewer than 3
     *                                       entrants
     */
    private function requireAThirdPlaceToDecide(int $participantCount): void
    {
        if (!$this->options->thirdPlace || $participantCount >= EliminationPlan::THIRD_PLACE_MINIMUM) {
            return;
        }

        throw new InvalidConfigurationException(
            'A bracket with the third-place option requires at least ' . EliminationPlan::THIRD_PLACE_MINIMUM
                . ' participants: with 2 there is no third place to decide',
            ['participant_count' => $participantCount, 'minimum_required' => EliminationPlan::THIRD_PLACE_MINIMUM],
            reason: InvalidConfigurationReason::TooFewParticipants
        );
    }

    /**
     * Refuse, without the third-place option, a state that has a
     * third-place tie recorded: an event marked BracketTie::ThirdPlace.
     *
     * This engine's last round has one tie, and it reads a recorded round
     * by the ties it has itself. The third-place tie would pass as an
     * event the application added: its results would count in the records,
     * its two entrants would share a tier, and a last round with the final
     * decided and that tie not would be a finished bracket. The mark is
     * what tells the tie from such an event, so it is the one thing this
     * engine reads of it; a recorded event without the mark is read by
     * nothing, as before the option existed. A field of 3 has no such tie
     * with the option either, and its state is read by both engines.
     *
     * @throws InvalidConfigurationException When the option is off and a recorded event is marked
     *                                       as a third-place tie
     */
    private function requireNoThirdPlaceTieRecorded(StageState $state): void
    {
        if ($this->options->thirdPlace) {
            return;
        }

        foreach ($state->getRoundsPlayed() as $recorded) {
            foreach ($recorded->getEvents() as $event) {
                if (BracketTie::of($event) !== BracketTie::ThirdPlace) {
                    continue;
                }

                $round = $recorded->getRoundNumber();

                throw new InvalidConfigurationException(
                    "The recorded round {$round} holds a third-place tie, and this bracket has none: the state was"
                        . ' paired by an engine with the third-place option. Read it with an engine that has the'
                        . ' option (EliminationOptions::$thirdPlace), or rebuild the state from the rounds that stand.',
                    [
                        'round' => $round,
                        'participants' => array_map(
                            static fn(Participant $participant): string => $participant->getId(),
                            array_values($event->getParticipants())
                        ),
                    ],
                    reason: InvalidConfigurationReason::RecordedRoundMismatch
                );
            }
        }
    }

    /**
     * Refuse a re-seeded bracket that will have a bye it cannot credit.
     *
     * A field that is not a power of two has byes in its first round, and
     * the ranking before the second round credits each one, which needs a
     * ranking strategy that says what a bye is worth
     * (RankingStrategyWithByeValue; StandingsCalculator::calculateWith()).
     * The entrant list says all of that before a round is paired, so the
     * bracket is refused then, on every call, and not after its first
     * round has been played.
     *
     * @throws InvalidConfigurationException When the bracket is re-seeded, the field is not a
     *                                       power of two and the ranking strategy does not say
     *                                       what a bye is worth
     */
    private function requireByesToBeCreditable(int $participantCount): void
    {
        if (!$this->options->reseedEachRound || $participantCount === $this->bracketSize($participantCount)) {
            return;
        }

        $rankingStrategy = $this->standingsCalculator->getRankingStrategy();
        if ($rankingStrategy instanceof RankingStrategyWithByeValue) {
            return;
        }

        throw new InvalidConfigurationException(
            "A re-seeded bracket of {$participantCount} entrants has byes, and crediting a bye requires a ranking strategy"
                . ' that says what a bye is worth: nothing else says what advancing without a tie counts for on its scale.'
                . ' Use a RankingStrategyWithByeValue (WinDrawLossRanking is one), a field that is a power of two, or a'
                . ' bracket on a fixed path',
            ['ranking_strategy' => $rankingStrategy::class, 'participant_count' => $participantCount],
            reason: InvalidConfigurationReason::IncompatibleOptions
        );
    }

    /**
     * The round the bracket has for a set of slots: the ties of the
     * bracket in bracket order, then the third-place tie where the round
     * has one.
     *
     * A tie an entrant has withdrawn from is not played: the round holds
     * no event for it, and the other entrant is listed among the byes
     * with those who stand alone in a pair of slots, after them the one who
     * takes the third place from a third-place tie the other has withdrawn
     * from. Nobody is listed for a tie both entrants have withdrawn from.
     *
     * With the third-place option, every event of the last round says
     * which tie it belongs to (see BracketTie), also in a field of 3,
     * whose last round holds the final alone. Without the option no event
     * carries that mark.
     *
     * @param array<array<Participant|null>> $pairs
     * @param array{Participant, Participant}|null $thirdPlaceTie
     * @param array<int|string, true> $withdrawn The IDs of the entrants who have withdrawn
     */
    private function buildRoundPairing(
        int $roundNumber,
        int $slotCount,
        array $pairs,
        ?array $thirdPlaceTie,
        array $withdrawn
    ): RoundPairing {
        $label = RoundLabel::ofBracketRound(BracketSide::Main, 0, 0, $slotCount);
        $round = new Round($roundNumber, [FormatMetadataKey::ROUND_LABEL => $label->toString()]);
        $marked = $this->options->thirdPlace && $slotCount === 2;

        $events = [];
        $byes = [];
        foreach ($pairs as $pair) {
            [$first, $second] = [$pair[0] ?? null, $pair[1] ?? null];
            if ($first !== null && $second !== null) {
                $firstOut = isset($withdrawn[$first->getId()]);
                $secondOut = isset($withdrawn[$second->getId()]);
                if (!$firstOut && !$secondOut) {
                    $events = [...$events, ...$this->buildTieEvents(
                        $first,
                        $second,
                        $round,
                        $this->options->legsPerTie,
                        $marked ? [BracketTie::METADATA_KEY => BracketTie::Final->value] : []
                    )];
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

        if ($thirdPlaceTie !== null) {
            $firstOut = isset($withdrawn[$thirdPlaceTie[0]->getId()]);
            $secondOut = isset($withdrawn[$thirdPlaceTie[1]->getId()]);
            if (!$firstOut && !$secondOut) {
                $events = [...$events, ...$this->buildTieEvents(
                    $thirdPlaceTie[0],
                    $thirdPlaceTie[1],
                    $round,
                    $this->options->legsPerTie,
                    [BracketTie::METADATA_KEY => BracketTie::ThirdPlace->value]
                )];
            } elseif (!$firstOut || !$secondOut) {
                $byes[] = $firstOut ? $thirdPlaceTie[1] : $thirdPlaceTie[0];
            }
        }

        return new RoundPairing($roundNumber, $label, $events, $byes);
    }

    /**
     * Order survivors by their standings rank as of the given round:
     * only results from earlier rounds count, so the ranking is stable
     * across replays regardless of what has been recorded since. A level
     * event of an earlier round counts as a win for whoever advanced from
     * it (see resultsForStandings()).
     *
     * A bye is an advance like a tie won, so it is credited as one: the
     * value the ranking strategy gives a bye (the value of a win unless the
     * caller chose another) for each leg of a tie. Without that an entrant who sat out the first
     * round would rank below everyone who played it, and the top of the
     * entrant list would be folded as the bottom of the round. Survivors
     * the table leaves level are in the order of the participant list:
     * no seed attribute, label or ID is read.
     *
     * An adjustment is in the ranking when it was made before the round
     * was paired (ADR 0001): for a round that is recorded, the
     * adjustments made after a round before it
     * (Adjustment::getAfterRound()), so that one recorded later changes
     * neither the round nor anything read from it; for the round that is
     * paired next, every adjustment the state holds, which is what is
     * known now. A stage state says of every adjustment when it was made.
     *
     * @param array<Participant> $survivors
     * @param array<string, Participant> $levelAdvancers
     * @param array<int|string, int> $byes Byes of the earlier rounds, by participant ID
     * @param int|null $pairedAt The number of the recorded round the ranking was made for, or
     *                           null when it is made now
     * @return array<Participant>
     *
     * @throws InvalidConfigurationException When a survivor had a bye and the ranking strategy does
     *                                       not say what a bye is worth
     */
    private function rankByStandings(
        array $survivors,
        StageState $state,
        int $beforeRound,
        array $levelAdvancers,
        array $byes,
        ?int $pairedAt
    ): array {
        $adjustments = array_values(array_filter(
            $state->getAdjustments(),
            static fn(Adjustment $adjustment): bool => $pairedAt === null
                || ($adjustment->getAfterRound() ?? 0) < $pairedAt
        ));

        $priorResults = array_values(array_filter(
            $state->getResults(),
            fn($result) => ($result->getEvent()->getRound()?->getNumber() ?? 0) < $beforeRound
        ));

        $entrants = $this->entrantsOf($state);
        $standings = $this->standingsCalculator
            ->calculateWith(
                $entrants,
                $this->resultsForStandings($priorResults, $levelAdvancers),
                $byes,
                $adjustments,
                $this->options->legsPerTie
            )
            ->withTiedSetsOrderedBy($entrants);

        $survivorIds = [];
        foreach ($survivors as $survivor) {
            $survivorIds[$survivor->getId()] = true;
        }

        $ranked = [];
        foreach ($standings->getEntries() as $entry) {
            if (isset($survivorIds[$entry->getParticipant()->getId()])) {
                $ranked[] = $entry->getParticipant();
            }
        }

        return $ranked;
    }
}
