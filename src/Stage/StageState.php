<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\JsonConversionException;
use MissionGaming\Tactician\Serialization\PlainData;
use MissionGaming\Tactician\Standings\Adjustment;

/**
 * The full state of a results-driven stage between rounds.
 *
 * This value absorbs the bookkeeping engines used to push onto callers —
 * bye threading, round numbers, played pairings — and gives withdrawals a
 * first-class verb. It records the *pairings* played (not just their
 * results), so repeat avoidance works even when no results are recorded,
 * and it serializes (toArray()/fromArray()/JSON) so platforms persist
 * state between rounds instead of re-deriving it. An application that
 * keeps the rounds and results in rows of its own stores no state at all
 * and builds one from them on each request with fromHistory(); either way
 * an engine reads the same thing.
 *
 * The driver loop every platform writes:
 *
 *     $state = StageState::start($participants);
 *     while (!$engine->isComplete($state)) {
 *         $pairing = $engine->pairNextRound($state);
 *         $state = $state->withRoundPaired($pairing);  // recorded when it is drawn
 *         $results = playRound($pairing);              // application-side
 *         $state = $state->withAdditionalResults($results);
 *     }
 *     $outcome = $engine->getOutcome($state);
 *
 * A round is recorded when it is paired, so that a withdrawal or an
 * adjustment made while it is played is recorded after it (see
 * withRoundPaired()). withRoundPlayed() records a round with its results
 * in one call, where nothing can happen in between.
 *
 * @experimental
 */
final readonly class StageState
{
    /**
     * @param array<Participant> $participants Active participants
     * @param array<RoundPairing> $roundsPlayed Pairings recorded so far, in play order
     * @param array<Result> $results Results of every recorded round
     * @param string|null $engineFingerprint The stamp of the engine that pairs this stage, when one was set
     * @param list<Adjustment> $adjustments Adjustments to ranking values, in the order recorded
     * @param array<string, Participant> $adjustedParticipants The participant each adjustment is
     *                                                         for, by ID, so that one who has
     *                                                         withdrawn since is still known
     * @param list<Participant> $entrants The active participants and, each in the place it had,
     *                                    those who withdrew: from the live stage, or before the
     *                                    first round was recorded (out of the draw until that
     *                                    round names them)
     * @param list<Withdrawal> $withdrawals The withdrawal of every entrant in that list who is not
     *                                      active, with its round, in the order they happened
     */
    private function __construct(
        private array $participants,
        private array $roundsPlayed = [],
        private array $results = [],
        private ?string $engineFingerprint = null,
        private array $adjustments = [],
        private array $adjustedParticipants = [],
        private array $entrants = [],
        private array $withdrawals = []
    ) {}

    /**
     * Begin a stage with the given active participants.
     *
     * Order is authoritative: engines seed and pair from list position.
     * The number of participants is not checked here; an engine refuses
     * a state with fewer than 2 when it is asked to pair it.
     *
     * @param array<Participant> $participants
     * @throws InvalidConfigurationException When participant IDs collide (reason
     *                                       `DuplicateParticipantIds`)
     */
    public static function start(array $participants): self
    {
        $participants = array_values($participants);

        $ids = array_map(fn(Participant $participant) => $participant->getId(), $participants);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidConfigurationException(
                'All participants must have unique IDs',
                ['participant_count' => count($participants), 'unique_ids' => count(array_unique($ids))],
                reason: InvalidConfigurationReason::DuplicateParticipantIds
            );
        }

        return new self($participants, entrants: $participants);
    }

    /**
     * Build the state of a stage from a history the application holds: the
     * entrants, the rounds that were paired and whatever results exist.
     *
     * This is the entry point for an application that keeps its fixtures
     * and results in its own storage and stores no state: on every request
     * it maps its rows to these arguments, and the engine reads the state
     * as it reads one recorded verb by verb. The state it returns is equal
     * to the one built by start(), then withRoundPlayed() for each round in
     * order with its results in the order of its events, with
     * withoutParticipant() for each withdrawal and withAdjustment() for
     * each adjustment at the moment it says it happened.
     *
     * What is supplied is a fact and is kept as supplied: a round is never
     * paired again and never compared with what an engine would have
     * paired here. An engine reads the recorded rounds and derives only
     * the round that does not exist yet; a round it would have paired
     * otherwise is reported by HistoryReadingEngine::getRecordedRounds()
     * as manual, and is no error. A round with some or none of its
     * results is normal.
     *
     * The order the rounds and the results are supplied in does not
     * matter: the rounds are put in the order of their numbers, and the
     * results in the order of their rounds and, inside a round, of the
     * events they belong to (a result is matched to its event by round
     * number, participants in either order and tie leg, not by object).
     * The order of the events inside a round is part of the round and is
     * kept; no engine reads a recorded round by it.
     *
     * Three rules are checked that the verbs do not check, because rows
     * can state what no engine paired:
     *
     * - the round numbers are 1, 2, 3 and so on, with none left out or
     *   given twice (`RoundOutOfSequence`);
     * - every participant of an event, a bye, a result, a withdrawal or an
     *   adjustment is one of the entrants, by ID
     *   (`ParticipantNotAnEntrant`);
     * - nobody is in a round twice: in two events against different
     *   opponents, in an event and among the byes, or in one event listed
     *   twice (`ParticipantTwiceInRound`). The two legs of a tie, marked 1
     *   and 2 under `tie_leg` in the metadata of their events, are one
     *   appearance.
     *
     * An event with two results is refused as well (`DuplicateResult`).
     * Everything withRoundPlayed() checks is checked by it here too, with
     * the reason it gives.
     *
     * The entrants are the list the first round was paired from, in that
     * order: an entrant who has withdrawn since stays in it and is named
     * among the withdrawals, so that it keeps its place (see
     * withoutParticipant()). An entrant who withdrew before the draw may be
     * left out of the list, or listed with a Withdrawal after round 0 that
     * the first round does not name: either way it is no entrant.
     *
     * When a withdrawal or an adjustment happened is part of the history,
     * and the verbs record it by themselves (the number of the last round
     * recorded at that moment). Here the application says it: a withdrawal
     * as a Withdrawal with its round, an adjustment with
     * Adjustment::madeAfterRound(). The withdrawals are put in the order of
     * their rounds, and those after the same round stay in the order
     * given. A round stated here is 0 or one of the supplied rounds
     * (`ValueOutOfRange` otherwise).
     *
     * Once the history holds a round, every withdrawal states its round: a
     * plain participant among the withdrawals is refused
     * (`WithdrawalWithoutRound`). Which of two entrants of a bracket tie
     * went on, or whether the tie is void, depends on when each left, and
     * rows that do not say it were read otherwise than the stage was played
     * as soon as two withdrawals met in one tie. With no round supplied a
     * plain participant is a withdrawal after round 0, which is the only
     * time it can be.
     *
     * The same holds for an adjustment: once the history holds a round,
     * every adjustment states the round it was made after
     * (Adjustment::madeAfterRound()), and one that does not is refused
     * (`AdjustmentWithoutRound`). A round is ranked and shown from the
     * adjustments made before it was paired, and that is not known of an
     * adjustment without its round. With no round supplied an adjustment
     * without one is recorded as made after round 0.
     *
     * The state carries no engine fingerprint; stamp it with
     * withEngineFingerprint() where the application keeps one.
     *
     * @param array<Participant> $entrants The list the stage was drawn from, in its order
     * @param array<RoundPairing> $rounds The rounds that were paired, in any order
     * @param array<Result> $results The results recorded so far, of any of the rounds, in any order
     * @param array<Participant|Withdrawal> $withdrawals The entrants who have withdrawn, each as a
     *                                                   Withdrawal with the round it withdrew
     *                                                   after; a plain participant only where no
     *                                                   round is supplied
     * @param array<Adjustment> $adjustments The adjustments made, in the order they are to be kept
     *                                       in, each with the round it was made after
     *                                       (Adjustment::madeAfterRound()); one without it only
     *                                       where no round is supplied
     *
     * @throws InvalidConfigurationException When two entrants share an ID
     *                                       (`DuplicateParticipantIds`), one of the three rules
     *                                       above is broken, an event has two results
     *                                       (`DuplicateResult`), the history holds rounds and a
     *                                       withdrawal that does not say its round
     *                                       (`WithdrawalWithoutRound`, with the participant and
     *                                       the last round in the context) or an adjustment that
     *                                       does not (`AdjustmentWithoutRound`, the same context),
     *                                       a withdrawal or an adjustment
     *                                       states a round after the last one supplied
     *                                       (`ValueOutOfRange`), or the history is one
     *                                       withRoundPlayed() refuses (`ValueOutOfRange`,
     *                                       `EventNotInRound`, `EventWithoutRoundNumber`). The
     *                                       context names the round and the participants
     * @throws InvalidInputException When an adjustment names an entrant who withdrew before any
     *                               round was supplied, and is therefore no longer in the stage
     */
    public static function fromHistory(
        array $entrants,
        array $rounds,
        array $results = [],
        array $withdrawals = [],
        array $adjustments = []
    ): self {
        $state = self::start($entrants);

        $entrantIds = [];
        foreach ($state->entrants as $entrant) {
            $entrantIds[$entrant->getId()] = true;
        }
        $requireEntrant = static function (Participant $participant, string $role, ?int $round) use ($entrantIds): void {
            if (!isset($entrantIds[$participant->getId()])) {
                throw new InvalidConfigurationException(
                    "The history names participant {$participant->getId()} ({$role}), who is not one of the entrants",
                    ['participant' => $participant->getId(), 'role' => $role, 'round' => $round],
                    reason: InvalidConfigurationReason::ParticipantNotAnEntrant
                );
            }
        };

        $rounds = array_values($rounds);
        usort(
            $rounds,
            static fn(RoundPairing $first, RoundPairing $second): int => $first->getRoundNumber() <=> $second->getRoundNumber()
        );

        $positions = [];
        foreach ($rounds as $index => $pairing) {
            $number = $pairing->getRoundNumber();
            // A number below 1 is left to withRoundPlayed(), which has a
            // reason of its own for it.
            if ($number >= 1 && $number !== $index + 1) {
                throw new InvalidConfigurationException(
                    'The rounds of a history are numbered 1, 2, 3 and so on: round ' . ($index + 1)
                        . " is missing or round {$number} is given twice",
                    [
                        'expected_round' => $index + 1,
                        'round' => $number,
                        'round_numbers' => array_map(
                            static fn(RoundPairing $round): int => $round->getRoundNumber(),
                            $rounds
                        ),
                    ],
                    reason: InvalidConfigurationReason::RoundOutOfSequence
                );
            }

            $positions += $state->positionsOfSoundRound($pairing, $requireEntrant);
        }

        // The results in the order the verbs would have recorded them:
        // round by round, and in a round by the event they belong to. A
        // result of no recorded event goes last, where the replay finds it
        // and refuses it with the verb's reason.
        $ordered = [];
        $seen = [];
        foreach (array_values($results) as $index => $result) {
            $event = $result->getEvent();
            foreach ($event->getParticipants() as $participant) {
                $requireEntrant($participant, 'in a result', $event->getRound()?->getNumber());
            }

            $key = $state->eventKey($event);
            if (isset($seen[$key])) {
                throw new InvalidConfigurationException(
                    'The history holds two results for one event',
                    [
                        'round' => $event->getRound()?->getNumber(),
                        'participants' => array_map(
                            static fn(Participant $participant): string => $participant->getId(),
                            array_values($event->getParticipants())
                        ),
                    ],
                    reason: InvalidConfigurationReason::DuplicateResult
                );
            }
            $seen[$key] = true;

            $ordered[] = [$positions[$key] ?? [PHP_INT_MAX, PHP_INT_MAX], $index, $result];
        }
        usort($ordered, static fn(array $first, array $second): int => [$first[0], $first[1]] <=> [$second[0], $second[1]]);

        $state = self::recorded(
            $state->participants,
            $rounds,
            array_map(static fn(array $row): Result => $row[2], $ordered),
            null,
            [],
            $state->entrants
        );

        $lastRound = $state->getLastRound()?->getRoundNumber() ?? 0;
        $requireRecordedRound = static function (int $afterRound, string $what, string $id) use ($lastRound): void {
            if ($afterRound > $lastRound) {
                throw new InvalidConfigurationException(
                    "The history says that {$what} participant {$id} happened after round {$afterRound}, and its last"
                        . " round is round {$lastRound}",
                    ['participant' => $id, 'after_round' => $afterRound, 'last_round' => $lastRound],
                    reason: InvalidConfigurationReason::ValueOutOfRange
                );
            }
        };

        // The withdrawals in the order they happened: by round, and those
        // of one round in the order given. Once there are rounds, each says
        // which it happened after; before them it can only be before the
        // first.
        $timed = [];
        foreach (array_values($withdrawals) as $index => $withdrawal) {
            $withdrawn = $withdrawal instanceof Withdrawal ? $withdrawal->getParticipant() : $withdrawal;
            $requireEntrant($withdrawn, 'as withdrawn', null);

            if (!$withdrawal instanceof Withdrawal) {
                if ($lastRound > 0) {
                    throw new InvalidConfigurationException(
                        "The history holds rounds and the withdrawal of participant {$withdrawn->getId()}, which does not say"
                            . ' after which round it happened. Give it as a Withdrawal with the number of the last round'
                            . ' recorded when the participant withdrew (0 before the first): a tie both of whose entrants'
                            . ' withdrew is read from when they did.',
                        ['participant' => $withdrawn->getId(), 'last_round' => $lastRound],
                        reason: InvalidConfigurationReason::WithdrawalWithoutRound
                    );
                }

                $withdrawal = new Withdrawal($withdrawn, 0);
            }

            $requireRecordedRound($withdrawal->getAfterRound(), 'the withdrawal of', $withdrawn->getId());
            $timed[] = [$withdrawal->getAfterRound(), $index, $withdrawal];
        }
        usort($timed, static fn(array $first, array $second): int => [$first[0], $first[1]] <=> [$second[0], $second[1]]);
        foreach ($timed as [, , $withdrawal]) {
            $state = $state->withdrawn($withdrawal->getParticipant(), $withdrawal->getAfterRound());
        }

        foreach ($adjustments as $adjustment) {
            $id = $adjustment->getParticipantId();
            if (!isset($entrantIds[$id])) {
                throw new InvalidConfigurationException(
                    "The history names participant {$id} (in an adjustment), who is not one of the entrants",
                    ['participant' => $id, 'role' => 'in an adjustment', 'round' => null],
                    reason: InvalidConfigurationReason::ParticipantNotAnEntrant
                );
            }
            // Once there are rounds, an adjustment says which it was made
            // after; before them it can only be before the first
            $afterRound = $adjustment->getAfterRound();
            if ($afterRound === null) {
                if ($lastRound > 0) {
                    throw new InvalidConfigurationException(
                        "The history holds rounds and an adjustment for participant {$id} that does not say after which"
                            . ' round it was made. Give it with Adjustment::madeAfterRound() and the number of the last round'
                            . ' recorded when it was made (0 before the first): a round is read from the adjustments made'
                            . ' before it was paired.',
                        ['participant' => $id, 'last_round' => $lastRound],
                        reason: InvalidConfigurationReason::AdjustmentWithoutRound
                    );
                }

                $adjustment = $adjustment->madeAfterRound(0);
                $afterRound = 0;
            }
            $requireRecordedRound($afterRound, 'the adjustment for', $id);

            $participant = null;
            foreach ($state->getAllSeenParticipants() as $seen) {
                if ($seen->getId() === $id) {
                    $participant = $seen;
                    break;
                }
            }
            if ($participant === null) {
                throw new InvalidInputException("Adjustment references participant {$id} who is not in the stage");
            }
            $state = $state->adjustedBy($adjustment, $participant);
        }

        return $state;
    }

    /**
     * Check one round of a supplied history against the rules that concern
     * it alone, and say where each of its events stands.
     *
     * @param \Closure(Participant, string, int|null): void $requireEntrant
     *
     * @return array<string, array{int, int}> The round number and the place in the round of
     *                                        every event, by event key
     *
     * @throws InvalidConfigurationException When a participant is no entrant or is in the round twice
     */
    private function positionsOfSoundRound(RoundPairing $pairing, \Closure $requireEntrant): array
    {
        $number = $pairing->getRoundNumber();
        $positions = [];
        $tieOf = [];

        foreach (array_values($pairing->getEvents()) as $place => $event) {
            $ids = array_map(
                static fn(Participant $participant): string => $participant->getId(),
                array_values($event->getParticipants())
            );
            $tie = PairKey::of(...$ids);
            $key = $this->eventKey($event);

            foreach ($event->getParticipants() as $participant) {
                $requireEntrant($participant, 'in an event', $number);

                // The same opponents again are the other leg of the tie,
                // unless it is the same leg again
                if ((isset($tieOf[$participant->getId()]) && $tieOf[$participant->getId()] !== $tie) || isset($positions[$key])) {
                    throw self::twiceInRound($participant, $number);
                }
            }
            foreach ($ids as $id) {
                $tieOf[$id] = $tie;
            }

            $positions[$key] = [$number, $place];
        }

        foreach ($pairing->getByes() as $bye) {
            $requireEntrant($bye, 'with a bye', $number);
            if (isset($tieOf[$bye->getId()])) {
                throw self::twiceInRound($bye, $number);
            }
            $tieOf[$bye->getId()] = '';
        }

        return $positions;
    }

    private static function twiceInRound(Participant $participant, int $round): InvalidConfigurationException
    {
        return new InvalidConfigurationException(
            "Participant {$participant->getId()} is in round {$round} twice",
            ['round' => $round, 'participant' => $participant->getId()],
            reason: InvalidConfigurationReason::ParticipantTwiceInRound
        );
    }

    /**
     * Record a round that has been paired and not played yet: its pairing,
     * including any byes it awarded, with none of its results.
     *
     * This is how a round is recorded where things can happen while it is
     * played. The state's clock is the rounds it holds: a withdrawal or an
     * adjustment is recorded with the number of the last round recorded at
     * that moment (Withdrawal, Adjustment::getAfterRound()), and an engine
     * reads a recorded round from what was recorded before it. Recorded
     * when it is paired, the round comes before everything that happens
     * while it is played, as it did. The results are added as they come
     * with withAdditionalResults(), which reaches the last recorded round:
     *
     *     $pairing = $engine->pairNextRound($state);
     *     $state = $state->withRoundPaired($pairing);    // the draw is published
     *     // ...the round is played; withdrawals and adjustments as they happen...
     *     $state = $state->withAdditionalResults($results);
     *
     * It is withRoundPlayed() with no results, and is checked the same way.
     * The state it returns is the state that verb returns, stores the same
     * (a round with no results is a round like any other) and is read the
     * same: an engine waits for the results of the round before it pairs a
     * further one (see withRoundPlayed()). The verb is pairwise-neutral: it
     * records the pairing as given, whatever the size of its events.
     *
     * @throws InvalidConfigurationException When withRoundPlayed() refuses the pairing (a round
     *                                       number below 1, `ValueOutOfRange`; out of sequence,
     *                                       `RoundOutOfSequence`; an event of another round,
     *                                       `EventNotInRound` or `EventWithoutRoundNumber`)
     */
    public function withRoundPaired(RoundPairing $pairing): self
    {
        return $this->withRoundPlayed($pairing, []);
    }

    /**
     * Record a round: its pairing (including any byes it awarded) and the
     * results of its events that exist.
     *
     * Recording some of the results, or none, is legal. A round recorded
     * once it has been played comes, to the state, after everything that
     * happened while it was played: record the round when it is paired
     * (withRoundPaired()) and add its results as they come
     * (withAdditionalResults()) where withdrawals or adjustments can be
     * made while a round is played. The pairing's events count as paired
     * from then on, and an engine waits for the missing results before it
     * pairs a further round, unless it is told that the stage has none
     * (SwissPairingEngine in results-free mode, which whole-schedule
     * generation relies on). Rounds are recorded in play order: a round number is 1 or
     * above and must exceed the last recorded one, and every event and result
     * in the pairing must carry that round number, so the history can
     * never contradict itself.
     *
     * What is not checked: that the round number is the next one (a
     * higher one is accepted, and the gap stays), that the participants
     * of the pairing are active, and that an event has only one result.
     * The results are appended to getResults() in the order given.
     *
     * @param array<Result> $results
     * @throws InvalidConfigurationException When the pairing's round number is below 1
     *                                       (`ValueOutOfRange`), the pairing does not follow the
     *                                       recorded rounds (`RoundOutOfSequence`), or an event or result
     *                                       belongs to a different round or is not in the pairing
     *                                       (`EventNotInRound`; `EventWithoutRoundNumber` when the
     *                                       event carries no round number at all)
     */
    public function withRoundPlayed(RoundPairing $pairing, array $results): self
    {
        if ($pairing->getRoundNumber() < 1) {
            throw new InvalidConfigurationException(
                'Round numbers are 1-based: a round cannot be recorded with a number below 1',
                ['pairing_round' => $pairing->getRoundNumber()],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }

        $lastRound = $this->getLastRound();
        if ($lastRound !== null && $pairing->getRoundNumber() <= $lastRound->getRoundNumber()) {
            throw new InvalidConfigurationException(
                'Rounds must be recorded in play order with increasing round numbers',
                ['last_round' => $lastRound->getRoundNumber(), 'pairing_round' => $pairing->getRoundNumber()],
                reason: InvalidConfigurationReason::RoundOutOfSequence
            );
        }

        foreach ($pairing->getEvents() as $event) {
            $eventRound = $event->getRound()?->getNumber();
            if ($eventRound !== $pairing->getRoundNumber()) {
                throw new InvalidConfigurationException(
                    'Pairing contains an event from a different round',
                    ['pairing_round' => $pairing->getRoundNumber(), 'event_round' => $eventRound],
                    reason: $eventRound === null
                        ? InvalidConfigurationReason::EventWithoutRoundNumber
                        : InvalidConfigurationReason::EventNotInRound
                );
            }
        }

        $this->assertResultsBelongTo($pairing, $results);

        // The first round says who was in the draw: of those who withdrew
        // before it was recorded, the ones it names keep their place, and
        // the others withdrew before the draw and are no entrants
        $entrants = $this->entrants;
        $withdrawals = $this->withdrawals;
        if ($this->roundsPlayed === [] && $withdrawals !== []) {
            $outOfTheDraw = [];
            foreach ($withdrawals as $withdrawal) {
                $id = $withdrawal->getParticipant()->getId();
                if (!self::names($pairing, $id)) {
                    $outOfTheDraw[$id] = true;
                }
            }
            $entrants = array_values(array_filter(
                $entrants,
                static fn(Participant $entrant): bool => !isset($outOfTheDraw[$entrant->getId()])
            ));
            $withdrawals = array_values(array_filter(
                $withdrawals,
                static fn(Withdrawal $withdrawal): bool => !isset($outOfTheDraw[$withdrawal->getParticipant()->getId()])
            ));
        }

        return new self(
            $this->participants,
            [...$this->roundsPlayed, $pairing],
            [...$this->results, ...array_values($results)],
            $this->engineFingerprint,
            $this->adjustments,
            $this->adjustedParticipants,
            $entrants,
            $withdrawals
        );
    }

    /**
     * Every result must reference one of the pairing's events — accepting
     * results for unrelated events would silently corrupt engine replay.
     *
     * @param array<Result> $results
     * @throws InvalidConfigurationException When a result references an event outside the pairing
     */
    private function assertResultsBelongTo(RoundPairing $pairing, array $results): void
    {
        $pairingEventKeys = [];
        foreach ($pairing->getEvents() as $event) {
            $pairingEventKeys[$this->eventKey($event)] = true;
        }

        foreach ($results as $result) {
            $resultRound = $result->getEvent()->getRound()?->getNumber();
            if ($resultRound !== $pairing->getRoundNumber()) {
                throw new InvalidConfigurationException(
                    'Result belongs to a different round than the pairing being recorded',
                    ['pairing_round' => $pairing->getRoundNumber(), 'result_round' => $resultRound],
                    reason: $resultRound === null
                        ? InvalidConfigurationReason::EventWithoutRoundNumber
                        : InvalidConfigurationReason::EventNotInRound
                );
            }

            if (!isset($pairingEventKeys[$this->eventKey($result->getEvent())])) {
                throw new InvalidConfigurationException(
                    'Result references an event that is not part of the pairing being recorded',
                    ['pairing_round' => $pairing->getRoundNumber(), 'event' => $this->eventKey($result->getEvent())],
                    reason: InvalidConfigurationReason::EventNotInRound
                );
            }
        }
    }

    /**
     * Identify an event by round, participants, and tie leg — object
     * identity does not survive serialization round-trips.
     */
    private function eventKey(Event $event): string
    {
        $ids = array_map(fn(Participant $participant) => $participant->getId(), $event->getParticipants());
        $leg = $event->getMetadataValue('tie_leg');

        return ($event->getRound()?->getNumber() ?? 0) . ':' . PairKey::of(...array_values($ids)) . ':' . (is_int($leg) ? $leg : 1);
    }

    /**
     * Record further results for the most recently recorded round.
     *
     * The escape hatch for rounds recorded before all their results were
     * known (e.g. a two-legged tie whose second leg finished later):
     * engines report such rounds as partially resolved until the missing
     * results arrive here.
     *
     * Only the last recorded round is reached. A result of a round before
     * it is refused, and the refusal says where it goes: a state can await
     * one there when the round after it was paired early
     * (SwissPairingEngine::pairNextRoundOnIncompleteResults()), and it is
     * then supplied with the history (fromHistory() takes the results of
     * any round).
     *
     * @param array<Result> $results
     * @throws InvalidConfigurationException When no round is recorded (reason `NoRoundRecorded`) or
     *                                       a result belongs to a different round or to an event
     *                                       the last round does not hold (`EventNotInRound`;
     *                                       `EventWithoutRoundNumber` when its event carries no
     *                                       round number at all)
     */
    public function withAdditionalResults(array $results): self
    {
        $lastRound = $this->getLastRound();
        if ($lastRound === null) {
            throw new InvalidConfigurationException(
                'No round has been recorded to add results to',
                [],
                reason: InvalidConfigurationReason::NoRoundRecorded
            );
        }

        $recordedRounds = [];
        foreach ($this->roundsPlayed as $pairing) {
            $recordedRounds[$pairing->getRoundNumber()] = true;
        }
        foreach ($results as $result) {
            $resultRound = $result->getEvent()->getRound()?->getNumber();
            if ($resultRound !== null && $resultRound !== $lastRound->getRoundNumber() && isset($recordedRounds[$resultRound])) {
                throw new InvalidConfigurationException(
                    "Result belongs to a different round than the last recorded one: round {$resultRound} is recorded, and results"
                        . " are added to the last recorded round only (round {$lastRound->getRoundNumber()}). Supply the result with"
                        . ' the history (StageState::fromHistory() takes the results of any round), or rebuild the state from its'
                        . ' rounds with the result in its place.',
                    ['pairing_round' => $lastRound->getRoundNumber(), 'result_round' => $resultRound],
                    reason: InvalidConfigurationReason::EventNotInRound
                );
            }
        }

        $this->assertResultsBelongTo($lastRound, $results);

        return new self(
            $this->participants,
            $this->roundsPlayed,
            [...$this->results, ...array_values($results)],
            $this->engineFingerprint,
            $this->adjustments,
            $this->adjustedParticipants,
            $this->entrants,
            $this->withdrawals
        );
    }

    /**
     * Replace the recorded result of one event of the most recently
     * recorded round: the correction of a result that was entered
     * wrongly. It changes what is recorded and decides nothing: an event
     * that really finished level is not resolved by recording a winner
     * it did not have.
     *
     * The result to replace is found by the replacement's event: the same
     * round number, the same participants in either order, and the same
     * tie leg. Object identity is not needed, so a state that came back
     * from storage can be corrected. The replacement takes the place of
     * the result it replaces in getResults(). If the event had been given
     * more than one result (withAdditionalResults() does not look for an
     * existing one), the first is replaced and the others are dropped, so
     * the event has exactly one result afterwards. Rounds, byes and the
     * active list are untouched.
     *
     * Only the last recorded round can be corrected. Every later round was
     * paired from the results of the rounds before it — a bracket's next
     * round holds the winners, a Swiss round follows the table — so a
     * state that kept those rounds over a changed result would state
     * pairings no engine made from it. The method refuses instead of
     * dropping or keeping them. To correct an earlier round, rebuild the
     * state: StageState::start(), then withRoundPlayed() for each round
     * that still stands, with the corrected result, and ask the engine for
     * the next round again. Where the later pairings were in fact played
     * and stand whatever the correction (a Swiss round is a fact once
     * played), record them again the same way.
     *
     * A replacement removes nothing but the old result: an event whose
     * result should not exist at all is not what this verb is for.
     *
     * @throws InvalidConfigurationException When no round has been recorded (reason
     *                                       `NoRoundRecorded`), when the event has no recorded
     *                                       result (`ResultNotRecorded`), or when its round is not
     *                                       the last recorded round (`RoundSuperseded`)
     */
    public function withResultReplaced(Result $result): self
    {
        $lastRound = $this->getLastRound();
        if ($lastRound === null) {
            throw new InvalidConfigurationException(
                'No round has been recorded to replace a result in',
                [],
                reason: InvalidConfigurationReason::NoRoundRecorded
            );
        }

        $event = $result->getEvent();
        $eventKey = $this->eventKey($event);
        $round = $event->getRound()?->getNumber();

        $results = [];
        $replaced = false;
        foreach ($this->results as $recorded) {
            if ($this->eventKey($recorded->getEvent()) !== $eventKey) {
                $results[] = $recorded;
                continue;
            }

            if (!$replaced) {
                $results[] = $result;
                $replaced = true;
            }
        }

        if (!$replaced) {
            throw new InvalidConfigurationException(
                'No result is recorded for the event; record a first result with withRoundPlayed() or withAdditionalResults()',
                [
                    'round' => $round,
                    'participants' => array_map(
                        fn(Participant $participant) => $participant->getId(),
                        $event->getParticipants()
                    ),
                ],
                reason: InvalidConfigurationReason::ResultNotRecorded
            );
        }

        if ($round !== $lastRound->getRoundNumber()) {
            throw new InvalidConfigurationException(
                "A result of round {$round} cannot be replaced: round {$lastRound->getRoundNumber()} was paired from the"
                    . " results of round {$round}. Rebuild the state up to round {$round} with the corrected result"
                    . ' (StageState::start(), then withRoundPlayed() for each round that stands) and pair again.',
                ['round' => $round, 'last_round' => $lastRound->getRoundNumber()],
                reason: InvalidConfigurationReason::RoundSuperseded
            );
        }

        return new self(
            $this->participants,
            $this->roundsPlayed,
            $results,
            $this->engineFingerprint,
            $this->adjustments,
            $this->adjustedParticipants,
            $this->entrants,
            $this->withdrawals
        );
    }

    /**
     * Withdraw a participant: they leave the active list; their recorded
     * pairings and results remain and still count toward standings.
     *
     * The participant is found by ID. Withdrawing one that is not active
     * changes nothing and is not an error. There is no verb that puts a
     * withdrawn participant back.
     *
     * Every withdrawal is recorded with its time: the number of the last
     * round recorded at this moment, 0 before the first (see
     * getWithdrawals() and Withdrawal). The state needs no argument for
     * it, because it knows its rounds.
     *
     * Once a round is recorded the stage is live, and a participant who
     * withdraws from it keeps its place in the entrant list (see
     * getEntrants()): nothing that was drawn from the list is drawn
     * again. A bracket engine reads the place and sends the opponent of a
     * withdrawn entrant on without an event. Where both entrants of a tie
     * its results do not decide have withdrawn, the one still in the stage
     * when its next tie was paired went on, and where neither was, the tie
     * is void: it sends nobody on (see Withdrawal). Before the first round
     * is recorded nothing has been drawn as far as the state knows: the
     * participant is out of the draw (getEntrants() does not list it), and
     * the first round is paired from those who remain. The first round,
     * once recorded, says whether it was in the draw after all.
     *
     * A round that is recorded after this call and names the participant
     * (it was paired before the withdrawal and is recorded once it has
     * been played) is read as paired while the participant was in the
     * stage: the tie it holds for the participant is given away, and the
     * round is not read as paired without it. That holds for the first
     * round as well: a participant it names keeps its place in the entrant
     * list, with its withdrawal after round 0, and one it does not name
     * withdrew before the draw, is no entrant and keeps no withdrawal.
     *
     * A round is not to name a participant who had left before it was
     * paired, among byes listed as everyone without an event for example.
     * An engine reads such a participant as in the stage for that round
     * and for every round before it, whatever round the withdrawal states:
     * the rounds between are then compared with rounds that pair the
     * participant (RecordedRound::isManual()), and a bracket is read with
     * the participant still in it, which a double elimination engine can
     * refuse.
     */
    public function withoutParticipant(Participant $participant): self
    {
        return $this->withdrawn($participant, $this->getLastRound()?->getRoundNumber() ?? 0);
    }

    /**
     * The state without a participant, who is said to have withdrawn after
     * the given round (0 before the first round was recorded).
     *
     * A participant who is not active has nothing to withdraw from, and
     * the state is returned as it is. One who withdrew after round 0 and
     * whom the first round, recorded already, does not name was not in the
     * draw: it is removed from the entrant list, and no withdrawal is
     * kept. Every other withdrawal is kept with its round, and the entrant
     * keeps its place.
     */
    private function withdrawn(Participant $participant, int $afterRound): self
    {
        $withdrawing = null;
        foreach ($this->participants as $active) {
            if ($active->getId() === $participant->getId()) {
                $withdrawing = $active;
            }
        }
        if ($withdrawing === null) {
            return $this;
        }

        $remains = static fn(Participant $other): bool => $other->getId() !== $participant->getId();
        $beforeTheDraw = $afterRound === 0
            && $this->roundsPlayed !== []
            && !self::names($this->roundsPlayed[0], $participant->getId());

        return new self(
            array_values(array_filter($this->participants, $remains)),
            $this->roundsPlayed,
            $this->results,
            $this->engineFingerprint,
            $this->adjustments,
            $this->adjustedParticipants,
            $beforeTheDraw ? array_values(array_filter($this->entrants, $remains)) : $this->entrants,
            $beforeTheDraw ? $this->withdrawals : [...$this->withdrawals, new Withdrawal($withdrawing, $afterRound)]
        );
    }

    /**
     * Whether a round names a participant, in an event or as a bye.
     */
    private static function names(RoundPairing $pairing, string $id): bool
    {
        foreach ($pairing->getEvents() as $event) {
            foreach ($event->getParticipants() as $participant) {
                if ($participant->getId() === $id) {
                    return true;
                }
            }
        }
        foreach ($pairing->getByes() as $bye) {
            if ($bye->getId() === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * The withdrawals from the stage, each with the round it happened
     * after, in the order they happened; empty when there are none.
     *
     * Every entrant among getWithdrawnEntrants() has one, with the number
     * of the last round recorded when it withdrew. While no round is
     * recorded the withdrawals are after round 0, and their participants
     * are out of the draw: getEntrants() does not list them until the first
     * round is recorded and names them. A participant the first round does
     * not name withdrew before the draw, and its withdrawal is not kept. A
     * state stored by 0.2.x has none: a participant who had withdrawn then
     * has no place and no time.
     *
     * @return list<Withdrawal>
     */
    public function getWithdrawals(): array
    {
        return $this->withdrawals;
    }

    /**
     * The entrant list of the stage: the active participants in the order
     * the stage was started with and, each in the place it had, the
     * participants who withdrew from the live stage, the first round
     * included where that round names them. Before the first round is
     * recorded it is the list that round is paired from: a participant who
     * has withdrawn is not in it.
     *
     * A bracket is folded from this list, so that a withdrawal does not
     * move anybody. getParticipants() is this list without the withdrawn.
     * A state read from data that an earlier release stored does not know
     * the place of a participant who had withdrawn: such a participant is
     * not in the list, and it is the active list.
     *
     * @return list<Participant>
     */
    public function getEntrants(): array
    {
        if ($this->roundsPlayed !== [] || $this->withdrawals === []) {
            return $this->entrants;
        }

        // Before the first round is recorded, whoever has withdrawn is out
        // of the draw until that round names it
        $withdrawn = [];
        foreach ($this->withdrawals as $withdrawal) {
            $withdrawn[$withdrawal->getParticipant()->getId()] = true;
        }

        return array_values(array_filter(
            $this->entrants,
            static fn(Participant $entrant): bool => !isset($withdrawn[$entrant->getId()])
        ));
    }

    /**
     * The entrants who have withdrawn and kept their place in the entrant
     * list, in the order of that list; empty when there are none.
     *
     * @return list<Participant>
     */
    public function getWithdrawnEntrants(): array
    {
        $active = [];
        foreach ($this->participants as $participant) {
            $active[$participant->getId()] = true;
        }

        return array_values(array_filter(
            $this->getEntrants(),
            static fn(Participant $entrant): bool => !isset($active[$entrant->getId()])
        ));
    }

    /**
     * Every event of a recorded round that has no recorded result, in play
     * order; empty when every recorded event has one.
     *
     * A result is matched to its event by round number, participants in
     * either order and tie leg, so a state that came back from storage
     * answers the same. Whether such an event still awaits a result is the
     * engine's to say (HistoryReadingEngine::getReadiness()): a bracket
     * awaits none for a tie an entrant has withdrawn from.
     *
     * @return list<Event>
     */
    public function getEventsWithoutResult(): array
    {
        $resolved = [];
        foreach ($this->results as $result) {
            $resolved[$this->eventKey($result->getEvent())] = true;
        }

        $events = [];
        foreach ($this->roundsPlayed as $pairing) {
            foreach ($pairing->getEvents() as $event) {
                if (!isset($resolved[$this->eventKey($event)])) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /**
     * Record an adjustment: an amount added to, or taken from, one
     * participant's ranking value for a reason that is no event (see
     * Adjustment). The application decides it; the state records it.
     *
     * Every table an engine builds from the state has it: the table a
     * Swiss round is paired from, the ranking a re-seeded bracket folds
     * its survivors by, the order of the entrants a bracket put out in the
     * same round, and the table of the outcome. The entry of the
     * participant shows the sum of its adjustments as a figure of its own
     * (StandingEntry::getAdjustment()) and is ordered by what it earned
     * plus that sum. So an adjustment recorded between two rounds of a
     * Swiss stage changes the rounds paired after it and none of those
     * recorded before: a round is paired once and is a fact once
     * recorded. That holds for an elimination bracket that re-seeds each
     * round too: the round paired next is folded from the adjusted totals,
     * and a round already recorded is ranked from the adjustments made
     * before it was paired, and from no later one.
     *
     * The state says when: an adjustment given here without a round is
     * recorded as made after the last round the state holds at this moment
     * (Adjustment::getAfterRound(); 0 before the first round), so no
     * argument is needed between rounds. One that states a round keeps it,
     * which is how an adjustment that was made earlier is recorded late;
     * the round may not be after the last recorded one. getAdjustments()
     * therefore returns the adjustment with its round, which is another
     * object than the one given when that one had none.
     *
     * The last round the state holds is the last one recorded, so record
     * a round when it is paired (withRoundPaired()) and add its results as
     * they come (withAdditionalResults()). An adjustment made while a round
     * is played is then recorded after that round, and is not read as known
     * when the round was paired. A round recorded only once it has been
     * played comes after such an adjustment, and the adjustment is read as
     * known when the round was paired: nothing in a round says which
     * adjustments it was paired from.
     *
     * Adjustments are kept in the order recorded and are never merged: two
     * for one participant are two entries of getAdjustments(), and the
     * table adds them. There is no verb that removes one; record the
     * opposite amount to take one back, which also keeps the record of
     * both.
     *
     * The participant must be one the stage has seen (see
     * getAllSeenParticipants()): active, or withdrawn and still named by a
     * recorded round, a result or an earlier adjustment. One that withdraws
     * afterwards keeps its adjustment and stays among the participants seen.
     *
     * @throws InvalidInputException When the adjustment names a participant the stage has not seen,
     *                               or states a round after the last recorded one
     */
    public function withAdjustment(Adjustment $adjustment): self
    {
        $id = $adjustment->getParticipantId();

        $participant = null;
        foreach ($this->getAllSeenParticipants() as $seen) {
            if ($seen->getId() === $id) {
                $participant = $seen;
                break;
            }
        }

        if ($participant === null) {
            throw new InvalidInputException(
                "Adjustment references participant {$id} who is not in the stage"
            );
        }

        $lastRound = $this->getLastRound()?->getRoundNumber() ?? 0;
        $afterRound = $adjustment->getAfterRound();
        if ($afterRound === null) {
            $adjustment = $adjustment->madeAfterRound($lastRound);
        } elseif ($afterRound > $lastRound) {
            throw new InvalidInputException(
                "The adjustment for participant {$id} is said to be made after round {$afterRound}, and the last round"
                    . " recorded is round {$lastRound}: an adjustment is made after a round that is recorded, or after"
                    . ' round 0 before the first'
            );
        }

        return $this->adjustedBy($adjustment, $participant);
    }

    /**
     * The state with one more adjustment, for a participant already found:
     * what withAdjustment() records of a live state, and what a stored
     * state is given back for each adjustment it holds.
     */
    private function adjustedBy(Adjustment $adjustment, Participant $participant): self
    {
        return new self(
            $this->participants,
            $this->roundsPlayed,
            $this->results,
            $this->engineFingerprint,
            [...$this->adjustments, $adjustment],
            $this->adjustedParticipants + [$participant->getId() => $participant],
            $this->entrants,
            $this->withdrawals
        );
    }

    /**
     * Every recorded adjustment, in the order recorded; empty when the
     * stage has none.
     *
     * @return list<Adjustment>
     */
    public function getAdjustments(): array
    {
        return $this->adjustments;
    }

    /**
     * Stamp the state with the fingerprint of the engine that pairs it, or
     * remove the stamp with null.
     *
     * A state does not otherwise say which engine paired its rounds, and
     * an engine replays whatever it is handed: a Swiss state given to a
     * bracket engine, or a two-legged bracket given to a one-legged one,
     * is read as that engine's own history. A stamped state is refused by
     * every engine whose fingerprint differs (see requireEngineFingerprint()):
     *
     *     $state = StageState::start($participants)
     *         ->withEngineFingerprint($engine->getFingerprint());
     *
     * The stamp is optional and opt-in. A state without one is accepted by
     * every engine, as before the stamp existed, and serializes without
     * the key. The stamp survives every other verb and toArray()/toJson().
     * To change the engine's configuration on purpose in mid-stage (a
     * bracket moved from a fixed path to a re-seeded one), stamp the state
     * again with the new engine's fingerprint.
     *
     * The library's engines each offer getFingerprint() (see
     * FingerprintedEngine for what a fingerprint covers and for how long
     * one is kept: an option at its default is not part of it, so an
     * engine that gains an option still accepts the states stamped before;
     * a patch release keeps every fingerprint, and a 0.x minor release may
     * change the scheme, with a migration note). Their fingerprints begin
     * with `tactician:`; any non-empty string that does not will do for
     * an engine of your own.
     *
     * @throws InvalidConfigurationException When the fingerprint is an empty string
     */
    public function withEngineFingerprint(?string $fingerprint): self
    {
        if ($fingerprint === '') {
            throw new InvalidConfigurationException('An engine fingerprint cannot be empty', [], reason: InvalidConfigurationReason::EmptyEngineFingerprint);
        }

        return new self(
            $this->participants,
            $this->roundsPlayed,
            $this->results,
            $fingerprint,
            $this->adjustments,
            $this->adjustedParticipants,
            $this->entrants,
            $this->withdrawals
        );
    }

    /**
     * The fingerprint the state was stamped with, or null when it carries
     * none.
     */
    public function getEngineFingerprint(): ?string
    {
        return $this->engineFingerprint;
    }

    /**
     * Fail unless this state may be read by the engine with the given
     * fingerprint: it carries no stamp, or it carries this one.
     *
     * The library's engines call this at the start of getPlan(),
     * pairNextRound(), isComplete() and getOutcome(); an engine of your own
     * can do the same.
     *
     * The error says where the two differ, in its message and as the list
     * `differences` of its context: the format, or each option with its
     * value on either side (`legs-per-tie: recorded 2, this engine the
     * default`). That wording is for a person to read and may change. The
     * context also holds the two fingerprints, as `recorded` and `engine`.
     *
     * @throws InvalidConfigurationException When the state is stamped with a different fingerprint
     */
    public function requireEngineFingerprint(string $fingerprint): void
    {
        if ($this->engineFingerprint !== null && $this->engineFingerprint !== $fingerprint) {
            $differences = EngineFingerprint::differences($this->engineFingerprint, $fingerprint);

            throw new InvalidConfigurationException(
                'The stage state was recorded by a different engine or configuration ('
                    . implode('; ', $differences) . '); stamp it again with'
                    . ' withEngineFingerprint() if the change is deliberate',
                ['recorded' => $this->engineFingerprint, 'engine' => $fingerprint, 'differences' => $differences],
                reason: InvalidConfigurationReason::EngineFingerprintMismatch
            );
        }
    }

    /**
     * The active participants as a list, in the order the stage was
     * started with, without those withdrawn since. Engines seed and pair
     * from this order.
     *
     * @return array<Participant> Active participants
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    /**
     * @return array<RoundPairing> Pairings recorded so far, in play order
     */
    public function getRoundsPlayed(): array
    {
        return $this->roundsPlayed;
    }

    /**
     * The most recently recorded round, or null before any round is played.
     */
    public function getLastRound(): ?RoundPairing
    {
        return $this->roundsPlayed === [] ? null : $this->roundsPlayed[count($this->roundsPlayed) - 1];
    }

    /**
     * The 1-based number the next round should carry: 1 before any round
     * is recorded, otherwise one more than the last recorded round's.
     */
    public function getNextRoundNumber(): int
    {
        $lastRound = $this->getLastRound();

        return $lastRound === null ? 1 : $lastRound->getRoundNumber() + 1;
    }

    /**
     * Every recorded result, in the order recorded: round by round, with
     * the results added later by withAdditionalResults() after those of
     * withRoundPlayed(). A round recorded without results contributes
     * none, so this can be shorter than getPlayedEvents().
     *
     * @return array<Result>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Every event from every recorded pairing, in play order.
     *
     * @return array<Event>
     */
    public function getPlayedEvents(): array
    {
        $events = [];
        foreach ($this->roundsPlayed as $pairing) {
            foreach ($pairing->getEvents() as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Bye recipients in award order, one entry per bye (IDs repeat when a
     * participant sat out more than once).
     *
     * @return array<string>
     */
    public function getByeIds(): array
    {
        $byeIds = [];
        foreach ($this->roundsPlayed as $pairing) {
            foreach ($pairing->getByes() as $bye) {
                $byeIds[] = $bye->getId();
            }
        }

        return $byeIds;
    }

    /**
     * The byes of every recorded round: the participants its pairing lists
     * as having one (RoundPairing::getByes()), by round number in the
     * order the rounds were recorded. Every recorded round is a key, with
     * an empty list where nobody had a bye.
     *
     * @return array<int, list<Participant>>
     */
    public function getByesByRound(): array
    {
        $byes = [];
        foreach ($this->roundsPlayed as $pairing) {
            $byes[$pairing->getRoundNumber()] = array_values($pairing->getByes());
        }

        return $byes;
    }

    /**
     * Bye counts keyed by participant ID. A participant that had no bye
     * has no entry. This is the shape a standings calculator takes byes
     * in (Standings\StandingsCalculator::calculateWith()).
     *
     * @return array<string, int>
     */
    public function getByeCounts(): array
    {
        $counts = [];
        foreach ($this->getByeIds() as $byeId) {
            $counts[$byeId] = ($counts[$byeId] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Every participant the stage has seen: the active list plus withdrawn
     * participants still referenced by recorded rounds (events or byes),
     * results or adjustments. This is what plans and outcome standings
     * cover — a withdrawn participant's played games, and an adjustment
     * made to it, remain part of the record.
     *
     * @return array<Participant>
     */
    public function getAllSeenParticipants(): array
    {
        $participants = array_values($this->participants);
        $knownIds = [];
        foreach ($participants as $participant) {
            $knownIds[$participant->getId()] = true;
        }

        $add = function (Participant $participant) use (&$participants, &$knownIds): void {
            if (!isset($knownIds[$participant->getId()])) {
                $knownIds[$participant->getId()] = true;
                $participants[] = $participant;
            }
        };

        foreach ($this->roundsPlayed as $pairing) {
            foreach ($pairing->getEvents() as $event) {
                foreach ($event->getParticipants() as $participant) {
                    $add($participant);
                }
            }
            foreach ($pairing->getByes() as $participant) {
                $add($participant);
            }
        }

        foreach ($this->results as $result) {
            foreach ($result->getEvent()->getParticipants() as $participant) {
                $add($participant);
            }
        }

        foreach ($this->adjustedParticipants as $participant) {
            $add($participant);
        }

        // An entrant who withdrew from a live stage and is named by nothing
        // recorded is still an entrant of it
        foreach ($this->getEntrants() as $participant) {
            $add($participant);
        }

        return $participants;
    }

    /**
     * Convert this state to a serializable array.
     *
     * The participant registry lists every participant seen — active ones
     * plus any withdrawn participants still referenced by recorded rounds,
     * results or adjustments — so rehydration resolves all references.
     *
     * The `engine_fingerprint` key is present only when the state is
     * stamped (see withEngineFingerprint()), and the `adjustments` key only
     * when an adjustment is recorded (see withAdjustment()), so a state
     * with neither has the keys it has always had after the version. A
     * result carries an `award` key only when it is awarded and a
     * `decider` key only when it carries the decision of a level tie, and
     * a round a `paired_on_incomplete_results` key only when it carries
     * that mark.
     * The `entrants` key, the IDs of the entrant list in its order (see
     * getEntrants()), is present only when an entrant has withdrawn and
     * kept its place: `active` is then that list without the withdrawn,
     * and a state nobody has withdrawn from says its entrant list with
     * `active` alone, as it always did. Before the first round is recorded
     * the list holds a participant who has withdrawn, in its place, until
     * that round says whether it was in the draw.
     * The `withdrawals` key is present only when somebody has withdrawn
     * (see getWithdrawals()): one entry for each entrant in `entrants` who
     * is not in `active`, in the order they happened, with the ID under
     * `participant` and the round under `after_round`, 0 before the first
     * round. Every adjustment carries an `after_round` key: a state records
     * after which round each was made.
     *
     * The first key is `format_version`, the version of this form (1). It
     * says how the array is to be read and is written once, here: the
     * participants, rounds, results and adjustments inside carry none. It is another
     * thing than the `v2` inside an engine fingerprint, which is the
     * version of the scheme the stamp is written in; the stamp is one
     * string value of this form, and either version can change without
     * the other, as the scheme did in 0.3.0 while this form stayed at
     * version 1.
     *
     * @return array{format_version: int, participants: array<int, array{id: string, label: string, seed: int|null, metadata: array<string, mixed>}>, active: array<string>, rounds: array<int, array{round: int, label: string|null, events: array<int, array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}>, byes: array<string>}>, results: array<int, array{event: array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}, winner: string|null, scores: array<int|string, int|float>, metadata: array<string, mixed>, award?: array{label: string|null, reason: string|null}, decider?: array{participants: list<string>, label: string|null, reason: string|null}}>, engine_fingerprint?: string, adjustments?: list<array{participant: string, amount: float, label: string|null, reason: string|null, after_round?: int}>, entrants?: list<string>, withdrawals?: list<array{participant: string, after_round: int}>}
     */
    public function toArray(): array
    {
        /** @var array<string, Participant> $registry */
        $registry = [];
        foreach ($this->participants as $participant) {
            $registry[$participant->getId()] ??= $participant;
        }
        foreach ($this->roundsPlayed as $pairing) {
            foreach ($pairing->getEvents() as $event) {
                foreach ($event->getParticipants() as $participant) {
                    $registry[$participant->getId()] ??= $participant;
                }
            }
            foreach ($pairing->getByes() as $participant) {
                $registry[$participant->getId()] ??= $participant;
            }
        }
        foreach ($this->results as $result) {
            foreach ($result->getEvent()->getParticipants() as $participant) {
                $registry[$participant->getId()] ??= $participant;
            }
        }
        foreach ($this->adjustedParticipants as $participant) {
            $registry[$participant->getId()] ??= $participant;
        }
        foreach ($this->entrants as $participant) {
            $registry[$participant->getId()] ??= $participant;
        }

        // One array has one version, at its top: what the state holds is
        // written without the key.
        $participants = [];
        foreach ($registry as $participant) {
            $form = $participant->toArray();
            unset($form[PlainData::VERSION_KEY]);
            $participants[] = $form;
        }

        $rounds = [];
        foreach ($this->roundsPlayed as $key => $pairing) {
            $form = $pairing->toArray();
            unset($form[PlainData::VERSION_KEY]);
            $rounds[$key] = $form;
        }

        $results = [];
        foreach ($this->results as $key => $result) {
            $form = $result->toArray();
            unset($form[PlainData::VERSION_KEY]);
            $results[$key] = $form;
        }

        $data = [
            PlainData::VERSION_KEY => PlainData::VERSION,
            'participants' => $participants,
            'active' => array_map(
                fn(Participant $participant) => $participant->getId(),
                $this->participants
            ),
            'rounds' => $rounds,
            'results' => $results,
        ];

        if ($this->engineFingerprint !== null) {
            $data['engine_fingerprint'] = $this->engineFingerprint;
        }

        if ($this->adjustments !== []) {
            $adjustments = [];
            foreach ($this->adjustments as $adjustment) {
                $form = $adjustment->toArray();
                unset($form[PlainData::VERSION_KEY]);
                $adjustments[] = $form;
            }
            $data['adjustments'] = $adjustments;
        }

        if (count($this->entrants) !== count($this->participants)) {
            $data['entrants'] = array_map(
                static fn(Participant $participant): string => $participant->getId(),
                $this->entrants
            );
        }

        if ($this->withdrawals !== []) {
            $data['withdrawals'] = array_map(
                static fn(Withdrawal $withdrawal): array => [
                    'participant' => $withdrawal->getParticipant()->getId(),
                    'after_round' => $withdrawal->getAfterRound(),
                ],
                $this->withdrawals
            );
        }

        return $data;
    }

    /**
     * Recreate a state from its array representation.
     *
     * The `engine_fingerprint` key is optional: data stored before the
     * stamp existed, or by a caller that does not stamp, loads as an
     * unstamped state. So is `adjustments`: data without it, which is all
     * data stored before adjustments existed, loads as a state with none.
     * Every adjustment must name a participant of the `participants`
     * registry. So is `entrants`: data without it, which is all data
     * stored before the key existed, loads with the active list as its
     * entrant list, so a participant who had withdrawn has no place in it
     * (see getEntrants()). So is `withdrawals`: data without it, which is
     * all data stored before it existed, loads with no withdrawal, and a
     * participant who had withdrawn then (0.2.x wrote neither key) is read
     * with no place and no time, and nothing is assumed about when it left.
     * The `after_round` of an adjustment is required where the state holds
     * a round, and read as 0 where it holds none (0.2.x wrote no
     * adjustments). So is
     * `format_version`: data without it is read as version 1, the form the
     * releases before 0.3.0 wrote. A version this release does not know is
     * refused.
     *
     * The shape is checked, that the data holds no key but the version and
     * the eight toArray() writes, that every ID resolves in the `participants`
     * registry, that no ID is listed twice in `participants`, in `active`, in
     * `entrants` or in `withdrawals`, that `entrants` holds every active
     * participant in the order of `active`, that each entry of
     * `withdrawals` names an entrant who is not active, with a round from 0
     * to the last recorded one that is not below the round of the entry
     * before it (and round 0 only for a participant the first recorded
     * round names, where one is recorded), and that every entrant who is
     * not active has an entry there. Every adjustment of a state with a
     * recorded round says its round, which may not be after the last
     * recorded round either.
     *
     * This is the reader of what toArray() wrote. An application that
     * holds its rounds and results in rows of its own does not build this
     * array: it calls fromHistory(), which records the history through the
     * same verbs and checks three rules more.
     *
     * The history is checked as the verbs check it, because it is
     * recorded again through withRoundPlayed(): rounds in play order with
     * rising numbers from 1, every event of a pairing carrying the
     * pairing's round number, and every result standing with the results
     * of its round and belonging to an event of that round. Stored data
     * that states a history no sequence of verbs could have recorded is
     * refused with the reason the verb gives (`ValueOutOfRange` for a
     * round below 1, `RoundOutOfSequence`, `EventNotInRound`,
     * `EventWithoutRoundNumber`), where it used to load and be replayed by
     * an engine as if it were sound. What the verbs do not check is not
     * checked here either: a gap in the round numbers, the participants of
     * a pairing being active, and an event with more than one result (the
     * standings and the bracket engines refuse that one, and
     * withResultReplaced() repairs it).
     *
     * @param array<string, mixed> $data
     * @throws InvalidInputException When the data is malformed
     * @throws InvalidConfigurationException When the stored history is one the verbs refuse
     */
    public static function fromArray(array $data): self
    {
        PlainData::checkFields(
            $data,
            ['participants', 'active', 'rounds', 'results', 'engine_fingerprint', 'adjustments', 'entrants', 'withdrawals'],
            'stage state data'
        );

        $participantsData = $data['participants'] ?? [];
        if (!is_array($participantsData)) {
            throw new InvalidInputException('Stage state participants must be an array');
        }

        /** @var array<string, Participant> $registry */
        $registry = [];
        foreach ($participantsData as $participantData) {
            if (!is_array($participantData)) {
                throw new InvalidInputException('Each stage state participant must be an array');
            }
            /** @var array<string, mixed> $participantData */
            $participant = Participant::fromArray($participantData);
            if (isset($registry[$participant->getId()])) {
                throw new InvalidInputException(
                    "Stage state registry contains participant {$participant->getId()} twice"
                );
            }
            $registry[$participant->getId()] = $participant;
        }

        $activeIds = $data['active'] ?? [];
        if (!is_array($activeIds)) {
            throw new InvalidInputException('Stage state active list must be an array');
        }
        $active = [];
        foreach ($activeIds as $activeId) {
            if (!is_string($activeId) || !isset($registry[$activeId])) {
                throw new InvalidInputException(
                    'Stage state references unknown active participant ' . var_export($activeId, true)
                );
            }
            if (isset($active[$activeId])) {
                throw new InvalidInputException(
                    'Stage state lists active participant ' . var_export($activeId, true) . ' twice'
                );
            }
            $active[$activeId] = $registry[$activeId];
        }
        $entrants = null;
        $entrantIds = $data['entrants'] ?? null;
        if ($entrantIds !== null) {
            if (!is_array($entrantIds)) {
                throw new InvalidInputException('Stage state entrant list must be an array');
            }
            $entrants = [];
            foreach ($entrantIds as $entrantId) {
                if (!is_string($entrantId) || !isset($registry[$entrantId])) {
                    throw new InvalidInputException(
                        'Stage state references unknown entrant ' . var_export($entrantId, true)
                    );
                }
                if (isset($entrants[$entrantId])) {
                    throw new InvalidInputException(
                        'Stage state lists entrant ' . var_export($entrantId, true) . ' twice'
                    );
                }
                $entrants[$entrantId] = $registry[$entrantId];
            }

            // The active participants are the entrants who have not
            // withdrawn, in the order of the entrant list
            if (array_keys(array_intersect_key($entrants, $active)) !== array_keys($active)) {
                throw new InvalidInputException(
                    'Stage state entrant list must hold every active participant, in the order of the active list'
                );
            }
            $entrants = array_values($entrants);
        }
        $active = array_values($active);

        $roundsData = $data['rounds'] ?? [];
        if (!is_array($roundsData)) {
            throw new InvalidInputException('Stage state rounds must be an array');
        }
        $rounds = [];
        foreach ($roundsData as $roundData) {
            if (!is_array($roundData)) {
                throw new InvalidInputException('Each stage state round must be an array');
            }
            /** @var array<string, mixed> $roundData */
            $rounds[] = RoundPairing::fromArray($roundData, $registry);
        }

        $resultsData = $data['results'] ?? [];
        if (!is_array($resultsData)) {
            throw new InvalidInputException('Stage state results must be an array');
        }
        $results = [];
        foreach ($resultsData as $resultData) {
            if (!is_array($resultData)) {
                throw new InvalidInputException('Each stage state result must be an array');
            }
            /** @var array<string, mixed> $resultData */
            $results[] = Result::fromArray($resultData, $registry);
        }

        $fingerprint = $data['engine_fingerprint'] ?? null;
        if ($fingerprint !== null && (!is_string($fingerprint) || $fingerprint === '')) {
            throw new InvalidInputException('Stage state engine fingerprint must be a non-empty string');
        }

        $adjustmentsData = $data['adjustments'] ?? [];
        if (!is_array($adjustmentsData)) {
            throw new InvalidInputException('Stage state adjustments must be an array');
        }
        $adjustments = [];
        foreach ($adjustmentsData as $adjustmentData) {
            if (!is_array($adjustmentData)) {
                throw new InvalidInputException('Each stage state adjustment must be an array');
            }
            $adjustment = Adjustment::fromArray($adjustmentData);
            $adjustedId = $adjustment->getParticipantId();
            if (!isset($registry[$adjustedId])) {
                throw new InvalidInputException(
                    "Stage state adjustment references unknown participant {$adjustedId}"
                );
            }
            $adjustments[] = ['adjustment' => $adjustment, 'participant' => $registry[$adjustedId]];
        }

        $lastRound = 0;
        foreach ($rounds as $round) {
            $lastRound = max($lastRound, $round->getRoundNumber());
        }
        foreach ($adjustments as $index => ['adjustment' => $adjustment]) {
            $afterRound = $adjustment->getAfterRound();
            if ($afterRound === null) {
                // Only a revision before 0.3.0 that was never released wrote
                // an adjustment without its round; 0.2.x wrote none at all
                if ($lastRound > 0) {
                    throw new InvalidInputException(
                        "Stage state adjustment for participant {$adjustment->getParticipantId()} does not say after which"
                            . ' round it was made, and the state holds rounds: every adjustment of a state with rounds has'
                            . ' an after_round'
                    );
                }
                $adjustments[$index]['adjustment'] = $adjustment->madeAfterRound(0);
            } elseif ($afterRound > $lastRound) {
                throw new InvalidInputException(
                    "Stage state adjustment for participant {$adjustment->getParticipantId()} is said to be made after round"
                        . " {$afterRound}, and the last recorded round is round {$lastRound}"
                );
            }
        }

        $withdrawalsData = $data['withdrawals'] ?? [];
        if (!is_array($withdrawalsData)) {
            throw new InvalidInputException('Stage state withdrawals must be an array');
        }
        $activeIds = [];
        foreach ($active as $participant) {
            $activeIds[$participant->getId()] = true;
        }
        $entrantIds = [];
        foreach ($entrants ?? $active as $entrant) {
            $entrantIds[$entrant->getId()] = true;
        }
        $withdrawals = [];
        $withdrawnIds = [];
        $previousRound = 0;
        foreach ($withdrawalsData as $withdrawalData) {
            if (!is_array($withdrawalData)) {
                throw new InvalidInputException('Each stage state withdrawal must be an array');
            }
            $unknown = array_diff(array_map(strval(...), array_keys($withdrawalData)), ['participant', 'after_round']);
            if ($unknown !== []) {
                throw new InvalidInputException(
                    "Unknown key in stage state withdrawal data: '" . implode("', '", $unknown)
                        . "' (the known keys are 'participant', 'after_round')"
                );
            }

            $withdrawnId = $withdrawalData['participant'] ?? null;
            if (!is_string($withdrawnId) || !isset($registry[$withdrawnId])) {
                throw new InvalidInputException(
                    'Stage state withdrawal references unknown participant ' . var_export($withdrawnId, true)
                );
            }
            if (isset($activeIds[$withdrawnId])) {
                throw new InvalidInputException("Stage state withdrawal names participant {$withdrawnId}, who is active");
            }
            if (!isset($entrantIds[$withdrawnId])) {
                throw new InvalidInputException(
                    "Stage state withdrawal names participant {$withdrawnId}, who is not one of the entrants"
                );
            }
            if (isset($withdrawnIds[$withdrawnId])) {
                throw new InvalidInputException("Stage state lists the withdrawal of participant {$withdrawnId} twice");
            }
            $withdrawnIds[$withdrawnId] = true;

            $afterRound = $withdrawalData['after_round'] ?? null;
            if (!is_int($afterRound) || $afterRound < $previousRound || $afterRound > $lastRound) {
                throw new InvalidInputException(
                    "Stage state withdrawal of participant {$withdrawnId} requires the round it happened after: an integer"
                        . " from 0 to the last recorded round ({$lastRound}), not below the round of the withdrawal listed"
                        . ' before it'
                );
            }
            if ($afterRound === 0 && $rounds !== [] && !self::names($rounds[0], $withdrawnId)) {
                throw new InvalidInputException(
                    "Stage state withdrawal of participant {$withdrawnId} after round 0 is of a participant the first round"
                        . ' does not name: such a participant withdrew before the draw and is no entrant'
                );
            }
            $previousRound = $afterRound;

            $withdrawals[] = new Withdrawal($registry[$withdrawnId], $afterRound);
        }

        // Every entrant who is not active withdrew at a time the state knows
        foreach (array_keys($entrantIds) as $entrantId) {
            if (!isset($activeIds[$entrantId]) && !isset($withdrawnIds[$entrantId])) {
                throw new InvalidInputException(
                    "In the stage state, participant {$entrantId} is not active and has no withdrawal: every entrant who"
                        . ' has withdrawn is listed under withdrawals with the round it happened after'
                );
            }
        }

        return self::recorded($active, $rounds, $results, $fingerprint, $adjustments, $entrants ?? $active, $withdrawals);
    }

    /**
     * Serialize this state to a JSON string: toArray(), encoded.
     * fromJson() reads it back.
     *
     * @throws JsonConversionException When the state contains values JSON cannot represent
     */
    public function toJson(): string
    {
        try {
            return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw JsonConversionException::from($exception);
        }
    }

    /**
     * Recreate a state from its JSON representation, as toJson() wrote
     * it; see fromArray() for what is checked.
     *
     * @throws JsonConversionException When the JSON is malformed
     * @throws InvalidInputException When the decoded data is malformed
     * @throws InvalidConfigurationException When the stored history is one the verbs refuse
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw JsonConversionException::from($exception);
        }
        if (!is_array($data)) {
            throw new InvalidInputException('Stage state JSON must decode to an array');
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    /**
     * The state a stored history describes, recorded again through
     * withRoundPlayed(): whatever that verb refuses of a live state is
     * refused of a stored one, by the same check and with the same reason.
     *
     * The results of a state are in the order they were recorded, and a
     * result can only be recorded with its round or added to the last
     * round recorded, so the results of one round stand together and the
     * rounds follow each other. Each round is recorded with the run of
     * results that carries its number. A result that is left over is one
     * no state could have recorded where it stands: its round is not
     * recorded, or it stands among the results of another round.
     *
     * Two results of one event are not refused here. The verbs accept them
     * (see withAdditionalResults()), so a live state can hold them, and a
     * stored state that holds them has to load for withResultReplaced() to
     * repair it. The standings and the bracket engines refuse them.
     *
     * The adjustments are recorded after the rounds, in the order stored.
     * Each was resolved against the stored registry by the caller, which is
     * the check withAdjustment() makes of a live state: the registry holds
     * every participant the state had seen when it was written, one who was
     * adjusted and withdrew before any round among them, whom the rounds
     * and the active list alone would no longer name.
     *
     * @param array<Participant> $active
     * @param array<RoundPairing> $rounds
     * @param list<Result> $results
     * @param list<array{adjustment: Adjustment, participant: Participant}> $adjustments
     * @param list<Participant> $entrants The entrant list: the active participants and, in their
     *                                    places, those who withdrew from the live stage
     * @param list<Withdrawal> $withdrawals The withdrawals whose round is known, in their order
     *
     * @throws InvalidConfigurationException When the history is one the verbs refuse
     */
    private static function recorded(
        array $active,
        array $rounds,
        array $results,
        ?string $fingerprint,
        array $adjustments,
        array $entrants,
        array $withdrawals = []
    ): self {
        $state = new self($active, entrants: $entrants);

        $next = 0;
        $count = count($results);
        foreach ($rounds as $pairing) {
            $ofRound = [];
            while ($next < $count && $results[$next]->getEvent()->getRound()?->getNumber() === $pairing->getRoundNumber()) {
                $ofRound[] = $results[$next];
                ++$next;
            }

            $state = $state->withRoundPlayed($pairing, $ofRound);
        }

        if ($next < $count) {
            $resultRound = $results[$next]->getEvent()->getRound()?->getNumber();

            throw new InvalidConfigurationException(
                'Stored result belongs to a round that is not recorded, or does not stand with the results of its round',
                [
                    'result' => $next,
                    'result_round' => $resultRound,
                    'recorded_rounds' => array_map(
                        fn(RoundPairing $pairing): int => $pairing->getRoundNumber(),
                        array_values($rounds)
                    ),
                ],
                reason: $resultRound === null
                    ? InvalidConfigurationReason::EventWithoutRoundNumber
                    : InvalidConfigurationReason::EventNotInRound
            );
        }

        foreach ($adjustments as ['adjustment' => $adjustment, 'participant' => $participant]) {
            $state = $state->adjustedBy($adjustment, $participant);
        }

        return new self(
            $state->participants,
            $state->roundsPlayed,
            $state->results,
            $fingerprint,
            $state->adjustments,
            $state->adjustedParticipants,
            $state->entrants,
            $withdrawals
        );
    }
}
