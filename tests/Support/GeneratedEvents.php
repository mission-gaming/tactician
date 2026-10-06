<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use Random\Randomizer;

/**
 * Event lists for the tests that hold an index of events to a scan of them.
 *
 * The lists are deliberately not what a scheduler produces: rounds out of
 * order and missing, events of three participants, a participant listed
 * twice, a participant from outside the field, and keys that are not 0, 1,
 * 2, ... An index that is right only for a tidy schedule is not right.
 */
final class GeneratedEvents
{
    /**
     * A field whose ids include ones PHP would treat as equal numbers or
     * turn into integer array keys.
     *
     * @return list<Participant>
     */
    public static function field(int $size): array
    {
        $ids = ['p1', '1', '01', '1e3', '1000', 'a|b', 'a\\', '0', 'P1', '10', '9', 'x y'];

        $participants = [];
        for ($i = 0; $i < $size; ++$i) {
            $participants[] = new Participant($ids[$i] ?? 'q' . $i, 'Participant ' . ($i + 1), $i + 1);
        }

        return $participants;
    }

    /**
     * @param list<Participant> $field
     * @return list<Event>
     */
    public static function events(Randomizer $randomizer, array $field, int $count, int $maxRound): array
    {
        $outsider = new Participant('outsider', 'Outsider');

        $events = [];
        for ($i = 0; $i < $count; ++$i) {
            $size = $randomizer->getInt(0, 7) === 0 ? 3 : 2;
            $members = [];
            for ($member = 0; $member < $size; ++$member) {
                $members[] = $randomizer->getInt(0, 24) === 0
                    ? $outsider
                    : $field[$randomizer->getInt(0, count($field) - 1)];
            }
            $round = $randomizer->getInt(0, 7) === 0 ? null : new Round($randomizer->getInt(1, $maxRound));
            $events[] = new Event($members, $round);
        }

        return $events;
    }

    /**
     * The same events under keys of another shape: spaced integers, mixed
     * string and integer keys, reversed integers, or left as a list.
     *
     * @param list<Event> $events
     * @return array<array-key, Event>
     */
    public static function rekeyed(Randomizer $randomizer, array $events): array
    {
        $shape = $randomizer->getInt(0, 4);
        if ($shape === 0) {
            $spaced = [];
            foreach ($events as $key => $event) {
                $spaced[$key * 2 + 5] = $event;
            }

            return $spaced;
        }
        if ($shape === 1) {
            $mixed = [];
            foreach ($events as $key => $event) {
                $mixed[$key % 3 === 0 ? 's' . $key : $key] = $event;
            }

            return $mixed;
        }
        if ($shape === 2) {
            return array_reverse($events, true);
        }

        return $events;
    }
}
