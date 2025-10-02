<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\Constraints\CallableConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Create a complex gaming tournament with 8 teams
$teams = [
    new Participant('fnatic', 'Fnatic', 1, [
        'region' => 'Europe',
        'tier' => 'S',
        'founded' => 2004,
        'wins' => 147,
        'avg_match_duration' => 32,
        'sponsor_tier' => 'premium',
        'streaming_platform' => 'twitch',
        'coach_experience' => 'veteran',
    ]),
    new Participant('tsm', 'Team SoloMid', 2, [
        'region' => 'North America',
        'tier' => 'S',
        'founded' => 2009,
        'wins' => 134,
        'avg_match_duration' => 28,
        'sponsor_tier' => 'premium',
        'streaming_platform' => 'twitch',
        'coach_experience' => 'veteran',
    ]),
    new Participant('skt', 'T1', 3, [
        'region' => 'Asia',
        'tier' => 'S',
        'founded' => 2002,
        'wins' => 189,
        'avg_match_duration' => 35,
        'sponsor_tier' => 'premium',
        'streaming_platform' => 'youtube',
        'coach_experience' => 'veteran',
    ]),
    new Participant('g2', 'G2 Esports', 4, [
        'region' => 'Europe',
        'tier' => 'A',
        'founded' => 2013,
        'wins' => 98,
        'avg_match_duration' => 30,
        'sponsor_tier' => 'standard',
        'streaming_platform' => 'twitch',
        'coach_experience' => 'experienced',
    ]),
    new Participant('cloud9', 'Cloud9', 5, [
        'region' => 'North America',
        'tier' => 'A',
        'founded' => 2013,
        'wins' => 87,
        'avg_match_duration' => 26,
        'sponsor_tier' => 'standard',
        'streaming_platform' => 'twitch',
        'coach_experience' => 'experienced',
    ]),
    new Participant('geng', 'Gen.G', 6, [
        'region' => 'Asia',
        'tier' => 'A',
        'founded' => 2017,
        'wins' => 76,
        'avg_match_duration' => 33,
        'sponsor_tier' => 'standard',
        'streaming_platform' => 'youtube',
        'coach_experience' => 'experienced',
    ]),
    new Participant('mad', 'MAD Lions', 7, [
        'region' => 'Europe',
        'tier' => 'B',
        'founded' => 2017,
        'wins' => 45,
        'avg_match_duration' => 29,
        'sponsor_tier' => 'basic',
        'streaming_platform' => 'twitch',
        'coach_experience' => 'rookie',
    ]),
    new Participant('100t', '100 Thieves', 8, [
        'region' => 'North America',
        'tier' => 'B',
        'founded' => 2017,
        'wins' => 52,
        'avg_match_duration' => 31,
        'sponsor_tier' => 'basic',
        'streaming_platform' => 'youtube',
        'coach_experience' => 'rookie',
    ]),
];

// Complex constraint set combining multiple rules
$constraints = ConstraintSet::create()
    ->noRepeatPairings()

    // Protect top 3 seeds for 40% of tournament
    ->add(new SeedProtectionConstraint(3, 0.4))

    // Minimum 1 round rest between matches (for player stamina)
    ->add(new MinimumRestPeriodsConstraint(1))

    // Custom constraint: Tier balance
    ->add(new CallableConstraint(
        function ($event, $context) {
            $participants = $event->getParticipants();
            $round = $event->getRound()->getNumber();

            // In early rounds (1-3), avoid S-tier vs B-tier matchups
            if ($round <= 3) {
                $tier1 = $participants[0]->getMetadataValue('tier');
                $tier2 = $participants[1]->getMetadataValue('tier');

                return !(
                    ($tier1 === 'S' && $tier2 === 'B') ||
                    ($tier1 === 'B' && $tier2 === 'S')
                );
            }

            return true;
        },
        'Early Round Tier Balance'
    ))

    // Custom constraint: Regional distribution
    ->add(new CallableConstraint(
        function ($event, $context) {
            $participants = $event->getParticipants();
            $round = $event->getRound()->getNumber();

            // Prefer cross-regional matches in later rounds for variety
            if ($round >= 5) {
                $region1 = $participants[0]->getMetadataValue('region');
                $region2 = $participants[1]->getMetadataValue('region');

                // 70% preference for cross-regional matches in later rounds
                if ($region1 !== $region2) {
                    return true; // Strongly prefer
                }

                return rand(0, 100) < 30; // 30% chance for same region
            }

            return true;
        },
        'Late Round Regional Diversity'
    ))

    // Custom constraint: Streaming platform balance
    ->add(new CallableConstraint(
        function ($event, $context) {
            $participants = $event->getParticipants();
            $platform1 = $participants[0]->getMetadataValue('streaming_platform');
            $platform2 = $participants[1]->getMetadataValue('streaming_platform');

            // For broadcast scheduling, prefer mixed platform matches
            // This helps with streaming rights and viewership distribution
            return $platform1 !== $platform2 || rand(0, 100) < 40;
        },
        'Streaming Platform Balance'
    ))

    ->build();

// Generate the complex tournament schedule
try {
    $scheduler = new RoundRobinScheduler($constraints);
    $schedule = $scheduler->schedule($teams);
    $success = true;
    $error = null;
} catch (Exception $e) {
    $success = false;
    $error = $e->getMessage();
    $schedule = null;
}

// Analysis functions
function analyzeTournamentComplexity($schedule, $teams)
{
    if (!$schedule) {
        return null;
    }

    $analysis = [
        'total_events' => count($schedule),
        'total_rounds' => $schedule->getMetadataValue('total_rounds'),
        'tier_matchups' => ['S_vs_S' => 0, 'S_vs_A' => 0, 'S_vs_B' => 0, 'A_vs_A' => 0, 'A_vs_B' => 0, 'B_vs_B' => 0],
        'regional_matchups' => ['same_region' => 0, 'cross_region' => 0],
        'platform_distribution' => ['same_platform' => 0, 'mixed_platform' => 0],
        'seed_protection_violations' => 0,
        'early_mismatches' => [],
        'late_regional_diversity' => 0,
    ];

    $protectedPeriod = (int) ceil($analysis['total_rounds'] * 0.4);

    foreach ($schedule as $event) {
        $participants = $event->getParticipants();
        $round = $event->getRound()->getNumber();

        $tier1 = $participants[0]->getMetadataValue('tier');
        $tier2 = $participants[1]->getMetadataValue('tier');
        $region1 = $participants[0]->getMetadataValue('region');
        $region2 = $participants[1]->getMetadataValue('region');
        $platform1 = $participants[0]->getMetadataValue('streaming_platform');
        $platform2 = $participants[1]->getMetadataValue('streaming_platform');

        // Tier analysis
        $tierKey = $tier1 . '_vs_' . $tier2;
        if ($tier1 > $tier2) {
            $tierKey = $tier2 . '_vs_' . $tier1;
        }
        ++$analysis['tier_matchups'][$tierKey];

        // Regional analysis
        if ($region1 === $region2) {
            ++$analysis['regional_matchups']['same_region'];
        } else {
            ++$analysis['regional_matchups']['cross_region'];
            if ($round >= 5) {
                ++$analysis['late_regional_diversity'];
            }
        }

        // Platform analysis
        if ($platform1 === $platform2) {
            ++$analysis['platform_distribution']['same_platform'];
        } else {
            ++$analysis['platform_distribution']['mixed_platform'];
        }

        // Seed protection analysis
        $seed1 = $participants[0]->getSeed();
        $seed2 = $participants[1]->getSeed();
        if ($round <= $protectedPeriod && (($seed1 <= 3 && $seed2 <= 3) && $seed1 !== $seed2)) {
            ++$analysis['seed_protection_violations'];
        }

        // Early mismatch analysis
        if ($round <= 3 && (($tier1 === 'S' && $tier2 === 'B') || ($tier1 === 'B' && $tier2 === 'S'))) {
            $analysis['early_mismatches'][] = [
                'round' => $round,
                'team1' => $participants[0]->getLabel(),
                'team2' => $participants[1]->getLabel(),
                'tier1' => $tier1,
                'tier2' => $tier2,
            ];
        }
    }

    return $analysis;
}

function getTierColor($tier)
{
    return match($tier) {
        'S' => 'bg-red-100 text-red-800 border-red-300',
        'A' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
        'B' => 'bg-green-100 text-green-800 border-green-300',
        default => 'bg-gray-100 text-gray-800 border-gray-300'
    };
}

function getRegionColor($region)
{
    return match($region) {
        'Europe' => 'bg-blue-100 text-blue-800',
        'North America' => 'bg-green-100 text-green-800',
        'Asia' => 'bg-purple-100 text-purple-800',
        default => 'bg-gray-100 text-gray-800'
    };
}

$analysis = $success ? analyzeTournamentComplexity($schedule, $teams) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complex Tournament - Tactician Examples</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-6xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">🎮 Complex Tournament</h1>
                    <p class="text-blue-100">Gaming tournament with multiple constraint types</p>
                </div>
                <a href="index.php" class="bg-blue-700 hover:bg-blue-800 px-4 py-2 rounded-lg transition-colors">
                    ← Back to Examples
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
        <!-- Tournament Overview -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Tournament Overview</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <p class="text-gray-600 mb-4 leading-relaxed">
                        This example demonstrates a complex esports tournament with multiple overlapping constraints.
                        Real tournaments often require balancing competitive fairness, broadcast considerations, 
                        sponsor requirements, and player welfare simultaneously.
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        The constraint system handles seed protection, rest periods, tier balancing, regional distribution,
                        and streaming platform considerations all working together.
                    </p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-3">Active Constraints</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-blue-500 rounded-full mr-2"></span>
                            <span>No Repeat Pairings</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-green-500 rounded-full mr-2"></span>
                            <span>Top 3 Seed Protection (40%)</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-purple-500 rounded-full mr-2"></span>
                            <span>Minimum 1 Round Rest</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-orange-500 rounded-full mr-2"></span>
                            <span>Early Round Tier Balance</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-red-500 rounded-full mr-2"></span>
                            <span>Regional Diversity (Late Rounds)</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-yellow-500 rounded-full mr-2"></span>
                            <span>Streaming Platform Balance</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tournament Teams -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Participating Teams</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php foreach ($teams as $team): ?>
                    <div class="border-2 rounded-lg p-4 <?= getTierColor($team->getMetadataValue('tier')); ?>">
                        <div class="flex items-center justify-between mb-2">
                            <div class="font-semibold"><?= htmlspecialchars($team->getLabel()); ?></div>
                            <div class="text-sm font-bold">Seed #<?= $team->getSeed(); ?></div>
                        </div>
                        <div class="space-y-1 text-xs">
                            <div class="flex justify-between">
                                <span>Tier:</span>
                                <span class="font-medium"><?= $team->getMetadataValue('tier'); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Region:</span>
                                <span class="px-1 rounded <?= getRegionColor($team->getMetadataValue('region')); ?>"><?= htmlspecialchars($team->getMetadataValue('region')); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Wins:</span>
                                <span class="font-medium"><?= $team->getMetadataValue('wins'); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Platform:</span>
                                <span class="font-medium"><?= ucfirst($team->getMetadataValue('streaming_platform')); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Sponsor:</span>
                                <span class="font-medium"><?= ucfirst($team->getMetadataValue('sponsor_tier')); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($success && $analysis): ?>
            <!-- Tournament Statistics -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Tournament Analysis</h2>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-blue-50 rounded-lg p-4">
                        <div class="text-2xl font-bold text-blue-600"><?= $analysis['total_events']; ?></div>
                        <div class="text-blue-700 text-sm">Total Matches</div>
                    </div>
                    <div class="bg-green-50 rounded-lg p-4">
                        <div class="text-2xl font-bold text-green-600"><?= $analysis['total_rounds']; ?></div>
                        <div class="text-green-700 text-sm">Total Rounds</div>
                    </div>
                    <div class="bg-purple-50 rounded-lg p-4">
                        <div class="text-2xl font-bold text-purple-600"><?= $analysis['regional_matchups']['cross_region']; ?></div>
                        <div class="text-purple-700 text-sm">Cross-Regional</div>
                    </div>
                    <div class="bg-orange-50 rounded-lg p-4">
                        <div class="text-2xl font-bold text-orange-600"><?= count($analysis['early_mismatches']); ?></div>
                        <div class="text-orange-700 text-sm">Early Mismatches</div>
                    </div>
                </div>

                <!-- Detailed Analysis Charts -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <!-- Tier Distribution -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="font-semibold text-gray-800 mb-3">Tier Matchups</h3>
                        <div class="space-y-2">
                            <?php foreach ($analysis['tier_matchups'] as $matchup => $count): ?>
                                <?php if ($count > 0): ?>
                                    <div class="flex justify-between">
                                        <span class="text-sm"><?= str_replace('_', ' ', $matchup); ?></span>
                                        <span class="font-medium"><?= $count; ?></span>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Regional Distribution -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="font-semibold text-gray-800 mb-3">Regional Distribution</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span class="text-sm">Same Region</span>
                                <span class="font-medium"><?= $analysis['regional_matchups']['same_region']; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm">Cross Region</span>
                                <span class="font-medium"><?= $analysis['regional_matchups']['cross_region']; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm">Late Diversity</span>
                                <span class="font-medium"><?= $analysis['late_regional_diversity']; ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Platform Distribution -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="font-semibold text-gray-800 mb-3">Streaming Platforms</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span class="text-sm">Same Platform</span>
                                <span class="font-medium"><?= $analysis['platform_distribution']['same_platform']; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm">Mixed Platform</span>
                                <span class="font-medium"><?= $analysis['platform_distribution']['mixed_platform']; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm">Mix Percentage</span>
                                <span class="font-medium">
                                    <?= round(($analysis['platform_distribution']['mixed_platform'] / $analysis['total_events']) * 100); ?>%
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Schedule Preview -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">Tournament Schedule (First 8 Matches)</h2>
                <p class="text-gray-600 mb-4">
                    This schedule demonstrates how multiple constraints work together to create balanced, 
                    fair, and broadcast-friendly matchups.
                </p>
                
                <div class="space-y-3">
                    <?php
                    $sampleCount = 0;
            foreach ($schedule as $event):
                if ($sampleCount >= 8) {
                    break;
                }
                $eventParticipants = $event->getParticipants();
                $p1 = $eventParticipants[0];
                $p2 = $eventParticipants[1];
                $round = $event->getRound()->getNumber();

                $tier1 = $p1->getMetadataValue('tier');
                $tier2 = $p2->getMetadataValue('tier');
                $region1 = $p1->getMetadataValue('region');
                $region2 = $p2->getMetadataValue('region');
                $platform1 = $p1->getMetadataValue('streaming_platform');
                $platform2 = $p2->getMetadataValue('streaming_platform');

                $isProtectedPeriod = $round <= ceil($analysis['total_rounds'] * 0.4);
                $isTierMismatch = ($tier1 === 'S' && $tier2 === 'B') || ($tier1 === 'B' && $tier2 === 'S');
                $isCrossRegional = $region1 !== $region2;
                $isMixedPlatform = $platform1 !== $platform2;
                ++$sampleCount;
                ?>
                        <div class="flex items-center justify-between p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                            <div class="flex items-center space-x-4">
                                <span class="bg-gray-100 text-gray-600 px-3 py-1 rounded-full text-sm font-medium">
                                    Round <?= $round; ?>
                                </span>
                                <?php if ($isProtectedPeriod): ?>
                                    <span class="bg-green-100 text-green-800 px-2 py-1 rounded-full text-xs">Protected</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="flex items-center space-x-6">
                                <div class="text-center">
                                    <div class="font-medium text-gray-800"><?= htmlspecialchars($p1->getLabel()); ?></div>
                                    <div class="text-xs space-x-1">
                                        <span class="px-1 rounded <?= getTierColor($tier1); ?>"><?= $tier1; ?></span>
                                        <span class="px-1 rounded <?= getRegionColor($region1); ?>"><?= substr($region1, 0, 3); ?></span>
                                        <span class="text-gray-500">Seed #<?= $p1->getSeed(); ?></span>
                                    </div>
                                </div>
                                <div class="text-gray-400 font-bold">VS</div>
                                <div class="text-center">
                                    <div class="font-medium text-gray-800"><?= htmlspecialchars($p2->getLabel()); ?></div>
                                    <div class="text-xs space-x-1">
                                        <span class="px-1 rounded <?= getTierColor($tier2); ?>"><?= $tier2; ?></span>
                                        <span class="px-1 rounded <?= getRegionColor($region2); ?>"><?= substr($region2, 0, 3); ?></span>
                                        <span class="text-gray-500">Seed #<?= $p2->getSeed(); ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex flex-wrap gap-1">
                                <?php if ($isCrossRegional): ?>
                                    <span class="bg-blue-500 text-white px-2 py-1 rounded text-xs">Cross-Regional</span>
                                <?php endif; ?>
                                <?php if ($isMixedPlatform): ?>
                                    <span class="bg-purple-500 text-white px-2 py-1 rounded text-xs">Mixed Platform</span>
                                <?php endif; ?>
                                <?php if ($isTierMismatch && $round <= 3): ?>
                                    <span class="bg-red-500 text-white px-2 py-1 rounded text-xs">Tier Violation!</span>
                                <?php elseif ($tier1 === $tier2): ?>
                                    <span class="bg-green-500 text-white px-2 py-1 rounded text-xs">Same Tier</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php else: ?>
            <!-- Error State -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="bg-red-50 rounded-lg p-6">
                    <h2 class="text-xl font-bold text-red-800 mb-4">❌ Tournament Generation Failed</h2>
                    <p class="text-red-700 mb-4"><?= htmlspecialchars($error); ?></p>
                    
                    <div class="bg-red-100 rounded p-4 text-sm text-red-800">
                        <strong>Why this might have failed:</strong> The combination of constraints is too restrictive 
                        for the number of participants. Complex tournaments with multiple overlapping constraints 
                        can sometimes create impossible scheduling scenarios. Consider:
                        <ul class="mt-2 ml-4 list-disc">
                            <li>Reducing the seed protection period</li>
                            <li>Relaxing the rest period requirements</li>
                            <li>Making tier balance constraints less strict</li>
                            <li>Adding more participants to increase scheduling flexibility</li>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Complex Constraint Code Example -->
        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Complex Constraint Implementation</h2>
            <div class="bg-gray-900 text-gray-100 rounded-lg p-4 overflow-x-auto">
                <pre><code><?= htmlspecialchars('<?php
// Building a complex tournament with multiple constraints
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    
    // Protect top seeds for early rounds
    ->add(new SeedProtectionConstraint(3, 0.4))
    
    // Ensure player rest between matches  
    ->add(new MinimumRestPeriodsConstraint(1))
    
    // Custom tier balancing
    ->add(new CallableConstraint(
        function($event, $context) {
            $participants = $event->getParticipants();
            $round = $event->getRound()->getNumber();
            
            if ($round <= 3) {
                $tier1 = $participants[0]->getMetadataValue(\'tier\');
                $tier2 = $participants[1]->getMetadataValue(\'tier\');
                
                // Avoid S-tier vs B-tier in early rounds
                return !(
                    ($tier1 === \'S\' && $tier2 === \'B\') ||
                    ($tier1 === \'B\' && $tier2 === \'S\')
                );
            }
            return true;
        },
        \'Early Round Tier Balance\'
    ))
    
    // Regional diversity in later rounds
    ->add(new CallableConstraint(
        function($event, $context) {
            $participants = $event->getParticipants();
            $round = $event->getRound()->getNumber();
            
            if ($round >= 5) {
                $region1 = $participants[0]->getMetadataValue(\'region\');
                $region2 = $participants[1]->getMetadataValue(\'region\');
                
                // Prefer cross-regional matches for variety
                return $region1 !== $region2 || rand(0, 100) < 30;
            }
            return true;
        },
        \'Late Round Regional Diversity\'
    ))
    
    ->build();

$scheduler = new RoundRobinScheduler($constraints);
$schedule = $scheduler->schedule($teams);'); ?></code></pre>
            </div>
        </div>

        <!-- Real-World Applications -->
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mt-8">
            <h3 class="text-lg font-semibold text-yellow-800 mb-3">🏆 Real-World Tournament Applications</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
                <div>
                    <h4 class="font-medium text-yellow-800 mb-2">Esports Tournaments</h4>
                    <ul class="space-y-1 text-yellow-700">
                        <li>• Seed protection for fair competition</li>
                        <li>• Regional representation requirements</li>
                        <li>• Streaming platform distribution</li>
                        <li>• Player fatigue management</li>
                        <li>• Sponsor tier considerations</li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-medium text-yellow-800 mb-2">Professional Sports</h4>
                    <ul class="space-y-1 text-yellow-700">
                        <li>• Television broadcast scheduling</li>
                        <li>• Travel time minimization</li>
                        <li>• Venue capacity matching</li>
                        <li>• Rivalry game distribution</li>
                        <li>• Player safety protocols</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <div class="flex justify-between mt-8">
            <a href="09-multi-leg-home-away.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition-colors">
                ← Previous: Multi-Leg Home/Away
            </a>
            <a href="11-error-handling.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg transition-colors">
                Next: Error Handling →
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
