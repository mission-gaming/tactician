<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

/**
 * Phases B and C: colour one session.
 *
 * Phase B is an exact search against the contiguity targets: slot c must
 * hold a perfect matching on exactly the participants targeting c, so a
 * budget-bounded depth-first search with fail-first vertex selection
 * either packs the session with every participant on its target, or
 * finds no such packing: every placement of the runs was tried and
 * failed, or the budget ran out first. A packing it finds gives an
 * unpinned participant one run with no gap, and among the run placements
 * it is the first that packs when they are tried in order of total late
 * start, all runs from the top first. A pinned participant's target can
 * itself start late or keep a gap its load cannot bridge
 * (ContiguityTargets).
 *
 * Phase C is the honest fallback: a prioritized greedy packing
 * (participants who must play every remaining slot go first), a direct
 * placement pass for whatever remains, then bounded local repair —
 * single-event moves into holes and two-slot alternating-chain (Kempe)
 * swaps, which preserve properness by construction; chains touching a
 * pinned position are rejected. Whatever repair cannot fix is left for
 * the audit to report rather than hidden.
 *
 * All ids are the orchestrator's dense integer indexes; index order is
 * caller-id order, so index tie-breaks are id tie-breaks.
 *
 * @internal
 */
final class SessionPacker
{
    /** @var array<int, array{int, int}> */
    private array $edges;

    /** @var array<int, array<int>> Participant => pinned slots, ascending */
    private array $pinSlots;

    /** @var array<int, array<int, true>> Participant => pinned slot set, for O(1) membership */
    private array $pinnedSet;

    /** @var array<int, int> Slot => pinned event count */
    private array $pinCounts;

    private int $slotCount;

    private int $capacity;

    /** @var array<int, int> Participant => movable load */
    private array $loads = [];

    /** @var array<int, array<int>> Participant => its event indexes, ascending */
    private array $eventsOf = [];

    /** @var array<int, int> Assigned event => slot */
    private array $slotOf = [];

    /** @var array<int, bool> */
    private array $usedEvents = [];

    /**
     * @var array<int, list<int>> Slot => the participants targeting it under the placement being tried,
     *                            in matching order
     */
    private array $slotParticipants = [];

    /**
     * @var array<int, array<int, list<array{int, int}>>> Slot => participant => [event, opponent] for each
     *                                                    of its events both ends target at that slot,
     *                                                    events ascending
     */
    private array $slotEvents = [];

    /**
     * @var array<int, array<int, int>> Slot => participant not yet matched at it on the current search
     *                                  path => how many events it could still play there: events not
     *                                  yet used whose opponent is not yet matched at the slot either
     */
    private array $optionsAt = [];

    public function __construct(private readonly StepBudget $budget) {}

    /**
     * @param array<int, array{int, int}> $edges This session's events, keyed by
     *                                           event index, ascending
     * @param array<int, array<int>> $pinSlots Participant => pinned slots
     * @param array<int, int> $pinCounts Slot => pinned event count
     *
     * @return array{assignments: array<int, int>, leftovers: array<int>}
     */
    public function pack(
        array $edges,
        array $pinSlots,
        array $pinCounts,
        int $slotCount,
        int $capacity
    ): array {
        $this->edges = $edges;
        $this->pinSlots = $pinSlots;
        $this->pinnedSet = array_map(
            static fn(array $slots): array => array_fill_keys($slots, true),
            $pinSlots
        );
        $this->pinCounts = $pinCounts;
        $this->slotCount = $slotCount;
        $this->capacity = $capacity;
        $this->slotOf = [];
        $this->usedEvents = [];

        $this->loads = [];
        $this->eventsOf = [];
        foreach ($edges as $eventIndex => [$a, $b]) {
            $this->loads[$a] = ($this->loads[$a] ?? 0) + 1;
            $this->loads[$b] = ($this->loads[$b] ?? 0) + 1;
            $this->eventsOf[$a][] = $eventIndex;
            $this->eventsOf[$b][] = $eventIndex;
        }
        ksort($this->loads);

        if ($edges === []) {
            return ['assignments' => [], 'leftovers' => []];
        }

        $exact = $this->exactSearch();
        if ($exact !== null) {
            return ['assignments' => $exact, 'leftovers' => []];
        }

        $this->greedyPack();
        $this->placeDirectly();
        $this->repair();
        $this->placeDirectly();

        $leftovers = [];
        foreach (array_keys($edges) as $eventIndex) {
            if (!isset($this->slotOf[$eventIndex])) {
                $leftovers[] = $eventIndex;
            }
        }

        return ['assignments' => $this->slotOf, 'leftovers' => $leftovers];
    }

    /**
     * Phase B: find a gap-free assignment, or report that none was found.
     *
     * Pinned participants play their fixed contiguity targets; every
     * unpinned participant plays one contiguous run whose position is
     * free. Parity-feasible run placements are enumerated cheapest total
     * late-start first (all-runs-at-the-top before any late start), and
     * each is checked against the actual event structure by a bounded
     * depth-first perfect-matching search per slot.
     *
     * @return array<int, int>|null Event => slot, null when infeasible or out of budget
     */
    private function exactSearch(): ?array
    {
        // The parity enumeration holds one bit per slot; sessions wider
        // than its bitmask width go straight to the greedy path
        if ($this->slotCount > IntervalPlacement::MAX_SLOTS) {
            return null;
        }

        $fixedMask = 0;
        $fixedTargets = [];
        $flexiblePids = [];
        $flexibleLengths = [];
        foreach ($this->loads as $pid => $load) {
            $pins = $this->pinSlots[$pid] ?? [];
            if ($pins === []) {
                $flexiblePids[] = $pid;
                $flexibleLengths[] = $load;
                continue;
            }

            $target = ContiguityTargets::movableTarget($pins, $load, $this->slotCount);
            if (count($target) < $load) {
                // Not reached: the load planner never gives a participant
                // more events in a session than it has free slots there
                return null;
            }
            $fixedTargets[$pid] = $target;
            $fixedMask ^= IntervalPlacement::maskOfSet($target, $this->slotCount);
        }

        $result = null;
        try {
            IntervalPlacement::enumerate(
                $fixedMask,
                $flexibleLengths,
                $this->slotCount,
                $this->budget,
                function (array $starts) use ($fixedTargets, $flexiblePids, $flexibleLengths, &$result): bool {
                    $assignments = $this->tryPlacement($fixedTargets, $flexiblePids, $flexibleLengths, $starts);
                    if ($assignments === null) {
                        return false;
                    }
                    $result = $assignments;

                    return true;
                }
            );
        } catch (BudgetExhausted) {
            // Fall through to the greedy path with whatever budget is left
        }

        $this->slotOf = [];
        $this->usedEvents = [];

        return $result;
    }

    /**
     * Attempt one concrete run placement: build the per-slot matching
     * sets and search for the perfect matchings they demand.
     *
     * @param array<int, array<int>> $fixedTargets Pinned participant => target slots
     * @param array<int> $flexiblePids
     * @param array<int> $flexibleLengths
     * @param array<int> $starts Run start per flexible participant
     *
     * @return array<int, int>|null Event => slot
     *
     * @throws BudgetExhausted
     */
    private function tryPlacement(array $fixedTargets, array $flexiblePids, array $flexibleLengths, array $starts): ?array
    {
        // How many participants target each slot. Most placements fail
        // here, on a slot with an odd number of them or more than it has
        // room for, so this is counted before anything else is built.
        $targeting = array_fill(0, $this->slotCount, 0);
        foreach ($fixedTargets as $target) {
            foreach ($target as $slot) {
                ++$targeting[$slot];
            }
        }
        foreach ($flexiblePids as $index => $pid) {
            for ($slot = $starts[$index], $end = $slot + $flexibleLengths[$index]; $slot < $end; ++$slot) {
                ++$targeting[$slot];
            }
        }
        foreach ($targeting as $slot => $count) {
            $free = $this->capacity - ($this->pinCounts[$slot] ?? 0);
            if ($count % 2 !== 0 || intdiv($count, 2) > $free) {
                return null;
            }
        }

        // Per participant, the slots it targets; per slot, the participants
        // targeting it, pinned ones first and then the flexible ones, each
        // in the order given: the order the matching search expands them in.
        $targets = [];
        $slotParticipants = array_fill(0, $this->slotCount, []);
        foreach ($fixedTargets as $pid => $target) {
            $targets[$pid] = array_fill_keys($target, true);
            foreach ($target as $slot) {
                $slotParticipants[$slot][] = $pid;
            }
        }
        foreach ($flexiblePids as $index => $pid) {
            $targets[$pid] = [];
            for ($slot = $starts[$index], $end = $slot + $flexibleLengths[$index]; $slot < $end; ++$slot) {
                $targets[$pid][$slot] = true;
                $slotParticipants[$slot][] = $pid;
            }
        }

        // An event can play at the slots both its participants target; one
        // with no such slot rules the placement out.
        $slotEvents = array_fill(0, $this->slotCount, []);
        foreach ($this->edges as $eventIndex => [$a, $b]) {
            $shared = array_intersect_key($targets[$a], $targets[$b]);
            if ($shared === []) {
                return null;
            }
            foreach ($shared as $slot => $unused) {
                $slotEvents[$slot][$a][] = [$eventIndex, $b];
                $slotEvents[$slot][$b][] = [$eventIndex, $a];
            }
        }

        $this->slotParticipants = $slotParticipants;
        $this->slotEvents = $slotEvents;
        $this->optionsAt = array_fill(0, $this->slotCount, []);
        $this->slotOf = [];
        $this->usedEvents = [];

        try {
            $solved = $this->solveSlot(0);
            $assignments = $this->slotOf;
        } finally {
            $this->slotParticipants = [];
            $this->slotEvents = [];
            $this->optionsAt = [];
            $this->slotOf = [];
            $this->usedEvents = [];
        }

        return $solved ? $assignments : null;
    }

    /**
     * @throws BudgetExhausted
     */
    private function solveSlot(int $slot): bool
    {
        if ($slot === $this->slotCount) {
            return true;
        }

        // No participant is matched at this slot yet, so a participant's
        // options are its events here that no earlier slot has used.
        $options = [];
        foreach ($this->slotParticipants[$slot] as $pid) {
            $count = 0;
            foreach ($this->slotEvents[$slot][$pid] ?? [] as [$eventIndex]) {
                if (!isset($this->usedEvents[$eventIndex])) {
                    ++$count;
                }
            }
            $options[$pid] = $count;
        }
        $this->optionsAt[$slot] = $options;

        return $this->matchNext($slot, count($this->slotParticipants[$slot]));
    }

    /**
     * Extend the matching of one slot's participants by one event.
     *
     * The participants still to match are the slot's participants, in
     * their order, less the ones matched on the path so far: the ones
     * that still have an entry in $optionsAt. That is the list this search
     * used to carry as a copy per node; it is read here from the fixed
     * list and the entries, which give the same participants in the same
     * order.
     *
     * How many options each of them has is kept in $optionsAt and brought
     * up to date as a pair is matched and released, where it used to be
     * counted afresh for every participant at every node. The number is
     * the same one: the participant's events at this slot that are unused
     * and whose opponent is unmatched.
     *
     * @param int $unmatchedCount How many of the slot's participants are still to match
     *
     * @throws BudgetExhausted
     */
    private function matchNext(int $slot, int $unmatchedCount): bool
    {
        if ($unmatchedCount === 0) {
            return $this->solveSlot($slot + 1);
        }

        if (!$this->budget->consume()) {
            throw new BudgetExhausted();
        }

        $options = &$this->optionsAt[$slot];
        $eventsHere = $this->slotEvents[$slot];

        // Fail-first: expand the participant with the fewest options, the
        // first such in order.
        $chosen = null;
        $fewest = PHP_INT_MAX;
        foreach ($this->slotParticipants[$slot] as $pid) {
            $count = $options[$pid] ?? PHP_INT_MAX;
            if ($count < $fewest) {
                $chosen = $pid;
                $fewest = $count;
                if ($count === 0) {
                    break;
                }
            }
        }

        if ($chosen === null || $fewest === 0) {
            return false;
        }

        $candidates = [];
        foreach ($eventsHere[$chosen] as [$eventIndex, $other]) {
            if (!isset($this->usedEvents[$eventIndex]) && isset($options[$other])) {
                $candidates[] = [$eventIndex, $other];
            }
        }

        foreach ($candidates as [$eventIndex, $other]) {
            $otherOptions = $options[$other];
            $this->usedEvents[$eventIndex] = true;
            $this->slotOf[$eventIndex] = $slot;
            unset($options[$chosen], $options[$other]);

            // Every unmatched opponent of the two loses the option of
            // playing one of them here.
            $lostAnOption = [];
            foreach ([$chosen, $other] as $end) {
                foreach ($eventsHere[$end] as [$theirEvent, $opponent]) {
                    if (isset($options[$opponent]) && !isset($this->usedEvents[$theirEvent])) {
                        --$options[$opponent];
                        $lostAnOption[] = $opponent;
                    }
                }
            }

            if ($this->matchNext($slot, $unmatchedCount - 2)) {
                return true;
            }

            foreach ($lostAnOption as $opponent) {
                ++$options[$opponent];
            }
            $options[$chosen] = $fewest;
            $options[$other] = $otherOptions;
            unset($this->usedEvents[$eventIndex], $this->slotOf[$eventIndex]);
        }

        return false;
    }

    /**
     * Phase C entry: prioritized greedy packing, slot by slot.
     * Participants who must play every remaining free slot go first.
     */
    private function greedyPack(): void
    {
        $remaining = $this->loads;

        for ($slot = 0; $slot < $this->slotCount; ++$slot) {
            $free = $this->capacity - ($this->pinCounts[$slot] ?? 0);
            $matched = [];

            $candidates = [];
            foreach ($this->loads as $pid => $load) {
                if (($remaining[$pid] ?? 0) > 0 && !$this->isPinnedAt($pid, $slot)) {
                    $candidates[] = $pid;
                }
            }
            usort($candidates, function (int $x, int $y) use ($remaining, $slot): int {
                $criticalX = (int) ($remaining[$x] >= $this->freeSlotsFrom($x, $slot));
                $criticalY = (int) ($remaining[$y] >= $this->freeSlotsFrom($y, $slot));

                return $criticalY <=> $criticalX
                    ?: $remaining[$y] <=> $remaining[$x]
                    ?: $x <=> $y;
            });

            foreach ($candidates as $pid) {
                if ($free < 1) {
                    break;
                }
                if (isset($matched[$pid])) {
                    continue;
                }

                $bestEvent = null;
                $bestRank = null;
                foreach ($this->eventsOf[$pid] as $eventIndex) {
                    if (isset($this->usedEvents[$eventIndex])) {
                        continue;
                    }
                    [$a, $b] = $this->edges[$eventIndex];
                    $other = $a === $pid ? $b : $a;
                    if (
                        isset($matched[$other])
                        || ($remaining[$other] ?? 0) < 1
                        || $this->isPinnedAt($other, $slot)
                    ) {
                        continue;
                    }

                    $rank = [
                        (int) ($remaining[$other] >= $this->freeSlotsFrom($other, $slot)),
                        $remaining[$other],
                    ];
                    if ($bestRank === null || $rank > $bestRank) {
                        $bestRank = $rank;
                        $bestEvent = $eventIndex;
                    }
                }

                if ($bestEvent === null) {
                    continue;
                }

                [$a, $b] = $this->edges[$bestEvent];
                $other = $a === $pid ? $b : $a;
                $this->usedEvents[$bestEvent] = true;
                $this->slotOf[$bestEvent] = $slot;
                $matched[$pid] = true;
                $matched[$other] = true;
                --$remaining[$pid];
                --$remaining[$other];
                --$free;
            }
        }
    }

    /**
     * Place still-unassigned events on any slot with capacity and both
     * participants free — gaps this creates are the audit's to report.
     */
    private function placeDirectly(): void
    {
        foreach (array_keys($this->edges) as $eventIndex) {
            if (isset($this->usedEvents[$eventIndex])) {
                continue;
            }
            for ($slot = 0; $slot < $this->slotCount; ++$slot) {
                if ($this->slotIsFullAt($slot) || !$this->bothFreeAt($eventIndex, $slot)) {
                    continue;
                }
                $this->usedEvents[$eventIndex] = true;
                $this->slotOf[$eventIndex] = $slot;
                break;
            }
        }
    }

    /**
     * Bounded local repair: pull events into holes, directly when the
     * partner is free, otherwise via an alternating-chain swap between
     * the two slots. Accepts only strict lexicographic improvements of
     * (total interior gaps, total late-start depth).
     */
    private function repair(): void
    {
        $improved = true;
        while ($improved && !$this->budget->isExhausted()) {
            $improved = false;

            foreach (array_keys($this->loads) as $pid) {
                if (!$this->hasMovableAssignment($pid)) {
                    continue;
                }

                $holes = $this->improvementTargets($this->occupiedSlots($pid));
                if ($holes === []) {
                    continue;
                }

                foreach ($holes as $hole) {
                    foreach (array_reverse($this->movableSlots($pid)) as $from) {
                        if ($from <= $hole) {
                            continue;
                        }
                        if (!$this->budget->consume()) {
                            return;
                        }
                        if ($this->tryMoveIntoHole($pid, $from, $hole)) {
                            $improved = true;
                            break 3;
                        }
                    }
                }
            }
        }
    }

    /**
     * Interior holes, plus the slot just below the first occupied one
     * when the run starts late.
     *
     * @param array<int> $occupied Ascending
     *
     * @return array<int>
     */
    private function improvementTargets(array $occupied): array
    {
        if ($occupied === []) {
            // Not reached: repair() asks only about a participant that has
            // a movable event in the session
            return [];
        }

        $targets = [];
        $first = $occupied[0];
        $last = $occupied[count($occupied) - 1];
        $set = array_fill_keys($occupied, true);
        for ($slot = $first + 1; $slot < $last; ++$slot) {
            if (!isset($set[$slot])) {
                $targets[] = $slot;
            }
        }
        if ($first > 0) {
            $targets[] = $first - 1;
        }

        return $targets;
    }

    private function tryMoveIntoHole(int $pid, int $from, int $hole): bool
    {
        $event = $this->movableEventAt($pid, $from);
        if ($event === null) {
            // Not reached: the slot is one of the participant's own movable
            // slots
            return false;
        }

        [$a, $b] = $this->edges[$event];
        $other = $a === $pid ? $b : $a;

        if (!$this->slotIsFullAt($hole) && $this->participantFreeAt($other, $hole)) {
            return $this->applyIfBetter([$event => $hole], [$pid, $other]);
        }

        return $this->tryChainSwap($pid, $from, $hole);
    }

    /**
     * Kempe swap: toggle every event in the alternating chain through the
     * participant between the two slots. Properness is preserved by
     * construction; the swap is rejected when the chain touches a pinned
     * position or would overflow either slot.
     */
    private function tryChainSwap(int $pid, int $slotA, int $slotB): bool
    {
        $atSlot = [];
        foreach ($this->slotOf as $eventIndex => $slot) {
            if ($slot === $slotA || $slot === $slotB) {
                [$a, $b] = $this->edges[$eventIndex];
                $atSlot[$slot][$a] = $eventIndex;
                $atSlot[$slot][$b] = $eventIndex;
            }
        }

        $chainEvents = [];
        $chainPids = [];
        $frontier = [$pid];
        while ($frontier !== []) {
            $current = array_shift($frontier);
            if (isset($chainPids[$current])) {
                // Not reached: the start has an event in one of the two
                // slots and none in the other, so the chain is a path that
                // begins there, never a cycle
                continue;
            }
            $chainPids[$current] = true;

            foreach ([$slotA, $slotB] as $slot) {
                $eventIndex = $atSlot[$slot][$current] ?? null;
                if ($eventIndex === null || isset($chainEvents[$eventIndex])) {
                    continue;
                }
                $chainEvents[$eventIndex] = $this->slotOf[$eventIndex];
                [$a, $b] = $this->edges[$eventIndex];
                $frontier[] = $a === $current ? $b : $a;
            }
        }

        if ($chainEvents === []) {
            // Not reached: the chain holds at least the start's own event
            return false;
        }

        $moves = [];
        $deltaA = 0;
        foreach ($chainEvents as $eventIndex => $slot) {
            $target = $slot === $slotA ? $slotB : $slotA;
            $moves[$eventIndex] = $target;
            $deltaA += $target === $slotA ? 1 : -1;

            [$a, $b] = $this->edges[$eventIndex];
            foreach ([$a, $b] as $endpoint) {
                if ($this->isPinnedAt($endpoint, $target)) {
                    return false;
                }
            }
        }

        $capA = $this->capacity - ($this->pinCounts[$slotA] ?? 0);
        $capB = $this->capacity - ($this->pinCounts[$slotB] ?? 0);
        if (
            $this->movableCountAt($slotA) + $deltaA > $capA
            || $this->movableCountAt($slotB) - $deltaA > $capB
        ) {
            return false;
        }

        return $this->applyIfBetter($moves, array_keys($chainPids));
    }

    /**
     * Apply a set of slot moves only when they strictly improve
     * (total gaps, total late depth) over the affected participants.
     *
     * @param array<int, int> $moves Event => new slot
     * @param array<int> $affected Participant ids whose patterns change
     */
    private function applyIfBetter(array $moves, array $affected): bool
    {
        $affected = array_values(array_unique($affected));
        $before = $this->patternScore($affected);

        $previous = [];
        foreach ($moves as $eventIndex => $slot) {
            $previous[$eventIndex] = $this->slotOf[$eventIndex];
            $this->slotOf[$eventIndex] = $slot;
        }

        $after = $this->patternScore($affected);
        // Lexicographic: fewer gaps wins outright, late depth breaks ties
        if ($after[0] < $before[0] || ($after[0] === $before[0] && $after[1] < $before[1])) {
            return true;
        }

        foreach ($previous as $eventIndex => $slot) {
            $this->slotOf[$eventIndex] = $slot;
        }

        return false;
    }

    /**
     * @param array<int> $pids
     *
     * @return array{int, int} [total interior gaps, total late-start depth]
     */
    private function patternScore(array $pids): array
    {
        $gaps = 0;
        $late = 0;
        foreach ($pids as $pid) {
            if (!$this->hasMovableAssignment($pid)) {
                // Not reached: every participant a move affects has a
                // movable event in the session, before the move and after
                continue;
            }
            $occupied = $this->occupiedSlots($pid);
            if ($occupied === []) {
                // Not reached: for the same reason
                continue;
            }
            $first = $occupied[0];
            $last = $occupied[count($occupied) - 1];
            $gaps += ($last - $first + 1) - count($occupied);
            $late += $first;
        }

        return [$gaps, $late];
    }

    private function isPinnedAt(int $pid, int $slot): bool
    {
        return isset($this->pinnedSet[$pid][$slot]);
    }

    private function freeSlotsFrom(int $pid, int $slot): int
    {
        $free = 0;
        for ($candidate = $slot; $candidate < $this->slotCount; ++$candidate) {
            if (!$this->isPinnedAt($pid, $candidate)) {
                ++$free;
            }
        }

        return $free;
    }

    private function movableCountAt(int $slot): int
    {
        $count = 0;
        foreach ($this->slotOf as $assigned) {
            if ($assigned === $slot) {
                ++$count;
            }
        }

        return $count;
    }

    private function slotIsFullAt(int $slot): bool
    {
        return $this->movableCountAt($slot) >= $this->capacity - ($this->pinCounts[$slot] ?? 0);
    }

    private function participantFreeAt(int $pid, int $slot): bool
    {
        if ($this->isPinnedAt($pid, $slot)) {
            return false;
        }
        foreach ($this->eventsOf[$pid] as $eventIndex) {
            if (($this->slotOf[$eventIndex] ?? null) === $slot) {
                return false;
            }
        }

        return true;
    }

    private function bothFreeAt(int $eventIndex, int $slot): bool
    {
        [$a, $b] = $this->edges[$eventIndex];

        return $this->participantFreeAt($a, $slot) && $this->participantFreeAt($b, $slot);
    }

    private function movableEventAt(int $pid, int $slot): ?int
    {
        foreach ($this->eventsOf[$pid] as $eventIndex) {
            if (($this->slotOf[$eventIndex] ?? null) === $slot) {
                return $eventIndex;
            }
        }

        // Not reached: the one caller passes a slot taken from the
        // participant's own movable slots
        return null;
    }

    /**
     * @return array<int> The participant's movable slots, ascending
     */
    private function movableSlots(int $pid): array
    {
        $slots = [];
        foreach ($this->eventsOf[$pid] as $eventIndex) {
            if (isset($this->slotOf[$eventIndex])) {
                $slots[] = $this->slotOf[$eventIndex];
            }
        }
        sort($slots);

        return $slots;
    }

    private function hasMovableAssignment(int $pid): bool
    {
        foreach ($this->eventsOf[$pid] as $eventIndex) {
            if (isset($this->slotOf[$eventIndex])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int> Pinned and movable slots combined, ascending
     */
    private function occupiedSlots(int $pid): array
    {
        $slots = array_merge($this->pinSlots[$pid] ?? [], $this->movableSlots($pid));
        sort($slots);

        return $slots;
    }
}
