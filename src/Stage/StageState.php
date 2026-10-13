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
 * state between rounds instead of re-deriving it.
 *
 * The driver loop every platform writes:
 *
 *     $state = StageState::start($participants);
 *     while (!$engine->isComplete($state)) {
 *         $pairing = $engine->pairNextRound($state);
 *         $results = playRound($pairing);              // application-side
 *         $state = $state->withRoundPlayed($pairing, $results);
 *     }
 *     $outcome = $engine->getOutcome($state);
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
     */
    private function __construct(
        private array $participants,
        private array $roundsPlayed = [],
        private array $results = [],
        private ?string $engineFingerprint = null,
        private array $adjustments = [],
        private array $adjustedParticipants = []
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

        return new self($participants);
    }

    /**
     * Record a completed round: its pairing (including any byes it awarded)
     * and the results of its events.
     *
     * Recording no results is legal — the pairing's events still count as
     * played, which is what whole-schedule generation without outcomes
     * relies on. Rounds are recorded in play order: a round number is 1 or
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

        return new self(
            $this->participants,
            [...$this->roundsPlayed, $pairing],
            [...$this->results, ...array_values($results)],
            $this->engineFingerprint,
            $this->adjustments,
            $this->adjustedParticipants
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

        $this->assertResultsBelongTo($lastRound, $results);

        return new self(
            $this->participants,
            $this->roundsPlayed,
            [...$this->results, ...array_values($results)],
            $this->engineFingerprint,
            $this->adjustments,
            $this->adjustedParticipants
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
            $this->adjustedParticipants
        );
    }

    /**
     * Withdraw a participant: they leave the active list; their recorded
     * pairings and results remain and still count toward standings.
     *
     * The participant is found by ID. Withdrawing one that is not active
     * changes nothing and is not an error. There is no verb that puts a
     * withdrawn participant back.
     */
    public function withoutParticipant(Participant $participant): self
    {
        return new self(
            array_values(array_filter(
                $this->participants,
                fn(Participant $active) => $active->getId() !== $participant->getId()
            )),
            $this->roundsPlayed,
            $this->results,
            $this->engineFingerprint,
            $this->adjustments,
            $this->adjustedParticipants
        );
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
     * recorded before: a Swiss round is paired once and is a fact once
     * recorded. An elimination bracket that re-seeds each round is the
     * exception. It ranks the survivors of every round again on every
     * call, with every adjustment the state holds, so one recorded after
     * its second round was paired can change a round already played, and
     * the engine then refuses the state (`RecordedRoundMismatch`). Record
     * the adjustments of such a bracket before its second round is paired.
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
     * @throws InvalidInputException When the adjustment names a participant the stage has not seen
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
            $this->adjustedParticipants + [$participant->getId() => $participant]
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
            $this->adjustedParticipants
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
     * result carries an `award` key only when it is awarded.
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
     * @return array{format_version: int, participants: array<int, array{id: string, label: string, seed: int|null, metadata: array<string, mixed>}>, active: array<string>, rounds: array<int, array{round: int, label: string|null, events: array<int, array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}>, byes: array<string>}>, results: array<int, array{event: array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}, winner: string|null, scores: array<int|string, int|float>, metadata: array<string, mixed>, award?: array{label: string|null, reason: string|null}}>, engine_fingerprint?: string, adjustments?: list<array{participant: string, amount: float, label: string|null, reason: string|null}>}
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
     * registry. So is `format_version`: data without it is read as
     * version 1, the form the releases before 0.3.0 wrote. A version this
     * release does not know is refused.
     *
     * The shape is checked, that the data holds no key but the version and
     * the six toArray() writes, that every ID resolves in the `participants`
     * registry, and that no ID is listed twice in `participants` or in
     * `active`.
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
            ['participants', 'active', 'rounds', 'results', 'engine_fingerprint', 'adjustments'],
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

        return self::recorded($active, $rounds, $results, $fingerprint, $adjustments);
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
     *
     * @throws InvalidConfigurationException When the history is one the verbs refuse
     */
    private static function recorded(
        array $active,
        array $rounds,
        array $results,
        ?string $fingerprint,
        array $adjustments = []
    ): self {
        $state = new self($active);

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
            $state->adjustedParticipants
        );
    }
}
