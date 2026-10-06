<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Repack\CapacityExceeded;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\UnplacedEvent;
use MissionGaming\Tactician\Repack\UnplacedReason;

/**
 * Phase A: decide the per-participant, per-session load before choosing
 * any slot.
 *
 * Every movable event is assigned to a session subject to session
 * capacity (slots x concurrency, minus pins) and each participant's free
 * slots per session (slots minus its pinned slots — pins are consulted
 * before any load is fixed). The objective balances consolidation
 * against early fill per the configured weights; a bounded improvement
 * pass then walks single-event moves downhill, and a parity repair pass
 * nudges loads until every per-slot matching set the contiguity targets
 * imply has even size (a necessary condition for a gap-free packing —
 * fixing it here is cheap, fixing it during slot search is impossible).
 *
 * Events that exceed a participant's total free positions are dropped
 * here, deterministically: the participant's events against the
 * opponents with the most slack go first (least damage to everyone
 * else's feasibility), tie-broken by event id descending.
 *
 * All ids are the orchestrator's dense integer indexes; index order is
 * caller-id order, so index tie-breaks are id tie-breaks.
 *
 * @internal
 */
final class LoadPlanner
{
    /** @var array<int, array{int, int}> */
    private array $edges;

    /** @var array<int, array<int, array<int>>> */
    private array $pinSlots;

    /** @var array<int, int> */
    private array $slotCounts;

    /** @var array<string> */
    private array $eventIds;

    /** @var array<Participant> */
    private array $participants;

    private int $sessionCount;

    /** @var array<int, int> */
    private array $demand = [];

    /** @var array<int, array<int, int>> Participant => session => free slot count */
    private array $freeBySession = [];

    /** @var array<int, int> */
    private array $freeTotal = [];

    /** @var array<int, int> Session => movable event capacity */
    private array $remCap = [];

    /** @var array<int, int> Session => movable events placed */
    private array $used = [];

    /** @var array<int, array<int, int>> Session => participant => movable load */
    private array $mov = [];

    /** @var array<int, int> */
    private array $sessionByEvent = [];

    /**
     * @var array<string, int> Placement scores already worked out, by slot count, fixed mask and
     *                         the flexible loads in ascending order
     */
    private array $scoreByLoads = [];

    /** @var array<int, array<int, array<int, int>>> Session => pinned participant => movable load => fixed mask */
    private array $fixedMaskByLoad = [];

    public function __construct(
        private readonly RepackOptions $options,
        private readonly StepBudget $budget
    ) {}

    /**
     * @param array<int, array{int, int}> $edges Event index => participant index pair,
     *                                           indexes ascending in caller-id order
     * @param array<int, array<int, array<int>>> $pinSlots Session => participant => pinned slots
     * @param array<int, array<int, int>> $pinCounts Session => slot => pinned event count
     * @param array<int, int> $slotCounts Session => slot count
     * @param array<string> $eventIds Event index => caller event id
     * @param array<Participant> $participants Participant index => participant
     */
    public function plan(
        array $edges,
        array $pinSlots,
        array $pinCounts,
        array $slotCounts,
        int $capacityPerSlot,
        array $eventIds,
        array $participants
    ): LoadPlan {
        $this->edges = $edges;
        $this->pinSlots = $pinSlots;
        $this->slotCounts = $slotCounts;
        $this->eventIds = $eventIds;
        $this->participants = $participants;
        $this->sessionCount = count($slotCounts);

        $this->demand = [];
        foreach ($edges as [$a, $b]) {
            $this->demand[$a] = ($this->demand[$a] ?? 0) + 1;
            $this->demand[$b] = ($this->demand[$b] ?? 0) + 1;
        }
        ksort($this->demand);

        $this->freeBySession = [];
        $this->freeTotal = [];
        foreach ($this->demand as $pid => $count) {
            $total = 0;
            for ($session = 0; $session < $this->sessionCount; ++$session) {
                $free = $slotCounts[$session] - count($pinSlots[$session][$pid] ?? []);
                $this->freeBySession[$pid][$session] = $free;
                $total += $free;
            }
            $this->freeTotal[$pid] = $total;
        }

        $this->remCap = [];
        $totalCapacity = 0;
        for ($session = 0; $session < $this->sessionCount; ++$session) {
            $capacity = 0;
            for ($slot = 0; $slot < $slotCounts[$session]; ++$slot) {
                $capacity += max(0, $capacityPerSlot - ($pinCounts[$session][$slot] ?? 0));
            }
            $this->remCap[$session] = $capacity;
            $totalCapacity += $capacity;
        }

        $unplaced = [];
        $violations = [];
        $dropped = [];

        $this->dropOverCapacityParticipants($dropped, $unplaced, $violations);
        $this->dropGridOverflow($totalCapacity, $dropped, $unplaced, $violations);

        $active = [];
        foreach (array_keys($edges) as $eventIndex) {
            if (!isset($dropped[$eventIndex])) {
                $active[] = $eventIndex;
            }
        }

        $this->used = array_fill(0, $this->sessionCount, 0);
        $this->mov = array_fill(0, $this->sessionCount, []);
        $this->sessionByEvent = [];
        $this->scoreByLoads = [];
        $this->fixedMaskByLoad = [];

        $this->assignGreedily($active, $unplaced);
        $this->improve();
        $this->repairParity();

        ksort($this->sessionByEvent);

        return new LoadPlan($this->sessionByEvent, $unplaced, $violations);
    }

    /**
     * @param array<int, bool> $dropped
     * @param array<UnplacedEvent> $unplaced
     * @param array<\MissionGaming\Tactician\Repack\RepackViolation> $violations
     */
    private function dropOverCapacityParticipants(array &$dropped, array &$unplaced, array &$violations): void
    {
        while (true) {
            $worstPid = null;
            $worstShortfall = 0;
            foreach ($this->demand as $pid => $demand) {
                $shortfall = $demand - $this->freeTotal[$pid];
                if ($shortfall > $worstShortfall) {
                    $worstShortfall = $shortfall;
                    $worstPid = $pid;
                }
            }

            if ($worstPid === null) {
                return;
            }

            $violations[] = new CapacityExceeded(
                $this->participants[$worstPid],
                $this->demand[$worstPid],
                $this->freeTotal[$worstPid]
            );

            $candidates = [];
            foreach ($this->edges as $eventIndex => [$a, $b]) {
                if (isset($dropped[$eventIndex]) || ($a !== $worstPid && $b !== $worstPid)) {
                    continue;
                }
                $candidates[] = [$eventIndex, $a === $worstPid ? $b : $a];
            }

            usort($candidates, function (array $x, array $y): int {
                $slackX = $this->freeTotal[$x[1]] - $this->demand[$x[1]];
                $slackY = $this->freeTotal[$y[1]] - $this->demand[$y[1]];

                return $slackY <=> $slackX ?: $y[0] <=> $x[0];
            });

            for ($i = 0; $i < $worstShortfall; ++$i) {
                [$eventIndex] = $candidates[$i];
                $dropped[$eventIndex] = true;
                [$a, $b] = $this->edges[$eventIndex];
                --$this->demand[$a];
                --$this->demand[$b];
                $unplaced[] = new UnplacedEvent(
                    $this->eventIds[$eventIndex],
                    UnplacedReason::ParticipantOverCapacity,
                    $this->participants[$worstPid]
                );
            }
        }
    }

    /**
     * @param array<int, bool> $dropped
     * @param array<UnplacedEvent> $unplaced
     * @param array<\MissionGaming\Tactician\Repack\RepackViolation> $violations
     */
    private function dropGridOverflow(int $totalCapacity, array &$dropped, array &$unplaced, array &$violations): void
    {
        $activeCount = count($this->edges) - count($dropped);
        if ($activeCount <= $totalCapacity) {
            return;
        }

        $violations[] = new CapacityExceeded(null, $activeCount, $totalCapacity);

        $candidates = [];
        foreach ($this->edges as $eventIndex => [$a, $b]) {
            if (!isset($dropped[$eventIndex])) {
                $candidates[] = [$eventIndex, max($this->demand[$a], $this->demand[$b])];
            }
        }
        usort(
            $candidates,
            static fn(array $x, array $y): int => $y[1] <=> $x[1] ?: $y[0] <=> $x[0]
        );

        for ($i = 0; $i < $activeCount - $totalCapacity; ++$i) {
            [$eventIndex] = $candidates[$i];
            $dropped[$eventIndex] = true;
            [$a, $b] = $this->edges[$eventIndex];
            --$this->demand[$a];
            --$this->demand[$b];
            $unplaced[] = new UnplacedEvent($this->eventIds[$eventIndex], UnplacedReason::NoSlotAvailable);
        }
    }

    /**
     * @param array<int> $active Event indexes ascending
     * @param array<UnplacedEvent> $unplaced
     */
    private function assignGreedily(array $active, array &$unplaced): void
    {
        $slack = [];
        foreach ($this->demand as $pid => $demand) {
            $slack[$pid] = $this->freeTotal[$pid] - $demand;
        }

        $order = $active;
        usort($order, function (int $x, int $y) use ($slack): int {
            $tightX = min($slack[$this->edges[$x][0]], $slack[$this->edges[$x][1]]);
            $tightY = min($slack[$this->edges[$y][0]], $slack[$this->edges[$y][1]]);

            return $tightX <=> $tightY ?: $x <=> $y;
        });

        $deferred = [];
        foreach ($order as $eventIndex) {
            $best = $this->bestSessionFor($eventIndex);
            if ($best === null) {
                $deferred[] = $eventIndex;
                continue;
            }
            $this->place($eventIndex, $best);
        }

        foreach ($deferred as $eventIndex) {
            if (!$this->placeWithRelocation($eventIndex)) {
                $unplaced[] = new UnplacedEvent($this->eventIds[$eventIndex], UnplacedReason::NoSlotAvailable);
            }
        }
    }

    private function bestSessionFor(int $eventIndex): ?int
    {
        [$a, $b] = $this->edges[$eventIndex];
        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($session = 0; $session < $this->sessionCount; ++$session) {
            if (!$this->fits($eventIndex, $session)) {
                continue;
            }
            $score = $this->options->earlyFillWeight * $session
                - $this->options->consolidationWeight
                    * ($this->isPresent($a, $session) + $this->isPresent($b, $session));
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $session;
            }
        }

        return $best;
    }

    private function fits(int $eventIndex, int $session): bool
    {
        [$a, $b] = $this->edges[$eventIndex];

        return $this->used[$session] < $this->remCap[$session]
            && ($this->mov[$session][$a] ?? 0) < $this->freeBySession[$a][$session]
            && ($this->mov[$session][$b] ?? 0) < $this->freeBySession[$b][$session];
    }

    private function isPresent(int $pid, int $session): int
    {
        return (($this->mov[$session][$pid] ?? 0) > 0 || isset($this->pinSlots[$session][$pid])) ? 1 : 0;
    }

    private function place(int $eventIndex, int $session): void
    {
        [$a, $b] = $this->edges[$eventIndex];
        $this->sessionByEvent[$eventIndex] = $session;
        ++$this->used[$session];
        $this->mov[$session][$a] = ($this->mov[$session][$a] ?? 0) + 1;
        $this->mov[$session][$b] = ($this->mov[$session][$b] ?? 0) + 1;
    }

    private function unplace(int $eventIndex): void
    {
        $session = $this->sessionByEvent[$eventIndex];
        [$a, $b] = $this->edges[$eventIndex];
        unset($this->sessionByEvent[$eventIndex]);
        --$this->used[$session];
        --$this->mov[$session][$a];
        --$this->mov[$session][$b];
    }

    /**
     * Free a session for a blocked event by relocating one blocking event
     * elsewhere, then place the blocked event. Single-relocation only —
     * deeper repair belongs to the packing phases.
     */
    private function placeWithRelocation(int $eventIndex): bool
    {
        [$a, $b] = $this->edges[$eventIndex];
        for ($session = 0; $session < $this->sessionCount; ++$session) {
            if (!$this->budget->consume()) {
                return false;
            }

            // Structural impossibility: relocation cannot create free slots
            if ($this->freeBySession[$a][$session] === 0 || $this->freeBySession[$b][$session] === 0) {
                continue;
            }

            foreach ($this->sessionByEvent as $otherIndex => $otherSession) {
                if ($otherSession !== $session) {
                    continue;
                }

                $this->unplace($otherIndex);
                if ($this->fits($eventIndex, $session)) {
                    $target = $this->bestSessionForOther($otherIndex, $session);
                    if ($target !== null) {
                        $this->place($otherIndex, $target);
                        $this->place($eventIndex, $session);

                        return true;
                    }
                }
                $this->place($otherIndex, $session);
            }
        }

        return false;
    }

    private function bestSessionForOther(int $eventIndex, int $exclude): ?int
    {
        for ($session = 0; $session < $this->sessionCount; ++$session) {
            if ($session !== $exclude && $this->fits($eventIndex, $session)) {
                return $session;
            }
        }

        return null;
    }

    /**
     * Bounded downhill walk over single-event session moves against the
     * weighted objective.
     */
    private function improve(): void
    {
        $consolidation = $this->options->consolidationWeight;
        $earlyFill = $this->options->earlyFillWeight;

        $passes = 0;
        do {
            $changed = false;
            foreach (array_keys($this->sessionByEvent) as $eventIndex) {
                if (!$this->budget->consume()) {
                    return;
                }

                $from = $this->sessionByEvent[$eventIndex];
                [$a, $b] = $this->edges[$eventIndex];
                for ($to = 0; $to < $this->sessionCount; ++$to) {
                    if ($to === $from) {
                        continue;
                    }
                    $this->unplace($eventIndex);
                    if (!$this->fits($eventIndex, $to)) {
                        $this->place($eventIndex, $from);
                        continue;
                    }

                    $delta = $earlyFill * ($to - $from)
                        + $consolidation * ($this->presenceDelta($a, $from, $to) + $this->presenceDelta($b, $from, $to));
                    if ($delta < 0) {
                        $this->place($eventIndex, $to);
                        $changed = true;
                        break;
                    }

                    $this->place($eventIndex, $from);
                }
            }
            ++$passes;
        } while ($changed && $passes < 60 && !$this->budget->isExhausted());
    }

    /**
     * Change in the participant's count of sessions it appears in, were
     * one of its events moved (the event itself already unplaced).
     */
    private function presenceDelta(int $pid, int $from, int $to): int
    {
        $delta = 0;
        if (($this->mov[$from][$pid] ?? 0) === 0 && !isset($this->pinSlots[$from][$pid])) {
            --$delta;
        }
        if (($this->mov[$to][$pid] ?? 0) === 0 && !isset($this->pinSlots[$to][$pid])) {
            ++$delta;
        }

        return $delta;
    }

    /**
     * Nudge session loads until every session admits a gap-free run
     * placement in parity terms. An infeasible load vector dooms the slot
     * search before it starts, and load moves are the only fix the slot
     * search cannot perform itself. Three move kinds, tried in order,
     * first strict improvement of the total infeasibility score wins:
     * single-event moves, whole-participant evictions (the fix when a
     * session holds an odd number of participants), and cross-session
     * event swaps.
     */
    private function repairParity(): void
    {
        $scores = [];
        $total = 0;
        for ($session = 0; $session < $this->sessionCount; ++$session) {
            $scores[$session] = $this->placementScore($session);
            $total += $scores[$session];
        }

        while ($total > 0 && !$this->budget->isExhausted()) {
            $improvement = $this->tryParityMove($scores, $total)
                ?? $this->tryParityEviction($scores, $total)
                ?? $this->tryParitySwap($scores, $total);

            if ($improvement === null) {
                return;
            }
            $total = $improvement;
        }
    }

    /**
     * Single-event moves against the placement score.
     *
     * @param array<int, int> $scores Mutated on success
     *
     * @return int|null The improved total, null when no move improves it
     */
    private function tryParityMove(array &$scores, int $total): ?int
    {
        foreach (array_keys($this->sessionByEvent) as $eventIndex) {
            if (!$this->budget->consume()) {
                return null;
            }

            $from = $this->sessionByEvent[$eventIndex];
            for ($to = 0; $to < $this->sessionCount; ++$to) {
                if ($to === $from) {
                    continue;
                }
                $this->unplace($eventIndex);
                if (!$this->fits($eventIndex, $to)) {
                    $this->place($eventIndex, $from);
                    continue;
                }

                $this->place($eventIndex, $to);
                $newFrom = $this->placementScore($from);
                $newTo = $this->placementScore($to);
                $newTotal = $total - $scores[$from] - $scores[$to] + $newFrom + $newTo;
                if ($newTotal < $total) {
                    $scores[$from] = $newFrom;
                    $scores[$to] = $newTo;

                    return $newTotal;
                }

                $this->unplace($eventIndex);
                $this->place($eventIndex, $from);
            }
        }

        return null;
    }

    /**
     * Whole-participant evictions: move every event a participant has in
     * one session somewhere else. A session holding an odd number of
     * participants can never pack gap-free — one of them has to go, and
     * no single-event move expresses that.
     *
     * @param array<int, int> $scores Mutated on success
     *
     * @return int|null The improved total, null when no eviction improves it
     */
    private function tryParityEviction(array &$scores, int $total): ?int
    {
        foreach (array_keys($this->freeTotal) as $pid) {
            for ($from = 0; $from < $this->sessionCount; ++$from) {
                if ($scores[$from] === 0 || ($this->mov[$from][$pid] ?? 0) < 1) {
                    continue;
                }
                if (!$this->budget->consume()) {
                    return null;
                }

                $moved = [];
                $failed = false;
                foreach (array_keys($this->sessionByEvent) as $eventIndex) {
                    if ($this->sessionByEvent[$eventIndex] !== $from) {
                        continue;
                    }
                    [$a, $b] = $this->edges[$eventIndex];
                    if ($a !== $pid && $b !== $pid) {
                        continue;
                    }

                    $target = $this->bestSessionForOther($eventIndex, $from);
                    if ($target === null) {
                        $failed = true;
                        break;
                    }
                    $this->unplace($eventIndex);
                    $this->place($eventIndex, $target);
                    $moved[$eventIndex] = $from;
                }

                if (!$failed) {
                    $newScores = [];
                    $newTotal = 0;
                    for ($session = 0; $session < $this->sessionCount; ++$session) {
                        $newScores[$session] = $this->placementScore($session);
                        $newTotal += $newScores[$session];
                    }
                    if ($newTotal < $total) {
                        $scores = $newScores;

                        return $newTotal;
                    }
                }

                foreach ($moved as $eventIndex => $origin) {
                    $this->unplace($eventIndex);
                    $this->place($eventIndex, $origin);
                }
            }
        }

        return null;
    }

    /**
     * Cross-session event swaps — reachable load shapes single moves and
     * evictions cannot express.
     *
     * @param array<int, int> $scores Mutated on success
     *
     * @return int|null The improved total, null when no swap improves it
     */
    private function tryParitySwap(array &$scores, int $total): ?int
    {
        // Every non-returning path below restores its moves, so the key
        // set of sessionByEvent, the session of every event and the scores
        // are stable across the whole scan. The pairs worth a step are
        // therefore known up front: the second event must sit in a later
        // session than the first, and one of the two sessions must be
        // infeasible. Which second events qualify depends only on the first
        // event's session, so the list is made once per session and not
        // once per first event; the pairs come in the order they always did.
        $eventIndexes = array_keys($this->sessionByEvent);
        $sessionOf = $this->sessionByEvent;
        /** @var array<int, list<int>> $secondsAfter Session of the first event => second events worth a step */
        $secondsAfter = [];
        foreach ($eventIndexes as $first) {
            $from = $sessionOf[$first];
            if (!isset($secondsAfter[$from])) {
                $secondsAfter[$from] = [];
                foreach ($eventIndexes as $second) {
                    $to = $sessionOf[$second];
                    if ($from < $to && ($scores[$from] !== 0 || $scores[$to] !== 0)) {
                        $secondsAfter[$from][] = $second;
                    }
                }
            }

            foreach ($secondsAfter[$from] as $second) {
                $to = $sessionOf[$second];
                if (!$this->budget->consume()) {
                    return null;
                }

                $this->unplace($first);
                $this->unplace($second);
                if (!$this->fits($first, $to) || !$this->fits($second, $from)) {
                    $this->place($first, $from);
                    $this->place($second, $to);
                    continue;
                }

                $this->place($first, $to);
                $this->place($second, $from);
                $newFrom = $this->placementScore($from);
                $newTo = $this->placementScore($to);
                $newTotal = $total - $scores[$from] - $scores[$to] + $newFrom + $newTo;
                if ($newTotal < $total) {
                    $scores[$from] = $newFrom;
                    $scores[$to] = $newTo;

                    return $newTotal;
                }

                $this->unplace($first);
                $this->unplace($second);
                $this->place($first, $from);
                $this->place($second, $to);
            }
        }

        return null;
    }

    /**
     * How far one session's load vector is from admitting a gap-free run
     * placement (0 = feasible in parity terms).
     */
    private function placementScore(int $session): int
    {
        // Sessions wider than the parity machinery's bitmask width skip
        // parity reasoning: treated as feasible here, packed greedily in
        // Phase C, with the audit reporting whatever pattern results
        if ($this->slotCounts[$session] > IntervalPlacement::MAX_SLOTS) {
            return 0;
        }

        $slotCount = $this->slotCounts[$session];
        $sessionPins = $this->pinSlots[$session] ?? [];

        $fixedMask = 0;
        $flexible = [];
        foreach ($this->mov[$session] as $pid => $load) {
            if ($load < 1) {
                continue;
            }
            $pins = $sessionPins[$pid] ?? [];
            if ($pins === []) {
                $flexible[] = $load;
                continue;
            }

            // A pinned participant's target, and so its mask, depends only
            // on its pins (fixed) and its load.
            $fixedMask ^= $this->fixedMaskByLoad[$session][$pid][$load]
                ??= IntervalPlacement::maskOfSet(
                    ContiguityTargets::movableTarget($pins, $load, $slotCount),
                    $slotCount
                );
        }

        // The score is a function of the fixed mask and of which run
        // lengths there are, in any order (IntervalPlacement works on the
        // set of masks the runs can reach). The repair pass scores the same
        // few load shapes over and over as it tries moves and takes them
        // back, so each shape is worked out once.
        sort($flexible);
        $shape = $slotCount . '|' . $fixedMask . '|' . implode(',', $flexible);

        return $this->scoreByLoads[$shape] ??= IntervalPlacement::minOddScore($fixedMask, $flexible, $slotCount);
    }
}
