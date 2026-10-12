<?php

declare(strict_types=1);

/**
 * Loaded by Composer's autoloader in a development checkout
 * (`autoload-dev.files`), which is before anything else in every PHP process
 * that uses the library's `vendor/autoload.php`.
 *
 * It matters to `composer mutation` only: the test process of a mutation
 * gets a memory limit and logs that it ended there. In every other process
 * it does nothing. {@see MissionGaming\Tactician\Tests\Support\MutationMemoryLimit}
 * says what it does and why it has to be loaded this early.
 */

use MissionGaming\Tactician\Tests\Support\MutationMemoryLimit;

/** @var list<string> $arguments */
$arguments = array_values(array_filter((array) ($_SERVER['argv'] ?? []), is_string(...)));

MutationMemoryLimit::install(dirname(__DIR__), $arguments);
