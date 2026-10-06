<?php

declare(strict_types=1);

/**
 * Writes the summary of a mutation testing run as Markdown.
 *
 *     composer mutation 2>&1 | tee build/mutation.log
 *     php tests/bin/mutation-summary.php build/mutation.log
 *
 * The CI job `Mutation testing` appends what this prints to its job summary.
 * It exits 0 whatever the score is: the score is reported, not enforced. It
 * exits 1 only when the log it is given cannot be read.
 */

use MissionGaming\Tactician\Tests\Support\MutationReport;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$path = $_SERVER['argv'][1] ?? '';
$output = $path === '' || !is_file($path) ? false : file_get_contents($path);

if ($output === false) {
    fwrite(STDERR, "Usage: php tests/bin/mutation-summary.php <log of composer mutation>\n");

    exit(1);
}

echo MutationReport::fromOutput($output)->toMarkdown(), "\n";
