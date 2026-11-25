<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

// A warning or deprecation that PHP raises while it compiles a file is not
// seen by the run's own handling of warnings: a test file is compiled when
// Pest loads it, before PHPUnit's error handler is installed, so the run
// prints the warning and still passes. That is how a test file without a
// namespace came to import `Override`, which PHP warns has no effect (#221;
// 8.4 and 8.5 both do). These tests compile every PHP file of src/, tests/ and examples/
// with the PHP that runs the suite (`php -l`, which compiles a file without
// running it) and fail on anything it says besides the line that a file has
// no syntax errors. CI runs the suite on every supported PHP version, so a
// warning that only a later version raises is caught on that version.

/**
 * Compiles files with `php -l` under the PHP that runs this process.
 *
 * The process starts without a php.ini (`-n`), so that what it prints is
 * PHP's own and not an extension's (Xdebug, for one, prints a line when it
 * cannot reach a debugger), and reports every error, warning and
 * deprecation on standard output.
 *
 * @param list<string> $files Paths relative to the repository root
 *
 * @return array{int, list<string>} Exit status, and the lines the process printed that are not empty
 *
 * @throws PHPUnit\Framework\Exception
 */
function compileWithRunningPhp(array $files): array
{
    $process = proc_open(
        [PHP_BINARY, '-n', '-d', 'error_reporting=-1', '-d', 'display_errors=1', '-d', 'display_startup_errors=1', '-d', 'log_errors=0', '-l', ...$files],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 2)
    );
    Assert::assertIsResource($process);

    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    return [$status, array_values(array_filter(
        array_map(trim(...), explode("\n", $output)),
        static fn(string $line): bool => $line !== ''
    ))];
}

/**
 * Every PHP file under src/, tests/ and examples/, relative to the repository root, in a fixed order.
 *
 * @return list<string>
 *
 * @throws PHPUnit\Framework\Exception
 * @throws UnexpectedValueException
 */
function phpFilesOfTheRepository(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];
    foreach (['src', 'tests', 'examples'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            Assert::assertInstanceOf(SplFileInfo::class, $file);
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }
    sort($files);

    return $files;
}

/**
 * Writes a PHP file to a directory of its own under the system's temporary directory and returns its path.
 *
 * @throws PHPUnit\Framework\ExpectationFailedException
 * @throws Random\RandomException
 */
function temporaryPhpFile(string $source): string
{
    $directory = sys_get_temp_dir() . '/tactician-compile-' . bin2hex(random_bytes(6));
    Assert::assertTrue(mkdir($directory));
    $path = "{$directory}/Fixture.php";
    Assert::assertNotFalse(file_put_contents($path, $source));

    return $path;
}

function removeTemporaryPhpFile(string $path): void
{
    unlink($path);
    rmdir(dirname($path));
}

describe('compiling the PHP files of the repository', function (): void {
    it('finds the files of all three directories', function (): void {
        $files = phpFilesOfTheRepository();

        expect($files)->toContain('src/DTO/Event.php')
            ->toContain('tests/Feature/CompileTimeWarningsTest.php')
            ->toContain('examples/support/Example.php');
    });

    it('raises no warning, notice or deprecation in any of them under the running PHP', function (): void {
        $files = phpFilesOfTheRepository();
        $reported = [];
        $compiled = [];
        // A few hundred paths at a time keep the command line short
        foreach (array_chunk($files, 100) as $chunk) {
            [$status, $lines] = compileWithRunningPhp($chunk);
            expect($status)->toBe(0);
            foreach ($lines as $line) {
                if (preg_match('/^No syntax errors detected in (.+)$/', $line, $match) === 1) {
                    $compiled[] = $match[1];
                } else {
                    $reported[] = $line;
                }
            }
        }

        // Every file was compiled, not only the first of each command
        expect($reported)->toBe([])
            ->and($compiled)->toBe($files);
    });
});

describe('the compile check', function (): void {
    // Files that compile with a warning, with a deprecation, and that do
    // not parse. Every supported PHP version says each of them, so that
    // the check is shown to work on each version.
    it('reports what PHP says while it compiles a file', function (string $source, string $expected): void {
        $path = temporaryPhpFile($source);
        try {
            [, $lines] = compileWithRunningPhp([$path]);
        } finally {
            removeTemporaryPhpFile($path);
        }

        $reported = array_values(array_filter(
            $lines,
            static fn(string $line): bool => !str_starts_with($line, 'No syntax errors detected in ')
        ));
        expect($reported)->not->toBe([])
            ->and($reported[0])->toContain($expected);
    })->with([
        'the import of #221' => ["<?php\n\ndeclare(strict_types=1);\n\nuse Override;\n", "Warning: The use statement with non-compound name 'Override' has no effect"],
        'a warning' => ["<?php\n\ndeclare(strict_types=1);\ndeclare(unknown_directive=1);\n", "Warning: Unsupported declare 'unknown_directive'"],
        'a deprecation' => ["<?php\n\ndeclare(strict_types=1);\n\nfunction optionalFirst(int \$a = 1, int \$b): void {}\n", 'Deprecated: optionalFirst(): Optional parameter $a declared before required parameter $b'],
        'a parse error' => ["<?php\n\ndeclare(strict_types=1);\n\nfunction (\n", 'Parse error'],
    ]);

    it('passes a file that compiles cleanly', function (): void {
        $path = temporaryPhpFile("<?php\n\ndeclare(strict_types=1);\n\nnamespace Example;\n\nuse Override;\n");
        try {
            [$status, $lines] = compileWithRunningPhp([$path]);
        } finally {
            removeTemporaryPhpFile($path);
        }

        expect($status)->toBe(0)
            ->and($lines)->toBe(["No syntax errors detected in {$path}"]);
    });
});
