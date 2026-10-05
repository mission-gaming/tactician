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
 * - A block that never returns (an endless loop) hangs the run rather than
 *   failing it; the harness sets no time limit.
 */

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';

$extracted = [];
$blocks = [];
$skippedBlocks = [];
foreach (DocumentationSnippets::DOCUMENTS as $document) {
    $extracted[$document] = DocumentationSnippets::extract($document, (string) file_get_contents($root . '/' . $document));

    foreach ($extracted[$document]['snippets'] as $snippet) {
        if ($snippet->mode !== DocumentationSnippet::SETUP) {
            $blocks["{$snippet->location()} ({$snippet->section})"] = [$snippet];
        }
        if ($snippet->mode === DocumentationSnippet::SKIP) {
            $skippedBlocks["{$snippet->location()} ({$snippet->section})"] = [$snippet];
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

describe('Documentation snippets', function () use ($extracted, $blocks, $skippedBlocks, $autoload): void {
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

    // A skip marker takes a block out of the suite, so it has to be earned:
    // the block must still be PHP a reader can read (it parses), and it must
    // really be unable to run. A marker left on a block that runs cleanly,
    // or put on one to hide a syntax error, fails here.
    it('keeps a skip marker only on a block that parses and cannot run', function (DocumentationSnippet $snippet) use ($extracted, $autoload): void {
        expect(DocumentationSnippets::syntaxError($snippet))->toBeNull();

        $spared = DocumentationSnippets::runSkipped($snippet, $extracted[$snippet->file]['snippets'], $autoload);
        if ($spared === null) {
            Assert::fail("{$snippet->location()} is marked skip but runs cleanly; remove the marker so the suite executes it.");
        }

        expect($spared)->toContain("{$snippet->location()}, under \"{$snippet->section}\"");
    })->with($skippedBlocks);

    // Blocks build on the earlier blocks under the same heading, so a
    // heading that appears twice must not join two sections into one
    it('gives every section that holds a block a heading of its own', function (string $document) use ($extracted): void {
        $headings = [];
        foreach ($extracted[$document]['snippets'] as $snippet) {
            $headings[$snippet->sectionLine] = $snippet->section;
        }

        expect(array_values($headings))->toBe(array_values(array_unique($headings)));
    })->with(DocumentationSnippets::DOCUMENTS);
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
        'setup with an indented import' => ["<!-- snippet: setup\n    use RuntimeException;\n-->\n", 'sample.md:1: hidden setup must not import a class'],
        'setup with an import after another statement' => ["<!-- snippet: setup\n\$a = 1; use RuntimeException;\n-->\n", 'sample.md:1: hidden setup must not import a class'],
        'setup with a grouped import' => ["<!-- snippet: setup\nuse Random\\{Randomizer, Engine\\Mt19937};\n-->\n", 'sample.md:1: hidden setup must not import a class'],
        'setup that imports a function' => ["<!-- snippet: setup\nuse function strlen;\n-->\n", 'sample.md:1: hidden setup must not import a class'],
        'setup that aliases a class' => ["<!-- snippet: setup\nclass_alias(RuntimeException::class, 'Boom');\n-->\n", 'sample.md:1: hidden setup must not import a class or alias one'],
        'setup that aliases a class by a qualified call' => ["<!-- snippet: setup\n\\Class_Alias(RuntimeException::class, 'Boom');\n-->\n", 'sample.md:1: hidden setup must not import a class or alias one'],
        'setup marker closed on its own line' => ["<!-- snippet: setup -->\n```php\n\$a = 1;\n```\n", 'sample.md:1: unknown snippet marker `setup`'],
        'two markers for one block' => [
            "<!-- snippet: skip reason=\"x\" -->\n<!-- snippet: throws=\"RuntimeException\" -->\n```php\n\$a = 1;\n```\n",
            'sample.md:1: the snippet marker is not followed by a php block',
        ],
        'throws without a class' => ["<!-- snippet: throws=\"\" -->\n```php\n\$a = 1;\n```\n", 'sample.md:1: unknown snippet marker'],
        'marker sharing its line with prose' => ["See <!-- snippet: skip reason=\"x\" --> below.\n\n```php\n\$a = 1;\n```\n", 'sample.md:1: malformed snippet marker'],
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

    // Exit code 0 and a silent stderr are what success looks like, and also
    // what a script that stopped early looks like: the block after the stop
    // would pass without one line of it having run.
    it('fails a block the script never reaches the end of', function (string $markdown, string $where): void {
        $failure = snippetSampleFailure($markdown);

        expect($failure)->toContain($where)
            ->and($failure)->toContain('the php block did not run to its end');
    })->with([
        'exit' => ["```php\nexit;\nundefinedFunction();\n```\n", 'sample.md:1'],
        'die with a message, exit code 0' => ["```php\ndie('Error: could not schedule');\n```\n", 'sample.md:1'],
        'a top-level return' => ["```php\nreturn;\nundefinedFunction();\n```\n", 'sample.md:1'],
        'exit in an earlier block' => ["## Section\n\n```php\nexit;\n```\n\n```php\nundefinedFunction();\n```\n", 'sample.md:7, under "Section", run after the 1 earlier block(s)'],
        'a return in an earlier block' => ["## Section\n\n```php\nreturn;\n```\n\n```php\nundefinedFunction();\n```\n", 'sample.md:7'],
        'an exception swallowed by an earlier handler' => [
            "## Section\n\n```php\nset_exception_handler(static function (Throwable \$e): void {});\n```\n\n```php\nthrow new RuntimeException('lost');\n```\n",
            'sample.md:7',
        ],
        'exit inside hidden setup' => ["## Section\n\n<!-- snippet: setup\nexit;\n-->\n\n```php\nundefinedFunction();\n```\n", 'sample.md:7'],
    ]);

    it('fails a block that turns PHP\'s error reporting down', function (string $markdown): void {
        $failure = snippetSampleFailure($markdown);

        expect($failure)->toContain('the php block failed (exit code 1)')
            ->and($failure)->toContain('The block changed error_reporting or display_errors');
    })->with([
        'error_reporting(0)' => ["```php\nerror_reporting(0);\necho \$undefined;\n```\n"],
        'deprecations masked' => ["```php\nerror_reporting(E_ALL & ~E_DEPRECATED);\n```\n"],
        'errors sent to standard output' => ["```php\nini_set('display_errors', '1');\necho \$undefined;\n```\n"],
        'errors hidden' => ["```php\nini_set('display_errors', '0');\necho \$undefined;\n```\n"],
        'in an earlier block' => ["## Section\n\n```php\nerror_reporting(0);\n```\n\n```php\necho \$undefined;\n```\n"],
    ]);

    it('passes a block whose shape could hide the end-of-block check', function (string $code): void {
        expect(snippetSampleFailure("```php\n{$code}\n```\n"))->toBeNull();
    })->with([
        'ends in a line comment' => ['$a = 1; // the last line'],
        'ends in a function declared after its use' => ["echo late();\nfunction late(): string\n{\n    return 'late';\n}"],
        'leaves an output buffer open' => ["ob_start();\necho 'buffered';"],
        'prints the word the harness looks for' => ['echo "finished\\nsnippet-finished\\n";'],
        'silences one call with @' => ['$missing = @file_get_contents(__DIR__ . \'/no-such-file\');'],
        'empty' => [''],
    ]);

    it('names the earlier block when that is the one that fails, and fails every block built on it', function (): void {
        $markdown = "## Section\n\n```php\n\$a = 1;\necho \$undefined;\n```\n\n```php\n\$b = \$a + 1;\n```\n\n```php\n\$c = \$b + 1;\n```\n";
        $extracted = DocumentationSnippets::extract('sample.md', $markdown)['snippets'];
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        $failures = array_map(
            static fn (DocumentationSnippet $snippet): ?string => DocumentationSnippets::run($snippet, $extracted, $autoload),
            $extracted
        );

        // No later block reports success while the block it builds on is broken
        expect($failures[0])->toContain('sample.md:3, under "Section": the php block failed')
            ->and($failures[1])->toContain('sample.md:8, under "Section", run after the 1 earlier block(s)')
            ->and($failures[2])->toContain('sample.md:12, under "Section", run after the 2 earlier block(s)');
        foreach ($failures as $failure) {
            expect($failure)->toContain('Undefined variable $undefined in sample.md:5');
        }
    });

    it('does not take an exception thrown by an earlier block for the one a block is marked to throw', function (): void {
        $failure = snippetSampleFailure(<<<'MARKDOWN'
            ## Section

            ```php
            throw new RuntimeException('thrown too early');
            ```

            <!-- snippet: throws="RuntimeException" -->
            ```php
            throw new RuntimeException('expected');
            ```
            MARKDOWN);

        expect($failure)->toContain('is marked throws="RuntimeException" but failed differently:')
            ->and($failure)->toContain('Uncaught RuntimeException: thrown too early in sample.md:4');
    });

    it('fails a block marked throws whose class does not exist, or that exits instead of throwing', function (string $marker, string $code, string $reported): void {
        expect(snippetSampleFailure("<!-- snippet: throws=\"{$marker}\" -->\n```php\n{$code}\n```\n"))->toContain($reported);
    })->with([
        'misspelt class' => ['RuntimeExeption', "throw new RuntimeException('x');", 'is marked throws="RuntimeExeption" but threw RuntimeException.'],
        'exit instead of throwing' => ['RuntimeException', 'exit;', 'but ended without throwing.'],
        'exception caught by the block' => ['RuntimeException', "try { throw new RuntimeException('x'); } catch (RuntimeException) { echo 'handled'; }", 'but ended without throwing.'],
        'printing the class name is not throwing it' => ['RuntimeException', 'echo "RuntimeException\\n";', 'but ended without throwing.'],
    ]);

    it('keeps two sections apart when their headings share a text', function (): void {
        $markdown = <<<'MARKDOWN'
            ## Example

            ```php
            $fromTheFirst = 1;
            ```

            ## Another

            ## Example

            ```php
            echo $fromTheFirst;
            ```
            MARKDOWN;

        $snippets = DocumentationSnippets::extract('sample.md', $markdown)['snippets'];

        expect($snippets[0]->section)->toBe($snippets[1]->section)
            ->and($snippets[0]->inSameSectionAs($snippets[1]))->toBeFalse()
            ->and(snippetSampleFailure($markdown))->toContain('Undefined variable $fromTheFirst in sample.md:12');
    });

    it('starts a section at an underlined heading as well', function (): void {
        $markdown = <<<'MARKDOWN'
            First
            =====

            ```php
            $fromTheFirst = 1;
            ```

            | Not | A heading |
            |-----|-----------|

            - a list item
            ---

            ```php
            assert($fromTheFirst === 1);
            ```

            Second
            ------

            ```php
            echo $fromTheFirst;
            ```
            MARKDOWN;

        $snippets = DocumentationSnippets::extract('sample.md', $markdown)['snippets'];

        expect(array_map(static fn (DocumentationSnippet $snippet): string => $snippet->section, $snippets))
            ->toBe(['First', 'First', 'Second'])
            ->and(DocumentationSnippets::run($snippets[1], $snippets, dirname(__DIR__, 2) . '/vendor/autoload.php'))->toBeNull()
            ->and(snippetSampleFailure($markdown))->toContain('Undefined variable $fromTheFirst in sample.md:22');
    });

    it('lets a deeper heading with the text of the section heading stay inside the section', function (): void {
        $failure = snippetSampleFailure("## Usage\n\n```php\n\$a = 1;\n```\n\n### Usage\n\n```php\nassert(\$a === 1);\n```\n");

        expect($failure)->toBeNull();
    });

    it('reads a document with Windows line endings like any other', function (): void {
        $markdown = "## Section\r\n\r\n<!-- snippet: throws=\"RuntimeException\" -->\r\n```php\r\nthrow new RuntimeException('x');\r\n```\r\n\r\n```php\r\necho \$undefined;\r\n```\r\n";
        $extracted = DocumentationSnippets::extract('sample.md', $markdown);

        expect($extracted['problems'])->toBe([])
            ->and($extracted['snippets'])->toHaveCount(2)
            ->and($extracted['snippets'][0]->mode)->toBe(DocumentationSnippet::THROWS)
            ->and(DocumentationSnippets::countPhpFences($markdown))->toBe(2)
            ->and(snippetSampleFailure($markdown))->toContain('Undefined variable $undefined in sample.md:9');
    });

    // The feature test compares the extractor's count with this one, so the
    // two must disagree exactly when the extractor walks past a php fence
    it('counts php fences the way the extractor finds them, and differently when a block is passed over', function (string $markdown, int $extracted, int $counted): void {
        $snippets = DocumentationSnippets::extract('sample.md', $markdown)['snippets'];

        expect(count($snippets))->toBe($extracted)
            ->and(DocumentationSnippets::countPhpFences($markdown))->toBe($counted);
    })->with([
        'no block at all' => ["# Title\n\nProse only.\n", 0, 0],
        'only other languages' => ["```bash\nls\n```\n\n```phpunit\nx\n```\n", 0, 0],
        'an info string after the language' => ["```php title=\"a.php\"\n\$a = 1;\n```\n", 1, 1],
        'a space before the language' => ["``` php\n\$a = 1;\n```\n", 1, 1],
        'upper case and tildes' => ["~~~PHP\n\$a = 1;\n~~~\n", 1, 1],
        'a longer closing fence' => ["```php\n\$a = 1;\n`````\n\n```php\n\$b = 2;\n```\n", 2, 2],
        'a php fence shown inside a longer fence' => ["````markdown\n```php\n\$a = 1;\n```\n````\n", 0, 1],
        'a block swallowed by an unclosed fence' => ["```text\nnever closed\n\n```php\n\$a = 1;\n", 0, 1],
        'a language the extractor does not run' => ["```php-template\n<?= \$a ?>\n```\n", 0, 1],
    ]);

    it('does not close a fence with a shorter one or with the other fence character', function (): void {
        $extracted = DocumentationSnippets::extract('sample.md', "````php\n\$a = '```';\n```\n~~~~\n\$b = 2;\n````\n");

        expect($extracted['problems'])->toBe([])
            ->and($extracted['snippets'])->toHaveCount(1)
            ->and($extracted['snippets'][0]->code)->toBe(["\$a = '```';", '```', '~~~~', '$b = 2;']);
    });

    it('drops a repeated import only, never a different one or a closure use', function (): void {
        $failure = snippetSampleFailure(<<<'MARKDOWN'
            ## Section

            ```php
            use MissionGaming\Tactician\DTO\Participant;
            use MissionGaming\Tactician\DTO\Participant as Entrant;

            $prefix = 'p';
            ```

            ```php
            use MissionGaming\Tactician\DTO\Participant;
            use MissionGaming\Tactician\DTO\Participant as Entrant;
            use MissionGaming\Tactician\DTO\Round;

            $make = function (string $id) use ($prefix): Participant {
                return new Entrant($prefix . $id, $id);
            };
            assert($make('1')->getId() === 'p1' && (new Round(2))->getNumber() === 2);
            ```
            MARKDOWN);

        expect($failure)->toBeNull();
    });

    it('fails a block that imports one name for two classes, as PHP would for the reader', function (): void {
        $failure = snippetSampleFailure(<<<'MARKDOWN'
            ## Section

            ```php
            use MissionGaming\Tactician\DTO\Participant;
            ```

            ```php
            use MissionGaming\Tactician\DTO\Round as Participant;
            ```
            MARKDOWN);

        expect($failure)->toContain('Cannot use MissionGaming\\Tactician\\DTO\\Round as Participant')
            ->and($failure)->toContain('in sample.md:8');
    });

    it('allows a closure use and a trait use in hidden setup', function (): void {
        $failure = snippetSampleFailure(<<<'MARKDOWN'
            ## Section

            <!-- snippet: setup
            trait Greets
            {
                public function greet(): string
                {
                    return 'hello';
                }
            }

            $greeter = new class () {
                use Greets;
            };
            $suffix = '!';
            $shout = static function (string $text) use ($suffix): string {
                return "{$text}{$suffix}";
            };
            -->

            ```php
            assert($shout($greeter->greet()) === 'hello!');
            ```
            MARKDOWN);

        expect($failure)->toBeNull();
    });

    it('returns what the block printed, without the earlier blocks or its own bookkeeping', function (): void {
        $extracted = DocumentationSnippets::extract(
            'sample.md',
            "## Section\n\n```php\necho \"earlier\\n\";\n```\n\n```php\necho \"line one\\nline two\\n\";\n```\n\n```php\necho \$undefined;\n```\n"
        )['snippets'];
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        expect(DocumentationSnippets::output($extracted[1], $extracted, $autoload))->toBe("line one\nline two\n")
            ->and(static fn (): string => DocumentationSnippets::output($extracted[2], $extracted, $autoload))
            ->toThrow(RuntimeException::class, 'Undefined variable $undefined in sample.md:12');
    });

    it('checks the syntax of a block by itself and reports the document line', function (): void {
        $snippets = DocumentationSnippets::extract('sample.md', <<<'MARKDOWN'
            <!-- snippet: skip reason="A sketch." -->
            ```php
            <?php

            use App\Missing\Contract;

            class Sketch implements Contract
            {
                // ...
            }
            ```

            <!-- snippet: skip reason="Hides a typo." -->
            ```php
            class Broken
            {
                public function run(): void {
                    return $this->
                }
            }
            ```
            MARKDOWN)['snippets'];

        expect(DocumentationSnippets::syntaxError($snippets[0]))->toBeNull()
            ->and(DocumentationSnippets::syntaxError($snippets[1]))->toContain('sample.md:14: the php block is not valid PHP.')
            ->and(DocumentationSnippets::syntaxError($snippets[1]))->toContain('in sample.md:19');
    });

    it('tells a skip marker that is needed from one that is stale', function (): void {
        $snippets = DocumentationSnippets::extract('sample.md', <<<'MARKDOWN'
            ## Section

            ```php
            $a = 1;
            ```

            <!-- snippet: skip reason="Needs an interface that does not exist." -->
            ```php
            class Sketch implements App\Missing\Contract
            {
            }
            ```

            <!-- snippet: skip reason="Left behind after the block was fixed." -->
            ```php
            assert($a === 1);
            ```
            MARKDOWN)['snippets'];
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        expect(DocumentationSnippets::runSkipped($snippets[1], $snippets, $autoload))
            ->toContain('sample.md:8, under "Section", run after the 1 earlier block(s)')
            ->and(DocumentationSnippets::runSkipped($snippets[2], $snippets, $autoload))->toBeNull()
            // Asking the question changes nothing: the block is still skipped
            ->and($snippets[2]->mode)->toBe(DocumentationSnippet::SKIP);
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
