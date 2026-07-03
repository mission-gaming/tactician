<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use InvalidArgumentException;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;

/**
 * The pairings a results-driven engine produces for a single round.
 *
 * One value object for every format: Swiss rounds carry no label, bracket
 * rounds label themselves ('semifinal', 'losers round 2'), and byes are a
 * list because brackets can award several in one round while Swiss awards
 * at most one.
 */
final readonly class RoundPairing
{
    /**
     * @param int $roundNumber 1-based round number
     * @param string|null $label Human-readable round label ('semifinal', 'losers round 2'); null for Swiss
     * @param array<Event> $events The paired events for this round
     * @param array<Participant> $byes Participants advancing or sitting out without playing
     */
    public function __construct(
        private int $roundNumber,
        private ?string $label,
        private array $events,
        private array $byes = []
    ) {
    }

    public function getRoundNumber(): int
    {
        return $this->roundNumber;
    }

    /**
     * Human-readable round label ('semifinal', 'losers round 2'); null for
     * formats whose rounds have no names (Swiss).
     */
    public function getLabel(): ?string
    {
        return $this->label;
    }

    /**
     * @return array<Event>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * @return array<Participant>
     */
    public function getByes(): array
    {
        return $this->byes;
    }

    public function hasByes(): bool
    {
        return $this->byes !== [];
    }

    /**
     * Convert this pairing to a serializable array.
     *
     * Events and byes reference participants by ID; pair with a participant
     * registry to rehydrate.
     *
     * @return array{round: int, label: string|null, events: array<int, array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}>, byes: array<string>}
     */
    public function toArray(): array
    {
        return [
            'round' => $this->roundNumber,
            'label' => $this->label,
            'events' => array_map(fn (Event $event) => $event->toArray(), $this->events),
            'byes' => array_map(fn (Participant $participant) => $participant->getId(), $this->byes),
        ];
    }

    /**
     * Recreate a pairing from its array representation.
     *
     * @param array<string, mixed> $data
     * @param array<string, Participant> $participantsById Registry resolving participant IDs
     *
     * @throws InvalidArgumentException When fields are malformed or a participant ID is unknown
     */
    public static function fromArray(array $data, array $participantsById): self
    {
        $roundNumber = $data['round'] ?? null;
        if (!is_int($roundNumber)) {
            throw new InvalidArgumentException('Round pairing data requires an integer round number');
        }

        $label = $data['label'] ?? null;
        if ($label !== null && !is_string($label)) {
            throw new InvalidArgumentException('Round pairing label must be a string or null');
        }

        $eventsData = $data['events'] ?? [];
        if (!is_array($eventsData)) {
            throw new InvalidArgumentException('Round pairing events must be an array');
        }
        $events = [];
        foreach ($eventsData as $eventData) {
            if (!is_array($eventData)) {
                throw new InvalidArgumentException('Each round pairing event must be an array');
            }
            /** @var array<string, mixed> $eventData */
            $events[] = Event::fromArray($eventData, $participantsById);
        }

        $byeIds = $data['byes'] ?? [];
        if (!is_array($byeIds)) {
            throw new InvalidArgumentException('Round pairing byes must be an array');
        }
        $byes = [];
        foreach ($byeIds as $byeId) {
            if (!is_string($byeId) || !isset($participantsById[$byeId])) {
                throw new InvalidArgumentException(
                    'Round pairing references unknown bye participant ' . var_export($byeId, true)
                );
            }
            $byes[] = $participantsById[$byeId];
        }

        return new self($roundNumber, $label, $events, $byes);
    }
}
