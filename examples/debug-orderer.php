<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\Ordering\BalancedParticipantOrderer;
use MissionGaming\Tactician\Ordering\EventOrderingContext;
use MissionGaming\Tactician\Ordering\ParticipantOrderer;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Debug orderer that prints context info
class DebugBalancedOrderer implements ParticipantOrderer
{
    private int $callCount = 0;

    public function order(array $participants, EventOrderingContext $context): array
    {
        $this->callCount++;
        $eventCount = count($context->schedulingContext->getExistingEvents());

        if ($this->callCount <= 12) { // Only print first round
            $p1 = $participants[0]->getLabel();
            $p2 = $participants[1]->getLabel();
            echo "Call #{$this->callCount}: Round {$context->roundNumber}, Event {$context->eventIndexInRound}, Context has {$eventCount} events - Ordering: {$p1} vs {$p2}\n";
        }

        // Use same logic as BalancedParticipantOrderer
        $participants = array_values($participants);
        if (count($participants) !== 2) {
            return $participants;
        }

        $participant1 = $participants[0];
        $participant2 = $participants[1];

        $homeCount1 = $this->getHomeCount($participant1, $context);
        $homeCount2 = $this->getHomeCount($participant2, $context);

        if ($homeCount1 < $homeCount2) {
            return [$participant1, $participant2];
        } elseif ($homeCount2 < $homeCount1) {
            return [$participant2, $participant1];
        }

        return [$participant1, $participant2];
    }

    private function getHomeCount(Participant $participant, EventOrderingContext $context): int
    {
        $events = $context->schedulingContext->getEventsForParticipant($participant);
        $homeCount = 0;

        foreach ($events as $event) {
            $eventParticipants = $event->getParticipants();
            if (empty($eventParticipants)) {
                continue;
            }

            if ($eventParticipants[0]->getId() === $participant->getId()) {
                ++$homeCount;
            }
        }

        return $homeCount;
    }
}

// Create participants
$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
    new Participant('clapton', 'Clapton Community FC'),
    new Participant('rayo', 'Rayo Vallecano'),
];

$orderer = new DebugBalancedOrderer();
$scheduler = new RoundRobinScheduler(constraints: null, randomizer: null, participantOrderer: $orderer);
$schedule = $scheduler->generateSchedule($participants, 2, new MirroredLegStrategy());

echo "\nSchedule generated!\n";
