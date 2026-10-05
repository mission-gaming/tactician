<?php

declare(strict_types=1);

require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\Examples\Example;

// The index of the examples: a page of links for the browser. It computes
// nothing. tests/Feature/ExamplesTest.php fails when a script in this
// directory is missing from the list below, or listed here but absent.
$examples = [
    'Round robin' => [
        '01-basic-round-robin.php' => 'The smallest schedule: four participants, every pair meets once',
        '02-participants-and-metadata.php' => 'Seeds and free-form metadata on participants',
        '03-iterating-schedules.php' => 'Five ways to read a schedule: iterate, count, list, group by round, metadata',
        '09-multi-leg-home-away.php' => 'Two legs with the mirrored, repeated and shuffled leg strategies',
        '12-performance-patterns.php' => 'How a round robin grows with the field, and measured generation times',
    ],
    'Constraints' => [
        '04-basic-constraints.php' => 'Building a constraint set; a custom rule that moves one pairing',
        '05-seed-protection.php' => 'Keeping the top seeds apart for a fraction of the rounds',
        '06-rest-periods.php' => 'A minimum number of rounds between repeat meetings of a pair',
        '07-metadata-constraints.php' => 'Rules over participant metadata, and how an impossible rule fails',
        '08-custom-constraints.php' => 'Your own rule as a closure or as a class',
        '10-complex-tournament.php' => 'Seed protection, rest and a custom rule in one two-leg season',
        '11-error-handling.php' => 'The exceptions the scheduler throws and what they carry',
        '16-backtracking-generation.php' => 'Constraints the default generator cannot solve, solved by the opt-in search',
    ],
    'Results-driven formats' => [
        '13-swiss-stage-engine.php' => 'A Swiss stage paired round by round from the results',
        '14-groups-to-knockout.php' => 'Pools, qualification and a single-elimination bracket composed together',
        '18-stateless-web-flow.php' => 'A stage kept as JSON between stateless requests',
        '20-double-elimination.php' => 'A double-elimination bracket with a grand final reset',
        '21-standings-and-tiebreakers.php' => 'A standings table and a chain of tiebreakers',
    ],
    'After generation' => [
        '15-timeline-assignment.php' => 'Kickoff times and resources for every event, under time rules',
        '17-schedule-optimization.php' => 'Scoring schedule quality and keeping the best of many samples',
        '19-repacking-a-season.php' => 'Repacking outstanding events onto an irregular grid of sessions',
    ],
];

$body = '<p class="summary">Every example is one script that is both a browser page and a command-line script. '
    . 'Follow a link to see it as a page, or run it from the project root, for example '
    . '<code>php examples/01-basic-round-robin.php</code>, to print the same results as text. '
    . 'Each page lists the code that produced it.</p>';

foreach ($examples as $group => $scripts) {
    $body .= '<section><h2>' . Example::escape($group) . '</h2><ul>';
    foreach ($scripts as $script => $description) {
        $body .= '<li><a href="' . Example::escape($script) . '">' . Example::escape($script) . '</a>: ' . Example::escape($description) . '</li>';
    }
    $body .= '</ul></section>';
}

echo Example::page('Tactician examples', $body);
