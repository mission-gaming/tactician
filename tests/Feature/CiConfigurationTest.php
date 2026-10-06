<?php

declare(strict_types=1);

use MissionGaming\Tactician\Tests\Support\DocumentationSnippets;
use PHPUnit\Framework\Assert;

// The CI hardening is configuration, so nothing in the library exercises it.
// These tests pin its guarantees: least-privilege token permissions, actions
// referenced by immutable commit SHA, Dependabot coverage that keeps those
// pins current, test jobs that run, under the check names branch protection
// requires, for every change set, cancellation limited to superseded pull
// request runs, a dependency audit that is visible without being one of those
// required checks, and a scheduled workflow that stays off pull requests.
//
// The files are read as text on purpose: the project has no YAML parser among
// its dependencies. Gap left knowingly - nothing here proves the files are
// valid YAML or that GitHub accepts them; only a run on GitHub shows that.

$root = dirname(__DIR__, 2);
$workflows = glob($root . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE) ?: [];
$workflowDataset = array_combine(
    array_map(basename(...), $workflows),
    array_map(fn(string $workflow) => [$workflow], $workflows)
);

it('discovers workflows to check', function () use ($workflows): void {
    expect($workflows)->not->toBeEmpty();
});

it('pins every action to a commit SHA with its release in a trailing comment', function (string $workflow): void {
    $name = basename($workflow);
    $lines = file($workflow, FILE_IGNORE_NEW_LINES) ?: [];
    $references = 0;

    foreach ($lines as $index => $line) {
        if (preg_match('/^\s*(?:-\s+)?uses:\s*(\S+)(.*)$/', $line, $matches) !== 1) {
            continue;
        }

        [, $reference, $trailer] = $matches;

        // Actions inside this repository are versioned with the workflow.
        if (str_starts_with($reference, './')) {
            continue;
        }

        ++$references;
        $where = sprintf('%s line %d (%s)', $name, $index + 1, $reference);

        Assert::assertMatchesRegularExpression(
            '/^[\w.-]+\/[\w.\/-]+@[0-9a-f]{40}$/',
            $reference,
            "{$where} is not pinned to a full commit SHA"
        );
        Assert::assertMatchesRegularExpression(
            '/^\s+#\s*v?\d+\.\d+\.\d+\s*$/',
            $trailer,
            "{$where} does not name its release in a trailing comment"
        );
    }

    Assert::assertGreaterThan(0, $references, "{$name} references no actions; the check matched nothing");
})->with($workflowDataset);

// An update that reaches one reference and misses another leaves two releases
// of one action in use: the jobs then differ in runtime and behaviour, and the
// pin check above passes all the same, because each reference is still a
// commit SHA with a release beside it.
it('pins each action to one release everywhere it is used', function () use ($workflows): void {
    $pins = [];

    foreach ($workflows as $workflow) {
        $lines = explode("\n", (string) file_get_contents($workflow));

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:-\s+)?uses:\s*([^\s@]+)@(\S+)\s*#\s*(\S+)\s*$/', $line, $matches) !== 1) {
                continue;
            }

            [, $action, $commit, $release] = $matches;
            $pins[$action]["{$commit} # {$release}"][] = sprintf('%s line %d', basename($workflow), $index + 1);
        }
    }

    // Used in both workflows, so the check has something to compare
    expect($pins)->toHaveKey('actions/checkout');
    expect(array_unique(array_map(
        fn(string $where): string => explode(' ', $where)[0],
        array_merge(...array_values($pins['actions/checkout']))
    )))->toHaveCount(count($workflows));

    foreach ($pins as $action => $releases) {
        $found = [];
        foreach ($releases as $pin => $places) {
            $found[] = $pin . ' (' . implode(', ', $places) . ')';
        }

        Assert::assertCount(1, $releases, "{$action} is pinned to more than one release: " . implode('; ', $found));
    }
});

it('grants the workflow token read access to contents and nothing else by default', function (string $workflow): void {
    $name = basename($workflow);
    $contents = (string) file_get_contents($workflow);

    // The top-level block, with no further scope indented beneath it.
    Assert::assertMatchesRegularExpression(
        '/^permissions:\n  contents: read\n(?!  \S)/m',
        $contents,
        "{$name} does not declare top-level `permissions: contents: read` alone"
    );
})->with($workflowDataset);

it('grants no job a write scope', function (string $workflow): void {
    $name = basename($workflow);
    $contents = (string) file_get_contents($workflow);

    Assert::assertDoesNotMatchRegularExpression(
        '/^\s*permissions:\s*write-all\s*$/m',
        $contents,
        "{$name} grants every scope with write-all"
    );

    preg_match_all('/^( +)permissions:\n((?:\1 +\S.*\n)+)/m', $contents, $blocks);

    foreach ($blocks[2] as $block) {
        Assert::assertDoesNotMatchRegularExpression(
            '/:\s*write\s*$/m',
            $block,
            "{$name} grants a job a write scope"
        );
    }
})->with($workflowDataset);

// The suite reads documentation as well as code: it executes the code blocks
// of README.md and docs/USAGE.md and checks other Markdown files against the
// repository. So the test and coverage jobs must run for every change set, a
// documentation-only one included, or a broken snippet could merge unseen.
//
// The workflow once had a documentation-only fast path built on a paths
// filter. It never skipped anything (the filter's `'**'` pattern matched
// every file under the action's default quantifier), and had it worked it
// would have skipped exactly the changes the documentation tests exist for.
// These tests keep any such gate from coming back.
//
// No condition is allowed, not even one that skips a draft pull request.
// GitHub reports a job skipped by a condition as passed, and that result
// satisfies a required check. Nothing documented says that the skipped result
// stops counting once the draft is marked as ready, before the real run for
// the same commit has finished, so the jobs simply always run.
it('runs the test and coverage jobs for every change set, a draft pull request included', function (string $job) use ($root): void {
    $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');

    if (preg_match('/^  ' . preg_quote($job, '/') . ":\n((?:(?:    .*)?\n)*)/m", $workflow, $definition) !== 1) {
        Assert::fail("ci.yml has no `{$job}` job");
    }

    // A job-level condition or a dependency on another job can skip the job
    expect($definition[1])->toContain("    steps:\n");
    Assert::assertDoesNotMatchRegularExpression('/^    if:/m', $definition[1], "The `{$job}` job is conditional");
    Assert::assertDoesNotMatchRegularExpression('/^    needs:/m', $definition[1], "The `{$job}` job waits on another job");

    // Nor may the job pass without its work being done: a tolerated failure
    // reports the required check as passed just as a skipped job does
    Assert::assertDoesNotMatchRegularExpression('/^\s*continue-on-error:/m', $definition[1], "The `{$job}` job tolerates a failure");
    Assert::assertStringNotContainsString('draft', $definition[1], "The `{$job}` job reads the draft state of the pull request");
})->with(['test', 'coverage']);

it('triggers on every push to main and every pull request, without a path or change filter', function () use ($root): void {
    $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');

    // No `types` list: GitHub's defaults (opened, synchronize, reopened) run
    // the workflow for every commit of a pull request. A list is how an
    // activity type gets dropped, and with it the run for a new commit
    expect($workflow)->toContain(
        "on:\n  push:\n    branches: [ main ]\n  pull_request:\n    branches: [ main ]\n  workflow_dispatch:\n\n"
    );
    Assert::assertDoesNotMatchRegularExpression('/^\s*types:/m', $workflow, 'ci.yml restricts the activity types of a trigger');
    Assert::assertDoesNotMatchRegularExpression('/^\s*paths(?:-ignore)?:/m', $workflow, 'ci.yml filters its triggers by path');
    Assert::assertDoesNotMatchRegularExpression('/^\s*branches-ignore:/m', $workflow, 'ci.yml excludes branches from its triggers');
    Assert::assertStringNotContainsString('paths-filter', $workflow, 'ci.yml detects changed paths to decide what runs');

    // The documents the snippet test executes exist, so "every change set" covers them
    foreach (DocumentationSnippets::DOCUMENTS as $document) {
        expect(is_file($root . '/' . $document))->toBeTrue();
    }
});

// A superseded run for a pull request is cancelled; a run on main never is.
// Both halves depend on the event name: only the runs of one pull request
// share a group, and only those runs cancel one another.
it('cancels a superseded run for a pull request and no other run', function () use ($root): void {
    $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');

    expect($workflow)->toContain(
        "\nconcurrency:\n"
        . '  group: ${{ github.workflow }}-${{ github.event_name == \'pull_request\' && github.ref || github.run_id }}' . "\n"
        . '  cancel-in-progress: ${{ github.event_name == \'pull_request\' }}' . "\n"
    );
    Assert::assertDoesNotMatchRegularExpression(
        '/^\s*cancel-in-progress:\s*true\s*$/m',
        $workflow,
        'ci.yml cancels runs in progress unconditionally, which includes runs on main'
    );

    // The workflow-level block is the only one: a job-level group shared by
    // runs on main would queue them, and drop all but the newest one waiting
    expect(preg_match_all('/^\s*concurrency:/m', $workflow))->toBe(1);
    expect(preg_match_all('/^\s*cancel-in-progress:/m', $workflow))->toBe(1);
});

// `pull_request_target` runs in the context of the base branch, with its
// secrets, while the workflow may check out the pull request's code. No
// workflow here needs it. Comments are searched too, on purpose.
it('never uses pull_request_target', function (string $workflow): void {
    Assert::assertStringNotContainsString(
        'pull_request_target',
        (string) file_get_contents($workflow),
        basename($workflow) . ' mentions pull_request_target'
    );
})->with($workflowDataset);

// The scheduled workflow is an early warning against freshly resolved
// dependencies and the next PHP version. It must stay off pull requests: its
// result depends on the day it runs, not on the change under review.
it('keeps the scheduled workflow off pull requests and pushes', function () use ($root, $workflows): void {
    // Discovered, so the pin and permission checks above cover it
    expect(array_map(basename(...), $workflows))->toContain('scheduled.yml');

    $workflow = (string) file_get_contents($root . '/.github/workflows/scheduled.yml');

    if (preg_match('/^on:\n((?:(?:  .*)?\n)*)/m', $workflow, $triggers) !== 1) {
        Assert::fail('scheduled.yml has no `on` block');
    }

    preg_match_all('/^  (\w+):/m', $triggers[1], $events);
    expect($events[1])->toBe(['schedule', 'workflow_dispatch']);

    // The block read above ends at the first line that is not indented, a
    // comment in column one included, so the whole file is searched as well
    Assert::assertDoesNotMatchRegularExpression(
        '/^\s*["\']?(?:push|pull_request\w*)["\']?\s*:/m',
        $workflow,
        'scheduled.yml triggers on a push or a pull request'
    );
    Assert::assertMatchesRegularExpression('/^    - cron: \'[^\']+\'$/m', $triggers[1], 'scheduled.yml has no schedule');

    $jobs = [];
    foreach (['unlocked', 'next-php'] as $job) {
        if (preg_match('/^  ' . preg_quote($job, '/') . ":\n((?:(?:    .*)?\n)*)/m", $workflow, $definition) !== 1) {
            Assert::fail("scheduled.yml has no `{$job}` job");
        }
        $jobs[$job] = $definition[1];

        // Resolved afresh: a step that runs, not a mention in a comment
        expect($definition[1])->toContain("      run: composer update --prefer-dist --no-progress\n");
        Assert::assertDoesNotMatchRegularExpression('/^\s*run: composer install/m', $definition[1], "The `{$job}` job installs from the lock file");
    }

    // The supported versions run the whole gate and must pass; a failure is
    // tolerated on the next PHP version only
    expect($jobs['unlocked'])->toContain("        php-version: ['8.3', '8.4', '8.5']\n")
        ->toContain("      fail-fast: false\n")
        ->toContain("      run: composer ci\n");
    Assert::assertStringNotContainsString('continue-on-error', $jobs['unlocked'], 'The `unlocked` job is allowed to fail');
    expect($jobs['next-php'])->toContain("        php-version: nightly\n")
        ->toContain("    continue-on-error: true\n")
        ->toContain("      run: composer test\n")
        ->toContain("      run: composer examples\n");
    expect(substr_count($workflow, 'continue-on-error:'))->toBe(1);

    // None of its checks may take a name that is required on pull requests,
    // however the name is written
    Assert::assertDoesNotMatchRegularExpression(
        '/^\s*name:\s*["\']?(?:PHP \S+|Coverage)["\']?\s*$/m',
        $workflow,
        'scheduled.yml reports a check under a name that is required on pull requests'
    );
});

// A coverage driver slows every test down, so only the jobs that need one
// load one: the job that measures coverage, and the weekly mutation job,
// which mutates the lines a test executes.
it('loads a coverage driver in the coverage job and the weekly mutation job only', function () use ($root, $workflows): void {
    $ci = (string) file_get_contents($root . '/.github/workflows/ci.yml');
    $scheduled = (string) file_get_contents($root . '/.github/workflows/scheduled.yml');
    $mutation = (string) file_get_contents($root . '/.github/workflows/mutation.yml');

    // The three files read here are all there are
    expect(array_map(basename(...), $workflows))->toEqualCanonicalizing(['ci.yml', 'scheduled.yml', 'mutation.yml']);

    $drivers = function (string $workflow, string $job): array {
        if (preg_match('/^  ' . preg_quote($job, '/') . ":\n((?:(?:    .*)?\n)*)/m", $workflow, $definition) !== 1) {
            Assert::fail("No `{$job}` job");
        }
        preg_match_all('/^        coverage: (\S+)$/m', $definition[1], $matches);

        return $matches[1];
    };

    expect($drivers($ci, 'test'))->toBe(['none'])
        ->and($drivers($ci, 'coverage'))->toBe(['xdebug'])
        ->and($drivers($ci, 'audit'))->toBe(['none'])
        ->and($drivers($mutation, 'mutation'))->toBe(['xdebug'])
        ->and($drivers($scheduled, 'unlocked'))->toBe(['none'])
        ->and($drivers($scheduled, 'next-php'))->toBe(['none']);

    // Every PHP setup states its choice: the action's default is a driver
    expect(substr_count($ci . $scheduled . $mutation, 'shivammathur/setup-php@'))
        ->toBe(preg_match_all('/^        coverage: \S+$/m', $ci . $scheduled . $mutation));
});

// `composer test-coverage` must work with no prepared environment, and the
// report it writes must be the file the workflow uploads.
it('measures coverage with a self-contained script whose report the workflow uploads', function () use ($root): void {
    $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);

    /** @var array{scripts: array<string, string|list<string>>} $composer */
    $script = $composer['scripts']['test-coverage'];

    // Xdebug measures nothing unless its mode includes `coverage`; the
    // variable has to be set before the command that needs it
    expect($script)->toBe([
        '@putenv XDEBUG_MODE=coverage',
        'pest --coverage --coverage-clover=build/clover.xml',
    ]);

    expect($workflow)->toContain("      run: composer test-coverage\n")
        ->toContain("        files: build/clover.xml\n");

    // The report is a build product, never a tracked file
    expect(file($root . '/.gitignore', FILE_IGNORE_NEW_LINES))->toContain('/build');
});

// A run for a pull request from a fork, or from Dependabot, receives no
// secrets. Its upload may be rejected, and that must not turn the Coverage
// check red; a run that does have the token must still fail loudly.
it('fails the coverage upload only for a run that has the upload token', function () use ($root): void {
    $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');

    expect($workflow)->toContain("        token: \${{ secrets.CODECOV_TOKEN }}\n")
        ->toContain("        fail_ci_if_error: \${{ secrets.CODECOV_TOKEN != '' }}\n");
    expect(preg_match_all('/^\s*fail_ci_if_error:/m', $workflow))->toBe(1);

    // Nor may the job hide a failed upload, or a failed test, another way
    if (preg_match('/^  coverage:\n((?:(?:    .*)?\n)*)/m', $workflow, $coverage) !== 1) {
        Assert::fail('ci.yml has no `coverage` job');
    }
    expect($coverage[1])->toContain('        fail_ci_if_error: ');
    Assert::assertStringNotContainsString('continue-on-error', $coverage[1], 'The `coverage` job tolerates a failing step');
});

it('sets a patch coverage target and a tolerance for the project status', function () use ($root): void {
    $config = (string) file_get_contents($root . '/codecov.yml');

    Assert::assertMatchesRegularExpression(
        '/^coverage:\n  status:\n(?:    .*\n)*?    patch:\n      default:\n        target: \d+%\n/m',
        $config,
        'codecov.yml sets no patch coverage target'
    );
    Assert::assertMatchesRegularExpression(
        '/^    project:\n      default:\n        target: auto\n        threshold: \d+(?:\.\d+)?%\n/m',
        $config,
        'codecov.yml sets no project threshold'
    );
});

// vendor/ is rebuilt from the lock file on every run; what is cached is
// Composer's download directory. A cached vendor/ restored under a key that
// no longer matches the lock file is how a stale dependency reaches a run.
it('caches the Composer download directory, keyed on the lock file, and never vendor', function () use ($root): void {
    $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');

    // At least the test and the coverage job; every use must look the same
    $caches = (int) preg_match_all('/^      uses: actions\/cache@/m', $workflow);
    expect($caches)->toBeGreaterThanOrEqual(2);

    expect(substr_count($workflow, "      id: composer-cache\n      run: echo \"dir=\$(composer config cache-files-dir)\" >> \"\$GITHUB_OUTPUT\"\n"))->toBe($caches)
        ->and(substr_count($workflow, "        path: \${{ steps.composer-cache.outputs.dir }}\n"))->toBe($caches)
        ->and(substr_count($workflow, "        key: \${{ runner.os }}-composer-\${{ hashFiles('composer.lock') }}\n"))->toBe($caches)
        ->and(preg_match_all('/^        path:/m', $workflow))->toBe($caches);

    // The step that names the directory comes before the step that reads it
    foreach (explode('uses: actions/cache@', $workflow, -1) as $before) {
        expect(strrpos($before, 'id: composer-cache'))->not->toBeFalse();
    }

    // The lock file the key hashes is tracked and installed from
    expect(is_file($root . '/composer.lock'))->toBeTrue();
    expect(substr_count($workflow, "      run: composer install --prefer-dist --no-progress\n"))->toBeGreaterThanOrEqual($caches);
    Assert::assertDoesNotMatchRegularExpression('/^\s*path:\s*["\']?vendor/m', $workflow, 'ci.yml caches vendor/');
});

// The tests that use tests/Support/DecimalCommaLocale.php need a locale with
// a decimal comma. Where none is installed they skip on a developer's machine
// and fail on CI, so every job that runs the suite must generate one first;
// the runner image ships none.
it('generates a locale with a decimal comma before every run of the test suite', function (string $workflow): void {
    $contents = (string) file_get_contents($workflow);
    $jobs = preg_split('/^  (?=[a-z][a-z0-9-]*:\n)/m', $contents);

    if ($jobs === false) {
        Assert::fail(basename($workflow) . ' could not be split into jobs.');
    }

    $suiteRuns = 0;

    foreach ($jobs as $job) {
        // `composer mutation` runs the suite as well, as a line of a script
        // block: its output goes through `tee`
        if (preg_match('/^ {6,}(?:run: )?composer (?:ci|test|test-coverage|mutation)(?: .*)?$/m', $job, $suite, PREG_OFFSET_CAPTURE) !== 1) {
            continue;
        }

        ++$suiteRuns;
        $locale = strpos($job, "      run: sudo locale-gen de_DE.UTF-8\n");

        expect($locale)->not->toBeFalse()
            ->and($locale)->toBeLessThan($suite[0][1]);
    }

    // Every workflow runs the suite; a pattern that stopped matching would
    // otherwise pass by finding nothing. ci.yml runs it in the test and the
    // coverage job, the scheduled workflow in its two jobs, and the
    // mutation workflow in its one.
    expect($suiteRuns)->toBe(basename($workflow) === 'mutation.yml' ? 1 : 2);
})->with($workflowDataset);

// A mutation run takes hours of a runner: every change to the source
// starts a test process of its own. So it is not part of `composer ci` and
// it is in no job of ci.yml. It has a workflow of its own, mutation.yml,
// which runs once a week and on demand and never for a pull request, and
// not a job of scheduled.yml: a mutation job that is slow, or stopped at
// its limit, would turn that workflow red every week and hide what its own
// jobs found.
it('keeps mutation testing in a workflow of its own, off pull requests and out of the other two', function () use ($root): void {
    $ci = (string) file_get_contents($root . '/.github/workflows/ci.yml');
    $scheduled = (string) file_get_contents($root . '/.github/workflows/scheduled.yml');
    $workflow = (string) file_get_contents($root . '/.github/workflows/mutation.yml');

    Assert::assertDoesNotMatchRegularExpression('/^[^#\n]*mutation/m', $ci, 'ci.yml runs mutation testing');
    Assert::assertDoesNotMatchRegularExpression('/^[^#\n]*mutation/mi', $scheduled, 'scheduled.yml runs mutation testing');

    if (preg_match('/^on:\n((?:(?:  .*)?\n)*)/m', $workflow, $triggers) !== 1) {
        Assert::fail('mutation.yml has no `on` block');
    }
    preg_match_all('/^  (\w+):/m', $triggers[1], $events);
    expect($events[1])->toBe(['schedule', 'workflow_dispatch']);
    Assert::assertDoesNotMatchRegularExpression(
        '/^\s*["\']?(?:push|pull_request\w*)["\']?\s*:/m',
        $workflow,
        'mutation.yml triggers on a push or a pull request'
    );

    // Once a week, and not at the time of the other weekly workflow
    $crons = static function (string $contents): array {
        preg_match_all('/^    - cron: \'([^\']+)\'$/m', $contents, $matches);

        return $matches[1];
    };
    expect($crons($workflow))->toHaveCount(1)
        ->and($crons($scheduled))->toHaveCount(1);
    Assert::assertMatchesRegularExpression('/^\d+ \d+ \* \* [0-6]$/', $crons($workflow)[0], 'mutation.yml does not run once a week');
    expect($crons($workflow)[0])->not->toBe($crons($scheduled)[0]);

    // One job, and none of its checks takes a name that is required on
    // pull requests, however the name is written
    preg_match_all('/^  ([a-z][a-z0-9-]*):\n/m', substr($workflow, (int) strpos($workflow, "\njobs:\n")), $jobNames);
    expect($jobNames[1])->toBe(['mutation']);
    Assert::assertDoesNotMatchRegularExpression(
        '/^\s*name:\s*["\']?(?:PHP \S+|Coverage)["\']?\s*$/m',
        $workflow,
        'mutation.yml reports a check under a name that is required on pull requests'
    );
});

/**
 * The shards of the mutation workflow: name => the paths of its one
 * `--path` list.
 *
 * @return array<string, list<string>>
 */
function ciMutationShards(string $workflow): array
{
    preg_match_all('/^          - shard: (.+)\n            path: (\S+)\n/m', $workflow, $entries, PREG_SET_ORDER);

    $shards = [];
    // Two shards of one name would be one entry here; the test of the job
    // compares the number of entries with the number of path lists
    foreach ($entries as [, $name, $paths]) {
        $shards[$name] = explode(',', $paths);
    }

    return $shards;
}

// The source is mutated in shards, one job each, so that every job ends
// well inside its limit on a four-core runner. A shard is a list of paths
// for the one `--path` option. A PHP file of the two directories that no
// shard names would never be mutated, and nothing would say so; a file in
// two shards would be counted twice.
it('mutates every file of the two directories in exactly one shard', function () use ($root): void {
    $shards = ciMutationShards((string) file_get_contents($root . '/.github/workflows/mutation.yml'));

    expect(count($shards))->toBeGreaterThanOrEqual(2);

    /** @var array<string, list<string>> $coveredBy Source file => the shards that mutate it */
    $coveredBy = [];
    foreach (['src/Scheduling', 'src/Repack/Internal'] as $directory) {
        expect(is_dir($root . '/' . $directory))->toBeTrue();

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            Assert::assertInstanceOf(SplFileInfo::class, $file);
            if ($file->getExtension() === 'php') {
                $coveredBy[str_replace($root . '/', '', $file->getPathname())] = [];
            }
        }
    }
    expect(count($coveredBy))->toBeGreaterThan(10);

    foreach ($shards as $name => $paths) {
        expect($paths)->not->toBeEmpty();

        foreach ($paths as $path) {
            // A path is a file or a directory that exists, inside the two
            // directories: a misspelt one would mutate nothing
            expect(file_exists($root . '/' . $path))->toBeTrue("{$path} of the shard {$name} does not exist");
            Assert::assertMatchesRegularExpression('#^src/(?:Scheduling|Repack/Internal)(?:/|$)#', $path, "{$path} is outside the two directories");

            foreach (array_keys($coveredBy) as $file) {
                if ($file === $path || str_starts_with($file, rtrim($path, '/') . '/')) {
                    $coveredBy[$file][] = $name;
                }
            }
        }
    }

    $inNone = array_keys(array_filter($coveredBy, static fn(array $names): bool => $names === []));
    $inSeveral = array_keys(array_filter($coveredBy, static fn(array $names): bool => count($names) > 1));

    expect($inNone)->toBe([], 'No shard of mutation.yml mutates: ' . implode(', ', $inNone))
        ->and($inSeveral)->toBe([], 'More than one shard of mutation.yml mutates: ' . implode(', ', $inSeveral));

    // Together the shards are what the script mutates when it is given no
    // path: the two directories, which the script names itself
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
    /** @var array{scripts: array<string, string|list<string>>} $composer */
    expect(implode(' ', (array) $composer['scripts']['mutation']))->toContain('--path=src/Scheduling,src/Repack/Internal ');
});

it('reports a mutation score for every shard once a week, in jobs that enforce no minimum and end inside their limit', function () use ($root): void {
    $workflow = (string) file_get_contents($root . '/.github/workflows/mutation.yml');
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);

    if (preg_match("/^  mutation:\n((?:(?:    .*)?\n)*)/m", $workflow, $definition) !== 1) {
        Assert::fail('mutation.yml has no `mutation` job');
    }
    $jobs = ['mutation' => $definition[1]];

    // It runs once, in that job, with the path list of its shard, which
    // replaces the two directories of the script. One stopped shard does
    // not cancel the others
    expect(preg_match_all('/^\s*composer mutation\b/m', $workflow))->toBe(1);
    expect($jobs['mutation'])
        ->toContain("      fail-fast: false\n")
        ->toContain("        composer mutation -- --path=\${{ matrix.path }} 2>&1 | tee build/mutation.log\n");
    expect(preg_match_all('/^            path: /m', $jobs['mutation']))->toBe(count(ciMutationShards($workflow)));

    // Its check name is its own, one per shard
    preg_match_all('/^    name: (.+)$/m', $jobs['mutation'], $names);
    expect($names[1])->toBe(['Mutation testing, ${{ matrix.shard }}']);

    // It waits for no other job, no condition skips it, and neither a suite
    // that fails under it nor a shard that is stopped is tolerated: a
    // stopped shard is red, where it is seen
    Assert::assertDoesNotMatchRegularExpression(
        '/^    (?:if|needs|continue-on-error):/m',
        $jobs['mutation'],
        'The `mutation` job is conditional, waits on another job or may fail'
    );
    Assert::assertStringNotContainsString('continue-on-error', $workflow, 'mutation.yml tolerates a failing job or step');

    // The score is of the tests, so the tools come from the lock file
    expect($jobs['mutation'])->toContain("      run: composer install --prefer-dist --no-progress\n");
    Assert::assertDoesNotMatchRegularExpression('/^\s*run: composer update/m', $jobs['mutation'], 'The `mutation` job resolves dependencies afresh');

    // A run that never ends is stopped: the job and the run step both have a
    // limit, and the step's is the lower one, so that the summary still
    // runs. The limit is half of the six hours GitHub allows a job or less:
    // a shard that needs more is to be split, not given more time
    if (
        preg_match('/^    timeout-minutes: (\d+)$/m', $jobs['mutation'], $jobLimit) !== 1
        || preg_match('/^      timeout-minutes: (\d+)$/m', $jobs['mutation'], $stepLimit) !== 1
    ) {
        Assert::fail('The `mutation` job or its run step has no timeout');
    }
    expect((int) $jobLimit[1])->toBeLessThanOrEqual(180)
        ->and((int) $stepLimit[1])->toBeLessThan((int) $jobLimit[1]);

    // `tee` would hide a failed run without pipefail, which naming the shell sets
    expect($jobs['mutation'])->toContain("      shell: bash\n");

    // The summary of the shard goes to the job summary, also after a failed
    // or stopped run, under the shard's name and with its paths
    expect($jobs['mutation'])
        ->toContain("      if: \${{ !cancelled() }}\n")
        ->toContain("      run: php tests/bin/mutation-summary.php build/mutation.log \"\${{ matrix.shard }}\" \"\${{ matrix.path }}\" >> \"\$GITHUB_STEP_SUMMARY\"\n");
    expect(is_file($root . '/tests/bin/mutation-summary.php'))->toBeTrue();

    // The script: the two directories, the lines a test executes, in
    // parallel, with no minimum score, and outside the gate.
    //
    // Two spellings that look right give a wrong score, so they are pinned
    // out. A second `--path` replaces the first (the option takes one
    // comma-separated list), which would mutate one directory only. And
    // `--processes` reaches the test process of every mutation, which then
    // fails to start: every change is counted as noticed and the score is
    // 100%.
    /** @var array{scripts: array<string, string|list<string>>} $composer */
    expect($composer['scripts']['mutation'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        '@putenv XDEBUG_MODE=coverage',
        'pest --configuration=phpunit.mutation.xml --mutate --everything --covered-only --path=src/Scheduling,src/Repack/Internal --parallel',
    ]);
    expect(substr_count(implode(' ', (array) $composer['scripts']['mutation']), '--path='))->toBe(1);
    expect(implode(' ', (array) $composer['scripts']['mutation']))->not->toContain('--processes');
    expect(is_file($root . '/phpunit.mutation.xml'))->toBeTrue();
    expect($composer['scripts']['ci'])->not->toContain('@mutation');
    foreach ($composer['scripts'] as $name => $script) {
        expect(implode(' ', (array) $script))->not->toContain('--min', "The `{$name}` script sets a minimum score");
        if ($name !== 'mutation') {
            expect(implode(' ', (array) $script))->not->toContain('--mutate');
        }
    }
    expect(is_dir($root . '/src/Scheduling'))->toBeTrue()
        ->and(is_dir($root . '/src/Repack/Internal'))->toBeTrue();

    // The report is a build product, never a tracked file
    expect(file($root . '/.gitignore', FILE_IGNORE_NEW_LINES))->toContain('/build');
});

// Pest requires PHPUnit and Collision itself, at the versions it supports; a
// second, direct requirement can only disagree with it. Faker was never used.
it('requires no development package that is unused or that Pest already brings', function () use ($root): void {
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
    $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true);

    /** @var array{require: array<string, string>, require-dev: array<string, string>} $composer */
    expect(array_keys($composer['require']))->toBe(['php']);
    expect($composer['require-dev'])->not->toHaveKeys(['fakerphp/faker', 'nunomaduro/collision', 'phpunit/phpunit']);

    /** @var array{packages: list<array{name: string}>, packages-dev: list<array{name: string}>} $lock */
    $locked = array_column($lock['packages-dev'], 'name');

    expect($lock['packages'])->toBe([])
        ->and($locked)->not->toContain('fakerphp/faker')
        // Still installed, through Pest: the tests use PHPUnit's Assert
        ->and($locked)->toContain('pestphp/pest', 'phpunit/phpunit', 'nunomaduro/collision');
});

// The audit asks Packagist for advisories: it needs the network, and its
// result can change without a commit. So it is not part of `composer ci`,
// which must also pass offline, and it is not a step of a required check
// either: an advisory published against a development tool would otherwise
// block every merge. It runs in a job of its own, for every pull request and
// push, under a name branch protection does not require.
it('audits dependencies in a job of its own that is not a required check, and keeps the audit out of the local gate', function () use ($root): void {
    $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');
    $scheduled = (string) file_get_contents($root . '/.github/workflows/scheduled.yml');
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);

    $jobs = [];
    foreach (['test', 'coverage', 'audit'] as $job) {
        if (preg_match('/^  ' . preg_quote($job, '/') . ":\n((?:(?:    .*)?\n)*)/m", $workflow, $definition) !== 1) {
            Assert::fail("ci.yml has no `{$job}` job");
        }
        $jobs[$job] = $definition[1];
    }

    // The library's dependencies are audited once, in the audit job, and in
    // neither required job. The one other audit in the workflow is of the
    // benchmark runner's own lock file, in the Benchmarks job
    // (tests/Feature/BenchmarkComparisonTest.php).
    expect($jobs['audit'])->toContain("      run: composer security-audit\n");
    preg_match_all('/^\s*run: (.*\baudit\b.*)$/m', $workflow, $audits);
    expect($audits[1])->toBe([
        'composer security-audit',
        'composer audit --working-dir=tools/phpbench --locked --abandoned=report',
    ]);
    foreach (['test', 'coverage'] as $required) {
        Assert::assertDoesNotMatchRegularExpression('/^\s*run:.*\baudit\b/m', $jobs[$required], "The required `{$required}` job audits dependencies");
    }

    // Its check name is its own, and none of the four that are required
    preg_match_all('/^    name: (.+)$/m', $jobs['audit'], $names);
    expect($names[1])->toBe(['Dependency audit']);
    expect(preg_match_all('/^    name: Dependency audit$/m', $workflow))->toBe(1);

    // It runs for every pull request and push, and a finding fails it: no
    // condition, no dependency on another job, no tolerated failure
    foreach (['if', 'needs', 'continue-on-error'] as $key) {
        Assert::assertDoesNotMatchRegularExpression('/^\s*' . $key . ':/m', $jobs['audit'], "The `audit` job sets `{$key}`");
    }

    // It reads composer.lock, so the job installs nothing
    Assert::assertDoesNotMatchRegularExpression('/^\s*run: composer (?:install|update)/m', $jobs['audit'], 'The `audit` job installs dependencies');
    expect(is_file($root . '/composer.lock'))->toBeTrue();
    expect($composer)->toBeArray();

    /** @var array{scripts: array<string, string|list<string>>} $composer */
    expect($composer['scripts']['security-audit'])->toBe('@composer audit --locked --abandoned=report');
    expect($composer['scripts']['ci'])->not->toContain('@security-audit');
    foreach ($composer['scripts'] as $name => $script) {
        if ($name !== 'security-audit') {
            expect(implode(' ', (array) $script))->not->toContain('audit');
        }
    }

    // An abandoned package is reported on a pull request, and fails only in
    // the weekly run, where a maintainer is the one who sees it
    expect($scheduled)->toContain("      run: composer audit --abandoned=fail\n");
    Assert::assertStringNotContainsString('--abandoned=fail', $workflow, 'ci.yml fails on an abandoned package');
});

// Branch protection requires these checks by name; renaming one leaves the
// required check waiting forever on every pull request.
it('reports the checks named PHP 8.3, PHP 8.4, PHP 8.5 and Coverage', function () use ($root): void {
    $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');

    if (preg_match('/^  test:\n((?:(?:    .*)?\n)*)/m', $workflow, $test) !== 1) {
        Assert::fail('ci.yml has no `test` job');
    }
    if (preg_match('/^  coverage:\n((?:(?:    .*)?\n)*)/m', $workflow, $coverage) !== 1) {
        Assert::fail('ci.yml has no `coverage` job');
    }

    expect($test[1])->toContain("        php-version: ['8.3', '8.4', '8.5']\n")
        ->toContain('    name: PHP ${{ matrix.php-version }}' . "\n")
        // One version failing must not cancel the others' checks
        ->toContain("      fail-fast: false\n");
    expect($coverage[1])->toContain("    name: Coverage\n");
});

it('keeps Composer dependencies and the pinned actions current through Dependabot', function () use ($root): void {
    $config = (string) file_get_contents($root . '/.github/dependabot.yml');

    foreach (['composer', 'github-actions'] as $ecosystem) {
        Assert::assertMatchesRegularExpression(
            '/^\s*-\s+package-ecosystem:\s*["\']?' . preg_quote($ecosystem, '/') . '["\']?\s*$/m',
            $config,
            "Dependabot does not cover the {$ecosystem} ecosystem"
        );
    }
});

it('quotes Dependabot update types that contain a colon', function () use ($root): void {
    $config = (string) file_get_contents($root . '/.github/dependabot.yml');

    // `version-update:semver-major` unquoted inside a flow sequence is
    // rejected by strict YAML parsers, which would void the whole file.
    Assert::assertDoesNotMatchRegularExpression(
        '/(?<!["\'])version-update:/',
        $config,
        'An unquoted version-update type makes the Dependabot config unparseable'
    );
});
