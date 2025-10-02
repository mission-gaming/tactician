<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tactician Examples</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-6xl mx-auto px-6 py-8">
            <h1 class="text-4xl font-bold mb-2">🏆 Tactician Examples</h1>
            <p class="text-blue-100 text-lg">Interactive examples demonstrating tournament scheduling capabilities</p>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Welcome to Tactician</h2>
            <p class="text-gray-600 mb-4 leading-relaxed">
                Tactician is a modern PHP library for generating structured tournament schedules.
                These interactive examples demonstrate the library's capabilities from basic round-robin
                tournaments to complex multi-leg scenarios with advanced constraints.
            </p>
            <div class="bg-gray-100 rounded p-3 text-sm text-gray-700">
                <strong>Running:</strong> PHP <?= PHP_VERSION; ?> | 
                <strong>Server:</strong> <?= $_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT']; ?>
            </div>
        </div>

        <div class="space-y-8">
            <section class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-xl font-bold text-gray-800 mb-2 flex items-center">
                    <span class="mr-2">🎯</span> Basic Examples
                </h3>
                <p class="text-gray-600 mb-4">Core concepts and fundamental usage patterns</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <a href="01-basic-round-robin.php" class="block p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:shadow-md transition-all">
                        <h4 class="font-semibold text-gray-800 mb-2">Basic Round Robin</h4>
                        <p class="text-sm text-gray-600 mb-3">Simple 4-team tournament demonstrating core scheduling</p>
                        <span class="inline-block px-2 py-1 bg-green-100 text-green-800 text-xs rounded-full">Beginner</span>
                    </a>
                    <a href="02-participants-and-metadata.php" class="block p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:shadow-md transition-all">
                        <h4 class="font-semibold text-gray-800 mb-2">Participants & Metadata</h4>
                        <p class="text-sm text-gray-600 mb-3">Working with seeded participants and custom data</p>
                        <span class="inline-block px-2 py-1 bg-green-100 text-green-800 text-xs rounded-full">Beginner</span>
                    </a>
                    <a href="03-iterating-schedules.php" class="block p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:shadow-md transition-all">
                        <h4 class="font-semibold text-gray-800 mb-2">Schedule Iteration</h4>
                        <p class="text-sm text-gray-600 mb-3">Different ways to access and display schedule data</p>
                        <span class="inline-block px-2 py-1 bg-green-100 text-green-800 text-xs rounded-full">Beginner</span>
                    </a>
                </div>
            </section>

            <section class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-xl font-bold text-gray-800 mb-2 flex items-center">
                    <span class="mr-2">🔧</span> Constraint System
                </h3>
                <p class="text-gray-600 mb-4">Understanding and implementing tournament rules</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <a href="04-basic-constraints.php" class="block p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:shadow-md transition-all">
                        <h4 class="font-semibold text-gray-800 mb-2">Basic Constraints</h4>
                        <p class="text-sm text-gray-600 mb-3">NoRepeatPairings and simple constraint usage</p>
                        <span class="inline-block px-2 py-1 bg-yellow-100 text-yellow-800 text-xs rounded-full">Intermediate</span>
                    </a>
                    <a href="05-seed-protection.php" class="block p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:shadow-md transition-all">
                        <h4 class="font-semibold text-gray-800 mb-2">Seed Protection</h4>
                        <p class="text-sm text-gray-600 mb-3">Protecting high-seeded participants from early meetings</p>
                        <span class="inline-block px-2 py-1 bg-yellow-100 text-yellow-800 text-xs rounded-full">Intermediate</span>
                    </a>
                    <a href="06-rest-periods.php" class="block p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:shadow-md transition-all">
                        <h4 class="font-semibold text-gray-800 mb-2">Rest Periods</h4>
                        <p class="text-sm text-gray-600 mb-3">Ensuring minimum rest between participant encounters</p>
                        <span class="inline-block px-2 py-1 bg-yellow-100 text-yellow-800 text-xs rounded-full">Intermediate</span>
                    </a>
                    <a href="07-metadata-constraints.php" class="block p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:shadow-md transition-all">
                        <h4 class="font-semibold text-gray-800 mb-2">Metadata Constraints</h4>
                        <p class="text-sm text-gray-600 mb-3">Region-based and skill-level matching rules</p>
                        <span class="inline-block px-2 py-1 bg-yellow-100 text-yellow-800 text-xs rounded-full">Intermediate</span>
                    </a>
                    <a href="08-custom-constraints.php" class="block p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:shadow-md transition-all">
                        <h4 class="font-semibold text-gray-800 mb-2">Custom Constraints</h4>
                        <p class="text-sm text-gray-600 mb-3">Creating custom constraint functions</p>
                        <span class="inline-block px-2 py-1 bg-red-100 text-red-800 text-xs rounded-full">Advanced</span>
                    </a>
                </div>
            </section>

            <section class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-xl font-bold text-gray-800 mb-2 flex items-center">
                    <span class="mr-2">🚀</span> Advanced Features
                </h3>
                <p class="text-gray-600 mb-4">Real-world scenarios and complex tournament structures</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <a href="09-multi-leg-home-away.php" class="block p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:shadow-md transition-all">
                        <h4 class="font-semibold text-gray-800 mb-2">Multi-Leg Home/Away</h4>
                        <p class="text-sm text-gray-600 mb-3">Premier League style home and away seasons</p>
                        <span class="inline-block px-2 py-1 bg-red-100 text-red-800 text-xs rounded-full">Advanced</span>
                    </a>
                    <a href="10-complex-tournament.php" class="block p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:shadow-md transition-all">
                        <h4 class="font-semibold text-gray-800 mb-2">Complex Tournament</h4>
                        <p class="text-sm text-gray-600 mb-3">Gaming tournament with multiple constraint types</p>
                        <span class="inline-block px-2 py-1 bg-red-100 text-red-800 text-xs rounded-full">Advanced</span>
                    </a>
                    <a href="11-error-handling.php" class="block p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:shadow-md transition-all">
                        <h4 class="font-semibold text-gray-800 mb-2">Error Handling</h4>
                        <p class="text-sm text-gray-600 mb-3">Validation failures and exception demonstrations</p>
                        <span class="inline-block px-2 py-1 bg-yellow-100 text-yellow-800 text-xs rounded-full">Intermediate</span>
                    </a>
                    <a href="12-performance-patterns.php" class="block p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:shadow-md transition-all">
                        <h4 class="font-semibold text-gray-800 mb-2">Performance Patterns</h4>
                        <p class="text-sm text-gray-600 mb-3">Memory-efficient iteration for large tournaments</p>
                        <span class="inline-block px-2 py-1 bg-red-100 text-red-800 text-xs rounded-full">Advanced</span>
                    </a>
                </div>
            </section>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <h3 class="text-xl font-bold text-gray-800 mb-4">📚 Documentation</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="../docs/USAGE.md" target="_blank" class="flex items-center p-3 border border-gray-200 rounded-lg hover:border-blue-300 hover:bg-blue-50 transition-all">
                    <span class="mr-2">📖</span> Usage Guide
                </a>
                <a href="../docs/ARCHITECTURE.md" target="_blank" class="flex items-center p-3 border border-gray-200 rounded-lg hover:border-blue-300 hover:bg-blue-50 transition-all">
                    <span class="mr-2">🏗️</span> Architecture
                </a>
                <a href="../docs/ROADMAP.md" target="_blank" class="flex items-center p-3 border border-gray-200 rounded-lg hover:border-blue-300 hover:bg-blue-50 transition-all">
                    <span class="mr-2">🛣️</span> Roadmap
                </a>
                <a href="../README.md" target="_blank" class="flex items-center p-3 border border-gray-200 rounded-lg hover:border-blue-300 hover:bg-blue-50 transition-all">
                    <span class="mr-2">📋</span> README
                </a>
            </div>
        </div>
    </main>

    <footer class="bg-gray-800 text-white mt-16">
        <div class="max-w-6xl mx-auto px-6 py-6 text-center">
            <p>
                <strong>Tactician</strong> - Modern PHP Tournament Scheduling | 
                <a href="https://github.com/mission-gaming/tactician" target="_blank" class="text-blue-300 hover:text-blue-100">GitHub</a> |
                <a href="../LICENSE" target="_blank" class="text-blue-300 hover:text-blue-100">MIT License</a>
            </p>
        </div>
    </footer>
</body>
</html>
