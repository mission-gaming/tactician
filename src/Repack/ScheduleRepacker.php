<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\RepackViolationsException;
use MissionGaming\Tactician\Repack\Internal\LoadPlanner;
use MissionGaming\Tactician\Repack\Internal\RepackAuditor;
use MissionGaming\Tactician\Repack\Internal\SessionPacker;
use MissionGaming\Tactician\Repack\Internal\StepBudget;

/**
 * Repairs an existing schedule onto a session grid.
 *
 * Given events that already exist — some pinned in place — every movable
 * event is assigned a (session, slot) position such that no participant
 * is ever in two events at once and each participant's events within a
 * session run back to back where possible. Generation invents events;
 * repacking never does — the events, their participant pairs, and their
 * identities are fixed inputs, and the only free variable is where each
 * lands.
 *
 * Three phases, per the constraint hierarchy: per-session loads are
 * decided first (capacity- and pin-aware, weighted between consolidation
 * and early fill), each session is then packed against its contiguity
 * targets by exact search, and a bounded greedy-plus-repair fallback
 * covers what exact search cannot reach. Properness — no participant
 * twice at one position, pins immovable — is never traded; contiguity is
 * satisfied or reported, never silently relaxed.
 *
 * Deterministic: same input, same output, independent of input list
 * order (events are ordered internally by their caller-supplied ids and
 * nothing else). Pure: no clock reads, no I/O, no persistence. Bounded:
 * every search spends from the options' step budget.
 *
 * A shape-only grid is repacked exactly as the instant-based grid of the
 * same shape is: positions are all the algorithm reads. The assignments
 * then carry no kickoff. A grid of unbounded capacity is repacked with no
 * limit on the events sharing a slot other than that no participant is in
 * two of them; it never produces a CapacityExceeded for the grid as a
 * whole, and still produces one for a participant with more events than
 * free positions, which no capacity changes.
 *
 * Infeasibility is an outcome, not an exception: the returned
 * RepackOutcome carries the schedule plus every compromise as structured
 * data, and the caller decides what is fatal
 * (RepackOptions(throwOnViolations: true) opts into throwing).
 */
final readonly class ScheduleRepacker
{
    /**
     * @throws RepackViolationsException Only when the options opted into
     *                                   throwOnViolations and the outcome
     *                                   is not clean
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException
     *         Never in practice: the repacker only addresses positions the
     *         request already validated against the grid
     */
    public function repack(RepackRequest $request): RepackOutcome
    {
        $grid = $request->getGrid();
        $options = $request->getOptions();
        $sessionCount = $grid->getSessionCount();

        $slotCounts = [];
        for ($session = 0; $session < $sessionCount; ++$session) {
            $slotCounts[$session] = $grid->getSlotCount($session);
        }

        // Normalize to dense integer indexes; index order is caller-id
        // order, so every internal tie-break is an id tie-break and the
        // output cannot depend on input list order
        $movables = $request->getMovableEvents();
        usort(
            $movables,
            static fn(MovableEvent $x, MovableEvent $y): int => strcmp($x->getId(), $y->getId())
        );
        $pins = $request->getPinnedEvents();
        usort(
            $pins,
            static fn(PinnedEvent $x, PinnedEvent $y): int => strcmp($x->getId(), $y->getId())
        );

        /** @var array<string, Participant> $participantsById */
        $participantsById = [];
        foreach ($movables as $movable) {
            foreach ($movable->getParticipants() as $participant) {
                $participantsById[$participant->getId()] ??= $participant;
            }
        }
        foreach ($pins as $pin) {
            foreach ($pin->getParticipants() as $participant) {
                $participantsById[$participant->getId()] ??= $participant;
            }
        }
        ksort($participantsById, SORT_STRING);

        /** @var array<string, int> $participantIndexes */
        $participantIndexes = [];
        /** @var array<Participant> $participants */
        $participants = [];
        foreach ($participantsById as $id => $participant) {
            $participantIndexes[(string) $id] = count($participants);
            $participants[] = $participant;
        }

        /** @var array<string> $eventIds */
        $eventIds = [];
        /** @var array<int, array{int, int}> $edges */
        $edges = [];
        foreach ($movables as $movable) {
            $eventIds[] = $movable->getId();
            $edges[] = [
                $participantIndexes[$movable->getParticipantA()->getId()],
                $participantIndexes[$movable->getParticipantB()->getId()],
            ];
        }

        /** @var array<int, array<int, array<int>>> $pinSlots */
        $pinSlots = [];
        /** @var array<int, array<int, int>> $pinCounts */
        $pinCounts = [];
        foreach ($pins as $pin) {
            foreach ($pin->getParticipants() as $participant) {
                $pinSlots[$pin->getSession()][$participantIndexes[$participant->getId()]][] = $pin->getSlot();
            }
            $pinCounts[$pin->getSession()][$pin->getSlot()]
                = ($pinCounts[$pin->getSession()][$pin->getSlot()] ?? 0) + 1;
        }
        foreach ($pinSlots as &$byParticipant) {
            foreach ($byParticipant as &$slots) {
                sort($slots);
            }
            unset($slots);
        }
        unset($byParticipant);

        // An unbounded capacity becomes the number of events in the
        // request: no slot can ever hold more than all of them, so that
        // limit never binds, and no arithmetic on it can overflow
        $capacityPerSlot = $grid->getCapacityLimit() ?? max(1, count($movables) + count($pins));

        $budget = new StepBudget($options->stepBudget);

        // Phase A: which session does every event play in?
        $plan = (new LoadPlanner($options, $budget))->plan(
            $edges,
            $pinSlots,
            $pinCounts,
            $slotCounts,
            $capacityPerSlot,
            $eventIds,
            $participants
        );

        // Phases B and C: colour each session
        /** @var array<int, array{int, int}> $positions */
        $positions = [];
        $leftovers = [];
        $packer = new SessionPacker($budget);
        for ($session = 0; $session < $sessionCount; ++$session) {
            $sessionEdges = [];
            foreach ($plan->sessionByEvent as $eventIndex => $assignedSession) {
                if ($assignedSession === $session) {
                    $sessionEdges[$eventIndex] = $edges[$eventIndex];
                }
            }

            $packed = $packer->pack(
                $sessionEdges,
                $pinSlots[$session] ?? [],
                $pinCounts[$session] ?? [],
                $slotCounts[$session],
                $capacityPerSlot
            );
            foreach ($packed['assignments'] as $eventIndex => $slot) {
                $positions[$eventIndex] = [$session, $slot];
            }
            foreach ($packed['leftovers'] as $eventIndex) {
                $leftovers[] = $eventIndex;
            }
        }

        // Final sweep: any free position anywhere beats declaring an event
        // unplaced — "no slot available" must be literally true. Planner
        // rejections with that reason re-enter the sweep alongside packer
        // leftovers; deliberate over-capacity drops do not, because their
        // absence is what makes the CapacityExceeded arithmetic true.
        $unplaced = [];
        $eventIndexById = array_flip($eventIds);
        foreach ($plan->unplaced as $planUnplaced) {
            if ($planUnplaced->getReason() === UnplacedReason::NoSlotAvailable) {
                $leftovers[] = $eventIndexById[$planUnplaced->getEventId()];
                continue;
            }
            $unplaced[] = $planUnplaced;
        }
        sort($leftovers);
        foreach ($leftovers as $eventIndex) {
            $position = $this->firstFreePosition(
                $eventIndex,
                $edges,
                $positions,
                $pinSlots,
                $pinCounts,
                $slotCounts,
                $capacityPerSlot
            );
            if ($position === null) {
                $unplaced[] = new UnplacedEvent($eventIds[$eventIndex], UnplacedReason::NoSlotAvailable);
                continue;
            }
            $positions[$eventIndex] = $position;
        }

        usort(
            $unplaced,
            static fn(UnplacedEvent $x, UnplacedEvent $y): int => strcmp($x->getEventId(), $y->getEventId())
        );

        // The audit reports what is true of the returned schedule
        $violations = (new RepackAuditor())->audit(
            $positions,
            $edges,
            $pins,
            $eventIds,
            $participants,
            $participantIndexes
        );
        foreach ($unplaced as $unplacedEvent) {
            $violations[] = new EventUnplaced(
                $unplacedEvent->getEventId(),
                $unplacedEvent->getReason(),
                $unplacedEvent->getParticipant()
            );
        }
        foreach ($plan->violations as $violation) {
            $violations[] = $violation;
        }

        $kindOrder = array_flip(array_map(
            static fn(ViolationKind $kind): string => $kind->value,
            ViolationKind::cases()
        ));
        usort(
            $violations,
            static fn(RepackViolation $x, RepackViolation $y): int => $kindOrder[$x->getKind()->value] <=> $kindOrder[$y->getKind()->value]
        );

        ksort($positions);
        $assignments = [];
        foreach ($positions as $eventIndex => [$session, $slot]) {
            $assignments[] = new SlotAssignment(
                $eventIds[$eventIndex],
                $session,
                $slot,
                $grid->hasInstants() ? $grid->getSlotTime($session, $slot) : null
            );
        }

        $outcome = new RepackOutcome($assignments, $unplaced, $violations);

        if ($options->throwOnViolations && !$outcome->isClean()) {
            throw new RepackViolationsException($outcome);
        }

        return $outcome;
    }

    /**
     * The earliest position with capacity left and both participants
     * free, or null when none exists on the whole grid.
     *
     * @param array<int, array{int, int}> $edges
     * @param array<int, array{int, int}> $positions
     * @param array<int, array<int, array<int>>> $pinSlots
     * @param array<int, array<int, int>> $pinCounts
     * @param array<int, int> $slotCounts
     *
     * @return array{int, int}|null
     */
    private function firstFreePosition(
        int $eventIndex,
        array $edges,
        array $positions,
        array $pinSlots,
        array $pinCounts,
        array $slotCounts,
        int $capacityPerSlot
    ): ?array {
        [$a, $b] = $edges[$eventIndex];

        $occupancy = [];
        $busy = [];
        foreach ($positions as $otherIndex => [$session, $slot]) {
            $occupancy[$session][$slot] = ($occupancy[$session][$slot] ?? 0) + 1;
            foreach ($edges[$otherIndex] as $pid) {
                $busy[$session][$slot][$pid] = true;
            }
        }

        foreach ($slotCounts as $session => $slotCount) {
            for ($slot = 0; $slot < $slotCount; ++$slot) {
                $used = ($occupancy[$session][$slot] ?? 0) + ($pinCounts[$session][$slot] ?? 0);
                if ($used >= $capacityPerSlot) {
                    continue;
                }
                if (isset($busy[$session][$slot][$a]) || isset($busy[$session][$slot][$b])) {
                    continue;
                }
                if (
                    in_array($slot, $pinSlots[$session][$a] ?? [], true)
                    || in_array($slot, $pinSlots[$session][$b] ?? [], true)
                ) {
                    continue;
                }

                return [$session, $slot];
            }
        }

        return null;
    }
}
