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
// can leave tests/agent-hook-probe-* behind, which is safe to delete.
//
// Gap left knowingly - nothing here proves that an agent reads
// .claude/settings.json the way these tests do, or that it calls the hook
// with this JSON shape. Only a session with the agent shows that.

const AGENT_HOOK_SCRIPT = '.claude/hooks/format-and-analyse.sh';

/**
 * Run a copy of the hook, or the hook itself, with the given stdin.
 *
 * @throws RuntimeException When the process cannot be started
 *
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function runAgentHook(string $script, string $stdin): array
{
    // The output goes to temporary files, not pipes: nothing reads a pipe
    // while stdin is being written, and a full pipe blocks its writer.
    $stdout = tmpfile();
    $stderr = tmpfile();

    if ($stdout === false || $stderr === false) {
        throw new RuntimeException('Could not create temporary files for the hook output.');
    }

    $process = proc_open(['bash', $script], [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr], $pipes);

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

    it('does nothing where the tools are not installed', function () use ($hook): void {
        // A copy of the hook in a tree that has no vendor/ directory. The
        // file has the same type error as above, so only the missing tools
        // can explain the silence.
        $tree = sys_get_temp_dir() . '/tactician-agent-hook-' . bin2hex(random_bytes(6));
        mkdir($tree . '/.claude/hooks', 0o777, true);
        mkdir($tree . '/tests');

        try {
            copy($hook, $tree . '/' . AGENT_HOOK_SCRIPT);
            file_put_contents($tree . '/tests/Probe.php', agentHookProbeSource('agentHookProbe', '"not an integer"'));

            $result = runAgentHook($tree . '/' . AGENT_HOOK_SCRIPT, agentHookToolCall($tree . '/tests/Probe.php'));
        } finally {
            agentHookRemoveDirectory($tree);
        }

        expect($result)->toBe(['exitCode' => 0, 'stdout' => '', 'stderr' => '']);
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

    it('allows Composer and vendor/bin commands and nothing else', function () use ($root): void {
        $settings = json_decode((string) file_get_contents($root . '/.claude/settings.json'), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($settings);

        expect($settings['permissions'] ?? null)->toBe([
            'allow' => ['Bash(composer:*)', 'Bash(vendor/bin/*:*)'],
        ]);
    });

    it('ships the verify and release commands', function (string $command, string $mustMention) use ($root): void {
        $file = $root . '/.claude/commands/' . $command . '.md';

        expect(is_file($file))->toBeTrue()
            ->and((string) file_get_contents($file))->toContain($mustMention);
    })->with([
        'verify runs the gate' => ['verify', 'composer ci'],
        'release follows the checklist' => ['release', 'docs/RELEASING.md'],
    ]);
});
