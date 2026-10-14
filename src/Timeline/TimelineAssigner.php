<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Stage\RoundPairing;

/**
 * Maps a generated schedule onto a timeline's slots, deterministically.
 *
 * The assigner owns the mechanism only: given the round-grouped view and
 * a declarative slot model, produce timestamped events. Round-aligned
 * assignment is the one-slot case, staggering the many-slot case — one
 * mechanism, no parallel scheduler for staggered kickoffs.
 *
 * Filling is deterministic with declared ordering: a round's events fill
 * its slots in schedule order against slot time order, and within a slot
 * the timeline's resources in declared order, so the same schedule and
 * timeline always produce the same kickoffs. A slot is filled to its
 * capacity before the next one is used. Validation is loud: rounds with
 * more events than the timeline can hold (slots per round times capacity
 * per slot), and schedules carrying round-less events (which the
 * round-grouped view would silently drop), fail with diagnostics.
 *
 * A round whose slots are not at rising times, all of them before the
 * first slot of the next round, fails too: two slots would be one kickoff,
 * or the next round would start, on the same resources and with the same
 * participants, while this one is still being played. The definition
 * checks its first two rounds when it is built, and the assigner every
 * round it assigns, because the length of a month or a clock change can
 * make a later round shorter than the first. The check is of the slots the
 * timeline declares for the round, filled or not, and of the round after
 * it whether or not the schedule has one.
 *
 * A round that puts one participant in two events at the same instant
 * fails as well, with or without rules: a slot with two or more resources
 * holds several events, and nobody can be in two of them. The assigner
 * fills in schedule order and cannot move an event, so it refuses (reason
 * `ParticipantDoubleBooked`). This check is of the events placed, and it
 * is made after the checks of the declared slots, so within a round two
 * events at one instant are two events of one slot. Two events of one
 * participant in different slots of a round are assigned: whether the
 * time between them is enough is a rule's to say (MinimumRestRule).
 *
 * @experimental
 */
final readonly class TimelineAssigner
{
    /**
     * @param array<TimelineRule> $rules Time-aware rules every assignment must satisfy:
     *                                   assign() judges the whole schedule by them and
     *                                   assignRound() the round it is given. Violations
     *                                   are reported in this order
     *
     * @throws InvalidConfigurationException When an entry is not a TimelineRule
     */
    public function __construct(
        private array $rules = []
    ) {
        foreach ($rules as $index => $rule) {
            if (!$rule instanceof TimelineRule) {
                throw new InvalidConfigurationException(
                    'Every timeline rule must implement TimelineRule',
                    ['index' => $index, 'given' => get_debug_type($rule)],
                    reason: InvalidConfigurationReason::WrongValueType
                );
            }
        }
    }

    /**
     * Assign kickoff times to every event of a complete schedule.
     *
     * The result holds one scheduled event for each event of the schedule,
     * rounds ascending and each round's events in schedule order. The round
     * number of an event decides its round's time
     * (TimelineDefinition::getSlotTime()), so a schedule that starts at
     * round 5 starts four round intervals after the timeline's start.
     *
     * When the assigner carries time-aware rules, the assigned timeline is
     * validated against every rule and assignment fails loudly with all
     * violations — a deterministic mapping cannot route around a broken
     * rule, only report it. The exception's context has every violation
     * under `violations`, each prefixed with its rule's name; the message
     * quotes the first three.
     *
     * @throws InvalidConfigurationException When the schedule carries round-less events,
     *                                       a round has more events than the timeline has
     *                                       slots, the slots of a round are not at rising
     *                                       times or run up to or past the next round's
     *                                       first slot, a participant is in two events at
     *                                       one instant, or a time-aware rule is violated
     */
    public function assign(Schedule $schedule, TimelineDefinition $timeline): ScheduledSchedule
    {
        $eventsByRound = $schedule->getEventsByRound();

        // getEventsByRound() silently excludes round-less events; dropping
        // fixtures silently is exactly what this library never does
        $groupedCount = array_sum(array_map(count(...), $eventsByRound));
        if ($groupedCount !== count($schedule)) {
            throw new InvalidConfigurationException(
                'Timeline assignment requires every event to carry a round number',
                ['events' => count($schedule), 'round_grouped_events' => $groupedCount],
                reason: InvalidConfigurationReason::EventWithoutRoundNumber
            );
        }

        $scheduledEvents = [];
        foreach ($eventsByRound as $roundNumber => $events) {
            foreach ($this->assignEvents($events, $roundNumber, $timeline) as $scheduledEvent) {
                $scheduledEvents[] = $scheduledEvent;
            }
        }

        $scheduled = new ScheduledSchedule($scheduledEvents);
        $this->applyRules($scheduled);

        return $scheduled;
    }

    /**
     * Assign kickoff times to one round of a results-driven stage.
     *
     * The engine bridge: pair the round, assign its times, play it,
     * record it — the timeline definition stays the same object across
     * the stage's rounds. The round's time comes from the pairing's round
     * number. Byes get no entry.
     *
     * The assigner's rules are applied to the round, and the round fails
     * loudly with every violation, as assign() does. A rule sees this
     * round's events and no others, because the assigner keeps nothing
     * between calls: a blackout window is judged in full, and a minimum
     * rest only between the events of this round. To judge rest between
     * rounds, collect the rounds assigned so far into a ScheduledSchedule
     * and call the rule's validate() on it.
     *
     * @return array<ScheduledEvent> In slot order, the pairing's events in their order
     *
     * @throws InvalidConfigurationException When the round has more events than the timeline has
     *                                       slots, its slots are not at rising times or run
     *                                       up to or past the next round's first slot, a
     *                                       participant is in two events at one instant, or a
     *                                       time-aware rule is violated
     */
    public function assignRound(RoundPairing $pairing, TimelineDefinition $timeline): array
    {
        $scheduledEvents = $this->assignEvents($pairing->getEvents(), $pairing->getRoundNumber(), $timeline);
        $this->applyRules(new ScheduledSchedule($scheduledEvents));

        return $scheduledEvents;
    }

    /**
     * Judge assigned events by every rule, and fail with all the
     * violations at once.
     *
     * @throws InvalidConfigurationException When a rule is violated
     */
    private function applyRules(ScheduledSchedule $scheduled): void
    {
        $violations = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->validate($scheduled) as $violation) {
                $violations[] = "[{$rule->getName()}] {$violation}";
            }
        }

        if ($violations !== []) {
            throw new InvalidConfigurationException(
                'The assigned timeline has ' . count($violations) . ' time-rule violation(s): '
                    . implode(' ', array_slice($violations, 0, 3)),
                ['violations' => $violations],
                reason: InvalidConfigurationReason::TimeRuleViolation
            );
        }
    }

    /**
     * Fill deterministically: schedule order fills slot by slot, and
     * within a slot resource by resource in declared order.
     *
     * @param array<\MissionGaming\Tactician\DTO\Event> $events In schedule order
     * @return array<ScheduledEvent>
     *
     * @throws InvalidConfigurationException When the round overflows the timeline's capacity, its
     *                                       slots run up to or past the next round's first, or a
     *                                       participant is in two events at one instant
     */
    private function assignEvents(array $events, int $roundNumber, TimelineDefinition $timeline): array
    {
        $capacityPerSlot = $timeline->getCapacityPerSlot();
        $roundCapacity = $timeline->getSlotsPerRound() * $capacityPerSlot;

        if (count($events) > $roundCapacity) {
            throw new InvalidConfigurationException(
                "Round {$roundNumber} has more events than the timeline can hold",
                [
                    'round' => $roundNumber,
                    'events' => count($events),
                    'slots_per_round' => $timeline->getSlotsPerRound(),
                    'capacity_per_slot' => $capacityPerSlot,
                    'round_capacity' => $roundCapacity,
                ],
                reason: InvalidConfigurationReason::TimelineCapacityExceeded
            );
        }

        if ($events === []) {
            return [];
        }

        // Every slot the round declares, in order: each after the one
        // before it, and the last before the next round's first. The
        // definition limits how many there are, and gives them in one pass
        $slotTimes = [];
        $lastSlot = null;
        foreach ($timeline->roundSlotTimes($roundNumber) as $slot => $time) {
            if ($lastSlot !== null && $time <= $lastSlot) {
                throw new InvalidConfigurationException(
                    "Round {$roundNumber} has two slots that are not at rising times: slot {$slot} is at "
                        . $time->format('Y-m-d H:i:s') . ' UTC and the slot before it at ' . $lastSlot->format('Y-m-d H:i:s') . ' UTC',
                    [
                        'round' => $roundNumber,
                        'slot' => $slot,
                        'slot_time' => $time->format(DATE_ATOM),
                        'previous_slot_time' => $lastSlot->format(DATE_ATOM),
                    ],
                    reason: InvalidConfigurationReason::OverlappingTime
                );
            }
            $slotTimes[] = $time;
            $lastSlot = $time;
        }

        if ($lastSlot !== null) {
            $nextRound = $timeline->getSlotTime($roundNumber + 1);
            if ($lastSlot >= $nextRound) {
                throw new InvalidConfigurationException(
                    "Round {$roundNumber} is still being played when round " . ($roundNumber + 1) . ' starts: its last slot is at '
                        . $lastSlot->format('Y-m-d H:i:s') . ' UTC and the next round starts at ' . $nextRound->format('Y-m-d H:i:s') . ' UTC',
                    [
                        'round' => $roundNumber,
                        'slots_per_round' => $timeline->getSlotsPerRound(),
                        'last_slot' => $lastSlot->format(DATE_ATOM),
                        'next_round_start' => $nextRound->format(DATE_ATOM),
                    ],
                    reason: InvalidConfigurationReason::OverlappingTime
                );
            }
        }

        $scheduledEvents = [];
        foreach (array_values($events) as $index => $event) {
            $slot = intdiv($index, $capacityPerSlot);
            $scheduledEvents[] = new ScheduledEvent(
                $event,
                $slotTimes[$slot],
                $timeline->getResourceAt($index % $capacityPerSlot)
            );
        }

        $this->refuseDoubleBookings($scheduledEvents, $roundNumber);

        return $scheduledEvents;
    }

    /**
     * Refuse a round in which one participant is in two events that kick
     * off at the same instant, which a slot with two or more resources
     * allows. Participants are matched by ID. Every participant concerned
     * is reported, with the first two of its events at that instant, in the
     * order they are found: the round's events in slot order, and the
     * participants of an event in its order.
     *
     * It is refused and not left to a rule: a participant cannot be in two
     * places at once under any policy, and the filling is deterministic, so
     * the assigner cannot move one of the events. The order of the round's
     * events in the schedule decides which slot each lands in.
     *
     * @param array<ScheduledEvent> $scheduledEvents The assigned events of one round
     *
     * @throws InvalidConfigurationException When a participant is in two events at one instant
     */
    private function refuseDoubleBookings(array $scheduledEvents, int $roundNumber): void
    {
        /** @var array<string, array<string, ScheduledEvent>> $firstEventAt Keyed by instant, then by participant ID */
        $firstEventAt = [];
        $doubleBookings = [];
        $sentences = [];

        foreach ($scheduledEvents as $scheduledEvent) {
            $kickoff = $scheduledEvent->getKickoff();
            $instant = $kickoff->format('U.u');

            $seenInEvent = [];
            foreach ($scheduledEvent->getEvent()->getParticipants() as $participant) {
                $id = $participant->getId();
                if (isset($seenInEvent[$id])) {
                    continue;
                }
                $seenInEvent[$id] = true;

                $first = $firstEventAt[$instant][$id] ?? null;
                if ($first === null) {
                    $firstEventAt[$instant][$id] = $scheduledEvent;
                    continue;
                }

                if (isset($doubleBookings[$instant . ' ' . $id])) {
                    continue;
                }

                $doubleBookings[$instant . ' ' . $id] = [
                    'participant' => $id,
                    'kickoff' => $kickoff->format(DATE_ATOM),
                    'events' => [$this->describeEvent($first, false), $this->describeEvent($scheduledEvent, false)],
                ];
                $sentences[] = sprintf(
                    '%s is in %s and in %s at %s UTC.',
                    $participant->getLabel(),
                    $this->describeEvent($first, true),
                    $this->describeEvent($scheduledEvent, true),
                    $kickoff->format('Y-m-d H:i:s')
                );
            }
        }

        if ($doubleBookings !== []) {
            throw new InvalidConfigurationException(
                "Round {$roundNumber} has " . count($doubleBookings) . ' participant(s) in two events at the same time: '
                    . implode(' ', array_slice($sentences, 0, 3)),
                ['round' => $roundNumber, 'double_bookings' => array_values($doubleBookings)],
                reason: InvalidConfigurationReason::ParticipantDoubleBooked
            );
        }
    }

    /**
     * The participants of an event, by label or by ID, joined with "vs".
     */
    private function describeEvent(ScheduledEvent $scheduledEvent, bool $byLabel): string
    {
        $names = [];
        foreach ($scheduledEvent->getEvent()->getParticipants() as $participant) {
            $names[] = $byLabel ? $participant->getLabel() : $participant->getId();
        }

        return implode(' vs ', $names);
    }
}
