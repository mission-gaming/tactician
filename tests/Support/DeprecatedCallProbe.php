<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use RuntimeException;

/**
 * Deprecated methods that misbehave on purpose, for the tests of
 * {@see DeprecatedCall} in tests/Feature/DeprecationsTest.php: each one
 * raises something of its own, or throws, while it runs.
 */
final class DeprecatedCallProbe
{
    #[\Deprecated(message: 'a probe; there is no replacement', since: '0.2.2')]
    public function raising(int $level, string $message): string
    {
        trigger_error($message, $level);

        return 'returned';
    }

    /**
     * @throws RuntimeException Always
     */
    #[\Deprecated(message: 'a probe; there is no replacement', since: '0.2.2')]
    public function throwing(): never
    {
        throw new RuntimeException('thrown by the deprecated method');
    }
}
