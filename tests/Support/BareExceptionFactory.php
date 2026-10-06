<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use RuntimeException;

/**
 * Static factories that hand back an exception outside the library's
 * marker interface. tests/Feature/ExceptionMarkerTest.php throws them from
 * sample source to prove that {@see ThrowSites} rejects a factory by its
 * return type; nothing else uses this class.
 */
final class BareExceptionFactory
{
    public static function typed(): RuntimeException
    {
        return new RuntimeException('typed');
    }

    /**
     * Deliberately without a return type: a factory the scanner cannot read.
     *
     * @return RuntimeException
     */
    public static function untyped()
    {
        return new RuntimeException('untyped');
    }
}
