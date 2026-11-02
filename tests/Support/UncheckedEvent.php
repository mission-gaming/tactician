<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use ReflectionClass;
use ReflectionProperty;

/**
 * Builds an event that holds whatever participants it was given, one of
 * them listed twice included.
 *
 * `Event` refuses a participant paired with itself when it is constructed,
 * and it is final, so no event of the library's API holds one. The plans,
 * the event index and a constraint still guard against such an event, and
 * their tests need one to show the guard. This class makes it the only way
 * left: an Event created without its constructor, whose properties are set
 * through reflection. Before Event was final, the same tests used a
 * subclass that overrode the readers of the participants.
 */
final class UncheckedEvent
{
    /**
     * An Event that holds the given participants, unchecked.
     *
     * @param array<Participant> $listed What getParticipants() returns
     * @param array<string, mixed> $metadata
     */
    public static function of(array $listed, ?Round $round = null, array $metadata = []): Event
    {
        $event = new ReflectionClass(Event::class)->newInstanceWithoutConstructor();
        foreach (['participants' => $listed, 'round' => $round, 'metadata' => $metadata] as $property => $value) {
            new ReflectionProperty(Event::class, $property)->setValue($event, $value);
        }

        return $event;
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
            : self::of($participants, $round);
    }
}
