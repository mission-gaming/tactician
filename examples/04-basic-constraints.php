<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Create participants
$participants = [
    new Participant('celtic', 'Celtic FC', 1),
    new Participant('athletic', 'Athletic Bilbao', 2),
    new Participant('livorno', 'AS Livorno', 3),
    new Participant('redstar', 'Red Star Belgrade', 4),
    new Participant('stpauli', 'FC St. Pauli', 5),
    new Participant('clapton', 'Clapton Community FC', 6),
];

// Create different constraint scenarios
$scenarios = [
    'No Constraints' => [
        'description' => 'Schedule without any constraints applied',
        'constraints' => ConstraintSet::create()->build(),
        'explanation' => 'This allows any valid round-robin pairing without restrictions.',
    ],
    'No Repeat Pairings' => [
        'description' => 'Prevent teams from playing each other more than once',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->build(),
        'explanation' => 'The most common constraint - ensures each team plays every other team exactly once.',
    ],
    'Custom Constraint' => [
        'description' => 'Custom predicate function to control pairings',
        'constraints' => ConstraintSet::create()
            ->noRepeatPairings()
            ->custom(
                function ($event, $context) {
                    $participants = $event->getParticipants();
                    // Don't allow the first and last seeded teams to play in early rounds
                    if ($event->getRound()->getNumber() <= 2) {
                        $seeds = [$participants[0]->getSeed(), $participants[1]->getSeed()];

                        return !(in_array(1, $seeds) && in_array(6, $seeds));
                    }

                    return true;
                },
                'Protect Seed Extremes'
            )
            ->build(),
        'explanation' => 'Prevents the highest and lowest seeded teams from meeting in the first 2 rounds.',
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
            'explanation' => $scenario['explanation'],
            'total_events' => count($schedule),
            'total_rounds' => $schedule->getMetadataValue('total_rounds'),
            'constraints_count' => count($scenario['constraints']->getConstraints()),
        ];
    } catch (Exception $e) {
        $results[$name] = [
            'status' => 'failed',
            'description' => $scenario['explanation'],
            'explanation' => $scenario['explanation'],
            'error' => $e->getMessage(),
        ];
    }
}

// Function to check if an event has the extreme seed pairing
function hasExtremeSeedPairing($event)
{
    $participants = $event->getParticipants();
    $seeds = [$participants[0]->getSeed(), $participants[1]->getSeed()];

    return in_array(1, $seeds) && in_array(6, $seeds);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Basic Constraints - Tactician Examples</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-6xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">🔧 Basic Constraints</h1>
                    <p class="text-blue-100">NoRepeatPairings and simple constraint usage</p>
                </div>
                <a href="index.php" class="bg-blue-700 hover:bg-blue-800 px-4 py-2 rounded-lg transition-colors">
                    ← Back to Examples
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
        <!-- Constraints Concept ---->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Understanding Constraints</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <p class="text-gray-600 mb-4 leading-relaxed">
                        Constraints are rules that control how tournaments are scheduled. They ensure that 
                        certain conditions are met, such as preventing repeat pairings or protecting 
                        high-seeded participants from meeting early.
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        Tactician provides built-in constraints for common scenarios, plus the ability 
                        to create custom constraints using callback functions.
                    </p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-3">Constraint Types</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-blue-500 rounded-full mr-2"></span>
                            <span>Built-in constraints (NoRepeatPairings, etc.)</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-green-500 rounded-full mr-2"></span>
                            <span>Seed-based constraints (SeedProtection)</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-purple-500 rounded-full mr-2"></span>
                            <span>Timing constraints (MinimumRestPeriods)</span>
                        </div>
                        <div class="flex items-center">
                            <span class="w-3 h-3 bg-orange-500 rounded-full mr-2"></span>
                            <span>Custom constraints (user-defined functions)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tournament Participants ---->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Tournament Participants</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($participants as $participant): ?>
                    <div class="border border-gray-200 rounded-lg p-4 text-center">
                        <div class="font-semibold text-gray-800"><?= htmlspecialchars($participant->getLabel()); ?></div>
                        <div class="text-sm text-gray-500">Seed #<?= $participant->getSeed(); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Constraint Scenarios Comparison ---->
        <div class="space-y-8">
            <?php foreach ($results as $scenarioName => $result): ?>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800"><?= htmlspecialchars($scenarioName); ?></h3>
                            <p class="text-gray-600"><?= htmlspecialchars($result['description']); ?></p>
                        </div>
                        <?php if ($result['status'] === 'success'): ?>
                            <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm">
                                <?= $result['constraints_count']; ?> constraint<?= $result['constraints_count'] === 1 ? '' : 's'; ?>
                            </span>
                        <?php else: ?>
                            <span class="bg-red-100 text-red-800 px-3 py-1 rounded-full text-sm">
                                Failed
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($result['status'] === 'failed'): ?>
                        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                            <div class="text-sm text-red-800 mb-2">
                                <strong>Constraint Failure:</strong> This constraint configuration cannot generate a complete schedule.
                            </div>
                            <div class="text-xs text-red-700 mt-2">
                                <?= htmlspecialchars($result['error']); ?>
                            </div>
                            <div class="mt-3 text-xs text-red-700">
                                <strong>Why:</strong> The "seed extremes" constraint prevents seed #1 and #6 from playing in rounds 1-2, but with 6 teams,
                                it's mathematically impossible to create a complete schedule without this pairing occurring in early rounds.
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="bg-blue-50 rounded-lg p-4 mb-4">
                            <div class="text-sm text-blue-800 mb-2">
                                <strong>How it works:</strong> <?= htmlspecialchars($result['explanation']); ?>
                            </div>
                            <div class="grid grid-cols-3 gap-4 text-sm">
                                <div>
                                    <span class="text-blue-700">Total Events:</span>
                                    <span class="font-medium ml-1"><?= $result['total_events']; ?></span>
                                </div>
                                <div>
                                    <span class="text-blue-700">Total Rounds:</span>
                                    <span class="font-medium ml-1"><?= $result['total_rounds']; ?></span>
                                </div>
                                <div>
                                    <span class="text-blue-700">Algorithm:</span>
                                    <span class="font-medium ml-1"><?= htmlspecialchars($result['schedule']->getMetadataValue('algorithm')); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Show first few matches to demonstrate constraint effects -->
                        <div class="space-y-3">
                            <h4 class="font-medium text-gray-800">Sample Matches (First 6)</h4>
                            <?php
                            $sampleCount = 0;
                        foreach ($result['schedule'] as $event):
                            if ($sampleCount >= 6) {
                                break;
                            }
                            $eventParticipants = $event->getParticipants();
                            $isExtremePairing = hasExtremeSeedPairing($event);
                            ++$sampleCount;
                            ?>
                            <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg <?= $isExtremePairing ? 'bg-yellow-50 border-yellow-300' : 'hover:bg-gray-50'; ?> transition-colors">
                                <span class="bg-gray-100 text-gray-600 px-2 py-1 rounded text-sm">
                                    Round <?= $event->getRound()->getNumber(); ?>
                                </span>
                                <div class="flex items-center space-x-4">
                                    <div class="text-center">
                                        <div class="font-medium text-gray-800"><?= htmlspecialchars($eventParticipants[0]->getLabel()); ?></div>
                                        <div class="text-xs text-gray-500">Seed #<?= $eventParticipants[0]->getSeed(); ?></div>
                                    </div>
                                    <div class="text-gray-400 font-bold">VS</div>
                                    <div class="text-center">
                                        <div class="font-medium text-gray-800"><?= htmlspecialchars($eventParticipants[1]->getLabel()); ?></div>
                                        <div class="text-xs text-gray-500">Seed #<?= $eventParticipants[1]->getSeed(); ?></div>
                                    </div>
                                </div>
                                <?php if ($isExtremePairing): ?>
                                    <span class="bg-yellow-500 text-white px-2 py-1 rounded text-xs">
                                        Extreme Seeds
                                    </span>
                                <?php else: ?>
                                    <div class="w-20"></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if ($scenarioName === 'Custom Constraint'): ?>
                            <?php
                                // Count extreme pairings in early rounds for this scenario
                                $earlyExtremeCount = 0;
                            foreach ($result['schedule'] as $event) {
                                if ($event->getRound()->getNumber() <= 2 && hasExtremeSeedPairing($event)) {
                                    ++$earlyExtremeCount;
                                }
                            }
                            ?>
                            <div class="bg-green-50 border border-green-200 rounded p-3 text-sm text-green-800">
                                <strong>Constraint Effect:</strong> 
                                <?= $earlyExtremeCount === 0 ? 'Successfully prevented' : 'Found ' . $earlyExtremeCount; ?> 
                                extreme seed pairings (Seeds #1 vs #6) in rounds 1-2.
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Constraint Builder Pattern ---->
        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Building Constraint Sets</h2>
            <p class="text-gray-600 mb-4">
                Tactician uses a fluent builder pattern to create constraint sets. You can chain multiple constraints together.
            </p>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h3 class="font-semibold text-gray-800 mb-3">Simple Constraint Set</h3>
                    <div class="bg-gray-900 text-gray-100 rounded-lg p-4 text-sm">
                        <pre><code><?= htmlspecialchars('$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->build();'); ?></code></pre>
                    </div>
                </div>
                
                <div>
                    <h3 class="font-semibold text-gray-800 mb-3">Multiple Constraints</h3>
                    <div class="bg-gray-900 text-gray-100 rounded-lg p-4 text-sm">
                        <pre><code><?= htmlspecialchars('$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(new SeedProtectionConstraint(2, 0.5))
    ->add(new MinimumRestPeriodsConstraint(2))
    ->build();'); ?></code></pre>
                    </div>
                </div>
                
                <div>
                    <h3 class="font-semibold text-gray-800 mb-3">Custom Constraint</h3>
                    <div class="bg-gray-900 text-gray-100 rounded-lg p-4 text-sm">
                        <pre><code><?= htmlspecialchars('$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->custom(
        function($event, $context) {
            // Your custom logic here
            return true; // or false
        },
        \'Custom Rule Name\'
    )
    ->build();'); ?></code></pre>
                    </div>
                </div>
                
                <div>
                    <h3 class="font-semibold text-gray-800 mb-3">Using the Constraint Set</h3>
                    <div class="bg-gray-900 text-gray-100 rounded-lg p-4 text-sm">
                        <pre><code><?= htmlspecialchars('$scheduler = new RoundRobinScheduler($constraints);
$schedule = $scheduler->generateSchedule($participants);

// The scheduler validates all constraints
// during schedule generation'); ?></code></pre>
                    </div>
                </div>
            </div>
        </div>

        <!-- Constraint Best Practices ---->
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mt-8">
            <h3 class="text-lg font-semibold text-yellow-800 mb-3">💡 Constraint Best Practices</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
                <div>
                    <h4 class="font-medium text-yellow-800 mb-2">Performance Tips</h4>
                    <ul class="space-y-1 text-yellow-700">
                        <li>• Start with NoRepeatPairings for most tournaments</li>
                        <li>• Add constraints incrementally and test</li>
                        <li>• More constraints = longer generation time</li>
                        <li>• Custom constraints should be efficient</li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-medium text-yellow-800 mb-2">Common Patterns</h4>
                    <ul class="space-y-1 text-yellow-700">
                        <li>• NoRepeatPairings is almost always needed</li>
                        <li>• Seed protection for competitive tournaments</li>
                        <li>• Rest periods for multi-day events</li>
                        <li>• Custom constraints for special rules</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Navigation ---->
        <div class="flex justify-between mt-8">
            <a href="03-iterating-schedules.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition-colors">
                ← Previous: Schedule Iteration
            </a>
            <a href="05-seed-protection.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg transition-colors">
                Next: Seed Protection →
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
