<?php

declare(strict_types=1);

use MissionGaming\Tactician\Tests\Support\DocumentationSnippets;
use PHPUnit\Framework\Assert;

// The CI hardening is configuration, so nothing in the library exercises it.
// These tests pin its three guarantees: least-privilege token permissions,
// actions referenced by immutable commit SHA, and Dependabot coverage that
// keeps those pins current.
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

// The workflow has a fast path that skips the test jobs for a change set
// holding documentation only. README.md and docs/USAGE.md are documentation
// whose code blocks the suite executes, so a change to either must still run
// the tests, or a broken snippet could merge on a documentation-only pull
// request.
it('runs the test jobs when a document with executed code blocks changes', function () use ($root): void {
    $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');

    // One glob, so the filter means the same whether paths-filter requires
    // some pattern or every pattern to match
    if (preg_match("/^          snippets:\n            - '\{([^}']+)\}'\n(?!            - )/m", $workflow, $filter) !== 1) {
        Assert::fail('ci.yml has no `snippets` paths filter holding a single brace glob');
    }
    $filtered = explode(',', $filter[1]);
    $executed = DocumentationSnippets::DOCUMENTS;
    sort($filtered);
    sort($executed);
    expect($filtered)->toBe($executed);

    expect($workflow)->toContain('      snippets: ${{ steps.filter.outputs.snippets }}' . "\n");

    if (preg_match('/^  test:\n(?:(?:    .*)?\n)*?    if: (.+)$/m', $workflow, $condition) !== 1) {
        Assert::fail('The test job in ci.yml has no `if:` condition');
    }
    expect($condition[1])->toContain("needs.changes.outputs.code == 'true'")
        ->toContain("|| needs.changes.outputs.snippets == 'true'");
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
