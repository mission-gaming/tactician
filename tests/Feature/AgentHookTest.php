<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

// .claude/ holds optional settings for contributors who use an AI coding
// agent. One part of it is executable: after the agent edits a file, the
// agent runs .claude/hooks/format-and-analyse.sh with the tool call as JSON
// on stdin. The hook must stay out of the way for everything it does not
// check (exit 0, nothing printed), and must hand PHPStan's errors back (exit
// 2, errors on stderr) for a PHP file it does check. These tests run the
// real script.
//
// The route that reports an error needs a PHP file under src/, tests/ or
// examples/ of this repository, because the hook finds the repository from
// its own location. The test writes one to a directory it creates under
// tests/ and removes it again in a `finally`; a run that is killed half-way
// can leave tests/agent-hook-probe-* or examples/agent-hook-probe-* behind,
// which is safe to delete.
//
// The second group runs a copy of the script in a temporary tree, where
// vendor/bin holds stand-ins that record how they were called. That shows
// which tool the hook starts, from where and with which arguments, and what
// it does with each way a tool can end, without a real PHPStan run per case.
//
// Gap left knowingly - nothing here proves that an agent reads
// .claude/settings.json the way these tests do, or that it calls the hook
// with this JSON shape. Only a session with the agent shows that.

const AGENT_HOOK_SCRIPT = '.claude/hooks/format-and-analyse.sh';

/**
 * Run a copy of the hook, or the hook itself, with the given stdin.
 *
 * @param string|null $cwd The working directory, or null for the current one
 * @param array<string, string> $environment Variables added to the current environment
 *
 * @throws RuntimeException When the process cannot be started
 *
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function runAgentHook(string $script, string $stdin, ?string $cwd = null, array $environment = []): array
{
    // The output goes to temporary files, not pipes: nothing reads a pipe
    // while stdin is being written, and a full pipe blocks its writer.
    $stdout = tmpfile();
    $stderr = tmpfile();

    if ($stdout === false || $stderr === false) {
        throw new RuntimeException('Could not create temporary files for the hook output.');
    }

    $process = proc_open(
        ['bash', $script],
        [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr],
        $pipes,
        $cwd,
        $environment === [] ? null : array_merge(getenv(), $environment)
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the hook.');
    }

    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $exitCode = proc_close($process);

    rewind($stdout);
    rewind($stderr);
    $result = [
        'exitCode' => $exitCode,
        'stdout' => (string) stream_get_contents($stdout),
        'stderr' => (string) stream_get_contents($stderr),
    ];
    fclose($stdout);
    fclose($stderr);

    return $result;
}

/**
 * The JSON an agent sends for an edit of the given file.
 */
function agentHookToolCall(string $path): string
{
    return (string) json_encode([
        'hook_event_name' => 'PostToolUse',
        'tool_name' => 'Edit',
        'tool_input' => ['file_path' => $path, 'old_string' => 'a', 'new_string' => 'b'],
    ]);
}

/**
 * A PHP file that PHP-CS-Fixer leaves alone, returning the given expression
 * from a function declared to return int.
 */
function agentHookProbeSource(string $function, string $expression): string
{
    return "<?php\n\ndeclare(strict_types=1);\n\nfunction {$function}(): int\n{\n    return {$expression};\n}\n";
}

function agentHookRemoveDirectory(string $directory): void
{
    exec('rm -rf ' . escapeshellarg($directory));
}

/**
 * A temporary directory that holds `repo`, a tree with a copy of the hook and
 * the directories the hook looks at, but no vendor/. Returns the directory;
 * the tree is at `<directory>/repo`. Remove the directory after the test.
 *
 * @throws RuntimeException When the directory cannot be created
 * @throws Random\RandomException When no random name can be made
 */
function agentHookSandbox(string $hook): string
{
    // The real path: the hook resolves links, and the temporary directory
    // is behind one on some systems.
    $base = realpath(sys_get_temp_dir()) . '/tactician-agent-hook-' . bin2hex(random_bytes(6));

    foreach (['.claude/hooks', 'src', 'tests', 'examples', 'docs'] as $directory) {
        if (!mkdir($base . '/repo/' . $directory, 0o777, true)) {
            throw new RuntimeException('Could not create the sandbox for the hook.');
        }
    }

    copy($hook, $base . '/repo/' . AGENT_HOOK_SCRIPT);

    return $base;
}

/**
 * Put a stand-in for one of the two tools into the tree's vendor/bin. It adds
 * one line to `<tree>/calls.log` (its name, its working directory and its
 * arguments, as JSON), prints what it is given and ends with the given status.
 */
function agentHookStandIn(string $tree, string $tool, int $exitCode = 0, string $stdout = '', string $stderr = ''): void
{
    if (!is_dir($tree . '/vendor/bin')) {
        mkdir($tree . '/vendor/bin', 0o777, true);
    }

    $file = $tree . '/vendor/bin/' . $tool;

    file_put_contents($file, implode("\n", [
        '#!/usr/bin/env php',
        '<?php',
        'file_put_contents(' . var_export($tree . '/calls.log', true) . ', json_encode([' . var_export($tool, true) . ', getcwd(), array_slice($argv, 1)]) . "\n", FILE_APPEND);',
        'fwrite(STDOUT, ' . var_export($stdout, true) . ');',
        'fwrite(STDERR, ' . var_export($stderr, true) . ');',
        'exit(' . $exitCode . ');',
        '',
    ]));
    chmod($file, 0o755);
}

/**
 * The calls the stand-ins recorded, in order.
 *
 * @throws RuntimeException When a line of the log is not a recorded call
 *
 * @return list<array{tool: string, cwd: string, arguments: list<string>}>
 */
function agentHookCalls(string $tree): array
{
    $calls = [];

    // No stand-in was started: there is no log.
    if (!is_file($tree . '/calls.log')) {
        return $calls;
    }

    foreach (file($tree . '/calls.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $call = json_decode($line, true);

        if (!is_array($call) || !is_string($call[0] ?? null) || !is_string($call[1] ?? null) || !is_array($call[2] ?? null)) {
            throw new RuntimeException("The stand-in log holds a line that is not a call: {$line}");
        }

        $calls[] = [
            'tool' => $call[0],
            'cwd' => $call[1],
            'arguments' => array_values(array_filter($call[2], is_string(...))),
        ];
    }

    return $calls;
}

$root = dirname(__DIR__, 2);
$hook = $root . '/' . AGENT_HOOK_SCRIPT;

describe('agent hook', function () use ($root, $hook): void {
    it('does nothing for a path it does not check', function (string $path) use ($hook): void {
        expect(runAgentHook($hook, agentHookToolCall($path)))
            ->toBe(['exitCode' => 0, 'stdout' => '', 'stderr' => '']);
    })->with([
        'a file that is not PHP' => [$root . '/README.md'],
        'a file under tests/ that is not PHP' => [$root . '/tests/Fixtures/repack-scenario.json'],
        'a PHP file at the repository root' => [$root . '/rector.php'],
        'a PHP file outside the three directories, reached through tests/' => [$root . '/tests/../rector.php'],
        'a PHP file under vendor/' => [$root . '/vendor/autoload.php'],
        'a PHP file that does not exist' => [$root . '/src/DoesNotExist.php'],
        'a relative path that does not exist' => ['src/DoesNotExist.php'],
        // phpstan.neon excludes this file, so PHPStan fails with "No files
        // found to analyse". That is not an error in the file.
        'a PHP file that PHPStan is configured to skip' => [$root . '/tests/Pest.php'],
    ]);

    it('does nothing for input that is not a tool call', function (string $stdin) use ($hook): void {
        expect(runAgentHook($hook, $stdin))
            ->toBe(['exitCode' => 0, 'stdout' => '', 'stderr' => '']);
    })->with([
        'malformed JSON' => ['{"tool_input": {"file_path": '],
        'no input' => [''],
        'JSON that is not an object' => ['"tests/Pest.php"'],
        'no tool input' => ['{"tool_name": "Edit"}'],
        'tool input that is not an object' => ['{"tool_input": "tests/Pest.php"}'],
        'a file path that is not a string' => ['{"tool_input": {"file_path": ["tests/Pest.php"]}}'],
    ]);

    it('reports a PHPStan error on stderr with exit status 2, and passes the corrected file', function () use ($root, $hook): void {
        $suffix = bin2hex(random_bytes(6));
        $directory = $root . '/tests/agent-hook-probe-' . $suffix;
        $file = $directory . '/Probe.php';
        $function = 'agentHookProbe' . $suffix;

        mkdir($directory);

        try {
            // Double quotes, so that the file also needs formatting.
            file_put_contents($file, agentHookProbeSource($function, '"not an integer"'));
            $red = runAgentHook($hook, agentHookToolCall($file));
            $formatted = (string) file_get_contents($file);

            file_put_contents($file, agentHookProbeSource($function, '1'));
            $green = runAgentHook($hook, agentHookToolCall($file));
        } finally {
            agentHookRemoveDirectory($directory);
        }

        expect($red['exitCode'])->toBe(2)
            ->and($red['stdout'])->toBe('')
            ->and($red['stderr'])->toContain("Function {$function}() should return int but returns string.")
            ->and($red['stderr'])->toContain('Probe.php:7:')
            // PHP-CS-Fixer ran first: the string is single-quoted now.
            ->and($formatted)->toContain("return 'not an integer';")
            ->and($green)->toBe(['exitCode' => 0, 'stdout' => '', 'stderr' => ''])
            ->and(is_dir($directory))->toBeFalse();
    });

    it('reports a file that does not parse', function () use ($root, $hook): void {
        $directory = $root . '/tests/agent-hook-probe-' . bin2hex(random_bytes(6));
        $file = $directory . '/Probe.php';
        $source = "<?php\n\ndeclare(strict_types=1);\n\nfunction agentHookUnclosed(): int\n{\n    return 1;\n";

        mkdir($directory);

        try {
            file_put_contents($file, $source);
            $result = runAgentHook($hook, agentHookToolCall($file));
            $after = (string) file_get_contents($file);
        } finally {
            agentHookRemoveDirectory($directory);
        }

        expect($result['exitCode'])->toBe(2)
            ->and($result['stdout'])->toBe('')
            ->and($result['stderr'])->toContain('Probe.php:')
            ->and($result['stderr'])->toContain('Syntax error')
            // PHP-CS-Fixer cannot format it, and leaves it as it was.
            ->and($after)->toBe($source);
    });

    it('does not follow a symbolic link to a file outside the repository', function () use ($root, $hook): void {
        // The target has a type error and needs formatting. If the hook
        // followed the link, PHP-CS-Fixer would rewrite a file outside the
        // repository and PHPStan would report it.
        $suffix = bin2hex(random_bytes(6));
        $outside = sys_get_temp_dir() . '/tactician-agent-hook-' . $suffix;
        $directory = $root . '/tests/agent-hook-probe-' . $suffix;
        $source = agentHookProbeSource('agentHookProbe' . $suffix, '"not an integer"');

        mkdir($outside);
        mkdir($directory);

        try {
            file_put_contents($outside . '/Target.php', $source);
            symlink($outside . '/Target.php', $directory . '/Link.php');

            $result = runAgentHook($hook, agentHookToolCall($directory . '/Link.php'));
            $after = (string) file_get_contents($outside . '/Target.php');
        } finally {
            agentHookRemoveDirectory($directory);
            agentHookRemoveDirectory($outside);
        }

        expect($result)->toBe(['exitCode' => 0, 'stdout' => '', 'stderr' => ''])
            ->and($after)->toBe($source);
    });

    it('does not follow a symbolic link to a directory outside the repository', function () use ($root, $hook): void {
        $suffix = bin2hex(random_bytes(6));
        $outside = sys_get_temp_dir() . '/tactician-agent-hook-' . $suffix;
        $link = $root . '/tests/agent-hook-probe-' . $suffix;
        $source = agentHookProbeSource('agentHookProbe' . $suffix, '"not an integer"');

        mkdir($outside);

        try {
            file_put_contents($outside . '/Probe.php', $source);
            symlink($outside, $link);

            $result = runAgentHook($hook, agentHookToolCall($link . '/Probe.php'));
            $after = (string) file_get_contents($outside . '/Probe.php');
        } finally {
            // The link itself, not the directory behind it.
            unlink($link);
            agentHookRemoveDirectory($outside);
        }

        expect($result)->toBe(['exitCode' => 0, 'stdout' => '', 'stderr' => ''])
            ->and($after)->toBe($source);
    });

    it('treats the file path as data, never as shell text', function () use ($root, $hook): void {
        // A file name made of shell syntax. If any expansion in the hook
        // were unquoted or evaluated, one of the markers would be created.
        $suffix = bin2hex(random_bytes(6));
        $directory = $root . '/tests/agent-hook-probe-' . $suffix;
        $marker = 'agent-hook-marker-' . $suffix;
        $file = $directory . '/a $(touch ' . $marker . ') `touch ' . $marker . '` ; touch ' . $marker . ' #.php';

        mkdir($directory);

        try {
            file_put_contents($file, agentHookProbeSource('agentHookProbe' . $suffix, '1'));
            $result = runAgentHook($hook, agentHookToolCall($file));
            $created = array_filter(
                [$root . '/' . $marker, $directory . '/' . $marker, getcwd() . '/' . $marker],
                file_exists(...)
            );
        } finally {
            agentHookRemoveDirectory($directory);

            if (is_file($root . '/' . $marker)) {
                unlink($root . '/' . $marker);
            }
        }

        expect($created)->toBe([])
            ->and($result)->toBe(['exitCode' => 0, 'stdout' => '', 'stderr' => '']);
    });

    it('formats a file under examples/ and does not analyse it', function () use ($root, $hook): void {
        // phpstan.neon analyses src/ and tests/ only, so the example scripts
        // are not held to level 8 by the gate. This one has an untyped
        // function, which level 8 reports, and it needs formatting.
        $directory = $root . '/examples/agent-hook-probe-' . bin2hex(random_bytes(6));
        $file = $directory . '/Probe.php';

        mkdir($directory);

        try {
            file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\nfunction agentHookExample(\$value)\n{\n    return \"text\";\n}\n");
            $result = runAgentHook($hook, agentHookToolCall($file));
            $formatted = (string) file_get_contents($file);
        } finally {
            agentHookRemoveDirectory($directory);
        }

        expect($result)->toBe(['exitCode' => 0, 'stdout' => '', 'stderr' => ''])
            ->and($formatted)->toContain("return 'text';");
    });
});

describe('agent hook, with stand-ins for the tools', function () use ($hook): void {
    it('runs the tools from the repository root with the file as one argument', function (string $relative, array $expectedTools) use ($hook): void {
        $base = agentHookSandbox($hook);
        $tree = $base . '/repo';
        $file = $tree . '/' . $relative;

        try {
            agentHookStandIn($tree, 'php-cs-fixer');
            agentHookStandIn($tree, 'phpstan', 1, "first error\nsecond error\n");
            file_put_contents($file, "<?php\n");

            $result = runAgentHook($tree . '/' . AGENT_HOOK_SCRIPT, agentHookToolCall($file));
            $calls = agentHookCalls($tree);
        } finally {
            agentHookRemoveDirectory($base);
        }

        expect(array_column($calls, 'tool'))->toBe($expectedTools);

        foreach ($calls as $call) {
            // The path is the last argument and follows `--`, so a name that
            // starts with a dash or holds a space is still one file.
            expect($call['cwd'])->toBe($tree)
                ->and(array_slice($call['arguments'], -2))->toBe(['--', $file]);
        }

        expect($result)->toBe(
            in_array('phpstan', $expectedTools, true)
                ? ['exitCode' => 2, 'stdout' => '', 'stderr' => "first error\nsecond error\n"]
                : ['exitCode' => 0, 'stdout' => '', 'stderr' => '']
        );
    })->with([
        'a file under src/' => ['src/Probe.php', ['php-cs-fixer', 'phpstan']],
        'a file under tests/' => ['tests/Probe.php', ['php-cs-fixer', 'phpstan']],
        'a file under examples/, which the gate does not analyse' => ['examples/Probe.php', ['php-cs-fixer']],
        'a name made of spaces and shell syntax' => ['src/a b $(c) `d` ; e #.php', ['php-cs-fixer', 'phpstan']],
        'a name that starts with a dash' => ['tests/--version.php', ['php-cs-fixer', 'phpstan']],
    ]);

    it('acts on the file a path leads to inside the repository', function (string $path, ?string $cwd) use ($hook): void {
        $base = agentHookSandbox($hook);
        $tree = $base . '/repo';

        try {
            agentHookStandIn($tree, 'php-cs-fixer');
            agentHookStandIn($tree, 'phpstan');
            file_put_contents($tree . '/src/Probe.php', "<?php\n");
            symlink($tree, $base . '/link');

            $result = runAgentHook(
                $tree . '/' . AGENT_HOOK_SCRIPT,
                agentHookToolCall(str_replace(['{tree}', '{base}'], [$tree, $base], $path)),
                $cwd === null ? null : str_replace('{base}', $base, $cwd)
            );
            $calls = agentHookCalls($tree);
        } finally {
            agentHookRemoveDirectory($base);
        }

        expect($result)->toBe(['exitCode' => 0, 'stdout' => '', 'stderr' => ''])
            ->and(array_column($calls, 'tool'))->toBe(['php-cs-fixer', 'phpstan']);

        foreach ($calls as $call) {
            expect(array_slice($call['arguments'], -1))->toBe([$tree . '/src/Probe.php']);
        }
    })->with([
        'through `..` and back in' => ['{tree}/docs/../src/Probe.php', null],
        'through a link to the repository' => ['{base}/link/src/Probe.php', null],
        // Relative to the repository, not to the working directory.
        'a relative path, from another working directory' => ['src/Probe.php', '{base}'],
    ]);

    it('starts no tool for a path it does not check', function (string $create, string $path) use ($hook): void {
        $base = agentHookSandbox($hook);
        $tree = $base . '/repo';

        try {
            agentHookStandIn($tree, 'php-cs-fixer');
            agentHookStandIn($tree, 'phpstan', 1, "an error\n");

            if (!is_dir(dirname($base . '/' . $create))) {
                mkdir(dirname($base . '/' . $create), 0o777, true);
            }

            file_put_contents($base . '/' . $create, "<?php\n");
            file_put_contents($tree . '/src/Probe.php', "<?php\n");

            $result = runAgentHook($tree . '/' . AGENT_HOOK_SCRIPT, agentHookToolCall(str_replace('{tree}', $tree, $path)));
            $calls = agentHookCalls($tree);
        } finally {
            agentHookRemoveDirectory($base);
        }

        expect($result)->toBe(['exitCode' => 0, 'stdout' => '', 'stderr' => ''])
            ->and($calls)->toBe([]);
    })->with([
        'a directory named src that is not the one at the root' => ['repo/docs/src/Probe.php', '{tree}/docs/src/Probe.php'],
        'a directory beside the repository whose name starts the same' => ['repo-other/src/Probe.php', '{tree}-other/src/Probe.php'],
        'a file outside the repository, reached through src/' => ['repo-other/src/Probe.php', '{tree}/src/../../repo-other/src/Probe.php'],
        'an extension in upper case' => ['repo/src/Upper.PHP', '{tree}/src/Upper.PHP'],
        'a name that only contains .php' => ['repo/src/Probe.php.bak', '{tree}/src/Probe.php.bak'],
        'a directory whose name ends in .php' => ['repo/src/Directory.php/file.txt', '{tree}/src/Directory.php'],
        // The shell drops a NUL byte and a trailing newline from the path it
        // reads. Without a check, both of these become src/Probe.php, which
        // exists and is not the file the tool call names.
        'a path with a NUL byte' => ['repo/src/other.txt', "{tree}/src/Pro\0be.php"],
        'a path that ends in a newline' => ['repo/src/other.txt', "{tree}/src/Probe.php\n"],
    ]);

    it('starts neither tool unless both are installed', function (array $installed) use ($hook): void {
        // The control: with both stand-ins this tree reports an error (the
        // first test of this group), so here only the missing tool explains
        // the silence. A formatter that ran alone would rewrite a file that
        // nothing then analyses.
        $base = agentHookSandbox($hook);
        $tree = $base . '/repo';

        try {
            foreach ($installed as $tool) {
                agentHookStandIn($tree, $tool, 1, "an error\n");
            }

            file_put_contents($tree . '/tests/Probe.php', "<?php\n");

            $result = runAgentHook($tree . '/' . AGENT_HOOK_SCRIPT, agentHookToolCall($tree . '/tests/Probe.php'));
            $calls = agentHookCalls($tree);
        } finally {
            agentHookRemoveDirectory($base);
        }

        expect($result)->toBe(['exitCode' => 0, 'stdout' => '', 'stderr' => ''])
            ->and($calls)->toBe([]);
    })->with([
        'no vendor/ directory' => [[]],
        'PHP-CS-Fixer only' => [['php-cs-fixer']],
        'PHPStan only' => [['phpstan']],
    ]);

    it('reports only what PHPStan prints on stdout when it fails', function (array $fixer, array $phpstan, array $expected) use ($hook): void {
        $base = agentHookSandbox($hook);
        $tree = $base . '/repo';

        try {
            agentHookStandIn($tree, 'php-cs-fixer', ...$fixer);
            agentHookStandIn($tree, 'phpstan', ...$phpstan);
            file_put_contents($tree . '/src/Probe.php', "<?php\n");

            $result = runAgentHook($tree . '/' . AGENT_HOOK_SCRIPT, agentHookToolCall($tree . '/src/Probe.php'));
            $calls = agentHookCalls($tree);
        } finally {
            agentHookRemoveDirectory($base);
        }

        // PHPStan runs whatever PHP-CS-Fixer did.
        expect(array_column($calls, 'tool'))->toBe(['php-cs-fixer', 'phpstan'])
            ->and($result)->toBe($expected);
    })->with([
        'PHPStan fails with errors' => [[0], [1, "src/Probe.php:1:An error.\n"], ['exitCode' => 2, 'stdout' => '', 'stderr' => "src/Probe.php:1:An error.\n"]],
        'PHPStan fails with errors and its own messages' => [[0], [1, "src/Probe.php:1:An error.\n", "Note: Using configuration file.\n"], ['exitCode' => 2, 'stdout' => '', 'stderr' => "src/Probe.php:1:An error.\n"]],
        // A crash, or "No files found to analyse": not an error in the file.
        'PHPStan fails with nothing on stdout' => [[0], [255, '', "Fatal error: out of memory\n"], ['exitCode' => 0, 'stdout' => '', 'stderr' => '']],
        'PHPStan passes and still prints' => [[0], [0, "[OK] No errors\n", "Note\n"], ['exitCode' => 0, 'stdout' => '', 'stderr' => '']],
        'PHP-CS-Fixer fails and prints, PHPStan passes' => [[16, "fixer stdout\n", "fixer stderr\n"], [0], ['exitCode' => 0, 'stdout' => '', 'stderr' => '']],
        'PHP-CS-Fixer fails and prints, PHPStan fails' => [[16, "fixer stdout\n", "fixer stderr\n"], [1, "src/Probe.php:1:An error.\n"], ['exitCode' => 2, 'stdout' => '', 'stderr' => "src/Probe.php:1:An error.\n"]],
    ]);

    it('finds its own repository when it is started with a relative path and CDPATH is set', function () use ($hook): void {
        // `cd .claude/hooks/../..` looks in CDPATH first. The decoy has that
        // directory, so without `unset CDPATH` the hook takes the decoy for
        // the repository and checks nothing.
        $base = agentHookSandbox($hook);
        $tree = $base . '/repo';

        try {
            agentHookStandIn($tree, 'php-cs-fixer');
            agentHookStandIn($tree, 'phpstan', 1, "an error\n");
            file_put_contents($tree . '/src/Probe.php', "<?php\n");
            mkdir($base . '/decoy/.claude/hooks', 0o777, true);

            $result = runAgentHook(AGENT_HOOK_SCRIPT, agentHookToolCall($tree . '/src/Probe.php'), $tree, ['CDPATH' => $base . '/decoy']);
            $calls = agentHookCalls($tree);
        } finally {
            agentHookRemoveDirectory($base);
        }

        expect($result)->toBe(['exitCode' => 2, 'stdout' => '', 'stderr' => "an error\n"])
            ->and(array_column($calls, 'cwd'))->toBe([$tree, $tree]);
    });
});

describe('agent settings', function () use ($root): void {
    it('registers the hook for edits and writes with a 30 second timeout', function () use ($root): void {
        $settings = json_decode((string) file_get_contents($root . '/.claude/settings.json'), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($settings);

        expect($settings['hooks'] ?? null)->toBe([
            'PostToolUse' => [
                [
                    'matcher' => 'Edit|Write',
                    'hooks' => [
                        [
                            'type' => 'command',
                            'command' => 'bash "$CLAUDE_PROJECT_DIR/' . AGENT_HOOK_SCRIPT . '"',
                            'timeout' => 30,
                        ],
                    ],
                ],
            ],
        ]);

        expect(is_file($root . '/' . AGENT_HOOK_SCRIPT))->toBeTrue();
    });

    it('allows the named Composer scripts and vendor/bin commands, and nothing else', function () use ($root): void {
        $settings = json_decode((string) file_get_contents($root . '/.claude/settings.json'), true, flags: JSON_THROW_ON_ERROR);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($settings);
        Assert::assertIsArray($composer);

        expect(array_keys($settings['permissions'] ?? []))->toBe(['allow']);

        $allowed = $settings['permissions']['allow'];
        $scripts = [];

        foreach ($allowed as $rule) {
            if ($rule === 'Bash(vendor/bin/*:*)') {
                continue;
            }

            // Anything else must be one Composer script, named in full and
            // with no wildcard. `Bash(composer:*)` would also allow
            // `composer require` and `composer exec`, which install packages
            // and run arbitrary commands.
            if (preg_match('/^Bash\(composer ([a-z][a-z-]*)\)$/', $rule, $match) !== 1) {
                Assert::fail("The allow list holds `{$rule}`, which is neither one Composer script nor vendor/bin.");
            }

            Assert::assertArrayHasKey($match[1], $composer['scripts'], "The allow list names `composer {$match[1]}`, which composer.json does not define.");
            $scripts[] = $match[1];
        }

        // The gate and each of its checks, so that the agent is not asked for them.
        expect($allowed)->toContain('Bash(vendor/bin/*:*)')
            ->and($scripts)->toContain('ci', ...array_map(fn (string $script): string => ltrim($script, '@'), $composer['scripts']['ci']))
            // Regenerating the golden fixtures changes what the tests
            // compare against; the agent has to ask first.
            ->and($scripts)->not->toContain('golden-update');
    });

    it('ships the verify and release commands', function (string $command, string $mustMention) use ($root): void {
        $file = $root . '/.claude/commands/' . $command . '.md';

        expect(is_file($file))->toBeTrue()
            ->and((string) file_get_contents($file))->toContain($mustMention);
    })->with([
        'verify runs the gate' => ['verify', 'composer ci'],
        'release follows the checklist' => ['release', 'docs/RELEASING.md'],
    ]);

    it('lists the steps of the gate in the verify command in the order the gate runs them', function () use ($root): void {
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $command = (string) preg_replace('/\s+/', ' ', (string) file_get_contents($root . '/.claude/commands/verify.md'));
        Assert::assertIsArray($composer);

        // What the command calls each script of the gate.
        $names = [
            '@norm' => '`composer normalize --dry-run`',
            '@phpstan' => 'PHPStan',
            '@rector' => 'Rector (dry run)',
            '@cs-fixer' => 'PHP-CS-Fixer (dry run)',
            '@test' => 'the Pest suite',
            '@examples' => 'the example smoke-run',
        ];

        $positions = [];

        foreach ($composer['scripts']['ci'] as $script) {
            Assert::assertArrayHasKey($script, $names, "The gate runs `{$script}`, which the verify command does not list.");
            $position = strpos($command, $names[$script]);
            Assert::assertNotFalse($position, "The verify command does not mention {$names[$script]}.");
            $positions[] = $position;
        }

        $sorted = $positions;
        sort($sorted);

        expect($positions)->toBe($sorted)
            ->and(count($positions))->toBe(count($names));
    });

    it('makes the release command wait for confirmation before the tag, the push and the release', function () use ($root): void {
        $command = (string) preg_replace('/\s+/', ' ', (string) file_get_contents($root . '/.claude/commands/release.md'));

        expect($command)->toContain('Do not run `git tag`, do not push a tag, and do not create a GitHub release until the maintainer has confirmed')
            ->and($command)->toContain('Ask again before the push, and again before creating the release');
    });

    it('names no version in the release command', function () use ($root): void {
        // The version comes from the maintainer. An example number in the
        // command would be out of date after the next release.
        expect((string) file_get_contents($root . '/.claude/commands/release.md'))->not->toMatch('/\d+\.\d+/');
    });
});
