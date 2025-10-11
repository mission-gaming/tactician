<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Ordering\AlternatingParticipantOrderer;
use MissionGaming\Tactician\Ordering\BalancedParticipantOrderer;
use MissionGaming\Tactician\Ordering\SeededRandomParticipantOrderer;
use MissionGaming\Tactician\Ordering\StaticParticipantOrderer;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Create participants
$participants = [
    new Participant('celtic', 'Celtic', 1),
    new Participant('athletic', 'Athletic Bilbao', 2),
    new Participant('livorno', 'AS Livorno', 3),
    new Participant('redstar', 'Red Star FC', 4),
];

// Create schedules with different participant ordering strategies
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->build();

$ordererScenarios = [
    'Static (Default)' => [
        'orderer' => new StaticParticipantOrderer(),
        'description' => 'Maintains array order - first participant is always "home"',
        'color' => 'blue',
    ],
    'Alternating' => [
        'orderer' => new AlternatingParticipantOrderer(),
        'description' => 'Alternates based on event index - creates balanced home/away distribution',
        'color' => 'green',
    ],
    'Balanced' => [
        'orderer' => new BalancedParticipantOrderer(),
        'description' => 'Balances home/away based on participant history - perfect for Swiss tournaments',
        'color' => 'purple',
    ],
    'Seeded Random' => [
        'orderer' => new SeededRandomParticipantOrderer(),
        'description' => 'Deterministic randomization using CRC32 hash - same seed always produces same order',
        'color' => 'orange',
    ],
];

$schedules = [];
foreach ($ordererScenarios as $name => $scenario) {
    $scheduler = new RoundRobinScheduler($constraints, null, $scenario['orderer']);
    $schedules[$name] = [
        'schedule' => $scheduler->generateSchedule($participants),
        'description' => $scenario['description'],
        'color' => $scenario['color'],
    ];
}

// Function to analyze home/away distribution
function analyzeHomeAwayDistribution($schedule, $participants)
{
    $stats = [];

    foreach ($participants as $participant) {
        $stats[$participant->getId()] = [
            'participant' => $participant,
            'home' => 0,
            'away' => 0,
        ];
    }

    foreach ($schedule as $event) {
        $eventParticipants = $event->getParticipants();
        if (count($eventParticipants) === 2) {
            ++$stats[$eventParticipants[0]->getId()]['home'];
            ++$stats[$eventParticipants[1]->getId()]['away'];
        }
    }

    return $stats;
}

// Analyze each schedule
$analyses = [];
foreach ($schedules as $name => $data) {
    $analyses[$name] = analyzeHomeAwayDistribution($data['schedule'], $participants);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Participant Ordering - Tactician Examples</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-purple-600 text-white shadow-lg">
        <div class="max-w-6xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">🎲 Participant Ordering Strategies</h1>
                    <p class="text-purple-100">Control home/away assignments with different ordering strategies</p>
                </div>
                <a href="index.php" class="bg-purple-700 hover:bg-purple-800 px-4 py-2 rounded-lg transition-colors">
                    ← Back to Examples
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
        <!-- Introduction -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Why Participant Ordering Matters</h2>
            <div class="space-y-3 text-gray-600">
                <p>
                    In sports tournaments, the order of participants in an event often determines "home" and "away"
                    designations. By default, the first participant in the array is always "home", which creates
                    imbalance (e.g., "Celtic always home").
                </p>
                <p>
                    <strong>Participant Orderers</strong> solve this by providing different strategies to control
                    the order of participants within each event, creating fair and balanced schedules.
                </p>
            </div>
        </div>

        <!-- Orderer Strategies Comparison -->
        <div class="space-y-6">
            <?php foreach ($ordererScenarios as $name => $scenario): ?>
                <?php
                $schedule = $schedules[$name]['schedule'];
                $analysis = $analyses[$name];
                $colorClass = $scenario['color'];
                ?>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($name); ?></h3>
                        <span class="bg-<?= $colorClass; ?>-100 text-<?= $colorClass; ?>-800 px-3 py-1 rounded-full text-sm font-medium">
                            <?= count($schedule); ?> matches
                        </span>
                    </div>

                    <p class="text-gray-600 mb-4"><?= htmlspecialchars($scenario['description']); ?></p>

                    <!-- Home/Away Distribution -->
                    <div class="bg-gray-50 rounded-lg p-4 mb-4">
                        <h4 class="font-semibold text-gray-800 mb-3">Home/Away Distribution</h4>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <?php foreach ($analysis as $stats): ?>
                                <div class="bg-white rounded-lg p-3 text-center">
                                    <div class="text-sm font-medium text-gray-800 mb-2">
                                        <?= htmlspecialchars($stats['participant']->getLabel()); ?>
                                    </div>
                                    <div class="flex justify-around text-xs">
                                        <div>
                                            <div class="text-<?= $colorClass; ?>-600 font-bold"><?= $stats['home']; ?></div>
                                            <div class="text-gray-500">Home</div>
                                        </div>
                                        <div>
                                            <div class="text-gray-600 font-bold"><?= $stats['away']; ?></div>
                                            <div class="text-gray-500">Away</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- First 3 Matches -->
                    <div>
                        <h4 class="font-semibold text-gray-800 mb-3">First 3 Matches</h4>
                        <div class="space-y-2">
                            <?php
                            $count = 0;
                foreach ($schedule as $event):
                    if (++$count > 3) {
                        break;
                    }
                    $eventParticipants = $event->getParticipants();
                    $round = $event->getRound();
                    ?>
                                <div class="flex items-center justify-between bg-gray-50 rounded-lg p-3">
                                    <span class="text-xs text-gray-500 w-16">R<?= $round->getNumber(); ?></span>
                                    <div class="flex-1 flex items-center justify-between">
                                        <div class="text-center flex-1">
                                            <span class="font-medium text-<?= $colorClass; ?>-600">
                                                🏠 <?= htmlspecialchars($eventParticipants[0]->getLabel()); ?>
                                            </span>
                                        </div>
                                        <div class="px-3 text-gray-400">vs</div>
                                        <div class="text-center flex-1">
                                            <span class="font-medium text-gray-600">
                                                ✈️ <?= htmlspecialchars($eventParticipants[1]->getLabel()); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Code Examples -->
        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Usage Examples</h2>

            <div class="space-y-4">
                <div>
                    <h3 class="font-semibold text-gray-800 mb-2">Static Orderer (Default)</h3>
                    <div class="bg-gray-900 text-gray-100 rounded-lg p-4 overflow-x-auto">
                        <pre><code><?= htmlspecialchars('use MissionGaming\\Tactician\\Ordering\\StaticParticipantOrderer;

// Default behavior - first participant always "home"
$scheduler = new RoundRobinScheduler(
    constraints: $constraints,
    participantOrderer: new StaticParticipantOrderer()
);'); ?></code></pre>
                    </div>
                </div>

                <div>
                    <h3 class="font-semibold text-gray-800 mb-2">Alternating Orderer</h3>
                    <div class="bg-gray-900 text-gray-100 rounded-lg p-4 overflow-x-auto">
                        <pre><code><?= htmlspecialchars('use MissionGaming\\Tactician\\Ordering\\AlternatingParticipantOrderer;

// Alternates home/away based on event index
$scheduler = new RoundRobinScheduler(
    constraints: $constraints,
    participantOrderer: new AlternatingParticipantOrderer()
);'); ?></code></pre>
                    </div>
                </div>

                <div>
                    <h3 class="font-semibold text-gray-800 mb-2">Balanced Orderer (Recommended for Swiss)</h3>
                    <div class="bg-gray-900 text-gray-100 rounded-lg p-4 overflow-x-auto">
                        <pre><code><?= htmlspecialchars('use MissionGaming\\Tactician\\Ordering\\BalancedParticipantOrderer;

// Balances home/away based on participant history
// Perfect for Swiss tournaments!
$scheduler = new RoundRobinScheduler(
    constraints: $constraints,
    participantOrderer: new BalancedParticipantOrderer()
);'); ?></code></pre>
                    </div>
                </div>

                <div>
                    <h3 class="font-semibold text-gray-800 mb-2">Seeded Random Orderer</h3>
                    <div class="bg-gray-900 text-gray-100 rounded-lg p-4 overflow-x-auto">
                        <pre><code><?= htmlspecialchars('use MissionGaming\\Tactician\\Ordering\\SeededRandomParticipantOrderer;

// Deterministic randomization
// Same seed always produces same order
$scheduler = new RoundRobinScheduler(
    constraints: $constraints,
    participantOrderer: new SeededRandomParticipantOrderer()
);'); ?></code></pre>
                    </div>
                </div>
            </div>
        </div>

        <!-- Key Insights -->
        <div class="bg-blue-50 rounded-lg p-6 mt-8">
            <h3 class="font-semibold text-blue-900 mb-3">💡 Key Insights</h3>
            <ul class="space-y-2 text-blue-800">
                <li class="flex items-start">
                    <span class="mr-2">→</span>
                    <span><strong>StaticParticipantOrderer</strong> creates imbalance - useful when you need consistent "home" assignments</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-2">→</span>
                    <span><strong>AlternatingParticipantOrderer</strong> provides simple balance within rounds</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-2">→</span>
                    <span><strong>BalancedParticipantOrderer</strong> is ideal for Swiss tournaments where balance matters across all rounds</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-2">→</span>
                    <span><strong>SeededRandomParticipantOrderer</strong> provides fairness through deterministic randomization</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-2">→</span>
                    <span>Ordering strategies are independent of leg strategies - mix and match as needed!</span>
                </li>
            </ul>
        </div>

        <!-- Navigation -->
        <div class="flex justify-between mt-8">
            <a href="12-performance-patterns.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition-colors">
                ← Performance Patterns
            </a>
            <a href="14-position-based-scheduling.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg transition-colors">
                Next: Position-Based Scheduling →
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
