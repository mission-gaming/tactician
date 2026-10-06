<?php

declare(strict_types=1);

/**
 * Writes the summary of a mutation testing run as Markdown.
 *
 *     composer mutation 2>&1 | tee build/mutation.log
 *     php tests/bin/mutation-summary.php build/mutation.log
 *
 * The CI jobs `Mutation testing, <shard>` append what this prints to their
 * job summaries. Each mutates a part of the source, and gives the name of
 * its shard and the comma-separated paths it mutated as the second and
 * third argument, which head the summary:
 *
 *     php tests/bin/mutation-summary.php build/mutation.log "elimination" "src/A.php,src/B.php"
 *
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

$shard = trim((string) ($_SERVER['argv'][2] ?? ''));
$paths = array_values(array_filter(array_map(trim(...), explode(',', (string) ($_SERVER['argv'][3] ?? ''))), static fn(string $path): bool => $path !== ''));

echo MutationReport::fromOutput($output)->toMarkdown(25, $shard === '' ? null : $shard, $paths), "\n";
