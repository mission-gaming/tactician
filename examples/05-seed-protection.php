<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;

// Create participants with different seeding levels
$participants = [
    new Participant('celtic', 'Celtic FC', 1, ['tier' => 'top']),
    new Participant('athletic', 'Athletic Bilbao', 2, ['tier' => 'top']),
    new Participant('livorno', 'AS Livorno', 3, ['tier' => 'mid']),
    new Participant('redstar', 'Red Star Belgrade', 4, ['tier' => 'mid']),
    new Participant('stpauli', 'FC St. Pauli', 5, ['tier' => 'mid']),
    new Participant('clapton', 'Clapton Community FC', 6, ['tier' => 'lower']),
    new Participant('rayo', 'Rayo Vallecano', 7, ['tier' => 'lower']),
    new Participant('union', 'Union Berlin', 8, ['tier' => 'lower']),
];

// Create schedules with different seed protection levels
$schedulesToCompare = [];

// Schedule without seed protection
$constraints1 = ConstraintSet::create()
    ->noRepeatPairings()
    ->build();
$scheduler1 = new RoundRobinScheduler($constraints1);
$schedulesToCompare['No Protection'] = $scheduler1->schedule($participants);

// Schedule with 25% seed protection
$constraints2 = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(new SeedProtectionConstraint(2, 0.25))
    ->build();
$scheduler2 = new RoundRobinScheduler($constraints2);
$schedulesToCompare['25% Protection'] = $scheduler2->schedule($participants);

// Schedule with 50% seed protection
$constraints3 = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(new SeedProtectionConstraint(2, 0.5))
    ->build();
$scheduler3 = new RoundRobinScheduler($constraints3);
$schedulesToCompare['50% Protection'] = $scheduler3->schedule($participants);

// Function to check if a match involves protected seeds
function isProtectedSeedMatch($event, $protectedSeedCount = 2) {
    $participants = $event->getParticipants();
    return ($participants[0]->getSeed() <= $protectedSeedCount || 
            $participants[1]->getSeed() <= $protectedSeedCount);
}

// Function to check if a match is between top seeds
function isTopSeedClash($event, $protectedSeedCount = 2) {
    $participants = $event->getParticipants();
    return ($participants[0]->getSeed() <= $protectedSeedCount && 
            $participants[1]->getSeed() <= $protectedSeedCount);
}

// Calculate statistics for each schedule
$stats = [];
foreach ($schedulesToCompare as $name => $schedule) {
    $totalRounds = $schedule->getMetadataValue('total_rounds');
    $protectedPeriod = (int) ceil($totalRounds * 0.5); // 50% protection period
    $earlyRounds = [];
    $lateRounds = [];
    $topSeedClashes = [];
    
    foreach ($schedule as $event) {
        $roundNumber = $event->getRound()->getNumber();
        if ($roundNumber <= $protectedPeriod) {
            $earlyRounds[] = $event;
        } else {
            $lateRounds[] = $event;
        }
        
        if (isTopSeedClash($event)) {
            $topSeedClashes[] = $event;
        }
    }
    
    $stats[$name] = [
        'schedule' => $schedule,
        'total_rounds' => $totalRounds,
        'protected_period' => $protectedPeriod,
        'early_rounds' => $earlyRounds,
        'late_rounds' => $lateRounds,
        'top_seed_clashes' => $topSeedClashes,
        'early_clashes' => array_filter($earlyRounds, fn($e) => isTopSeedClash($e)),
        'late_clashes' => array_filter($lateRounds, fn($e) => isTopSeedClash($e))
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seed Protection - Tactician Examples</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-6xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">🛡️ Seed Protection</h1>
                    <p class="text-blue-100">Protecting high-seeded participants from early meetings</p>
                </div>
                <a href="index.php" class="bg-blue-700 hover:bg-blue-800 px-4 py-2 rounded-lg transition-colors">
                    ← Back to Examples
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
        <!-- Concept Explanation -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">What is Seed Protection?</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <p class="text-gray-600 mb-4 leading-relaxed">
                        Seed protection prevents high-seeded (top-ranked) participants from meeting each other 
                        early in a tournament. This ensures that the best competitors have a chance to meet 
                        in the later stages, creating more exciting climactic matches.
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        The <code class="bg-gray-100 px-2 py-1 rounded text-sm">SeedProtectionConstraint</code> 
                        takes two parameters: the number of protected seeds and the protection period 
                        (as a percentage of the tournament).
                    </p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-3">Example Configuration</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Protected Seeds:</span>
                            <span class="font-medium">Top 2 (Seeds #1 & #2)</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Protection Period:</span>
                            <span class="font-medium">50% of tournament</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Total Rounds:</span>
                            <span class="font-medium"><?= $stats['50% Protection']['total_rounds'] ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Protected Rounds:</span>
                            <span class="font-medium">1 - <?= $stats['50% Protection']['protected_period'] ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Participants Overview -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Tournament Participants</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php foreach ($participants as $participant): ?>
                    <?php 
                    $seedClass = '';
                    $tierLabel = '';
                    if ($participant->getSeed() <= 2) {
                        $seedClass = 'bg-red-100 border-red-300 text-red-800';
                        $tierLabel = 'Protected Seed';
                    } elseif ($participant->getSeed() <= 4) {
                        $seedClass = 'bg-yellow-100 border-yellow-300 text-yellow-800';
                        $tierLabel = 'High Seed';
                    } else {
                        $seedClass = 'bg-gray-100 border-gray-300 text-gray-800';
                        $tierLabel = 'Lower Seed';
                    }
                    ?>
                    <div class="border-2 rounded-lg p-4 <?= $seedClass ?>">
                        <div class="flex items-center justify-between mb-2">
                            <div class="font-semibold"><?= htmlspecialchars($participant->getLabel()) ?></div>
                            <div class="text-sm font-bold">#{<?= $participant->getSeed() ?>}</div>
                        </div>
                        <div class="text-xs"><?= $tierLabel ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Comparison of Protection Levels -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Protection Level Comparison</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <?php foreach ($stats as $name => $data): ?>
                    <div class="border border-gray-200 rounded-lg p-4">
                        <h3 class="font-semibold text-gray-800 mb-3"><?= htmlspecialchars($name) ?></h3>
                        
                        <div class="space-y-3">
                            <div class="bg-red-50 rounded p-3">
                                <div class="text-lg font-bold text-red-600"><?= count($data['early_clashes']) ?></div>
                                <div class="text-sm text-red-700">Early Top Seed Clashes</div>
                                <div class="text-xs text-gray-500">Rounds 1-<?= $data['protected_period'] ?></div>
                            </div>
                            
                            <div class="bg-green-50 rounded p-3">
                                <div class="text-lg font-bold text-green-600"><?= count($data['late_clashes']) ?></div>
                                <div class="text-sm text-green-700">Late Top Seed Clashes</div>
                                <div class="text-xs text-gray-500">Rounds <?= $data['protected_period'] + 1 ?>-<?= $data['total_rounds'] ?></div>
                            </div>
                            
                            <div class="bg-blue-50 rounded p-3">
                                <div class="text-lg font-bold text-blue-600"><?= count($data['top_seed_clashes']) ?></div>
                                <div class="text-sm text-blue-700">Total Top Seed Clashes</div>
                                <div class="text-xs text-gray-500">Throughout tournament</div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Sample Schedule with Visual Indicators -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Schedule with 50% Protection (Sample)</h2>
            <p class="text-gray-600 mb-4">
                This schedule shows the first 8 matches with visual indicators for seed protection.
                Protected matches are highlighted to show how top seeds are kept apart early.
            </p>
            
            <?php
            $sampleSchedule = $stats['50% Protection']['schedule'];
            $sampleCount = 0;
            $eventsByRound = [];
            foreach ($sampleSchedule as $event) {
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
                            <?php if ($roundNumber <= $stats['50% Protection']['protected_period']): ?>
                                <span class="bg-green-100 text-green-800 px-2 py-1 rounded-full text-xs">
                                    Protected Period
                                </span>
                            <?php else: ?>
                                <span class="bg-gray-100 text-gray-800 px-2 py-1 rounded-full text-xs">
                                    Open Period
                                </span>
                            <?php endif; ?>
                        </h3>
                        
                        <div class="space-y-2">
                            <?php foreach ($roundEvents as $event): ?>
                                <?php 
                                $eventParticipants = $event->getParticipants();
                                $isTopClash = isTopSeedClash($event);
                                $hasProtectedSeed = isProtectedSeedMatch($event);
                                
                                $cardClass = 'border border-gray-100 rounded-lg p-3';
                                $indicator = '';
                                
                                if ($isTopClash && $roundNumber <= $stats['50% Protection']['protected_period']) {
                                    $cardClass = 'border-2 border-red-300 bg-red-50 rounded-lg p-3';
                                    $indicator = '<span class="bg-red-500 text-white px-2 py-1 rounded text-xs ml-2">⚠️ Protected Clash</span>';
                                } elseif ($isTopClash) {
                                    $cardClass = 'border-2 border-orange-300 bg-orange-50 rounded-lg p-3';
                                    $indicator = '<span class="bg-orange-500 text-white px-2 py-1 rounded text-xs ml-2">🔥 Top Seed Clash</span>';
                                } elseif ($hasProtectedSeed) {
                                    $cardClass = 'border border-blue-200 bg-blue-50 rounded-lg p-3';
                                    $indicator = '<span class="bg-blue-500 text-white px-2 py-1 rounded text-xs ml-2">🛡️ Protected Seed</span>';
                                }
                                ?>
                                
                                <div class="<?= $cardClass ?>">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-4">
                                            <div class="text-center">
                                                <div class="font-medium text-gray-800"><?= htmlspecialchars($eventParticipants[0]->getLabel()) ?></div>
                                                <div class="text-xs text-gray-500">
                                                    Seed #<?= $eventParticipants[0]->getSeed() ?>
                                                    <?php if ($eventParticipants[0]->getSeed() <= 2): ?>
                                                        <span class="text-red-600 font-bold">★</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="text-gray-400 font-bold">VS</div>
                                            <div class="text-center">
                                                <div class="font-medium text-gray-800"><?= htmlspecialchars($eventParticipants[1]->getLabel()) ?></div>
                                                <div class="text-xs text-gray-500">
                                                    Seed #<?= $eventParticipants[1]->getSeed() ?>
                                                    <?php if ($eventParticipants[1]->getSeed() <= 2): ?>
                                                        <span class="text-red-600 font-bold">★</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <?= $indicator ?>
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
use MissionGaming\\Tactician\\Constraints\\ConstraintSet;
use MissionGaming\\Tactician\\Constraints\\SeedProtectionConstraint;
use MissionGaming\\Tactician\\Scheduling\\RoundRobinScheduler;

// Protect top 2 seeds for 50% of the tournament
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(new SeedProtectionConstraint(
        protectedSeedCount: 2,    // Protect seeds #1 and #2
        protectionPeriod: 0.5     // For 50% of tournament
    ))
    ->build();

$scheduler = new RoundRobinScheduler($constraints);
$schedule = $scheduler->schedule($participants);

// The constraint ensures that seed #1 and seed #2 
// won\'t meet until the second half of the tournament') ?></code></pre>
            </div>
        </div>

        <!-- Navigation -->
        <div class="flex justify-between mt-8">
            <a href="04-basic-constraints.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition-colors">
                ← Previous: Basic Constraints
            </a>
            <a href="06-rest-periods.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg transition-colors">
                Next: Rest Periods →
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
