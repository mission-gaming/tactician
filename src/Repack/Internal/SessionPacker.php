<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

/**
 * Phases B and C: colour one session.
 *
 * Phase B is an exact search against the contiguity targets: slot c must
 * hold a perfect matching on exactly the participants targeting c, so a
 * budget-bounded depth-first search with fail-first vertex selection
 * either packs the session with zero gaps and zero late starts by
 * construction, or proves the targets unreachable within budget.
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

    public function __construct(private readonly StepBudget $budget)
    {
    }

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
        $targets = [];
        $matchSet = array_fill(0, $this->slotCount, []);
        foreach ($fixedTargets as $pid => $target) {
            $targets[$pid] = array_fill_keys($target, true);
            foreach ($target as $slot) {
                $matchSet[$slot][$pid] = true;
            }
        }
        foreach ($flexiblePids as $index => $pid) {
            $targets[$pid] = [];
            for ($slot = $starts[$index]; $slot < $starts[$index] + $flexibleLengths[$index]; ++$slot) {
                $targets[$pid][$slot] = true;
                $matchSet[$slot][$pid] = true;
            }
        }

        foreach ($matchSet as $slot => $pids) {
            $free = $this->capacity - ($this->pinCounts[$slot] ?? 0);
            if (count($pids) % 2 !== 0 || intdiv(count($pids), 2) > $free) {
                return null;
            }
        }

        $usable = [];
        foreach ($this->edges as $eventIndex => [$a, $b]) {
            $slots = array_keys(array_intersect_key($targets[$a], $targets[$b]));
            if ($slots === []) {
                return null;
            }
            sort($slots);
            $usable[$eventIndex] = $slots;
        }

        $this->slotOf = [];
        $this->usedEvents = [];

        if ($this->solveSlot(0, $matchSet, $usable)) {
            $assignments = $this->slotOf;
            $this->slotOf = [];
            $this->usedEvents = [];

            return $assignments;
        }

        $this->slotOf = [];
        $this->usedEvents = [];

        return null;
    }

    /**
     * @param array<int, array<int, bool>> $matchSet Slot => participants targeting it
     * @param array<int, array<int>> $usable Event => slots both endpoints target
     *
     * @throws BudgetExhausted
     */
    private function solveSlot(int $slot, array $matchSet, array $usable): bool
    {
        if ($slot === $this->slotCount) {
            return true;
        }

        return $this->matchNext($slot, $matchSet[$slot], $matchSet, $usable);
    }

    /**
     * @param array<int, bool> $unmatched Participants still to match at this slot
     * @param array<int, array<int, bool>> $matchSet
     * @param array<int, array<int>> $usable
     *
     * @throws BudgetExhausted
     */
    private function matchNext(int $slot, array $unmatched, array $matchSet, array $usable): bool
    {
        if ($unmatched === []) {
            return $this->solveSlot($slot + 1, $matchSet, $usable);
        }

        if (!$this->budget->consume()) {
            throw new BudgetExhausted();
        }

        // Fail-first: expand the participant with the fewest options
        $chosen = null;
        $chosenCandidates = [];
        foreach (array_keys($unmatched) as $pid) {
            $candidates = [];
            foreach ($this->eventsOf[$pid] as $eventIndex) {
                if (isset($this->usedEvents[$eventIndex]) || !in_array($slot, $usable[$eventIndex], true)) {
                    continue;
                }
                [$a, $b] = $this->edges[$eventIndex];
                $other = $a === $pid ? $b : $a;
                if (isset($unmatched[$other])) {
                    $candidates[] = $eventIndex;
                }
            }

            if ($chosen === null || count($candidates) < count($chosenCandidates)) {
                $chosen = $pid;
                $chosenCandidates = $candidates;
                if ($candidates === []) {
                    break;
                }
            }
        }

        foreach ($chosenCandidates as $eventIndex) {
            [$a, $b] = $this->edges[$eventIndex];
            $other = $a === $chosen ? $b : $a;

            $this->usedEvents[$eventIndex] = true;
            $this->slotOf[$eventIndex] = $slot;
            $remaining = $unmatched;
            unset($remaining[$chosen], $remaining[$other]);

            if ($this->matchNext($slot, $remaining, $matchSet, $usable)) {
                return true;
            }

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
                $occupied = $this->occupiedSlots($pid);
                if (!$this->hasMovableAssignment($pid)) {
                    continue;
                }

                $holes = $this->improvementTargets($occupied);
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
                continue;
            }
            $occupied = $this->occupiedSlots($pid);
            if ($occupied === []) {
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
        return in_array($slot, $this->pinSlots[$pid] ?? [], true);
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
