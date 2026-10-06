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
 * What is wrong with the worker setting of a Rector configuration, given the
 * locked Rector version, or null when nothing is.
 *
 * Rector before 2.7.0 copies a cache entry onto its final path, and every
 * Rector process loads and rewrites the entry for the configuration when it
 * starts. Parallel workers start together, so one can load the entry while
 * another has written part of it: PHP then reports a syntax error in the
 * half-written file, the worker dies and the run fails with "Child process
 * error". Rector 2.7.0 writes the entry to a temporary file and renames it.
 * So before 2.7.0 the configuration must turn the workers off, and from
 * 2.7.0 it must not: one process takes about twice as long, and a workaround
 * that nothing asks to be removed stays for ever.
 *
 * A version that does not compare as 2.7.0 or later (a pre-release of
 * 2.7.0, a branch such as `dev-main`) is treated as one that still writes in
 * place. Comments and string literals are left out of the configuration
 * before it is searched, so only a call that PHP runs counts.
 */
function gateRectorWorkerProblem(string $lockedVersion, string $configuration): ?string
{
    $code = '';

    foreach (token_get_all($configuration) as $token) {
        if (!is_array($token)) {
            $code .= $token;

            continue;
        }

        if (!in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_CONSTANT_ENCAPSED_STRING], true)) {
            $code .= $token[1];
        }
    }

    $version = ltrim($lockedVersion, 'v');
    $turnsWorkersOff = str_contains($code, '->withoutParallel()');
    $turnsWorkersOn = str_contains($code, '->withParallel(');

    if (version_compare($version, '2.7.0', '>=')) {
        return $turnsWorkersOff
            ? "Rector {$version} writes its cache atomically, so its parallel workers are safe again: remove ->withoutParallel() and the comment above it from rector.php, then this test and gateRectorWorkerProblem()."
            : null;
    }

    return $turnsWorkersOff && !$turnsWorkersOn
        ? null
        : "Rector {$version} writes its cache in place, so parallel workers can read a half-written entry: rector.php must call ->withoutParallel() and must not call ->withParallel().";
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

    it('runs as one process for as long as the locked Rector writes its cache in place, and no longer', function () use ($root): void {
        // The gate failed on CI with "Child process error" on commits that
        // changed nothing Rector reads; gateRectorWorkerProblem() says why
        // and what it asks of rector.php on each side of Rector 2.7.0.
        $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($lock);
        Assert::assertIsArray($lock['packages-dev']);

        $locked = null;

        foreach ($lock['packages-dev'] as $package) {
            Assert::assertIsArray($package);

            if ($package['name'] === 'rector/rector') {
                Assert::assertIsString($package['version']);
                $locked = $package['version'];
            }
        }

        Assert::assertNotNull($locked, 'composer.lock does not lock rector/rector.');

        $problem = gateRectorWorkerProblem($locked, (string) file_get_contents($root . '/rector.php'));

        Assert::assertNull($problem, (string) $problem);
    });

    // The rule the test above applies, on configurations and versions that
    // this checkout does not have: without these, the rule is only ever run
    // on the one case that passes.
    it('accepts a configuration that turns the workers off while the cache is written in place', function (string $version): void {
        expect(gateRectorWorkerProblem($version, "<?php\n\nreturn RectorConfig::configure()\n    ->withoutParallel()\n    ->withPhpSets(php83: true);\n"))
            ->toBeNull();
    })->with([
        'the locked version when this was written' => ['2.6.7'],
        'a version with a v prefix' => ['v2.6.7'],
        'an earlier minor' => ['2.6.0'],
        'a release candidate of 2.7.0' => ['2.7.0-RC1'],
        'a beta of 2.7.0 with a v prefix' => ['v2.7.0-beta2'],
        'a branch' => ['dev-main'],
        'a branch alias' => ['2.7.x-dev'],
    ]);

    it('refuses a configuration that leaves the workers on while the cache is written in place', function (string $configuration): void {
        expect(gateRectorWorkerProblem('2.6.7', "<?php\n\nreturn RectorConfig::configure()\n{$configuration}    ->withPhpSets(php83: true);\n"))
            ->toContain('must call ->withoutParallel()');
    })->with([
        'no call at all' => [''],
        'the call in a line comment' => ["    // ->withoutParallel()\n"],
        'the call on a line of its own inside a block comment' => ["    /*\n    ->withoutParallel()\n    */\n"],
        'the call in a string' => ["    ->withSkip(['->withoutParallel()'])\n"],
        'the workers turned on again afterwards' => ["    ->withoutParallel()\n    ->withParallel()\n"],
        'the workers turned on again with arguments' => ["    ->withoutParallel()\n    ->withParallel(120, 4)\n"],
    ]);

    it('counts the call however it is laid out, and ignores a mention of the parallel call in a comment', function (string $configuration): void {
        expect(gateRectorWorkerProblem('2.6.7', "<?php\n\nreturn RectorConfig::configure()\n{$configuration}    ->withPhpSets(php83: true);\n"))
            ->toBeNull();
    })->with([
        'on the line of the call before it' => ["    ->withPaths([__DIR__ . '/src'])->withoutParallel()\n"],
        'with the arrow on the line above' => ["    ->\n        withoutParallel()\n"],
        'beside a comment that names ->withParallel()' => ["    // Not ->withParallel(): see below.\n    ->withoutParallel()\n"],
    ]);

    it('asks for the one-process setting to be removed once the locked Rector writes its cache atomically', function (string $version): void {
        $workersOff = "<?php\n\nreturn RectorConfig::configure()\n    ->withoutParallel()\n    ->withPhpSets(php83: true);\n";
        $default = "<?php\n\nreturn RectorConfig::configure()\n    // ->withoutParallel() was here.\n    ->withPhpSets(php83: true);\n";
        $workersOn = "<?php\n\nreturn RectorConfig::configure()\n    ->withParallel(120, 4)\n    ->withPhpSets(php83: true);\n";

        expect(gateRectorWorkerProblem($version, $workersOff))->toContain('remove ->withoutParallel()')
            ->and(gateRectorWorkerProblem($version, $default))->toBeNull()
            ->and(gateRectorWorkerProblem($version, $workersOn))->toBeNull();
    })->with([
        'the first version that does' => ['2.7.0'],
        'that version with a v prefix' => ['v2.7.0'],
        'a later patch' => ['2.7.1'],
        'a minor that sorts before 2.7 as text' => ['2.10.0'],
        'a release candidate of a later minor' => ['2.10.0-RC1'],
        'the next major' => ['3.0.0'],
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
