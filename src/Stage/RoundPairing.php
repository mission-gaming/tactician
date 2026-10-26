<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Serialization\PlainData;

/**
 * The pairings a results-driven engine produces for a single round.
 *
 * One value object for every format: Swiss rounds carry no label, bracket
 * rounds label themselves ('semifinal', 'losers round 2'), and byes are a
 * list because brackets can award several in one round while Swiss awards
 * at most one.
 *
 * @experimental
 */
final readonly class RoundPairing
{
    private ?string $label;

    private ?RoundLabel $roundLabel;

    private bool $pairedOnIncompleteResults;

    /** @var list<Event> */
    private array $unresolvedWhenPaired;

    /**
     * Nothing is checked here. StageState::withRoundPlayed() checks, when
     * the pairing is recorded, that every event carries this round
     * number, and that every event of $unresolvedWhenPaired is an event of
     * a round recorded before it.
     *
     * @param int $roundNumber 1-based round number
     * @param RoundLabel|string|null $label The round label: as typed data, which the elimination
     *                                      engines give (RoundLabel::ofBracketRound()), or as
     *                                      text ('semifinal', 'Matchday 1'), which is read by
     *                                      RoundLabel::fromString(); null for Swiss
     * @param array<Event> $events The paired events for this round
     * @param array<Participant> $byes Participants advancing or sitting out without playing
     * @param bool $pairedOnIncompleteResults Whether the round was paired, on purpose, while an
     *                                        event of an earlier round still awaited its result
     * @param array<Event> $unresolvedWhenPaired The events of earlier rounds that awaited a
     *                                           result when the round was paired (see
     *                                           getUnresolvedWhenPaired()); a list that is not
     *                                           empty marks the round whatever
     *                                           $pairedOnIncompleteResults says
     */
    public function __construct(
        private int $roundNumber,
        RoundLabel|string|null $label,
        private array $events,
        private array $byes = [],
        bool $pairedOnIncompleteResults = false,
        array $unresolvedWhenPaired = []
    ) {
        $this->unresolvedWhenPaired = array_values($unresolvedWhenPaired);
        $this->pairedOnIncompleteResults = $pairedOnIncompleteResults || $this->unresolvedWhenPaired !== [];

        if ($label instanceof RoundLabel) {
            $this->label = $label->toString();
            $this->roundLabel = $label;
        } else {
            $this->label = $label;
            $this->roundLabel = $label === null ? null : RoundLabel::fromString($label);
        }
    }

    /**
     * Whether the round was paired while an event of an earlier round
     * still awaited its result.
     *
     * An engine pairs such a round only when it is asked to in so many
     * words (SwissPairingEngine::pairNextRoundOnIncompleteResults()), and
     * marks the pairing it returns. The mark is recorded and stored with
     * the round, so that the table the round was paired from can be told
     * from the table the results give once they are all in. An application
     * that rebuilds its rounds from its own rows keeps the mark with them
     * and passes it here, with the events getUnresolvedWhenPaired() lists.
     */
    public function isPairedOnIncompleteResults(): bool
    {
        return $this->pairedOnIncompleteResults;
    }

    /**
     * The events of earlier rounds that awaited a result when the round
     * was paired, in the order given (an engine gives them in play order);
     * empty for a round that was not paired early.
     *
     * The engine that pairs a round early lists them with the mark
     * (isPairedOnIncompleteResults()). They are what tells the table the
     * round was paired from: the results of the rounds before it, without
     * the results of these events, which may have arrived since. The Swiss
     * engine reads the round it would have paired there from that table
     * (RecordedRound::getDerivation()), so a round it paired early is not
     * reported as manual once the late results are in. A marked round that
     * lists no event says that a result was missing and not which: the
     * Swiss engine has no derivation for it. The round-robin engine pairs
     * the same round whatever the results, and reads neither.
     *
     * The events are the events of the earlier rounds, which
     * StageState::withRoundPlayed() checks when the round is recorded;
     * whether they have a result by then is not checked, since one may
     * have arrived between the pairing and the recording.
     *
     * @return list<Event>
     */
    public function getUnresolvedWhenPaired(): array
    {
        return $this->unresolvedWhenPaired;
    }

    /**
     * The 1-based number of the round, which each of its events carries
     * too.
     */
    public function getRoundNumber(): int
    {
        return $this->roundNumber;
    }

    /**
     * Human-readable round label ('semifinal', 'losers round 2'); null for
     * formats whose rounds have no names (Swiss). For a label given as a
     * RoundLabel, its toString().
     */
    public function getLabel(): ?string
    {
        return $this->label;
    }

    /**
     * The label as typed data: its kind, and the number it names where it
     * names one. An elimination engine gives it from the place of the round
     * in the bracket, the label of the same round of the bracket view
     * (BracketRound::getRoundLabel()); a label given as text, as fromArray()
     * gives it, is read by RoundLabel::fromString(), which reads every label
     * an engine writes back to the one it was. Null for a round without a
     * label, and for text the library does not write (a label an
     * application gave a pairing of its own); getLabel() has the text.
     */
    public function getRoundLabel(): ?RoundLabel
    {
        return $this->roundLabel;
    }

    /**
     * The events to play, in the engine's order: bracket order for a
     * bracket, where that order is the path through it. A two-legged tie
     * is two events here, with the two participants in opposite roles.
     *
     * @return array<Event>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * The participants who are in the round and play no event in it: in
     * a bracket they go on to the next round, in a Swiss round the one
     * participant sits out. Empty when there are none.
     *
     * @return array<Participant>
     */
    public function getByes(): array
    {
        return $this->byes;
    }

    /**
     * Whether any participant has a bye in this round.
     */
    public function hasByes(): bool
    {
        return $this->byes !== [];
    }

    /**
     * Convert this pairing to a serializable array.
     *
     * Events and byes reference participants by ID; pair with a participant
     * registry to rehydrate. The first key is `format_version`, the
     * version of this form (1); the events are written without one. The
     * key `paired_on_incomplete_results` is written, as true, only for a
     * round that carries that mark (see isPairedOnIncompleteResults()), and
     * the key `unresolved_when_paired`, the events getUnresolvedWhenPaired()
     * lists in the form of `events`, only for a round that lists one, so
     * every other round has the keys it has always had.
     *
     * @return array{format_version: int, round: int, label: string|null, events: array<int, array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}>, byes: array<string>, paired_on_incomplete_results?: true, unresolved_when_paired?: list<array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}>}
     */
    public function toArray(): array
    {
        $form = static function (Event $event): array {
            $data = $event->toArray();
            unset($data[PlainData::VERSION_KEY]);

            return $data;
        };

        $events = array_map($form, $this->events);

        $data = [
            PlainData::VERSION_KEY => PlainData::VERSION,
            'round' => $this->roundNumber,
            'label' => $this->label,
            'events' => $events,
            'byes' => array_map(fn(Participant $participant) => $participant->getId(), $this->byes),
        ];

        if ($this->pairedOnIncompleteResults) {
            $data['paired_on_incomplete_results'] = true;
        }
        if ($this->unresolvedWhenPaired !== []) {
            $data['unresolved_when_paired'] = array_map($form, $this->unresolvedWhenPaired);
        }

        return $data;
    }

    /**
     * Recreate a pairing from its array representation, as toArray()
     * gives it. `round` is required; `label`, `events`, `byes`,
     * `paired_on_incomplete_results` and `unresolved_when_paired` may be
     * left out. A list under `unresolved_when_paired` that is not empty is
     * refused unless `paired_on_incomplete_results` is true, as toArray()
     * writes it. Any other key is refused.
     *
     * @param array<string, mixed> $data
     * @param array<string, Participant> $participantsById Registry resolving participant IDs
     *
     * @throws InvalidInputException When the format version is not one this release reads,
     *                               a key is unknown, fields are malformed or a
     *                               participant ID is unknown
     */
    public static function fromArray(array $data, array $participantsById): self
    {
        PlainData::checkFields(
            $data,
            ['round', 'label', 'events', 'byes', 'paired_on_incomplete_results', 'unresolved_when_paired'],
            'round pairing data'
        );

        $pairedOnIncompleteResults = $data['paired_on_incomplete_results'] ?? false;
        if (!is_bool($pairedOnIncompleteResults)) {
            throw new InvalidInputException('Round pairing paired_on_incomplete_results must be a boolean');
        }

        $roundNumber = $data['round'] ?? null;
        if (!is_int($roundNumber)) {
            throw new InvalidInputException('Round pairing data requires an integer round number');
        }

        $label = $data['label'] ?? null;
        if ($label !== null && !is_string($label)) {
            throw new InvalidInputException('Round pairing label must be a string or null');
        }

        $events = self::eventsFromArray($data['events'] ?? [], 'events', $participantsById);
        $unresolvedWhenPaired = self::eventsFromArray($data['unresolved_when_paired'] ?? [], 'unresolved_when_paired', $participantsById);
        if ($unresolvedWhenPaired !== [] && !$pairedOnIncompleteResults) {
            throw new InvalidInputException(
                'Round pairing unresolved_when_paired lists the events that awaited a result when the round was paired,'
                    . ' and requires paired_on_incomplete_results to be true'
            );
        }

        $byeIds = $data['byes'] ?? [];
        if (!is_array($byeIds)) {
            throw new InvalidInputException('Round pairing byes must be an array');
        }
        $byes = [];
        foreach ($byeIds as $byeId) {
            if (!is_string($byeId) || !isset($participantsById[$byeId])) {
                throw new InvalidInputException(
                    'Round pairing references unknown bye participant ' . var_export($byeId, true)
                );
            }
            $byes[] = $participantsById[$byeId];
        }

        return new self($roundNumber, $label, $events, $byes, $pairedOnIncompleteResults, $unresolvedWhenPaired);
    }

    /**
     * Read a list of events in the form toArray() writes them.
     *
     * @param array<string, Participant> $participantsById
     *
     * @return list<Event>
     *
     * @throws InvalidInputException When the list or an event of it is malformed
     */
    private static function eventsFromArray(mixed $eventsData, string $key, array $participantsById): array
    {
        if (!is_array($eventsData)) {
            throw new InvalidInputException("Round pairing {$key} must be an array");
        }
        $events = [];
        foreach ($eventsData as $eventData) {
            if (!is_array($eventData)) {
                throw new InvalidInputException("Each round pairing {$key} entry must be an array");
            }
            /** @var array<string, mixed> $eventData */
            $events[] = Event::fromArray($eventData, $participantsById);
        }

        return $events;
    }
}
