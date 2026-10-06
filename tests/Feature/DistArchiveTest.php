<?php

declare(strict_types=1);

use MissionGaming\Tactician\Tests\Support\CiEnvironment;
use PHPUnit\Framework\Assert;

// The dist archive is what a consumer gets in vendor/. It is built by
// `git archive`, which leaves out every path marked `export-ignore` in
// .gitattributes, so a new root file or directory ships to every consumer
// unless it is added there. This test builds the archive of HEAD and fails
// when anything outside the allowed list is in it.
//
// It reads the committed tree only: `git archive HEAD` takes both the files
// and the attributes from the commit, exactly as a release archive does, so
// an uncommitted edit to .gitattributes changes nothing here until it is
// committed. That is deliberate - the result must not depend on the state of
// the working tree.
//
// Gap left knowingly - the test needs `git` and the repository metadata. It
// skips, with the reason, where they are missing (a source tree unpacked from
// an archive, for example). On CI a skip would hide the guard, so there the
// same condition fails instead.
//
// One check is different: "no tracked file is ignored" compares the index
// with the .gitignore files in the working tree, because that is the state an
// ignore rule acts on.

const DIST_ALLOWED_ENTRIES = ['CHANGELOG.md', 'LICENSE', 'README.md', 'composer.json', 'src'];

// CHANGELOG.md is allowed but not required: the archive is valid without it.
const DIST_REQUIRED_ENTRIES = ['LICENSE', 'README.md', 'composer.json', 'src'];

/**
 * Run git in the given directory without a shell.
 *
 * @param list<string> $arguments
 *
 * @return array{exitCode: int, stdout: string, stderr: string}|null Null when git cannot be started.
 */
function distArchiveGit(string $directory, array $arguments): ?array
{
    // stderr goes to a temporary file, not a pipe: only stdout is read while
    // git runs, and a pipe nobody reads blocks its writer once it is full
    // (tracing switched on in the environment is enough to fill one).
    $errors = tmpfile();

    if ($errors === false) {
        return null;
    }

    $process = @proc_open(
        ['git', '-C', $directory, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $errors],
        $pipes
    );

    if (!is_resource($process)) {
        fclose($errors);

        return null;
    }

    // git gets an empty stdin of its own, so it can never wait on the
    // terminal or on whatever the test runner was given as input.
    fclose($pipes[0]);

    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exitCode = proc_close($process);

    rewind($errors);
    $stderr = (string) stream_get_contents($errors);
    fclose($errors);

    return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => trim($stderr)];
}

/**
 * Why the archive of HEAD cannot be built here, or null when it can.
 */
function distArchiveUnavailableReason(string $root): ?string
{
    if (!function_exists('proc_open')) {
        return 'proc_open is disabled, so git cannot be run';
    }

    $topLevel = distArchiveGit($root, ['rev-parse', '--show-toplevel']);

    if ($topLevel === null || $topLevel['exitCode'] === 127) {
        return 'git is not installed';
    }

    if ($topLevel['exitCode'] !== 0) {
        return 'the source tree is not a git repository: ' . $topLevel['stderr'];
    }

    // Installed as a dependency, the nearest repository is the consuming
    // project's; its archive says nothing about this package.
    if (realpath(trim($topLevel['stdout'])) !== realpath($root)) {
        return 'the source tree is inside another git repository, not its own';
    }

    $head = distArchiveGit($root, ['rev-parse', '--verify', '--quiet', 'HEAD^{commit}']);

    if ($head === null || $head['exitCode'] !== 0) {
        return 'the repository has no commit to archive';
    }

    return null;
}

/**
 * The reason to skip the test, or null when git and the repository are usable.
 *
 * @throws PHPUnit\Framework\AssertionFailedError On CI, where the check must run
 */
function distArchiveSkipReason(string $root): ?string
{
    $reason = distArchiveUnavailableReason($root);

    if ($reason === null) {
        return null;
    }

    $message = "Cannot build the dist archive: {$reason}.";

    if (CiEnvironment::isCi(getenv('CI'))) {
        Assert::fail($message . ' On CI this check must run; a skip would hide it.');
    }

    return $message;
}

/**
 * Run git and return its stdout, failing the test when git fails.
 *
 * @param list<string> $arguments
 *
 * @throws PHPUnit\Framework\AssertionFailedError When git cannot be started or exits non-zero
 */
function distArchiveGitOutput(string $root, array $arguments): string
{
    $result = distArchiveGit($root, $arguments);
    $command = 'git ' . implode(' ', $arguments);

    if ($result === null) {
        Assert::fail("git could not be started to run `{$command}`");
    }

    Assert::assertSame(0, $result['exitCode'], "`{$command}` failed: " . $result['stderr']);

    return $result['stdout'];
}

/**
 * The names in NUL-separated git output (`-z`), which are never quoted.
 *
 * @return list<string>
 */
function distArchiveNames(string $output): array
{
    return array_values(array_filter(explode("\0", $output), static fn (string $name): bool => $name !== ''));
}

/**
 * The `path` record of a pax extended header, or null when it has none.
 *
 * Each record is "<length> <key>=<value>\n", where the length counts the
 * whole record, its own digits included.
 */
function distArchivePaxPath(string $records): ?string
{
    $path = null;
    $total = strlen($records);

    for ($offset = 0; $offset < $total;) {
        $space = strpos($records, ' ', $offset);

        if ($space === false) {
            break;
        }

        $length = (int) substr($records, $offset, $space - $offset);

        if ($length <= $space - $offset + 1) {
            break;
        }

        $record = substr($records, $space + 1, $length - ($space - $offset) - 2);

        if (str_starts_with($record, 'path=')) {
            $path = substr($record, 5);
        }

        $offset += $length;
    }

    return $path;
}

/**
 * Every entry in a tar stream: its path mapped to its type flag.
 *
 * A tar stream is a sequence of 512-byte blocks: a header, then the entry's
 * content padded to a whole block. Only the name, size and type are read. A
 * directory has type '5' and a path that ends with a slash.
 *
 * @return array<string, string>
 */
function distArchiveEntries(string $tar): array
{
    $entries = [];
    $length = strlen($tar);
    $paxPath = null;

    for ($offset = 0; $offset + 512 <= $length;) {
        $header = substr($tar, $offset, 512);

        // The archive ends with blocks of zero bytes.
        if (trim($header, "\0") === '') {
            break;
        }

        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = rtrim(substr($header, 345, 155), "\0");
        $size = (int) octdec(trim(substr($header, 124, 12), " \0"));
        $type = $header[156];

        if ($type === 'x') {
            // A pax header describes the entry after it. git writes one for
            // a path too long for the name and prefix fields, and gives that
            // entry a placeholder name ("<object id>.data") in its header.
            $paxPath = distArchivePaxPath(substr($tar, $offset + 512, $size));
        } elseif ($type !== 'g') {
            // 'g' is the global pax header (git records the commit id in
            // it); it is not an entry.
            $entries[$paxPath ?? ($prefix === '' ? $name : $prefix . '/' . $name)] = $type;
            $paxPath = null;
        }

        $offset += 512 + (int) (ceil($size / 512) * 512);
    }

    return $entries;
}

/**
 * The distinct top-level names in a tar stream, sorted.
 *
 * @return list<string>
 */
function distArchiveTopLevelEntries(string $tar): array
{
    $names = [];

    foreach (array_keys(distArchiveEntries($tar)) as $path) {
        $names[explode('/', (string) $path)[0]] = true;
    }

    $names = array_map(strval(...), array_keys($names));
    sort($names);

    return $names;
}

/**
 * The paths of everything in a tar stream that is not a directory, sorted.
 *
 * @return list<string>
 */
function distArchiveFiles(string $tar): array
{
    $files = [];

    foreach (distArchiveEntries($tar) as $path => $type) {
        if ($type !== '5') {
            $files[] = (string) $path;
        }
    }

    sort($files);

    return $files;
}

/**
 * Build one tar entry (header and padded content) for the parser tests.
 */
function distArchiveTarBlock(string $name, string $type, string $content = '', string $prefix = ''): string
{
    $header = str_pad($name, 100, "\0")
        . str_repeat("\0", 24)
        . sprintf('%011o', strlen($content)) . "\0"
        . str_repeat("\0", 20)
        . $type
        . str_repeat("\0", 188)
        . str_pad($prefix, 155, "\0");

    return str_pad($header, 512, "\0") . str_pad($content, (int) (ceil(strlen($content) / 512) * 512), "\0");
}

/**
 * One pax record, with the length prefix the format requires.
 */
function distArchivePaxRecord(string $key, string $value): string
{
    $body = " {$key}={$value}\n";
    $length = strlen($body) + 1;

    // The length counts its own digits, so adding them can add a digit.
    while (strlen((string) $length) + strlen($body) !== $length) {
        $length = strlen((string) $length) + strlen($body);
    }

    return $length . $body;
}

$root = dirname(__DIR__, 2);

it('ships only library files in the dist archive', function () use ($root): void {
    $skip = distArchiveSkipReason($root);

    if ($skip !== null) {
        $this->markTestSkipped($skip);
    }

    $entries = distArchiveTopLevelEntries(distArchiveGitOutput($root, ['archive', '--format=tar', 'HEAD']));

    Assert::assertSame(
        [],
        array_values(array_diff($entries, DIST_ALLOWED_ENTRIES)),
        'The dist archive carries entries a consumer does not need. '
        . 'Mark each one `export-ignore` in .gitattributes and commit it.'
    );
    Assert::assertSame(
        [],
        array_values(array_diff(DIST_REQUIRED_ENTRIES, $entries)),
        'The dist archive is missing entries the library needs. '
        . 'Check .gitattributes for an `export-ignore` rule that is too broad.'
    );
});

it('keeps every committed library file in the dist archive', function () use ($root): void {
    $skip = distArchiveSkipReason($root);

    if ($skip !== null) {
        $this->markTestSkipped($skip);
    }

    // An `export-ignore` pattern with no leading slash matches at every
    // depth, so a rule meant for a root entry can also remove files from
    // src/. The top-level check cannot see that: src/ is still there.
    $committed = array_values(array_filter(
        distArchiveNames(distArchiveGitOutput($root, ['ls-tree', '-r', '-z', '--name-only', 'HEAD'])),
        static fn (string $path): bool => in_array(explode('/', $path)[0], DIST_ALLOWED_ENTRIES, true)
    ));
    sort($committed);

    Assert::assertSame(
        $committed,
        distArchiveFiles(distArchiveGitOutput($root, ['archive', '--format=tar', 'HEAD'])),
        'The dist archive does not carry exactly the committed library files. '
        . 'Check .gitattributes for an `export-ignore` pattern that also matches below src/.'
    );
});

it('keeps every path composer.json loads at runtime in the dist archive', function () use ($root): void {
    $skip = distArchiveSkipReason($root);

    if ($skip !== null) {
        $this->markTestSkipped($skip);
    }

    $composer = json_decode(distArchiveGitOutput($root, ['show', 'HEAD:composer.json']), true);
    Assert::assertIsArray($composer);

    // `autoload-dev` is left out on purpose: Composer reads it for the root
    // package only, never for an installed dependency.
    $autoload = is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];
    $paths = [];
    array_walk_recursive($autoload, static function (mixed $path) use (&$paths): void {
        $paths[] = $path;
    });

    foreach ((array) ($composer['bin'] ?? []) as $path) {
        $paths[] = $path;
    }

    Assert::assertNotSame([], $paths, 'composer.json declares no autoload path');

    $archived = array_keys(distArchiveEntries(distArchiveGitOutput($root, ['archive', '--format=tar', 'HEAD'])));
    $missing = [];

    foreach ($paths as $path) {
        Assert::assertIsString($path);
        $wanted = rtrim($path, '/');

        // A directory is in the archive with a trailing slash, a file without.
        if (!in_array($wanted, $archived, true) && !in_array($wanted . '/', $archived, true)) {
            $missing[] = $path;
        }
    }

    Assert::assertSame(
        [],
        $missing,
        'composer.json loads paths that the dist archive does not carry, so the installed package would be broken.'
    );
});

it('ignores no tracked file', function () use ($root): void {
    $skip = distArchiveSkipReason($root);

    if ($skip !== null) {
        $this->markTestSkipped($skip);
    }

    // Only the repository's own .gitignore files are read, not the ignore
    // rules of the machine (core.excludesFile, .git/info/exclude).
    $ignored = distArchiveNames(distArchiveGitOutput(
        $root,
        ['ls-files', '-z', '--cached', '--ignored', '--exclude-per-directory=.gitignore']
    ));

    Assert::assertSame(
        [],
        $ignored,
        'A .gitignore pattern matches tracked files. Anchor it to the root with a leading slash.'
    );
});

it('reads the top-level entries of a tar stream', function (): void {
    $tar = distArchiveTarBlock('pax_global_header', 'g', 'comment=abc')
        . distArchiveTarBlock('src/', '5')
        . distArchiveTarBlock('src/A.php', '0', str_repeat('a', 700))
        . distArchiveTarBlock('composer.json', '0', '{}')
        . distArchiveTarBlock('Deep.php', '0', 'x', 'docs/a/long/path')
        . str_repeat("\0", 1024)
        . distArchiveTarBlock('after-the-end', '0');

    expect(distArchiveTopLevelEntries($tar))->toBe(['composer.json', 'docs', 'src']);
});

it('lists the files of a tar stream without its directories', function (): void {
    $tar = distArchiveTarBlock('src/', '5')
        . distArchiveTarBlock('src/B.php', '0', 'b')
        . distArchiveTarBlock('src/A.php', '0', str_repeat('a', 512))
        . distArchiveTarBlock('link', '2')
        . distArchiveTarBlock('Deep.php', '0', '', 'src/a/long/path');

    expect(distArchiveFiles($tar))->toBe(['link', 'src/A.php', 'src/B.php', 'src/a/long/path/Deep.php']);
});

it('reads an empty tar stream as no entries', function (string $tar): void {
    expect(distArchiveEntries($tar))->toBe([])
        ->and(distArchiveTopLevelEntries($tar))->toBe([])
        ->and(distArchiveFiles($tar))->toBe([]);
})->with([
    'no bytes' => [''],
    'only the end blocks' => [str_repeat("\0", 1024)],
    'less than one block' => [str_repeat('a', 511)],
]);

it('takes the path of a long entry from its pax header', function (): void {
    // What git writes for a path that fits neither the name field nor the
    // name and prefix fields together: the real path in a pax header, and a
    // placeholder name on the entry itself.
    $long = 'src/' . str_repeat('d', 120) . '/' . str_repeat('f', 120) . '.php';
    $records = distArchivePaxRecord('mtime', '1') . distArchivePaxRecord('path', $long);

    $tar = distArchiveTarBlock('0123abcd.paxheader', 'x', $records)
        . distArchiveTarBlock('0123abcd.data', '0', 'content')
        . distArchiveTarBlock('src/Short.php', '0');

    expect(distArchiveFiles($tar))->toBe(['src/Short.php', $long])
        ->and(distArchiveTopLevelEntries($tar))->toBe(['src']);
});

it('reads the path record of a pax header', function (string $records, ?string $expected): void {
    expect(distArchivePaxPath($records))->toBe($expected);
})->with([
    'a path alone' => ["18 path=src/A.php\n", 'src/A.php'],
    'a path after another record' => ["11 mtime=1\n18 path=src/A.php\n", 'src/A.php'],
    'a path that holds a newline' => ["12 path=a\nb\n", "a\nb"],
    'no path record' => ["11 mtime=1\n", null],
    'no records' => ['', null],
    'a record with no length' => ["path=src/A.php\n", null],
    'a length of zero' => ["0 path=src/A.php\n", null],
]);

it('writes pax records the parser reads back', function (string $value): void {
    $record = distArchivePaxRecord('path', $value);

    expect((int) $record)->toBe(strlen($record))
        ->and(distArchivePaxPath($record))->toBe($value);
})->with([
    // 9 and 10 bytes sit either side of the point where the length gains a digit.
    'short' => ['a'],
    'length just under ten' => ['ab'],
    'length just over ten' => ['abc'],
    'length just under a hundred' => [str_repeat('p', 90)],
    'length just over a hundred' => [str_repeat('p', 93)],
    'long' => [str_repeat('p', 400)],
]);

it('tells a CI run from a local one', function (string|false $ci, bool $expected): void {
    expect(CiEnvironment::isCi($ci))->toBe($expected);
})->with([
    'unset' => [false, false],
    'empty' => ['', false],
    'zero' => ['0', false],
    'false' => ['false', false],
    'FALSE' => ['FALSE', false],
    'true' => ['true', true],
    'one' => ['1', true],
]);

it('reports why git cannot be used outside a repository', function (): void {
    if (!function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is disabled, so git cannot be run.');
    }

    $directory = sys_get_temp_dir() . '/dist-archive-test-' . bin2hex(random_bytes(6));
    mkdir($directory);

    try {
        $reason = distArchiveUnavailableReason($directory);
    } finally {
        rmdir($directory);
    }

    // The temporary directory is outside any repository unless the machine
    // keeps one above it; either way the archive of this package is not
    // available there, and the reason says so.
    expect($reason)->not->toBeNull();
});

it('reports a directory below the repository root as not its own repository', function () use ($root): void {
    if (!function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is disabled, so git cannot be run.');
    }

    // This is the shape of a copy vendored inside a consuming project: the
    // nearest repository belongs to something else. In a checkout the reason
    // is that one; without git or a repository it is one of the others. In
    // every case the archive must not be built from there.
    //
    // Gap left knowingly - the "no commit to archive" reason needs a freshly
    // initialised repository, which a test would have to create and remove
    // on the machine; it is not covered.
    $reason = distArchiveUnavailableReason($root . '/tests');

    expect($reason)->not->toBeNull();

    if (distArchiveUnavailableReason($root) === null) {
        expect($reason)->toBe('the source tree is inside another git repository, not its own');
    }
});
