<?php

declare(strict_types=1);

use MissionGaming\Tactician\Tests\Support\DocumentationSnippets;
use PHPUnit\Framework\Assert;

// The CI hardening is configuration, so nothing in the library exercises it.
// These tests pin its guarantees: least-privilege token permissions, actions
// referenced by immutable commit SHA, Dependabot coverage that keeps those
// pins current, and test jobs that run, under the check names branch
// protection requires, for every change set.
//
// The files are read as text on purpose: the project has no YAML parser among
// its dependencies. Gap left knowingly - nothing here proves the files are
// valid YAML or that GitHub accepts them; only a run on GitHub shows that.

$root = dirname(__DIR__, 2);
$workflows = glob($root . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE) ?: [];
$workflowDataset = array_combine(
    array_map(fn (string $workflow) => basename($workflow), $workflows),
    array_map(fn (string $workflow) => [$workflow], $workflows)
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
it('runs the test and coverage jobs for every change set', function (string $job) use ($root): void {
    $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');

    if (preg_match('/^  ' . preg_quote($job, '/') . ":\n((?:(?:    .*)?\n)*)/m", $workflow, $definition) !== 1) {
        Assert::fail("ci.yml has no `{$job}` job");
    }

    // A job-level condition or a dependency on another job can skip the job
    expect($definition[1])->toContain("    steps:\n");
    Assert::assertDoesNotMatchRegularExpression('/^    if:/m', $definition[1], "The `{$job}` job is conditional");
    Assert::assertDoesNotMatchRegularExpression('/^    needs:/m', $definition[1], "The `{$job}` job waits on another job");
})->with(['test', 'coverage']);

it('triggers on every push to main and every pull request, without a path or change filter', function () use ($root): void {
    $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');

    expect($workflow)->toContain("on:\n  push:\n    branches: [ main ]\n  pull_request:\n    branches: [ main ]\n\n");
    Assert::assertDoesNotMatchRegularExpression('/^\s*paths(?:-ignore)?:/m', $workflow, 'ci.yml filters its triggers by path');
    Assert::assertStringNotContainsString('paths-filter', $workflow, 'ci.yml detects changed paths to decide what runs');

    // The documents the snippet test executes exist, so "every change set" covers them
    foreach (DocumentationSnippets::DOCUMENTS as $document) {
        expect(is_file($root . '/' . $document))->toBeTrue();
    }
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

it('names only paths that exist in the docs-only filter', function () use ($root): void {
    $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');

    // The negated entries of the paths-filter list: a change to one of these
    // does not count as code, so the test jobs are skipped for it.
    preg_match_all('/^\s+-\s+\'!([^\']+)\'\s*$/m', $workflow, $matches);

    Assert::assertNotSame([], $matches[1], 'ci.yml has no docs-only filter entries; the check matched nothing');

    foreach ($matches[1] as $pattern) {
        // The part before the first wildcard: a file, or the directory a
        // glob starts in. A pattern that starts with a wildcard names none.
        $fixed = rtrim((string) preg_replace('/\*.*$/', '', $pattern), '/');

        if ($fixed === '') {
            continue;
        }

        Assert::assertFileExists(
            $root . '/' . $fixed,
            "The docs-only filter names `{$pattern}`, which is not in the repository"
        );
    }
});
