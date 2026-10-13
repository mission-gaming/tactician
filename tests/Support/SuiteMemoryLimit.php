<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

/**
 * The least memory a process of a test run may have.
 *
 * The suite peaks a few megabytes either side of PHP's default
 * `memory_limit` of 128M, so under that default a run ended with "Allowed
 * memory size exhausted" in a test that has nothing to do with memory, a
 * different one each time (tests run in random order), and passed when run
 * again. A run that collects coverage needs several times as much
 * ({@see self::FLOOR}).
 *
 * So a process of a test run raises its limit to the floor when the limit
 * PHP is configured with is lower, and leaves a limit that is higher, or no
 * limit at all, as it is. `tests/Pest.php` does it, which Pest loads in
 * every process that runs tests (the one process of an ordinary run, and
 * each worker of a run with `--parallel`) before the first test file.
 *
 * It is not an `<ini name="memory_limit">` in `phpunit.xml`, which was tried
 * first. PHPUnit sets such a value outright: it raises a low limit and
 * lowers a high one. PHP has no limit on a CI runner, and the `Coverage` job
 * needs more than the gigabyte that was stated: the job ended, after every
 * test had passed, where Pest reads the collected coverage back to report
 * it.
 *
 * The test process of a mutation is left alone. It has a limit of its own
 * for a reason of its own ({@see MutationMemoryLimit}), which may be lower
 * than the floor.
 */
final class SuiteMemoryLimit
{
    /**
     * Measured on PHP 8.4 with 6,121 tests, as the peak PHP reports for the
     * process (`memory_get_peak_usage(true)`): 125 to 127 MB for a plain
     * run, and for `composer test-coverage` 1,077 MB with Xdebug and 1,084
     * MB with PCOV. Until the last test a coverage run holds under 200 MB;
     * the rest is the step after it, where Pest reads the collected coverage
     * back to report it. The floor is a little under four times that, so
     * that the suite can grow for a long while before a run under PHP's
     * default needs it raised.
     */
    public const string FLOOR = '4G';

    /**
     * Raises the memory limit of this process to the floor, unless it is
     * already there or above it, or this is the test process of a mutation.
     */
    public static function raise(): void
    {
        if (getenv('PEST_MUTATION_TESTING') !== false) {
            return;
        }

        $configured = ini_get('memory_limit');
        if ($configured !== false && self::isBelowFloor($configured)) {
            ini_set('memory_limit', self::FLOOR);
        }
    }

    /**
     * Whether a `memory_limit` is lower than the floor.
     *
     * @param string $limit In PHP's notation: a number of bytes, with a suffix of K, M or G or without
     *     one; a negative number is no limit, and is never below the floor
     */
    public static function isBelowFloor(string $limit): bool
    {
        $bytes = ini_parse_quantity($limit);

        return $bytes >= 0 && $bytes < ini_parse_quantity(self::FLOOR);
    }
}
