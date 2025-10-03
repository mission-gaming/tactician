<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Create participants
$participants = [
    new Participant('celtic', 'Celtic FC', 1, ['city' => 'Glasgow', 'league' => 'Scottish Premier']),
    new Participant('athletic', 'Athletic Bilbao', 2, ['city' => 'Bilbao', 'league' => 'La Liga']),
    new Participant('livorno', 'AS Livorno', 3, ['city' => 'Livorno', 'league' => 'Serie C']),
    new Participant('redstar', 'Red Star Belgrade', 4, ['city' => 'Belgrade', 'league' => 'SuperLiga']),
    new Participant('stpauli', 'FC St. Pauli', 5, ['city' => 'Hamburg', 'league' => '2. Bundesliga']),
];

// Generate schedule
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->build();

$scheduler = new RoundRobinScheduler($constraints);
$schedule = $scheduler->generateSchedule($participants);

// Different ways to access schedule data
$totalEvents = count($schedule);
$totalRounds = $schedule->getMetadataValue('total_rounds');
$algorithm = $schedule->getMetadataValue('algorithm');

// Group events by round for demonstration
$eventsByRound = [];
foreach ($schedule as $event) {
    $roundNumber = $event->getRound()->getNumber();
    if (!isset($eventsByRound[$roundNumber])) {
        $eventsByRound[$roundNumber] = [];
    }
    $eventsByRound[$roundNumber][] = $event;
}

// Create a flat array for different iteration methods
$eventsArray = iterator_to_array($schedule);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Iteration - Tactician Examples</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-6xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">🔄 Schedule Iteration</h1>
                    <p class="text-blue-100">Different ways to access and display schedule data</p>
                </div>
                <a href="index.php" class="bg-blue-700 hover:bg-blue-800 px-4 py-2 rounded-lg transition-colors">
                    ← Back to Examples
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
        <!-- Schedule Overview -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Schedule Overview</h2>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="bg-blue-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-blue-600"><?= count($participants); ?></div>
                    <div class="text-gray-600">Teams</div>
                </div>
                <div class="bg-green-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-green-600"><?= $totalEvents; ?></div>
                    <div class="text-gray-600">Matches</div>
                </div>
                <div class="bg-purple-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-purple-600"><?= $totalRounds; ?></div>
                    <div class="text-gray-600">Rounds</div>
                </div>
                <div class="bg-orange-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-orange-600"><?= htmlspecialchars($algorithm); ?></div>
                    <div class="text-gray-600">Algorithm</div>
                </div>
            </div>
        </div>

        <!-- Method 1: Direct Iteration (Memory Efficient) -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Method 1: Direct Iteration (Memory Efficient)</h2>
            <p class="text-gray-600 mb-4">
                The most memory-efficient way to iterate through a schedule. Perfect for large tournaments 
                as it doesn't load all events into memory at once.
            </p>
            
            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 mb-4">
                <pre><code><?= htmlspecialchars('foreach ($schedule as $event) {
    $round = $event->getRound();
    $participants = $event->getParticipants();
    
    echo "Round {$round->getNumber()}: ";
    echo "{$participants[0]->getLabel()} vs {$participants[1]->getLabel()}";
}'); ?></code></pre>
            </div>

            <div class="space-y-2">
                <?php foreach ($schedule as $event): ?>
                    <?php $eventParticipants = $event->getParticipants(); ?>
                    <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                        <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-sm">
                            Round <?= $event->getRound()->getNumber(); ?>
                        </span>
                        <div class="flex items-center space-x-4">
                            <span class="font-medium"><?= htmlspecialchars($eventParticipants[0]->getLabel()); ?></span>
                            <span class="text-gray-400">vs</span>
                            <span class="font-medium"><?= htmlspecialchars($eventParticipants[1]->getLabel()); ?></span>
                        </div>
                        <span class="text-sm text-gray-500">
                            <?= htmlspecialchars($eventParticipants[0]->getMetadataValue('city')); ?> vs
                            <?= htmlspecialchars($eventParticipants[1]->getMetadataValue('city')); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Method 2: Count Without Loading -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Method 2: Counting Events (No Memory Load)</h2>
            <p class="text-gray-600 mb-4">
                You can count events without loading them all into memory using PHP's Countable interface.
            </p>
            
            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 mb-4">
                <pre><code><?= htmlspecialchars('$totalEvents = count($schedule);
echo "Total events: " . $totalEvents;'); ?></code></pre>
            </div>

            <div class="bg-blue-50 rounded-lg p-4">
                <div class="text-2xl font-bold text-blue-600"><?= $totalEvents; ?></div>
                <div class="text-blue-800">Total events counted without loading into memory</div>
            </div>
        </div>

        <!-- Method 3: Convert to Array -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Method 3: Convert to Array (Random Access)</h2>
            <p class="text-gray-600 mb-4">
                Convert the schedule to an array when you need random access or want to use array functions.
                <strong>Note:</strong> This loads all events into memory.
            </p>
            
            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 mb-4">
                <pre><code><?= htmlspecialchars('$eventsArray = iterator_to_array($schedule);

// Now you can access by index
$firstEvent = $eventsArray[0];
$lastEvent = $eventsArray[count($eventsArray) - 1];

// Or use array functions
$filteredEvents = array_filter($eventsArray, function($event) {
    return $event->getRound()->getNumber() <= 2;
});'); ?></code></pre>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="border border-gray-200 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-2">First Event</h3>
                    <?php $firstEvent = $eventsArray[0]; ?>
                    <?php $firstParticipants = $firstEvent->getParticipants(); ?>
                    <div class="text-sm">
                        <div><strong>Round:</strong> <?= $firstEvent->getRound()->getNumber(); ?></div>
                        <div><strong>Match:</strong> <?= htmlspecialchars($firstParticipants[0]->getLabel()); ?> vs <?= htmlspecialchars($firstParticipants[1]->getLabel()); ?></div>
                    </div>
                </div>
                
                <div class="border border-gray-200 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-2">Last Event</h3>
                    <?php $lastEvent = $eventsArray[count($eventsArray) - 1]; ?>
                    <?php $lastParticipants = $lastEvent->getParticipants(); ?>
                    <div class="text-sm">
                        <div><strong>Round:</strong> <?= $lastEvent->getRound()->getNumber(); ?></div>
                        <div><strong>Match:</strong> <?= htmlspecialchars($lastParticipants[0]->getLabel()); ?> vs <?= htmlspecialchars($lastParticipants[1]->getLabel()); ?></div>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <h3 class="font-semibold text-gray-800 mb-2">First Two Rounds Only (using array_filter)</h3>
                <?php
                $firstTwoRounds = array_filter($eventsArray, function ($event) {
                    return $event->getRound()->getNumber() <= 2;
                });
?>
                <div class="text-sm text-gray-600 mb-2">
                    Filtered <?= count($firstTwoRounds); ?> events from first 2 rounds out of <?= count($eventsArray); ?> total events
                </div>
                <div class="space-y-1">
                    <?php foreach ($firstTwoRounds as $event): ?>
                        <?php $eventParticipants = $event->getParticipants(); ?>
                        <div class="text-sm p-2 bg-gray-100 rounded">
                            Round <?= $event->getRound()->getNumber(); ?>: 
                            <?= htmlspecialchars($eventParticipants[0]->getLabel()); ?> vs 
                            <?= htmlspecialchars($eventParticipants[1]->getLabel()); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Method 4: Group by Rounds -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Method 4: Group Events by Round</h2>
            <p class="text-gray-600 mb-4">
                Organize events by round number for display purposes. Useful for showing tournament brackets or round-based views.
            </p>
            
            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 mb-4">
                <pre><code><?= htmlspecialchars('$eventsByRound = [];
foreach ($schedule as $event) {
    $roundNumber = $event->getRound()->getNumber();
    if (!isset($eventsByRound[$roundNumber])) {
        $eventsByRound[$roundNumber] = [];
    }
    $eventsByRound[$roundNumber][] = $event;
}'); ?></code></pre>
            </div>

            <div class="space-y-6">
                <?php foreach ($eventsByRound as $roundNumber => $roundEvents): ?>
                    <div class="border border-gray-200 rounded-lg p-4">
                        <h3 class="text-lg font-semibold text-gray-800 mb-3 flex items-center">
                            <span class="bg-purple-100 text-purple-800 px-3 py-1 rounded-full text-sm mr-3">
                                Round <?= $roundNumber; ?>
                            </span>
                            <span class="text-gray-500 text-sm"><?= count($roundEvents); ?> match<?= count($roundEvents) === 1 ? '' : 'es'; ?></span>
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <?php foreach ($roundEvents as $event): ?>
                                <?php $eventParticipants = $event->getParticipants(); ?>
                                <div class="bg-gray-50 rounded p-3">
                                    <div class="flex items-center justify-between">
                                        <span class="font-medium text-gray-800"><?= htmlspecialchars($eventParticipants[0]->getLabel()); ?></span>
                                        <span class="text-gray-400 font-bold">VS</span>
                                        <span class="font-medium text-gray-800"><?= htmlspecialchars($eventParticipants[1]->getLabel()); ?></span>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1 text-center">
                                        <?= htmlspecialchars($eventParticipants[0]->getMetadataValue('league')); ?> vs 
                                        <?= htmlspecialchars($eventParticipants[1]->getMetadataValue('league')); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Method 5: Accessing Schedule Metadata -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Method 5: Accessing Schedule Metadata</h2>
            <p class="text-gray-600 mb-4">
                Schedules contain metadata about how they were generated, useful for understanding the tournament structure.
            </p>
            
            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 mb-4">
                <pre><code><?= htmlspecialchars('$algorithm = $schedule->getMetadataValue(\'algorithm\');
$participantCount = $schedule->getMetadataValue(\'participant_count\');
$totalRounds = $schedule->getMetadataValue(\'total_rounds\');

// Check if metadata exists
if ($schedule->hasMetadata(\'creation_time\')) {
    $createdAt = $schedule->getMetadataValue(\'creation_time\');
}'); ?></code></pre>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-sm text-gray-600 mb-1">Algorithm</div>
                    <div class="font-medium text-gray-800"><?= htmlspecialchars($schedule->getMetadataValue('algorithm')); ?></div>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-sm text-gray-600 mb-1">Participant Count</div>
                    <div class="font-medium text-gray-800"><?= $schedule->getMetadataValue('participant_count'); ?></div>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-sm text-gray-600 mb-1">Total Rounds</div>
                    <div class="font-medium text-gray-800"><?= $schedule->getMetadataValue('total_rounds'); ?></div>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-sm text-gray-600 mb-1">Events per Round</div>
                    <div class="font-medium text-gray-800"><?= $schedule->getMetadataValue('events_per_round'); ?></div>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-sm text-gray-600 mb-1">Default Value Example</div>
                    <div class="font-medium text-gray-800"><?= htmlspecialchars($schedule->getMetadataValue('nonexistent_key', 'N/A')); ?></div>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-sm text-gray-600 mb-1">Has Custom Metadata?</div>
                    <div class="font-medium text-gray-800"><?= $schedule->hasMetadata('custom_field') ? 'Yes' : 'No'; ?></div>
                </div>
            </div>
        </div>

        <!-- Performance Considerations -->
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mt-8">
            <h3 class="text-lg font-semibold text-yellow-800 mb-3">⚡ Performance Considerations</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
                <div>
                    <h4 class="font-medium text-yellow-800 mb-2">Memory Efficient</h4>
                    <ul class="space-y-1 text-yellow-700">
                        <li>• Direct iteration (foreach)</li>
                        <li>• Counting with count()</li>
                        <li>• Processing one event at a time</li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-medium text-yellow-800 mb-2">Higher Memory Usage</h4>
                    <ul class="space-y-1 text-yellow-700">
                        <li>• Converting to array (iterator_to_array)</li>
                        <li>• Grouping all events</li>
                        <li>• Random access patterns</li>
                    </ul>
                </div>
            </div>
            <div class="mt-4 text-yellow-700">
                <strong>Recommendation:</strong> Use direct iteration for large tournaments (1000+ events). 
                Convert to arrays only when you need random access or complex array operations.
            </div>
        </div>

        <!-- Navigation -->
        <div class="flex justify-between mt-8">
            <a href="02-participants-and-metadata.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition-colors">
                ← Previous: Participants & Metadata
            </a>
            <a href="04-basic-constraints.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg transition-colors">
                Next: Basic Constraints →
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
