<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Repack\RepackRequest;

/**
 * The figures of a repack outcome, counted from their definitions: the
 * reference the tests hold `RepackOutcome` to.
 *
 * Each figure is written as its definition reads, one participant and one
 * session at a time, from the request and the assignments and from nothing
 * else: it shares no code with `Repack\Internal\PlacementMetrics`, and it
 * reads no violation, so it also checks the audit's count of gap slots. It
 * is slow on purpose (every question goes through every event again).
 */
final class RepackFigures
{
    /**
     * @return array{unplaced: int, gap_slots: int, lone_event_sessions: list<int>, idle_sessions: int, moved: list<string>}
     */
    public static function of(RepackRequest $request, RepackOutcome $outcome): array
    {
        return [
            'unplaced' => self::unplaced($request, $outcome),
            'gap_slots' => self::gapSlots($request, $outcome),
            'lone_event_sessions' => self::loneEventSessions($request, $outcome),
            'idle_sessions' => self::idleSessions($request, $outcome),
            'moved' => self::moved($request, $outcome),
        ];
    }

    /**
     * The movable events with no assignment.
     */
    public static function unplaced(RepackRequest $request, RepackOutcome $outcome): int
    {
        $count = 0;
        foreach ($request->getMovableEvents() as $event) {
            if ($outcome->getAssignmentFor($event->getId()) === null) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * For every participant and session in which the participant has a
     * placed movable event: the slots between its first and its last
     * there, pinned events included, that hold none of its events.
     */
    public static function gapSlots(RepackRequest $request, RepackOutcome $outcome): int
    {
        $gapSlots = 0;
        foreach (self::participantIds($request) as $id) {
            for ($session = 0; $session < $request->getGrid()->getSessionCount(); ++$session) {
                $movable = self::slotsOf($request, $outcome, $id, $session, false);
                if ($movable === []) {
                    continue;
                }
                $all = self::slotsOf($request, $outcome, $id, $session, true);
                $first = PHP_INT_MAX;
                $last = -1;
                foreach ($all as $slot) {
                    $first = min($first, $slot);
                    $last = max($last, $slot);
                }
                for ($slot = $first; $slot <= $last; ++$slot) {
                    if (!in_array($slot, $all, true)) {
                        ++$gapSlots;
                    }
                }
            }
        }

        return $gapSlots;
    }

    /**
     * The sessions that hold one event, pinned or movable, and that one
     * a movable event.
     *
     * @return list<int>
     */
    public static function loneEventSessions(RepackRequest $request, RepackOutcome $outcome): array
    {
        $lone = [];
        for ($session = 0; $session < $request->getGrid()->getSessionCount(); ++$session) {
            $movable = 0;
            foreach ($outcome->getAssignments() as $assignment) {
                if ($assignment->getSession() === $session) {
                    ++$movable;
                }
            }
            $pinned = 0;
            foreach ($request->getPinnedEvents() as $pin) {
                if ($pin->getSession() === $session) {
                    ++$pinned;
                }
            }
            if ($movable === 1 && $pinned === 0) {
                $lone[] = $session;
            }
        }

        return $lone;
    }

    /**
     * For every participant with a placed movable event: the sessions
     * with no event of the participant that have one of its events both
     * before and after them, pinned events included.
     */
    public static function idleSessions(RepackRequest $request, RepackOutcome $outcome): int
    {
        $sessions = $request->getGrid()->getSessionCount();
        $idle = 0;
        foreach (self::participantIds($request) as $id) {
            $placedMovable = false;
            $active = [];
            for ($session = 0; $session < $sessions; ++$session) {
                $placedMovable = $placedMovable || self::slotsOf($request, $outcome, $id, $session, false) !== [];
                $active[$session] = self::slotsOf($request, $outcome, $id, $session, true) !== [];
            }
            if (!$placedMovable) {
                continue;
            }
            for ($session = 0; $session < $sessions; ++$session) {
                if ($active[$session]) {
                    continue;
                }
                $earlier = in_array(true, array_slice($active, 0, $session), true);
                $later = in_array(true, array_slice($active, $session + 1), true);
                if ($earlier && $later) {
                    ++$idle;
                }
            }
        }

        return $idle;
    }

    /**
     * The ids of the placed events that state a current position and are
     * at another one, ascending.
     *
     * @return list<string>
     */
    public static function moved(RepackRequest $request, RepackOutcome $outcome): array
    {
        $moved = [];
        foreach ($request->getMovableEvents() as $event) {
            $assignment = $outcome->getAssignmentFor($event->getId());
            if (!$event->hasCurrentPosition() || $assignment === null) {
                continue;
            }
            if ($assignment->getSession() !== $event->getCurrentSession() || $assignment->getSlot() !== $event->getCurrentSlot()) {
                $moved[] = $event->getId();
            }
        }
        sort($moved, SORT_STRING);

        return $moved;
    }

    /**
     * The slots a participant holds in a session: by its placed movable
     * events, and by its pinned ones as well when asked.
     *
     * @return list<int>
     */
    private static function slotsOf(RepackRequest $request, RepackOutcome $outcome, string $id, int $session, bool $withPins): array
    {
        $slots = [];
        foreach ($request->getMovableEvents() as $event) {
            $assignment = $outcome->getAssignmentFor($event->getId());
            if ($assignment === null || $assignment->getSession() !== $session) {
                continue;
            }
            foreach ($event->getParticipants() as $participant) {
                if ($participant->getId() === $id) {
                    $slots[] = $assignment->getSlot();
                }
            }
        }
        if ($withPins) {
            foreach ($request->getPinnedEvents() as $pin) {
                if ($pin->getSession() !== $session) {
                    continue;
                }
                foreach ($pin->getParticipants() as $participant) {
                    if ($participant->getId() === $id) {
                        $slots[] = $pin->getSlot();
                    }
                }
            }
        }

        return array_values(array_unique($slots));
    }

    /**
     * @return list<string>
     */
    private static function participantIds(RepackRequest $request): array
    {
        $ids = [];
        foreach ([...$request->getMovableEvents(), ...$request->getPinnedEvents()] as $event) {
            foreach ($event->getParticipants() as $participant) {
                $ids[$participant->getId()] = true;
            }
        }

        return array_map(strval(...), array_keys($ids));
    }
}
