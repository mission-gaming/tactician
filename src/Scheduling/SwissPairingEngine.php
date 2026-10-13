<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Exceptions\UnresolvedRoundException;
use MissionGaming\Tactician\Stage\EngineFingerprint;
use MissionGaming\Tactician\Stage\FingerprintedEngine;
use MissionGaming\Tactician\Stage\HistoryReadingEngine;
use MissionGaming\Tactician\Stage\PairingReadiness;
use MissionGaming\Tactician\Stage\PairKey;
use MissionGaming\Tactician\Stage\RecordedRound;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\SwissPlan;
use MissionGaming\Tactician\Stage\Withdrawal;
use MissionGaming\Tactician\Standings\EntryOrder;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use Override;
use Random\Randomizer;

/**
 * Pairs Swiss rounds incrementally from the recorded stage state.
 *
 * Unlike whole-schedule generators, Swiss pairings depend on results: each
 * round is paired from the current standings. This engine implements
 * Monrad-style pairing - participants ordered by standings and paired
 * adjacently (leader vs runner-up, and so on) - with backtracking to avoid
 * repeat pairings and satisfy constraints. Byes rotate to the lowest-placed
 * participant who has had the fewest, and the first-named role of a pairing
 * goes to whichever of the two has been first-named less often (to the
 * lower-placed one when they are level).
 *
 * Byes recorded on the state are credited, although no Result exists for
 * a bye: each adds what the ranking strategy of the standings calculator
 * says a bye is worth (RankingStrategyWithByeValue). For a WinDrawLossRanking
 * that is the value of a win unless another is given (the value of a draw,
 * zero, any amount), so by default a bye recipient is paired among the
 * winners in the following round. The credit is part of the one table the
 * engine builds: the order a round is paired from and the table of the
 * outcome are the same table, so the two cannot value a bye differently.
 *
 * That table also has what the application recorded beside the play. An
 * awarded result (DTO\Result::isAwarded()) is a result: it counts for the
 * participants it names, and its event awaits no other outcome. An
 * adjustment recorded on the state (StageState::withAdjustment()) is added
 * to the ranking value of its participant, so the next round is paired from
 * the adjusted totals, and the outcome shows the adjustment as a figure of
 * its own.
 *
 * Where no result separates two participants, their position in the
 * participant list of the state decides: the active participants in the
 * order the stage was started with. The first round pairs position 1 with
 * 2 and 3 with 4, and its bye goes to the last position. No seed
 * attribute, label or ID is read.
 *
 * Withdrawals are supported via StageState::withoutParticipant(): the
 * participant leaves the active list, and their recorded pairings and
 * results still count toward the remaining participants' standings.
 *
 * A round is paired from the results of the rounds before it, so the
 * engine waits for them: while an event of a recorded round has no
 * result, pairNextRound() refuses with an UnresolvedRoundException that
 * lists the events, isComplete() is false, and getReadiness() says the
 * same without throwing. Reading the stage does not wait: the table
 * (getStandings()) and the recorded rounds are available at any point. An
 * application that means to pair a round before the last results are in
 * says so by calling pairNextRoundOnIncompleteResults(), and the round it
 * gets is marked as paired that way. An event that is never played is
 * given an awarded result, which is a result.
 *
 * Results-free mode (the resultsFree constructor argument) is the other
 * way to pair without results, and it is a property of the stage, not of
 * one call: the rounds are recorded without results on purpose, no event
 * awaits one, and the stage is complete when its planned rounds are
 * recorded. Repeat avoidance reads the pairings recorded on the state,
 * not the results - so driving the engine in this mode with a Randomizer
 * produces non-repeat pairing from a shuffled field, which is the
 * whole-schedule Swiss preset SwissScheduler wraps. In a field of odd size
 * that pairing is not random throughout: a recorded bye is credited (as a
 * win, with the default ranking strategy the preset uses), so the
 * participants who have had a bye are placed above the rest and are
 * paired with each other first. The mode is part of the engine's
 * fingerprint, so a state stamped by an engine in one mode is refused by
 * an engine in the other: a stage with no results yet is never mistaken
 * for one that is to have none.
 *
 * Without a Randomizer the engine draws nothing: the same state gives the
 * same pairing. With one, each call to pairNextRound() draws from it, so
 * pairing the same state twice gives two different rounds unless the
 * randomizer is seeded the same before each call.
 *
 * Constraints that reason about the tournament length (e.g.
 * SeedProtectionConstraint) need to know the planned number of rounds;
 * provide it via the plannedRounds constructor argument.
 *
 * @experimental
 */
readonly class SwissPairingEngine implements StageEngineInterface, FingerprintedEngine, HistoryReadingEngine
{
    /**
     * @param ConstraintSet|null $constraints Asked about every candidate pairing, with a context of
     *                                        the recorded rounds and the pairings made so far in
     *                                        the round; a pairing one of them rejects is not made
     * @param StandingsCalculator $standingsCalculator Orders the table each round is paired from
     * @param int|null $plannedRounds Total rounds the tournament will run, exposed to
     *                                constraints via the stage plan on the scheduling context;
     *                                null leaves the stage open-ended (isComplete() only
     *                                becomes true when too few participants remain)
     * @param Randomizer|null $randomizer Shuffles pairing order within equal-ranking groups;
     *                                    with no recorded rounds the whole field ties at zero,
     *                                    so the entire pairing order is shuffled
     * @param bool $resultsFree Whether the stage is paired without results on purpose (see the
     *                          class docblock): no recorded event then awaits a result, and a
     *                          round is paired whatever the earlier rounds have of them
     * @throws InvalidInputException When the planned rounds are below 1
     */
    public function __construct(
        private ?ConstraintSet $constraints = null,
        private StandingsCalculator $standingsCalculator = new StandingsCalculator(),
        private ?int $plannedRounds = null,
        private ?Randomizer $randomizer = null,
        private bool $resultsFree = false
    ) {
        if ($plannedRounds !== null && $plannedRounds < 1) {
            throw new InvalidInputException('Planned rounds must be at least 1');
        }
    }

    /**
     * What this engine stamps a stage state with, and requires of a state
     * that carries a stamp (see FingerprintedEngine for the contract).
     *
     * With a standings calculator built without arguments it names the
     * format and nothing else. Part of it when not at the default, because
     * each round is paired from the table:
     *
     * - the ranking scale: the three values of a WinDrawLossRanking, with
     *   3/1/0 as the default. Any other RankingStrategy is stated as custom,
     *   so it differs from every win/draw/loss scale; two strategies of
     *   your own are not told apart;
     * - the tiebreakers, by name and in order (default: none);
     * - each of the two choices of the awarded result policy, when it is
     *   off (default: both on);
     * - a subclass of StandingsCalculator, stated as custom in place of the
     *   two above: it may order the table by rules of its own, so nothing
     *   is read from it, and two subclasses are not told apart;
     * - results-free mode, when it is on (default: off). An engine that
     *   waits for results has the fingerprint it had before the mode
     *   existed.
     *
     * Not part of it:
     *
     * - the planned rounds. They say when the stage ends. No round is
     *   paired or read differently for them, so a stage extended or cut
     *   short by a round keeps its stamp;
     * - the constraints and the randomizer. They do change pairings, and
     *   the engine cannot describe them: a closure and a seed have no name.
     *   Keeping them the same between rounds is the caller's to do.
     *
     * A subclass does not inherit this engine's fingerprint, because it may
     * pair differently: its fingerprint says that it is a subclass, without
     * naming the class, so every subclass that does not override this
     * method has the same one. Override it to return a string of your own
     * (one that does not begin with `tactician:`) to tell them apart.
     */
    #[Override]
    public function getFingerprint(): string
    {
        return EngineFingerprint::of('swiss')
            ->withStandingsRules($this->standingsCalculator)
            ->with('results-free', $this->resultsFree, false)
            ->with('subclass', static::class !== self::class, false)
            ->toString();
    }

    /**
     * The shape declaration for this stage: rounds when planned, no legs.
     *
     * The plan covers every participant the stage has seen - active ones
     * plus withdrawn participants still referenced by recorded rounds.
     *
     * @throws InvalidConfigurationException When fewer than 2 participants have been seen, or
     *                                       the state is stamped with another engine's fingerprint
     */
    #[Override]
    public function getPlan(StageState $state): SwissPlan
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        return new SwissPlan($state->getAllSeenParticipants(), $this->plannedRounds);
    }

    /**
     * Pair the next round from the recorded state.
     *
     * The pairing carries the state's next round number (1-based) and no
     * label. Its events are in the order the pairs were made, from the top
     * of the pairing order down, and every active participant is in exactly
     * one of them, but for the one who has the bye in a field of odd size.
     * No two participants are paired who are paired in a round recorded on
     * the state. The call does not record anything: pass the pairing and
     * its results to StageState::withRoundPlayed(). A stage that has played
     * its planned rounds has no next round, and asking for one is refused,
     * as the bracket engines refuse a round after the final; ask
     * isComplete() first. A stage with no planned rounds is never refused
     * for its length.
     *
     * A round is paired from the table, so the rounds before it have to be
     * finished: while an event of a recorded round has no result the call
     * is refused, and the exception lists the events. Ask getReadiness()
     * to learn the same without catching anything. In results-free mode no
     * event awaits a result and the call is never refused for one.
     *
     * The search is exhaustive: the exception means that no complete
     * pairing exists, not that the search gave up. In a field of odd size
     * every participant is tried as the bye, in the order the class
     * docblock gives, before it is thrown.
     *
     * @throws UnresolvedRoundException When an event of a recorded round has no result and the
     *                                  engine is not in results-free mode (reason
     *                                  `RoundPartiallyResolved`)
     * @throws InvalidConfigurationException When fewer than 2 active participants remain, the
     *                                       planned rounds have all been played (reason
     *                                       `PlannedRoundsPlayed`), the state is stamped with
     *                                       another engine's fingerprint, or a bye is recorded and the standings calculator's
     *                                       ranking strategy does not say what a bye is worth (it
     *                                       is no RankingStrategyWithByeValue)
     * @throws NoValidPairingException When no complete pairing exists for the round
     */
    #[Override]
    public function pairNextRound(StageState $state): RoundPairing
    {
        $state->requireEngineFingerprint($this->getFingerprint());
        $this->validateParticipants(array_values($state->getParticipants()));

        $unresolved = $this->unresolvedEvents($state);
        if ($unresolved !== []) {
            throw new UnresolvedRoundException(
                $unresolved[0]->getRound()?->getNumber() ?? 0,
                $unresolved,
                'Record the remaining results before pairing the next round, or pair it now with'
                    . ' pairNextRoundOnIncompleteResults() if that is what is meant.'
            );
        }

        return $this->pair($state, false);
    }

    /**
     * Pair the next round now, whether or not the recorded rounds have all
     * their results: the explicit way to pair early.
     *
     * The round is paired from the table as it stands, in which an event
     * without a result counts for nobody. When an event did await a result
     * the pairing is marked (RoundPairing::isPairedOnIncompleteResults()),
     * and the mark is recorded and stored with the round. When none did,
     * this is pairNextRound() and the pairing carries no mark. In
     * results-free mode no event awaits a result, so nothing is marked.
     *
     * The event still awaits its result afterwards: pairNextRound()
     * refuses and isComplete() is false until it has one. Once the round
     * paired here is recorded, StageState::withAdditionalResults() no
     * longer reaches the earlier round (it adds to the last round only):
     * supply the late result with the history (StageState::fromHistory()),
     * or rebuild the state from its rounds.
     *
     * @throws InvalidConfigurationException For every case pairNextRound() names but the
     *                                       unfinished round
     * @throws NoValidPairingException When no complete pairing exists for the round
     */
    public function pairNextRoundOnIncompleteResults(StageState $state): RoundPairing
    {
        $state->requireEngineFingerprint($this->getFingerprint());
        $this->validateParticipants(array_values($state->getParticipants()));

        return $this->pair($state, $this->unresolvedEvents($state) !== []);
    }

    /**
     * Whether the next round can be paired now and, when it cannot, the
     * events that await a result (see HistoryReadingEngine).
     *
     * A stage with fewer than 2 active participants is complete, as
     * isComplete() has it.
     *
     * Ready means that no recorded event awaits a result and that the
     * table the round is paired from can be built. The search for the
     * round is not run here, so pairNextRound() can still find that no
     * pairing is left that nobody has played (NoValidPairingException).
     *
     * @throws InvalidConfigurationException When the state is stamped with another engine's
     *                                       fingerprint, or the next round could be paired and a
     *                                       bye is recorded under a ranking strategy that does not
     *                                       say what a bye is worth (it is no
     *                                       RankingStrategyWithByeValue), as pairNextRound()
     *                                       refuses it
     * @throws InvalidInputException When the next round could be paired and the table cannot be
     *                               built from the results, as pairNextRound() refuses it: an
     *                               event has two results (a state StageState::fromHistory()
     *                               refuses)
     */
    #[Override]
    public function getReadiness(StageState $state): PairingReadiness
    {
        if ($this->isComplete($state)) {
            return PairingReadiness::complete();
        }

        $unresolved = $this->unresolvedEvents($state);
        if ($unresolved !== []) {
            return PairingReadiness::awaitingResults($unresolved[0]->getRound()?->getNumber() ?? 0, $unresolved);
        }

        // The round is paired from the table: a state the table cannot be
        // built from is refused here as pairNextRound() refuses it, and is
        // not called ready
        $this->table($state);

        return PairingReadiness::ready($state->getNextRoundNumber());
    }

    /**
     * Every recorded round as this engine reads it (see RecordedRound).
     *
     * The derivation of a round is the round this engine pairs from what
     * was known when the round was paired: the rounds recorded before it
     * and their results, the adjustments made before it
     * (Adjustment::getAfterRound()), and the participants who had not
     * withdrawn by then (StageState::getWithdrawals(); a participant whose
     * withdrawal has no round is taken to have been in the stage for the
     * rounds that name it and for those before them). So a round this
     * engine paired is not reported as manual for what was recorded after
     * it.
     *
     * An engine that holds a Randomizer has no derivation to give: what it
     * drew when the round was paired is not part of the state. Neither has
     * a state that holds an adjustment which does not say when it was
     * made: the table the round was paired from is then not known, and no
     * round is reported as manual. Neither has an engine whose search
     * finds no pairing for the round, or a state whose earlier rounds
     * StageState::fromHistory() would refuse.
     *
     * @return list<RecordedRound>
     *
     * @throws InvalidConfigurationException When the state is stamped with another engine's fingerprint
     */
    #[Override]
    public function getRecordedRounds(StageState $state): array
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        $unresolvedByRound = [];
        foreach ($this->unresolvedEvents($state) as $event) {
            $unresolvedByRound[$event->getRound()?->getNumber() ?? 0][] = $event;
        }

        $rounds = [];
        foreach (array_values($state->getRoundsPlayed()) as $index => $pairing) {
            $derivation = $this->derivationOf($state, $index);

            $rounds[] = new RecordedRound(
                $pairing,
                $derivation,
                $derivation instanceof RoundPairing && !$this->pairsTheSame($pairing, $derivation),
                $unresolvedByRound[$pairing->getRoundNumber()] ?? []
            );
        }

        return $rounds;
    }

    /**
     * The table of the stage as recorded so far: the one the next round is
     * paired from, and the one the outcome carries when the stage is
     * complete. An event without a result counts for nobody in it.
     *
     * @throws InvalidConfigurationException When the state is stamped with another engine's
     *                                       fingerprint, or a bye is recorded and the ranking
     *                                       strategy does not say what a bye is worth (it is no
     *                                       RankingStrategyWithByeValue)
     */
    #[Override]
    public function getStandings(StageState $state): Standings
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        return $this->table($state);
    }

    /**
     * The recorded events that await a result, in play order: every event
     * without one, and none at all in results-free mode.
     *
     * @return list<Event>
     */
    private function unresolvedEvents(StageState $state): array
    {
        return $this->resultsFree ? [] : $state->getEventsWithoutResult();
    }

    /**
     * The round this engine pairs in the place of a recorded one, or null
     * when it cannot say (see getRecordedRounds()).
     *
     * @param int $index The place of the recorded round among the rounds of the state
     */
    private function derivationOf(StageState $state, int $index): ?RoundPairing
    {
        if ($this->randomizer !== null) {
            return null;
        }

        $rounds = array_values($state->getRoundsPlayed());
        $number = $rounds[$index]->getRoundNumber();

        // The table the round was paired from has the adjustments made
        // before it. One that does not say when it was made may or may not
        // be among them, and the engine does not guess.
        $adjustments = [];
        foreach ($state->getAdjustments() as $adjustment) {
            if ($adjustment->getAfterRound() === null) {
                return null;
            }
            if ($adjustment->getAfterRound() < $number) {
                $adjustments[] = $adjustment;
            }
        }

        // Who was in the stage when the round was paired: everyone who is
        // active now, and everyone who withdrew after this round or a
        // later one was recorded. Where the state does not say when a
        // participant withdrew, it was in the stage for this round if this
        // round or a later one names it.
        $present = [];
        foreach ($state->getParticipants() as $participant) {
            $present[$participant->getId()] = true;
        }
        $withdrewEarlier = [];
        foreach ($state->getWithdrawals() as $withdrawal) {
            if ($withdrawal->getAfterRound() < $number) {
                $withdrewEarlier[$withdrawal->getParticipant()->getId()] = $withdrawal;
            } else {
                $present[$withdrawal->getParticipant()->getId()] = true;
            }
        }
        foreach (array_slice($rounds, $index) as $later) {
            foreach ($later->getEvents() as $event) {
                foreach ($event->getParticipants() as $participant) {
                    if (!isset($withdrewEarlier[$participant->getId()])) {
                        $present[$participant->getId()] = true;
                    }
                }
            }
            foreach ($later->getByes() as $bye) {
                if (!isset($withdrewEarlier[$bye->getId()])) {
                    $present[$bye->getId()] = true;
                }
            }
        }

        $entrants = $state->getEntrants();
        $listed = [];
        foreach ($entrants as $entrant) {
            $listed[$entrant->getId()] = true;
        }
        foreach ($state->getAllSeenParticipants() as $seen) {
            if (!isset($listed[$seen->getId()])) {
                $entrants[] = $seen;
            }
        }

        try {
            $before = StageState::fromHistory(
                $entrants,
                array_slice($rounds, 0, $index),
                array_values(array_filter(
                    $state->getResults(),
                    static fn(Result $result): bool => ($result->getEvent()->getRound()?->getNumber() ?? 0) < $number
                )),
                array_map(
                    static fn(Participant $entrant): Participant|Withdrawal => $withdrewEarlier[$entrant->getId()] ?? $entrant,
                    array_values(array_filter(
                        $entrants,
                        static fn(Participant $entrant): bool => !isset($present[$entrant->getId()])
                    ))
                ),
                $adjustments
            );

            // Before its first round a stage has only the participants it
            // was started with, and fromHistory() has dropped the others
            return count($before->getParticipants()) < 2 ? null : $this->pair($before, false);
        } catch (InvalidConfigurationException|InvalidInputException|NoValidPairingException) {
            return null;
        }
    }

    /**
     * Whether two rounds pair the same participants and leave the same
     * ones out: roles, order, labels and metadata are not compared.
     */
    private function pairsTheSame(RoundPairing $one, RoundPairing $other): bool
    {
        $shape = static function (RoundPairing $pairing): array {
            $pairs = [];
            foreach ($pairing->getEvents() as $event) {
                $pairs[] = 'event ' . PairKey::of(...array_map(
                    static fn(Participant $participant): string => $participant->getId(),
                    array_values($event->getParticipants())
                ));
            }
            foreach ($pairing->getByes() as $bye) {
                $pairs[] = 'bye ' . PairKey::of($bye->getId());
            }
            $pairs = array_values(array_unique($pairs));
            sort($pairs, SORT_STRING);

            return $pairs;
        };

        return $shape($one) === $shape($other);
    }

    /**
     * Pair a round from the state, whatever its recorded rounds have of
     * results.
     *
     * @param bool $onIncompleteResults Whether the pairing is to be marked as made while an
     *                                  event awaited a result
     *
     * @throws InvalidConfigurationException When the planned rounds have all been played, or a
     *                                       bye is recorded under a ranking strategy that does
     *                                       not say what a bye is worth
     * @throws NoValidPairingException When no complete pairing exists for the round
     */
    private function pair(StageState $state, bool $onIncompleteResults): RoundPairing
    {
        $participants = array_values($state->getParticipants());

        $roundNumber = $state->getNextRoundNumber();
        if ($this->plannedRounds !== null && $roundNumber > $this->plannedRounds) {
            throw new InvalidConfigurationException(
                'The planned rounds have all been played; no further round exists',
                ['planned_rounds' => $this->plannedRounds, 'next_round' => $roundNumber],
                reason: InvalidConfigurationReason::PlannedRoundsPlayed
            );
        }

        // Include withdrawn participants referenced by results so their games
        // still count toward standings, then keep only active participants
        // for pairing.
        $activeIds = [];
        foreach ($participants as $participant) {
            $activeIds[$participant->getId()] = true;
        }

        $activeEntries = array_values(array_filter(
            $this->table($state)->getEntries(),
            fn($entry) => isset($activeIds[$entry->getParticipant()->getId()])
        ));
        $orderedParticipants = $this->orderForPairing($activeEntries, $participants);

        // Repeat avoidance and home balancing read the recorded pairings,
        // not the results, so rounds recorded without results still count.
        $playedEvents = $state->getPlayedEvents();
        $playedPairings = $this->collectPlayedPairings($playedEvents);
        $homeCounts = $this->collectHomeCounts($playedEvents);

        $plan = new SwissPlan($state->getAllSeenParticipants(), $this->plannedRounds);
        $context = new SchedulingContext($participants, $plan, $playedEvents);

        // Participants are paired adjacently in pairing order, backtracking
        // past repeat pairings and constraint rejections.
        $search = new SwissRoundSearch($this->constraints, $playedPairings, $homeCounts, $context, $roundNumber);

        if (count($orderedParticipants) % 2 === 0) {
            $events = $search->pair($orderedParticipants);

            if ($events !== null) {
                return new RoundPairing($roundNumber, null, $events, [], $onIncompleteResults);
            }

            throw new NoValidPairingException($roundNumber, $participants);
        }

        foreach ($this->orderByeCandidates($orderedParticipants, $state->getByeIds()) as $byeCandidate) {
            $remaining = array_values(array_filter(
                $orderedParticipants,
                fn(Participant $participant) => $participant->getId() !== $byeCandidate->getId()
            ));

            $events = $search->pair($remaining);

            if ($events !== null) {
                return new RoundPairing($roundNumber, null, $events, [$byeCandidate], $onIncompleteResults);
            }
        }

        throw new NoValidPairingException($roundNumber, $participants);
    }

    /**
     * A Swiss stage is complete when its planned rounds have all been
     * played, or when too few active participants remain to pair another
     * round. An open-ended stage (no planned rounds) with enough
     * participants never reports complete - the application decides when
     * to stop, or pairNextRound() throws when no valid pairing remains.
     *
     * A round is played when every event of it has a result: a stage whose
     * planned rounds are all recorded and whose last round still has an
     * event without one is not complete, and nothing is thrown for it. In
     * results-free mode a round is played once it is recorded.
     *
     * @throws InvalidConfigurationException When the state is stamped with another engine's fingerprint
     */
    #[Override]
    public function isComplete(StageState $state): bool
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        if (count($state->getParticipants()) < 2) {
            return true;
        }

        return $this->plannedRounds !== null
            && $state->getNextRoundNumber() > $this->plannedRounds
            && $this->unresolvedEvents($state) === [];
    }

    /**
     * The uniform completion product; null while the stage is unfinished.
     *
     * Standings cover every participant the stage has seen, including
     * withdrawn ones - their played games remain part of the record.
     *
     * The standings are the table the rounds were paired from, so a bye
     * counts in them as it counts for pairing: with the value the ranking
     * strategy gives a bye, in the ranking value of the participant who had
     * it. StandingEntry::getByes() says how many byes an entry has, also
     * where a bye is worth nothing; played, wins, draws and losses count
     * results only, and the outcome lists the byes beside the table. Entries that no result separates are in the order of the
     * participant list (Standings::getTiedSets() reports them).
     *
     * @throws InvalidConfigurationException When the state is stamped with another engine's
     *                                       fingerprint, or a bye is recorded and the ranking
     *                                       strategy does not say what a bye is worth
     */
    #[Override]
    public function getOutcome(StageState $state): ?StageOutcome
    {
        if (!$this->isComplete($state)) {
            return null;
        }

        return new StageOutcome(
            $this->table($state),
            $state->getResults(),
            $state->getByeCounts(),
            $state->getLastRound(),
            [],
            $this->entrants($state)
        );
    }

    /**
     * The table of the stage as recorded: the one every round is paired
     * from and the one the outcome carries.
     *
     * The standings calculator computes it in one call from the results,
     * the byes and the adjustments recorded on the state
     * (StandingsCalculator::calculateWith()). Each recorded bye is credited
     * with what the calculator's ranking strategy says a bye is worth
     * (RankingStrategyWithByeValue: the value of a win unless the caller
     * chose another). A ranking strategy that does not say fails loudly
     * rather than have the engine guess what sitting out is worth on an
     * unknown scale. Each adjustment (StageState::withAdjustment()) is an
     * amount of the ranking value, so it is defined under every ranking
     * strategy. Last, entries that no result separates are put in the order
     * of the entrant list (see entrants()). No seed attribute or label
     * decides an order, and no ID decides one between two active
     * participants.
     *
     * @throws InvalidConfigurationException When a bye is recorded and the ranking strategy does
     *                                       not say what a bye is worth
     */
    private function table(StageState $state): Standings
    {
        $entrants = $this->entrants($state);

        return $this->standingsCalculator
            ->calculateWith($entrants, $state->getResults(), $state->getByeCounts(), $state->getAdjustments())
            ->withTiedSetsOrderedBy($entrants);
    }

    /**
     * The entrant list of the stage: the active participants in the order
     * of the state's list, then those who have withdrawn.
     *
     * A withdrawn participant has no position any more: the state keeps it
     * only in the rounds and results that name it. The withdrawn are
     * therefore listed in the order of their IDs (the total order the
     * standings calculator falls back on), so that the list is the same
     * whatever order the recorded events and results are in.
     *
     * @return list<Participant>
     */
    private function entrants(StageState $state): array
    {
        $active = array_values($state->getParticipants());
        $withdrawn = array_slice(array_values($state->getAllSeenParticipants()), count($active));
        usort(
            $withdrawn,
            static fn(Participant $first, Participant $second): int => EntryOrder::compareIds($first->getId(), $second->getId())
        );

        return [...$active, ...$withdrawn];
    }

    /**
     * ID uniqueness is StageState's invariant (start() validates it and
     * every transition preserves it), so only the size needs checking.
     *
     * @param array<Participant> $participants
     *
     * @throws InvalidConfigurationException
     */
    private function validateParticipants(array $participants): void
    {
        if (count($participants) < 2) {
            throw new InvalidConfigurationException(
                'Swiss pairing requires at least 2 participants',
                ['participant_count' => count($participants), 'minimum_required' => 2],
                reason: InvalidConfigurationReason::TooFewParticipants
            );
        }
    }

    /**
     * @param array<Event> $playedEvents
     * @return array<string, bool>
     */
    private function collectPlayedPairings(array $playedEvents): array
    {
        $playedPairings = [];
        foreach ($playedEvents as $event) {
            $eventParticipants = $event->getParticipants();
            if (count($eventParticipants) === 2) {
                $playedPairings[PairKey::of($eventParticipants[0]->getId(), $eventParticipants[1]->getId())] = true;
            }
        }

        return $playedPairings;
    }

    /**
     * @param array<Event> $playedEvents
     * @return array<string, int>
     */
    private function collectHomeCounts(array $playedEvents): array
    {
        $homeCounts = [];
        foreach ($playedEvents as $event) {
            $eventParticipants = $event->getParticipants();
            if (count($eventParticipants) === 2) {
                $homeId = $eventParticipants[0]->getId();
                $homeCounts[$homeId] = ($homeCounts[$homeId] ?? 0) + 1;
            }
        }

        return $homeCounts;
    }

    /**
     * Order participants for pairing: the order of the table (see
     * table(): byes credited with the value the ranking strategy gives
     * them, and level entries in list order) and - when a randomizer is
     * configured - shuffled within each score group (see
     * groupLevelRankings() for what level means).
     *
     * @param array<StandingEntry> $entries The entries of the active participants, in table order
     * @param array<Participant> $entrants The participant list, for the order of level entries
     * @return array<Participant>
     */
    private function orderForPairing(array $entries, array $entrants): array
    {
        $entries = array_values($entries);
        $positions = [];
        foreach (array_values($entrants) as $position => $entrant) {
            $positions[$entrant->getId()] = $position;
        }

        $indexed = [];
        foreach ($entries as $index => $entry) {
            $indexed[] = [
                'participant' => $entry->getParticipant(),
                'ranking_value' => $entry->getRankingValue(),
                'index' => $index,
            ];
        }

        // The library's calculator gives the table in this order already;
        // a subclass may order its table by rules of its own.
        usort(
            $indexed,
            fn(array $first, array $second): int => ($second['ranking_value'] <=> $first['ranking_value'])
                ?: ($first['index'] <=> $second['index'])
        );

        $tolerance = $this->levelTolerance($entries);

        $ordered = [];
        foreach ($this->groupLevelRankings($indexed, $tolerance) as $group) {
            // Within a group the table decides, not the last bits of a sum.
            usort($group, fn(array $first, array $second): int => $first['index'] <=> $second['index']);

            // The table is ordered by the ranking value first, to the last
            // bit. Where a group holds values that differ only there, the
            // table's order inside it follows those bits, so the group is
            // put in the order the table has without them: the figures
            // after the ranking value, then the position in the list.
            $inexact = false;
            foreach ($group as $member) {
                $inexact = $inexact || $member['ranking_value'] !== $group[0]['ranking_value'];
            }
            if ($inexact) {
                usort($group, function (array $first, array $second) use ($entries, $positions): int {
                    $one = $entries[$first['index']];
                    $other = $entries[$second['index']];

                    $byFigures = EntryOrder::compareFigures($one, $other, array_keys($one->getTiebreakers()), false);
                    if ($byFigures !== 0) {
                        return $byFigures;
                    }

                    return [$positions[$one->getParticipant()->getId()] ?? PHP_INT_MAX, $first['index']]
                        <=> [$positions[$other->getParticipant()->getId()] ?? PHP_INT_MAX, $second['index']];
                });
            }

            if ($this->randomizer !== null) {
                $group = $this->randomizer->shuffleArray($group);
            }

            foreach ($group as $entry) {
                $ordered[] = $entry['participant'];
            }
        }

        return $ordered;
    }

    /**
     * How far apart two ranking values may be and still be level: the most
     * that the rounding of float sums can put between two totals that are
     * the same total. Zero means level is equal.
     *
     * Only a WinDrawLossRanking gets a tolerance, because only there does
     * the engine know how a value was made: a sum of one configured value
     * per result (the class is final, so there is no subclass to consider).
     * Any other RankingStrategy is compared exactly: its values are its
     * own, and two that differ are two different scores however close.
     *
     * The bound. Let M be the largest magnitude of the win, draw, loss and
     * bye values, n the most terms any participant's total has (results played
     * plus byes credited), and u = PHP_FLOAT_EPSILON / 2 the unit roundoff.
     *
     * - Adding k floats in sequence is off by at most (k - 1) * u * S,
     *   where S is the sum of their magnitudes and S <= k * M (the
     *   standard bound for recursive summation, to first order in u).
     * - Crediting b byes multiplies once (off by at most u * b * M) and
     *   adds once (off by at most u * (k + b) * M).
     * - Together, with n = k + b: (k - 1) * k + b + n <= n * n, so one
     *   total is off by at most n * n * u * M.
     * - Two totals of the same results are therefore at most
     *   2 * n * n * u * M apart. The tolerance is twice that,
     *   2 * n * n * PHP_FLOAT_EPSILON * M: the other half covers the
     *   terms of higher order in u and the rounding of the configured
     *   values themselves (0.1 is not a tenth), so three draws at 0.1
     *   are level with one win at 0.3.
     *
     * For 3/1/0 over 11 rounds that is 1.6e-13: sums of whole and half
     * points are exact, so nothing but equal values is ever inside it.
     *
     * An adjustment (StandingEntry::getAdjustment()) is added to a total
     * once, so an adjusted entry has one term more, and M is at least the
     * largest magnitude of an adjustment in the table. The amounts are the
     * application's: two totals that differ by more than the tolerance
     * because their adjustments differ are two different totals.
     *
     * The cap. A total that differs from another by a real result is one
     * step away: a result more (a win, draw or loss value that is not
     * zero) or a result changed (the difference of two of them). A bye
     * whose value is not that of a win is a step of its own: a bye more,
     * or a bye in place of a result. The
     * tolerance never exceeds a quarter of the smallest such step, so on a
     * scale so stretched that the bound above would reach a step (a win
     * worth 1e15 draws), totals a real result apart still fall in
     * different groups, exactly as an exact comparison has them.
     *
     * @param array<StandingEntry> $entries Entries with their byes credited
     */
    private function levelTolerance(array $entries): float
    {
        $rankingStrategy = $this->standingsCalculator->getRankingStrategy();
        if (!$rankingStrategy instanceof WinDrawLossRanking) {
            return 0.0;
        }

        $win = $rankingStrategy->getWinValue();
        $draw = $rankingStrategy->getDrawValue();
        $loss = $rankingStrategy->getLossValue();

        // An adjustment is one more term of a total, and may be the
        // largest: adding it rounds to the last bit of a total that large.
        // A table without adjustments has the terms and the magnitude it
        // had before they existed.
        $terms = 0;
        $bye = $rankingStrategy->getByeValue();
        $magnitude = max(abs($win), abs($draw), abs($loss), abs($bye));
        foreach ($entries as $entry) {
            $adjustment = $entry->getAdjustment();
            $terms = max($terms, $entry->getPlayed() + $entry->getByes() + ($adjustment === 0.0 ? 0 : 1));
            $magnitude = max($magnitude, abs($adjustment));
        }

        $tolerance = 2.0 * $terms * $terms * PHP_FLOAT_EPSILON * $magnitude;

        // With a bye worth a win, the steps of a bye are those of a win
        // and are in the list already
        $steps = [$win, $draw, $loss, $win - $draw, $draw - $loss, $win - $loss];
        if ($bye !== $win) {
            $steps = [...$steps, $bye, $bye - $win, $bye - $draw, $bye - $loss];
        }

        foreach ($steps as $step) {
            if ($step !== 0.0) {
                $tolerance = min($tolerance, abs($step) / 4.0);
            }
        }

        // A value that is not a finite number has no rounding to allow for.
        return is_finite($tolerance) ? $tolerance : 0.0;
    }

    /**
     * Split entries into score groups: runs of participants who are level.
     *
     * A win/draw/loss ranking value is a float sum, and the sum of values a
     * float cannot hold exactly depends on the order of its terms: at 1 for
     * a win and 0.1 for a draw, win-draw-draw is 1.2000000000000002 and
     * draw-draw-win is 1.2. Two values are therefore level when they are
     * equal or no further apart than the tolerance (see levelTolerance();
     * zero for every ranking strategy but WinDrawLossRanking). A group is
     * measured from its highest value, so a chain of near-equal values
     * cannot stretch it.
     *
     * Scoring that floats hold exactly (3/1/0, 1/0.5/0) is unaffected:
     * there, level means equal. With no recorded rounds every participant
     * ties at zero, so a randomizer shuffles the whole field - which is
     * what makes results-free driving produce random non-repeat pairings.
     *
     * @param array<array{participant: Participant, ranking_value: float, index: int}> $indexed Ordered best first
     * @return list<list<array{participant: Participant, ranking_value: float, index: int}>>
     */
    private function groupLevelRankings(array $indexed, float $tolerance): array
    {
        $groups = [];
        $group = [];
        $groupValue = 0.0;

        foreach ($indexed as $entry) {
            // INF and NAN are never within a tolerance of anything: the
            // difference is INF or NAN, and neither is <= a finite number.
            $level = $entry['ranking_value'] === $groupValue
                || abs($groupValue - $entry['ranking_value']) <= $tolerance;

            if ($group !== [] && !$level) {
                $groups[] = $group;
                $group = [];
            }
            if ($group === []) {
                $groupValue = $entry['ranking_value'];
            }
            $group[] = $entry;
        }

        if ($group !== []) {
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * Order bye candidates by fewest previous byes, then lowest standing.
     *
     * @param array<Participant> $orderedParticipants Participants in standings order
     * @param array<string> $previousByeIds
     * @return array<Participant>
     */
    private function orderByeCandidates(array $orderedParticipants, array $previousByeIds): array
    {
        $byeCounts = array_count_values($previousByeIds);

        $candidates = array_reverse($orderedParticipants); // lowest standing first
        $positions = [];
        foreach ($candidates as $index => $candidate) {
            $positions[$candidate->getId()] = $index;
        }

        usort(
            $candidates,
            fn(Participant $first, Participant $second): int => (($byeCounts[$first->getId()] ?? 0) <=> ($byeCounts[$second->getId()] ?? 0))
                ?: ($positions[$first->getId()] <=> $positions[$second->getId()])
        );

        return $candidates;
    }
}
