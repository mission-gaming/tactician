<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
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
 * its slots in schedule order against slot time order, so the same
 * schedule and timeline always produce the same kickoffs. Validation is
 * loud: rounds with more events than slots, and schedules carrying
 * round-less events (which the round-grouped view would silently drop),
 * fail with diagnostics.
 */
final readonly class TimelineAssigner
{
    /**
     * @param array<TimelineRule> $rules Time-aware rules every assignment must satisfy
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
                    ['index' => $index, 'given' => get_debug_type($rule)]
                );
            }
        }
    }

    /**
     * Assign kickoff times to every event of a complete schedule.
     *
     * When the assigner carries time-aware rules, the assigned timeline is
     * validated against every rule and assignment fails loudly with all
     * violations — a deterministic mapping cannot route around a broken
     * rule, only report it.
     *
     * @throws InvalidConfigurationException When the schedule carries round-less events,
     *                                       a round has more events than the timeline has
     *                                       slots, or a time-aware rule is violated
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
                ['events' => count($schedule), 'round_grouped_events' => $groupedCount]
            );
        }

        $scheduledEvents = [];
        foreach ($eventsByRound as $roundNumber => $events) {
            foreach ($this->assignEvents($events, $roundNumber, $timeline) as $scheduledEvent) {
                $scheduledEvents[] = $scheduledEvent;
            }
        }

        $scheduled = new ScheduledSchedule($scheduledEvents);

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
                ['violations' => $violations]
            );
        }

        return $scheduled;
    }

    /**
     * Assign kickoff times to one round of a results-driven stage.
     *
     * The engine bridge: pair the round, assign its times, play it,
     * record it — the timeline definition stays the same object across
     * the stage's rounds.
     *
     * @return array<ScheduledEvent> In slot order
     *
     * @throws InvalidConfigurationException When the round has more events than the timeline has slots
     */
    public function assignRound(RoundPairing $pairing, TimelineDefinition $timeline): array
    {
        return $this->assignEvents($pairing->getEvents(), $pairing->getRoundNumber(), $timeline);
    }

    /**
     * @param array<\MissionGaming\Tactician\DTO\Event> $events In schedule order
     * @return array<ScheduledEvent>
     *
     * @throws InvalidConfigurationException When the round overflows the timeline's slots
     */
    private function assignEvents(array $events, int $roundNumber, TimelineDefinition $timeline): array
    {
        if (count($events) > $timeline->getSlotsPerRound()) {
            throw new InvalidConfigurationException(
                "Round {$roundNumber} has more events than the timeline has slots",
                [
                    'round' => $roundNumber,
                    'events' => count($events),
                    'slots_per_round' => $timeline->getSlotsPerRound(),
                ]
            );
        }

        $scheduledEvents = [];
        foreach (array_values($events) as $slot => $event) {
            $scheduledEvents[] = new ScheduledEvent($event, $timeline->getSlotTime($roundNumber, $slot));
        }

        return $scheduledEvents;
    }
}
