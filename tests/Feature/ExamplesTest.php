<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

// Examples are executable documentation and must not ship without validation.
// This test auto-discovers every script in examples/, so a new example is
// covered the moment it is added: each script must run cleanly under full
// error reporting. Stale examples have shipped fatal errors before - see the
// git history for examples 07, 09, and 11.

$exampleScripts = glob(dirname(__DIR__, 2) . '/examples/*.php') ?: [];

it('discovers example scripts to validate', function () use ($exampleScripts): void {
    expect($exampleScripts)->not->toBeEmpty();
});

it('runs cleanly under full error reporting', function (string $script): void {
    $command = sprintf(
        '%s -d error_reporting=E_ALL -d display_errors=1 %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($script)
    );

    exec($command, $outputLines, $exitCode);
    $output = implode("\n", $outputLines);
    $name = basename($script);

    Assert::assertSame(0, $exitCode, "Example {$name} exited with code {$exitCode}:\n{$output}");

    foreach (['Fatal error', 'Parse error', 'Uncaught', 'Warning:', 'Deprecated:', 'Notice:'] as $marker) {
        Assert::assertStringNotContainsString(
            $marker,
            $output,
            "Example {$name} emitted '{$marker}' in its output"
        );
    }
})->with(array_combine(
    array_map(fn (string $script) => basename($script), $exampleScripts),
    array_map(fn (string $script) => [$script], $exampleScripts)
));
