<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Create participants
$participants = [
    new Participant('team1', 'Manchester City', 1),
    new Participant('team2', 'Arsenal', 2),
    new Participant('team3', 'Liverpool', 3),
    new Participant('team4', 'Chelsea', 4),
    new Participant('team5', 'Manchester United', 5),
    new Participant('team6', 'Tottenham', 6),
];

$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->build();

$scheduler = new RoundRobinScheduler($constraints);

// 1. Generate positional structure (tournament blueprint)
$structure = $scheduler->generateStructure(count($participants));

// 2. Generate complete schedule (all events at once)
$completeSchedule = $scheduler->generateSchedule($participants);

// 3. Generate rounds one at a time (round-by-round)
$round1 = $scheduler->generateRound($participants, 1);
$round2 = $scheduler->generateRound($participants, 2);
$round3 = $scheduler->generateRound($participants, 3);

// 4. Check if complete generation is supported
$supportsComplete = $scheduler->supportsCompleteGeneration();

// Analysis
$totalRounds = $structure->getRoundCount();
$totalPairings = $structure->getTotalPairingCount();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Position-Based Scheduling - Tactician Examples</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-indigo-600 text-white shadow-lg">
        <div class="max-w-6xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">🎯 Position-Based Scheduling</h1>
                    <p class="text-indigo-100">Generate tournament blueprints and round-by-round schedules</p>
                </div>
                <a href="index.php" class="bg-indigo-700 hover:bg-indigo-800 px-4 py-2 rounded-lg transition-colors">
                    ← Back to Examples
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
        <!-- Introduction -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Understanding Position-Based Scheduling</h2>
            <div class="space-y-3 text-gray-600">
                <p>
                    Tactician uses a <strong>position-based architecture</strong> that separates tournament structure
                    from participant assignment. This enables powerful features like:
                </p>
                <ul class="list-disc list-inside space-y-1 ml-4">
                    <li><strong>Tournament Blueprints</strong> - Inspect structure before participants are assigned</li>
                    <li><strong>Round-by-Round Generation</strong> - Generate one round at a time for dynamic tournaments</li>
                    <li><strong>Swiss Tournaments</strong> - Where pairings depend on standings after each round</li>
                    <li><strong>Flexible Position Types</strong> - Seed-based, standing-based, or custom positions</li>
                </ul>
            </div>
        </div>

        <!-- API Capabilities -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div class="bg-green-50 rounded-lg p-6">
                <h3 class="font-bold text-green-900 mb-2">✓ Supports Complete Generation</h3>
                <p class="text-green-800 text-sm mb-3">
                    <?= $supportsComplete ? 'Yes' : 'No'; ?> - This scheduler can generate all events upfront
                </p>
                <div class="text-xs text-green-700">
                    Round-robin schedulers know all pairings in advance and can generate the complete schedule.
                    Swiss tournaments (coming soon) require round-by-round generation.
                </div>
            </div>

            <div class="bg-blue-50 rounded-lg p-6">
                <h3 class="font-bold text-blue-900 mb-2">📊 Tournament Statistics</h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-blue-700">Total Rounds:</span>
                        <span class="font-bold text-blue-900"><?= $totalRounds; ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-blue-700">Total Pairings:</span>
                        <span class="font-bold text-blue-900"><?= $totalPairings; ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-blue-700">Participants:</span>
                        <span class="font-bold text-blue-900"><?= count($participants); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Method 1: Positional Structure -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">1. Generate Positional Structure (Blueprint)</h2>
            <p class="text-gray-600 mb-4">
                The positional structure shows <em>which positions</em> play each other, independent of actual participants.
            </p>

            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 mb-4">
                <pre><code><?= htmlspecialchars('$structure = $scheduler->generateStructure(count($participants));

// Inspect tournament blueprint
echo "Total rounds: " . $structure->getRoundCount();
echo "Total pairings: " . $structure->getTotalPairingCount();'); ?></code></pre>
            </div>

            <div class="bg-indigo-50 rounded-lg p-4">
                <h3 class="font-semibold text-indigo-900 mb-3">Tournament Blueprint (First 3 Rounds)</h3>
                <div class="space-y-2">
                    <?php
                    $roundsToShow = array_slice($structure->getRounds(), 0, 3);
                    foreach ($roundsToShow as $round):
                        $pairings = $round->getPairings();
                    ?>
                        <div class="bg-white rounded p-3">
                            <div class="font-medium text-indigo-800 mb-2">Round <?= $round->getRoundNumber(); ?></div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-sm">
                                <?php foreach ($pairings as $pairing): ?>
                                    <div class="flex items-center justify-between bg-indigo-50 rounded px-2 py-1">
                                        <span class="text-indigo-600"><?= htmlspecialchars((string) $pairing->getPosition1()); ?></span>
                                        <span class="text-gray-400 text-xs">vs</span>
                                        <span class="text-indigo-600"><?= htmlspecialchars((string) $pairing->getPosition2()); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Method 2: Complete Schedule -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">2. Generate Complete Schedule (All At Once)</h2>
            <p class="text-gray-600 mb-4">
                Generate all events with participants assigned. This works for round-robin and seeded Swiss.
            </p>

            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 mb-4">
                <pre><code><?= htmlspecialchars('$schedule = $scheduler->generateSchedule($participants);

// All events are generated and ready
foreach ($schedule as $event) {
    $round = $event->getRound();
    $participants = $event->getParticipants();
    // Process event...
}'); ?></code></pre>
            </div>

            <div class="bg-green-50 rounded-lg p-4">
                <h3 class="font-semibold text-green-900 mb-3">Complete Schedule (First 6 Matches)</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <?php
                    $count = 0;
                    foreach ($completeSchedule as $event):
                        if (++$count > 6) break;
                        $eventParticipants = $event->getParticipants();
                        $round = $event->getRound();
                    ?>
                        <div class="bg-white rounded p-3 flex items-center justify-between">
                            <span class="text-xs text-gray-500">R<?= $round->getNumber(); ?></span>
                            <div class="flex items-center gap-2 text-sm">
                                <span class="text-green-700"><?= htmlspecialchars($eventParticipants[0]->getLabel()); ?></span>
                                <span class="text-gray-400">vs</span>
                                <span class="text-green-700"><?= htmlspecialchars($eventParticipants[1]->getLabel()); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Method 3: Round-by-Round -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">3. Generate Round-by-Round (Dynamic)</h2>
            <p class="text-gray-600 mb-4">
                Generate one round at a time. Essential for Swiss tournaments where pairings depend on standings.
            </p>

            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 mb-4">
                <pre><code><?= htmlspecialchars('// Generate specific rounds
$round1 = $scheduler->generateRound($participants, 1);
$round2 = $scheduler->generateRound($participants, 2);
$round3 = $scheduler->generateRound($participants, 3);

// For standings-based Swiss (coming soon):
// $round2 = $scheduler->generateRound($participants, 2, $standingsResolver);'); ?></code></pre>
            </div>

            <div class="bg-purple-50 rounded-lg p-4">
                <h3 class="font-semibold text-purple-900 mb-3">Round-by-Round Generation</h3>
                <div class="space-y-3">
                    <?php foreach ([$round1, $round2, $round3] as $round): ?>
                        <div class="bg-white rounded p-3">
                            <div class="font-medium text-purple-800 mb-2">
                                Round <?= $round->getRoundNumber(); ?>
                                <span class="text-xs text-purple-600 ml-2">(<?= $round->getEventCount(); ?> matches)</span>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-sm">
                                <?php foreach ($round->getEvents() as $event): ?>
                                    <?php $eventParticipants = $event->getParticipants(); ?>
                                    <div class="flex items-center justify-between bg-purple-50 rounded px-2 py-1">
                                        <span class="text-purple-700"><?= htmlspecialchars($eventParticipants[0]->getLabel()); ?></span>
                                        <span class="text-gray-400 text-xs">vs</span>
                                        <span class="text-purple-700"><?= htmlspecialchars($eventParticipants[1]->getLabel()); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Comparison Table -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Method Comparison</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left py-3 px-4 font-semibold text-gray-800">Method</th>
                            <th class="text-left py-3 px-4 font-semibold text-gray-800">Use Case</th>
                            <th class="text-left py-3 px-4 font-semibold text-gray-800">Returns</th>
                            <th class="text-left py-3 px-4 font-semibold text-gray-800">When to Use</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="py-3 px-4 font-medium text-indigo-600">generateStructure()</td>
                            <td class="py-3 px-4 text-gray-600">Inspect tournament blueprint</td>
                            <td class="py-3 px-4 text-gray-600">PositionalSchedule</td>
                            <td class="py-3 px-4 text-gray-600">Before tournament starts, for validation</td>
                        </tr>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="py-3 px-4 font-medium text-green-600">generateSchedule()</td>
                            <td class="py-3 px-4 text-gray-600">Generate all events upfront</td>
                            <td class="py-3 px-4 text-gray-600">Schedule</td>
                            <td class="py-3 px-4 text-gray-600">Round-robin, seeded Swiss</td>
                        </tr>
                        <tr class="hover:bg-gray-50">
                            <td class="py-3 px-4 font-medium text-purple-600">generateRound()</td>
                            <td class="py-3 px-4 text-gray-600">Generate one round at a time</td>
                            <td class="py-3 px-4 text-gray-600">RoundSchedule</td>
                            <td class="py-3 px-4 text-gray-600">Standings-based Swiss, dynamic tournaments</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Key Insights -->
        <div class="bg-yellow-50 rounded-lg p-6 mb-8">
            <h3 class="font-semibold text-yellow-900 mb-3">💡 Key Insights</h3>
            <ul class="space-y-2 text-yellow-800">
                <li class="flex items-start">
                    <span class="mr-2">→</span>
                    <span><strong>Position-based architecture</strong> separates "what matches happen" from "who plays"</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-2">→</span>
                    <span><strong>generateStructure()</strong> shows the tournament blueprint before participants are assigned</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-2">→</span>
                    <span><strong>generateSchedule()</strong> is perfect for round-robin where all pairings are known upfront</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-2">→</span>
                    <span><strong>generateRound()</strong> enables Swiss tournaments where pairings depend on standings</span>
                </li>
                <li class="flex items-start">
                    <span class="mr-2">→</span>
                    <span><strong>supportsCompleteGeneration()</strong> tells you which methods will work for a given scheduler</span>
                </li>
            </ul>
        </div>

        <!-- Coming Soon -->
        <div class="bg-blue-50 rounded-lg p-6">
            <h3 class="font-semibold text-blue-900 mb-3">🚀 Coming Soon: Swiss Tournaments</h3>
            <p class="text-blue-800 mb-3">
                The position-based architecture was designed with Swiss tournaments in mind. Soon you'll be able to:
            </p>
            <div class="bg-white rounded p-4 text-sm text-blue-800">
                <pre><code><?= htmlspecialchars('// Swiss tournament with standings-based pairing
$swissScheduler = new SwissScheduler($constraints);

// Round 1: Use seed-based pairing
$round1 = $swissScheduler->generateRound($participants, 1);

// After round 1, update standings
$standings = calculateStandings($participants, $results);
$standingsResolver = new StandingsBasedPositionResolver($standings);

// Round 2: Use standings-based pairing
$round2 = $swissScheduler->generateRound($participants, 2, $standingsResolver);'); ?></code></pre>
            </div>
        </div>

        <!-- Navigation -->
        <div class="flex justify-between mt-8">
            <a href="13-participant-ordering.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition-colors">
                ← Participant Ordering
            </a>
            <a href="index.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg transition-colors">
                Back to Examples →
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
