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
 * The files a NEON file includes, comments left out.
 *
 * @return list<string>
 */
function gateNeonIncludes(string $neon): array
{
    if (preg_match('/^includes:[ \t]*\n((?:\t[^\n]*\n|\n)*)/m', $neon, $match) !== 1) {
        return [];
    }

    preg_match_all('/^\t- (\S+)[ \t]*$/m', $match[1], $entries);

    return $entries[1];
}

/**
 * What is wrong with a Composer constraint as a floor for a package, or null
 * when it allows no version before the minimum.
 *
 * Only `^<major>.<minor>` and `^<major>.<minor>.<patch>` are read: that is
 * how composer.json writes every development tool, and the first version
 * such a constraint allows is the one it names. Any other form (a range, an
 * alternative, a branch) is refused, not interpreted, so that a constraint
 * this function cannot read never passes. The minimum is `<major>.<minor>`.
 */
function gateFloorProblem(string $package, string $constraint, string $minimum): ?string
{
    if (preg_match('/^\^(\d+)\.(\d+)(?:\.\d+)?$/', $constraint, $floor) !== 1) {
        return "{$package}: the constraint `{$constraint}` is not written as `^<major>.<minor>`, so its lowest version cannot be read.";
    }

    // Major and minor only, on both sides: version_compare() puts `2.7`
    // before `2.7.0`, so a floor with a patch part is not compared whole.
    return version_compare("{$floor[1]}.{$floor[2]}", $minimum, '>=')
        ? null
        : "{$package}: the constraint `{$constraint}` allows a version before {$minimum}.";
}

/**
 * gateFloorProblem() for a development dependency of this checkout.
 *
 * @throws JsonException When composer.json is not JSON
 * @throws PHPUnit\Framework\Exception When composer.json does not require the package as a string
 */
function gateDevelopmentFloorProblem(string $root, string $package, string $minimum): ?string
{
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    Assert::assertIsArray($composer);
    Assert::assertIsArray($composer['require-dev']);
    Assert::assertIsString($composer['require-dev'][$package] ?? null, "composer.json does not require {$package} for development.");

    return gateFloorProblem($package, $composer['require-dev'][$package], $minimum);
}

/**
 * How many entries the `ignoreErrors` list of a NEON file holds, in whatever
 * form an entry is written (a pattern on one line, or a block with `message`,
 * `messages`, `rawMessage` or `identifier`).
 */
function gateNeonIgnoredErrors(string $neon): int
{
    if (preg_match('/^\tignoreErrors:[ \t]*\n((?:\t\t[^\n]*(?:\n|$)|\n)*)/m', $neon, $match) !== 1) {
        return 0;
    }

    return (int) preg_match_all('/^\t\t-/m', $match[1]);
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
        $common = (string) file_get_contents($root . '/phpstan/common.neon');
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($composer);

        // A path in an included file is relative to that file's directory.
        expect($common)->toContain("\t- ../vendor/phpstan/phpstan-strict-rules/rules.neon\n")
            ->and($common)->toContain("\t- ../vendor/phpstan/phpstan-deprecation-rules/rules.neon\n")
            ->and($composer['require-dev'])->toHaveKeys(['phpstan/phpstan-strict-rules', 'phpstan/phpstan-deprecation-rules']);

        foreach (['phpstan.neon', 'phpstan-tests.neon'] as $file) {
            expect((string) file_get_contents($root . '/' . $file))->toContain("\t- phpstan/common.neon\n");
        }
    });

    it('switches off no rule of the strict set', function (string $file) use ($root): void {
        // A `strictRules` parameter is how a rule of the set is disabled, and
        // any of the three files can carry it: the two configurations
        // override what the shared file sets.
        expect((string) file_get_contents($root . '/' . $file))->not->toContain('strictRules');
    })->with(['phpstan/common.neon', 'phpstan.neon', 'phpstan-tests.neon']);

    it('includes the shared rules and one baseline, and nothing else', function (string $file, array $includes) use ($root): void {
        // Another included file could lower the level, switch a rule off or
        // ignore errors without any of the files read here changing.
        expect(gateNeonIncludes((string) file_get_contents($root . '/' . $file)))->toBe($includes);
    })->with([
        'phpstan.neon' => ['phpstan.neon', ['phpstan/common.neon', 'phpstan/baseline.neon']],
        'phpstan-tests.neon' => ['phpstan-tests.neon', ['phpstan/common.neon', 'phpstan/tests-baseline.neon']],
        'phpstan/common.neon' => ['phpstan/common.neon', [
            '../vendor/phpstan/phpstan-deprecation-rules/rules.neon',
            '../vendor/phpstan/phpstan-strict-rules/rules.neon',
        ]],
    ]);

    it('keeps only the two configurations that are run at the root', function () use ($root): void {
        // The shared include and the baselines are in phpstan/. A NEON file
        // added beside them or at the root is one no test here reads.
        $neonFiles = static function (string $directory): array {
            $found = glob($directory . '/*.neon');
            Assert::assertIsArray($found);

            return array_map(basename(...), $found);
        };

        expect($neonFiles($root))->toBe(['phpstan-tests.neon', 'phpstan.neon'])
            ->and($neonFiles($root . '/phpstan'))->toBe(['baseline.neon', 'common.neon', 'tests-baseline.neon']);
    });

    it('names in each baseline only files that exist', function (string $file) use ($root): void {
        // A baseline path is relative to the baseline's own directory. One
        // that points nowhere would be an entry that matches nothing.
        preg_match_all('/^\t\t\tpath: (\S+)$/m', (string) file_get_contents($root . '/' . $file), $paths);

        expect($paths[1])->not->toBe([]);

        foreach ($paths[1] as $path) {
            expect($path)->toStartWith('../')
                ->and(is_file($root . '/phpstan/' . $path))->toBeTrue();
        }
    })->with(['phpstan/baseline.neon', 'phpstan/tests-baseline.neon']);

    it('leaves no file of src/ out of the analysis', function () use ($root): void {
        // Excluding a file is the other way to stop seeing its findings.
        expect((string) file_get_contents($root . '/phpstan.neon'))->not->toContain('excludePaths')
            ->and((string) file_get_contents($root . '/phpstan/common.neon'))->not->toContain('excludePaths');
    });

    it('silences no finding with a comment under src/', function () use ($root): void {
        // PHPStan's ignore comment on a line does what an ignoreErrors entry
        // does, from inside the code. The tests use it twice, to pass a value
        // of the wrong type on purpose; the library has no such case. (The
        // tag is not spelt out here: PHPStan would read this comment as one.)
        $silenced = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
            Assert::assertInstanceOf(SplFileInfo::class, $file);

            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), '@phpstan-ignore')) {
                $silenced[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        expect($silenced)->toBe([]);
    });

    it('keeps a result cache for each configuration', function () use ($root): void {
        // With the one default file each run of `composer phpstan` discards
        // the cache the other configuration wrote, and both analyse every
        // file every time. Both files are in PHPStan's temporary directory,
        // outside the repository: PHP-CS-Fixer formats every PHP file it
        // finds under the root, and a result cache is one.
        $library = gateNeonParameter((string) file_get_contents($root . '/phpstan.neon'), 'resultCachePath');
        $tests = gateNeonParameter((string) file_get_contents($root . '/phpstan-tests.neon'), 'resultCachePath');

        expect($library)->toStartWith('%tmpDir%/')
            ->and($tests)->toStartWith('%tmpDir%/')
            ->and($tests)->not->toBe($library);
    });

    it('ignores no error outside the baselines, except the two Pest patterns', function () use ($root): void {
        // An ignored error is a finding nobody will see again. The baselines
        // are the one place for those, and they only shrink.
        expect((string) file_get_contents($root . '/phpstan.neon'))->not->toContain('ignoreErrors')
            ->and((string) file_get_contents($root . '/phpstan/common.neon'))->not->toContain('ignoreErrors');

        $tests = (string) file_get_contents($root . '/phpstan-tests.neon');

        preg_match_all('/^\t\t\tmessage: (.*)$/m', $tests, $messages);

        // Entries are counted as well as messages: an entry can name an
        // identifier or a raw message and have no `message` line at all.
        expect(gateNeonIgnoredErrors($tests))->toBe(2)
            ->and($messages[1])->toHaveCount(2);

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
        'src/' => ['phpstan/baseline.neon', 7],
        'tests/ and examples/support/' => ['phpstan/tests-baseline.neon', 28],
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

    it('enables the dead code and early return sets, for src/ and tests/', function () use ($root): void {
        $configuration = (string) file_get_contents($root . '/rector.php');

        expect($configuration)->toContain('->withPreparedSets(deadCode: true, earlyReturn: true)')
            ->and($configuration)->toContain("->withPaths([\n        __DIR__ . '/src',\n        __DIR__ . '/tests',\n    ])");
    });

    it('requires a Rector that writes its cache atomically, since its parallel workers are on', function () use ($root): void {
        // Rector before 2.7.0 copies a cache entry onto its final path, and
        // every worker loads and rewrites the entry for the configuration as
        // it starts, so one could load an entry another had half written:
        // the gate failed on CI with "Child process error" on commits that
        // changed nothing Rector reads. Rector 2.7.0 writes the entry to a
        // temporary file and renames it. rector.php leaves the workers on,
        // so no install may resolve to an earlier version, the lowest one
        // the constraint allows included.
        expect(gateDevelopmentFloorProblem($root, 'rector/rector', '2.7'))->toBeNull();
    });

    // The rule the test above applies, on constraints this checkout does not
    // have: without these, the rule is only ever run on the one that passes.
    it('accepts a floor at or above the minimum, however many parts it has', function (string $constraint): void {
        expect(gateFloorProblem('rector/rector', $constraint, '2.7'))->toBeNull();
    })->with([
        'the minimum' => ['^2.7'],
        'the minimum with a patch part' => ['^2.7.0'],
        'a later patch' => ['^2.7.3'],
        'a minor that sorts before 2.7 as text' => ['^2.10'],
        'the next major' => ['^3.0'],
    ]);

    it('refuses a floor below the minimum, and a constraint whose floor it cannot read', function (string $constraint, string $problem): void {
        expect(gateFloorProblem('rector/rector', $constraint, '2.7'))->toContain($problem);
    })->with([
        'the constraint before the upgrade' => ['^2.0', 'allows a version before 2.7'],
        'the minor before' => ['^2.6', 'allows a version before 2.7'],
        'the last patch of the minor before' => ['^2.6.7', 'allows a version before 2.7'],
        'an earlier major' => ['^1.2', 'allows a version before 2.7'],
        'an alternative that reaches below' => ['^2.0 || ^2.7', 'is not written as'],
        'a range' => ['>=2.7', 'is not written as'],
        'any version' => ['*', 'is not written as'],
        'a branch' => ['dev-main', 'is not written as'],
    ]);

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

// The weekly workflow fails when the library's lock file holds an abandoned
// package (`composer audit --abandoned=fail`), because that is where a
// maintainer finds out about one. A package that nothing can be done about
// would fail it every week. One has been added that way before: the benchmark
// runner requires an abandoned package, and it now has a lock file of its own
// (tools/phpbench/). These tests see the next one in the pull request that
// brings it, not a week after the merge.
describe('The locked development dependencies', function () use ($root): void {
    it('hold no package that is marked abandoned', function () use ($root): void {
        $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($lock);
        Assert::assertIsArray($lock['packages']);
        Assert::assertIsArray($lock['packages-dev']);

        $abandoned = [];
        foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
            Assert::assertIsArray($package);
            Assert::assertIsString($package['name']);
            // Composer writes `"abandoned": true`, or the name of the
            // replacement, into the lock file entry of such a package.
            if (array_key_exists('abandoned', $package) && $package['abandoned'] !== false) {
                $abandoned[] = $package['name'];
            }
        }

        expect($abandoned)->toBe([]);
    });

    it('do not hold the benchmark runner', function () use ($root): void {
        $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($lock);
        Assert::assertIsArray($lock['packages-dev']);

        $locked = array_column($lock['packages-dev'], 'name');

        expect($locked)->not->toContain('phpbench/phpbench')
            ->and($locked)->not->toContain('doctrine/annotations');
    });

    it('recognise an abandoned package in a lock file', function () use ($root): void {
        // The runner's lock file holds one, which shows that the mark the
        // first test looks for is the one Composer writes.
        $lock = json_decode((string) file_get_contents($root . '/tools/phpbench/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($lock);
        Assert::assertIsArray($lock['packages']);

        $abandoned = [];
        foreach ($lock['packages'] as $package) {
            Assert::assertIsArray($package);
            if (array_key_exists('abandoned', $package) && $package['abandoned'] !== false) {
                $abandoned[] = $package['name'];
            }
        }

        expect($abandoned)->toBe(['doctrine/annotations']);
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
        // The notice that a mock object has no expectation is one of these.
        'failOnPhpunitNotice',
        'failOnPhpunitWarning',
        // Output printed by a test makes it risky, so this is what lets
        // failOnRisky catch a stray echo.
        'beStrictAboutOutputDuringTests',
    ]);

    // A test marked `todo` is not run, and a run with one in it still
    // passes: none of the failOn* settings above reads it. So the mark is a
    // way to switch a failing test off without anyone seeing a failure. The
    // tests that carry it are named here, each with the behaviour it waits
    // for; another one fails this test until it is listed with its reason.
    it('marks as todo only the tests named here', function () use ($root): void {
        $mark = '->' . 'todo(';
        $marked = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/tests', FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            Assert::assertInstanceOf(SplFileInfo::class, $file);
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $count = substr_count((string) file_get_contents($file->getPathname()), $mark);
            if ($count > 0) {
                $marked[str_replace($root . '/', '', $file->getPathname())] = $count;
            }
        }

        expect($marked)->toBe([
            // "schedules the two legs with roles the constraints accept":
            // a schedule exists and generation does not find it
            'tests/Feature/ComplexConstraintTest.php' => 1,
        ]);
    });

    it('is valid against the schema of the installed PHPUnit', function () use ($root): void {
        // PHPUnit does not stop for a configuration that fails validation:
        // it warns and carries on, so a misspelt failOn* attribute is a flag
        // that is quietly off.
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->load($root . '/phpunit.xml');
            $valid = $loaded && $document->schemaValidate($root . '/vendor/phpunit/phpunit/phpunit.xsd');
            $errors = array_map(fn(LibXMLError $error): string => trim($error->message), libxml_get_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        expect($errors)->toBe([])
            ->and($valid)->toBeTrue();
    });

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

// `composer mutation` runs a part of the suite, named in a configuration of
// its own (phpunit.mutation.xml says why). It is not part of the gate, and
// it must not be a quieter suite than the gate's: a test that fails on a
// warning in `composer test` fails on it in a mutation run as well.
describe('Mutation testing configuration', function () use ($root): void {
    it('differs from phpunit.xml in the tests it names and in nothing else', function () use ($root): void {
        $gate = simplexml_load_file($root . '/phpunit.xml');
        $mutation = simplexml_load_file($root . '/phpunit.mutation.xml');
        Assert::assertInstanceOf(SimpleXMLElement::class, $gate);
        Assert::assertInstanceOf(SimpleXMLElement::class, $mutation);

        $attributes = static function (SimpleXMLElement $configuration): array {
            $attributes = [];
            foreach ($configuration->attributes() ?? [] as $name => $value) {
                $attributes[(string) $name] = (string) $value;
            }
            ksort($attributes);

            return $attributes;
        };

        expect($attributes($mutation))->toBe($attributes($gate))
            ->and($mutation->source->asXML())->toBe($gate->source->asXML());
    });

    it('is valid against the schema of the installed PHPUnit', function () use ($root): void {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->load($root . '/phpunit.mutation.xml');
            $valid = $loaded && $document->schemaValidate($root . '/vendor/phpunit/phpunit/phpunit.xsd');
            $errors = array_map(fn(LibXMLError $error): string => trim($error->message), libxml_get_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        expect($errors)->toBe([])
            ->and($valid)->toBeTrue();
    });

    it('names only test files and directories that exist, each once', function () use ($root): void {
        $mutation = simplexml_load_file($root . '/phpunit.mutation.xml');
        Assert::assertInstanceOf(SimpleXMLElement::class, $mutation);

        $named = [];
        foreach ($mutation->testsuites->testsuite as $suite) {
            foreach ($suite->directory as $directory) {
                expect(is_dir($root . '/' . $directory))->toBeTrue("{$directory} is not a directory")
                    ->and((string) $directory['suffix'])->toBe('Test.php');
                $named[] = (string) $directory;
            }
            foreach ($suite->file as $file) {
                expect(is_file($root . '/' . $file))->toBeTrue("{$file} is not a file")
                    ->and((string) $file)->toEndWith('Test.php');
                $named[] = (string) $file;
            }
        }

        expect($named)->not->toBeEmpty()
            ->and(array_unique($named))->toHaveCount(count($named));

        // The unit tests of both mutated directories are among them
        expect($named)->toContain('./tests/Unit/Scheduling', './tests/Unit/Repack');

        // A file inside a directory that is named as well would run twice
        foreach ($named as $path) {
            foreach ($named as $other) {
                expect($path !== $other && str_starts_with($path, $other . '/'))->toBeFalse("{$path} is inside {$other}");
            }
        }
    });
});
