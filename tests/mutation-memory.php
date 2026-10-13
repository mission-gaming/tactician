<?php

declare(strict_types=1);

/**
 * Run by PHP before the script of every process of `composer mutation`
 * (`auto_prepend_file`, which `tests/bin/run-mutation.php` sets for the run),
 * and by nothing else.
 *
 * The test process of a mutation gets a memory limit and logs that it ended
 * there. {@see MissionGaming\Tactician\Tests\Support\MutationMemoryLimit}
 * says what it does and why it has to be loaded this early.
 *
 * The autoloader is not loaded yet, and this file does not load it: the
 * class is one file with no dependency. Everything is inside a function,
 * because a prepended file shares its variables with the script.
 */

use MissionGaming\Tactician\Tests\Support\MutationMemoryLimit;

require_once __DIR__ . '/Support/MutationMemoryLimit.php';

(static function (): void {
    /** @var list<string> $arguments */
    $arguments = array_values(array_filter((array) ($_SERVER['argv'] ?? []), is_string(...)));

    MutationMemoryLimit::install(dirname(__DIR__), $arguments);
})();
