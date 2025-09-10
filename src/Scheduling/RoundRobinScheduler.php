<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use InvalidArgumentException;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use Random\Randomizer;

readonly class RoundRobinScheduler implements SchedulerInterface
{
    public function __construct(
        private ?ConstraintSet $constraints = null,
        private ?Randomizer $randomizer = null
    ) {
    }

    /**
     * Generate a round-robin schedule for the given participants.
     *
     * @param array<Participant> $participants
     */
    public function schedule(array $participants): Schedule
    {
        if (count($participants) < 2) {
            throw new InvalidArgumentException('Round-robin scheduling requires at least 2 participants');
        }

        $events = $this->generateRoundRobinEvents($participants);

        return new Schedule($events, [
            'algorithm' => 'round-robin',
            'participant_count' => count($participants),
            'total_rounds' => count($participants) - 1,
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

                $event = new Event([$participant1, $participant2], $round);

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

        $this->randomizer->shuffleArray($participants);

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
