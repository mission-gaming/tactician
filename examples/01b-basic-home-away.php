<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Create participants for our tournament
$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];

// Generate a 2-leg home/away schedule with proper mirroring
$scheduler = new RoundRobinScheduler();
$schedule = $scheduler->generateSchedule(
    participants: $participants,
    legs: 2,
    legStrategy: new MirroredLegStrategy()
);

// Calculate statistics
$totalEvents = count($schedule);
$totalRounds = $schedule->getMetadataValue('total_rounds');
$participantCount = $schedule->getMetadataValue('participant_count');
$legs = $schedule->getMetadataValue('legs');
$roundsPerLeg = $schedule->getMetadataValue('rounds_per_leg');

// Function to determine home/away indicators
// Since MirroredLegStrategy already handles participant order reversal,
// we just need to treat first participant as home, second as away
function getHomeAwayIndicators($event, $roundsPerLeg)
{
    $participants = $event->getParticipants();

    // MirroredLegStrategy has already handled the reversal for us
    return [
        '🏠 ' . $participants[0]->getLabel(),
        '✈️ ' . $participants[1]->getLabel(),
    ];
}

// Group events by round
$eventsByRound = [];
foreach ($schedule as $event) {
    $roundNumber = $event->getRound()->getNumber();
    if (!isset($eventsByRound[$roundNumber])) {
        $eventsByRound[$roundNumber] = [];
    }
    $eventsByRound[$roundNumber][] = $event;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Basic Home/Away Round Robin - Tactician Examples</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-green-600 text-white shadow-lg">
        <div class="max-w-6xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">🏠 Basic Home/Away Round Robin</h1>
                    <p class="text-green-100">4-team tournament with home and away matches</p>
                </div>
                <a href="index.php" class="bg-green-700 hover:bg-green-800 px-4 py-2 rounded-lg transition-colors">
                    ← Back to Examples
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
        <!-- Home/Away Concept Explanation -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Home/Away Tournament Concept</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <p class="text-gray-600 mb-4 leading-relaxed">
                        In a home/away tournament, each team plays every other team twice - once at their "home" venue 
                        and once "away" at the opponent's venue. This creates a fair and balanced competition.
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        The <strong>MirroredLegStrategy</strong> automatically creates the second leg by reversing 
                        all the home/away assignments from the first leg, ensuring perfect balance.
                    </p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-3">Tournament Configuration</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Teams:</span>
                            <span class="font-medium"><?= $participantCount; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Legs:</span>
                            <span class="font-medium"><?= $legs; ?> (Home & Away)</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Total Matches:</span>
                            <span class="font-medium"><?= $totalEvents; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Rounds per Leg:</span>
                            <span class="font-medium"><?= $roundsPerLeg; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tournament Statistics -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Tournament Overview</h2>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="bg-blue-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-blue-600"><?= $participantCount; ?></div>
                    <div class="text-gray-600">Teams</div>
                </div>
                <div class="bg-green-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-green-600"><?= $legs; ?></div>
                    <div class="text-gray-600">Legs</div>
                </div>
                <div class="bg-purple-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-purple-600"><?= $totalRounds; ?></div>
                    <div class="text-gray-600">Total Rounds</div>
                </div>
                <div class="bg-orange-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-orange-600"><?= $totalEvents; ?></div>
                    <div class="text-gray-600">Total Matches</div>
                </div>
            </div>
        </div>

        <!-- Teams -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Participating Teams</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php foreach ($participants as $participant): ?>
                    <div class="border border-gray-200 rounded-lg p-4 text-center hover:shadow-md transition-shadow">
                        <div class="text-lg font-semibold text-gray-800"><?= htmlspecialchars($participant->getLabel()); ?></div>
                        <div class="text-sm text-gray-500">ID: <?= htmlspecialchars($participant->getId()); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Home/Away Schedule -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Complete Home/Away Schedule</h2>
            <p class="text-gray-600 mb-6">
                🏠 indicates the home team, ✈️ indicates the away team. 
                Notice how the second leg (rounds <?= $roundsPerLeg + 1; ?>-<?= $totalRounds; ?>) reverses all home/away assignments.
            </p>
            
            <div class="space-y-6">
                <?php foreach ($eventsByRound as $roundNumber => $roundEvents): ?>
                    <div class="border border-gray-200 rounded-lg p-4">
                        <h3 class="text-lg font-semibold text-gray-800 mb-3 flex items-center">
                            <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-sm mr-3">
                                Round <?= $roundNumber; ?>
                            </span>
                            <?php if ($roundNumber <= $roundsPerLeg): ?>
                                <span class="bg-green-100 text-green-800 px-2 py-1 rounded-full text-xs">
                                    First Leg
                                </span>
                            <?php else: ?>
                                <span class="bg-orange-100 text-orange-800 px-2 py-1 rounded-full text-xs">
                                    Second Leg (Return Fixtures)
                                </span>
                            <?php endif; ?>
                            <span class="text-gray-500 text-sm ml-3"><?= count($roundEvents); ?> match<?= count($roundEvents) === 1 ? '' : 'es'; ?></span>
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php foreach ($roundEvents as $event): ?>
                                <?php
                                $homeAway = getHomeAwayIndicators($event, $roundsPerLeg);
                                $eventParticipants = $event->getParticipants();
                                ?>
                                <div class="border border-gray-100 rounded-lg p-3 hover:bg-gray-50 transition-colors">
                                    <div class="flex items-center justify-between">
                                        <div class="text-center flex-1">
                                            <div class="font-medium text-gray-800"><?= htmlspecialchars($homeAway[0]); ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($eventParticipants[0]->getId()); ?></div>
                                        </div>
                                        <div class="px-3 text-gray-400 font-bold">VS</div>
                                        <div class="text-center flex-1">
                                            <div class="font-medium text-gray-800"><?= htmlspecialchars($homeAway[1]); ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($eventParticipants[1]->getId()); ?></div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($roundNumber > $roundsPerLeg): ?>
                                        <div class="mt-2 text-center">
                                            <span class="bg-orange-100 text-orange-800 px-2 py-1 rounded text-xs">
                                                Return Fixture
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Leg Comparison -->
        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">First Leg vs Second Leg</h2>
            <p class="text-gray-600 mb-4">
                Compare the first few matches from each leg to see how the MirroredLegStrategy works:
            </p>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- First Leg Sample -->
                <div class="border border-green-200 rounded-lg p-4">
                    <h3 class="font-semibold text-green-800 mb-3 flex items-center">
                        <span class="bg-green-100 text-green-800 px-2 py-1 rounded-full text-sm mr-2">First Leg</span>
                        Original Fixtures
                    </h3>
                    <div class="space-y-2">
                        <?php
                        $sampleEvents = array_slice($eventsByRound[1] ?? [], 0, 2);
foreach ($sampleEvents as $event):
    $homeAway = getHomeAwayIndicators($event, $roundsPerLeg);
    ?>
                            <div class="flex justify-between items-center p-2 bg-green-50 rounded">
                                <span class="text-sm"><?= htmlspecialchars($homeAway[0]); ?></span>
                                <span class="text-xs text-gray-500">vs</span>
                                <span class="text-sm"><?= htmlspecialchars($homeAway[1]); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Second Leg Sample -->
                <div class="border border-orange-200 rounded-lg p-4">
                    <h3 class="font-semibold text-orange-800 mb-3 flex items-center">
                        <span class="bg-orange-100 text-orange-800 px-2 py-1 rounded-full text-sm mr-2">Second Leg</span>
                        Mirrored Fixtures
                    </h3>
                    <div class="space-y-2">
                        <?php
    $secondLegStart = $roundsPerLeg + 1;
$sampleEvents = array_slice($eventsByRound[$secondLegStart] ?? [], 0, 2);
foreach ($sampleEvents as $event):
    $homeAway = getHomeAwayIndicators($event, $roundsPerLeg);
    ?>
                            <div class="flex justify-between items-center p-2 bg-orange-50 rounded">
                                <span class="text-sm"><?= htmlspecialchars($homeAway[0]); ?></span>
                                <span class="text-xs text-gray-500">vs</span>
                                <span class="text-sm"><?= htmlspecialchars($homeAway[1]); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="mt-4 p-3 bg-blue-50 rounded-lg">
                <p class="text-sm text-blue-800">
                    <strong>💡 Notice:</strong> Each team plays exactly the same opponents in both legs, 
                    but the home/away roles are swapped. This ensures perfect fairness!
                </p>
            </div>
        </div>

        <!-- Code Example -->
        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Code Example</h2>
            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 overflow-x-auto">
                <pre><code><?= htmlspecialchars('<?php
use MissionGaming\\Tactician\\DTO\\Participant;
use MissionGaming\\Tactician\\LegStrategies\\ShuffledLegStrategy;
use MissionGaming\\Tactician\\Scheduling\\RoundRobinScheduler;

// Create participants
$participants = [
    new Participant(\'celtic\', \'Celtic\'),
    new Participant(\'athletic\', \'Athletic Bilbao\'),
    new Participant(\'livorno\', \'AS Livorno\'),
    new Participant(\'redstar\', \'Red Star FC\'),
];

// Generate mirrored home/away schedule
$scheduler = new RoundRobinScheduler();
$schedule = $scheduler->generateSchedule(
    participants: $participants,
    legs: 2,
    legStrategy: new MirroredLegStrategy()
);

// Access metadata
$roundsPerLeg = $schedule->getMetadataValue(\'rounds_per_leg\');

// Display matches with home/away indicators
foreach ($schedule as $event) {
    $roundNumber = $event->getRound()->getNumber();
    $participants = $event->getParticipants();
    
    // First participant is always "home"
    echo "🏠 {$participants[0]->getLabel()} vs ✈️ {$participants[1]->getLabel()}\\n";
}'); ?></code></pre>
            </div>
        </div>

        <!-- Navigation -->
        <div class="flex justify-between mt-8">
            <a href="01-basic-round-robin.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition-colors">
                ← Basic Round Robin
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
