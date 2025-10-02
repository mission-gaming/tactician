<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Create different participant set sizes for performance comparison
$participantSets = [
    'Small (6 teams)' => [],
    'Medium (12 teams)' => [],
    'Large (20 teams)' => [],
    'Very Large (30 teams)' => [],
];

// Generate participants for each set size
for ($i = 1; $i <= 30; ++$i) {
    $teamName = 'Team ' . chr(64 + (($i - 1) % 26) + 1) . ($i > 26 ? (int) (($i - 1) / 26) : '');
    $participant = new Participant(
        'team' . $i,
        $teamName,
        $i,
        [
            'skill_rating' => rand(1000, 3000),
            'region' => ['North', 'South', 'East', 'West'][rand(0, 3)],
            'experience' => ['rookie', 'intermediate', 'veteran'][rand(0, 2)],
            'budget' => ['low', 'medium', 'high'][rand(0, 2)],
        ]
    );

    if ($i <= 6) {
        $participantSets['Small (6 teams)'][] = $participant;
    }
    if ($i <= 12) {
        $participantSets['Medium (12 teams)'][] = $participant;
    }
    if ($i <= 20) {
        $participantSets['Large (20 teams)'][] = $participant;
    }
    $participantSets['Very Large (30 teams)'][] = $participant;
}

// Performance testing function
function measurePerformance($participants, $constraints, $testName)
{
    $startTime = microtime(true);
    $startMemory = memory_get_usage(true);

    try {
        $scheduler = new RoundRobinScheduler($constraints);
        $schedule = $scheduler->schedule($participants);

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);

        return [
            'success' => true,
            'time' => round(($endTime - $startTime) * 1000, 2), // milliseconds
            'memory_used' => $endMemory - $startMemory,
            'peak_memory' => $peakMemory,
            'total_events' => count($schedule),
            'total_rounds' => $schedule->getMetadataValue('total_rounds'),
            'events_per_round' => $schedule->getMetadataValue('events_per_round'),
            'error' => null,
            'test_name' => $testName,
        ];
    } catch (Exception $e) {
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        return [
            'success' => false,
            'time' => round(($endTime - $startTime) * 1000, 2),
            'memory_used' => $endMemory - $startMemory,
            'peak_memory' => memory_get_peak_usage(true),
            'total_events' => 0,
            'total_rounds' => 0,
            'events_per_round' => 0,
            'error' => $e->getMessage(),
            'test_name' => $testName,
        ];
    }
}

// Run performance tests
$performanceResults = [];

foreach ($participantSets as $setName => $participants) {
    if (empty($participants)) {
        continue;
    }

    // Test 1: Basic constraints
    $basicConstraints = ConstraintSet::create()
        ->noRepeatPairings()
        ->build();

    $result = measurePerformance($participants, $basicConstraints, $setName . ' - Basic');
    $result['constraint_type'] = 'Basic (NoRepeatPairings only)';
    $result['participant_count'] = count($participants);
    $performanceResults[] = $result;
}

// Memory-efficient iteration patterns demonstration
function demonstrateIterationPatterns($schedule)
{
    if (!$schedule) {
        return null;
    }

    // Pattern 1: Direct iteration (memory efficient)
    $start = microtime(true);
    $count1 = 0;
    foreach ($schedule as $event) {
        ++$count1;
        // Simulate some processing
        $participants = $event->getParticipants();
    }
    $time1 = (microtime(true) - $start) * 1000;

    // Pattern 2: Convert to array (memory intensive)
    $start = microtime(true);
    $eventsArray = iterator_to_array($schedule);
    $count2 = count($eventsArray);
    // Process array
    foreach ($eventsArray as $event) {
        $participants = $event->getParticipants();
    }
    $time2 = (microtime(true) - $start) * 1000;

    // Pattern 3: Count only (most efficient)
    $start = microtime(true);
    $count3 = count($schedule);
    $time3 = (microtime(true) - $start) * 1000;

    return [
        'direct_iteration' => ['time' => round($time1, 2), 'count' => $count1],
        'array_conversion' => ['time' => round($time2, 2), 'count' => $count2],
        'count_only' => ['time' => round($time3, 2), 'count' => $count3],
    ];
}

// Generate a medium schedule for iteration testing
$mediumSchedule = null;
try {
    $scheduler = new RoundRobinScheduler(ConstraintSet::create()->noRepeatPairings()->build());
    $mediumSchedule = $scheduler->schedule($participantSets['Medium (12 teams)']);
} catch (Exception $e) {
    // Handle error
}

$iterationResults = demonstrateIterationPatterns($mediumSchedule);

// Helper functions
function formatBytes($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);

    return round($bytes, 2) . ' ' . $units[$pow];
}

function getPerformanceColor($time)
{
    if ($time < 10) {
        return 'bg-green-100 text-green-800';
    }
    if ($time < 50) {
        return 'bg-yellow-100 text-yellow-800';
    }
    if ($time < 200) {
        return 'bg-orange-100 text-orange-800';
    }

    return 'bg-red-100 text-red-800';
}

function getMemoryColor($memory)
{
    if ($memory < 1024 * 1024) {
        return 'bg-green-100 text-green-800';
    } // < 1MB
    if ($memory < 5 * 1024 * 1024) {
        return 'bg-yellow-100 text-yellow-800';
    } // < 5MB
    if ($memory < 10 * 1024 * 1024) {
        return 'bg-orange-100 text-orange-800';
    } // < 10MB

    return 'bg-red-100 text-red-800';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Patterns - Tactician Examples</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-6xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">⚡ Performance Patterns</h1>
                    <p class="text-blue-100">Memory-efficient scheduling for large tournaments</p>
                </div>
                <a href="index.php" class="bg-blue-700 hover:bg-blue-800 px-4 py-2 rounded-lg transition-colors">
                    ← Back to Examples
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
        <!-- Performance Overview -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Performance Considerations</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <p class="text-gray-600 mb-4 leading-relaxed">
                        Tournament scheduling performance scales with the number of participants and constraints. 
                        Understanding these patterns helps you build efficient systems that can handle everything 
                        from small local tournaments to massive international events.
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        This example demonstrates memory usage, execution time, and iteration patterns 
                        across different tournament sizes and constraint complexities.
                    </p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-3">Key Performance Factors</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-blue-500 rounded-full mr-2"></span>
                            <span>Number of participants (O(n²) events)</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-green-500 rounded-full mr-2"></span>
                            <span>Constraint complexity and count</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-purple-500 rounded-full mr-2"></span>
                            <span>Memory allocation patterns</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-orange-500 rounded-full mr-2"></span>
                            <span>Schedule iteration methods</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Test Results -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Performance Benchmarks</h2>
            <p class="text-gray-600 mb-6">
                Performance measurements across different tournament sizes using basic constraints (NoRepeatPairings only).
            </p>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <?php foreach ($performanceResults as $result): ?>
                    <div class="border border-gray-200 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="font-semibold text-gray-800"><?= $result['participant_count']; ?> Teams</h3>
                            <?php if ($result['success']): ?>
                                <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-xs">Success</span>
                            <?php else: ?>
                                <span class="bg-red-100 text-red-800 px-2 py-1 rounded text-xs">Failed</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($result['success']): ?>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Time:</span>
                                    <span class="px-2 py-1 rounded text-xs <?= getPerformanceColor($result['time']); ?>">
                                        <?= $result['time']; ?>ms
                                    </span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Memory:</span>
                                    <span class="px-2 py-1 rounded text-xs <?= getMemoryColor($result['memory_used']); ?>">
                                        <?= formatBytes($result['memory_used']); ?>
                                    </span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Events:</span>
                                    <span class="font-medium"><?= $result['total_events']; ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Rounds:</span>
                                    <span class="font-medium"><?= $result['total_rounds']; ?></span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-sm text-red-600">
                                <?= htmlspecialchars($result['error']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Performance Charts -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-3">Execution Time by Team Count</h3>
                    <canvas id="timeChart" width="400" height="200"></canvas>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-3">Memory Usage by Team Count</h3>
                    <canvas id="memoryChart" width="400" height="200"></canvas>
                </div>
            </div>
        </div>

        <!-- Iteration Patterns -->
        <?php if ($iterationResults): ?>
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Schedule Iteration Patterns</h2>
            <p class="text-gray-600 mb-4">
                Comparison of different methods for accessing schedule data (tested on 12-team tournament with <?= $mediumSchedule->getMetadataValue('total_rounds'); ?> rounds).
            </p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="border border-gray-200 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-3 flex items-center">
                        <span class="w-3 h-3 bg-green-500 rounded-full mr-2"></span>
                        Direct Iteration
                    </h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Time:</span>
                            <span class="font-medium text-green-600"><?= $iterationResults['direct_iteration']['time']; ?>ms</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Events:</span>
                            <span class="font-medium"><?= $iterationResults['direct_iteration']['count']; ?></span>
                        </div>
                        <div class="text-xs text-gray-600 mt-2">
                            ✅ Most memory efficient<br>
                            ✅ Suitable for large tournaments<br>
                            ✅ Streaming-friendly
                        </div>
                    </div>
                </div>

                <div class="border border-gray-200 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-3 flex items-center">
                        <span class="w-3 h-3 bg-yellow-500 rounded-full mr-2"></span>
                        Array Conversion
                    </h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Time:</span>
                            <span class="font-medium text-yellow-600"><?= $iterationResults['array_conversion']['time']; ?>ms</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Events:</span>
                            <span class="font-medium"><?= $iterationResults['array_conversion']['count']; ?></span>
                        </div>
                        <div class="text-xs text-gray-600 mt-2">
                            ⚠️ Higher memory usage<br>
                            ✅ Random access to events<br>
                            ✅ Array functions available
                        </div>
                    </div>
                </div>

                <div class="border border-gray-200 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-3 flex items-center">
                        <span class="w-3 h-3 bg-blue-500 rounded-full mr-2"></span>
                        Count Only
                    </h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Time:</span>
                            <span class="font-medium text-blue-600"><?= $iterationResults['count_only']['time']; ?>ms</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Events:</span>
                            <span class="font-medium"><?= $iterationResults['count_only']['count']; ?></span>
                        </div>
                        <div class="text-xs text-gray-600 mt-2">
                            ✅ Fastest execution<br>
                            ✅ Minimal memory usage<br>
                            ✅ Perfect for totals only
                        </div>
                    </div>
                </div>
            </div>

            <!-- Iteration Code Examples -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <h4 class="font-medium text-gray-800 mb-2">Direct Iteration</h4>
                    <div class="bg-gray-900 text-gray-100 rounded p-3 text-xs">
                        <pre><code><?= htmlspecialchars('foreach ($schedule as $event) {
    $participants = $event->getParticipants();
    $round = $event->getRound();
    
    // Process each event
    processEvent($event);
}'); ?></code></pre>
                    </div>
                </div>
                
                <div>
                    <h4 class="font-medium text-gray-800 mb-2">Array Conversion</h4>
                    <div class="bg-gray-900 text-gray-100 rounded p-3 text-xs">
                        <pre><code><?= htmlspecialchars('$events = iterator_to_array($schedule);

// Random access
$firstEvent = $events[0];
$lastEvent = $events[count($events) - 1];

// Array functions
$filtered = array_filter($events, $callback);'); ?></code></pre>
                    </div>
                </div>
                
                <div>
                    <h4 class="font-medium text-gray-800 mb-2">Count Only</h4>
                    <div class="bg-gray-900 text-gray-100 rounded p-3 text-xs">
                        <pre><code><?= htmlspecialchars('// Get total count
$totalEvents = count($schedule);

// Get metadata
$rounds = $schedule->getMetadataValue("total_rounds");
$perRound = $schedule->getMetadataValue("events_per_round");'); ?></code></pre>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Performance Guidelines -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Performance Optimization Guidelines</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h3 class="font-semibold text-gray-800 mb-3">🚀 For Small Tournaments (&lt; 10 teams)</h3>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li class="flex items-start">
                            <span class="text-green-500 mr-2 mt-0.5">✓</span>
                            Any iteration pattern is fine
                        </li>
                        <li class="flex items-start">
                            <span class="text-green-500 mr-2 mt-0.5">✓</span>
                            Array conversion is acceptable
                        </li>
                        <li class="flex items-start">
                            <span class="text-green-500 mr-2 mt-0.5">✓</span>
                            Complex constraints have minimal impact
                        </li>
                        <li class="flex items-start">
                            <span class="text-green-500 mr-2 mt-0.5">✓</span>
                            Memory usage is not a concern
                        </li>
                    </ul>
                </div>

                <div>
                    <h3 class="font-semibold text-gray-800 mb-3">⚡ For Medium Tournaments (10-20 teams)</h3>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li class="flex items-start">
                            <span class="text-yellow-500 mr-2 mt-0.5">!</span>
                            Prefer direct iteration when possible
                        </li>
                        <li class="flex items-start">
                            <span class="text-yellow-500 mr-2 mt-0.5">!</span>
                            Monitor constraint complexity
                        </li>
                        <li class="flex items-start">
                            <span class="text-green-500 mr-2 mt-0.5">✓</span>
                            Array conversion still viable for processing
                        </li>
                        <li class="flex items-start">
                            <span class="text-yellow-500 mr-2 mt-0.5">!</span>
                            Consider constraint execution order
                        </li>
                    </ul>
                </div>

                <div>
                    <h3 class="font-semibold text-gray-800 mb-3">🔥 For Large Tournaments (20+ teams)</h3>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li class="flex items-start">
                            <span class="text-red-500 mr-2 mt-0.5">⚠</span>
                            Always use direct iteration
                        </li>
                        <li class="flex items-start">
                            <span class="text-red-500 mr-2 mt-0.5">⚠</span>
                            Avoid array conversion if possible
                        </li>
                        <li class="flex items-start">
                            <span class="text-red-500 mr-2 mt-0.5">⚠</span>
                            Minimize constraint count
                        </li>
                        <li class="flex items-start">
                            <span class="text-red-500 mr-2 mt-0.5">⚠</span>
                            Use count() for totals only
                        </li>
                        <li class="flex items-start">
                            <span class="text-red-500 mr-2 mt-0.5">⚠</span>
                            Consider batch processing
                        </li>
                    </ul>
                </div>

                <div>
                    <h3 class="font-semibold text-gray-800 mb-3">💡 General Best Practices</h3>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li class="flex items-start">
                            <span class="text-blue-500 mr-2 mt-0.5">💡</span>
                            Profile your specific use case
                        </li>
                        <li class="flex items-start">
                            <span class="text-blue-500 mr-2 mt-0.5">💡</span>
                            Test with realistic data sizes
                        </li>
                        <li class="flex items-start">
                            <span class="text-blue-500 mr-2 mt-0.5">💡</span>
                            Cache schedules when appropriate
                        </li>
                        <li class="flex items-start">
                            <span class="text-blue-500 mr-2 mt-0.5">💡</span>
                            Consider async processing for huge tournaments
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Code Example -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Memory-Efficient Tournament Processing</h2>
            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 overflow-x-auto">
                <pre><code><?= htmlspecialchars('<?php
// Example: Processing a large tournament efficiently

class TournamentProcessor {
    private $scheduler;
    
    public function __construct() {
        // Use minimal constraints for performance
        $constraints = ConstraintSet::create()
            ->noRepeatPairings()
            ->build();
            
        $this->scheduler = new RoundRobinScheduler($constraints);
    }
    
    public function processLargeTournament(array $participants): array {
        $schedule = $this->scheduler->schedule($participants);
        
        // Get basic stats without loading all events
        $stats = [
            \'total_events\' => count($schedule),
            \'total_rounds\' => $schedule->getMetadataValue(\'total_rounds\'),
            \'events_per_round\' => $schedule->getMetadataValue(\'events_per_round\')
        ];
        
        // Process events one by one (memory efficient)
        $roundSummaries = [];
        foreach ($schedule as $event) {
            $roundNum = $event->getRound()->getNumber();
            
            if (!isset($roundSummaries[$roundNum])) {
                $roundSummaries[$roundNum] = [
                    \'matches\' => 0,
                    \'participants\' => []
                ];
            }
            
            $roundSummaries[$roundNum][\'matches\']++;
            
            // Track participants without storing full objects
            foreach ($event->getParticipants() as $participant) {
                $roundSummaries[$roundNum][\'participants\'][] = $participant->getId();
            }
        }
        
        return [
            \'stats\' => $stats,
            \'round_summaries\' => $roundSummaries
        ];
    }
    
    public function exportMatches(array $participants, callable $exporter): void {
        $schedule = $this->scheduler->schedule($participants);
        
        // Stream events to exporter without storing in memory
        foreach ($schedule as $event) {
            $matchData = [
                \'round\' => $event->getRound()->getNumber(),
                \'team1\' => $event->getParticipants()[0]->getLabel(),
                \'team2\' => $event->getParticipants()[1]->getLabel(),
                \'timestamp\' => date(\'Y-m-d H:i:s\')
            ];
            
            // Export immediately, don\'t accumulate
            $exporter($matchData);
        }
    }
}

// Usage example
$processor = new TournamentProcessor();

// For analysis
$results = $processor->processLargeTournament($participants);

// For export (CSV, database, etc.)
$processor->exportMatches($participants, function($match) {
    // Write to file, database, API, etc.
    file_put_contents(\'matches.json\', json_encode($match) . "\n", FILE_APPEND);
});'); ?></code></pre>
            </div>
        </div>

        <!-- Memory Monitoring -->
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6">
            <h3 class="text-lg font-semibold text-yellow-800 mb-3">🔍 Memory Monitoring & Debugging</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
                <div>
                    <h4 class="font-medium text-yellow-800 mb-2">Monitoring Functions</h4>
                    <div class="bg-yellow-100 rounded p-3 text-xs font-mono">
                        <div>memory_get_usage() - Current usage</div>
                        <div>memory_get_peak_usage() - Peak usage</div>
                        <div>memory_limit_get() - Memory limit</div>
                        <div>microtime() - Execution timing</div>
                    </div>
                </div>
                <div>
                    <h4 class="font-medium text-yellow-800 mb-2">Warning Signs</h4>
                    <ul class="space-y-1 text-yellow-700">
                        <li>• Memory usage > 50MB for small tournaments</li>
                        <li>• Execution time > 1 second for < 20 teams</li>
                        <li>• Memory increasing during iteration</li>
                        <li>• PHP memory limit errors</li>
                        <li>• Slow array operations on schedules</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <div class="flex justify-between mt-8">
            <a href="11-error-handling.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition-colors">
                ← Previous: Error Handling
            </a>
            <a href="index.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg transition-colors">
                🏠 Back to Examples Home
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

    <!-- Chart.js Scripts -->
    <script>
        // Performance charts
        const performanceData = <?= json_encode($performanceResults); ?>;
        
        // Time Chart
        const timeCtx = document.getElementById('timeChart').getContext('2d');
        new Chart(timeCtx, {
            type: 'line',
            data: {
                labels: performanceData.filter(r => r.success).map(r => r.participant_count + ' teams'),
                datasets: [{
                    label: 'Execution Time (ms)',
                    data: performanceData.filter(r => r.success).map(r => r.time),
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Time (milliseconds)'
                        }
                    }
                }
            }
        });
        
        // Memory Chart
        const memoryCtx = document.getElementById('memoryChart').getContext('2d');
        new Chart(memoryCtx, {
            type: 'line',
            data: {
                labels: performanceData.filter(r => r.success).map(r => r.participant_count + ' teams'),
                datasets: [{
                    label: 'Memory Usage (MB)',
                    data: performanceData.filter(r => r.success).map(r => r.memory_used / (1024 * 1024)),
                    borderColor: 'rgb(16, 185, 129)',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Memory (MB)'
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
