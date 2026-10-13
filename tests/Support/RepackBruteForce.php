<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\SessionGrid;

/**
 * The most movable events any placement of a repack request can hold,
 * found by trying every placement: a reference written apart from the
 * repacker, for instances small enough to search exhaustively.
 *
 * A placement gives each movable event one grid position or none. It is
 * legal when no participant is at one position twice, counting pins, and
 * no position holds more events than the grid's capacity, counting pins.
 * Contiguity, late starts and the weights play no part: this counts
 * events and nothing else.
 *
 * The search takes the events one at a time, tries each legal position
 * and then no position, and stops a branch that can no longer beat the
 * best placement found. Its cost grows with (positions + 1) to the power
 * of the event count, so it is for a dozen events at most.
 */
final class RepackBruteForce
{
    /** @var list<array{string, string}> */
    private array $events = [];

    /** @var list<array{int, int}> */
    private array $positions = [];

    /** @var array<int, int> Position index => places left */
    private array $room = [];

    /** @var array<int, array<string, true>> Position index => participants there */
    private array $busy = [];

    private int $best = 0;

    /**
     * @param array<MovableEvent> $movable
     * @param array<PinnedEvent> $pinned
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException
     *         Never in practice: only positions the grid has are asked for
     */
    public static function maxPlaced(array $movable, array $pinned, SessionGrid $grid): int
    {
        $search = new self();
        $capacity = $grid->getCapacityLimit() ?? count($movable) + count($pinned);

        foreach ($movable as $event) {
            $search->events[] = [$event->getParticipantA()->getId(), $event->getParticipantB()->getId()];
        }

        $indexOf = [];
        for ($session = 0; $session < $grid->getSessionCount(); ++$session) {
            for ($slot = 0; $slot < $grid->getSlotCount($session); ++$slot) {
                $indexOf["{$session}:{$slot}"] = count($search->positions);
                $search->positions[] = [$session, $slot];
                $search->room[] = $capacity;
                $search->busy[] = [];
            }
        }
        foreach ($pinned as $pin) {
            $index = $indexOf["{$pin->getSession()}:{$pin->getSlot()}"];
            --$search->room[$index];
            foreach ($pin->getParticipants() as $participant) {
                $search->busy[$index][$participant->getId()] = true;
            }
        }

        $search->best = 0;
        $search->search(0, 0);

        return $search->best;
    }

    /**
     * The fewest gap slots any placement of the most events can have:
     * the reference for "no avoidable gap". Every legal placement of
     * maxPlaced() events is tried, and its gap slots are counted as the
     * repacker's audit counts them: for each participant and session in
     * which it has a movable event, the slots between its first and its
     * last, pinned ones included, that it does not play in.
     *
     * Written apart from the repacker's own search: no bound on gaps cuts
     * a branch, only the count of events, and it stops early only at a
     * placement with no gap at all.
     *
     * @param array<MovableEvent> $movable
     * @param array<PinnedEvent> $pinned
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException
     *         Never in practice: only positions the grid has are asked for
     */
    public static function fewestGapSlots(array $movable, array $pinned, SessionGrid $grid): int
    {
        $most = self::maxPlaced($movable, $pinned, $grid);

        $events = [];
        foreach ($movable as $event) {
            $events[] = [$event->getParticipantA()->getId(), $event->getParticipantB()->getId()];
        }
        $capacity = $grid->getCapacityLimit() ?? count($movable) + count($pinned);
        $positions = [];
        $room = [];
        for ($session = 0; $session < $grid->getSessionCount(); ++$session) {
            for ($slot = 0; $slot < $grid->getSlotCount($session); ++$slot) {
                $positions[] = [$session, $slot];
                $room["{$session}:{$slot}"] = $capacity;
            }
        }
        /** @var array<string, array<int, array<int, int>>> $held Participant => session => slot => events there */
        $held = [];
        /** @var array<string, array<int, int>> $movableIn Participant => session => movable events */
        $movableIn = [];
        foreach ($pinned as $pin) {
            --$room["{$pin->getSession()}:{$pin->getSlot()}"];
            foreach ($pin->getParticipants() as $participant) {
                $held[$participant->getId()][$pin->getSession()][$pin->getSlot()] = 1;
            }
        }

        $fewest = PHP_INT_MAX;
        $search = static function (int $next, int $placed) use (&$search, &$fewest, &$held, &$movableIn, &$room, $events, $positions, $most): void {
            if ($fewest === 0 || $placed + count($events) - $next < $most) {
                return;
            }
            if ($next === count($events)) {
                $gapSlots = 0;
                foreach ($movableIn as $id => $bySession) {
                    foreach ($bySession as $session => $count) {
                        if ($count < 1) {
                            continue;
                        }
                        $first = PHP_INT_MAX;
                        $last = 0;
                        $played = 0;
                        foreach ($held[$id][$session] as $slot => $eventsThere) {
                            if ($eventsThere > 0) {
                                $first = min($first, $slot);
                                $last = max($last, $slot);
                                ++$played;
                            }
                        }
                        $gapSlots += $last - $first + 1 - $played;
                    }
                }
                $fewest = min($fewest, $gapSlots);

                return;
            }

            [$a, $b] = $events[$next];
            foreach ($positions as [$session, $slot]) {
                if (
                    $room["{$session}:{$slot}"] < 1
                    || ($held[$a][$session][$slot] ?? 0) > 0
                    || ($held[$b][$session][$slot] ?? 0) > 0
                ) {
                    continue;
                }
                --$room["{$session}:{$slot}"];
                foreach ([$a, $b] as $id) {
                    $held[$id][$session][$slot] = 1;
                    $movableIn[$id][$session] = ($movableIn[$id][$session] ?? 0) + 1;
                }
                $search($next + 1, $placed + 1);
                ++$room["{$session}:{$slot}"];
                foreach ([$a, $b] as $id) {
                    $held[$id][$session][$slot] = 0;
                    --$movableIn[$id][$session];
                }
            }
            $search($next + 1, $placed);
        };
        $search(0, 0);

        return $fewest;
    }

    private function search(int $next, int $placed): void
    {
        $left = count($this->events) - $next;
        if ($placed + $left <= $this->best) {
            return;
        }
        if ($left === 0) {
            $this->best = $placed;

            return;
        }

        [$a, $b] = $this->events[$next];
        foreach (array_keys($this->positions) as $index) {
            if ($this->room[$index] < 1 || isset($this->busy[$index][$a]) || isset($this->busy[$index][$b])) {
                continue;
            }
            --$this->room[$index];
            $this->busy[$index][$a] = true;
            $this->busy[$index][$b] = true;
            $this->search($next + 1, $placed + 1);
            ++$this->room[$index];
            unset($this->busy[$index][$a], $this->busy[$index][$b]);

            if ($this->best === count($this->events)) {
                return;
            }
        }

        $this->search($next + 1, $placed);
    }
}
