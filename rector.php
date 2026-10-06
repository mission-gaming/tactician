<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // Every PHP set up to 8.3, the Composer floor. Named, not read from the
    // running PHP, so that the gate proposes the same changes on 8.3, 8.4
    // and 8.5 and never syntax from a later version.
    ->withPhpSets(php83: true)
    // A rule is skipped where it would change what a consumer of the library
    // can see. Each entry says what the change would be.
    ->withSkip([
        // Promotion renames the constructor parameter to the property name,
        // which breaks a caller that passes the argument by name.
        ClassPropertyAssignToConstructorPromotionRector::class,
        // These hold public constants. A type on a public constant changes
        // its declaration, so it waits for a release that may change one.
        AddTypeToConstRector::class => [
            __DIR__ . '/src/Repack/Internal/IntervalPlacement.php',
            __DIR__ . '/src/Scheduling/BacktrackingRoundRobinGenerator.php',
            __DIR__ . '/src/Stage/TieDecision.php',
        ],
    ]);
