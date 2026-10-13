<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use Closure;
use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;

/**
 * Searches the roles of a round robin whose pairings and rounds are given:
 * it builds the events in order, puts each one to the constraints, and
 * changes roles, and nothing else, where they reject one.
 *
 * The events to build are handed over as slots, of four kinds:
 *
 * - an event of the first leg whose role is free: the event as given is
 *   tried first and its reverse second;
 * - an event of the first leg that is `fixed`: it has the one role it is
 *   given. A search in which every first-leg event is fixed is a replay:
 *   it says whether these roles stand, and where they first do not;
 * - an event of a later leg whose strategy states its roles as a
 *   preference (LegPlanContribution::$rolesArePreferences, the shuffled
 *   legs). The event the strategy returns is tried first and its reverse
 *   second;
 * - an event of a later leg whose strategy states a rule (mirrored and
 *   repeated legs). It has the one role the strategy gives it, and can
 *   only change when the first-leg event it is derived from does.
 *
 * Where the role assignment states a rule for the first leg, an event of
 * the first leg is put to that rule before it is put to the constraints,
 * and the rule's rejection is a rejection like theirs.
 *
 * When every role of an event is rejected, the search goes back to an
 * earlier event that has a role left, takes it and comes forward again.
 * Which earlier event depends on why the roles were rejected. Where each
 * was rejected for a reason that only the events of that event's own
 * participants can change, it is the latest event that shares a
 * participant with the rejected one, or with one that sent the search back
 * to it before: no other event can change the verdict, so nothing is lost
 * by passing over the ones in between, and a search that ends without
 * roles has shown that there are none. Such a reason is a constraint set
 * whose verdict can depend only on those events
 * (ConstraintPurity::readsOwnEventsOnly()), or the rule of the first leg
 * where it is one the library knows. Where a role was rejected for any
 * other reason it is the event before, one at a time, which shows the same
 * and takes longer.
 *
 * With a rule for the first leg, a set that holds both kinds of constraint
 * is asked one constraint at a time about an event it rejected, and the
 * rejection counts as one of the first kind when a constraint of that kind
 * rejects the event by itself. Without such a rule a set is asked as a
 * whole, as it was before the first leg could be searched without
 * backtracking.
 *
 * A first leg whose roles the search changed is put to the caller once it
 * is whole (`$keepsFirstLeg`), where one is given: a leg that is not kept
 * is a dead end, and the search goes on to the next choice of roles, one
 * event back at a time.
 *
 * A strategy that states a preference is asked for each event once, the
 * first time the search comes to it, in the order of the events. Going
 * back does not ask again: a strategy that draws makes one draw for each
 * event the search reached, however much was searched. A strategy that
 * states a rule is asked again whenever the search comes forward to the
 * event, because the first-leg event it derives from may have changed.
 *
 * Two bounds stop a search, and a search that a bound stopped says so
 * (wasStoppedByABound()): it has not shown that no roles exist.
 *
 * - the attempt limit: every event put to the constraints is one attempt.
 *   The backtracking generator passes what is left of its step budget;
 * - the rejection limit: every attempt that is rejected, by the rule of the
 *   first leg or by the constraints, and every first leg that is not kept.
 *   An attempt that is accepted at once costs nothing against it, so a
 *   schedule that needs no search is never stopped by it, whatever its
 *   size.
 *
 * @internal Not public API: the round-robin scheduler runs it for the legs
 *           after the first, for the roles of the first leg where the role
 *           assignment states a rule, and with
 *           RoundRobinOptions(backtracking: true) for the roles of the
 *           first leg of every layout the search finds.
 */
final class LegRoleSearch
{
    /**
     * Rejected attempts allowed in one search. See
     * docs/design/backtracking-generation.md for what was measured.
     */
    public const int REJECTION_LIMIT = 20_000;

    /**
     * Whether what the constraints say about an event can depend only on
     * the events of its own two participants.
     */
    private readonly bool $verdictsAreLocal;

    /**
     * Whether what the rule of the first leg says about an event can
     * depend only on the events of its own two participants.
     */
    private readonly bool $ruleIsLocal;

    /**
     * Whether a set that is not local as a whole is asked one constraint at
     * a time about an event it rejected, to find a local reason for it.
     */
    private readonly bool $explainsRejections;

    private int $attempts = 0;

    private int $rejections = 0;

    private int $firstLegsNotKept = 0;

    private bool $stoppedByABound = false;

    /** @var array{leg: int, round: int, pairing: array<Participant>, event?: Event, source?: int, fixed?: bool}|null */
    private ?array $slotWithoutEvent = null;

    private ?int $furthestSlot = null;

    /** @var list<Event> */
    private array $furthestEvents = [];

    /** @var list<array{Event, SchedulingContext}> */
    private array $furthestRejections = [];

    /**
     * @param ConstraintSet|null $constraints Null accepts every event
     * @param bool $rolesArePreferences Whether a later-leg event the constraints reject may be reversed
     * @param bool $contextHoldsOwnRound Whether the context an event is put to the constraints with
     *                                   holds the events already made in the event's own round (the
     *                                   backtracking search) or the earlier rounds only (the circle
     *                                   layout)
     * @param ConstraintInterface|null $firstLegRule The rule the role assignment holds the first leg
     *                                               to: a first-leg event whose role is free is put to
     *                                               it before the constraints. Null for no rule
     * @param (Closure(list<Event>): bool)|null $keepsFirstLeg Asked about a first leg whose roles the
     *                                                         search changed, once it is whole: whether
     *                                                         it may stand. Null lets every leg stand
     */
    public function __construct(
        private readonly ?ConstraintSet $constraints,
        private readonly LegStrategyInterface $strategy,
        private readonly bool $rolesArePreferences,
        private readonly bool $contextHoldsOwnRound,
        private readonly ?ConstraintInterface $firstLegRule = null,
        private readonly ?Closure $keepsFirstLeg = null
    ) {
        $this->verdictsAreLocal = ConstraintPurity::readsOwnEventsOnly($constraints);
        $this->ruleIsLocal = ConstraintPurity::ruleReadsOwnEventsOnly($firstLegRule);
        $this->explainsRejections = $firstLegRule instanceof ConstraintInterface
            && $constraints instanceof ConstraintSet
            && $constraints::class === ConstraintSet::class;
    }

    /**
     * One event for each slot, in the order of the slots, or null when the
     * constraints accept no choice of roles, a bound stopped the search, or
     * the strategy returned no event for a slot.
     *
     * A slot holds its leg, its round number and its two participants
     * (`pairing`), and says where its event comes from:
     *
     * - `event`: an event of the first leg, to be kept or reversed, or
     *   with `fixed` set to true, to be kept;
     * - `source`: the index of the first-leg slot whose event, as the
     *   search has it, is the pairing handed to the strategy;
     * - neither: `pairing` is handed to the strategy as it is, in the roles
     *   the leg has before the strategy acts.
     *
     * @param SchedulingContext $context The events generated before the first slot, with the leg of
     *                                   that slot as its current leg
     * @param list<array{leg: int, round: int, pairing: array<Participant>, event?: Event, source?: int, fixed?: bool}> $slots
     *                                   In generation order; legs do not decrease, and a source comes
     *                                   before the slots that name it
     * @param int $attemptLimit Attempts allowed
     * @param int $rejectionLimit Rejected attempts allowed
     * @return list<Event>|null
     */
    public function search(SchedulingContext $context, array $slots, int $attemptLimit, int $rejectionLimit): ?array
    {
        $this->attempts = 0;
        $this->rejections = 0;
        $this->firstLegsNotKept = 0;
        $this->stoppedByABound = false;
        $this->slotWithoutEvent = null;
        $this->furthestSlot = null;
        $this->furthestEvents = [];
        $this->furthestRejections = [];

        $slotCount = count($slots);
        $lastFirstLegSlot = -1;
        if ($this->keepsFirstLeg instanceof Closure) {
            foreach ($slots as $position => $slot) {
                if (isset($slot['event'])) {
                    $lastFirstLegSlot = $position;
                }
            }
        }

        $contexts = [$context];
        /** @var array<int, list<Event>> $candidates */
        $candidates = [];
        /** @var array<int, int> $tried */
        $tried = [];
        /** @var array<int, Event> $placed */
        $placed = [];
        /** @var array<int, array<int, true>> $inherited */
        $inherited = [];
        /** @var array<int, true> $anyEarlierEvent Slots with a role rejected for a reason any earlier event may change */
        $anyEarlierEvent = [];
        $slotsByParticipant = null;
        $furthest = -1;
        $index = 0;

        while ($index < $slotCount) {
            $slot = $slots[$index];
            $slotContext = $contexts[$index];

            if (!isset($candidates[$index])) {
                $slotCandidates = $this->candidates($slot, $slotContext, $placed);
                if ($slotCandidates === null) {
                    $this->slotWithoutEvent = $slot;
                    $this->furthestSlot = $index;
                    $this->furthestEvents = array_slice($placed, 0, $index);

                    return null;
                }

                $candidates[$index] = $slotCandidates;
                $tried[$index] = 0;
            }

            $accepted = null;
            while ($tried[$index] < count($candidates[$index])) {
                if ($this->attempts >= $attemptLimit) {
                    $this->stoppedByABound = true;

                    return null;
                }

                ++$this->attempts;
                $candidate = $candidates[$index][$tried[$index]];
                ++$tried[$index];

                $rejectedLocally = $this->rejection($slot, $candidate, $slotContext);

                if ($rejectedLocally === null && $index === $lastFirstLegSlot) {
                    // The first leg is whole. One the search changed stands
                    // only when the caller keeps it
                    $placed[$index] = $candidate;
                    if ($this->firstLegWasChanged($slots, $placed, $index) && !$this->firstLegIsKept(array_slice($placed, 0, $index + 1))) {
                        ++$this->firstLegsNotKept;
                        $rejectedLocally = false;
                    }
                }

                if ($rejectedLocally === null) {
                    $accepted = $candidate;
                    break;
                }

                if (!$rejectedLocally) {
                    $anyEarlierEvent[$index] = true;
                }

                ++$this->rejections;
                if ($this->rejections > $rejectionLimit) {
                    $this->stoppedByABound = true;

                    return null;
                }
            }

            if ($accepted instanceof Event) {
                $placed[$index] = $accepted;
                ++$index;
                if ($index < $slotCount) {
                    $contexts[$index] = $this->contextAfter($slots, $index, $slotContext, $placed);
                }

                continue;
            }

            if ($index > $furthest) {
                $furthest = $index;
                $this->furthestSlot = $index;
                $this->furthestEvents = array_slice($placed, 0, $index);
                $this->furthestRejections = array_map(
                    static fn(Event $rejected): array => [$rejected, $slotContext],
                    $candidates[$index]
                );
            }

            // Go back to an earlier event and take its next role. The
            // events in between start again from their first role when the
            // search comes forward to them.
            $target = $index - 1;
            if (isset($anyEarlierEvent[$index])) {
                // Any earlier event may be what the rejection turned on:
                // the event before is tried, and told the same
                if ($target >= 0) {
                    $anyEarlierEvent[$target] = true;
                }
            } else {
                // Only an event that shares a participant with this one,
                // or with an event that already sent the search back here,
                // can change what was said here: go straight to the latest
                // of those, and hand it the rest to go back to in its turn.
                $slotsByParticipant ??= $this->slotsByParticipant($slots);
                $culprits = $inherited[$index] ?? [];
                foreach ($slot['pairing'] as $participant) {
                    foreach ($slotsByParticipant[$participant->getId()] ?? [] as $other) {
                        if ($other >= $index) {
                            break;
                        }
                        $culprits[$other] = true;
                    }
                }

                $target = $culprits === [] ? -1 : max(array_keys($culprits));
                if ($target >= 0) {
                    unset($culprits[$target]);
                    $inherited[$target] = ($inherited[$target] ?? []) + $culprits;
                }
            }

            for ($reset = $index; $reset > $target; --$reset) {
                $tried[$reset] = 0;
                unset($inherited[$reset], $anyEarlierEvent[$reset]);
                if (!$this->keepsItsCandidates($slots[$reset])) {
                    unset($candidates[$reset]);
                }
            }
            $index = $target;

            if ($index < 0) {
                return null;
            }
        }

        return array_slice($placed, 0, $slotCount);
    }

    /**
     * How many events the last search put to the constraints.
     */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * How many attempts of the last search were rejected, the first legs
     * that were not kept included: what it spent of its rejection limit.
     */
    public function rejections(): int
    {
        return $this->rejections;
    }

    /**
     * How many first legs the last search completed, with roles the
     * constraints accept in that leg, that the caller did not keep.
     */
    public function firstLegsNotKept(): int
    {
        return $this->firstLegsNotKept;
    }

    /**
     * Whether the last search stopped at one of its two limits, and so
     * says nothing about whether roles exist.
     */
    public function wasStoppedByABound(): bool
    {
        return $this->stoppedByABound;
    }

    /**
     * The slot the strategy returned no event for in the last search; null
     * when it returned one for every slot it was asked about.
     *
     * @return array{leg: int, round: int, pairing: array<Participant>, event?: Event, source?: int, fixed?: bool}|null
     */
    public function slotWithoutEvent(): ?array
    {
        return $this->slotWithoutEvent;
    }

    /**
     * The index of the slot the last failed search was furthest on at: the
     * latest slot whose every role was rejected, or the slot the strategy
     * returned no event for. Null when the search succeeded, or stopped at
     * a bound before any slot had every role rejected.
     */
    public function furthestSlot(): ?int
    {
        return $this->furthestSlot;
    }

    /**
     * The events in place when the last failed search was furthest on: at
     * the latest slot whose every role the constraints rejected, or at the
     * slot the strategy returned no event for.
     *
     * @return list<Event>
     */
    public function furthestEvents(): array
    {
        return $this->furthestEvents;
    }

    /**
     * The events the constraints rejected at that furthest slot, each with
     * the context it was put to them with.
     *
     * @return list<array{Event, SchedulingContext}>
     */
    public function furthestRejections(): array
    {
        return $this->furthestRejections;
    }

    /**
     * Why an event is rejected, or null when it is accepted: true when
     * only the events of its own participants can change the verdict, and
     * false when any earlier event may.
     *
     * @param array{leg: int, round: int, pairing: array<Participant>, event?: Event, source?: int, fixed?: bool} $slot
     */
    private function rejection(array $slot, Event $candidate, SchedulingContext $context): ?bool
    {
        if (
            $this->firstLegRule instanceof ConstraintInterface
            && isset($slot['event'])
            && !$this->firstLegRule->isSatisfied($candidate, $context)
        ) {
            return $this->ruleIsLocal;
        }

        if (!$this->constraints instanceof ConstraintSet || $this->constraints->isSatisfied($candidate, $context)) {
            return null;
        }

        if ($this->verdictsAreLocal) {
            return true;
        }

        if ($this->explainsRejections) {
            foreach ($this->constraints->getConstraints() as $constraint) {
                if (ConstraintPurity::constraintReadsOwnEventsOnly($constraint) && !$constraint->isSatisfied($candidate, $context)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a first-leg slot up to $last holds another event than the one
     * it was given.
     *
     * @param list<array{leg: int, round: int, pairing: array<Participant>, event?: Event, source?: int, fixed?: bool}> $slots
     * @param array<int, Event> $placed
     */
    private function firstLegWasChanged(array $slots, array $placed, int $last): bool
    {
        for ($index = 0; $index <= $last; ++$index) {
            if (isset($slots[$index]['event']) && $placed[$index] !== $slots[$index]['event']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Event> $firstLeg
     */
    private function firstLegIsKept(array $firstLeg): bool
    {
        return !$this->keepsFirstLeg instanceof Closure || ($this->keepsFirstLeg)($firstLeg);
    }

    /**
     * The events to try for a slot, in the order to try them; null when
     * the strategy returns no event for it.
     *
     * @param array{leg: int, round: int, pairing: array<Participant>, event?: Event, source?: int, fixed?: bool} $slot
     * @param array<int, Event> $placed
     * @return list<Event>|null
     */
    private function candidates(array $slot, SchedulingContext $context, array $placed): ?array
    {
        if (isset($slot['event'])) {
            return ($slot['fixed'] ?? false) ? [$slot['event']] : $this->withReverse($slot['event']);
        }

        $pairing = isset($slot['source']) ? $placed[$slot['source']]->getParticipants() : $slot['pairing'];
        $event = $this->strategy->generateEventForLeg($pairing, $slot['leg'], $slot['round'], $context);

        if (!$event instanceof Event) {
            return null;
        }

        return $this->rolesArePreferences ? $this->withReverse($event) : [$event];
    }

    /**
     * Whether the events to try for a slot stay what they were when the
     * search comes forward to it again: not for a rule derived from a
     * first-leg event, which may have been reversed in between.
     *
     * @param array{leg: int, round: int, pairing: array<Participant>, event?: Event, source?: int, fixed?: bool} $slot
     */
    private function keepsItsCandidates(array $slot): bool
    {
        return $this->rolesArePreferences || !isset($slot['source']);
    }

    /**
     * The event and the same event with its two participants reversed. An
     * event of any other size has no reverse.
     *
     * @return list<Event>
     */
    private function withReverse(Event $event): array
    {
        $participants = $event->getParticipants();
        if (count($participants) !== 2) {
            return [$event];
        }

        return [$event, new Event([$participants[1], $participants[0]], $event->getRound(), $event->getMetadata())];
    }

    /**
     * The indexes of the slots each participant is in, in slot order.
     *
     * @param list<array{leg: int, round: int, pairing: array<Participant>, event?: Event, source?: int, fixed?: bool}> $slots
     * @return array<array-key, list<int>>
     */
    private function slotsByParticipant(array $slots): array
    {
        $byParticipant = [];
        foreach ($slots as $index => $slot) {
            foreach ($slot['pairing'] as $participant) {
                $byParticipant[$participant->getId()][] = $index;
            }
        }

        return $byParticipant;
    }

    /**
     * The context of the slot at $index, once the slot before it is placed.
     *
     * @param list<array{leg: int, round: int, pairing: array<Participant>, event?: Event, source?: int, fixed?: bool}> $slots
     * @param SchedulingContext $previousContext The context of the slot before
     * @param array<int, Event> $placed
     */
    private function contextAfter(array $slots, int $index, SchedulingContext $previousContext, array $placed): SchedulingContext
    {
        $previous = $slots[$index - 1];
        $context = $previousContext;

        if ($this->contextHoldsOwnRound) {
            $context = $context->withEvents([$placed[$index - 1]]);
        } elseif ($slots[$index]['round'] !== $previous['round']) {
            $roundEvents = [];
            for ($earlier = $index - 1; $earlier >= 0 && $slots[$earlier]['round'] === $previous['round']; --$earlier) {
                $roundEvents[] = $placed[$earlier];
            }
            $context = $context->withEvents(array_reverse($roundEvents));
        }

        return $slots[$index]['leg'] === $previous['leg'] ? $context : $context->withNextLeg();
    }
}
