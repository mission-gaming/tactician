<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use PHPUnit\Runner\Version;

// A gate that is configured to check nothing still passes. That happened
// here: rector.php enabled no rule set, so `composer rector` was green
// whatever the code looked like. These tests pin the settings that make each
// tool of the gate find something, so that loosening one is a failing test
// and not a quiet edit.
//
// The NEON and PHP configuration files are read as text on purpose: the
// project has no NEON parser among its dependencies, and loading rector.php
// would run it. Gap left knowingly - nothing here proves that a tool reads
// its file the way these patterns do; the gate itself shows that.

$root = dirname(__DIR__, 2);

/**
 * The value of a top-level key of the `parameters` section of a NEON file,
 * written on one line (`level: 9`).
 */
function gateNeonParameter(string $neon, string $key): ?string
{
    return preg_match('/^\t' . preg_quote($key, '/') . ':[ \t]*(\S+)[ \t]*$/m', $neon, $match) === 1 ? $match[1] : null;
}

/**
 * The entries of a list under a top-level key of the `parameters` section
 * of a NEON file, comments left out.
 *
 * @return list<string>
 */
function gateNeonList(string $neon, string $key): array
{
    if (preg_match('/^\t' . preg_quote($key, '/') . ':[ \t]*\n((?:\t\t[^\n]*\n|\n)*)/m', $neon, $match) !== 1) {
        return [];
    }

    preg_match_all('/^\t\t- (\S+)[ \t]*$/m', $match[1], $entries);

    return $entries[1];
}

/**
 * How many findings a PHPStan baseline file holds.
 */
function gateBaselineSize(string $neon): int
{
    preg_match_all('/^\t\t\tcount: (\d+)$/m', $neon, $counts);

    return (int) array_sum(array_map(intval(...), $counts[1]));
}

describe('PHPStan configuration', function () use ($root): void {
    it('analyses src/ at level 9 or higher', function () use ($root): void {
        $neon = (string) file_get_contents($root . '/phpstan.neon');
        $level = gateNeonParameter($neon, 'level');

        expect(gateNeonList($neon, 'paths'))->toBe(['src'])
            ->and($level === 'max' || (is_numeric($level) && (int) $level >= 9))->toBeTrue();
    });

    it('analyses the tests and what the examples share at level 8 or higher', function () use ($root): void {
        $neon = (string) file_get_contents($root . '/phpstan-tests.neon');
        $level = gateNeonParameter($neon, 'level');

        expect(gateNeonList($neon, 'paths'))->toBe(['tests', 'examples/support'])
            ->and($level === 'max' || (is_numeric($level) && (int) $level >= 8))->toBeTrue();
    });

    it('runs both configurations in the one Composer script', function () use ($root): void {
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($composer);
        Assert::assertIsArray($composer['scripts']['phpstan']);

        $commands = array_values(array_filter($composer['scripts']['phpstan'], is_string(...)));

        expect($commands)->toHaveCount(2)
            // The first has no configuration option, so it reads phpstan.neon.
            ->and($commands[0])->toStartWith('phpstan analyse')
            ->and($commands[0])->not->toContain('--configuration')
            ->and($commands[1])->toStartWith('phpstan analyse')
            ->and($commands[1])->toContain('--configuration=phpstan-tests.neon');
    });

    it('turns on the strict rules and the deprecation rules for both', function () use ($root): void {
        $common = (string) file_get_contents($root . '/phpstan-common.neon');
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($composer);

        expect($common)->toContain("\t- vendor/phpstan/phpstan-strict-rules/rules.neon\n")
            ->and($common)->toContain("\t- vendor/phpstan/phpstan-deprecation-rules/rules.neon\n")
            // No rule of the strict set is switched off.
            ->and($common)->not->toContain('strictRules:')
            ->and($composer['require-dev'])->toHaveKeys(['phpstan/phpstan-strict-rules', 'phpstan/phpstan-deprecation-rules']);

        foreach (['phpstan.neon', 'phpstan-tests.neon'] as $file) {
            expect((string) file_get_contents($root . '/' . $file))->toContain("\t- phpstan-common.neon\n");
        }
    });

    it('ignores no error outside the baselines, except the two Pest patterns', function () use ($root): void {
        // An ignored error is a finding nobody will see again. The baselines
        // are the one place for those, and they only shrink.
        expect((string) file_get_contents($root . '/phpstan.neon'))->not->toContain('ignoreErrors')
            ->and((string) file_get_contents($root . '/phpstan-common.neon'))->not->toContain('ignoreErrors');

        preg_match_all('/^\t\t\tmessage: (.*)$/m', (string) file_get_contents($root . '/phpstan-tests.neon'), $messages);

        expect($messages[1])->toHaveCount(2);

        foreach ($messages[1] as $message) {
            expect($message)->toContain('Pest\\\\PendingCalls\\\\TestCall');
        }
    });

    it('does not let a baseline grow', function (string $file, int $limit) use ($root): void {
        // The limit is the size the baseline had when the strict rules were
        // enabled. Lower it when findings are fixed; never raise it.
        $size = gateBaselineSize((string) file_get_contents($root . '/' . $file));

        expect($size)->toBeGreaterThan(0)
            ->and($size)->toBeLessThanOrEqual($limit);
    })->with([
        'src/' => ['phpstan-baseline.neon', 7],
        'tests/ and examples/support/' => ['phpstan-tests-baseline.neon', 30],
    ]);
});

describe('Rector configuration', function () use ($root): void {
    it('enables the PHP set of the Composer floor', function () use ($root): void {
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($composer);
        Assert::assertIsString($composer['require']['php']);

        if (preg_match('/^\^(\d+)\.(\d+)$/', $composer['require']['php'], $floor) !== 1) {
            Assert::fail('composer.json does not require PHP as `^<major>.<minor>`.');
        }

        // Without a rule set Rector changes nothing and reports success.
        expect((string) file_get_contents($root . '/rector.php'))
            ->toContain("->withPhpSets(php{$floor[1]}{$floor[2]}: true)");
    });

    it('gives a reason for every rule it skips', function () use ($root): void {
        $configuration = (string) file_get_contents($root . '/rector.php');

        if (preg_match('/->withSkip\(\[\n(.*?)\n    \]\)/s', $configuration, $skip) !== 1) {
            Assert::fail('rector.php has no withSkip() list in the expected form.');
        }

        $previous = '';
        $skipped = 0;

        foreach (explode("\n", $skip[1]) as $line) {
            // An entry of the list itself, not a path inside an entry.
            if (preg_match('/^        \w+::class/', $line) === 1) {
                ++$skipped;

                // The line above is the reason, or another rule that shares it.
                expect($previous)->toMatch('/^        (\/\/ \S|\w+::class,$)/');
            }

            $previous = $line;
        }

        expect($skipped)->toBeGreaterThan(0);
    });
});

describe('PHP-CS-Fixer configuration', function () use ($root): void {
    it('follows PER Coding Style and the migration set of the Composer floor, and enforces strict types', function () use ($root): void {
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($composer);
        Assert::assertIsString($composer['require']['php']);

        if (preg_match('/^\^(\d+)\.(\d+)$/', $composer['require']['php'], $floor) !== 1) {
            Assert::fail('composer.json does not require PHP as `^<major>.<minor>`.');
        }

        $configuration = require $root . '/.php-cs-fixer.dist.php';
        Assert::assertInstanceOf(PhpCsFixer\ConfigInterface::class, $configuration);

        $rules = $configuration->getRules();

        expect($rules['@PER-CS'] ?? null)->toBeTrue()
            ->and($rules["@PHP{$floor[1]}x{$floor[2]}Migration"] ?? null)->toBeTrue()
            ->and($rules['declare_strict_types'] ?? null)->toBeTrue()
            // declare_strict_types is a risky rule: without this it is not applied.
            ->and($configuration->getRiskyAllowed())->toBeTrue();
    });
});

describe('PHPUnit configuration', function () use ($root): void {
    it('fails a run on a warning, a notice, a deprecation and a risky test', function (string $attribute) use ($root): void {
        $configuration = simplexml_load_file($root . '/phpunit.xml');
        Assert::assertInstanceOf(SimpleXMLElement::class, $configuration);

        expect((string) $configuration[$attribute])->toBe('true');
    })->with([
        'failOnWarning',
        'failOnNotice',
        'failOnDeprecation',
        'failOnRisky',
        'failOnPhpunitDeprecation',
    ]);

    it('runs the tests in random order', function () use ($root): void {
        $configuration = simplexml_load_file($root . '/phpunit.xml');
        Assert::assertInstanceOf(SimpleXMLElement::class, $configuration);

        expect((string) $configuration['executionOrder'])->toBe('random');
    });

    it('names a schema of the installed PHPUnit major version', function () use ($root): void {
        // A minor release keeps reading the schema of an earlier minor of the
        // same major version, so only the major version is pinned.
        expect((string) file_get_contents($root . '/phpunit.xml'))
            ->toContain('xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/' . Version::majorVersionNumber() . '.');
    });
});
