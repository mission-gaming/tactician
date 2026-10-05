<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Tests\Support\DocumentationSnippet;
use MissionGaming\Tactician\Tests\Support\DocumentationSnippets;
use PHPUnit\Framework\Assert;

/*
 * Documentation snippets are executable documentation: every fenced `php`
 * block of README.md and docs/USAGE.md is run here, in a PHP process of its
 * own, under strict types and E_ALL, and anything PHP reports (a parse
 * error, an uncaught exception, a warning, a notice, a deprecation) fails
 * the block. Never-run samples have shipped broken before: a parse error
 * and a sample that could only throw sat in the usage guide unnoticed.
 *
 * The rules a document is written against (blocks of one section build on
 * each other, imports are written in the document, and the three markers
 * `skip reason="..."`, `throws="..."` and `setup`) are documented on
 * MissionGaming\Tactician\Tests\Support\DocumentationSnippets. A failure
 * names the document, the line of the block's opening fence and PHP's
 * message, with PHP's own line numbers translated to lines of the document.
 *
 * Gaps left knowingly:
 *
 * - docs/integrations/*.md is not executed. Those blocks are Symfony and
 *   Laravel code (service definitions, controllers, Eloquent casts) and
 *   neither framework is a dependency of this library, so they cannot run
 *   here.
 * - docs/ARCHITECTURE.md and docs/design/*.md are not executed. Their
 *   blocks are design sketches (signatures, interface outlines) rather than
 *   programs; covering them means marking almost every block as skipped.
 * - A block is run, not checked: an inline value comment (`// 6`) is not
 *   compared with the value the code produces.
 */

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';

$extracted = [];
$blocks = [];
foreach (DocumentationSnippets::DOCUMENTS as $document) {
    $extracted[$document] = DocumentationSnippets::extract($document, (string) file_get_contents($root . '/' . $document));

    foreach ($extracted[$document]['snippets'] as $snippet) {
        if ($snippet->mode !== DocumentationSnippet::SETUP) {
            $blocks["{$snippet->location()} ({$snippet->section})"] = [$snippet];
        }
    }
}

/**
 * The blocks of every document that carry the given mode, as
 * "document / section" => the marker's reason or exception class.
 *
 * @param array<string, array{snippets: list<DocumentationSnippet>, problems: list<string>}> $extracted
 * @param DocumentationSnippet::* $mode
 *
 * @return list<string>
 */
function snippetsMarked(array $extracted, string $mode): array
{
    $marked = [];
    foreach ($extracted as $document) {
        foreach ($document['snippets'] as $snippet) {
            if ($snippet->mode === $mode) {
                $marked[] = "{$snippet->file} / {$snippet->section}: " . ($snippet->reason ?? $snippet->exception ?? 'hidden setup');
            }
        }
    }

    return $marked;
}

/**
 * Extract a Markdown sample and run its last executable block.
 *
 * @return string|null The failure message, null when the block passed
 *
 * @throws RuntimeException When PHP cannot be started
 */
function snippetSampleFailure(string $markdown): ?string
{
    $extracted = DocumentationSnippets::extract('sample.md', $markdown);
    Assert::assertSame([], $extracted['problems']);

    $executed = array_values(array_filter(
        $extracted['snippets'],
        static fn (DocumentationSnippet $snippet): bool => $snippet->isExecuted()
    ));
    Assert::assertNotEmpty($executed, 'The sample has no executable block.');

    return DocumentationSnippets::run(
        $executed[count($executed) - 1],
        $extracted['snippets'],
        dirname(__DIR__, 2) . '/vendor/autoload.php'
    );
}

/**
 * @return list<string> The problems extraction reports for a Markdown sample
 */
function snippetSampleProblems(string $markdown): array
{
    return DocumentationSnippets::extract('sample.md', $markdown)['problems'];
}

describe('Documentation snippets', function () use ($extracted, $blocks, $autoload): void {
    it('finds every php block of the document and no malformed marker', function (string $document) use ($extracted): void {
        $root = dirname(__DIR__, 2);
        $fenced = array_filter(
            $extracted[$document]['snippets'],
            static fn (DocumentationSnippet $snippet): bool => $snippet->mode !== DocumentationSnippet::SETUP
        );

        // An empty problem list also means every skip marker gives its reason
        expect($extracted[$document]['problems'])->toBe([])
            ->and($fenced)->not->toBeEmpty()
            // Counted a second way, so a block the extractor walks past is noticed
            ->and(count($fenced))->toBe(DocumentationSnippets::countPhpFences((string) file_get_contents($root . '/' . $document)));
    })->with(DocumentationSnippets::DOCUMENTS);

    it('runs the block', function (DocumentationSnippet $snippet) use ($extracted, $autoload): void {
        if ($snippet->mode === DocumentationSnippet::SKIP) {
            // Reported as a skipped test, so the run's summary counts the blocks nothing executes
            $this->markTestSkipped("{$snippet->location()} is not executed: {$snippet->reason}");
        }

        $failure = DocumentationSnippets::run($snippet, $extracted[$snippet->file]['snippets'], $autoload);
        if ($failure !== null) {
            Assert::fail($failure);
        }

        expect($failure)->toBeNull();
    })->with($blocks);

    // The markers are the ways a block escapes plain execution, so the set
    // of marked blocks is pinned: marking another one means changing this
    // list, where the reason is reviewed.
    it('skips only the blocks listed here', function () use ($extracted): void {
        expect(snippetsMarked($extracted, DocumentationSnippet::SKIP))->toBe([
            'docs/USAGE.md / Advanced Patterns: A sketch of a custom scheduler: it omits getPlan() and '
                . 'generateCustomEvents(), so the class cannot be declared.',
        ]);
    });

    it('expects only the blocks listed here to throw', function () use ($extracted): void {
        expect(snippetsMarked($extracted, DocumentationSnippet::THROWS))->toBe([
            'docs/USAGE.md / Real-World Examples: ' . IncompleteScheduleException::class,
        ]);
    });

    it('hides setup code only in the sections listed here', function () use ($extracted): void {
        expect(snippetsMarked($extracted, DocumentationSnippet::SETUP))->toBe([
            'README.md / Beyond Round Robin: hidden setup',
            'docs/USAGE.md / Pools, Progression, and Multi-Stage Tournaments: hidden setup',
        ]);
    });
});

/*
 * The harness itself, against Markdown written here. These prove the
 * documentation test can fail: without them a harness that ran nothing, or
 * swallowed what PHP reported, would pass every block above.
 */
describe('Documentation snippet harness', function (): void {
    it('fails a block that does not parse, naming the document, the fence line and the PHP error', function (): void {
        $failure = snippetSampleFailure(<<<'MARKDOWN'
            # Sample

            ## Constraints

            ```php
            $a = 1;
            $b = fn () => { return $a; };
            ```
            MARKDOWN);

        expect($failure)->toContain('sample.md:5, under "Constraints": the php block failed')
            ->and($failure)->toContain('Parse error: syntax error')
            ->and($failure)->toContain('in sample.md:7');
    });

    it('fails a block that ends in an uncaught exception', function (): void {
        $failure = snippetSampleFailure("```php\nthrow new RuntimeException('boom');\n```\n");

        expect($failure)->toContain('sample.md:1')
            ->and($failure)->toContain('Uncaught RuntimeException: boom in sample.md:2');
    });

    it('fails a block for a diagnostic below an error', function (string $code, string $reported): void {
        expect(snippetSampleFailure("```php\n{$code}\n```\n"))->toContain($reported);
    })->with([
        'warning' => ['echo $undefined;', 'Warning: Undefined variable $undefined in sample.md:2'],
        'notice' => ["trigger_error('careful', E_USER_NOTICE);", 'Notice: careful'],
        'deprecation' => ["trigger_error('old', E_USER_DEPRECATED);", 'Deprecated: old'],
        'failed assertion' => ['assert(1 + 1 === 3);', 'AssertionError'],
        'missing import' => ["new Participant('a', 'A');", 'Class "Participant" not found'],
        'strict types' => ['strlen(5);', 'must be of type string, int given'],
    ]);

    it('passes a block that runs cleanly, with or without an opening tag', function (string $opening): void {
        expect(snippetSampleFailure("```php\n{$opening}use MissionGaming\\Tactician\\DTO\\Participant;\n\necho (new Participant('a', 'A'))->getLabel();\n```\n"))
            ->toBeNull();
    })->with(['bare' => [''], 'opening tag' => ["<?php\n\n"], 'strict types' => ["<?php\n\ndeclare(strict_types=1);\n\n"]]);

    it('runs a block after the earlier blocks of its section, across deeper headings', function (): void {
        $failure = snippetSampleFailure(<<<'MARKDOWN'
            ## Section

            ```php
            use MissionGaming\Tactician\DTO\Participant;

            $alice = new Participant('alice', 'Alice');
            echo "printed by an earlier block\n";
            ```

            ### Deeper

            Repeating an import keeps a block readable by itself:

            ```php
            use MissionGaming\Tactician\DTO\Participant;

            $bob = new Participant('bob', 'Bob');
            assert($alice->getLabel() . $bob->getLabel() === 'AliceBob');
            ```
            MARKDOWN);

        expect($failure)->toBeNull();
    });

    it('carries nothing from one section to the next', function (): void {
        $failure = snippetSampleFailure(<<<'MARKDOWN'
            ## First

            ```php
            $alice = 'Alice';
            ```

            ## Second

            ```php
            echo $alice;
            ```
            MARKDOWN);

        expect($failure)->toContain('sample.md:9, under "Second": the php block failed')
            ->and($failure)->toContain('Undefined variable $alice in sample.md:10');
    });

    it('says how many earlier blocks a failing block ran after', function (): void {
        $failure = snippetSampleFailure("## Section\n\n```php\n\$a = 1;\n```\n\n```php\n\$b = 2;\n```\n\n```php\necho \$c;\n```\n");

        expect($failure)->toContain('sample.md:11, under "Section", run after the 2 earlier block(s) of that section');
    });

    it('finds a php block indented inside a list and ignores other languages', function (): void {
        $extracted = DocumentationSnippets::extract('sample.md', <<<'MARKDOWN'
            1. A step:

               ```php
               $inList = true;
               ```

            ```bash
            composer install
            ```

            ```text
            <!-- snippet: skip -->
            ```

            ~~~PHP
            $tilde = true;
            ~~~
            MARKDOWN);

        expect($extracted['problems'])->toBe([])
            ->and(array_map(static fn (DocumentationSnippet $snippet): array => $snippet->code, $extracted['snippets']))
            ->toBe([['$inList = true;'], ['$tilde = true;']])
            ->and(array_map(static fn (DocumentationSnippet $snippet): int => $snippet->line, $extracted['snippets']))
            ->toBe([3, 15]);
    });

    it('does not execute a skipped block and does not build on it', function (): void {
        $markdown = <<<'MARKDOWN'
            ## Section

            <!-- snippet: skip reason="A sketch that omits its methods." -->

            ```php
            class Sketch implements Countable
            {
                // ...
            }
            $fromSketch = true;
            ```

            ```php
            assert(!isset($fromSketch) && !class_exists('Sketch', false));
            ```
            MARKDOWN;

        $skipped = DocumentationSnippets::extract('sample.md', $markdown)['snippets'][0];

        expect($skipped->mode)->toBe(DocumentationSnippet::SKIP)
            ->and($skipped->reason)->toBe('A sketch that omits its methods.')
            ->and($skipped->isExecuted())->toBeFalse()
            ->and(snippetSampleFailure($markdown))->toBeNull();
    });

    it('reports a marker it cannot honour', function (string $markdown, string $problem): void {
        $problems = snippetSampleProblems($markdown);

        expect($problems)->toHaveCount(1)
            ->and($problems[0])->toContain($problem);
    })->with([
        'skip without a reason' => ["<!-- snippet: skip -->\n```php\n\$a = 1;\n```\n", 'sample.md:1: the skip marker gives no reason'],
        'skip with an empty reason' => ["<!-- snippet: skip reason=\" \" -->\n```php\n\$a = 1;\n```\n", 'sample.md:1: the skip marker gives no reason'],
        'unknown marker' => ["<!-- snippet: ignore -->\n```php\n\$a = 1;\n```\n", 'sample.md:1: unknown snippet marker `ignore`'],
        'marker split over lines' => ["<!-- snippet: skip\nreason=\"x\" -->\n```php\n\$a = 1;\n```\n", 'sample.md:1: malformed snippet marker'],
        'marker followed by prose' => ["<!-- snippet: skip reason=\"x\" -->\nProse.\n\n```php\n\$a = 1;\n```\n", 'sample.md:1: the snippet marker is not followed by a php block'],
        'marker before another language' => ["<!-- snippet: skip reason=\"x\" -->\n```bash\nls\n```\n", 'sample.md:1: the snippet marker is not followed by a php block'],
        'marker at the end of the document' => ["Prose.\n\n<!-- snippet: throws=\"RuntimeException\" -->\n", 'sample.md:3: the snippet marker is not followed by a php block'],
        'setup that imports a class' => ["<!-- snippet: setup\nuse RuntimeException;\n-->\n", 'sample.md:1: hidden setup must not import a class'],
        'setup that is never closed' => ["<!-- snippet: setup\n\$a = 1;\n", 'sample.md:1: the setup marker is never closed'],
        'fence that is never closed' => ["```php\n\$a = 1;\n", 'sample.md:1: the code fence is never closed'],
    ]);

    it('passes a block marked throws when it ends in that exception or a subclass', function (string $expected): void {
        $failure = snippetSampleFailure(
            "<!-- snippet: throws=\"{$expected}\" -->\n```php\nthrow new InvalidArgumentException('expected');\n```\n"
        );

        expect($failure)->toBeNull();
    })->with(['the class' => ['InvalidArgumentException'], 'a parent class' => ['LogicException'], 'an interface' => ['Throwable']]);

    it('fails a block marked throws that does not end in that exception', function (string $code, string $reported): void {
        $failure = snippetSampleFailure("<!-- snippet: throws=\"InvalidArgumentException\" -->\n```php\n{$code}\n```\n");

        expect($failure)->toContain('sample.md:2')
            ->and($failure)->toContain($reported);
    })->with([
        'nothing thrown' => ['$a = 1;', 'is marked throws="InvalidArgumentException" but ended without throwing.'],
        'another exception' => ["throw new RuntimeException('other');", 'is marked throws="InvalidArgumentException" but threw RuntimeException.'],
        'a parse error' => ['$a = ;', "but failed differently:\nParse error: syntax error"],
        'a warning before the exception' => [
            "echo \$undefined;\nthrow new InvalidArgumentException('expected');",
            "threw InvalidArgumentException as marked, but PHP also reported:\nWarning: Undefined variable \$undefined in sample.md:3",
        ],
    ]);

    it('does not build later blocks on a block that throws', function (): void {
        $failure = snippetSampleFailure(<<<'MARKDOWN'
            ## Section

            <!-- snippet: throws="RuntimeException" -->
            ```php
            $beforeThrow = true;
            throw new RuntimeException('expected');
            ```

            ```php
            assert(!isset($beforeThrow));
            ```
            MARKDOWN);

        expect($failure)->toBeNull();
    });

    it('runs hidden setup ahead of the later blocks of its section only', function (): void {
        $setup = <<<'MARKDOWN'
            ## Section

            <!-- snippet: setup
            function playRound(): string
            {
                return 'played';
            }
            -->

            ```php
            assert(playRound() === 'played');
            ```
            MARKDOWN;

        expect(snippetSampleFailure($setup))->toBeNull()
            ->and(snippetSampleFailure($setup . "\n## Next\n\n```php\nplayRound();\n```\n"))
            ->toContain('Call to undefined function playRound() in sample.md:16');
    });
});
