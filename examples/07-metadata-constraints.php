<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MetadataConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Create participants with rich metadata
$participants = [
    new Participant('team1', 'Nordic Wolves', 1, [
        'region' => 'Europe',
        'skill_level' => 'Expert',
        'language' => 'English',
        'timezone' => 'UTC+1',
        'platform' => 'PC',
    ]),
    new Participant('team2', 'Tokyo Thunder', 2, [
        'region' => 'Asia',
        'skill_level' => 'Expert',
        'language' => 'Japanese',
        'timezone' => 'UTC+9',
        'platform' => 'PC',
    ]),
    new Participant('team3', 'California Kings', 3, [
        'region' => 'North America',
        'skill_level' => 'Advanced',
        'language' => 'English',
        'timezone' => 'UTC-8',
        'platform' => 'Console',
    ]),
    new Participant('team4', 'London Lions', 4, [
        'region' => 'Europe',
        'skill_level' => 'Advanced',
        'language' => 'English',
        'timezone' => 'UTC+0',
        'platform' => 'PC',
    ]),
    new Participant('team5', 'São Paulo Squad', 5, [
        'region' => 'South America',
        'skill_level' => 'Intermediate',
        'language' => 'Portuguese',
        'timezone' => 'UTC-3',
        'platform' => 'Console',
    ]),
    new Participant('team6', 'Sydney Sharks', 6, [
        'region' => 'Oceania',
        'skill_level' => 'Advanced',
        'language' => 'English',
        'timezone' => 'UTC+10',
        'platform' => 'PC',
    ]),
];

// Different metadata constraint scenarios
$scenarios = [
    'No Metadata Constraints' => [
        'description' => 'Standard tournament without metadata restrictions',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->build(),
        'rules' => 'Any team can play against any other team',
    ],
    'Same Skill Level Only' => [
        'description' => 'Teams can only play against teams of the same skill level',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->add(MetadataConstraint::requireSameValue('skill_level'))
            ->build(),
        'rules' => 'Expert vs Expert, Advanced vs Advanced, etc.',
    ],
    'Same Region Preference' => [
        'description' => 'Prefer matches within the same region when possible',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->add(MetadataConstraint::requireSameValue('region'))
            ->build(),
        'rules' => 'European teams prefer to play other European teams',
    ],
    'Cross-Platform Forbidden' => [
        'description' => 'PC teams cannot play against Console teams',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->add(MetadataConstraint::requireSameValue('platform'))
            ->build(),
        'rules' => 'PC teams only play PC teams, Console only play Console',
    ],
    'Complex Rules' => [
        'description' => 'Multiple metadata constraints combined',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->add(MetadataConstraint::requireSameValue('platform'))
            ->add(MetadataConstraint::requireSameValue('region'))
            ->build(),
        'rules' => 'Same platform required, same region preferred',
    ],
];

$results = [];

// Generate schedules for each scenario
foreach ($scenarios as $name => $scenario) {
    try {
        $scheduler = new RoundRobinScheduler($scenario['constraints']);
        $schedule = $scheduler->generateSchedule($participants);

        $results[$name] = [
            'status' => 'success',
            'schedule' => $schedule,
            'description' => $scenario['description'],
            'rules' => $scenario['rules'],
            'total_events' => count($schedule),
            'error' => null,
        ];
    } catch (Exception $e) {
        $results[$name] = [
            'status' => 'failed',
            'schedule' => null,
            'description' => $scenario['description'],
            'rules' => $scenario['rules'],
            'total_events' => 0,
            'error' => $e->getMessage(),
        ];
    }
}

// Helper functions
function getRegionColor($region)
{
    return match($region) {
        'Europe' => 'bg-blue-100 text-blue-800',
        'Asia' => 'bg-red-100 text-red-800',
        'North America' => 'bg-green-100 text-green-800',
        'South America' => 'bg-yellow-100 text-yellow-800',
        'Oceania' => 'bg-purple-100 text-purple-800',
        default => 'bg-gray-100 text-gray-800'
    };
}

function getSkillColor($skill)
{
    return match($skill) {
        'Expert' => 'bg-red-100 text-red-800',
        'Advanced' => 'bg-orange-100 text-orange-800',
        'Intermediate' => 'bg-yellow-100 text-yellow-800',
        default => 'bg-gray-100 text-gray-800'
    };
}

function analyzeMatchMetadata($schedule)
{
    $sameRegion = 0;
    $samePlatform = 0;
    $sameSkill = 0;
    $total = 0;

    foreach ($schedule as $event) {
        $participants = $event->getParticipants();
        $p1 = $participants[0];
        $p2 = $participants[1];

        if ($p1->getMetadataValue('region') === $p2->getMetadataValue('region')) {
            ++$sameRegion;
        }
        if ($p1->getMetadataValue('platform') === $p2->getMetadataValue('platform')) {
            ++$samePlatform;
        }
        if ($p1->getMetadataValue('skill_level') === $p2->getMetadataValue('skill_level')) {
            ++$sameSkill;
        }
        ++$total;
    }

    return [
        'same_region' => $sameRegion,
        'same_platform' => $samePlatform,
        'same_skill' => $sameSkill,
        'total' => $total,
        'region_percentage' => $total > 0 ? round(($sameRegion / $total) * 100) : 0,
        'platform_percentage' => $total > 0 ? round(($samePlatform / $total) * 100) : 0,
        'skill_percentage' => $total > 0 ? round(($sameSkill / $total) * 100) : 0,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Metadata Constraints - Tactician Examples</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-6xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">🏷️ Metadata Constraints</h1>
                    <p class="text-blue-100">Region-based and skill-level matching rules</p>
                </div>
                <a href="index.php" class="bg-blue-700 hover:bg-blue-800 px-4 py-2 rounded-lg transition-colors">
                    ← Back to Examples
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
        <!-- Metadata Constraints Concept -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Understanding Metadata Constraints</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <p class="text-gray-600 mb-4 leading-relaxed">
                        Metadata constraints use participant data to control matchmaking. You can enforce 
                        rules like "only match teams from the same region" or "prefer teams with similar 
                        skill levels" to create more balanced and fair tournaments.
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        These constraints are perfect for international tournaments, skill-based matchmaking, 
                        or any scenario where participant characteristics should influence scheduling.
                    </p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-3">Constraint Types</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-red-500 rounded-full mr-2"></span>
                            <span>requireSameValue() - Strict requirement</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-yellow-500 rounded-full mr-2"></span>
                            <span>requireSameValue() - Soft preference</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-blue-500 rounded-full mr-2"></span>
                            <span>mustNotMatch() - Strict avoidance</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-purple-500 rounded-full mr-2"></span>
                            <span>Custom predicates - Complex rules</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Participant Overview -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Tournament Participants</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($participants as $participant): ?>
                    <div class="border border-gray-200 rounded-lg p-4">
                        <div class="font-semibold text-gray-800 mb-2"><?= htmlspecialchars($participant->getLabel()); ?></div>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Region:</span>
                                <span class="px-2 py-1 rounded-full text-xs <?= getRegionColor($participant->getMetadataValue('region')); ?>">
                                    <?= htmlspecialchars($participant->getMetadataValue('region')); ?>
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Skill:</span>
                                <span class="px-2 py-1 rounded-full text-xs <?= getSkillColor($participant->getMetadataValue('skill_level')); ?>">
                                    <?= htmlspecialchars($participant->getMetadataValue('skill_level')); ?>
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Platform:</span>
                                <span class="text-gray-700"><?= htmlspecialchars($participant->getMetadataValue('platform')); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Language:</span>
                                <span class="text-gray-700"><?= htmlspecialchars($participant->getMetadataValue('language')); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Constraint Scenarios -->
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
                            <p class="text-sm text-blue-600 mt-1"><strong>Rules:</strong> <?= htmlspecialchars($result['rules']); ?></p>
                        </div>
                        <?php if ($result['status'] === 'success'): ?>
                            <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm">
                                <?= $result['total_events']; ?> matches
                            </span>
                        <?php else: ?>
                            <span class="bg-red-100 text-red-800 px-3 py-1 rounded-full text-sm">
                                Failed
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($result['status'] === 'success'): ?>
                        <?php $analysis = analyzeMatchMetadata($result['schedule']); ?>
                        
                        <!-- Metadata Analysis -->
                        <div class="grid grid-cols-3 gap-4 mb-6">
                            <div class="bg-blue-50 rounded-lg p-4">
                                <div class="text-2xl font-bold text-blue-600"><?= $analysis['region_percentage']; ?>%</div>
                                <div class="text-blue-700 text-sm">Same Region Matches</div>
                                <div class="text-xs text-blue-600"><?= $analysis['same_region']; ?>/<?= $analysis['total']; ?></div>
                            </div>
                            <div class="bg-green-50 rounded-lg p-4">
                                <div class="text-2xl font-bold text-green-600"><?= $analysis['platform_percentage']; ?>%</div>
                                <div class="text-green-700 text-sm">Same Platform Matches</div>
                                <div class="text-xs text-green-600"><?= $analysis['same_platform']; ?>/<?= $analysis['total']; ?></div>
                            </div>
                            <div class="bg-purple-50 rounded-lg p-4">
                                <div class="text-2xl font-bold text-purple-600"><?= $analysis['skill_percentage']; ?>%</div>
                                <div class="text-purple-700 text-sm">Same Skill Matches</div>
                                <div class="text-xs text-purple-600"><?= $analysis['same_skill']; ?>/<?= $analysis['total']; ?></div>
                            </div>
                        </div>

                        <!-- Sample Matches -->
                        <div class="space-y-3">
                            <h4 class="font-medium text-gray-800">Sample Matches (First 6)</h4>
                            <?php
                            $sampleCount = 0;
                        foreach ($result['schedule'] as $event):
                            if ($sampleCount >= 6) {
                                break;
                            }
                            $eventParticipants = $event->getParticipants();
                            $p1 = $eventParticipants[0];
                            $p2 = $eventParticipants[1];

                            $sameRegion = $p1->getMetadataValue('region') === $p2->getMetadataValue('region');
                            $samePlatform = $p1->getMetadataValue('platform') === $p2->getMetadataValue('platform');
                            $sameSkill = $p1->getMetadataValue('skill_level') === $p2->getMetadataValue('skill_level');
                            ++$sampleCount;
                            ?>
                                <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                                    <div class="flex items-center space-x-6">
                                        <div class="text-center">
                                            <div class="font-medium text-gray-800"><?= htmlspecialchars($p1->getLabel()); ?></div>
                                            <div class="text-xs space-x-1">
                                                <span class="px-1 rounded <?= getRegionColor($p1->getMetadataValue('region')); ?>">
                                                    <?= htmlspecialchars($p1->getMetadataValue('region')); ?>
                                                </span>
                                                <span class="px-1 rounded <?= getSkillColor($p1->getMetadataValue('skill_level')); ?>">
                                                    <?= htmlspecialchars($p1->getMetadataValue('skill_level')); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="text-gray-400 font-bold">VS</div>
                                        <div class="text-center">
                                            <div class="font-medium text-gray-800"><?= htmlspecialchars($p2->getLabel()); ?></div>
                                            <div class="text-xs space-x-1">
                                                <span class="px-1 rounded <?= getRegionColor($p2->getMetadataValue('region')); ?>">
                                                    <?= htmlspecialchars($p2->getMetadataValue('region')); ?>
                                                </span>
                                                <span class="px-1 rounded <?= getSkillColor($p2->getMetadataValue('skill_level')); ?>">
                                                    <?= htmlspecialchars($p2->getMetadataValue('skill_level')); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="flex space-x-1">
                                        <?php if ($sameRegion): ?>
                                            <span class="bg-blue-500 text-white px-2 py-1 rounded text-xs">Same Region</span>
                                        <?php endif; ?>
                                        <?php if ($samePlatform): ?>
                                            <span class="bg-green-500 text-white px-2 py-1 rounded text-xs">Same Platform</span>
                                        <?php endif; ?>
                                        <?php if ($sameSkill): ?>
                                            <span class="bg-purple-500 text-white px-2 py-1 rounded text-xs">Same Skill</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    <?php else: ?>
                        <!-- Failed scenario -->
                        <div class="bg-red-50 rounded-lg p-4">
                            <h4 class="font-semibold text-red-800 mb-2">❌ Schedule Generation Failed</h4>
                            <p class="text-red-700 text-sm"><?= htmlspecialchars($result['error']); ?></p>
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
use MissionGaming\\Tactician\\Constraints\\MetadataConstraint;

// Require teams to have matching platforms
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(MetadataConstraint::requireSameValue(\'platform\'))
    ->build();

// Prefer teams from the same region, but allow cross-region if needed
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(MetadataConstraint::requireSameValue(\'region\'))
    ->build();

// Prevent teams with different skill levels from playing
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(MetadataConstraint::mustNotMatch(\'skill_level\'))
    ->build();

// Complex metadata constraint with custom logic
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(MetadataConstraint::custom(function($p1, $p2) {
        // Only allow matches between teams in compatible timezones
        $tz1 = $p1->getMetadataValue(\'timezone\');
        $tz2 = $p2->getMetadataValue(\'timezone\');
        
        // Extract hour offset from timezone strings like "UTC+1"
        $offset1 = (int) str_replace([\'UTC\', \'+\'], \'\', $tz1);
        $offset2 = (int) str_replace([\'UTC\', \'+\'], \'\', $tz2);
        
        // Allow matches if timezone difference is <= 6 hours
        return abs($offset1 - $offset2) <= 6;
    }, \'Compatible Timezones\'))
    ->build();'); ?></code></pre>
            </div>
        </div>

        <!-- Navigation -->
        <div class="flex justify-between mt-8">
            <a href="06-rest-periods.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition-colors">
                ← Previous: Rest Periods
            </a>
            <a href="08-custom-constraints.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg transition-colors">
                Next: Custom Constraints →
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
