<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\RepackViolationsException;
use MissionGaming\Tactician\Repack\Internal\LeftoverRecolourer;
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
 * covers what exact search cannot reach. When an event is still unplaced
 * after that, a last placement step moves placed events to make room for
 * it, across sessions: placing an event comes before contiguity, whose
 * breaks are reported. Properness — no participant twice at one
 * position, pins immovable — is never traded; contiguity is satisfied or
 * reported, never silently relaxed.
 *
 * Deterministic: same input, same output, independent of input list
 * order (events are ordered internally by their caller-supplied ids and
 * nothing else). Pure: no clock reads, no I/O, no persistence. Bounded:
 * every search spends from the options' step budget (the last placement
 * step from a budget of its own of the same size), and the outcome says
 * whether a budget stopped one (RepackOutcome::isBudgetExhausted()).
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
 *
 * @api
 */
final readonly class ScheduleRepacker
{
    /**
     * Assign every movable event of the request a position, or say why
     * not.
     *
     * Every movable event is in the outcome's assignments or in its
     * unplaced list, never both and never neither; pinned events are in
     * neither. No two assigned or pinned events that share a participant
     * are at one position, and no position holds more events than the
     * grid's capacity. The assignments and the unplaced list are in
     * ascending byte order of event id; the violations are ordered by
     * kind. The same request gives the same outcome, whatever order its
     * two lists are in. The request is not changed and nothing is kept
     * between calls.
     *
     * An event the planner gave to one session can end in another: what
     * a session's packing leaves over, and what the planner could give to
     * no session, is put at the first position, in grid order, that has
     * room and both participants free. When an event is still unplaced
     * after that, the last placement step moves placed events along
     * alternating paths, and exchanges one placed event for one unplaced
     * event where that lets a further one in, until no move places one
     * more; it is offered the events dropped for an over-capacity
     * participant too. A move that does not place one more event is taken
     * back, so an outcome with nothing unplaced never reaches this step.
     * An event still unplaced has the reason NoSlotAvailable only when no
     * position has room with both its participants free.
     *
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
        // The plan lists events in ascending index order, so each session's
        // events come out in that order too.
        /** @var array<int, array<int, array{int, int}>> $edgesBySession */
        $edgesBySession = [];
        foreach ($plan->sessionByEvent as $eventIndex => $assignedSession) {
            $edgesBySession[$assignedSession][$eventIndex] = $edges[$eventIndex];
        }
        for ($session = 0; $session < $sessionCount; ++$session) {
            $packed = $packer->pack(
                $edgesBySession[$session] ?? [],
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
        /** @var array<int, UnplacedEvent> $dropped Event index => its over-capacity drop */
        $dropped = [];
        $eventIndexById = array_flip($eventIds);
        foreach ($plan->unplaced as $planUnplaced) {
            if ($planUnplaced->getReason() === UnplacedReason::NoSlotAvailable) {
                $leftovers[] = $eventIndexById[$planUnplaced->getEventId()];
                continue;
            }
            $dropped[$eventIndexById[$planUnplaced->getEventId()]] = $planUnplaced;
        }
        sort($leftovers);
        $stillLeft = [];
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
                $stillLeft[] = $eventIndex;
                continue;
            }
            $positions[$eventIndex] = $position;
        }

        // Last resort, reached only when an event is still unplaced: move
        // placed events along alternating paths to open a position for
        // it. The over-capacity drops are offered too: a participant can
        // never hold more positions than it has free, so placing one only
        // changes which of its events is the one that does not fit. The
        // step has a budget of its own, of the same size, so that a run
        // whose earlier searches spent the whole budget still gets one.
        $recolourBudget = new StepBudget($options->stepBudget);
        if ($stillLeft !== [] || $dropped !== []) {
            $stillLeft = (new LeftoverRecolourer($recolourBudget))->place(
                $edges,
                $positions,
                [...$stillLeft, ...array_keys($dropped)],
                $pinSlots,
                $pinCounts,
                $slotCounts,
                $capacityPerSlot
            );
        }

        $unplaced = $this->label($stillLeft, $dropped, $plan->shortfalls, $edges, $eventIds, $participants);

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

        $outcome = new RepackOutcome(
            $assignments,
            $unplaced,
            $violations,
            $budget->stoppedASearch() || $recolourBudget->stoppedASearch()
        );

        if ($options->throwOnViolations && !$outcome->isClean()) {
            throw new RepackViolationsException($outcome);
        }

        return $outcome;
    }

    /**
     * The unplaced list: every event still without a position, with its
     * reason.
     *
     * While every over-capacity drop is still unplaced, the drops keep
     * the reasons the planner gave them and every other event has the
     * reason NoSlotAvailable. When the last placement step placed a
     * drop, the reasons are given again from the events still unplaced,
     * by the planner's rule: events between two over-capacity
     * participants first, as many as can count towards both shortfalls,
     * then for each participant still short (largest shortfall first) its
     * remaining events, highest event id first, until its shortfall is
     * covered. A participant never holds more positions than it has free,
     * so it always has enough events still unplaced.
     *
     * @param list<int> $stillLeft Event indexes, ascending
     * @param array<int, UnplacedEvent> $dropped Event index => the planner's over-capacity drop
     * @param array<int, int> $shortfalls Over-capacity participant index => shortfall
     * @param array<int, array{int, int}> $edges
     * @param array<string> $eventIds
     * @param array<Participant> $participants
     *
     * @return list<UnplacedEvent> In ascending event id order
     */
    private function label(
        array $stillLeft,
        array $dropped,
        array $shortfalls,
        array $edges,
        array $eventIds,
        array $participants
    ): array {
        $left = array_fill_keys($stillLeft, true);
        $dropsAllLeft = array_diff_key($dropped, $left) === [];

        /** @var array<int, int> $overCapacityFor Event index => the participant it is unplaced for */
        $overCapacityFor = [];
        if (!$dropsAllLeft) {
            $candidates = [];
            foreach ($stillLeft as $eventIndex) {
                $candidates[$eventIndex] = $edges[$eventIndex];
            }

            $need = $shortfalls;
            foreach (LoadPlanner::sharedDrops($candidates, $shortfalls) as $eventIndex) {
                [$a, $b] = $edges[$eventIndex];
                $overCapacityFor[$eventIndex] = [$shortfalls[$b], $a] > [$shortfalls[$a], $b] ? $b : $a;
                --$need[$a];
                --$need[$b];
            }

            $short = array_keys($shortfalls);
            usort($short, static fn(int $x, int $y): int => [$shortfalls[$y], $x] <=> [$shortfalls[$x], $y]);
            foreach ($short as $pid) {
                foreach (array_reverse($stillLeft) as $eventIndex) {
                    if ($need[$pid] < 1) {
                        break;
                    }
                    [$a, $b] = $edges[$eventIndex];
                    if (isset($overCapacityFor[$eventIndex]) || ($a !== $pid && $b !== $pid)) {
                        continue;
                    }
                    $overCapacityFor[$eventIndex] = $pid;
                    $other = $a === $pid ? $b : $a;
                    --$need[$pid];
                    if (($need[$other] ?? 0) > 0) {
                        --$need[$other];
                    }
                }
            }
        }

        $unplaced = [];
        foreach ($stillLeft as $eventIndex) {
            if ($dropsAllLeft && isset($dropped[$eventIndex])) {
                $unplaced[] = $dropped[$eventIndex];
            } elseif (isset($overCapacityFor[$eventIndex])) {
                $unplaced[] = new UnplacedEvent(
                    $eventIds[$eventIndex],
                    UnplacedReason::ParticipantOverCapacity,
                    $participants[$overCapacityFor[$eventIndex]]
                );
            } else {
                $unplaced[] = new UnplacedEvent($eventIds[$eventIndex], UnplacedReason::NoSlotAvailable);
            }
        }

        return $unplaced;
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
