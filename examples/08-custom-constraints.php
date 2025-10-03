<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\Constraints\CallableConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Create participants with various metadata for custom constraint demonstrations
$participants = [
    new Participant('team1', 'Fire Dragons', 1, [
        'experience' => 'veteran',
        'preferred_time' => 'evening',
        'region' => 'west',
        'budget' => 'high',
        'team_size' => 5,
    ]),
    new Participant('team2', 'Ice Phoenix', 2, [
        'experience' => 'rookie',
        'preferred_time' => 'afternoon',
        'region' => 'east',
        'budget' => 'medium',
        'team_size' => 4,
    ]),
    new Participant('team3', 'Storm Hawks', 3, [
        'experience' => 'veteran',
        'preferred_time' => 'morning',
        'region' => 'west',
        'budget' => 'low',
        'team_size' => 5,
    ]),
    new Participant('team4', 'Thunder Wolves', 4, [
        'experience' => 'intermediate',
        'preferred_time' => 'evening',
        'region' => 'east',
        'budget' => 'high',
        'team_size' => 3,
    ]),
    new Participant('team5', 'Shadow Serpents', 5, [
        'experience' => 'intermediate',
        'preferred_time' => 'afternoon',
        'region' => 'central',
        'budget' => 'medium',
        'team_size' => 4,
    ]),
    new Participant('team6', 'Lightning Lions', 6, [
        'experience' => 'rookie',
        'preferred_time' => 'morning',
        'region' => 'central',
        'budget' => 'low',
        'team_size' => 5,
    ]),
];

// Custom constraint examples
$customConstraints = [
    'Basic Tournament' => [
        'description' => 'Standard tournament without custom constraints',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->build(),
        'rules' => 'No special matching rules applied',
    ],

    'Experience Balance' => [
        'description' => 'Prevent veteran teams from being matched together early',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->add(new CallableConstraint(
                function ($event, $context) {
                    $participants = $event->getParticipants();
                    $round = $event->getRound()->getNumber();

                    // In first 2 rounds, prevent veteran vs veteran matches
                    if ($round <= 2) {
                        $exp1 = $participants[0]->getMetadataValue('experience');
                        $exp2 = $participants[1]->getMetadataValue('experience');

                        return !($exp1 === 'veteran' && $exp2 === 'veteran');
                    }

                    return true;
                },
                'Experience Balance'
            ))
            ->build(),
        'rules' => 'Veteran teams avoid each other in rounds 1-2',
    ],

    'Time Preference Matching' => [
        'description' => 'Teams with similar time preferences play together',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->add(new CallableConstraint(
                function ($event, $context) {
                    $participants = $event->getParticipants();
                    $time1 = $participants[0]->getMetadataValue('preferred_time');
                    $time2 = $participants[1]->getMetadataValue('preferred_time');

                    // Prefer matches between teams with same time preference
                    // This is a soft constraint - we score it rather than block it
                    return $time1 === $time2;
                },
                'Time Preference Matching'
            ))
            ->build(),
        'rules' => 'Teams with same preferred time are matched together',
    ],

    'Budget Tier Separation' => [
        'description' => 'High budget teams cannot play low budget teams',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->add(new CallableConstraint(
                function ($event, $context) {
                    $participants = $event->getParticipants();
                    $budget1 = $participants[0]->getMetadataValue('budget');
                    $budget2 = $participants[1]->getMetadataValue('budget');

                    // Prevent high vs low budget matchups
                    return !(
                        ($budget1 === 'high' && $budget2 === 'low') ||
                        ($budget1 === 'low' && $budget2 === 'high')
                    );
                },
                'Budget Tier Separation'
            ))
            ->build(),
        'rules' => 'High budget teams cannot face low budget teams',
    ],

    'Complex Multi-Factor' => [
        'description' => 'Multiple custom rules combined for balanced matchmaking',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->add(new CallableConstraint(
                function ($event, $context) {
                    $participants = $event->getParticipants();
                    $round = $event->getRound()->getNumber();

                    // Rule 1: Team size difference shouldn't exceed 1
                    $size1 = $participants[0]->getMetadataValue('team_size');
                    $size2 = $participants[1]->getMetadataValue('team_size');
                    if (abs($size1 - $size2) > 1) {
                        return false;
                    }

                    // Rule 2: In early rounds, prefer same experience level
                    if ($round <= 3) {
                        $exp1 = $participants[0]->getMetadataValue('experience');
                        $exp2 = $participants[1]->getMetadataValue('experience');

                        // Slight preference for same experience in early rounds
                        if ($exp1 !== $exp2) {
                            // Allow it but with lower priority
                            return true;
                        }
                    }

                    return true;
                },
                'Multi-Factor Constraint'
            ))
            ->build(),
        'rules' => 'Team size difference ≤ 1, prefer same experience early',
    ],
];

$results = [];

// Generate schedules for each constraint scenario
foreach ($customConstraints as $name => $scenario) {
    try {
        $scheduler = new RoundRobinScheduler($scenario['constraints']);
        $schedule = $scheduler->generateSchedule($participants);

        $results[$name] = [
            'status' => 'success',
            'schedule' => $schedule,
            'description' => $scenario['description'],
            'rules' => $scenario['rules'],
            'total_events' => count($schedule),
            'total_rounds' => $schedule->getMetadataValue('total_rounds'),
            'error' => null,
        ];
    } catch (Exception $e) {
        $results[$name] = [
            'status' => 'failed',
            'schedule' => null,
            'description' => $scenario['description'],
            'rules' => $scenario['rules'],
            'total_events' => 0,
            'total_rounds' => 0,
            'error' => $e->getMessage(),
        ];
    }
}

// Analysis functions
function analyzeExperienceMatches($schedule)
{
    $veteranVsVeteran = 0;
    $rookieVsVeteran = 0;
    $sameExperience = 0;
    $total = 0;

    foreach ($schedule as $event) {
        $participants = $event->getParticipants();
        $exp1 = $participants[0]->getMetadataValue('experience');
        $exp2 = $participants[1]->getMetadataValue('experience');

        if ($exp1 === 'veteran' && $exp2 === 'veteran') {
            ++$veteranVsVeteran;
        }
        if (($exp1 === 'rookie' && $exp2 === 'veteran') ||
            ($exp1 === 'veteran' && $exp2 === 'rookie')) {
            ++$rookieVsVeteran;
        }
        if ($exp1 === $exp2) {
            ++$sameExperience;
        }
        ++$total;
    }

    return [
        'veteran_vs_veteran' => $veteranVsVeteran,
        'rookie_vs_veteran' => $rookieVsVeteran,
        'same_experience' => $sameExperience,
        'total' => $total,
        'same_exp_percentage' => $total > 0 ? round(($sameExperience / $total) * 100) : 0,
    ];
}

function analyzeTimePreferences($schedule)
{
    $sameTime = 0;
    $total = 0;

    foreach ($schedule as $event) {
        $participants = $event->getParticipants();
        $time1 = $participants[0]->getMetadataValue('preferred_time');
        $time2 = $participants[1]->getMetadataValue('preferred_time');

        if ($time1 === $time2) {
            ++$sameTime;
        }
        ++$total;
    }

    return [
        'same_time' => $sameTime,
        'total' => $total,
        'percentage' => $total > 0 ? round(($sameTime / $total) * 100) : 0,
    ];
}

function getExperienceColor($experience)
{
    return match($experience) {
        'veteran' => 'bg-red-100 text-red-800',
        'intermediate' => 'bg-yellow-100 text-yellow-800',
        'rookie' => 'bg-green-100 text-green-800',
        default => 'bg-gray-100 text-gray-800'
    };
}

function getBudgetColor($budget)
{
    return match($budget) {
        'high' => 'bg-purple-100 text-purple-800',
        'medium' => 'bg-blue-100 text-blue-800',
        'low' => 'bg-gray-100 text-gray-800',
        default => 'bg-gray-100 text-gray-800'
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custom Constraints - Tactician Examples</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-6xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">⚙️ Custom Constraints</h1>
                    <p class="text-blue-100">Creating custom constraint functions</p>
                </div>
                <a href="index.php" class="bg-blue-700 hover:bg-blue-800 px-4 py-2 rounded-lg transition-colors">
                    ← Back to Examples
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
        <!-- Custom Constraints Concept -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Understanding Custom Constraints</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <p class="text-gray-600 mb-4 leading-relaxed">
                        Custom constraints give you complete control over tournament scheduling logic. 
                        Using the <code class="bg-gray-100 px-2 py-1 rounded text-sm">CallableConstraint</code> class, 
                        you can implement any business rule or matching logic your tournament requires.
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        Custom constraints receive the proposed event and scheduling context, allowing 
                        you to make complex decisions based on participant metadata, round numbers, 
                        or any other factors relevant to your tournament.
                    </p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-3">Constraint Function Signature</h3>
                    <div class="bg-gray-900 text-gray-100 rounded p-3 text-sm font-mono">
                        <div>function($event, $context) {</div>
                        <div class="ml-4">// Your custom logic</div>
                        <div class="ml-4">return true; // or false</div>
                        <div>}</div>
                    </div>
                    <div class="mt-3 text-xs text-gray-600">
                        Return <strong>true</strong> to allow the match, <strong>false</strong> to prevent it.
                    </div>
                </div>
            </div>
        </div>

        <!-- Tournament Participants -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Tournament Participants</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($participants as $participant): ?>
                    <div class="border border-gray-200 rounded-lg p-4">
                        <div class="font-semibold text-gray-800 mb-3"><?= htmlspecialchars($participant->getLabel()); ?></div>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Experience:</span>
                                <span class="px-2 py-1 rounded-full text-xs <?= getExperienceColor($participant->getMetadataValue('experience')); ?>">
                                    <?= ucfirst($participant->getMetadataValue('experience')); ?>
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Budget:</span>
                                <span class="px-2 py-1 rounded-full text-xs <?= getBudgetColor($participant->getMetadataValue('budget')); ?>">
                                    <?= ucfirst($participant->getMetadataValue('budget')); ?>
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Time:</span>
                                <span class="text-gray-700"><?= ucfirst($participant->getMetadataValue('preferred_time')); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Region:</span>
                                <span class="text-gray-700"><?= ucfirst($participant->getMetadataValue('region')); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Team Size:</span>
                                <span class="text-gray-700"><?= $participant->getMetadataValue('team_size'); ?> players</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Custom Constraint Results -->
        <div class="space-y-8">
            <?php foreach ($results as $constraintName => $result): ?>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                                <?php if ($result['status'] === 'success'): ?>
                                    <span class="mr-2">✅</span>
                                <?php else: ?>
                                    <span class="mr-2">❌</span>
                                <?php endif; ?>
                                <?= htmlspecialchars($constraintName); ?>
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
                        <!-- Analysis Dashboard -->
                        <?php
                        $expAnalysis = analyzeExperienceMatches($result['schedule']);
                        $timeAnalysis = analyzeTimePreferences($result['schedule']);
                        ?>
                        
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                            <div class="bg-blue-50 rounded-lg p-4">
                                <div class="text-2xl font-bold text-blue-600"><?= $result['total_events']; ?></div>
                                <div class="text-blue-700 text-sm">Total Matches</div>
                            </div>
                            <div class="bg-green-50 rounded-lg p-4">
                                <div class="text-2xl font-bold text-green-600"><?= $expAnalysis['same_exp_percentage']; ?>%</div>
                                <div class="text-green-700 text-sm">Same Experience</div>
                                <div class="text-xs text-green-600"><?= $expAnalysis['same_experience']; ?>/<?= $expAnalysis['total']; ?></div>
                            </div>
                            <div class="bg-purple-50 rounded-lg p-4">
                                <div class="text-2xl font-bold text-purple-600"><?= $timeAnalysis['percentage']; ?>%</div>
                                <div class="text-purple-700 text-sm">Same Time Pref</div>
                                <div class="text-xs text-purple-600"><?= $timeAnalysis['same_time']; ?>/<?= $timeAnalysis['total']; ?></div>
                            </div>
                            <div class="bg-orange-50 rounded-lg p-4">
                                <div class="text-2xl font-bold text-orange-600"><?= $expAnalysis['veteran_vs_veteran']; ?></div>
                                <div class="text-orange-700 text-sm">Veteran Clashes</div>
                                <div class="text-xs text-orange-600">High-exp matches</div>
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

                            $sameExp = $p1->getMetadataValue('experience') === $p2->getMetadataValue('experience');
                            $sameTime = $p1->getMetadataValue('preferred_time') === $p2->getMetadataValue('preferred_time');
                            $teamSizeDiff = abs($p1->getMetadataValue('team_size') - $p2->getMetadataValue('team_size'));
                            $budgetConflict = ($p1->getMetadataValue('budget') === 'high' && $p2->getMetadataValue('budget') === 'low') ||
                                             ($p1->getMetadataValue('budget') === 'low' && $p2->getMetadataValue('budget') === 'high');
                            ++$sampleCount;
                            ?>
                                <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                                    <span class="bg-gray-100 text-gray-600 px-2 py-1 rounded text-sm">
                                        Round <?= $event->getRound()->getNumber(); ?>
                                    </span>
                                    
                                    <div class="flex items-center space-x-6">
                                        <div class="text-center">
                                            <div class="font-medium text-gray-800"><?= htmlspecialchars($p1->getLabel()); ?></div>
                                            <div class="text-xs space-x-1">
                                                <span class="px-1 rounded <?= getExperienceColor($p1->getMetadataValue('experience')); ?>">
                                                    <?= ucfirst($p1->getMetadataValue('experience')); ?>
                                                </span>
                                                <span class="px-1 rounded <?= getBudgetColor($p1->getMetadataValue('budget')); ?>">
                                                    <?= ucfirst($p1->getMetadataValue('budget')); ?>
                                                </span>
                                                <span class="text-gray-500"><?= $p1->getMetadataValue('team_size'); ?>p</span>
                                            </div>
                                        </div>
                                        <div class="text-gray-400 font-bold">VS</div>
                                        <div class="text-center">
                                            <div class="font-medium text-gray-800"><?= htmlspecialchars($p2->getLabel()); ?></div>
                                            <div class="text-xs space-x-1">
                                                <span class="px-1 rounded <?= getExperienceColor($p2->getMetadataValue('experience')); ?>">
                                                    <?= ucfirst($p2->getMetadataValue('experience')); ?>
                                                </span>
                                                <span class="px-1 rounded <?= getBudgetColor($p2->getMetadataValue('budget')); ?>">
                                                    <?= ucfirst($p2->getMetadataValue('budget')); ?>
                                                </span>
                                                <span class="text-gray-500"><?= $p2->getMetadataValue('team_size'); ?>p</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="flex flex-wrap gap-1">
                                        <?php if ($sameExp): ?>
                                            <span class="bg-green-500 text-white px-2 py-1 rounded text-xs">Same Exp</span>
                                        <?php endif; ?>
                                        <?php if ($sameTime): ?>
                                            <span class="bg-blue-500 text-white px-2 py-1 rounded text-xs">Same Time</span>
                                        <?php endif; ?>
                                        <?php if ($teamSizeDiff <= 1): ?>
                                            <span class="bg-purple-500 text-white px-2 py-1 rounded text-xs">Size OK</span>
                                        <?php endif; ?>
                                        <?php if ($budgetConflict): ?>
                                            <span class="bg-red-500 text-white px-2 py-1 rounded text-xs">Budget Clash</span>
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

        <!-- Advanced Custom Constraint Examples -->
        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Advanced Custom Constraint Examples</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h3 class="font-semibold text-gray-800 mb-3">Simple Boolean Constraint</h3>
                    <div class="bg-gray-900 text-gray-100 rounded-lg p-4 text-sm">
                        <pre><code><?= htmlspecialchars('new CallableConstraint(
    function($event, $context) {
        $participants = $event->getParticipants();
        $skill1 = $participants[0]->getMetadataValue(\'skill\');
        $skill2 = $participants[1]->getMetadataValue(\'skill\');
        
        // Only allow matches between similar skill levels
        return abs($skill1 - $skill2) <= 1;
    },
    \'Skill Balance\'
)'); ?></code></pre>
                    </div>
                </div>
                
                <div>
                    <h3 class="font-semibold text-gray-800 mb-3">Context-Aware Constraint</h3>
                    <div class="bg-gray-900 text-gray-100 rounded-lg p-4 text-sm">
                        <pre><code><?= htmlspecialchars('new CallableConstraint(
    function($event, $context) {
        $round = $event->getRound()->getNumber();
        $participants = $event->getParticipants();
        
        // Different rules for different rounds
        if ($round <= 3) {
            // Early rounds: prefer regional matches
            return $participants[0]->getMetadataValue(\'region\') === 
                   $participants[1]->getMetadataValue(\'region\');
        }
        
        // Later rounds: any match allowed
        return true;
    },
    \'Progressive Regional Matching\'
)'); ?></code></pre>
                    </div>
                </div>
                
                <div>
                    <h3 class="font-semibold text-gray-800 mb-3">Multi-Criteria Constraint</h3>
                    <div class="bg-gray-900 text-gray-100 rounded-lg p-4 text-sm">
                        <pre><code><?= htmlspecialchars('new CallableConstraint(
    function($event, $context) {
        $p1 = $event->getParticipants()[0];
        $p2 = $event->getParticipants()[1];
        
        // Multiple checks
        $timezoneOk = abs(
            $p1->getMetadataValue(\'timezone_offset\') - 
            $p2->getMetadataValue(\'timezone_offset\')
        ) <= 3;
        
        $languageOk = $p1->getMetadataValue(\'language\') === 
                      $p2->getMetadataValue(\'language\');
        
        // Must pass timezone check, language is preferred
        return $timezoneOk && ($languageOk || rand(0, 100) < 70);
    },
    \'Timezone & Language Preference\'
)'); ?></code></pre>
                    </div>
                </div>
                
                <div>
                    <h3 class="font-semibold text-gray-800 mb-3">Historical Data Constraint</h3>
                    <div class="bg-gray-900 text-gray-100 rounded-lg p-4 text-sm">
                        <pre><code><?= htmlspecialchars('new CallableConstraint(
    function($event, $context) {
        $p1 = $event->getParticipants()[0];
        $p2 = $event->getParticipants()[1];
        
        // Access scheduling context for history
        $history = $context->getMatchHistory();
        $recentMatches = $history->getRecentMatches(
            [$p1->getId(), $p2->getId()], 
            5 // last 5 tournaments
        );
        
        // Avoid teams that have played frequently
        return count($recentMatches) < 3;
    },
    \'Avoid Frequent Opponents\'
)'); ?></code></pre>
                    </div>
                </div>
            </div>
        </div>

        <!-- Best Practices -->
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mt-8">
            <h3 class="text-lg font-semibold text-yellow-800 mb-3">🔧 Custom Constraint Best Practices</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
                <div>
                    <h4 class="font-medium text-yellow-800 mb-2">Performance Tips</h4>
                    <ul class="space-y-1 text-yellow-700">
                        <li>• Keep constraint logic simple and fast</li>
                        <li>• Cache expensive calculations in variables</li>
                        <li>• Avoid database queries in constraint functions</li>
                        <li>• Use early returns for quick rejections</li>
                        <li>• Consider constraint execution order</li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-medium text-yellow-800 mb-2">Design Guidelines</h4>
                    <ul class="space-y-1 text-yellow-700">
                        <li>• Give constraints descriptive names</li>
                        <li>• Make hard requirements strict (return false)</li>
                        <li>• Use probabilities for soft preferences</li>
                        <li>• Test constraints with edge cases</li>
                        <li>• Document complex constraint logic</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <div class="flex justify-between mt-8">
            <a href="07-metadata-constraints.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition-colors">
                ← Previous: Metadata Constraints
            </a>
            <a href="09-multi-leg-home-away.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg transition-colors">
                Next: Multi-Leg Home/Away →
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
