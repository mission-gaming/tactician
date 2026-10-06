<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use MissionGaming\Tactician\DTO\Event;

/**
 * Counts roles in a list of events from first principles, for the tests of
 * the role assignments.
 *
 * Nothing here calls the library's own counters (the quality metrics, the
 * role constraints, the role assignments): a test that measured the output
 * with the code that produced it would compare the code with itself.
 *
 * The role of a participant in an event is its position: index 0 is
 * first-named, index 1 is second-named.
 */
final class RoleCounts
{
    /**
     * First-named count minus second-named count of every participant that
     * appears.
     *
     * @param iterable<Event> $events
     * @return array<string, int>
     */
    public static function differences(iterable $events): array
    {
        $differences = [];
        foreach ($events as $event) {
            [$first, $second] = self::ids($event);
            $differences[$first] = ($differences[$first] ?? 0) + 1;
            $differences[$second] = ($differences[$second] ?? 0) - 1;
        }

        return $differences;
    }

    /**
     * The largest distance between the two role counts of one participant
     * once every event is played.
     *
     * @param iterable<Event> $events
     */
    public static function worstEndImbalance(iterable $events): int
    {
        return self::largest(self::differences($events));
    }

    /**
     * The largest distance between the two role counts of one participant
     * at any point while the events are played in the order given.
     *
     * @param iterable<Event> $events
     */
    public static function worstRunningImbalance(iterable $events): int
    {
        $differences = [];
        $worst = 0;
        foreach ($events as $event) {
            [$first, $second] = self::ids($event);
            $differences[$first] = ($differences[$first] ?? 0) + 1;
            $differences[$second] = ($differences[$second] ?? 0) - 1;
            $worst = max($worst, abs($differences[$first]), abs($differences[$second]));
        }

        return $worst;
    }

    /**
     * The longest run of events in a row that one participant plays in the
     * same role. A round the participant sits out does not break a run.
     *
     * @param iterable<Event> $events Events in round order
     */
    public static function worstStreak(iterable $events): int
    {
        $lastRole = [];
        $run = [];
        $worst = 0;
        foreach ($events as $event) {
            foreach (self::ids($event) as $role => $id) {
                $run[$id] = ($lastRole[$id] ?? null) === $role ? ($run[$id] ?? 0) + 1 : 1;
                $lastRole[$id] = $role;
                $worst = max($worst, $run[$id]);
            }
        }

        return $worst;
    }

    /**
     * For every pairing: how many more times its lower-sorting participant
     * is first-named than second-named, as an absolute number. 0 is an even
     * split, and 1 is the closest an odd number of meetings allows.
     *
     * @param iterable<Event> $events
     * @return array<string, int>
     */
    public static function pairingSplits(iterable $events): array
    {
        $splits = [];
        foreach ($events as $event) {
            [$first, $second] = self::ids($event);
            $key = self::pairing($first, $second);
            $splits[$key] = ($splits[$key] ?? 0) + (strcmp($first, $second) < 0 ? 1 : -1);
        }

        return array_map(abs(...), $splits);
    }

    /**
     * Which pairings are played in which round, with the roles left out:
     * two schedules with equal structure differ in nothing but who is
     * first-named.
     *
     * @param iterable<Event> $events
     * @return list<string> One "round: a + b" entry per event, in the order given
     */
    public static function structure(iterable $events): array
    {
        $structure = [];
        foreach ($events as $event) {
            [$first, $second] = self::ids($event);
            $structure[] = ($event->getRound()?->getNumber() ?? 0) . ': ' . self::pairing($first, $second);
        }

        return $structure;
    }

    /**
     * The events of one leg of a round robin.
     *
     * @param iterable<Event> $events
     * @return list<Event>
     */
    public static function leg(iterable $events, int $leg, int $roundsPerLeg): array
    {
        $legEvents = [];
        foreach ($events as $event) {
            $round = $event->getRound()?->getNumber() ?? 0;
            if (intdiv($round - 1, $roundsPerLeg) + 1 === $leg) {
                $legEvents[] = $event;
            }
        }

        return $legEvents;
    }

    /**
     * @param array<int> $differences
     */
    public static function largest(array $differences): int
    {
        return $differences === [] ? 0 : max(array_map(abs(...), $differences));
    }

    /**
     * @return array{0: string, 1: string} The first-named and the second-named participant
     */
    private static function ids(Event $event): array
    {
        $participants = $event->getParticipants();

        return [$participants[0]->getId(), $participants[1]->getId()];
    }

    private static function pairing(string $one, string $other): string
    {
        return strcmp($one, $other) < 0 ? "{$one} + {$other}" : "{$other} + {$one}";
    }
}
