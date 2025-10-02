<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;

// Create teams for a mini league
$teams = [
    new Participant('mci', 'Manchester City', 1, ['city' => 'Manchester', 'country' => 'England']),
    new Participant('ars', 'Arsenal', 2, ['city' => 'London', 'country' => 'England']),
    new Participant('liv', 'Liverpool', 3, ['city' => 'Liverpool', 'country' => 'England']),
    new Participant('che', 'Chelsea', 4, ['city' => 'London', 'country' => 'England']),
    new Participant('mun', 'Manchester United', 5, ['city' => 'Manchester', 'country' => 'England']),
    new Participant('tot', 'Tottenham', 6, ['city' => 'London', 'country' => 'England']),
];

// Create different multi-leg schedules
$schedulesToCompare = [];

// Premier League style: Home and Away (Mirrored)
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(new SeedProtectionConstraint(2, 0.3))
    ->build();

$scheduler = new RoundRobinScheduler($constraints);

$schedulesToCompare['Home & Away (Mirrored)'] = [
    'schedule' => $scheduler->schedule($teams, 2, 2, new MirroredLegStrategy()),
    'strategy' => 'MirroredLegStrategy',
    'description' => 'Each team plays every other team twice - once at home, once away. Second leg reverses the fixture order.'
];

$schedulesToCompare['Repeated Encounters'] = [
    'schedule' => $scheduler->schedule($teams, 2, 2, new RepeatedLegStrategy()),
    'strategy' => 'RepeatedLegStrategy', 
    'description' => 'Each team plays every other team twice with identical fixture order in both legs.'
];

$schedulesToCompare['Shuffled Legs'] = [
    'schedule' => $scheduler->schedule($teams, 2, 2, new ShuffledLegStrategy()),
    'strategy' => 'ShuffledLegStrategy',
    'description' => 'Each team plays every other team twice with randomized fixture order in each leg.'
];

// Function to determine home/away for mirrored strategy
function getHomeAway($event, $schedule, $strategy) {
    if ($strategy !== 'MirroredLegStrategy') {
        return ['', ''];
    }
    
    $roundsPerLeg = $schedule->getMetadataValue('rounds_per_leg');
    $roundNumber = $event->getRound()->getNumber();
    $participants = $event->getParticipants();
    
    if ($roundNumber <= $roundsPerLeg) {
        // First leg
        return ['🏠 ' . $participants[0]->getLabel(), '✈️ ' . $participants[1]->getLabel()];
    } else {
        // Second leg (reversed)
        return ['🏠 ' . $participants[1]->getLabel(), '✈️ ' . $participants[0]->getLabel()];
    }
}

// Calculate statistics
$stats = [];
foreach ($schedulesToCompare as $name => $data) {
    $schedule = $data['schedule'];
    $totalEvents = count($schedule);
    $totalRounds = $schedule->getMetadataValue('total_rounds');
    $legs = $schedule->getMetadataValue('legs');
    $roundsPerLeg = $schedule->getMetadataValue('rounds_per_leg');
    
    // Count events per leg
    $legCounts = [];
    foreach ($schedule as $event) {
        $roundNumber = $event->getRound()->getNumber();
        $leg = (int) ceil($roundNumber / $roundsPerLeg);
        $legCounts[$leg] = ($legCounts[$leg] ?? 0) + 1;
    }
    
    $stats[$name] = [
        'total_events' => $totalEvents,
        'total_rounds' => $totalRounds,
        'legs' => $legs,
        'rounds_per_leg' => $roundsPerLeg,
        'leg_counts' => $legCounts,
        'strategy' => $data['strategy'],
        'description' => $data['description']
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multi-Leg Home/Away - Tactician Examples</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-6xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">🏠 Multi-Leg Home/Away</h1>
                    <p class="text-blue-100">Premier League style home and away seasons</p>
                </div>
                <a href="index.php" class="bg-blue-700 hover:bg-blue-800 px-4 py-2 rounded-lg transition-colors">
                    ← Back to Examples
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
        <!-- Multi-Leg Concept Explanation -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Multi-Leg Tournaments</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <p class="text-gray-600 mb-4 leading-relaxed">
                        Multi-leg tournaments allow the same participants to play multiple times with different arrangements. 
                        This is perfect for home/away leagues, repeated encounters, or tournaments with multiple phases.
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        Tactician provides three leg strategies: <strong>Mirrored</strong> (home/away), 
                        <strong>Repeated</strong> (identical), and <strong>Shuffled</strong> (randomized).
                    </p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-3">Tournament Configuration</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Teams:</span>
                            <span class="font-medium"><?= count($teams) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Legs:</span>
                            <span class="font-medium">2 (Home & Away)</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Total Matches:</span>
                            <span class="font-medium"><?= $stats['Home & Away (Mirrored)']['total_events'] ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Rounds per Leg:</span>
                            <span class="font-medium"><?= $stats['Home & Away (Mirrored)']['rounds_per_leg'] ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Teams Overview -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">League Teams</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($teams as $team): ?>
                    <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <h3 class="font-semibold text-gray-800"><?= htmlspecialchars($team->getLabel()) ?></h3>
                                <p class="text-sm text-gray-500"><?= htmlspecialchars($team->getMetadataValue('city')) ?></p>
                            </div>
                            <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs font-semibold">
                                Seed #<?= $team->getSeed() ?>
                            </span>
                        </div>
                        <div class="text-xs text-gray-400">
                            ID: <?= htmlspecialchars($team->getId()) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Strategy Comparison -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Leg Strategy Comparison</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <?php foreach ($stats as $name => $data): ?>
                    <div class="border border-gray-200 rounded-lg p-4">
                        <h3 class="font-semibold text-gray-800 mb-2"><?= htmlspecialchars($name) ?></h3>
                        <p class="text-sm text-gray-600 mb-4"><?= htmlspecialchars($data['description']) ?></p>
                        
                        <div class="space-y-3">
                            <div class="bg-blue-50 rounded p-3">
                                <div class="text-lg font-bold text-blue-600"><?= $data['total_events'] ?></div>
                                <div class="text-sm text-blue-700">Total Matches</div>
                            </div>
                            
                            <div class="bg-green-50 rounded p-3">
                                <div class="text-lg font-bold text-green-600"><?= $data['legs'] ?></div>
                                <div class="text-sm text-green-700">Legs</div>
                            </div>
                            
                            <div class="bg-purple-50 rounded p-3">
                                <div class="text-lg font-bold text-purple-600"><?= $data['rounds_per_leg'] ?></div>
                                <div class="text-sm text-purple-700">Rounds per Leg</div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Home & Away Schedule Detail -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Home & Away Schedule (First 8 Matches)</h2>
            <p class="text-gray-600 mb-4">
                In a mirrored leg strategy, the second leg reverses all home/away assignments. 
                🏠 indicates home team, ✈️ indicates away team.
            </p>
            
            <?php
            $homeAwaySchedule = $schedulesToCompare['Home & Away (Mirrored)']['schedule'];
            $strategy = $schedulesToCompare['Home & Away (Mirrored)']['strategy'];
            $roundsPerLeg = $homeAwaySchedule->getMetadataValue('rounds_per_leg');
            
            $sampleCount = 0;
            $eventsByRound = [];
            foreach ($homeAwaySchedule as $event) {
                $roundNumber = $event->getRound()->getNumber();
                if (!isset($eventsByRound[$roundNumber])) {
                    $eventsByRound[$roundNumber] = [];
                }
                $eventsByRound[$roundNumber][] = $event;
            }
            ?>
            
            <div class="space-y-4">
                <?php foreach (array_slice($eventsByRound, 0, 4, true) as $roundNumber => $roundEvents): ?>
                    <div class="border border-gray-200 rounded-lg p-4">
                        <h3 class="text-lg font-semibold text-gray-800 mb-3 flex items-center">
                            <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-sm mr-3">
                                Round <?= $roundNumber ?>
                            </span>
                            <?php if ($roundNumber <= $roundsPerLeg): ?>
                                <span class="bg-green-100 text-green-800 px-2 py-1 rounded-full text-xs">
                                    First Leg
                                </span>
                            <?php else: ?>
                                <span class="bg-orange-100 text-orange-800 px-2 py-1 rounded-full text-xs">
                                    Second Leg (Return)
                                </span>
                            <?php endif; ?>
                        </h3>
                        
                        <div class="space-y-2">
                            <?php foreach ($roundEvents as $event): ?>
                                <?php 
                                $homeAway = getHomeAway($event, $homeAwaySchedule, $strategy);
                                $participants = $event->getParticipants();
                                ?>
                                <div class="border border-gray-100 rounded-lg p-3 hover:bg-gray-50 transition-colors">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-6">
                                            <div class="text-center min-w-0 flex-1">
                                                <div class="font-medium text-gray-800 truncate">
                                                    <?= $homeAway[0] ?: htmlspecialchars($participants[0]->getLabel()) ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    Seed #<?= $participants[0]->getSeed() ?>
                                                </div>
                                            </div>
                                            <div class="text-gray-400 font-bold">VS</div>
                                            <div class="text-center min-w-0 flex-1">
                                                <div class="font-medium text-gray-800 truncate">
                                                    <?= $homeAway[1] ?: htmlspecialchars($participants[1]->getLabel()) ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    Seed #<?= $participants[1]->getSeed() ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <?php if ($roundNumber > $roundsPerLeg): ?>
                                            <span class="bg-orange-100 text-orange-800 px-2 py-1 rounded text-xs">
                                                Return Fixture
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- League Table Simulation -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Simulated League Table</h2>
            <p class="text-gray-600 mb-4">
                This shows how a league table might look with random results applied to the home & away fixtures.
            </p>
            
            <?php
            // Simulate league table with random results
            $leagueTable = [];
            foreach ($teams as $team) {
                $leagueTable[$team->getId()] = [
                    'team' => $team,
                    'played' => 0,
                    'won' => 0,
                    'drawn' => 0,
                    'lost' => 0,
                    'goals_for' => 0,
                    'goals_against' => 0,
                    'points' => 0
                ];
            }
            
            // Simulate results for each match
            foreach ($homeAwaySchedule as $event) {
                $participants = $event->getParticipants();
                $team1 = $participants[0]->getId();
                $team2 = $participants[1]->getId();
                
                // Random result (weighted by seed)
                $seed1 = $participants[0]->getSeed();
                $seed2 = $participants[1]->getSeed();
                $team1Strength = 10 - $seed1; // Higher seed = lower number = higher strength
                $team2Strength = 10 - $seed2;
                
                $goals1 = rand(0, 4);
                $goals2 = rand(0, 4);
                
                // Adjust goals based on strength
                if ($team1Strength > $team2Strength) {
                    $goals1 += rand(0, 1);
                } elseif ($team2Strength > $team1Strength) {
                    $goals2 += rand(0, 1);
                }
                
                // Update table
                $leagueTable[$team1]['played']++;
                $leagueTable[$team2]['played']++;
                $leagueTable[$team1]['goals_for'] += $goals1;
                $leagueTable[$team1]['goals_against'] += $goals2;
                $leagueTable[$team2]['goals_for'] += $goals2;
                $leagueTable[$team2]['goals_against'] += $goals1;
                
                if ($goals1 > $goals2) {
                    $leagueTable[$team1]['won']++;
                    $leagueTable[$team1]['points'] += 3;
                    $leagueTable[$team2]['lost']++;
                } elseif ($goals2 > $goals1) {
                    $leagueTable[$team2]['won']++;
                    $leagueTable[$team2]['points'] += 3;
                    $leagueTable[$team1]['lost']++;
                } else {
                    $leagueTable[$team1]['drawn']++;
                    $leagueTable[$team1]['points'] += 1;
                    $leagueTable[$team2]['drawn']++;
                    $leagueTable[$team2]['points'] += 1;
                }
            }
            
            // Sort by points, then goal difference
            uasort($leagueTable, function($a, $b) {
                $pointsDiff = $b['points'] - $a['points'];
                if ($pointsDiff !== 0) return $pointsDiff;
                return ($b['goals_for'] - $b['goals_against']) - ($a['goals_for'] - $a['goals_against']);
            });
            ?>
            
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="text-left p-2">Pos</th>
                            <th class="text-left p-2">Team</th>
                            <th class="text-center p-2">P</th>
                            <th class="text-center p-2">W</th>
                            <th class="text-center p-2">D</th>
                            <th class="text-center p-2">L</th>
                            <th class="text-center p-2">GF</th>
                            <th class="text-center p-2">GA</th>
                            <th class="text-center p-2">GD</th>
                            <th class="text-center p-2">Pts</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $position = 1; ?>
                        <?php foreach ($leagueTable as $row): ?>
                            <?php 
                            $goalDiff = $row['goals_for'] - $row['goals_against'];
                            $rowClass = '';
                            if ($position <= 2) {
                                $rowClass = 'bg-green-50 border-l-4 border-green-500';
                            } elseif ($position <= 4) {
                                $rowClass = 'bg-blue-50 border-l-4 border-blue-500';
                            } elseif ($position > count($leagueTable) - 2) {
                                $rowClass = 'bg-red-50 border-l-4 border-red-500';
                            }
                            ?>
                            <tr class="<?= $rowClass ?> hover:bg-gray-50">
                                <td class="p-2 font-medium"><?= $position ?></td>
                                <td class="p-2">
                                    <div class="font-medium"><?= htmlspecialchars($row['team']->getLabel()) ?></div>
                                    <div class="text-xs text-gray-500">Seed #<?= $row['team']->getSeed() ?></div>
                                </td>
                                <td class="text-center p-2"><?= $row['played'] ?></td>
                                <td class="text-center p-2"><?= $row['won'] ?></td>
                                <td class="text-center p-2"><?= $row['drawn'] ?></td>
                                <td class="text-center p-2"><?= $row['lost'] ?></td>
                                <td class="text-center p-2"><?= $row['goals_for'] ?></td>
                                <td class="text-center p-2"><?= $row['goals_against'] ?></td>
                                <td class="text-center p-2 <?= $goalDiff > 0 ? 'text-green-600' : ($goalDiff < 0 ? 'text-red-600' : '') ?>">
                                    <?= $goalDiff > 0 ? '+' : '' ?><?= $goalDiff ?>
                                </td>
                                <td class="text-center p-2 font-bold"><?= $row['points'] ?></td>
                            </tr>
                            <?php $position++; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="mt-4 text-xs text-gray-500">
                <div class="flex flex-wrap gap-4">
                    <div class="flex items-center">
                        <div class="w-3 h-3 bg-green-500 rounded mr-1"></div>
                        Champions League
                    </div>
                    <div class="flex items-center">
                        <div class="w-3 h-3 bg-blue-500 rounded mr-1"></div>
                        Europa League
                    </div>
                    <div class="flex items-center">
                        <div class="w-3 h-3 bg-red-500 rounded mr-1"></div>
                        Relegation
                    </div>
                </div>
            </div>
        </div>

        <!-- Code Example -->
        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Code Example</h2>
            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 overflow-x-auto">
                <pre><code><?= htmlspecialchars('<?php
use MissionGaming\\Tactician\\Scheduling\\RoundRobinScheduler;
use MissionGaming\\Tactician\\LegStrategies\\MirroredLegStrategy;
use MissionGaming\\Tactician\\Constraints\\ConstraintSet;

// Create home & away league
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->build();

$scheduler = new RoundRobinScheduler($constraints);

// Generate 2-leg tournament with mirrored fixtures
$schedule = $scheduler->schedule(
    participants: $teams,
    participantsPerEvent: 2,
    legs: 2,
    strategy: new MirroredLegStrategy()
);

// Access multi-leg metadata
echo "Total legs: " . $schedule->getMetadataValue(\'legs\');
echo "Rounds per leg: " . $schedule->getMetadataValue(\'rounds_per_leg\');
echo "Total rounds: " . $schedule->getMetadataValue(\'total_rounds\');

// Calculate which leg each round belongs to
$roundsPerLeg = $schedule->getMetadataValue(\'rounds_per_leg\');
foreach ($schedule as $event) {
    $roundNumber = $event->getRound()->getNumber();
    $leg = (int) ceil($roundNumber / $roundsPerLeg);
    echo "Round {$roundNumber} is in leg {$leg}";
}') ?></code></pre>
            </div>
        </div>

        <!-- Navigation -->
        <div class="flex justify-between mt-8">
            <a href="08-custom-constraints.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition-colors">
                ← Previous: Custom Constraints
            </a>
            <a href="10-complex-tournament.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg transition-colors">
                Next: Complex Tournament →
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
