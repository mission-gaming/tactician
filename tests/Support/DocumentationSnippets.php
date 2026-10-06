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
 *    fails the block. So does a block that never reaches its last line
 *    (`exit`, `die`, a top-level `return`, in it or in an earlier block of
 *    the section) and one that turns PHP's error reporting down: either
 *    would otherwise pass without having shown anything.
 *
 * 2. A block continues the earlier blocks of its section. A section is
 *    everything under one level-1 or level-2 heading, written with `#`
 *    marks or underlined; deeper headings do not start a new one, and two headings with the same text are two
 *    sections. The harness runs a block after the earlier
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
 *            the reader needs. It must not import or alias a class (a
 *            `use` statement, `class_alias()`), which would supply a name
 *            the visible blocks lack.
 *
 * A marker that is malformed, has no reason, or is not followed by a
 * `php` block is reported by extract() as a problem, as is a setup
 * marker that imports or aliases a class.
 */
final class DocumentationSnippets
{
    /**
     * The documents whose blocks the suite executes, as paths relative to
     * the repository root. The CI workflow runs the test jobs for every
     * change set, a documentation-only one included (pinned by
     * tests/Feature/CiConfigurationTest.php).
     */
    public const array DOCUMENTS = ['README.md', 'docs/USAGE.md'];

    /**
     * How long one block (with the earlier blocks of its section) may run,
     * in seconds of wall-clock time. The blocks of the two documents finish
     * in well under a second each; the limit is far above that so a slow
     * machine does not trip it, and exists so that a block that never
     * returns fails by name instead of hanging the whole suite.
     */
    public const float TIME_LIMIT = 30.0;

    /** What follows the nonce on the line a RUN block prints when it reaches its end. */
    private const string FINISHED = 'finished';

    /** How often a running block is checked for having finished, in microseconds. */
    private const int POLL_INTERVAL = 5_000;

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
        $sectionLine = 0;

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
                        $sectionLine,
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
                $sectionLine = $number;

                continue;
            }

            // The other spelling of a level-1 or level-2 heading: a line of
            // text underlined with `=` or `-`
            if (
                $index > 0
                && preg_match('/^ {0,3}(?:=+|-+)\s*$/', $line) === 1
                && preg_match('/^ {0,3}[^\s#>|<`~*+=-]/', $lines[$index - 1]) === 1
            ) {
                $section = trim($lines[$index - 1]);
                $sectionLine = $number - 1;

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
                if (self::suppliesClassName($code)) {
                    $problems[] = "{$file}:{$number}: hidden setup must not import a class or alias one; "
                        . 'that would hide a `use` statement the visible blocks need. Write fully qualified names.';
                }
                $snippets[] = new DocumentationSnippet(
                    $file,
                    $number,
                    $section,
                    $code,
                    DocumentationSnippet::SETUP,
                    sectionLine: $sectionLine,
                );

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
     * @param float $timeLimit Seconds the block may run before it is stopped and failed
     *
     * @return string|null Null when the block behaved as its mode demands, the failure message otherwise
     *
     * @throws RuntimeException When the block cannot be written to disk or PHP cannot be started
     */
    public static function run(DocumentationSnippet $target, array $all, string $autoload, float $timeLimit = self::TIME_LIMIT): ?string
    {
        return self::attempt($target, $all, $autoload, $timeLimit)['failure'];
    }

    /**
     * Execute one block and return what it printed, so a test can hold the
     * output against what the prose around the block promises. The output
     * of the earlier blocks of the section is not part of it.
     *
     * @param list<DocumentationSnippet> $all Every snippet of the block's document, in document order
     *
     * @throws RuntimeException When the block does not behave as its mode demands, or PHP cannot be started
     */
    public static function output(DocumentationSnippet $target, array $all, string $autoload): string
    {
        ['failure' => $failure, 'output' => $output] = self::attempt($target, $all, $autoload);
        if ($failure !== null) {
            throw new RuntimeException($failure);
        }

        return $output;
    }

    /**
     * @param list<DocumentationSnippet> $all
     *
     * @return array{failure: string|null, output: string} The failure message (null when the block
     *     behaved as its mode demands) and the block's standard output without the harness's own lines
     *
     * @throws RuntimeException When the block cannot be written to disk or PHP cannot be started
     */
    private static function attempt(DocumentationSnippet $target, array $all, string $autoload, float $timeLimit = self::TIME_LIMIT): array
    {
        // Marks the lines on which the script reports what a THROWS block
        // threw, or that a RUN block reached its end; it only has to differ
        // from anything a block prints
        $nonce = 'snippet-' . hash('sha256', $target->location() . '|' . microtime() . '|' . getmypid()) . ':';
        ['source' => $source, 'lines' => $lineMap, 'earlier' => $earlier] = self::script($target, $all, $autoload, $nonce);

        ['exitCode' => $exitCode, 'output' => $output, 'errors' => $errors, 'timedOut' => $timedOut] = self::execute(
            $source,
            [
                '-d', 'error_reporting=-1',
                '-d', 'display_errors=stderr',
                '-d', 'log_errors=0',
                '-d', 'html_errors=0',
                '-d', 'zend.assertions=1',
                '-d', 'assert.exception=1',
            ],
            $target,
            $lineMap,
            $timeLimit
        );

        $context = sprintf(
            '%s, under "%s"%s',
            $target->location(),
            $target->section,
            $earlier === 0 ? '' : sprintf(', run after the %d earlier block(s) of that section', $earlier)
        );

        // The harness's own lines (each follows a newline it wrote itself) are not the block's output
        $result = static fn(?string $failure): array => [
            'failure' => $failure,
            'output' => (string) preg_replace('/\n' . preg_quote($nonce, '/') . '\S*\n/', '', $output),
        ];

        // A stopped block proved nothing, whatever it printed before the limit
        if ($timedOut) {
            return $result(sprintf(
                '%s: the php block did not finish within %s second(s) and was stopped. It, or an earlier block of the '
                . 'section, never returns (an endless loop, or a wait for input that does not come). A documentation '
                . 'block must run to its end.',
                $context,
                rtrim(rtrim(sprintf('%.3F', $timeLimit), '0'), '.')
            ));
        }

        if ($target->mode === DocumentationSnippet::THROWS) {
            $expected = (string) $target->exception;
            $thrown = preg_match('/' . preg_quote($nonce, '/') . '(\S+)/', $output, $matches) === 1 ? $matches[1] : null;

            if ($thrown === null) {
                return $result("{$context}: the php block is marked throws=\"{$expected}\" but "
                    . ($errors === '' ? 'ended without throwing.' : "failed differently:\n{$errors}"));
            }
            if (!is_a($thrown, $expected, true)) {
                return $result("{$context}: the php block is marked throws=\"{$expected}\" but threw {$thrown}.");
            }

            return $result(
                $errors === '' ? null : "{$context}: the php block threw {$thrown} as marked, but PHP also reported:\n{$errors}"
            );
        }

        if ($exitCode !== 0 || $errors !== '') {
            return $result("{$context}: the php block failed (exit code {$exitCode}).\n"
                . ($errors === '' ? '(PHP reported nothing on stderr.)' : $errors));
        }

        // Exit code 0 and a silent stderr are also what a script that stopped
        // early leaves behind, so the block has to prove it got to its end
        if (preg_match('/^' . preg_quote($nonce . self::FINISHED, '/') . '$/m', $output) !== 1) {
            return $result("{$context}: the php block did not run to its end. It, or an earlier block of the section, "
                . 'stopped the script (exit, die, a top-level return, or an exception handler that swallowed an '
                . 'exception), so nothing after that point was executed.');
        }

        return $result(null);
    }

    /**
     * Whether a block is valid PHP syntax, checked by itself with `php -l`.
     * A skipped block is never executed, so this is the one check it gets.
     *
     * @return string|null Null when the block parses, PHP's message otherwise
     *
     * @throws RuntimeException When the block cannot be written to disk or PHP cannot be started
     */
    public static function syntaxError(DocumentationSnippet $snippet): ?string
    {
        $lineMap = [1 => null];
        foreach (array_keys($snippet->code) as $offset) {
            $lineMap[$offset + 2] = $snippet->line + 1 + $offset;
        }

        $code = $snippet->code;
        foreach ($code as $offset => $line) {
            if (trim($line) !== '') {
                // The block may open with its own tag; the linted file already has one
                $code[$offset] = preg_match('/^<\?php\s*$/', $line) === 1 ? '' : $line;

                break;
            }
        }

        ['exitCode' => $exitCode, 'output' => $output, 'errors' => $errors] = self::execute(
            "<?php\n" . implode("\n", $code) . "\n",
            ['-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'html_errors=0', '-l'],
            $snippet,
            $lineMap
        );

        return $exitCode === 0 && $errors === ''
            ? null
            : "{$snippet->location()}: the php block is not valid PHP.\n" . ($errors === '' ? trim($output) : $errors);
    }

    /**
     * What happens when a skipped block is executed after all, as a RUN
     * block in the same place. A skip marker is only earned by a block
     * that cannot run, so a null here means the marker is stale: the block
     * runs cleanly and the marker is hiding it from the suite.
     *
     * @param list<DocumentationSnippet> $all Every snippet of the block's document, in document order
     *
     * @return string|null The failure the marker spares the block, null when it would have passed
     *
     * @throws RuntimeException When the block cannot be written to disk or PHP cannot be started
     */
    public static function runSkipped(DocumentationSnippet $skipped, array $all, string $autoload): ?string
    {
        $unskipped = new DocumentationSnippet(
            $skipped->file,
            $skipped->line,
            $skipped->section,
            $skipped->code,
            DocumentationSnippet::RUN,
            sectionLine: $skipped->sectionLine,
        );

        return self::run(
            $unskipped,
            array_map(
                static fn(DocumentationSnippet $snippet): DocumentationSnippet => $snippet === $skipped ? $unskipped : $snippet,
                $all
            ),
            $autoload
        );
    }

    /**
     * The program a block is executed as: the harness preamble, the
     * earlier blocks of the section with their output discarded, the
     * block itself, and (unless the block must throw) a closing line that
     * checks error reporting is still on and reports that the end was
     * reached.
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
            if ($snippet->file === $target->file && $snippet->inSameSectionAs($target) && $snippet->carriesForward()) {
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

        if ($target->mode !== DocumentationSnippet::THROWS) {
            // On a line of its own, so a block left open (a trailing comment,
            // a heredoc) cannot swallow it
            $harness(
                "if ((error_reporting() & E_ALL) !== E_ALL || ini_get('display_errors') !== 'stderr') { fwrite(STDERR, "
                . "'The block changed error_reporting or display_errors, so what PHP reports could no longer fail it.' . \"\\n\"); "
                . 'exit(1); } fwrite(STDOUT, "\n" . ' . var_export($nonce . self::FINISHED, true) . ' . "\n");'
            );
        }

        return [
            'source' => implode("\n", $source) . "\n",
            'lines' => $map,
            'earlier' => count(array_filter(
                $predecessors,
                static fn(DocumentationSnippet $snippet): bool => $snippet->mode === DocumentationSnippet::RUN
            )),
        ];
    }

    /**
     * Run a source file through PHP and collect what it did. The process is
     * given a wall-clock limit: one that is still running when the limit
     * passes is killed, and reported as timed out.
     *
     * @param list<string> $arguments Arguments placed before the script path
     * @param array<int, int|null> $lineMap
     *
     * @return array{exitCode: int, output: string, errors: string, timedOut: bool} The exit code,
     *     standard output, standard error with its references to the script rewritten as references
     *     to the document, and whether the process had to be stopped
     *
     * @throws RuntimeException When the source cannot be written to disk or PHP cannot be started
     */
    private static function execute(
        string $source,
        array $arguments,
        DocumentationSnippet $snippet,
        array $lineMap,
        float $timeLimit = self::TIME_LIMIT
    ): array {
        $script = self::temporaryFile();
        $stdout = self::temporaryFile();
        $stderr = self::temporaryFile();

        try {
            file_put_contents($script, $source);

            $process = proc_open(
                [PHP_BINARY, ...$arguments, $script],
                [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']],
                $pipes
            );
            if ($process === false) {
                throw new RuntimeException('Could not start PHP to run ' . $snippet->location());
            }

            // Wait for the process, but not for ever. The exit code is read
            // from the status that first reports the process as finished:
            // after that, proc_close() no longer has it on every PHP version
            $deadline = microtime(true) + $timeLimit;
            $exitCode = null;
            $timedOut = false;
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];

                    break;
                }
                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    // SIGKILL: a block stuck in a loop does not answer a polite signal
                    proc_terminate($process, 9);

                    break;
                }
                usleep(self::POLL_INTERVAL);
            }
            $closed = proc_close($process);

            return [
                'exitCode' => $exitCode ?? $closed,
                'output' => self::translate((string) file_get_contents($stdout), $script, $snippet->file, $lineMap),
                'errors' => trim(self::translate((string) file_get_contents($stderr), $script, $snippet->file, $lineMap)),
                'timedOut' => $timedOut,
            ];
        } finally {
            foreach ([$script, $stdout, $stderr] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /**
     * Whether hidden setup code hands the visible blocks a class name they
     * did not import: a `use` statement or a `class_alias()` call. The code
     * is tokenized, so an indented statement, or one that shares its line
     * with another, is found as well. A closure's `use (...)` and a trait
     * `use` inside a class body are not imports.
     *
     * @param list<string> $code
     */
    private static function suppliesClassName(array $code): bool
    {
        $depth = 0;
        $previous = null;

        foreach (token_get_all("<?php\n" . implode("\n", $code)) as $token) {
            $id = is_array($token) ? $token[0] : $token;
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$depth;
            } elseif ($id === '}') {
                --$depth;
            } elseif ($id === T_USE && $depth === 0 && $previous !== ')') {
                return true;
            } elseif (
                is_array($token)
                && in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                && strtolower(ltrim($token[1], '\\')) === 'class_alias'
            ) {
                return true;
            }

            $previous = $id;
        }

        return false;
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
