<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\UnresolvedRoundException;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\BalancedWherePossibleRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use MissionGaming\Tactician\Stage\EngineFingerprint;
use MissionGaming\Tactician\Stage\FingerprintedEngine;
use MissionGaming\Tactician\Stage\HistoryReadingEngine;
use MissionGaming\Tactician\Stage\PairingReadiness;
use MissionGaming\Tactician\Stage\PairKey;
use MissionGaming\Tactician\Stage\RecordedRound;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\RoundRobinPlan;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\EntryOrder;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use Override;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * A round-robin stage (a league) held as a StageState, one round at a time:
 * the state-driven view of the schedule RoundRobinScheduler generates.
 *
 * A round robin does not depend on results: its fixtures are drawn once,
 * from the entrant list. The engine therefore pairs round N as exactly the
 * round N of the schedule that
 *
 *     (new RoundRobinScheduler($constraints, $randomizer))->schedule($state->getEntrants(), $options)
 *
 * returns, where $randomizer is null without a seed and
 * `new Randomizer(new Xoshiro256StarStar($seed))` with one: the same
 * events, with their roles, metadata and round, and the participant who
 * sits the round out in a field of odd size among the byes. The scheduler
 * stays the generator of the whole schedule; this engine adds what a
 * league needs between rounds, which a schedule cannot hold: the results
 * as they come, withdrawals, adjustments and awarded results, a table at
 * any point, the readiness of the next round and the outcome. An
 * application that publishes every fixture at once calls the scheduler; one
 * that holds the league as a state, or as rows of its own read with
 * StageState::fromHistory(), asks this engine for one round at a time, and
 * gets the same fixtures.
 *
 * The configuration is the engine's, not the state's: the options, the
 * constraints and the seed are given to the constructor, as every engine
 * is given its options, and the state holds facts only. The schedule is
 * generated again from the entrant list on every call, so the engine must
 * be built with the same configuration for the whole stage; the
 * fingerprint (getFingerprint()) holds the options and the seed to that,
 * and the constraints are the caller's to keep (they have no name). An
 * engine that draws differently on two calls could not give the same
 * round twice, so a seed is an integer and not a Randomizer, and a
 * ShuffledLegStrategy that holds a randomizer is refused: build it from a
 * seed.
 *
 * The entrant list is StageState::getEntrants(): the participants as the
 * stage was started, and an entrant who withdraws from the live stage
 * keeps its place, so the fixtures are drawn once and never again. An
 * entrant who withdraws before the first round is recorded is out of the
 * draw, and the league is drawn from those who remain.
 *
 * What the engine reads from the state:
 *
 * - Recorded rounds are facts. A round recorded as the engine pairs it is
 *   read as its own; a round recorded otherwise (fixtures swapped by hand)
 *   is read as recorded and reported as manual by getRecordedRounds(), with
 *   the scheduler's round beside it. A round recorded by hand moves no later
 *   fixture: the later rounds are those of the schedule, so a pairing a
 *   hand-drawn round took from a later round is paired again there unless
 *   that round is recorded by hand too.
 * - The table is the standings calculator's, from the recorded results,
 *   the byes and the adjustments in one call
 *   (StandingsCalculator::calculateWith()), with the entrant list deciding
 *   where results do not. A round sat out in a field of odd size is listed
 *   among the byes of the round and counted on the participant's entry
 *   (StandingEntry::getByes()), and earns nothing, whatever the ranking
 *   strategy says a bye is worth: a rest is a gap in a fixture list drawn
 *   once, not a round the format let a participant through without an
 *   opponent (ADR 0017). An awarded result is a result, and an adjustment
 *   is added to the ranking value of its participant. The table orders
 *   nothing the engine pairs.
 * - A withdrawal draws nothing again and decides nothing. The withdrawn
 *   entrant's results stand and count in the table. Its fixtures stay in
 *   the schedule, recorded and later ones alike: the engine still pairs
 *   them, and an event of it that has no result awaits none (it is
 *   uncontested, RecordedRound::getUncontestedEvents()) and counts for
 *   nobody. The library does not award it and does not void the results
 *   already played: the competition's rule is the application's. To award
 *   the event, record an awarded result for it (DTO\Award); to take back
 *   what was played, leave those results out of the history.
 *
 * The engine waits for results, as every engine does: while an event of a
 * recorded round awaits a result, pairNextRound() refuses with an
 * UnresolvedRoundException and getReadiness() says the same without
 * throwing. A league that plays on while a fixture is postponed asks for
 * the next round with pairNextRoundOnIncompleteResults(), and the round is
 * marked as paired that way.
 *
 * Pairwise through 1.x, as the scheduler is: a state that holds an event of
 * other than two participants is refused by every call
 * (`RecordedRoundMismatch`), never read as if it were a pairing.
 *
 * @experimental
 */
final readonly class RoundRobinEngine implements StageEngineInterface, FingerprintedEngine, HistoryReadingEngine
{
    /**
     * How many times each round a participant sits out is credited with the
     * value of a bye: never. A rest is counted on the participant's entry
     * and earns nothing, whatever the ranking strategy says a bye is worth
     * (ADR 0017). It is held here, in one place, so that an option could
     * credit a rest later; none does.
     */
    private const int CREDITS_PER_REST = 0;

    private RoundRobinOptions $options;

    /**
     * @param RoundRobinOptions|null $options Legs, leg strategy, role assignment and
     *                                        backtracking, as RoundRobinScheduler takes them;
     *                                        null for its default (one leg, roles balanced
     *                                        where possible)
     * @param ConstraintSet|null $constraints The constraints the scheduler generates the schedule
     *                                        under; they must answer the same on every call
     * @param int|null $seed Shuffles the entrant list before the first leg is laid out, as a
     *                       Randomizer on the Xoshiro256StarStar engine seeded with it, made
     *                       anew for every call; null for the list order as given
     * @param StandingsCalculator $standingsCalculator Orders the table of the stage
     *
     * @throws InvalidConfigurationException When the leg strategy is a ShuffledLegStrategy that
     *                                       holds a randomizer (reason `IncompatibleOptions`)
     */
    public function __construct(
        ?RoundRobinOptions $options = null,
        private ?ConstraintSet $constraints = null,
        private ?int $seed = null,
        private StandingsCalculator $standingsCalculator = new StandingsCalculator()
    ) {
        $options ??= new RoundRobinOptions();

        if ($options->strategy instanceof ShuffledLegStrategy && $options->strategy->seed === null) {
            throw new InvalidConfigurationException(
                'A round-robin engine pairs one round at a time from a schedule it generates again on every call, so a'
                    . ' shuffled leg strategy that draws from a randomizer would give another schedule each time: build it'
                    . ' from a seed',
                ['strategy' => $options->strategy::class],
                reason: InvalidConfigurationReason::IncompatibleOptions,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }

        $this->options = $options;
    }

    /**
     * What this engine stamps a stage state with, and requires of a state
     * that carries a stamp (see FingerprintedEngine for the contract).
     *
     * With the default options and no seed it names the format and nothing
     * else. Part of it when not at the default, because each changes the
     * fixtures: the legs, the leg strategy (by its identifier in
     * RoundRobinOptions; a strategy of the application's is stated as
     * custom) with the seed of a shuffled one, the role assignment (the
     * same way), backtracking, and the seed of the engine.
     *
     * Not part of it: the constraints, which change the fixtures and have
     * no name (keeping them the same is the caller's to do, as for the Swiss
     * engine), and the standings calculator, from which nothing is paired.
     *
     * The version of the rules is 1: the format is new in 0.3.0 (ADR 0015).
     */
    #[Override]
    public function getFingerprint(): string
    {
        $strategy = $this->options->strategy;
        $roleAssignment = $this->options->roleAssignment;

        return EngineFingerprint::of('round-robin', 1)
            ->with('legs', $this->options->legs, 1)
            ->with('leg-strategy', match ($strategy::class) {
                MirroredLegStrategy::class => 'mirrored',
                RepeatedLegStrategy::class => 'repeated',
                ShuffledLegStrategy::class => 'shuffled',
                default => 'custom',
            }, 'mirrored')
            ->with('leg-strategy-seed', $strategy instanceof ShuffledLegStrategy ? ($strategy->seed ?? '') : '', '')
            ->with('role-assignment', match ($roleAssignment::class) {
                BalancedWherePossibleRoleAssignment::class => 'balanced_where_possible',
                BalancedRoleAssignment::class => 'balanced',
                RoundParityRoleAssignment::class => 'round_parity',
                default => 'custom',
            }, 'balanced_where_possible')
            ->with('backtracking', $this->options->backtracking, false)
            ->with('seed', $this->seed ?? '', '')
            ->toString();
    }

    /**
     * The plan of the stage: the plan RoundRobinScheduler::getPlan() gives
     * for the entrant list and the options.
     *
     * @throws InvalidConfigurationException When the state is stamped with another engine's
     *                                       fingerprint, it names a participant outside its
     *                                       entrant list or holds an event of other than two
     *                                       participants (`RecordedRoundMismatch`), or the
     *                                       scheduler refuses the entrants or the options
     *                                       (fewer than 2 entrants, `TooFewParticipants`)
     */
    #[Override]
    public function getPlan(StageState $state): RoundRobinPlan
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        return (new RoundRobinScheduler($this->constraints))->getPlan($this->field($state), $this->options);
    }

    /**
     * Pair the next round: round N of the schedule the scheduler generates
     * for the entrant list (see the class docblock), with N the state's
     * next round number.
     *
     * The pairing carries the schedule's events of that round, in their
     * order, and the participant who sits the round out among its byes. It
     * holds the fixtures of an entrant who has withdrawn, which await no
     * result. The call records nothing: pass the pairing to
     * StageState::withRoundPaired().
     *
     * @throws UnresolvedRoundException When an event of a recorded round awaits a result (reason
     *                                  `RoundPartiallyResolved`)
     * @throws InvalidConfigurationException When the rounds of the schedule have all been
     *                                       recorded (reason `PlannedRoundsPlayed`), and for every
     *                                       case getPlan() names
     * @throws IncompleteScheduleException When the constraints leave the scheduler no schedule
     */
    #[Override]
    public function pairNextRound(StageState $state): RoundPairing
    {
        $state->requireEngineFingerprint($this->getFingerprint());
        $field = $this->field($state);

        $unresolved = $this->unresolvedEvents($state);
        if ($unresolved !== []) {
            throw new UnresolvedRoundException(
                $unresolved[0]->getRound()?->getNumber() ?? 0,
                $unresolved,
                'Record the remaining results before pairing the next round, or pair it now with'
                    . ' pairNextRoundOnIncompleteResults() if that is what is meant.'
            );
        }

        return $this->pair($state, $field, false);
    }

    /**
     * Pair the next round now, whether or not the recorded rounds have all
     * their results: the explicit way to go on while a fixture is postponed.
     *
     * The round is the one pairNextRound() gives, since no result changes a
     * fixture. When an event did await a result the pairing is marked
     * (RoundPairing::isPairedOnIncompleteResults()), and the mark is
     * recorded and stored with the round. The event still awaits its result
     * afterwards: the stage is not complete until it has one, and once the
     * round paired here is recorded, the result is supplied with the history
     * (StageState::fromHistory()), since StageState::withAdditionalResults()
     * reaches the last round only.
     *
     * @throws InvalidConfigurationException For every case pairNextRound() names but the
     *                                       unfinished round
     * @throws IncompleteScheduleException When the constraints leave the scheduler no schedule
     */
    public function pairNextRoundOnIncompleteResults(StageState $state): RoundPairing
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        return $this->pair($state, $this->field($state), $this->unresolvedEvents($state) !== []);
    }

    /**
     * Whether the stage has no further round: every round of the schedule
     * is recorded, and no recorded event awaits a result. A withdrawal does
     * not shorten the stage.
     *
     * @throws InvalidConfigurationException For every case getPlan() names
     */
    #[Override]
    public function isComplete(StageState $state): bool
    {
        return $state->getNextRoundNumber() > $this->getPlan($state)->getTotalRounds()
            && $this->unresolvedEvents($state) === [];
    }

    /**
     * The outcome of the stage once it is complete: the table
     * (getStandings()), the results, the byes, the last round and the
     * entrant list; null before.
     *
     * @throws InvalidConfigurationException For every case getPlan() names
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
            $this->field($state),
            $this->standingsCalculator
        );
    }

    /**
     * Whether the next round can be paired now and, when it cannot, the
     * events that await a result (see HistoryReadingEngine). An event of an
     * entrant who has withdrawn awaits none.
     *
     * Ready means that no recorded event awaits a result and a round of the
     * schedule is left. The schedule is not generated here, so where
     * constraints leave the scheduler no schedule, pairNextRound() still
     * refuses (IncompleteScheduleException).
     *
     * @throws InvalidConfigurationException For every case getPlan() names
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

        return PairingReadiness::ready($state->getNextRoundNumber());
    }

    /**
     * Every recorded round as this engine reads it (see RecordedRound).
     *
     * The derivation of a round is the round of the same number in the
     * schedule: a round robin's fixtures depend on the entrant list alone,
     * so it is what the engine paired, or would have paired, whatever was
     * recorded before it. A round is manual when some two participants meet
     * in it and not in the derivation, or the other way round. Roles, the
     * order of the events, the byes, labels and metadata are not compared
     * (who sits a round out follows from who plays), and neither is an event
     * that names an entrant who had withdrawn before the round was paired:
     * a round recorded without the fixtures of a withdrawn entrant is not
     * manual for that.
     *
     * A recorded round with a number beyond the last round of the schedule
     * has no derivation, and neither has any round where the constraints
     * leave the scheduler no schedule.
     *
     * The events of a round that have no result and name an entrant who has
     * withdrawn are its uncontested events: they await nothing.
     *
     * @return list<RecordedRound>
     *
     * @throws InvalidConfigurationException When the state is stamped with another engine's
     *                                       fingerprint, or names a participant outside its entrant
     *                                       list or holds an event of other than two participants
     *                                       (`RecordedRoundMismatch`), and when the scheduler refuses
     *                                       the entrants or the options (fewer than 2 entrants,
     *                                       `TooFewParticipants`), as every call of the engine does
     */
    #[Override]
    public function getRecordedRounds(StageState $state): array
    {
        $state->requireEngineFingerprint($this->getFingerprint());
        $field = $this->field($state);

        try {
            $rounds = $this->roundsOf($this->schedule($field));
        } catch (IncompleteScheduleException) {
            $rounds = null;
        }

        $active = $this->activeIds($state);
        $unresolvedByRound = [];
        foreach ($this->unresolvedEvents($state) as $event) {
            $unresolvedByRound[$event->getRound()?->getNumber() ?? 0][] = $event;
        }
        $uncontestedByRound = [];
        foreach ($state->getEventsWithoutResult() as $event) {
            if (!$this->contested($event, $active)) {
                $uncontestedByRound[$event->getRound()?->getNumber() ?? 0][] = $event;
            }
        }

        $read = [];
        foreach ($state->getRoundsPlayed() as $pairing) {
            $number = $pairing->getRoundNumber();
            $derivation = $rounds === null ? null : $this->roundOf($rounds, $number, false);

            $read[] = new RecordedRound(
                $pairing,
                $derivation,
                $derivation instanceof RoundPairing && $this->meetings($pairing, $state, $number) !== $this->meetings($derivation, $state, $number),
                $unresolvedByRound[$number] ?? [],
                $uncontestedByRound[$number] ?? []
            );
        }

        return $read;
    }

    /**
     * The table of the stage as recorded so far, whether or not the stage
     * is complete: the table getOutcome() carries once it is.
     *
     * It is the standings calculator's table of the recorded results, the
     * recorded byes and the adjustments
     * (StandingsCalculator::calculateWith()), every entrant in it, the
     * withdrawn included, and entries that results do not separate in the
     * order of the entrant list. A participant given an adjustment who then
     * withdrew before the draw is in no round and still has its adjustment:
     * it is in the table too, after the entrants where results are level.
     * An event without a result counts for nobody. A round sat out is
     * counted on the entry (StandingEntry::getByes()) and earns nothing,
     * under any ranking strategy (see the class docblock), so a strategy
     * that does not say what a bye is worth is not refused a league with a
     * rest.
     *
     * @throws InvalidConfigurationException When the state is stamped with another engine's
     *                                       fingerprint, names a participant outside its entrant
     *                                       list or holds an event of other than two participants
     *                                       (`RecordedRoundMismatch`)
     */
    #[Override]
    public function getStandings(StageState $state): Standings
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        return $this->table($state);
    }

    /**
     * @throws InvalidConfigurationException When a round or a result names a participant outside
     *                                       the entrant list, or an event has other than two
     *                                       participants
     */
    private function table(StageState $state): Standings
    {
        $listed = $this->tableList($state);

        return $this->standingsCalculator
            ->calculateWith($listed, $state->getResults(), $state->getByeCounts(), $state->getAdjustments(), self::CREDITS_PER_REST)
            ->withTiedSetsOrderedBy($listed);
    }

    /**
     * The participants of the table, in the order that decides where
     * results do not: the entrant list, then every other participant the
     * state has seen, in the order of their IDs (the total order the
     * standings calculator falls back on). The only such participant is
     * one given an adjustment who withdrew before the draw: it is in no
     * round, and its adjustment is in the table, as in every engine's.
     *
     * @return list<Participant>
     *
     * @throws InvalidConfigurationException For every case field() names
     */
    private function tableList(StageState $state): array
    {
        $field = $this->field($state);
        $listed = [];
        foreach ($field as $entrant) {
            $listed[$entrant->getId()] = true;
        }

        $others = array_values(array_filter(
            $state->getAllSeenParticipants(),
            static fn(Participant $participant): bool => !isset($listed[$participant->getId()])
        ));
        usort(
            $others,
            static fn(Participant $first, Participant $second): int => EntryOrder::compareIds($first->getId(), $second->getId())
        );

        return [...$field, ...$others];
    }

    /**
     * Pair round N of the schedule, N being the state's next round number.
     *
     * @param list<Participant> $field
     *
     * @throws InvalidConfigurationException When the rounds of the schedule have all been
     *                                       recorded, or the scheduler refuses the entrants or the
     *                                       options
     * @throws IncompleteScheduleException When the constraints leave the scheduler no schedule
     */
    private function pair(StageState $state, array $field, bool $onIncompleteResults): RoundPairing
    {
        $rounds = $this->roundsOf($this->schedule($field));
        $next = $state->getNextRoundNumber();

        $pairing = $this->roundOf($rounds, $next, $onIncompleteResults);
        if (!$pairing instanceof RoundPairing) {
            throw new InvalidConfigurationException(
                'Every round of the round robin has been recorded; no further round exists',
                ['planned_rounds' => $rounds['total'], 'next_round' => $next],
                reason: InvalidConfigurationReason::PlannedRoundsPlayed
            );
        }

        return $pairing;
    }

    /**
     * The rounds of a schedule, grouped once: the events and the byes by
     * round number, and the number of rounds.
     *
     * @return array{events: array<int, array<Event>>, byes: array<int, list<Participant>>, total: int}
     */
    private function roundsOf(Schedule $schedule): array
    {
        $total = $schedule->getMetadataValue('total_rounds');

        return [
            'events' => $schedule->getEventsByRound(),
            'byes' => $schedule->getByesByRound(),
            'total' => is_int($total) ? $total : 0,
        ];
    }

    /**
     * The round of the given number in the schedule, as a pairing; null when
     * the schedule has no such round.
     *
     * @param array{events: array<int, array<Event>>, byes: array<int, list<Participant>>, total: int} $rounds
     */
    private function roundOf(array $rounds, int $number, bool $onIncompleteResults): ?RoundPairing
    {
        if ($number < 1 || $number > $rounds['total']) {
            return null;
        }

        return new RoundPairing(
            $number,
            null,
            $rounds['events'][$number] ?? [],
            $rounds['byes'][$number] ?? [],
            $onIncompleteResults
        );
    }

    /**
     * The schedule of the stage, generated again.
     *
     * @param list<Participant> $field
     *
     * @throws InvalidConfigurationException When the scheduler refuses the entrants or the options
     * @throws IncompleteScheduleException When the constraints leave the scheduler no schedule
     */
    private function schedule(array $field): Schedule
    {
        $randomizer = $this->seed === null ? null : new Randomizer(new Xoshiro256StarStar($this->seed));

        return (new RoundRobinScheduler($this->constraints, $randomizer))->schedule($field, $this->options);
    }

    /**
     * The entrant list the league is drawn from: StageState::getEntrants().
     *
     * A state whose rounds or results name a participant outside that list
     * cannot say where the participant was drawn, and the schedule could
     * not be drawn again as it was: a round recorded with a participant who
     * is no entrant, or data stored before the entrant list was kept (0.2.x)
     * in which a participant has withdrawn. It is refused, and so is a state
     * with a recorded event that does not have two participants. A
     * participant named by an adjustment alone, who withdrew before the
     * draw, was drawn nowhere and is not refused (see tableList()).
     *
     * @return list<Participant>
     *
     * @throws InvalidConfigurationException When a round or a result names a participant that is not
     *                                       in the list, or the state holds an event of other than two
     *                                       participants
     */
    private function field(StageState $state): array
    {
        $field = $state->getEntrants();
        $listed = [];
        foreach ($field as $entrant) {
            $listed[$entrant->getId()] = true;
        }

        $named = [];
        foreach ($state->getRoundsPlayed() as $pairing) {
            foreach ($pairing->getEvents() as $event) {
                $named[] = $event->getParticipants();
            }
            $named[] = $pairing->getByes();
        }
        foreach ($state->getResults() as $result) {
            $named[] = $result->getEvent()->getParticipants();
        }
        foreach (array_merge(...$named) as $participant) {
            if (!isset($listed[$participant->getId()])) {
                throw new InvalidConfigurationException(
                    "The stage names participant {$participant->getId()}, who is not in its entrant list: the league cannot be"
                        . ' drawn again from the list it was drawn from. Build the state with every entrant, the withdrawn'
                        . ' included (StageState::fromHistory())',
                    ['participant' => $participant->getId()],
                    reason: InvalidConfigurationReason::RecordedRoundMismatch
                );
            }
        }

        // A round robin pairs: an event of another size is refused, never
        // read as if it were a pairing (ADR 0003)
        foreach ($state->getPlayedEvents() as $event) {
            if (count($event->getParticipants()) !== 2) {
                throw new InvalidConfigurationException(
                    'A round-robin stage holds events of two participants; round ' . ($event->getRound()?->getNumber() ?? 0)
                        . ' holds an event of ' . count($event->getParticipants()),
                    [
                        'round' => $event->getRound()?->getNumber(),
                        'participants' => array_map(
                            static fn(Participant $participant): string => $participant->getId(),
                            array_values($event->getParticipants())
                        ),
                    ],
                    reason: InvalidConfigurationReason::RecordedRoundMismatch
                );
            }
        }

        return $field;
    }

    /**
     * The recorded events that await a result, in play order: those without
     * one, but for an event that names an entrant who has withdrawn.
     *
     * @return list<Event>
     */
    private function unresolvedEvents(StageState $state): array
    {
        $active = $this->activeIds($state);

        return array_values(array_filter(
            $state->getEventsWithoutResult(),
            fn(Event $event): bool => $this->contested($event, $active)
        ));
    }

    /**
     * @return array<string, true>
     */
    private function activeIds(StageState $state): array
    {
        $active = [];
        foreach ($state->getParticipants() as $participant) {
            $active[$participant->getId()] = true;
        }

        return $active;
    }

    /**
     * Whether every participant of an event is still in the stage.
     *
     * @param array<string, true> $active
     */
    private function contested(Event $event, array $active): bool
    {
        foreach ($event->getParticipants() as $participant) {
            if (!isset($active[$participant->getId()])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Who meets whom in a round, sorted: the meetings a round is compared by
     * to call it manual. An event that names an entrant who had withdrawn
     * before the round was paired is left out.
     *
     * @return list<string>
     */
    private function meetings(RoundPairing $pairing, StageState $state, int $number): array
    {
        $goneBefore = [];
        foreach ($state->getWithdrawals() as $withdrawal) {
            if ($withdrawal->getAfterRound() < $number) {
                $goneBefore[$withdrawal->getParticipant()->getId()] = true;
            }
        }

        $meetings = [];
        foreach ($pairing->getEvents() as $event) {
            $ids = [];
            foreach ($event->getParticipants() as $participant) {
                if (isset($goneBefore[$participant->getId()])) {
                    continue 2;
                }
                $ids[] = $participant->getId();
            }
            $meetings[] = PairKey::of(...$ids);
        }
        sort($meetings, SORT_STRING);

        return $meetings;
    }
}
