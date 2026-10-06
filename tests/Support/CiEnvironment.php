<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

/**
 * Whether a test run is on CI, read from the `CI` environment variable.
 *
 * Some tests can only be skipped where the machine cannot run them (no git,
 * no listening socket). On CI such a skip would let the check pass without
 * having run, so those tests fail there instead; this is the one place that
 * decides what counts as CI.
 */
final class CiEnvironment
{
    /**
     * CI services set the variable to `true` or `1`. Unset, empty, `0` and
     * `false` (in any case) all mean a local run.
     *
     * @param string|false $ci The CI environment variable as getenv() gives it, false when unset
     */
    public static function isCi(string|false $ci): bool
    {
        return $ci !== false && !in_array(strtolower($ci), ['', '0', 'false'], true);
    }
}
