<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Constraints\ConstraintSet;

// Create participants with seeding and metadata
$participants = [
    new Participant('celtic', 'Celtic FC', 1, [
        'city' => 'Glasgow',
        'country' => 'Scotland',
        'division' => 'Premier',
        'founded' => 1887,
        'stadium' => 'Celtic Park',
        'capacity' => 60411
    ]),
    new Participant('athletic', 'Athletic Bilbao', 2, [
        'city' => 'Bilbao',
        'country' => 'Spain',
        'division' => 'La Liga',
        'founded' => 1898,
        'stadium' => 'San Mamés',
        'capacity' => 53289
    ]),
    new Participant('livorno', 'AS Livorno', 3, [
        'city' => 'Livorno',
        'country' => 'Italy',
        'division' => 'Serie C',
        'founded' => 1915,
        'stadium' => 'Armando Picchi',
        'capacity' => 19238
    ]),
    new Participant('redstar', 'Red Star Belgrade', 4, [
        'city' => 'Belgrade',
        'country' => 'Serbia',
        'division' => 'SuperLiga',
        'founded' => 1945,
        'stadium' => 'Rajko Mitić Stadium',
        'capacity' => 51755
    ]),
    new Participant('stpauli', 'FC St. Pauli', 5, [
        'city' => 'Hamburg',
        'country' => 'Germany',
        'division' => '2. Bundesliga',
        'founded' => 1910,
        'stadium' => 'Millerntor-Stadion',
        'capacity' => 29546
    ]),
    new Participant('clapton', 'Clapton Community FC', 6, [
        'city' => 'London',
        'country' => 'England',
        'division' => 'Essex Senior League',
        'founded' => 2018,
        'stadium' => 'Old Spotted Dog Ground',
        'capacity' => 3500
    ]),
];

// Create schedule
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->build();

$scheduler = new RoundRobinScheduler($constraints);
$schedule = $scheduler->schedule($participants);

// Calculate statistics
$totalEvents = count($schedule);
$totalRounds = $schedule->getMetadataValue('total_rounds');
$participantCount = $schedule->getMetadataValue('participant_count');

// Group participants by country for display
$participantsByCountry = [];
foreach ($participants as $participant) {
    $country = $participant->getMetadataValue('country');
    $participantsByCountry[$country][] = $participant;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Participants & Metadata - Tactician Examples</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-6xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">👥 Participants & Metadata</h1>
                    <p class="text-blue-100">Working with seeded participants and custom data</p>
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
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="bg-blue-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-blue-600"><?= $participantCount ?></div>
                    <div class="text-gray-600">Teams</div>
                </div>
                <div class="bg-green-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-green-600"><?= $totalEvents ?></div>
                    <div class="text-gray-600">Matches</div>
                </div>
                <div class="bg-purple-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-purple-600"><?= $totalRounds ?></div>
                    <div class="text-gray-600">Rounds</div>
                </div>
                <div class="bg-orange-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-orange-600"><?= count($participantsByCountry) ?></div>
                    <div class="text-gray-600">Countries</div>
                </div>
            </div>
        </div>

        <!-- Teams by Country -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Teams by Country</h2>
            <div class="space-y-6">
                <?php foreach ($participantsByCountry as $country => $countryTeams): ?>
                    <div class="border border-gray-200 rounded-lg p-4">
                        <h3 class="text-lg font-semibold text-gray-800 mb-3 flex items-center">
                            <span class="bg-gray-100 text-gray-800 px-2 py-1 rounded-full text-sm mr-3">
                                <?= htmlspecialchars($country) ?>
                            </span>
                            <span class="text-gray-500 text-sm"><?= count($countryTeams) ?> team<?= count($countryTeams) === 1 ? '' : 's' ?></span>
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($countryTeams as $participant): ?>
                                <div class="border border-gray-100 rounded-lg p-4 hover:shadow-md transition-shadow">
                                    <div class="flex items-start justify-between mb-2">
                                        <div>
                                            <h4 class="font-semibold text-gray-800"><?= htmlspecialchars($participant->getLabel()) ?></h4>
                                            <p class="text-sm text-gray-500"><?= htmlspecialchars($participant->getMetadataValue('city')) ?></p>
                                        </div>
                                        <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs font-semibold">
                                            Seed #<?= $participant->getSeed() ?>
                                        </span>
                                    </div>
                                    
                                    <div class="space-y-1 text-sm">
                                        <div class="flex justify-between">
                                            <span class="text-gray-500">Division:</span>
                                            <span class="text-gray-700"><?= htmlspecialchars($participant->getMetadataValue('division')) ?></span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-gray-500">Founded:</span>
                                            <span class="text-gray-700"><?= $participant->getMetadataValue('founded') ?></span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-gray-500">Stadium:</span>
                                            <span class="text-gray-700"><?= htmlspecialchars($participant->getMetadataValue('stadium')) ?></span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-gray-500">Capacity:</span>
                                            <span class="text-gray-700"><?= number_format($participant->getMetadataValue('capacity')) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Seeding Order -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Tournament Seeding</h2>
            <div class="space-y-3">
                <?php 
                // Sort participants by seed
                $sortedParticipants = $participants;
                usort($sortedParticipants, fn($a, $b) => $a->getSeed() <=> $b->getSeed());
                ?>
                <?php foreach ($sortedParticipants as $participant): ?>
                    <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                        <div class="flex items-center">
                            <div class="bg-blue-600 text-white rounded-full w-8 h-8 flex items-center justify-center font-bold text-sm mr-4">
                                <?= $participant->getSeed() ?>
                            </div>
                            <div>
                                <div class="font-semibold text-gray-800"><?= htmlspecialchars($participant->getLabel()) ?></div>
                                <div class="text-sm text-gray-500">
                                    <?= htmlspecialchars($participant->getMetadataValue('city')) ?>, 
                                    <?= htmlspecialchars($participant->getMetadataValue('country')) ?>
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-medium text-gray-800"><?= htmlspecialchars($participant->getMetadataValue('division')) ?></div>
                            <div class="text-xs text-gray-500">Est. <?= $participant->getMetadataValue('founded') ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Sample Schedule Preview -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Sample Schedule (First 6 Matches)</h2>
            <div class="space-y-3">
                <?php 
                $previewCount = 0;
                foreach ($schedule as $event):
                    if ($previewCount >= 6) break;
                    $eventParticipants = $event->getParticipants();
                    $previewCount++;
                ?>
                    <div class="flex items-center justify-between p-3 border border-gray-100 rounded-lg hover:bg-gray-50 transition-colors">
                        <div class="flex items-center">
                            <span class="bg-gray-100 text-gray-600 px-2 py-1 rounded text-sm mr-4">
                                Round <?= $event->getRound()->getNumber() ?>
                            </span>
                            <div class="flex items-center space-x-4">
                                <div class="text-center">
                                    <div class="font-medium text-gray-800"><?= htmlspecialchars($eventParticipants[0]->getLabel()) ?></div>
                                    <div class="text-xs text-gray-500">Seed #<?= $eventParticipants[0]->getSeed() ?></div>
                                </div>
                                <div class="text-gray-400 font-bold">VS</div>
                                <div class="text-center">
                                    <div class="font-medium text-gray-800"><?= htmlspecialchars($eventParticipants[1]->getLabel()) ?></div>
                                    <div class="text-xs text-gray-500">Seed #<?= $eventParticipants[1]->getSeed() ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="text-right text-sm text-gray-500">
                            <?= htmlspecialchars($eventParticipants[0]->getMetadataValue('country')) ?> vs 
                            <?= htmlspecialchars($eventParticipants[1]->getMetadataValue('country')) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="text-center text-gray-500 text-sm pt-2">
                    ... and <?= $totalEvents - 6 ?> more matches
                </div>
            </div>
        </div>

        <!-- Code Example -->
        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Code Example</h2>
            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 overflow-x-auto">
                <pre><code><?= htmlspecialchars('<?php
use MissionGaming\\Tactician\\DTO\\Participant;

// Create participant with seeding and metadata
$participant = new Participant(
    id: \'celtic\',
    label: \'Celtic FC\',
    seed: 1,  // Top seed
    metadata: [
        \'city\' => \'Glasgow\',
        \'country\' => \'Scotland\',
        \'division\' => \'Premier\',
        \'founded\' => 1887,
        \'stadium\' => \'Celtic Park\',
        \'capacity\' => 60411
    ]
);

// Access participant properties
echo $participant->getId();          // \'celtic\'
echo $participant->getLabel();       // \'Celtic FC\'
echo $participant->getSeed();        // 1
echo $participant->getMetadataValue(\'city\');    // \'Glasgow\'
echo $participant->getMetadataValue(\'missing\', \'default\'); // \'default\'

// Check if metadata exists
if ($participant->hasMetadata(\'stadium\')) {
    echo $participant->getMetadataValue(\'stadium\');
}') ?></code></pre>
            </div>
        </div>

        <!-- Navigation -->
        <div class="flex justify-between mt-8">
            <a href="01-basic-round-robin.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition-colors">
                ← Previous: Basic Round Robin
            </a>
            <a href="03-iterating-schedules.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg transition-colors">
                Next: Schedule Iteration →
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
