<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use RuntimeException;

/**
 * Finds the PHP in a Markdown document and executes it, so a code block
 * that no longer runs fails the suite instead of shipping.
 *
 * The rules a document is written against:
 *
 * 1. Every fenced `php` block is executed in a PHP process of its own,
 *    under `strict_types=1` and `E_ALL`. Anything PHP reports - a parse
 *    error, an uncaught exception, a warning, a notice, a deprecation -
 *    fails the block.
 *
 * 2. A block continues the earlier blocks of its section. A section is
 *    everything under one level-1 or level-2 heading; deeper headings do
 *    not start a new one. The harness runs a block after the earlier
 *    blocks of the same section (their output discarded), so a block may
 *    use the variables, functions, classes and imports those blocks
 *    established, exactly as a reader working down the section would have
 *    them. Nothing carries over from one section to the next. The rule
 *    needs no annotation in the Markdown.
 *
 * 3. Imports are written in the document, never supplied by the harness:
 *    a class is imported with a `use` statement in the first block of the
 *    section that needs it, so a reader who copies a section top to
 *    bottom has a program that runs. Because a section becomes one file,
 *    a later block may repeat an import to stay readable by itself; the
 *    harness drops the repeated line (PHP rejects a duplicate `use`). A
 *    leading `<?php` and a `declare(strict_types=1);` line are dropped
 *    the same way.
 *
 * 4. Markers are HTML comments, so they do not render. A marker applies
 *    to the next `php` block and only blank lines may separate them:
 *
 *        <!-- snippet: skip reason="why this block cannot run" -->
 *            The block is not executed. The reason is mandatory. Use it
 *            for a deliberate sketch (a class that omits methods, framework
 *            code); it does not carry forward to later blocks.
 *
 *        <!-- snippet: throws="Fully\Qualified\ExceptionClass" -->
 *            The block must end in an uncaught exception of that class
 *            (or a subclass). It does not carry forward either.
 *
 *    One marker stands alone and holds code:
 *
 *        <!-- snippet: setup
 *        function playRound(RoundPairing $pairing): array { ... }
 *        -->
 *            Hidden PHP that runs ahead of the later blocks of the
 *            section. It is for what the prose calls application-side (a
 *            stub for the reader's own function), not for hiding a step
 *            the reader needs. It must not contain a `use` statement,
 *            which would supply an import the visible blocks lack.
 *
 * A marker that is malformed, has no reason, or is not followed by a
 * `php` block is reported by extract() as a problem, as is a setup
 * marker that imports a class.
 */
final class DocumentationSnippets
{
    /**
     * The documents whose blocks the suite executes, as paths relative to
     * the repository root. The CI workflow runs the test jobs when one of
     * them changes, even in a change set that is otherwise documentation
     * only (pinned by tests/Feature/CiConfigurationTest.php).
     */
    public const DOCUMENTS = ['README.md', 'docs/USAGE.md'];

    /**
     * @return array{snippets: list<DocumentationSnippet>, problems: list<string>}
     */
    public static function extract(string $file, string $markdown): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $markdown);
        assert($lines !== false);

        $snippets = [];
        $problems = [];
        $section = '';

        /** @var array{line: int, mode: DocumentationSnippet::*, reason: ?string, exception: ?string}|null $pending */
        $pending = null;

        $count = count($lines);
        for ($index = 0; $index < $count; ++$index) {
            $line = $lines[$index];
            $number = $index + 1;

            if (preg_match('/^(\s*)(`{3,}|~{3,})\s*([^\s`]*)[^`]*$/', $line, $fence) === 1) {
                [, $indent, $ticks, $language] = $fence;
                $closing = '/^\s*' . preg_quote($ticks[0], '/') . '{' . strlen($ticks) . ',}\s*$/';

                $code = [];
                $closed = false;
                for (++$index; $index < $count; ++$index) {
                    if (preg_match($closing, $lines[$index]) === 1) {
                        $closed = true;
                        break;
                    }
                    $code[] = str_starts_with($lines[$index], $indent)
                        ? substr($lines[$index], strlen($indent))
                        : ltrim($lines[$index]);
                }

                if (!$closed) {
                    $problems[] = "{$file}:{$number}: the code fence is never closed.";
                }

                if (strtolower($language) === 'php') {
                    $snippets[] = new DocumentationSnippet(
                        $file,
                        $number,
                        $section,
                        $code,
                        $pending['mode'] ?? DocumentationSnippet::RUN,
                        $pending['reason'] ?? null,
                        $pending['exception'] ?? null,
                    );
                } elseif ($pending !== null) {
                    $problems[] = self::orphan($file, $pending['line']);
                }
                $pending = null;

                continue;
            }

            if (trim($line) === '') {
                continue;
            }

            if ($pending !== null) {
                $problems[] = self::orphan($file, $pending['line']);
                $pending = null;
            }

            if (preg_match('/^#{1,2}\s+(.+?)\s*$/', $line, $heading) === 1) {
                $section = $heading[1];

                continue;
            }

            if (preg_match('/<!--\s*snippet\b/', $line) !== 1) {
                continue;
            }

            if (preg_match('/^\s*<!--\s*snippet:\s*setup\s*$/', $line) === 1) {
                $code = [];
                $closed = false;
                for (++$index; $index < $count; ++$index) {
                    if (preg_match('/^\s*-->\s*$/', $lines[$index]) === 1) {
                        $closed = true;
                        break;
                    }
                    $code[] = $lines[$index];
                }

                if (!$closed) {
                    $problems[] = "{$file}:{$number}: the setup marker is never closed with a line holding only `-->`.";
                }
                if (preg_grep('/^use\s+[\w\\\\]/', $code) !== []) {
                    $problems[] = "{$file}:{$number}: hidden setup must not import a class; "
                        . 'an import there would hide a `use` statement the visible blocks need. Write fully qualified names.';
                }
                $snippets[] = new DocumentationSnippet($file, $number, $section, $code, DocumentationSnippet::SETUP);

                continue;
            }

            if (preg_match('/^\s*<!--\s*snippet:\s*(.*?)\s*-->\s*$/', $line, $marker) !== 1) {
                $problems[] = "{$file}:{$number}: malformed snippet marker; write `<!-- snippet: ... -->` on one line.";

                continue;
            }

            if (preg_match('/^skip(?:\s+reason="([^"]*)")?$/', $marker[1], $skip) === 1) {
                $reason = trim($skip[1] ?? '');
                if ($reason === '') {
                    $problems[] = "{$file}:{$number}: the skip marker gives no reason; write `skip reason=\"...\"`.";
                }
                $pending = ['line' => $number, 'mode' => DocumentationSnippet::SKIP, 'reason' => $reason, 'exception' => null];
            } elseif (preg_match('/^throws="\\\\?([\w\\\\]+)"$/', $marker[1], $throws) === 1) {
                $pending = ['line' => $number, 'mode' => DocumentationSnippet::THROWS, 'reason' => null, 'exception' => $throws[1]];
            } else {
                $problems[] = "{$file}:{$number}: unknown snippet marker `{$marker[1]}`; "
                    . 'the markers are `skip reason="..."`, `throws="Exception\Class"` and `setup`.';
            }
        }

        if ($pending !== null) {
            $problems[] = self::orphan($file, $pending['line']);
        }

        return ['snippets' => $snippets, 'problems' => $problems];
    }

    /**
     * The number of lines that open a `php` fence, counted without the
     * extractor's state machine, so a block the extractor walks past
     * shows up as a difference between the two counts.
     */
    public static function countPhpFences(string $markdown): int
    {
        return (int) preg_match_all('/^\s*(?:`{3,}|~{3,})\s*php\b/mi', $markdown);
    }

    /**
     * Execute one block and say what went wrong.
     *
     * @param list<DocumentationSnippet> $all Every snippet of the block's document, in document order
     * @param string $autoload Path of the Composer autoloader the block runs against
     *
     * @return string|null Null when the block behaved as its mode demands, the failure message otherwise
     *
     * @throws RuntimeException When the block cannot be written to disk or PHP cannot be started
     */
    public static function run(DocumentationSnippet $target, array $all, string $autoload): ?string
    {
        // Marks the line on which a THROWS block reports what it threw; it
        // only has to differ from anything a block prints
        $nonce = 'snippet-threw-' . hash('sha256', $target->location() . '|' . microtime() . '|' . getmypid()) . ':';
        ['source' => $source, 'lines' => $lineMap, 'earlier' => $earlier] = self::script($target, $all, $autoload, $nonce);

        $script = self::temporaryFile();
        $stdout = self::temporaryFile();
        $stderr = self::temporaryFile();

        try {
            file_put_contents($script, $source);

            $process = proc_open(
                [
                    PHP_BINARY,
                    '-d', 'error_reporting=-1',
                    '-d', 'display_errors=stderr',
                    '-d', 'log_errors=0',
                    '-d', 'html_errors=0',
                    '-d', 'zend.assertions=1',
                    '-d', 'assert.exception=1',
                    $script,
                ],
                [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']],
                $pipes
            );
            if ($process === false) {
                throw new RuntimeException('Could not start PHP to run ' . $target->location());
            }
            $exitCode = proc_close($process);

            $output = (string) file_get_contents($stdout);
            $errors = trim(self::translate((string) file_get_contents($stderr), $script, $target->file, $lineMap));
        } finally {
            foreach ([$script, $stdout, $stderr] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        $context = sprintf(
            '%s, under "%s"%s',
            $target->location(),
            $target->section,
            $earlier === 0 ? '' : sprintf(', run after the %d earlier block(s) of that section', $earlier)
        );

        if ($target->mode === DocumentationSnippet::THROWS) {
            $expected = (string) $target->exception;
            $thrown = preg_match('/' . preg_quote($nonce, '/') . '(\S+)/', $output, $matches) === 1 ? $matches[1] : null;

            if ($thrown === null) {
                return "{$context}: the php block is marked throws=\"{$expected}\" but "
                    . ($errors === '' ? 'ended without throwing.' : "failed differently:\n{$errors}");
            }
            if (!is_a($thrown, $expected, true)) {
                return "{$context}: the php block is marked throws=\"{$expected}\" but threw {$thrown}.";
            }

            return $errors === '' ? null : "{$context}: the php block threw {$thrown} as marked, but PHP also reported:\n{$errors}";
        }

        if ($exitCode !== 0 || $errors !== '') {
            return "{$context}: the php block failed (exit code {$exitCode}).\n"
                . ($errors === '' ? '(PHP reported nothing on stderr.)' : $errors);
        }

        return null;
    }

    /**
     * The program a block is executed as: the harness preamble, the
     * earlier blocks of the section with their output discarded, then
     * the block itself.
     *
     * @param list<DocumentationSnippet> $all
     *
     * @return array{source: string, lines: array<int, int|null>, earlier: int} The source, the
     *     document line each 1-based script line came from (null for harness lines), and the
     *     number of earlier visible blocks the target runs after
     */
    public static function script(DocumentationSnippet $target, array $all, string $autoload, string $nonce = ''): array
    {
        $source = ['<?php', 'declare(strict_types=1);', 'require ' . var_export($autoload, true) . ';'];
        $map = [1 => null, 2 => null, 3 => null];
        $imports = [];

        $harness = static function (string $line) use (&$source, &$map): void {
            $source[] = $line;
            $map[count($source)] = null;
        };

        $append = static function (DocumentationSnippet $snippet) use (&$source, &$map, &$imports): void {
            $first = true;
            foreach ($snippet->code as $offset => $line) {
                if ($first && trim($line) !== '') {
                    $first = false;
                    if (preg_match('/^<\?php\s*$/', $line) === 1) {
                        $line = '';
                    }
                }
                if (preg_match('/^declare\s*\(\s*strict_types\s*=\s*[01]\s*\)\s*;\s*$/', $line) === 1) {
                    $line = '';
                }
                if (preg_match('/^use\s+((?:function|const)\s+)?\\\\?([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;\s*$/', $line, $import) === 1) {
                    $key = trim($import[1]) . '|' . $import[2] . '|' . ($import[3] ?? '');
                    if (isset($imports[$key])) {
                        $line = '';
                    }
                    $imports[$key] = true;
                }

                $source[] = $line;
                $map[count($source)] = $snippet->line + 1 + $offset;
            }
        };

        $predecessors = [];
        foreach ($all as $snippet) {
            if ($snippet === $target) {
                break;
            }
            if ($snippet->file === $target->file && $snippet->section === $target->section && $snippet->carriesForward()) {
                $predecessors[] = $snippet;
            }
        }

        if ($predecessors !== []) {
            $harness('ob_start();');
            foreach ($predecessors as $predecessor) {
                $append($predecessor);
            }
            $harness('ob_end_clean();');
        }

        if ($target->mode === DocumentationSnippet::THROWS) {
            $harness(
                'set_exception_handler(static function (\Throwable $e): void { fwrite(STDOUT, "\n" . '
                . var_export($nonce, true) . ' . $e::class . "\n"); exit(0); });'
            );
        }

        $append($target);

        return [
            'source' => implode("\n", $source) . "\n",
            'lines' => $map,
            'earlier' => count(array_filter(
                $predecessors,
                static fn (DocumentationSnippet $snippet): bool => $snippet->mode === DocumentationSnippet::RUN
            )),
        ];
    }

    /**
     * Rewrite PHP's references to the temporary script as references to
     * the document, so the message points at the line a writer edits.
     *
     * @param array<int, int|null> $lineMap
     */
    private static function translate(string $errors, string $script, string $file, array $lineMap): string
    {
        $path = preg_quote($script, '~');

        return (string) preg_replace_callback(
            "~{$path}(?::(\\d+)| on line (\\d+)|\\((\\d+)\\))~",
            static function (array $match) use ($file, $lineMap): string {
                // One of the three alternatives captured the line number
                $scriptLine = (int) implode('', array_slice($match, 1));
                $documentLine = $lineMap[$scriptLine] ?? null;

                return $documentLine === null ? "{$file} (harness preamble)" : "{$file}:{$documentLine}";
            },
            $errors
        );
    }

    private static function orphan(string $file, int $line): string
    {
        return "{$file}:{$line}: the snippet marker is not followed by a php block; only blank lines may separate them.";
    }

    /**
     * @throws RuntimeException
     */
    private static function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'snippet');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file in ' . sys_get_temp_dir());
        }

        return realpath($path) ?: $path;
    }
}
