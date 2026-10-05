<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

// The versioning policy, the changelog and the release checklist are prose,
// so nothing in the library exercises them. These tests pin the facts in them
// that the repository can contradict: the namespaces and classes the README
// classifies, the PHP versions it states, the shape of the changelog, and the
// links between the documents.
//
// Gap left knowingly - whether a changelog entry describes its release
// truthfully is a matter of reading it against the release; no test here can
// judge that.

$root = dirname(__DIR__, 2);

/**
 * The README's "Versioning and stability" section, heading excluded.
 */
$stabilitySection = function () use ($root): string {
    $readme = (string) file_get_contents($root . '/README.md');

    if (preg_match('/^## Versioning and stability\n(.*?)(?=^## )/ms', $readme, $matches) !== 1) {
        Assert::fail('README.md has no "Versioning and stability" section.');
    }

    return $matches[1];
};

/**
 * The backticked names in the list that follows a bold label, e.g. the items
 * under "**Stable**". Only the list is read: prose after it is not a
 * classification.
 *
 * @return list<string>
 */
$classified = function (string $label) use ($stabilitySection): array {
    $section = $stabilitySection();
    $start = strpos($section, "**{$label}**");

    if ($start === false) {
        Assert::fail("The stability section has no \"{$label}\" list.");
    }

    $names = [];
    $inList = false;

    foreach (explode("\n", substr($section, $start)) as $line) {
        $isItem = str_starts_with($line, '- ');

        if (!$inList && !$isItem) {
            continue;
        }

        if ($inList && !$isItem && !str_starts_with($line, '  ')) {
            break;
        }

        $inList = true;
        preg_match_all('/`([^`]+)`/', $line, $matches);
        $names = [...$names, ...$matches[1]];
    }

    return $names;
};

/**
 * @return list<string>
 */
$sourceNamespaces = function () use ($root): array {
    $namespaces = array_map(basename(...), glob($root . '/src/*', GLOB_ONLYDIR) ?: []);
    sort($namespaces);

    return $namespaces;
};

/**
 * The versions of the changelog's `## [x]` headings, in file order.
 *
 * @return list<array{version: string, date: ?string}>
 */
$changelogSections = function () use ($root): array {
    $changelog = (string) file_get_contents($root . '/CHANGELOG.md');
    preg_match_all('/^## \[([^\]]+)\](?: - (\S+))?$/m', $changelog, $matches, PREG_SET_ORDER);

    return array_map(
        fn (array $match) => ['version' => $match[1], 'date' => $match[2] ?? null],
        $matches
    );
};

describe('stability classification', function () use ($root, $classified, $sourceNamespaces): void {
    it('classifies every source namespace as stable or experimental', function () use ($classified, $sourceNamespaces): void {
        $named = array_map(
            fn (string $name) => explode('\\', $name)[0],
            [...$classified('Stable'), ...$classified('Experimental')]
        );

        expect($sourceNamespaces())->not->toBeEmpty();

        foreach ($sourceNamespaces() as $namespace) {
            expect(in_array($namespace, $named, true))->toBeTrue(
                "src/{$namespace} is in neither the Stable nor the Experimental list in README.md."
            );
        }
    });

    it('names only namespaces and classes that exist under src/', function () use ($root, $classified): void {
        $names = [...$classified('Stable'), ...$classified('Experimental')];

        expect($names)->not->toBeEmpty();

        foreach ($names as $name) {
            $path = $root . '/src/' . str_replace('\\', '/', $name);

            expect(is_dir($path) || is_file($path . '.php'))->toBeTrue(
                "README.md classifies `{$name}`, which is neither a directory nor a class under src/."
            );
        }
    });

    it('lists no name as both stable and experimental', function () use ($classified): void {
        expect(array_intersect($classified('Stable'), $classified('Experimental')))->toBe([]);
    });

    it('keeps a whole-namespace entry out of the other list', function () use ($classified): void {
        $whole = fn (array $names): array => array_values(array_filter(
            $names,
            fn (string $name) => !str_contains($name, '\\')
        ));
        $roots = fn (array $names): array => array_map(fn (string $name) => explode('\\', $name)[0], $names);

        // `Repack\Internal` is the stable list's own stated exclusion, not a
        // second classification of `Repack`.
        $stable = array_values(array_diff($classified('Stable'), ['Repack\Internal']));
        $experimental = $classified('Experimental');

        expect(array_intersect($whole($stable), $roots($experimental)))->toBe([])
            ->and(array_intersect($whole($experimental), $roots($stable)))->toBe([]);
    });
});

describe('supported PHP versions', function () use ($root, $stabilitySection): void {
    it('states the Composer constraint', function () use ($root, $stabilitySection): void {
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        if (preg_match('/\*\*Supported PHP versions:\*\* `([^`]+)`/', $stabilitySection(), $matches) !== 1) {
            Assert::fail('The stability section does not state the supported PHP versions.');
        }

        expect($matches[1])->toBe($composer['require']['php']);
    });

    it('states the versions of the CI test matrix', function () use ($root, $stabilitySection): void {
        $workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');

        if (preg_match('/^\s*php-version:\s*\[([^\]]+)\]/m', $workflow, $matrix) !== 1) {
            Assert::fail('ci.yml has no php-version matrix.');
        }

        if (preg_match('/CI runs the suite\s+on PHP ([^\n]+)\./', $stabilitySection(), $stated) !== 1) {
            Assert::fail('The stability section does not state the PHP versions CI runs.');
        }

        preg_match_all('/\d+\.\d+/', $matrix[1], $versions);
        preg_match_all('/\d+\.\d+/', $stated[1], $statedVersions);

        expect($versions[0])->not->toBeEmpty()
            ->and($statedVersions[0])->toBe($versions[0]);
    });
});

describe('changelog', function () use ($root, $changelogSections): void {
    it('opens with an Unreleased section', function () use ($changelogSections): void {
        $sections = $changelogSections();

        expect($sections)->not->toBeEmpty()
            ->and($sections[0]['version'])->toBe('Unreleased')
            ->and($sections[0]['date'])->toBeNull();
    });

    it('has a dated section for each back-filled release', function () use ($changelogSections): void {
        $versions = array_column($changelogSections(), 'version');

        expect($versions)->toContain('0.1.0', '0.1.1', '0.2.0');
    });

    it('lists releases newest first, each with an ISO date', function () use ($changelogSections): void {
        $releases = array_slice($changelogSections(), 1);
        $versions = array_column($releases, 'version');
        $sorted = $versions;
        usort($sorted, fn (string $a, string $b) => version_compare($b, $a));

        expect($versions)->toBe($sorted)
            ->and(array_unique($versions))->toHaveCount(count($versions));

        $previous = null;

        foreach ($releases as $release) {
            expect($release['version'])->toMatch('/^\d+\.\d+\.\d+$/')
                ->and((string) $release['date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');

            // Newest first means dates never increase going down the file.
            if ($previous !== null) {
                expect($release['date'] <= $previous)->toBeTrue(
                    "{$release['version']} is dated after the release listed above it."
                );
            }

            $previous = $release['date'];
        }
    });

    it('defines a comparison link for every section and no others', function () use ($root, $changelogSections): void {
        $changelog = (string) file_get_contents($root . '/CHANGELOG.md');
        preg_match_all('/^\[([^\]]+)\]: (\S+)$/m', $changelog, $matches, PREG_SET_ORDER);
        $links = array_column($matches, 2, 1);
        $versions = array_column($changelogSections(), 'version');

        expect(array_keys($links))->toBe($versions);

        foreach ($versions as $index => $version) {
            $older = $versions[$index + 1] ?? null;
            $expected = match (true) {
                $version === 'Unreleased' => "/compare/v{$older}...HEAD",
                $older === null => "/releases/tag/v{$version}",
                default => "/compare/v{$older}...v{$version}",
            };

            expect($links[$version])->toEndWith($expected);
        }
    });

    it('has a section for every release tag', function () use ($root, $changelogSections): void {
        // Harness limitation: a shallow CI checkout fetches no tags, so this
        // only bites where tags exist (a full clone, a release machine).
        $tags = [];
        exec('git -C ' . escapeshellarg($root) . ' tag --list ' . escapeshellarg('v[0-9]*') . ' 2>/dev/null', $tags, $status);

        if ($status !== 0 || $tags === []) {
            $this->markTestSkipped('No release tags are available in this checkout.');
        }

        $versions = array_column($changelogSections(), 'version');

        foreach ($tags as $tag) {
            expect($versions)->toContain(ltrim($tag, 'v'));
        }
    });
});

describe('release checklist', function () use ($root): void {
    it('requires a changelog section for every tag', function () use ($root): void {
        $checklist = (string) file_get_contents($root . '/docs/RELEASING.md');

        expect($checklist)->toContain('A tag without a changelog section is not a release.');
    });
});

describe('links', function () use ($root): void {
    /**
     * GitHub's heading anchor: lower-cased, punctuation dropped, spaces to
     * hyphens.
     */
    $anchor = fn (string $heading): string => str_replace(
        ' ',
        '-',
        (string) preg_replace('/[^a-z0-9 \-]/', '', strtolower(trim($heading)))
    );

    it('resolves every relative link and anchor', function (string $document) use ($root, $anchor): void {
        $source = (string) file_get_contents($root . '/' . $document);
        preg_match_all('/\]\(([^)\s]+)\)/', $source, $matches);
        $relative = array_filter($matches[1], fn (string $target) => preg_match('#^[a-z]+:#i', $target) !== 1);

        expect($relative)->not->toBeEmpty();

        foreach ($relative as $target) {
            [$path, $fragment] = array_pad(explode('#', $target, 2), 2, null);
            $file = $path === '' ? $root . '/' . $document : dirname($root . '/' . $document) . '/' . $path;

            expect(file_exists($file))->toBeTrue("{$document} links to {$target}, which does not exist.");

            if ($fragment === null) {
                continue;
            }

            preg_match_all('/^#{1,6} (.+)$/m', (string) file_get_contents($file), $headings);

            expect(array_map($anchor, $headings[1]))->toContain($fragment);
        }
    })->with(['README.md', 'CHANGELOG.md', 'AGENTS.md', 'docs/RELEASING.md']);
});
