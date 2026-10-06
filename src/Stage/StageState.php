<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\JsonConversionException;

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
 */
final readonly class StageState
{
    /**
     * @param array<Participant> $participants Active participants
     * @param array<RoundPairing> $roundsPlayed Pairings recorded so far, in play order
     * @param array<Result> $results Results of every recorded round
     * @param string|null $engineFingerprint The stamp of the engine that pairs this stage, when one was set
     */
    private function __construct(
        private array $participants,
        private array $roundsPlayed = [],
        private array $results = [],
        private ?string $engineFingerprint = null
    ) {}

    /**
     * Begin a stage with the given active participants.
     *
     * Order is authoritative: engines seed and pair from list position.
     *
     * @param array<Participant> $participants
     * @throws InvalidConfigurationException When participant IDs collide
     */
    public static function start(array $participants): self
    {
        $participants = array_values($participants);

        $ids = array_map(fn(Participant $participant) => $participant->getId(), $participants);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidConfigurationException(
                'All participants must have unique IDs',
                ['participant_count' => count($participants), 'unique_ids' => count(array_unique($ids))]
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
     * relies on. Rounds are recorded in play order: each pairing's round
     * number must exceed the last recorded one, and every event and result
     * in the pairing must carry that round number, so the history can
     * never contradict itself.
     *
     * @param array<Result> $results
     * @throws InvalidConfigurationException When the pairing does not follow the recorded rounds,
     *                                       or an event or result belongs to a different round
     */
    public function withRoundPlayed(RoundPairing $pairing, array $results): self
    {
        $lastRound = $this->getLastRound();
        if ($lastRound !== null && $pairing->getRoundNumber() <= $lastRound->getRoundNumber()) {
            throw new InvalidConfigurationException(
                'Rounds must be recorded in play order with increasing round numbers',
                ['last_round' => $lastRound->getRoundNumber(), 'pairing_round' => $pairing->getRoundNumber()]
            );
        }

        foreach ($pairing->getEvents() as $event) {
            $eventRound = $event->getRound()?->getNumber();
            if ($eventRound !== $pairing->getRoundNumber()) {
                throw new InvalidConfigurationException(
                    'Pairing contains an event from a different round',
                    ['pairing_round' => $pairing->getRoundNumber(), 'event_round' => $eventRound]
                );
            }
        }

        $this->assertResultsBelongTo($pairing, $results);

        return new self(
            $this->participants,
            [...$this->roundsPlayed, $pairing],
            [...$this->results, ...array_values($results)],
            $this->engineFingerprint
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
                    ['pairing_round' => $pairing->getRoundNumber(), 'result_round' => $resultRound]
                );
            }

            if (!isset($pairingEventKeys[$this->eventKey($result->getEvent())])) {
                throw new InvalidConfigurationException(
                    'Result references an event that is not part of the pairing being recorded',
                    ['pairing_round' => $pairing->getRoundNumber(), 'event' => $this->eventKey($result->getEvent())]
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
     * @throws InvalidConfigurationException When no round is recorded or a result belongs to a different round
     */
    public function withAdditionalResults(array $results): self
    {
        $lastRound = $this->getLastRound();
        if ($lastRound === null) {
            throw new InvalidConfigurationException(
                'No round has been recorded to add results to',
                []
            );
        }

        $this->assertResultsBelongTo($lastRound, $results);

        return new self(
            $this->participants,
            $this->roundsPlayed,
            [...$this->results, ...array_values($results)],
            $this->engineFingerprint
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
     * @throws InvalidConfigurationException When no round has been recorded, when the event
     *                                       has no recorded result, or when its round is not
     *                                       the last recorded round
     */
    public function withResultReplaced(Result $result): self
    {
        $lastRound = $this->getLastRound();
        if ($lastRound === null) {
            // The errors of this method and of the fingerprint state no
            // reason, like the others of this class, and name an empty list
            // of requirements: without it their report would end with the
            // round-robin requirements, which do not describe them.
            throw new InvalidConfigurationException(
                'No round has been recorded to replace a result in',
                [],
                requirements: []
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
                requirements: []
            );
        }

        if ($round !== $lastRound->getRoundNumber()) {
            throw new InvalidConfigurationException(
                "A result of round {$round} cannot be replaced: round {$lastRound->getRoundNumber()} was paired from the"
                    . " results of round {$round}. Rebuild the state up to round {$round} with the corrected result"
                    . ' (StageState::start(), then withRoundPlayed() for each round that stands) and pair again.',
                ['round' => $round, 'last_round' => $lastRound->getRoundNumber()],
                requirements: []
            );
        }

        return new self($this->participants, $this->roundsPlayed, $results, $this->engineFingerprint);
    }

    /**
     * Withdraw a participant: they leave the active list; their recorded
     * pairings and results remain and still count toward standings.
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
            $this->engineFingerprint
        );
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
     * FingerprintedEngine for what a fingerprint covers: an option at its
     * default is not part of it, so an engine that gains an option still
     * accepts the states stamped before). Their fingerprints begin with
     * `tactician:`; any non-empty string that does not will do for an
     * engine of your own.
     *
     * @throws InvalidConfigurationException When the fingerprint is an empty string
     */
    public function withEngineFingerprint(?string $fingerprint): self
    {
        if ($fingerprint === '') {
            throw new InvalidConfigurationException('An engine fingerprint cannot be empty', [], requirements: []);
        }

        return new self($this->participants, $this->roundsPlayed, $this->results, $fingerprint);
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
                requirements: []
            );
        }
    }

    /**
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
     * The 1-based number the next round should carry.
     */
    public function getNextRoundNumber(): int
    {
        $lastRound = $this->getLastRound();

        return $lastRound === null ? 1 : $lastRound->getRoundNumber() + 1;
    }

    /**
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
     * Bye counts keyed by participant ID.
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
     * participants still referenced by recorded rounds (events or byes) or
     * results. This is what plans and outcome standings cover — a
     * withdrawn participant's played games remain part of the record.
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

        return $participants;
    }

    /**
     * Convert this state to a serializable array.
     *
     * The participant registry lists every participant seen — active ones
     * plus any withdrawn participants still referenced by recorded rounds
     * or results — so rehydration resolves all references.
     *
     * The `engine_fingerprint` key is present only when the state is
     * stamped (see withEngineFingerprint()), so an unstamped state has the
     * shape it has always had.
     *
     * @return array{participants: array<int, array{id: string, label: string, seed: int|null, metadata: array<string, mixed>}>, active: array<string>, rounds: array<int, array{round: int, label: string|null, events: array<int, array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}>, byes: array<string>}>, results: array<int, array{event: array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}, winner: string|null, scores: array<int|string, int|float>}>, engine_fingerprint?: string}
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

        $data = [
            'participants' => array_values(array_map(
                fn(Participant $participant) => $participant->toArray(),
                $registry
            )),
            'active' => array_map(
                fn(Participant $participant) => $participant->getId(),
                $this->participants
            ),
            'rounds' => array_map(fn(RoundPairing $pairing) => $pairing->toArray(), $this->roundsPlayed),
            'results' => array_map(fn(Result $result) => $result->toArray(), $this->results),
        ];

        if ($this->engineFingerprint !== null) {
            $data['engine_fingerprint'] = $this->engineFingerprint;
        }

        return $data;
    }

    /**
     * Recreate a state from its array representation.
     *
     * The `engine_fingerprint` key is optional: data stored before the
     * stamp existed, or by a caller that does not stamp, loads as an
     * unstamped state.
     *
     * @param array<string, mixed> $data
     * @throws InvalidInputException When the data is malformed
     */
    public static function fromArray(array $data): self
    {
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
            $active[] = $registry[$activeId];
        }

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

        return new self($active, $rounds, $results, $fingerprint);
    }

    /**
     * Serialize this state to a JSON string.
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
     * Recreate a state from its JSON representation.
     *
     * @throws JsonConversionException When the JSON is malformed
     * @throws InvalidInputException When the decoded data is malformed
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
}
