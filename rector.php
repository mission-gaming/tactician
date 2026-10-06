<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Cast\RecastingRemovalRector;
use Rector\DeadCode\Rector\Concat\RemoveConcatAutocastRector;
use Rector\DeadCode\Rector\MethodCall\RemoveNullArgOnNullDefaultParamRector;
use Rector\DeadCode\Rector\MethodCall\RemoveNullNamedArgOnNullDefaultParamRector;
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
    // The prepared sets that are enabled. Code quality and type declarations
    // are not: the first rewrites null checks and loop bounds across the
    // generators for style alone, the second adds closure types taken from
    // PHPDoc, which turns a value that is tolerated today into a TypeError.
    ->withPreparedSets(deadCode: true, earlyReturn: true)
    // A rule is skipped where it would change what a consumer of the library
    // can see. Each entry says what the change would be.
    ->withSkip([
        // Promotion renames the constructor parameter to the property name,
        // which breaks a caller that passes the argument by name.
        ClassPropertyAssignToConstructorPromotionRector::class,
        // These hold public constants. A type on a public constant changes
        // its declaration, so it waits for a release that may change one.
        // The four below are dead code rules that remove what is written
        // on purpose.
        // Trusts a declared return type and drops the cast that PHPStan
        // asks for, on `(string) ini_get(...)` for example.
        RecastingRemovalRector::class,
        // Drops the cast from `'text' . (string) $value`; the explicit cast
        // is what says the conversion is intended.
        RemoveConcatAutocastRector::class,
        // An explicit `null` argument states what the call means, in a test
        // above all.
        RemoveNullArgOnNullDefaultParamRector::class,
        RemoveNullNamedArgOnNullDefaultParamRector::class,
        AddTypeToConstRector::class => [
            __DIR__ . '/src/Repack/Internal/IntervalPlacement.php',
            __DIR__ . '/src/Scheduling/BacktrackingRoundRobinGenerator.php',
            __DIR__ . '/src/Stage/TieDecision.php',
        ],
    ]);
