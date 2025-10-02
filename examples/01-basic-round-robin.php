<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Create participants for our tournament
$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];

// Generate the schedule (no constraints needed for basic single-leg round robin)
$scheduler = new RoundRobinScheduler();
$schedule = $scheduler->schedule($participants);

// Calculate some statistics
$totalEvents = count($schedule);
$totalRounds = $schedule->getMetadataValue('total_rounds');
$participantCount = $schedule->getMetadataValue('participant_count');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Basic Round Robin - Tactician Examples</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-6xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">🎯 Basic Round Robin</h1>
                    <p class="text-blue-100">Simple 4-team tournament demonstrating core scheduling</p>
                </div>
                <a href="index.php" class="bg-blue-700 hover:bg-blue-800 px-4 py-2 rounded-lg transition-colors">
                    ← Back to Examples
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
        <!-- Tournament Info -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Tournament Overview</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-blue-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-blue-600"><?= $participantCount; ?></div>
                    <div class="text-gray-600">Teams</div>
                </div>
                <div class="bg-green-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-green-600"><?= $totalEvents; ?></div>
                    <div class="text-gray-600">Total Matches</div>
                </div>
                <div class="bg-purple-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-purple-600"><?= $totalRounds; ?></div>
                    <div class="text-gray-600">Rounds</div>
                </div>
            </div>
        </div>

        <!-- Teams -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Participating Teams</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php foreach ($participants as $participant): ?>
                    <div class="border border-gray-200 rounded-lg p-4 text-center">
                        <div class="text-lg font-semibold text-gray-800"><?= htmlspecialchars($participant->getLabel()); ?></div>
                        <div class="text-sm text-gray-500">ID: <?= htmlspecialchars($participant->getId()); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Schedule -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Complete Schedule</h2>
            
            <?php
            $eventsByRound = [];
foreach ($schedule as $event) {
    $roundNumber = $event->getRound()->getNumber();
    if (!isset($eventsByRound[$roundNumber])) {
        $eventsByRound[$roundNumber] = [];
    }
    $eventsByRound[$roundNumber][] = $event;
}
?>

            <div class="space-y-6">
                <?php foreach ($eventsByRound as $roundNumber => $roundEvents): ?>
                    <div class="border border-gray-200 rounded-lg p-4">
                        <h3 class="text-lg font-semibold text-gray-800 mb-3 flex items-center">
                            <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-sm mr-3">
                                Round <?= $roundNumber; ?>
                            </span>
                            <span class="text-gray-500 text-sm"><?= count($roundEvents); ?> match<?= count($roundEvents) === 1 ? '' : 'es'; ?></span>
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php foreach ($roundEvents as $event): ?>
                                <?php $eventParticipants = $event->getParticipants(); ?>
                                <div class="border border-gray-100 rounded-lg p-3 hover:bg-gray-50 transition-colors">
                                    <div class="flex items-center justify-between">
                                        <div class="text-center flex-1">
                                            <div class="font-medium text-gray-800"><?= htmlspecialchars($eventParticipants[0]->getLabel()); ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($eventParticipants[0]->getId()); ?></div>
                                        </div>
                                        <div class="px-3 text-gray-400 font-bold">VS</div>
                                        <div class="text-center flex-1">
                                            <div class="font-medium text-gray-800"><?= htmlspecialchars($eventParticipants[1]->getLabel()); ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($eventParticipants[1]->getId()); ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Code Example -->
        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Code Example</h2>
            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 overflow-x-auto">
                <pre><code><?= htmlspecialchars('<?php
use MissionGaming\\Tactician\\DTO\\Participant;
use MissionGaming\\Tactician\\Scheduling\\RoundRobinScheduler;

// Create participants
$participants = [
    new Participant(\'celtic\', \'Celtic\'),
    new Participant(\'athletic\', \'Athletic Bilbao\'),
    new Participant(\'livorno\', \'AS Livorno\'),
    new Participant(\'redstar\', \'Red Star FC\'),
];

// Generate schedule (no constraints needed for basic round robin)
$scheduler = new RoundRobinScheduler();
$schedule = $scheduler->schedule($participants);

// Iterate through matches
foreach ($schedule as $event) {
    $round = $event->getRound();
    $participants = $event->getParticipants();
    echo "Round {$round->getNumber()}: ";
    echo "{$participants[0]->getLabel()} vs {$participants[1]->getLabel()}\\n";
}'); ?></code></pre>
            </div>
        </div>

        <!-- Navigation -->
        <div class="flex justify-between mt-8">
            <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition-colors">
                ← Back to Examples
            </a>
            <a href="02-participants-and-metadata.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg transition-colors">
                Next: Participants & Metadata →
            </a>
        </div>
    </main>

    <footer class="bg-gray-800 text-white mt-16">
        <div class="max-w-6xl mx-auto px-6 py-6 text-center">
            <p class="text-gray-400">
                <strong>Tactician</strong> - Modern PHP Tournament Scheduling
            </p>
        </div>
    </footer>
</body>
</html>
