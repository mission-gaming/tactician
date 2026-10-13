<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use Override;

/**
 * An event that answers with whatever participants it was given, one of
 * them listed twice included.
 *
 * `Event` refuses a participant paired with itself when it is constructed.
 * The plans, the event index and a constraint still guard against such an
 * event, because `Event` is not final and an event of another class can
 * answer `getParticipants()` with anything. This class is that other class:
 * the tests of those guards use it where they used `new Event([$a, $a])`
 * before the constructor checked.
 *
 * The parent is constructed with two placeholders, which it requires, and
 * every reader of the participants is overridden to return the list given
 * here. The round and the metadata are the parent's.
 */
readonly class UncheckedEvent extends Event
{
    /**
     * @param array<Participant> $listed What getParticipants() returns, unchecked
     * @param array<string, mixed> $metadata
     *
     * @throws InvalidInputException Never: the placeholders differ
     */
    public function __construct(
        private array $listed,
        ?Round $round = null,
        array $metadata = []
    ) {
        parent::__construct(
            [new Participant('unchecked-event-first', 'First'), new Participant('unchecked-event-second', 'Second')],
            $round,
            $metadata
        );
    }

    /**
     * A plain event when the participants differ from each other, and an
     * unchecked one when an ID is listed twice: what a generator of random
     * events calls, so that the degenerate case keeps being generated.
     *
     * @param array<Participant> $participants
     *
     * @throws InvalidInputException When fewer than 2 participants are given
     */
    public static function orEvent(array $participants, ?Round $round = null): Event
    {
        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $participants);

        return count($ids) === count(array_unique($ids))
            ? new Event($participants, $round)
            : new self($participants, $round);
    }

    /**
     * @return array<Participant>
     */
    #[Override]
    public function getParticipants(): array
    {
        return $this->listed;
    }

    #[Override]
    public function hasParticipant(Participant $participant): bool
    {
        foreach ($this->listed as $listed) {
            if ($listed->getId() === $participant->getId()) {
                return true;
            }
        }

        return false;
    }

    #[Override]
    public function getParticipantCount(): int
    {
        return count($this->listed);
    }
}
