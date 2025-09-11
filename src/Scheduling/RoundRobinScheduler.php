<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use InvalidArgumentException;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use Override;
use Random\Randomizer;

class RoundRobinScheduler implements SchedulerInterface
{
    use SupportsMultipleLegs;

    public function __construct(
        private ?ConstraintSet $constraints = null,
        private ?Randomizer $randomizer = null,
        private int $legs = 1,
        private ?LegStrategyInterface $legStrategy = null
    ) {
    }

    private function getLegStrategy(): LegStrategyInterface
    {
        return $this->legStrategy ?? new MirroredLegStrategy();
    }

    /**
     * Generate a round-robin schedule for the given participants.
     *
     * @param array<Participant> $participants
     * @throws \DivisionByZeroError When roundsPerLeg is zero in multi-leg expansion
     */
    #[Override]
    public function schedule(array $participants): Schedule
    {
        if (count($participants) < 2) {
            throw new InvalidArgumentException('Round-robin scheduling requires at least 2 participants');
        }

        // Generate base single-leg events
        $baseEvents = $this->generateRoundRobinEvents($participants);

        // Expand to multiple legs if needed
        $allEvents = $this->expandScheduleForLegs(
            $baseEvents,
            $this->legs,
            $this->getLegStrategy(),
            $this->randomizer
        );

        $roundsPerLeg = count($participants) - 1;
        $totalRounds = $roundsPerLeg * $this->legs;

        return new Schedule($allEvents, [
            'algorithm' => 'round-robin',
            'participant_count' => count($participants),
            'legs' => $this->legs,
            'rounds_per_leg' => $roundsPerLeg,
            'total_rounds' => $totalRounds,
        ]);
    }

    /**
     * Generate all round-robin events using circle method.
     *
     * @param array<Participant> $participants
     * @return array<Event>
     */
    private function generateRoundRobinEvents(array $participants): array
    {
        $participantList = array_values($participants);
        $participantCount = count($participantList);
        $events = [];

        // Handle odd number of participants by adding a "bye"
        $hasBye = $participantCount % 2 !== 0;
        if ($hasBye) {
            $participantList[] = null; // null represents "bye"
            ++$participantCount;
        }

        $rounds = $participantCount - 1;
        $pairingsPerRound = $participantCount / 2;

        // Randomize initial order if randomizer is provided
        if ($this->randomizer !== null) {
            $participantList = $this->shuffleParticipants($participantList);
        }

        // Generate pairings for each round using circle method
        for ($round = 1; $round <= $rounds; ++$round) {
            $context = new SchedulingContext($participants, $events);

            for ($pair = 0; $pair < $pairingsPerRound; ++$pair) {
                $participant1 = $participantList[$pair];
                $participant2 = $participantList[$participantCount - 1 - $pair];

                // Skip if one participant is "bye" (null)
                if ($participant1 === null || $participant2 === null) {
                    continue;
                }

                $roundObject = new Round($round);
                $event = new Event([$participant1, $participant2], $roundObject);

                // Check constraints if provided
                if ($this->constraints === null || $this->constraints->isSatisfied($event, $context)) {
                    $events[] = $event;
                }
            }

            // Rotate participants for next round (keep first participant fixed)
            $this->rotateParticipants($participantList);
        }

        return $events;
    }

    /**
     * Check if an event should be added based on constraints.
     *
     * @param Event $event The event to validate
     * @param array<Event> $existingEvents All events created so far
     *
     * @return bool True if the event should be added
     */
    #[Override]
    protected function shouldAddEventWithConstraints(Event $event, array $existingEvents): bool
    {
        if ($this->constraints === null) {
            return true;
        }

        // Create context with all participants and existing events
        $allParticipants = [];
        foreach ($existingEvents as $existingEvent) {
            $allParticipants = [...$allParticipants, ...$existingEvent->getParticipants()];
        }
        $allParticipants = [...$allParticipants, ...$event->getParticipants()];
        $allParticipants = array_unique($allParticipants, SORT_REGULAR);

        $context = new SchedulingContext($allParticipants, $existingEvents);

        return $this->constraints->isSatisfied($event, $context);
    }

    /**
     * Shuffle participants using the provided randomizer.
     *
     * @param array<Participant|null> $participants
     * @return array<Participant|null>
     */
    private function shuffleParticipants(array $participants): array
    {
        // Keep the null/"bye" participant at the end if it exists
        $bye = null;
        if (end($participants) === null) {
            $bye = array_pop($participants);
        }

        $this->randomizer?->shuffleArray($participants);

        if ($bye !== null) {
            $participants[] = $bye;
        }

        return $participants;
    }

    /**
     * Rotate participants for circle method (keep first fixed, rotate others).
     *
     * @param array<Participant|null> $participants
     */
    private function rotateParticipants(array &$participants): void
    {
        // Circle method: fix position 0, rotate positions 1 to n-1
        if (count($participants) <= 2) {
            return;
        }

        $temp = $participants[1];
        for ($i = 1; $i < count($participants) - 1; ++$i) {
            $participants[$i] = $participants[$i + 1];
        }
        $participants[count($participants) - 1] = $temp;
    }
}
