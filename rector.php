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
    // One process, no parallel workers. Rector before 2.7.0 writes a cache
    // entry in place, not through a temporary file and a rename, and every
    // process loads and rewrites the entry for this configuration as it
    // starts. A worker that loads it while another is part way through
    // writing it reads half a PHP file and dies with a syntax error, which
    // the run reports as "Child process error": CI failed that way now and
    // then, on commits that changed nothing Rector reads. One process cannot
    // race with itself. Rector 2.7.0 writes the entry atomically: when the
    // locked version reaches it, this line can go
    // (tests/Feature/GateConfigurationTest.php says when).
    ->withoutParallel()
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
        // which breaks a caller that passes the argument by name. This is
        // the one class where the two names differ.
        ClassPropertyAssignToConstructorPromotionRector::class => [
            __DIR__ . '/src/Repack/Internal/StepBudget.php',
        ],
        // These hold public constants. A type on a public constant changes
        // its declaration, so it waits for a release that may change one.
        AddTypeToConstRector::class => [
            __DIR__ . '/src/Repack/Internal/IntervalPlacement.php',
            __DIR__ . '/src/Scheduling/BacktrackingRoundRobinGenerator.php',
            __DIR__ . '/src/Stage/TieDecision.php',
        ],
        // The dead code rules below remove what is written on purpose.
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
    ]);
