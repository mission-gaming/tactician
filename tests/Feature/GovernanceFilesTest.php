<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

// The governance files (code owners, issue forms, the pull request template,
// the security policy) are configuration and prose that GitHub reads, so
// nothing in the library exercises them. These tests pin what the repository
// can check on its own: the syntax GitHub requires of them, and the facts
// they share with the other documents.
//
// The YAML files are read as text on purpose: the project has no YAML parser
// among its dependencies. Gap left knowingly - nothing here proves that
// GitHub accepts the files, that a handle in CODEOWNERS belongs to an account
// with write access, or that private vulnerability reporting is switched on
// for the repository; only GitHub shows that.

$root = dirname(__DIR__, 2);

/**
 * The lines of a file that carry content: no blank lines, no `#` comments.
 *
 * @return list<string>
 */
$contentLines = fn (string $path): array => array_values(array_filter(
    array_map(trim(...), file($path, FILE_IGNORE_NEW_LINES) ?: []),
    static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')
));

/**
 * The keys at the first column of a YAML document.
 *
 * @return list<string>
 */
$topLevelKeys = function (string $yaml): array {
    preg_match_all('/^([A-Za-z_][\w-]*):/m', $yaml, $matches);

    return $matches[1];
};

/**
 * The branch types a document states, from the sentence that lists them.
 *
 * @return list<string>
 */
$branchTypes = function (string $path): array {
    $text = (string) preg_replace('/\s+/', ' ', (string) file_get_contents($path));

    if (preg_match('/the type is (?:one of )?((?:(?:or )?`[a-z]+`,? ?)+)/i', $text, $sentence) !== 1) {
        Assert::fail(basename($path) . ' does not list the branch types.');
    }

    preg_match_all('/`([a-z]+)`/', $sentence[1], $types);

    return $types[1];
};

$forms = array_values(array_filter(
    glob($root . '/.github/ISSUE_TEMPLATE/*.{yml,yaml}', GLOB_BRACE) ?: [],
    static fn (string $form): bool => !str_starts_with(basename($form), 'config.')
));
$formDataset = array_combine(
    array_map(fn (string $form) => basename($form), $forms),
    array_map(fn (string $form) => [$form], $forms)
);

describe('CODEOWNERS', function () use ($root, $contentLines): void {
    it('gives every rule a pattern and at least one valid GitHub handle', function () use ($root, $contentLines): void {
        $rules = $contentLines($root . '/.github/CODEOWNERS');

        expect($rules)->not->toBeEmpty();

        foreach ($rules as $rule) {
            $fields = preg_split('/\s+/', $rule) ?: [];
            $owners = array_slice($fields, 1);

            Assert::assertNotSame([], $owners, "CODEOWNERS rule `{$rule}` names no owner, which removes ownership.");

            foreach ($owners as $owner) {
                // A user handle, or an organization team as @org/team. An
                // email address is also valid for GitHub but is not accepted
                // here: the repository publishes none.
                Assert::assertMatchesRegularExpression(
                    '/^@[a-z\d](?:[a-z\d]|-(?=[a-z\d])){0,38}(?:\/[a-z\d][\w.-]*)?$/i',
                    $owner,
                    "CODEOWNERS owner `{$owner}` is not a GitHub handle."
                );
            }
        }
    });

    it('assigns an owner to every path', function () use ($root, $contentLines): void {
        $patterns = array_map(
            fn (string $rule) => (preg_split('/\s+/', $rule) ?: [''])[0],
            $contentLines($root . '/.github/CODEOWNERS')
        );

        // The last matching pattern wins, so `*` only covers everything when
        // it comes first and narrower patterns follow it.
        expect($patterns[0] ?? null)->toBe('*');
    });
});

describe('issue forms', function () use ($root, $topLevelKeys, $forms, $formDataset): void {
    it('discovers forms to check', function () use ($forms): void {
        expect($forms)->not->toBeEmpty();
    });

    it('has the top-level keys GitHub requires of a form', function (string $form) use ($topLevelKeys): void {
        $keys = $topLevelKeys((string) file_get_contents($form));

        foreach (['name', 'description', 'body'] as $required) {
            expect($keys)->toContain($required);
        }

        expect(array_unique($keys))->toHaveCount(count($keys));
    })->with($formDataset);

    it('uses only known element types and gives every input a unique id and a label', function (string $form): void {
        $name = basename($form);
        $contents = (string) file_get_contents($form);

        if (preg_match('/^body:\n(.*?)(?=^\S|\z)/ms', $contents, $body) !== 1) {
            Assert::fail("{$name} has no body.");
        }

        $elements = preg_split('/^  - /m', $body[1], flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $ids = [];
        $inputs = 0;

        foreach ($elements as $element) {
            if (preg_match('/\Atype:\s*(\S+)/', $element, $type) !== 1) {
                Assert::fail("{$name} has a body element that does not start with its type.");
            }

            expect($type[1])->toBeIn(['markdown', 'input', 'textarea', 'dropdown', 'checkboxes', 'upload']);

            // Markdown is display text: GitHub gives it no id and no label.
            if ($type[1] === 'markdown') {
                continue;
            }

            ++$inputs;

            if (preg_match('/^    id:\s*([\w-]+)\s*$/m', $element, $id) !== 1) {
                Assert::fail("{$name} has a {$type[1]} element without an id.");
            }

            $ids[] = $id[1];

            Assert::assertMatchesRegularExpression(
                '/^      label:\s*\S/m',
                $element,
                "{$name} element `{$id[1]}` has no label."
            );
        }

        // A form made only of markdown is rejected by GitHub.
        expect($inputs)->toBeGreaterThan(0)
            ->and(array_unique($ids))->toHaveCount(count($ids));
    })->with($formDataset);

    it('applies only labels written as a flow sequence of quoted names', function (string $form): void {
        $contents = (string) file_get_contents($form);

        if (preg_match('/^labels:(.*)$/m', $contents, $labels) !== 1) {
            // Labels are optional.
            expect(true)->toBeTrue();

            return;
        }

        expect(trim($labels[1]))->toMatch('/^\["[^"\]]+"(?:, "[^"\]]+")*\]$/');
    })->with($formDataset);

    it('asks a bug report for the versions, a reproduction and both results', function () use ($root): void {
        $contents = (string) file_get_contents($root . '/.github/ISSUE_TEMPLATE/bug_report.yml');

        foreach (['version', 'php-version', 'reproduction', 'expected', 'actual'] as $id) {
            // Each of these is required: a report without one cannot be acted on.
            Assert::assertMatchesRegularExpression(
                '/^    id: ' . preg_quote($id, '/') . '\n(?:      .*\n|    (?!id:).*\n)*?      required: true$/m',
                $contents,
                "The bug report form does not require `{$id}`."
            );
        }
    });

    it('sends security reports to private reporting from the template chooser', function () use ($root, $topLevelKeys): void {
        $config = (string) file_get_contents($root . '/.github/ISSUE_TEMPLATE/config.yml');

        expect($topLevelKeys($config))->toContain('blank_issues_enabled', 'contact_links');

        Assert::assertMatchesRegularExpression('/^blank_issues_enabled:\s*(?:true|false)\s*$/m', $config);

        // GitHub drops a contact link that lacks any of its three keys.
        preg_match_all('/^  - name:.*\n(?:    .*\n?)*/m', $config, $links);

        expect($links[0])->not->toBeEmpty();

        foreach ($links[0] as $link) {
            expect($link)->toMatch('/^    url:\s*https:\/\/\S+$/m')
                ->and($link)->toMatch('/^    about:\s*\S/m');
        }

        expect($config)->toContain('https://github.com/mission-gaming/tactician/security/advisories/new');
    });
});

describe('security policy', function () use ($root): void {
    it('publishes no email address', function () use ($root): void {
        // Reports go through GitHub's private vulnerability reporting, so
        // that they are tracked and stay private; an address in the policy
        // would open a second, untracked channel.
        $policy = (string) file_get_contents($root . '/SECURITY.md');

        expect($policy)->not->toMatch('/[\w.+-]+@[\w-]+\.[\w.-]+/');
        expect($policy)->not->toContain('mailto:');
    });

    it('points to the Security tab of this repository', function () use ($root): void {
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        expect((string) file_get_contents($root . '/SECURITY.md'))
            ->toContain("https://github.com/{$composer['name']}/security");
    });
});

describe('pull request template', function () use ($root): void {
    it('has the sections the contributing guide names, in order', function () use ($root): void {
        preg_match_all('/^## (.+)$/m', (string) file_get_contents($root . '/.github/pull_request_template.md'), $headings);

        expect($headings[1])->toBe(['Description', 'Approach', 'Compatibility impact', 'Test steps']);

        $guide = (string) preg_replace('/\s+/', ' ', (string) file_get_contents($root . '/docs/CONTRIBUTING.md'));

        expect($guide)->toContain(strtolower(implode(', ', array_slice($headings[1], 0, 3)) . ', and ' . $headings[1][3]));
    });

    it('asks about output, public signatures and the changelog', function () use ($root): void {
        $template = (string) file_get_contents($root . '/.github/pull_request_template.md');

        if (preg_match('/^## Compatibility impact\n(.*?)(?=^## )/ms', $template, $section) !== 1) {
            Assert::fail('The pull request template has no "Compatibility impact" section.');
        }

        preg_match_all('/^- \[ \] (.+)$/m', $section[1], $boxes);

        expect($boxes[1])->toHaveCount(3)
            ->and($boxes[1][0])->toContain('output')
            ->and($boxes[1][1])->toContain('public signature')
            ->and($boxes[1][2])->toContain('`Unreleased`');
    });
});

describe('branch and commit convention', function () use ($root, $branchTypes): void {
    it('lists the same branch types in the agent guide and the contributing guide', function () use ($root, $branchTypes): void {
        $types = $branchTypes($root . '/AGENTS.md');

        expect($types)->not->toBeEmpty()
            ->and($branchTypes($root . '/docs/CONTRIBUTING.md'))->toBe($types);
    });

    it('gives examples that follow the convention it states', function (string $document) use ($root, $branchTypes): void {
        $path = $root . '/' . $document;
        $text = (string) preg_replace('/\s+/', ' ', (string) file_get_contents($path));

        if (preg_match('/example:? `([^`]+)`.*?example:? `([^`]+)`/i', strstr($text, '<type>/<short-description>') ?: '', $examples) !== 1) {
            Assert::fail("{$document} gives no branch and commit example.");
        }

        expect($examples[1])->toMatch('/^(?:' . implode('|', $branchTypes($path)) . ')\/[a-z\d]+(?:-[a-z\d]+)*$/')
            ->and($examples[2])->toMatch('/^[A-Z][^:]*\.$/');
    })->with(['AGENTS.md', 'docs/CONTRIBUTING.md']);
});
