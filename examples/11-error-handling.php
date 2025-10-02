<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\ImpossibleConstraintsException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\SchedulingException;

// Create a small set of participants for demonstration
$participants = [
    new Participant('team1', 'Team Alpha', 1),
    new Participant('team2', 'Team Beta', 2),
    new Participant('team3', 'Team Gamma', 3),
    new Participant('team4', 'Team Delta', 4),
];

// Different constraint scenarios to demonstrate error handling
$scenarios = [
    'Valid Schedule' => [
        'description' => 'Normal constraints that should work fine',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->build(),
        'expected' => 'success'
    ],
    'Restrictive but Possible' => [
        'description' => 'Challenging but achievable constraints',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->add(new SeedProtectionConstraint(2, 0.3))
            ->build(),
        'expected' => 'success'
    ],
    'Very Restrictive' => [
        'description' => 'Very restrictive constraints that may cause issues',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->add(new MinimumRestPeriodsConstraint(3))
            ->add(ConsecutiveRoleConstraint::homeAway(1))
            ->build(),
        'expected' => 'possible_failure'
    ],
    'Impossible Constraints' => [
        'description' => 'Mathematically impossible constraints',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->add(new MinimumRestPeriodsConstraint(10)) // Impossible with only 4 teams
            ->build(),
        'expected' => 'failure'
    ]
];

$results = [];

// Test each scenario
foreach ($scenarios as $name => $scenario) {
    $result = [
        'name' => $name,
        'description' => $scenario['description'],
        'expected' => $scenario['expected'],
        'status' => null,
        'schedule' => null,
        'exception' => null,
        'error_type' => null,
        'error_message' => null,
        'diagnostic_info' => []
    ];
    
    try {
        $scheduler = new RoundRobinScheduler($scenario['constraints']);
        $schedule = $scheduler->schedule($participants);
        
        $result['status'] = 'success';
        $result['schedule'] = $schedule;
        $result['total_events'] = count($schedule);
        $result['total_rounds'] = $schedule->getMetadataValue('total_rounds');
        
    } catch (IncompleteScheduleException $e) {
        $result['status'] = 'incomplete';
        $result['exception'] = $e;
        $result['error_type'] = 'Incomplete Schedule';
        $result['error_message'] = $e->getMessage();
        $result['diagnostic_info'] = [
            'expected_events' => $e->getExpectedEventCount(),
            'generated_events' => $e->getGeneratedEventCount(),
            'constraint_violations' => count($e->getConstraintViolations())
        ];
        
    } catch (ImpossibleConstraintsException $e) {
        $result['status'] = 'impossible';
        $result['exception'] = $e;
        $result['error_type'] = 'Impossible Constraints';
        $result['error_message'] = $e->getMessage();
        
    } catch (InvalidConfigurationException $e) {
        $result['status'] = 'invalid_config';
        $result['exception'] = $e;
        $result['error_type'] = 'Invalid Configuration';
        $result['error_message'] = $e->getMessage();
        
    } catch (SchedulingException $e) {
        $result['status'] = 'general_error';
        $result['exception'] = $e;
        $result['error_type'] = 'Scheduling Error';
        $result['error_message'] = $e->getMessage();
        
    } catch (Exception $e) {
        $result['status'] = 'unexpected_error';
        $result['exception'] = $e;
        $result['error_type'] = 'Unexpected Error';
        $result['error_message'] = $e->getMessage();
    }
    
    $results[] = $result;
}

// Function to get status badge styling
function getStatusBadge($status) {
    switch ($status) {
        case 'success':
            return 'bg-green-100 text-green-800 border-green-300';
        case 'incomplete':
            return 'bg-yellow-100 text-yellow-800 border-yellow-300';
        case 'impossible':
        case 'general_error':
            return 'bg-red-100 text-red-800 border-red-300';
        case 'invalid_config':
            return 'bg-orange-100 text-orange-800 border-orange-300';
        default:
            return 'bg-gray-100 text-gray-800 border-gray-300';
    }
}

function getStatusIcon($status) {
    switch ($status) {
        case 'success':
            return '✅';
        case 'incomplete':
            return '⚠️';
        case 'impossible':
        case 'general_error':
            return '❌';
        case 'invalid_config':
            return '🔧';
        default:
            return '❓';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error Handling - Tactician Examples</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-6xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">⚠️ Error Handling</h1>
                    <p class="text-blue-100">Validation failures and exception demonstrations</p>
                </div>
                <a href="index.php" class="bg-blue-700 hover:bg-blue-800 px-4 py-2 rounded-lg transition-colors">
                    ← Back to Examples
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
        <!-- Error Handling Concept -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Understanding Error Handling</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <p class="text-gray-600 mb-4 leading-relaxed">
                        Tactician provides comprehensive error handling to help you identify and resolve 
                        issues with tournament scheduling. Different types of problems result in specific 
                        exceptions with detailed diagnostic information.
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        The library validates constraints and schedules to ensure completeness, 
                        preventing silent failures that could result in incomplete tournaments.
                    </p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-3">Exception Types</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-green-500 rounded-full mr-2"></span>
                            <span>Successful generation</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-yellow-500 rounded-full mr-2"></span>
                            <span>IncompleteScheduleException</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-red-500 rounded-full mr-2"></span>
                            <span>ImpossibleConstraintsException</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-orange-500 rounded-full mr-2"></span>
                            <span>InvalidConfigurationException</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Test Participants -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Test Participants</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php foreach ($participants as $participant): ?>
                    <div class="border border-gray-200 rounded-lg p-3 text-center">
                        <div class="font-semibold text-gray-800"><?= htmlspecialchars($participant->getLabel()) ?></div>
                        <div class="text-sm text-gray-500">Seed #<?= $participant->getSeed() ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Scenario Results -->
        <div class="space-y-6">
            <?php foreach ($results as $result): ?>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                                <span class="mr-2"><?= getStatusIcon($result['status']) ?></span>
                                <?= htmlspecialchars($result['name']) ?>
                            </h3>
                            <p class="text-gray-600"><?= htmlspecialchars($result['description']) ?></p>
                        </div>
                        <span class="px-3 py-1 rounded-full text-sm border <?= getStatusBadge($result['status']) ?>">
                            <?= ucfirst(str_replace('_', ' ', $result['status'])) ?>
                        </span>
                    </div>

                    <?php if ($result['status'] === 'success'): ?>
                        <!-- Success Case -->
                        <div class="bg-green-50 rounded-lg p-4">
                            <h4 class="font-semibold text-green-800 mb-2">✅ Schedule Generated Successfully</h4>
                            <div class="grid grid-cols-3 gap-4 text-sm">
                                <div>
                                    <span class="text-green-700">Total Events:</span>
                                    <span class="font-medium ml-1"><?= $result['total_events'] ?></span>
                                </div>
                                <div>
                                    <span class="text-green-700">Total Rounds:</span>
                                    <span class="font-medium ml-1"><?= $result['total_rounds'] ?></span>
                                </div>
                                <div>
                                    <span class="text-green-700">Status:</span>
                                    <span class="font-medium ml-1">Complete</span>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($result['status'] === 'incomplete'): ?>
                        <!-- Incomplete Schedule -->
                        <div class="bg-yellow-50 rounded-lg p-4">
                            <h4 class="font-semibold text-yellow-800 mb-2">⚠️ Incomplete Schedule</h4>
                            <p class="text-yellow-700 text-sm mb-3"><?= htmlspecialchars($result['error_message']) ?></p>
                            
                            <div class="grid grid-cols-3 gap-4 text-sm mb-3">
                                <div>
                                    <span class="text-yellow-700">Expected Events:</span>
                                    <span class="font-medium ml-1"><?= $result['diagnostic_info']['expected_events'] ?></span>
                                </div>
                                <div>
                                    <span class="text-yellow-700">Generated Events:</span>
                                    <span class="font-medium ml-1"><?= $result['diagnostic_info']['generated_events'] ?></span>
                                </div>
                                <div>
                                    <span class="text-yellow-700">Violations:</span>
                                    <span class="font-medium ml-1"><?= $result['diagnostic_info']['constraint_violations'] ?></span>
                                </div>
                            </div>

                            <?php if (!empty($result['exception']) && method_exists($result['exception'], 'getConstraintViolations')): ?>
                                <div class="mt-3">
                                    <h5 class="font-medium text-yellow-800 mb-2">Constraint Violations:</h5>
                                    <div class="space-y-1">
                                        <?php foreach ($result['exception']->getConstraintViolations() as $violation): ?>
                                            <div class="bg-yellow-100 rounded px-2 py-1 text-xs text-yellow-800">
                                                <?= htmlspecialchars($violation->getDescription()) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                    <?php else: ?>
                        <!-- Error Cases -->
                        <div class="bg-red-50 rounded-lg p-4">
                            <h4 class="font-semibold text-red-800 mb-2">❌ <?= htmlspecialchars($result['error_type']) ?></h4>
                            <p class="text-red-700 text-sm mb-3"><?= htmlspecialchars($result['error_message']) ?></p>
                            
                            <?php if ($result['error_type'] === 'Impossible Constraints'): ?>
                                <div class="bg-red-100 rounded p-3 text-sm text-red-800">
                                    <strong>Why this failed:</strong> The constraints are mathematically impossible to satisfy. 
                                    With only 4 teams and a 10-round minimum rest period constraint, there aren't enough 
                                    rounds to space out encounters properly.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Exception Details (if in debug mode) -->
                    <?php if (!empty($result['exception'])): ?>
                        <details class="mt-4">
                            <summary class="cursor-pointer text-sm text-gray-600 hover:text-gray-800">
                                🔍 Technical Details (Click to expand)
                            </summary>
                            <div class="mt-2 bg-gray-100 rounded p-3 text-xs">
                                <div class="font-medium mb-1">Exception Class:</div>
                                <div class="mb-2 font-mono"><?= get_class($result['exception']) ?></div>
                                
                                <div class="font-medium mb-1">Stack Trace:</div>
                                <pre class="bg-gray-200 p-2 rounded text-xs overflow-x-auto"><?= htmlspecialchars($result['exception']->getTraceAsString()) ?></pre>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Best Practices -->
        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Error Handling Best Practices</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h3 class="font-semibold text-gray-800 mb-3">Defensive Programming</h3>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li class="flex items-start">
                            <span class="text-green-500 mr-2">✓</span>
                            Always wrap scheduling in try-catch blocks
                        </li>
                        <li class="flex items-start">
                            <span class="text-green-500 mr-2">✓</span>
                            Handle different exception types appropriately
                        </li>
                        <li class="flex items-start">
                            <span class="text-green-500 mr-2">✓</span>
                            Validate constraints before scheduling large tournaments
                        </li>
                        <li class="flex items-start">
                            <span class="text-green-500 mr-2">✓</span>
                            Provide meaningful error messages to users
                        </li>
                    </ul>
                </div>
                <div>
                    <h3 class="font-semibold text-gray-800 mb-3">Debugging Tips</h3>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li class="flex items-start">
                            <span class="text-blue-500 mr-2">💡</span>
                            Use diagnostic information for troubleshooting
                        </li>
                        <li class="flex items-start">
                            <span class="text-blue-500 mr-2">💡</span>
                            Test with smaller participant sets first
                        </li>
                        <li class="flex items-start">
                            <span class="text-blue-500 mr-2">💡</span>
                            Review constraint violations for specific issues
                        </li>
                        <li class="flex items-start">
                            <span class="text-blue-500 mr-2">💡</span>
                            Consider relaxing constraints if scheduling fails
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Code Example -->
        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Code Example</h2>
            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 overflow-x-auto">
                <pre><code><?= htmlspecialchars('<?php
use MissionGaming\\Tactician\\Scheduling\\RoundRobinScheduler;
use MissionGaming\\Tactician\\Exceptions\\IncompleteScheduleException;
use MissionGaming\\Tactician\\Exceptions\\ImpossibleConstraintsException;
use MissionGaming\\Tactician\\Exceptions\\SchedulingException;

try {
    $scheduler = new RoundRobinScheduler($constraints);
    $schedule = $scheduler->schedule($participants);
    
    // Success - proceed with schedule
    echo "Schedule generated successfully with " . count($schedule) . " events";
    
} catch (IncompleteScheduleException $e) {
    // Schedule incomplete due to constraint conflicts
    echo "Incomplete schedule: " . $e->getMessage();
    echo "Generated: " . $e->getGeneratedEventCount();
    echo "Expected: " . $e->getExpectedEventCount();
    
    // Show constraint violations
    foreach ($e->getConstraintViolations() as $violation) {
        echo "Violation: " . $violation->getDescription();
    }
    
} catch (ImpossibleConstraintsException $e) {
    // Constraints are mathematically impossible
    echo "Impossible constraints: " . $e->getMessage();
    echo "Consider relaxing constraints or using fewer participants";
    
} catch (SchedulingException $e) {
    // Other scheduling errors
    echo "Scheduling error: " . $e->getMessage();
    
} catch (Exception $e) {
    // Unexpected errors
    echo "Unexpected error: " . $e->getMessage();
}') ?></code></pre>
            </div>
        </div>

        <!-- Navigation -->
        <div class="flex justify-between mt-8">
            <a href="10-complex-tournament.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition-colors">
                ← Previous: Complex Tournament
            </a>
            <a href="12-performance-patterns.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg transition-colors">
                Next: Performance Patterns →
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
