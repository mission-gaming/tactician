<?php

declare(strict_types=1);

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
    $process = @proc_open(
        ['git', '-C', $directory, ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if (!is_resource($process)) {
        return null;
    }

    // Read stdout to the end before stderr: git writes its diagnostics, a few
    // lines at most, to stderr, so that pipe cannot fill and block the read.
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exitCode' => proc_close($process), 'stdout' => $stdout, 'stderr' => trim($stderr)];
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
 * The distinct top-level names in a tar stream, sorted.
 *
 * A tar stream is a sequence of 512-byte blocks: a header, then the entry's
 * content padded to a whole block. Only the name, size and type are read.
 *
 * @return list<string>
 */
function distArchiveTopLevelEntries(string $tar): array
{
    $entries = [];
    $length = strlen($tar);

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

        // 'g' and 'x' are pax headers (git records the commit id in one);
        // they describe entries and are not entries themselves.
        if ($type !== 'g' && $type !== 'x') {
            $path = $prefix === '' ? $name : $prefix . '/' . $name;
            $entries[explode('/', $path)[0]] = true;
        }

        $offset += 512 + (int) (ceil($size / 512) * 512);
    }

    $names = array_map(strval(...), array_keys($entries));
    sort($names);

    return $names;
}

$root = dirname(__DIR__, 2);

it('ships only library files in the dist archive', function () use ($root): void {
    $reason = distArchiveUnavailableReason($root);

    if ($reason !== null) {
        $message = "Cannot build the dist archive: {$reason}.";

        if (in_array(getenv('CI'), [false, '', '0', 'false'], true)) {
            $this->markTestSkipped($message);
        }

        Assert::fail($message . ' On CI this check must run; a skip would hide it.');
    }

    $archive = distArchiveGit($root, ['archive', '--format=tar', 'HEAD']);

    Assert::assertNotNull($archive, 'git could not be started to build the archive');
    Assert::assertSame(0, $archive['exitCode'], 'git archive failed: ' . $archive['stderr']);

    $entries = distArchiveTopLevelEntries($archive['stdout']);

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

it('reads the top-level entries of a tar stream', function (): void {
    $block = function (string $name, string $type, string $content = '', string $prefix = ''): string {
        $header = str_pad($name, 100, "\0")
            . str_repeat("\0", 24)
            . sprintf('%011o', strlen($content)) . "\0"
            . str_repeat("\0", 20)
            . $type
            . str_repeat("\0", 188)
            . str_pad($prefix, 155, "\0");

        return str_pad($header, 512, "\0") . str_pad($content, (int) (ceil(strlen($content) / 512) * 512), "\0");
    };

    $tar = $block('pax_global_header', 'g', 'comment=abc')
        . $block('src/', '5')
        . $block('src/A.php', '0', str_repeat('a', 700))
        . $block('composer.json', '0', '{}')
        . $block('Deep.php', '0', 'x', 'docs/a/long/path')
        . str_repeat("\0", 1024)
        . $block('after-the-end', '0');

    expect(distArchiveTopLevelEntries($tar))->toBe(['composer.json', 'docs', 'src']);
});
