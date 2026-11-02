<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

use MissionGaming\Tactician\Repack\RepackObjective;

/**
 * The repair that follows the choice of a schedule when the options name
 * objectives: single events are moved to a free position where that
 * makes the schedule better by the objectives and worse by nothing that
 * outranks them.
 *
 * Only moves that an objective asks for are tried. For the fewest moved
 * events, an event that is not at its current position is tried there.
 * For the fewest lone-event sessions, the one event of such a session is
 * tried at every position of the sessions that hold an event, and then
 * every event of another session at every position of that one. For the
 * fewest idle sessions, each event of a participant that has an idle
 * session is tried at every position of the other sessions between that
 * participant's first and last. A move is made when the event fits there
 * (the position has room, and no participant of the event is in another
 * event or pinned there), the schedule is then strictly better on gap
 * slots followed by the objectives in their order, and no unplaced event
 * fits the position it left, so that an unplaced event stays one that no
 * position holds. The number of events placed never changes.
 *
 * It is a repair and not a search: it stops at the first schedule that no
 * single move improves. Every move that fits spends one step of the
 * budget it is given and counts towards a limit of its own
 * ({@see self::EVALUATION_LIMIT}), which a budget below the default makes
 * smaller as it does the other work limits.
 *
 * An event is a list of participants here, of whatever length.
 *
 * @internal
 */
final class ObjectivePolish
{
    /**
     * How many moves may be weighed at the default budget and above. A
     * move is weighed by counting every figure of the schedule again,
     * which is work in proportion to the events, so the limit bounds the
     * repair at about the cost of that many passes over them.
     */
    public const int EVALUATION_LIMIT = 2_000;

    /** @var array<int, array<int, int>> Session => slot => movable events there */
    private array $occupancy = [];

    /** @var array<int, array<int, array<int, true>>> Session => slot => participants of those events */
    private array $busy = [];

    /**
     * @param list<RepackObjective> $objectives In their order
     * @param array<int, list<int>> $participantsOf Movable event index => its participant indexes
     * @param array<int, array<int, array<int>>> $pinSlots Session => participant => pinned slots
     * @param array<int, array<int, int>> $pinCounts Session => slot => pinned events there
     * @param array<int, int> $slotCounts Session => slot count
     * @param array<int, array{int, int}> $current Movable event index => its current [session, slot]
     */
    public function __construct(
        private readonly PlacementMetrics $metrics,
        private readonly array $objectives,
        private readonly array $participantsOf,
        private readonly array $pinSlots,
        private readonly array $pinCounts,
        private readonly array $slotCounts,
        private readonly int $capacityPerSlot,
        private readonly array $current,
        private readonly StepBudget $budget
    ) {}

    /**
     * The schedule after every move that improves it, or the schedule
     * given when none does.
     *
     * @param array<int, array{int, int}> $positions Placed movable event index => [session, slot]
     * @param list<int> $unplaced The movable events with no position
     *
     * @return array<int, array{int, int}> The same events, each at a position
     */
    public function improve(array $positions, array $unplaced): array
    {
        if ($this->objectives === [] || $positions === []) {
            return $positions;
        }

        $this->occupancy = [];
        $this->busy = [];
        foreach ($positions as $eventIndex => [$session, $slot]) {
            $this->put($eventIndex, $session, $slot);
        }

        $limit = $this->budget->workLimit(self::EVALUATION_LIMIT);
        $evaluations = 0;
        $best = [$this->metrics->gapSlots($positions), ...$this->metrics->score($positions, $this->objectives)];

        do {
            $moved = false;
            foreach ($this->candidates($positions) as [$eventIndex, $session, $slot]) {
                if (!$this->fits($eventIndex, $session, $slot)) {
                    continue;
                }
                if ($evaluations >= $limit) {
                    $this->budget->stoppedAtWorkLimit();

                    return $positions;
                }
                if (!$this->budget->consume()) {
                    return $positions;
                }
                ++$evaluations;

                $trial = $positions;
                $trial[$eventIndex] = [$session, $slot];
                $key = [$this->metrics->gapSlots($trial), ...$this->metrics->score($trial, $this->objectives)];
                if ($key >= $best) {
                    continue;
                }

                [$fromSession, $fromSlot] = $positions[$eventIndex];
                $this->take($eventIndex, $fromSession, $fromSlot);
                $this->put($eventIndex, $session, $slot);
                if ($this->admitsOneOf($unplaced, $fromSession, $fromSlot)) {
                    $this->take($eventIndex, $session, $slot);
                    $this->put($eventIndex, $fromSession, $fromSlot);
                    continue;
                }

                $positions = $trial;
                $best = $key;
                $moved = true;
                break;
            }
        } while ($moved);

        return $positions;
    }

    /**
     * The moves to try on a schedule, the first objective's first: each
     * an event and a position that is not its own. Whether the event fits
     * there is not looked at here.
     *
     * @param array<int, array{int, int}> $positions
     *
     * @return \Generator<int, array{int, int, int}>
     */
    private function candidates(array $positions): \Generator
    {
        foreach ($this->objectives as $objective) {
            yield from match ($objective) {
                RepackObjective::LeastChurn => $this->backToCurrent($positions),
                RepackObjective::FewestLoneEventSessions => $this->outOfLoneSessions($positions),
                RepackObjective::FewestIdleSessions => $this->intoTheSpan($positions),
            };
        }
    }

    /**
     * @param array<int, array{int, int}> $positions
     *
     * @return \Generator<int, array{int, int, int}>
     */
    private function backToCurrent(array $positions): \Generator
    {
        foreach ($this->metrics->moved($positions) as $eventIndex) {
            [$session, $slot] = $this->current[$eventIndex];
            if ($slot < ($this->slotCounts[$session] ?? 0)) {
                yield [$eventIndex, $session, $slot];
            }
        }
    }

    /**
     * @param array<int, array{int, int}> $positions
     *
     * @return \Generator<int, array{int, int, int}>
     */
    private function outOfLoneSessions(array $positions): \Generator
    {
        $lone = array_fill_keys($this->metrics->loneEventSessions($positions), true);
        if ($lone === []) {
            return;
        }

        foreach ($positions as $eventIndex => [$own]) {
            if (!isset($lone[$own])) {
                continue;
            }
            foreach ($this->slotCounts as $session => $slotCount) {
                if ($session === $own || (($this->occupancy[$session] ?? []) === [] && ($this->pinCounts[$session] ?? []) === [])) {
                    continue;
                }
                for ($slot = 0; $slot < $slotCount; ++$slot) {
                    yield [$eventIndex, $session, $slot];
                }
            }
        }

        // Or the session gets a second event, from a session that is not
        // one itself
        foreach (array_keys($lone) as $session) {
            foreach ($positions as $eventIndex => [$own]) {
                if (isset($lone[$own])) {
                    continue;
                }
                for ($slot = 0; $slot < $this->slotCounts[$session]; ++$slot) {
                    yield [$eventIndex, $session, $slot];
                }
            }
        }
    }

    /**
     * @param array<int, array{int, int}> $positions
     *
     * @return \Generator<int, array{int, int, int}>
     */
    private function intoTheSpan(array $positions): \Generator
    {
        $idle = $this->metrics->idleSessionsByParticipant($positions);
        if ($idle === []) {
            return;
        }
        ksort($idle);

        /** @var array<int, array<int, int>> $eventsOf Participant with an idle session => event => its session */
        $eventsOf = [];
        foreach ($positions as $eventIndex => [$session]) {
            foreach ($this->participantsOf[$eventIndex] as $pid) {
                if (isset($idle[$pid])) {
                    $eventsOf[$pid][$eventIndex] = $session;
                }
            }
        }

        foreach (array_keys($idle) as $pid) {
            // The sessions it has an event in, pinned ones included
            $held = array_values($eventsOf[$pid]);
            foreach ($this->pinSlots as $session => $byParticipant) {
                if (isset($byParticipant[$pid])) {
                    $held[] = $session;
                }
            }
            [$first, $last] = PlacementMetrics::span($held);

            foreach ($eventsOf[$pid] as $eventIndex => $own) {
                for ($session = $first; $session <= $last; ++$session) {
                    if ($session === $own) {
                        continue;
                    }
                    for ($slot = 0; $slot < $this->slotCounts[$session]; ++$slot) {
                        yield [$eventIndex, $session, $slot];
                    }
                }
            }
        }
    }

    /**
     * Whether an event can go to a position as the schedule stands: the
     * position has room, and no participant of the event is in another
     * event or pinned there. An event does not fit where it already is.
     */
    private function fits(int $eventIndex, int $session, int $slot): bool
    {
        $used = ($this->occupancy[$session][$slot] ?? 0) + ($this->pinCounts[$session][$slot] ?? 0);
        if ($used >= $this->capacityPerSlot) {
            return false;
        }

        foreach ($this->participantsOf[$eventIndex] as $pid) {
            if (
                isset($this->busy[$session][$slot][$pid])
                || in_array($slot, $this->pinSlots[$session][$pid] ?? [], true)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether one of the unplaced events fits a position.
     *
     * @param list<int> $unplaced
     */
    private function admitsOneOf(array $unplaced, int $session, int $slot): bool
    {
        return array_any($unplaced, fn($eventIndex) => $this->fits($eventIndex, $session, $slot));
    }

    private function put(int $eventIndex, int $session, int $slot): void
    {
        $this->occupancy[$session][$slot] = ($this->occupancy[$session][$slot] ?? 0) + 1;
        foreach ($this->participantsOf[$eventIndex] as $pid) {
            $this->busy[$session][$slot][$pid] = true;
        }
    }

    private function take(int $eventIndex, int $session, int $slot): void
    {
        if (--$this->occupancy[$session][$slot] === 0) {
            unset($this->occupancy[$session][$slot]);
            if ($this->occupancy[$session] === []) {
                unset($this->occupancy[$session]);
            }
        }
        foreach ($this->participantsOf[$eventIndex] as $pid) {
            unset($this->busy[$session][$slot][$pid]);
        }
    }
}
