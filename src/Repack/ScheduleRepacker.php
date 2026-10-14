<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\RepackViolationsException;
use MissionGaming\Tactician\Repack\Internal\GapSearch;
use MissionGaming\Tactician\Repack\Internal\LeftoverRecolourer;
use MissionGaming\Tactician\Repack\Internal\LoadPlan;
use MissionGaming\Tactician\Repack\Internal\LoadPlanner;
use MissionGaming\Tactician\Repack\Internal\ObjectivePolish;
use MissionGaming\Tactician\Repack\Internal\PlacementMetrics;
use MissionGaming\Tactician\Repack\Internal\RepackAuditor;
use MissionGaming\Tactician\Repack\Internal\RoundColouring;
use MissionGaming\Tactician\Repack\Internal\SessionPacker;
use MissionGaming\Tactician\Repack\Internal\StepBudget;
use MissionGaming\Tactician\Repack\Internal\StructuralOrder;

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
 * The events are first split into rounds (sets of events that can share
 * a slot), laid on the grid's positions around the pins, and taken in
 * that order from then on. Three phases follow, per the constraint
 * hierarchy: per-session loads are decided first (capacity- and
 * pin-aware, weighted between consolidation and early fill), each session
 * is then packed against its contiguity targets by exact search, and a
 * bounded greedy-plus-repair fallback covers what exact search cannot
 * reach. When an event is still unplaced after that, a last placement
 * step moves placed events to make room for it, across sessions: placing
 * an event comes before contiguity, whose breaks are reported. Contiguity
 * in turn comes before the two weights: where the rounds as they were
 * laid out place more events than the planned schedule, or as many with
 * fewer gap slots, they are returned in its place, and a schedule that
 * still has a gap is searched for a placement of as many events with
 * fewer gap slots. Where that still places fewer events than the ceiling
 * no placement can pass, or leaves a gap, the events are planned and
 * packed once more in the order of their participants, on the steps the
 * budgets have left, and that third schedule is returned when it places
 * more events, or as many with fewer gap slots: no single order of the
 * events is the best one for every request, and the number of events
 * placed comes first. Properness — no participant twice at one position,
 * pins immovable — is never traded; contiguity is satisfied or reported,
 * never silently relaxed.
 *
 * Deterministic: same input, same output, independent of input list
 * order. Independent of the ids as well, as far as that can be: the
 * participants and events are taken in an order made from the request
 * (how many events each participant has, where it is pinned, whom it
 * plays), and an id decides only between events of the same two
 * participants and between participants the request does not tell apart.
 * So a request that differs from another only in its ids gets the same
 * number of events at every position, the same unplaced counts and the
 * same violations but for the names in them; and the same schedule,
 * participant for participant, up to a symmetry of the request, whenever
 * the id only ever decided between participants that such a symmetry
 * exchanges (see Internal\StructuralOrder for when it can decide between
 * two that are not alike). The order does not read current positions, so
 * the moved events can follow the ids with or without objectives, and
 * with RepackObjective::LeastChurn so can the rest of the outcome. Pure: no clock reads, no I/O, no persistence.
 * Bounded: every search spends from the options' step budget (the steps
 * after the packing from a budget of their own of the same size) or has
 * a limit of its own, which is fixed at the default budget and above and
 * the budget's share of that below it, so that a smaller budget shortens
 * every search; the order of the participants alone has a limit no
 * budget changes. The outcome says whether a budget stopped a search
 * (RepackOutcome::isBudgetExhausted()).
 *
 * A shape-only grid is repacked exactly as the instant-based grid of the
 * same shape is: positions are all the algorithm reads. The assignments
 * then carry no kickoff. A grid of unbounded capacity is repacked with no
 * limit on the events sharing a slot other than that no participant is in
 * two of them; it never produces a CapacityExceeded for the grid as a
 * whole, and still produces one for a participant with more events than
 * free positions, which no capacity changes.
 *
 * Objectives are opt-in (RepackOptions::$objectives). With none, a
 * request is repacked as described above, and the current positions its
 * events may state are read for one thing only: the outcome's list of
 * moved events. With any, the schedule described above is made first and
 * is the one no other may be worse than on events placed, then on gap
 * slots. For the fewest moved events, further schedules are then made
 * with the events that can stay at their current positions held there
 * and the others placed around them; where one places fewer events or
 * leaves more gap slots, the held events may move again along the paths
 * of the last placement step and in the search for fewer gap slots, and
 * the ones that had to do with what is still worse are let go for the
 * next attempt, of which there are three at most. Single events are then
 * moved where that serves an objective and costs nothing that ranks
 * before it. The schedule returned is the best of them by events placed,
 * gap slots and the objectives in their order, and the first one on a
 * tie. All of that spends what the two budgets have left.
 *
 * Every outcome carries the figures a caller ranks outcomes by: unplaced
 * events, gap slots, lone-event sessions, idle sessions and moved events.
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
     * The schedule returned is the planned one, or the rounds as they
     * were laid out when those place more events or as many with fewer
     * gap slots; either is then searched for a placement of as many
     * events with fewer gap slots, when it has a gap. Which event of an
     * over-capacity participant is the one left out can change in that
     * search, and the reasons are then given again as above. When the
     * result places fewer events than a bound on what any placement can
     * hold, or has a gap, a third schedule is planned from the events in
     * the order of their participants, with the steps the two budgets
     * have left, and takes its place if it places more events, or as
     * many with fewer gap slots.
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

        // Normalize to dense integer indexes. The lists are put in id
        // order first, so that nothing depends on the order they came in;
        // the indexes the phases work on are then given in an order made
        // from what the request says about each participant and event,
        // and from its id only where that says nothing (StructuralOrder,
        // RoundColouring). Every internal tie-break is an index tie-break,
        // so a request that differs only in its ids is repacked alike
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

        /** @var array<string, int> $rankById Participant id => its place in id order */
        $rankById = [];
        /** @var list<Participant> $inIdOrder */
        $inIdOrder = [];
        foreach ($participantsById as $id => $participant) {
            $rankById[(string) $id] = count($inIdOrder);
            $inIdOrder[] = $participant;
        }

        $rankedEvents = [];
        foreach ($movables as $movable) {
            $rankedEvents[] = [
                $rankById[$movable->getParticipantA()->getId()],
                $rankById[$movable->getParticipantB()->getId()],
            ];
        }
        $rankedPins = [];
        foreach ($pins as $pin) {
            $rankedPins[] = [
                $rankById[$pin->getParticipantA()->getId()],
                $rankById[$pin->getParticipantB()->getId()],
                $pin->getSession(),
                $pin->getSlot(),
            ];
        }

        /** @var array<string, int> $participantIndexes */
        $participantIndexes = [];
        /** @var array<Participant> $participants */
        $participants = [];
        foreach (StructuralOrder::of(count($inIdOrder), $rankedEvents, $rankedPins) as $rank) {
            $participantIndexes[$inIdOrder[$rank]->getId()] = count($participants);
            $participants[] = $inIdOrder[$rank];
        }

        // An unbounded capacity becomes the number of events in the
        // request: no slot can ever hold more than all of them, so that
        // limit never binds, and no arithmetic on it can overflow
        $capacityPerSlot = $grid->getCapacityLimit() ?? max(1, count($movables) + count($pins));

        // The events by their two participants, lower index first, and by
        // id only between events of the same two: which of those is which
        // is all an event id decides. This is the repacker's order of
        // events, and the index of an event from here on
        $byPair = [];
        foreach ($movables as $at => $movable) {
            $a = $participantIndexes[$movable->getParticipantA()->getId()];
            $b = $participantIndexes[$movable->getParticipantB()->getId()];
            $byPair[$at] = [min($a, $b), max($a, $b)];
        }
        $order = array_keys($byPair);
        usort($order, static fn(int $x, int $y): int => [$byPair[$x], $x] <=> [$byPair[$y], $y]);

        /** @var list<array{int, int}> $pairs */
        $pairs = [];
        /** @var list<string> $ids */
        $ids = [];
        /** @var array<int, array{int, int}> $current Event => its current position, where it states one */
        $current = [];
        foreach ($order as $at) {
            $currentSession = $movables[$at]->getCurrentSession();
            $currentSlot = $movables[$at]->getCurrentSlot();
            if ($currentSession !== null && $currentSlot !== null) {
                $current[count($pairs)] = [$currentSession, $currentSlot];
            }
            $pairs[] = $byPair[$at];
            $ids[] = $movables[$at]->getId();
        }

        /** @var array<int, array<int, array<int>>> $pinSlots */
        $pinSlots = [];
        /** @var array<int, array<int, int>> $pinCounts */
        $pinCounts = [];
        /** @var list<array{list<int>, int, int}> $pinned Each pinned event: its participants and its position */
        $pinned = [];
        foreach ($pins as $pin) {
            $ofPin = [];
            foreach ($pin->getParticipants() as $participant) {
                $pinSlots[$pin->getSession()][$participantIndexes[$participant->getId()]][] = $pin->getSlot();
                $ofPin[] = $participantIndexes[$participant->getId()];
            }
            $pinCounts[$pin->getSession()][$pin->getSlot()]
                = ($pinCounts[$pin->getSession()][$pin->getSlot()] ?? 0) + 1;
            $pinned[] = [$ofPin, $pin->getSession(), $pin->getSlot()];
        }
        foreach ($pinSlots as &$byParticipant) {
            foreach ($byParticipant as &$slots) {
                sort($slots);
            }
            unset($slots);
        }
        unset($byParticipant);

        // Two budgets: one for the planning and the packing, and one of the
        // same size for the steps after them, so that a run whose earlier
        // searches spent the first still gets its last placement step
        $budget = new StepBudget($options->stepBudget, $options->stepBudget);
        $recolourBudget = new StepBudget($options->stepBudget, $options->stepBudget);
        $layoutStopped = false;

        // What the outcome reports of a schedule, and what the objectives,
        // when the options name any, rank one by
        $metrics = new PlacementMetrics($pairs, $pinned, $current);
        $objectives = $options->objectives;
        $score = $objectives === []
            ? null
            : static fn(array $positions): array => $metrics->score($positions, $objectives);

        // The schedule of the request as it stands. With no objective it is
        // the outcome; with one, it is the schedule no other may be worse
        // than on events placed and on gap slots
        [$positions, $unplaced, $capacityViolations] = $this->solve(
            array_keys($pairs),
            $pairs,
            $ids,
            $participants,
            $pinSlots,
            $pinCounts,
            $slotCounts,
            $capacityPerSlot,
            $options,
            $budget,
            $recolourBudget,
            $score,
            $layoutStopped
        );

        if ($score !== null) {
            if ($current !== [] && in_array(RepackObjective::LeastChurn, $objectives, true)) {
                [$positions, $unplaced, $capacityViolations] = $this->keepingCurrentPositions(
                    $positions,
                    $unplaced,
                    $capacityViolations,
                    $metrics,
                    $current,
                    $pairs,
                    $ids,
                    $participants,
                    $participantIndexes,
                    $pinSlots,
                    $pinCounts,
                    $slotCounts,
                    $capacityPerSlot,
                    $options,
                    $budget,
                    $recolourBudget,
                    $layoutStopped
                );
            }

            // And single events are moved where that serves an objective
            // and costs nothing that outranks it
            $indexById = array_flip($ids);
            $positions = (new ObjectivePolish(
                $metrics,
                $objectives,
                $pairs,
                $pinSlots,
                $pinCounts,
                $slotCounts,
                $capacityPerSlot,
                $current,
                $recolourBudget
            ))->improve(
                $positions,
                array_map(static fn(UnplacedEvent $event): int => $indexById[$event->getEventId()], $unplaced)
            );
        }

        usort(
            $unplaced,
            static fn(UnplacedEvent $x, UnplacedEvent $y): int => strcmp($x->getEventId(), $y->getEventId())
        );

        // The audit reports what is true of the returned schedule
        $violations = (new RepackAuditor())->audit(
            $positions,
            $pairs,
            $pins,
            $ids,
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
        // The planner reports the over-capacity participants by shortfall
        // and then by index; the outcome lists them by shortfall and then
        // by id, the grid's own violation after them
        usort($capacityViolations, static function (RepackViolation $x, RepackViolation $y): int {
            if (!$x instanceof CapacityExceeded || !$y instanceof CapacityExceeded) {
                return 0;
            }
            $px = $x->getParticipant();
            $py = $y->getParticipant();
            if ($px === null || $py === null) {
                return ($px === null) <=> ($py === null);
            }

            $byShortfall = $y->getShortfall() <=> $x->getShortfall();

            return $byShortfall !== 0 ? $byShortfall : strcmp($px->getId(), $py->getId());
        });
        foreach ($capacityViolations as $violation) {
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

        $assignments = [];
        foreach ($positions as $eventIndex => [$session, $slot]) {
            $assignments[] = new SlotAssignment(
                $ids[$eventIndex],
                $session,
                $slot,
                $grid->hasInstants() ? $grid->getSlotTime($session, $slot) : null
            );
        }
        usort(
            $assignments,
            static fn(SlotAssignment $x, SlotAssignment $y): int => strcmp($x->getEventId(), $y->getEventId())
        );

        $movedIds = [];
        foreach ($metrics->moved($positions) as $eventIndex) {
            $movedIds[] = $ids[$eventIndex];
        }
        usort($movedIds, strcmp(...));

        $outcome = new RepackOutcome(
            $assignments,
            $unplaced,
            $violations,
            $budget->stoppedASearch() || $recolourBudget->stoppedASearch() || $layoutStopped,
            $metrics->loneEventSessions($positions),
            $metrics->idleSessions($positions),
            $movedIds
        );

        if ($options->throwOnViolations && !$outcome->isClean()) {
            throw new RepackViolationsException($outcome);
        }

        return $outcome;
    }

    /**
     * How many schedules are made around the events that stay where they
     * are, at most, when the fewest moved events are asked for: the first
     * keeps every event that can stay, and each one after it lets go of
     * the events that had to do with what the one before it did worse.
     */
    private const int KEEP_ATTEMPTS = 3;

    /**
     * The schedule with the fewest moved events that is found, for a
     * request whose options ask for them: never one that places fewer
     * events than the schedule given, or as many with more gap slots.
     *
     * Every event that can stay at its current position is held there, as
     * a pinned event is, and the other events are placed around them by
     * the same steps as any request (solve()). Which events can stay is
     * decided by taking them in the repacker's order of events: an event
     * stays when its position is on the grid, has room left, and holds no
     * other event, pinned or staying, of either of its participants.
     *
     * Where that schedule places fewer events than the one given, the
     * last placement step is run on it with the staying events free to
     * move again, until it places as many: an alternating path moves a
     * few events and leaves the rest where they are. Where it then has
     * more gap slots than the one given, the search for fewer gap slots
     * is run on it in the same way; it changes the events around a gap
     * first.
     *
     * Where it is still worse on one of the two, the staying events that
     * had to do with it are let go (those a participant with a gap has in
     * the session of the gap, or, when that lets go of nothing, every
     * event of that session; and every event of the participants of an
     * unplaced event, when more events are unplaced than in the schedule
     * given), and the rest are held for another attempt; KEEP_ATTEMPTS
     * bounds how often. The attempts stop at the first that is no worse
     * than the schedule given on those two figures, because one that
     * holds fewer events can only move more. Of all the schedules made,
     * the given one included, the best by events placed, then gap slots,
     * then the objectives in their order is returned, and the given one
     * on a tie.
     *
     * The attempts spend what the two budgets have left and have none of
     * their own. A participant never has more events staying than it has
     * positions free, so the capacity figures of an attempt are those of
     * the request once the staying events are added back to the demand
     * and to the capacity it was planned with.
     *
     * @param array<int, array{int, int}> $positions The schedule to improve on
     * @param list<UnplacedEvent> $unplaced Its unplaced events
     * @param array<RepackViolation> $capacityViolations Its capacity violations
     * @param array<int, array{int, int}> $current Event => current position
     * @param list<array{int, int}> $pairs
     * @param list<string> $ids
     * @param array<Participant> $participants
     * @param array<string, int> $participantIndexes
     * @param array<int, array<int, array<int>>> $pinSlots
     * @param array<int, array<int, int>> $pinCounts
     * @param array<int, int> $slotCounts
     *
     * @return array{array<int, array{int, int}>, list<UnplacedEvent>, array<RepackViolation>}
     *         The positions, the unplaced events and the capacity violations of the schedule chosen
     */
    private function keepingCurrentPositions(
        array $positions,
        array $unplaced,
        array $capacityViolations,
        PlacementMetrics $metrics,
        array $current,
        array $pairs,
        array $ids,
        array $participants,
        array $participantIndexes,
        array $pinSlots,
        array $pinCounts,
        array $slotCounts,
        int $capacityPerSlot,
        RepackOptions $options,
        StepBudget $budget,
        StepBudget $recolourBudget,
        bool &$layoutStopped
    ): array {
        $objectives = $options->objectives;
        $indexById = array_flip($ids);

        // Which events can stay, taken in the repacker's order of events,
        // which is the order of their indexes
        /** @var array<int, array{int, int}> $kept Event => the position it stays at */
        $kept = [];
        $taken = $pinCounts;
        $busy = [];
        foreach ($current as $eventIndex => [$session, $slot]) {
            if ($slot >= ($slotCounts[$session] ?? 0) || ($taken[$session][$slot] ?? 0) >= $capacityPerSlot) {
                continue;
            }
            foreach ($pairs[$eventIndex] as $pid) {
                if (isset($busy[$session][$slot][$pid]) || in_array($slot, $pinSlots[$session][$pid] ?? [], true)) {
                    continue 2;
                }
            }
            $kept[$eventIndex] = [$session, $slot];
            $taken[$session][$slot] = ($taken[$session][$slot] ?? 0) + 1;
            foreach ($pairs[$eventIndex] as $pid) {
                $busy[$session][$slot][$pid] = true;
            }
        }

        $given = [count($unplaced), $metrics->gapSlots($positions)];
        $best = [...$given, ...$metrics->score($positions, $objectives)];

        for ($attempt = 0; $attempt < self::KEEP_ATTEMPTS && $kept !== []; ++$attempt) {
            $heldSlots = $pinSlots;
            $heldCounts = $pinCounts;
            $keptOf = [];
            foreach ($kept as $eventIndex => [$session, $slot]) {
                $heldCounts[$session][$slot] = ($heldCounts[$session][$slot] ?? 0) + 1;
                foreach ($pairs[$eventIndex] as $pid) {
                    $heldSlots[$session][$pid][] = $slot;
                    $keptOf[$pid] = ($keptOf[$pid] ?? 0) + 1;
                }
            }
            foreach ($heldSlots as &$byParticipant) {
                foreach ($byParticipant as &$slots) {
                    sort($slots);
                }
                unset($slots);
            }
            unset($byParticipant);

            [$placed, $left, $violations, $shortfalls] = $this->solve(
                array_values(array_diff(array_keys($pairs), array_keys($kept))),
                $pairs,
                $ids,
                $participants,
                $heldSlots,
                $heldCounts,
                $slotCounts,
                $capacityPerSlot,
                $options,
                $budget,
                $recolourBudget,
                static fn(array $around): array => $metrics->score($around + $kept, $objectives),
                $layoutStopped
            );
            $placed += $kept;
            ksort($placed);

            $leftOut = array_map(static fn(UnplacedEvent $event): int => $indexById[$event->getEventId()], $left);
            sort($leftOut);
            $leftBefore = $leftOut;

            // More events left out than in the schedule given: the staying
            // events may move again to let them in, along as short a path
            // as the last placement step finds
            if (count($leftOut) > $given[0]) {
                $leftOut = (new LeftoverRecolourer($recolourBudget))->place(
                    $pairs,
                    $placed,
                    $leftOut,
                    $pinSlots,
                    $pinCounts,
                    $slotCounts,
                    $capacityPerSlot,
                    $given[0]
                );
                ksort($placed);
            }

            // As many placed and more gap slots: the events around the
            // gaps may move again to close them
            if (count($leftOut) <= $given[0] && [count($leftOut), $metrics->gapSlots($placed)] > $given) {
                $leftOut = (new GapSearch($recolourBudget))->improve(
                    $pairs,
                    $placed,
                    $leftOut,
                    $pinSlots,
                    $pinCounts,
                    $slotCounts,
                    $capacityPerSlot
                );
                ksort($placed);
            }

            if ($leftOut !== $leftBefore) {
                // Other events are unplaced now, so their reasons are given
                // again
                $left = $this->label($leftOut, [], $shortfalls, $pairs, $ids, $participants, $budget, true);
            }

            $reached = [count($leftOut), $metrics->gapSlots($placed)];
            $key = [...$reached, ...$metrics->score($placed, $objectives)];
            if ($key < $best) {
                $best = $key;
                $positions = $placed;
                $unplaced = $left;
                $capacityViolations = [];
                foreach ($violations as $violation) {
                    if ($violation instanceof CapacityExceeded) {
                        $participant = $violation->getParticipant();
                        $staying = $participant instanceof Participant
                            ? $keptOf[$participantIndexes[$participant->getId()]] ?? 0
                            : count($kept);
                        $violation = new CapacityExceeded(
                            $participant,
                            $violation->getDemand() + $staying,
                            $violation->getCapacity() + $staying
                        );
                    }
                    $capacityViolations[] = $violation;
                }
            }
            if ($reached <= $given) {
                break;
            }

            // Let go of the events this attempt went wrong around
            $withGap = $metrics->gaps($placed);
            $short = [];
            if ($reached[0] > $given[0]) {
                foreach ($leftOut as $eventIndex) {
                    foreach ($pairs[$eventIndex] as $pid) {
                        $short[$pid] = true;
                    }
                }
            }
            $still = [];
            foreach ($kept as $eventIndex => $position) {
                foreach ($pairs[$eventIndex] as $pid) {
                    if (isset($short[$pid]) || isset($withGap[$pid][$position[0]])) {
                        continue 2;
                    }
                }
                $still[$eventIndex] = $position;
            }
            if (count($still) === count($kept)) {
                // Nothing of theirs is staying there: every event of the
                // sessions with a gap, then
                $sessions = [];
                foreach ($withGap as $bySession) {
                    $sessions += $bySession;
                }
                $still = array_filter($kept, static fn(array $position): bool => !isset($sessions[$position[0]]));
                if (count($still) === count($kept)) {
                    break;
                }
            }
            $kept = $still;
        }

        return [$positions, $unplaced, $capacityViolations];
    }

    /**
     * A schedule of some of the movable events around what is held in
     * place: the steps of a repack from the laying out of the rounds to
     * the reasons of the events left unplaced.
     *
     * A repack with no objective calls this once, with every movable
     * event and the pinned events. The fewest moved events call it again
     * with the events that do not stay where they are, and the staying
     * ones among what is held.
     *
     * The objectives, when a score is given, decide here only where
     * nothing that outranks them is at stake: between the planned
     * schedule and the rounds as they were laid out when both place as
     * many events and neither has a gap, and between the schedule so far
     * and the third one when both place as many events with as many gap
     * slots. Either way the schedule returned places as many events, with
     * as many gap slots, as it would with no score.
     *
     * @param list<int> $subset The events to place, as indexes into $allPairs and $ids, ascending
     * @param list<array{int, int}> $allPairs Every movable event's two participants, lower index
     *                                        first, in the repacker's order of events (by those
     *                                        two, then by id)
     * @param list<string> $ids Every movable event's id, in the same order
     * @param array<Participant> $participants
     * @param array<int, array<int, array<int>>> $pinSlots Session => participant => held slots
     * @param array<int, array<int, int>> $pinCounts Session => slot => events held there
     * @param array<int, int> $slotCounts
     * @param StepBudget $budget For the planning and the packing
     * @param StepBudget $recolourBudget For the steps after the packing
     * @param (\Closure(array<int, array{int, int}>): list<int>)|null $score What the objectives make
     *        of a schedule of the subset, given as positions by index into $ids; null with no objective
     * @param bool $layoutStopped Set when the caller's budget cut the laying out of the rounds short
     *
     * @return array{array<int, array{int, int}>, list<UnplacedEvent>, array<RepackViolation>, array<int, int>}
     *         The positions by index into $ids (ascending), the events of the subset with none and
     *         their reasons, the planner's capacity violations, and the shortfall of every
     *         over-capacity participant by its index
     */
    private function solve(
        array $subset,
        array $allPairs,
        array $ids,
        array $participants,
        array $pinSlots,
        array $pinCounts,
        array $slotCounts,
        int $capacityPerSlot,
        RepackOptions $options,
        StepBudget $budget,
        StepBudget $recolourBudget,
        ?\Closure $score,
        bool &$layoutStopped
    ): array {
        // The events of the subset, still in the repacker's order
        $pairsInOrder = [];
        foreach ($subset as $index) {
            $pairsInOrder[] = $allPairs[$index];
        }

        // Then by round, so that the planner fills sessions with whole
        // rounds (RoundColouring). An event the rounds could not hold
        // comes after every round
        /** @var array<int, int> $firstPlaceOf Session => the place in grid order of its first slot */
        $firstPlaceOf = [];
        $positionCount = 0;
        foreach ($slotCounts as $session => $slotCount) {
            $firstPlaceOf[$session] = $positionCount;
            $positionCount += $slotCount;
        }
        // The layout's search spends no step of the two budgets below. It
        // has a work limit of its own, and the caller's budget decides how
        // much of that limit it gets: all of it at the default and above
        $layoutBudget = new StepBudget(PHP_INT_MAX, $options->stepBudget);
        $roundPositions = RoundColouring::of(
            $pairsInOrder,
            $pinSlots,
            $pinCounts,
            $slotCounts,
            $capacityPerSlot,
            $layoutBudget
        );
        $roundOf = [];
        foreach (array_keys($pairsInOrder) as $at) {
            $roundOf[$at] = isset($roundPositions[$at])
                ? $firstPlaceOf[$roundPositions[$at][0]] + $roundPositions[$at][1]
                : $positionCount;
        }
        $byRound = array_keys($pairsInOrder);
        usort($byRound, static fn(int $x, int $y): int => [$roundOf[$x], $x] <=> [$roundOf[$y], $y]);

        /** @var array<string> $eventIds */
        $eventIds = [];
        /** @var array<int, array{int, int}> $edges */
        $edges = [];
        /** @var array<int, array{int, int}> $roundLayout Event index => the position of its round */
        $roundLayout = [];
        /** @var list<int> $indexOf Event index here => its index into $ids */
        $indexOf = [];
        foreach ($byRound as $at) {
            if (isset($roundPositions[$at])) {
                $roundLayout[count($edges)] = $roundPositions[$at];
            }
            $indexOf[] = $subset[$at];
            $eventIds[] = $ids[$subset[$at]];
            $edges[] = $pairsInOrder[$at];
        }

        // A schedule by index into $ids, which is how the caller, and so
        // the score, has it
        $asGiven = static function (array $schedule) use ($indexOf): array {
            $keyed = [];
            foreach ($schedule as $eventIndex => $position) {
                $keyed[$indexOf[$eventIndex]] = $position;
            }
            ksort($keyed);

            return $keyed;
        };
        $scoreOf = static fn(array $schedule): array => $score instanceof \Closure ? $score($asGiven($schedule)) : [];

        // The planned schedule: sessions by the weights, then slots
        [$positions, $stillLeft, $dropped, $plan, $ceiling] = $this->planned(
            $edges,
            $eventIds,
            $participants,
            $pinSlots,
            $pinCounts,
            $slotCounts,
            $capacityPerSlot,
            $options,
            $budget,
            $recolourBudget
        );

        // Contiguity comes ahead of the weights the sessions were chosen
        // by. The rounds are a schedule themselves, made with no regard to
        // the weights: where they place more events than the planned
        // schedule, or as many with fewer gap slots, they are the schedule.
        // Where neither has a gap and they place as many, the objectives
        // decide, when there are any: nothing that follows depends on
        // which of two such schedules it is
        $roundsLeft = array_values(array_diff(array_keys($edges), array_keys($roundLayout)));
        $ofRounds = [count($roundsLeft), self::gapSlots($roundLayout, $edges, $pinSlots)];
        $ofPlanned = [count($stillLeft), self::gapSlots($positions, $edges, $pinSlots)];
        if (
            $ofRounds < $ofPlanned
            || ($score instanceof \Closure && $ofRounds === $ofPlanned && $ofRounds[1] === 0 && $scoreOf($roundLayout) < $scoreOf($positions))
        ) {
            $positions = $roundLayout;
            $stillLeft = $roundsLeft;
        }

        // And where the schedule still has a gap, look for a placement of
        // as many events that has fewer gap slots. It spends from the
        // second budget, and a schedule with no gap never reaches it
        $stillLeft = (new GapSearch($recolourBudget))->improve(
            $edges,
            $positions,
            $stillLeft,
            $pinSlots,
            $pinCounts,
            $slotCounts,
            $capacityPerSlot
        );

        // Both schedules so far come from the events taken round by round.
        // Where that still leaves something to gain (fewer events placed
        // than the ceiling no placement can pass, or a gap), the events
        // are planned once more in the order of their participants, which
        // drops other events of an over-capacity participant and fills the
        // sessions another way. It is a third schedule, made on what the
        // two budgets have left, and it is returned only when it places
        // more events, or as many with fewer gap slots (or, with
        // objectives, as many with as many and a better score). The order
        // is the structural one, so it depends on the ids no more than the
        // rounds
        $gapSlots = self::gapSlots($positions, $edges, $pinSlots);
        $byParticipants = array_flip($byRound);
        ksort($byParticipants);
        $byParticipants = array_values($byParticipants);
        if (
            (($ceiling !== null && count($positions) < $ceiling) || $gapSlots > 0)
            && $byParticipants !== array_keys($byParticipants)
        ) {
            $otherEdges = [];
            $otherIds = [];
            foreach ($byParticipants as $eventIndex) {
                $otherEdges[] = $edges[$eventIndex];
                $otherIds[] = $eventIds[$eventIndex];
            }
            [$otherPositions, $otherLeft, $otherDropped, $otherPlan] = $this->planned(
                $otherEdges,
                $otherIds,
                $participants,
                $pinSlots,
                $pinCounts,
                $slotCounts,
                $capacityPerSlot,
                $options,
                $budget,
                $recolourBudget
            );

            // Back to the indexes everything else is written in
            $inRoundOrder = [];
            foreach ($otherPositions as $at => $position) {
                $inRoundOrder[$byParticipants[$at]] = $position;
            }
            ksort($inRoundOrder);
            $otherPositions = $inRoundOrder;
            $inRoundOrder = [];
            foreach ($otherDropped as $at => $drop) {
                $inRoundOrder[$byParticipants[$at]] = $drop;
            }
            $otherDropped = $inRoundOrder;
            $otherLeft = array_map(static fn(int $at): int => $byParticipants[$at], $otherLeft);
            sort($otherLeft);

            if (count($otherLeft) <= count($stillLeft)) {
                $otherLeft = (new GapSearch($recolourBudget))->improve(
                    $edges,
                    $otherPositions,
                    $otherLeft,
                    $pinSlots,
                    $pinCounts,
                    $slotCounts,
                    $capacityPerSlot
                );
            }
            if (
                [count($otherLeft), self::gapSlots($otherPositions, $edges, $pinSlots), ...$scoreOf($otherPositions)]
                < [count($stillLeft), $gapSlots, ...$scoreOf($positions)]
            ) {
                $positions = $otherPositions;
                $stillLeft = $otherLeft;
                $dropped = $otherDropped;
                $plan = $otherPlan;
            }
        }

        $layoutStopped = $layoutStopped || $layoutBudget->stoppedASearch();

        return [
            $asGiven($positions),
            $this->label($stillLeft, $dropped, $plan->shortfalls, $edges, $eventIds, $participants, $budget),
            $plan->violations,
            $plan->shortfalls,
        ];
    }

    /**
     * The planned schedule of the events taken in the order given: the
     * session of every event by the weights, each session packed, what
     * that leaves over swept to the first free position, and the last
     * placement step for what is still unplaced.
     *
     * The planner and the packer take the events in index order, so the
     * order of the list is what this schedule depends on beside the
     * request itself.
     *
     * @param array<int, array{int, int}> $edges Event index => participant index pair
     * @param array<string> $eventIds Event index => caller event id
     * @param array<Participant> $participants
     * @param array<int, array<int, array<int>>> $pinSlots
     * @param array<int, array<int, int>> $pinCounts
     * @param array<int, int> $slotCounts
     * @param StepBudget $budget For the planning and the packing
     * @param StepBudget $recolourBudget For the last placement step
     *
     * @return array{array<int, array{int, int}>, list<int>, array<int, UnplacedEvent>, LoadPlan, int|null}
     *         The positions by event index, the events with none (ascending), the planner's
     *         over-capacity drops by event index, the plan, and the ceiling of the last placement
     *         step (a number of placed events no placement exceeds), or null when every event
     *         was placed without it
     */
    private function planned(
        array $edges,
        array $eventIds,
        array $participants,
        array $pinSlots,
        array $pinCounts,
        array $slotCounts,
        int $capacityPerSlot,
        RepackOptions $options,
        StepBudget $budget,
        StepBudget $recolourBudget
    ): array {
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
        foreach ($slotCounts as $session => $slotCount) {
            $packed = $packer->pack(
                $edgesBySession[$session] ?? [],
                $pinSlots[$session] ?? [],
                $pinCounts[$session] ?? [],
                $slotCount,
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
        $stillLeft = $this->sweep($leftovers, $edges, $positions, $pinSlots, $pinCounts, $slotCounts, $capacityPerSlot);

        // Last resort, reached only when an event is still unplaced: move
        // placed events along alternating paths to open a position for
        // it. The over-capacity drops are offered too: a participant can
        // never hold more positions than it has free, so placing one only
        // changes which of its events is the one that does not fit.
        $ceiling = null;
        if ($stillLeft !== [] || $dropped !== []) {
            $recolourer = new LeftoverRecolourer($recolourBudget);
            $stillLeft = $recolourer->place(
                $edges,
                $positions,
                [...$stillLeft, ...array_keys($dropped)],
                $pinSlots,
                $pinCounts,
                $slotCounts,
                $capacityPerSlot,
                $plan->leftOutAtLeast
            );
            $ceiling = $recolourer->lastCeiling();
        }

        return [$positions, $stillLeft, $dropped, $plan, $ceiling];
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
     * remaining events, the last in the repacker's order of events first,
     * until its shortfall is covered. A participant never holds more positions than it has free,
     * so it always has enough events still unplaced.
     *
     * @param list<int> $stillLeft Event indexes, ascending
     * @param array<int, UnplacedEvent> $dropped Event index => the planner's over-capacity drop
     * @param array<int, int> $shortfalls Over-capacity participant index => shortfall
     * @param array<int, array{int, int}> $edges
     * @param array<string> $eventIds
     * @param array<Participant> $participants
     * @param StepBudget $budget The budget the planner's search for shared events follows here too
     * @param bool $again Give the reasons again by the planner's rule whatever the drops were:
     *                    for a schedule whose unplaced events are no longer the ones a plan left
     *
     * @return list<UnplacedEvent> In ascending event id order
     */
    private function label(
        array $stillLeft,
        array $dropped,
        array $shortfalls,
        array $edges,
        array $eventIds,
        array $participants,
        StepBudget $budget,
        bool $again = false
    ): array {
        $left = array_fill_keys($stillLeft, true);
        $dropsAllLeft = !$again && array_diff_key($dropped, $left) === [];

        /** @var array<int, int> $overCapacityFor Event index => the participant it is unplaced for */
        $overCapacityFor = [];
        if (!$dropsAllLeft) {
            $candidates = [];
            foreach ($stillLeft as $eventIndex) {
                $candidates[$eventIndex] = $edges[$eventIndex];
            }

            $need = $shortfalls;
            foreach (LoadPlanner::sharedDrops($candidates, $shortfalls, budget: $budget) as $eventIndex) {
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
     * The gap slots of a schedule, counted as the audit counts them: per
     * participant and session, over its pinned and movable slots together,
     * and only where it has a movable event in the session.
     *
     * @param array<int, array{int, int}> $positions Event index => [session, slot]
     * @param array<int, array{int, int}> $edges
     * @param array<int, array<int, array<int>>> $pinSlots
     */
    private static function gapSlots(array $positions, array $edges, array $pinSlots): int
    {
        /** @var array<int, array<int, array<int, true>>> $held Participant => session => slots */
        $held = [];
        foreach ($positions as $eventIndex => [$session, $slot]) {
            foreach ($edges[$eventIndex] as $pid) {
                $held[$pid][$session][$slot] = true;
            }
        }

        $gapSlots = 0;
        foreach ($held as $pid => $bySession) {
            foreach ($bySession as $session => $slots) {
                foreach ($pinSlots[$session][$pid] ?? [] as $slot) {
                    $slots[$slot] = true;
                }
                $first = PHP_INT_MAX;
                $last = 0;
                foreach (array_keys($slots) as $slot) {
                    $first = min($first, $slot);
                    $last = max($last, $slot);
                }
                $gapSlots += $last - $first + 1 - count($slots);
            }
        }

        return $gapSlots;
    }

    /**
     * The final sweep: each event without a position, in the order given,
     * takes the earliest position in grid order with capacity left and
     * both participants free, and the ones no position holds are
     * returned.
     *
     * What every position holds is counted once and kept up to date as
     * events are placed. It was counted afresh for every event, which
     * made the sweep the slowest thing a repack with a small budget did:
     * such a repack leaves most events to it, and the work grew with the
     * events left over times the events placed.
     *
     * @param list<int> $leftovers Event indexes with no position, each once
     * @param array<int, array{int, int}> $edges
     * @param array<int, array{int, int}> $positions Placed event index => [session, slot]; the events
     *                                               the sweep places are added
     * @param array<int, array<int, array<int>>> $pinSlots
     * @param array<int, array<int, int>> $pinCounts
     * @param array<int, int> $slotCounts
     *
     * @return list<int> The events no position holds, in the order given
     */
    private function sweep(
        array $leftovers,
        array $edges,
        array &$positions,
        array $pinSlots,
        array $pinCounts,
        array $slotCounts,
        int $capacityPerSlot
    ): array {
        if ($leftovers === []) {
            return [];
        }

        /** @var array<int, array<int, int>> $occupancy Session => slot => movable events there */
        $occupancy = [];
        /** @var array<int, array<int, array<int, true>>> $busy Session => slot => participants of those events */
        $busy = [];
        foreach ($positions as $otherIndex => [$session, $slot]) {
            $occupancy[$session][$slot] = ($occupancy[$session][$slot] ?? 0) + 1;
            foreach ($edges[$otherIndex] as $pid) {
                $busy[$session][$slot][$pid] = true;
            }
        }

        $stillLeft = [];
        foreach ($leftovers as $eventIndex) {
            [$a, $b] = $edges[$eventIndex];
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

                    $positions[$eventIndex] = [$session, $slot];
                    $occupancy[$session][$slot] = ($occupancy[$session][$slot] ?? 0) + 1;
                    $busy[$session][$slot][$a] = true;
                    $busy[$session][$slot][$b] = true;
                    continue 3;
                }
            }
            $stillLeft[] = $eventIndex;
        }

        return $stillLeft;
    }
}
