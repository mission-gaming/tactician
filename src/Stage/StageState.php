<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use InvalidArgumentException;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

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
     */
    private function __construct(
        private array $participants,
        private array $roundsPlayed = [],
        private array $results = []
    ) {
    }

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

        $ids = array_map(fn (Participant $participant) => $participant->getId(), $participants);
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

        foreach ($results as $result) {
            $resultRound = $result->getEvent()->getRound()?->getNumber();
            if ($resultRound !== $pairing->getRoundNumber()) {
                throw new InvalidConfigurationException(
                    'Result belongs to a different round than the pairing being recorded',
                    ['pairing_round' => $pairing->getRoundNumber(), 'result_round' => $resultRound]
                );
            }
        }

        return new self(
            $this->participants,
            [...$this->roundsPlayed, $pairing],
            [...$this->results, ...array_values($results)]
        );
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
                fn (Participant $active) => $active->getId() !== $participant->getId()
            )),
            $this->roundsPlayed,
            $this->results
        );
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
     * Convert this state to a serializable array.
     *
     * The participant registry lists every participant seen — active ones
     * plus any withdrawn participants still referenced by recorded rounds
     * or results — so rehydration resolves all references.
     *
     * @return array{participants: array<int, array{id: string, label: string, seed: int|null, metadata: array<string, mixed>}>, active: array<string>, rounds: array<int, array{round: int, label: string|null, events: array<int, array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}>, byes: array<string>}>, results: array<int, array{event: array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}, winner: string|null, scores: array<int|string, int|float>}>}
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

        return [
            'participants' => array_values(array_map(
                fn (Participant $participant) => $participant->toArray(),
                $registry
            )),
            'active' => array_map(
                fn (Participant $participant) => $participant->getId(),
                $this->participants
            ),
            'rounds' => array_map(fn (RoundPairing $pairing) => $pairing->toArray(), $this->roundsPlayed),
            'results' => array_map(fn (Result $result) => $result->toArray(), $this->results),
        ];
    }

    /**
     * Recreate a state from its array representation.
     *
     * @param array<string, mixed> $data
     * @throws InvalidArgumentException When the data is malformed
     */
    public static function fromArray(array $data): self
    {
        $participantsData = $data['participants'] ?? [];
        if (!is_array($participantsData)) {
            throw new InvalidArgumentException('Stage state participants must be an array');
        }

        /** @var array<string, Participant> $registry */
        $registry = [];
        foreach ($participantsData as $participantData) {
            if (!is_array($participantData)) {
                throw new InvalidArgumentException('Each stage state participant must be an array');
            }
            /** @var array<string, mixed> $participantData */
            $participant = Participant::fromArray($participantData);
            if (isset($registry[$participant->getId()])) {
                throw new InvalidArgumentException(
                    "Stage state registry contains participant {$participant->getId()} twice"
                );
            }
            $registry[$participant->getId()] = $participant;
        }

        $activeIds = $data['active'] ?? [];
        if (!is_array($activeIds)) {
            throw new InvalidArgumentException('Stage state active list must be an array');
        }
        $active = [];
        foreach ($activeIds as $activeId) {
            if (!is_string($activeId) || !isset($registry[$activeId])) {
                throw new InvalidArgumentException(
                    'Stage state references unknown active participant ' . var_export($activeId, true)
                );
            }
            $active[] = $registry[$activeId];
        }

        $roundsData = $data['rounds'] ?? [];
        if (!is_array($roundsData)) {
            throw new InvalidArgumentException('Stage state rounds must be an array');
        }
        $rounds = [];
        foreach ($roundsData as $roundData) {
            if (!is_array($roundData)) {
                throw new InvalidArgumentException('Each stage state round must be an array');
            }
            /** @var array<string, mixed> $roundData */
            $rounds[] = RoundPairing::fromArray($roundData, $registry);
        }

        $resultsData = $data['results'] ?? [];
        if (!is_array($resultsData)) {
            throw new InvalidArgumentException('Stage state results must be an array');
        }
        $results = [];
        foreach ($resultsData as $resultData) {
            if (!is_array($resultData)) {
                throw new InvalidArgumentException('Each stage state result must be an array');
            }
            /** @var array<string, mixed> $resultData */
            $results[] = Result::fromArray($resultData, $registry);
        }

        return new self($active, $rounds, $results);
    }

    /**
     * Serialize this state to a JSON string.
     *
     * @throws \JsonException When the state contains values JSON cannot represent
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Recreate a state from its JSON representation.
     *
     * @throws \JsonException When the JSON is malformed
     * @throws InvalidArgumentException When the decoded data is malformed
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Stage state JSON must decode to an array');
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }
}
