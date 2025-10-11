<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Create participants for a multi-day tournament
$participants = [
    new Participant('team1', 'Thunder Hawks', 1, ['endurance' => 'high']),
    new Participant('team2', 'Lightning Bolts', 2, ['endurance' => 'medium']),
    new Participant('team3', 'Storm Eagles', 3, ['endurance' => 'high']),
    new Participant('team4', 'Wind Runners', 4, ['endurance' => 'low']),
    new Participant('team5', 'Fire Phoenixes', 5, ['endurance' => 'medium']),
    new Participant('team6', 'Ice Wolves', 6, ['endurance' => 'high']),
];

// Create schedules with different rest period requirements
$scheduleScenarios = [
    'No Rest Requirements' => [
        'description' => 'Teams can play in consecutive rounds without rest',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->build(),
        'rest_periods' => 0,
    ],
    'Minimum 1 Round Rest' => [
        'description' => 'Teams must have at least 1 round between encounters',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->add(new MinimumRestPeriodsConstraint(1))
            ->build(),
        'rest_periods' => 1,
    ],
    'Minimum 2 Rounds Rest' => [
        'description' => 'Teams must have at least 2 rounds between encounters',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->add(new MinimumRestPeriodsConstraint(2))
            ->build(),
        'rest_periods' => 2,
    ],
    'Minimum 3 Rounds Rest' => [
        'description' => 'Teams must have at least 3 rounds between encounters (challenging)',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->add(new MinimumRestPeriodsConstraint(3))
            ->build(),
        'rest_periods' => 3,
    ],
];

$results = [];

// Generate schedules for each scenario
foreach ($scheduleScenarios as $name => $scenario) {
    try {
        $scheduler = new RoundRobinScheduler($scenario['constraints']);
        $schedule = $scheduler->generateSchedule($participants);

        $results[$name] = [
            'status' => 'success',
            'schedule' => $schedule,
            'description' => $scenario['description'],
            'rest_periods' => $scenario['rest_periods'],
            'total_events' => count($schedule),
            'total_rounds' => $schedule->getMetadataValue('total_rounds'),
            'error' => null,
        ];
    } catch (Exception $e) {
        $results[$name] = [
            'status' => 'failed',
            'schedule' => null,
            'description' => $scenario['description'],
            'rest_periods' => $scenario['rest_periods'],
            'total_events' => 0,
            'total_rounds' => 0,
            'error' => $e->getMessage(),
        ];
    }
}

// Function to analyze rest periods between team encounters
function analyzeRestPeriods($schedule, $teamId)
{
    $teamEvents = [];
    foreach ($schedule as $event) {
        $participants = $event->getParticipants();
        foreach ($participants as $participant) {
            if ($participant->getId() === $teamId) {
                $teamEvents[] = $event->getRound()->getNumber();
                break;
            }
        }
    }

    sort($teamEvents);
    $restPeriods = [];
    for ($i = 1; $i < count($teamEvents); ++$i) {
        $restPeriods[] = $teamEvents[$i] - $teamEvents[$i - 1] - 1;
    }

    return [
        'rounds' => $teamEvents,
        'rest_periods' => $restPeriods,
        'min_rest' => !empty($restPeriods) ? min($restPeriods) : 0,
        'max_rest' => !empty($restPeriods) ? max($restPeriods) : 0,
        'avg_rest' => !empty($restPeriods) ? round(array_sum($restPeriods) / count($restPeriods), 1) : 0,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rest Periods - Tactician Examples</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-6xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">⏰ Rest Periods</h1>
                    <p class="text-blue-100">Ensuring minimum rest between participant encounters</p>
                </div>
                <a href="index.php" class="bg-blue-700 hover:bg-blue-800 px-4 py-2 rounded-lg transition-colors">
                    ← Back to Examples
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
        <!-- Rest Periods Concept -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Understanding Rest Periods</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <p class="text-gray-600 mb-4 leading-relaxed">
                        Rest periods ensure that teams don't play too frequently by enforcing a minimum 
                        number of rounds between their matches. This is crucial for multi-day tournaments, 
                        player fatigue management, and fair competition scheduling.
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        The <code class="bg-gray-100 px-2 py-1 rounded text-sm">MinimumRestPeriodsConstraint</code> 
                        prevents teams from playing again until a specified number of rounds have passed.
                    </p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-3">Rest Period Benefits</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-green-500 rounded-full mr-2"></span>
                            <span>Prevents player fatigue</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-blue-500 rounded-full mr-2"></span>
                            <span>Allows recovery time</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-purple-500 rounded-full mr-2"></span>
                            <span>Spreads matches over time</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-orange-500 rounded-full mr-2"></span>
                            <span>Improves tournament flow</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tournament Teams -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Tournament Teams</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($participants as $participant): ?>
                    <?php
                    $endurance = $participant->getMetadataValue('endurance');
                    $enduranceClass = match($endurance) {
                        'high' => 'bg-green-100 border-green-300 text-green-800',
                        'medium' => 'bg-yellow-100 border-yellow-300 text-yellow-800',
                        'low' => 'bg-red-100 border-red-300 text-red-800',
                        default => 'bg-gray-100 border-gray-300 text-gray-800'
                    };
                    ?>
                    <div class="border-2 rounded-lg p-4 <?= $enduranceClass; ?>">
                        <div class="font-semibold"><?= htmlspecialchars($participant->getLabel()); ?></div>
                        <div class="text-sm">Seed #<?= $participant->getSeed(); ?></div>
                        <div class="text-xs mt-1">Endurance: <?= ucfirst($endurance); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Rest Period Comparison -->
        <div class="space-y-8">
            <?php foreach ($results as $scenarioName => $result): ?>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                                <?php if ($result['status'] === 'success'): ?>
                                    <span class="mr-2">✅</span>
                                <?php else: ?>
                                    <span class="mr-2">❌</span>
                                <?php endif; ?>
                                <?= htmlspecialchars($scenarioName); ?>
                            </h3>
                            <p class="text-gray-600"><?= htmlspecialchars($result['description']); ?></p>
                        </div>
                        <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm">
                            <?= $result['rest_periods']; ?> round<?= $result['rest_periods'] === 1 ? '' : 's'; ?> rest
                        </span>
                    </div>

                    <?php if ($result['status'] === 'success'): ?>
                        <!-- Success - Show schedule analysis -->
                        <div class="grid grid-cols-3 gap-4 mb-6">
                            <div class="bg-green-50 rounded-lg p-4">
                                <div class="text-2xl font-bold text-green-600"><?= $result['total_events']; ?></div>
                                <div class="text-green-700 text-sm">Total Matches</div>
                            </div>
                            <div class="bg-blue-50 rounded-lg p-4">
                                <div class="text-2xl font-bold text-blue-600"><?= $result['total_rounds']; ?></div>
                                <div class="text-blue-700 text-sm">Total Rounds</div>
                            </div>
                            <div class="bg-purple-50 rounded-lg p-4">
                                <div class="text-2xl font-bold text-purple-600"><?= $result['rest_periods']; ?></div>
                                <div class="text-purple-700 text-sm">Min Rest Required</div>
                            </div>
                        </div>

                        <!-- Team Rest Analysis -->
                        <div class="space-y-4">
                            <h4 class="font-medium text-gray-800">Team Rest Period Analysis</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <?php foreach ($participants as $participant): ?>
                                    <?php $analysis = analyzeRestPeriods($result['schedule'], $participant->getId()); ?>
                                    <div class="border border-gray-200 rounded-lg p-3">
                                        <div class="font-medium text-gray-800 mb-2"><?= htmlspecialchars($participant->getLabel()); ?></div>
                                        <div class="text-xs space-y-1">
                                            <div class="flex justify-between">
                                                <span class="text-gray-500">Matches:</span>
                                                <span class="font-medium"><?= count($analysis['rounds']); ?></span>
                                            </div>
                                            <div class="flex justify-between">
                                                <span class="text-gray-500">Min Rest:</span>
                                                <span class="font-medium"><?= $analysis['min_rest']; ?> rounds</span>
                                            </div>
                                            <div class="flex justify-between">
                                                <span class="text-gray-500">Max Rest:</span>
                                                <span class="font-medium"><?= $analysis['max_rest']; ?> rounds</span>
                                            </div>
                                            <div class="flex justify-between">
                                                <span class="text-gray-500">Avg Rest:</span>
                                                <span class="font-medium"><?= $analysis['avg_rest']; ?> rounds</span>
                                            </div>
                                        </div>
                                        
                                        <!-- Visual representation of match rounds -->
                                        <div class="mt-2">
                                            <div class="text-xs text-gray-500 mb-1">Match Rounds:</div>
                                            <div class="flex flex-wrap gap-1">
                                                <?php foreach ($analysis['rounds'] as $round): ?>
                                                    <span class="bg-blue-100 text-blue-800 px-1 py-0.5 rounded text-xs">
                                                        <?= $round; ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Sample Schedule Timeline -->
                        <div class="mt-6">
                            <h4 class="font-medium text-gray-800 mb-3">Tournament Timeline (First 8 Rounds)</h4>
                            <div class="overflow-x-auto">
                                <div class="flex space-x-2 pb-2">
                                    <?php
                                    $roundEvents = [];
                        foreach ($result['schedule'] as $event) {
                            $roundNumber = $event->getRound()->getNumber();
                            if ($roundNumber <= 8) {
                                if (!isset($roundEvents[$roundNumber])) {
                                    $roundEvents[$roundNumber] = [];
                                }
                                $roundEvents[$roundNumber][] = $event;
                            }
                        }
                        ?>
                                    
                                    <?php for ($round = 1; $round <= min(8, $result['total_rounds']); ++$round): ?>
                                        <div class="min-w-32 bg-gray-100 rounded-lg p-2">
                                            <div class="text-center font-medium text-gray-800 mb-2 text-sm">
                                                Round <?= $round; ?>
                                            </div>
                                            <?php if (isset($roundEvents[$round])): ?>
                                                <div class="space-y-1">
                                                    <?php foreach ($roundEvents[$round] as $event): ?>
                                                        <?php $eventParticipants = $event->getParticipants(); ?>
                                                        <div class="bg-white rounded p-1 text-xs text-center">
                                                            <?= htmlspecialchars(substr($eventParticipants[0]->getLabel(), 0, 8)); ?><br>
                                                            <span class="text-gray-400">vs</span><br>
                                                            <?= htmlspecialchars(substr($eventParticipants[1]->getLabel(), 0, 8)); ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-xs text-gray-500 text-center">No matches</div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- Failed - Show error -->
                        <div class="bg-red-50 rounded-lg p-4">
                            <h4 class="font-semibold text-red-800 mb-2">❌ Schedule Generation Failed</h4>
                            <p class="text-red-700 text-sm mb-3"><?= htmlspecialchars($result['error']); ?></p>
                            
                            <div class="bg-red-100 rounded p-3 text-sm text-red-800">
                                <strong>Why this failed:</strong> With <?= count($participants); ?> teams and a requirement 
                                for <?= $result['rest_periods']; ?> round<?= $result['rest_periods'] === 1 ? '' : 's'; ?> rest 
                                between encounters, the scheduling becomes mathematically impossible. There aren't 
                                enough rounds to properly space out all the required matches.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Code Example -->
        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Code Example</h2>
            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 overflow-x-auto">
                <pre><code><?= htmlspecialchars('<?php
use MissionGaming\\Tactician\\Constraints\\ConstraintSet;
use MissionGaming\\Tactician\\Constraints\\MinimumRestPeriodsConstraint;
use MissionGaming\\Tactician\\Scheduling\\RoundRobinScheduler;

// Require teams to have at least 2 rounds rest between matches
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(new MinimumRestPeriodsConstraint(2))
    ->build();

$scheduler = new RoundRobinScheduler($constraints);

try {
    $schedule = $scheduler->generateSchedule($participants);
    echo "Schedule generated successfully!";
    
    // Analyze rest periods for a specific team
    $teamEvents = [];
    foreach ($schedule as $event) {
        $participants = $event->getParticipants();
        foreach ($participants as $participant) {
            if ($participant->getId() === \'team1\') {
                $teamEvents[] = $event->getRound()->getNumber();
            }
        }
    }
    
    // Calculate rest periods between matches
    sort($teamEvents);
    for ($i = 1; $i < count($teamEvents); $i++) {
        $restPeriods = $teamEvents[$i] - $teamEvents[$i-1] - 1;
        echo "Rest between match " . ($i) . " and " . ($i+1) . ": " . $restPeriods . " rounds";
    }
    
} catch (IncompleteScheduleException $e) {
    echo "Could not generate schedule with current rest requirements";
    echo "Consider reducing the minimum rest period";
}'); ?></code></pre>
            </div>
        </div>

        <!-- Best Practices -->
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mt-8">
            <h3 class="text-lg font-semibold text-yellow-800 mb-3">⚡ Rest Period Best Practices</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
                <div>
                    <h4 class="font-medium text-yellow-800 mb-2">Recommendations</h4>
                    <ul class="space-y-1 text-yellow-700">
                        <li>• Start with 1-2 rounds for most tournaments</li>
                        <li>• Consider sport intensity and player endurance</li>
                        <li>• Test constraints with smaller groups first</li>
                        <li>• Balance fairness with scheduling feasibility</li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-medium text-yellow-800 mb-2">Common Use Cases</h4>
                    <ul class="space-y-1 text-yellow-700">
                        <li>• Multi-day tournaments (2+ rounds rest)</li>
                        <li>• Physical sports requiring recovery</li>
                        <li>• Limited venue scheduling</li>
                        <li>• Player availability constraints</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <div class="flex justify-between mt-8">
            <a href="05-seed-protection.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition-colors">
                ← Previous: Seed Protection
            </a>
            <a href="07-metadata-constraints.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg transition-colors">
                Next: Metadata Constraints →
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
