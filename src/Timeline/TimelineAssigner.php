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
     * Assign kickoff times to every event of a complete schedule.
     *
     * @throws InvalidConfigurationException When the schedule carries round-less events
     *                                       or a round has more events than the timeline has slots
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

        return new ScheduledSchedule($scheduledEvents);
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
