<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Tests\Support\GoldenCases;
use PHPUnit\Framework\Assert;

/*
 * Golden-output tests: the generated output of every algorithm, pinned as
 * readable text under tests/Fixtures/golden/. The baseline was captured
 * from the v0.2.0 tag, so a failure here means generated output changed.
 * That is allowed, but never silently: explain the change in the
 * changelog and regenerate the fixtures with `composer golden-update`.
 *
 * The cases live in MissionGaming\Tactician\Tests\Support\GoldenCases;
 * this file only compares them with (or, when UPDATE_GOLDEN=1, writes
 * them to) the stored files.
 */

const GOLDEN_DIRECTORY = __DIR__ . '/../Fixtures/golden';

function goldenUpdateRequested(): bool
{
    return getenv('UPDATE_GOLDEN') === '1';
}

/**
 * The failure message for a stored fixture that no longer matches: names
 * the file and the first differing line, and says what a difference means.
 */
function goldenMismatchMessage(string $file, string $expected, string $actual): string
{
    $expectedLines = explode("\n", $expected);
    $actualLines = explode("\n", $actual);
    $line = 0;
    while (
        isset($expectedLines[$line], $actualLines[$line])
        && $expectedLines[$line] === $actualLines[$line]
    ) {
        ++$line;
    }

    return sprintf(
        "Generated output differs from tests/Fixtures/golden/%s at line %d.\n"
        . "  stored:    %s\n"
        . "  generated: %s\n"
        . 'A difference from a golden file is a change to generated output. If it is intended, '
        . 'explain it in the changelog and regenerate the fixtures with `composer golden-update`; '
        . 'if it is not, the change under test altered output it should not have.',
        $file,
        $line + 1,
        $expectedLines[$line] ?? '(end of file)',
        $actualLines[$line] ?? '(end of file)'
    );
}

/**
 * The stored wire payload: the file is the exact payload followed by one
 * newline.
 *
 * @throws PHPUnit\Framework\AssertionFailedError When the golden file is missing
 */
function goldenWirePayload(string $file): string
{
    $path = GOLDEN_DIRECTORY . '/' . $file;
    if (!is_file($path)) {
        Assert::fail("Golden file tests/Fixtures/golden/{$file} is missing. Generate it with `composer golden-update`.");
    }

    return substr((string) file_get_contents($path), 0, -1);
}

describe('Golden output', function (): void {
    it('matches the stored golden file', function (string $file): void {
        $actual = GoldenCases::all()[$file]();
        $path = GOLDEN_DIRECTORY . '/' . $file;

        if (goldenUpdateRequested()) {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, $actual);
        }

        if (!is_file($path)) {
            Assert::fail(
                "Golden file tests/Fixtures/golden/{$file} is missing. Every golden case needs a stored "
                . 'baseline: generate it with `composer golden-update` and review it before committing.'
            );
        }

        $expected = (string) file_get_contents($path);
        if ($actual !== $expected) {
            Assert::fail(goldenMismatchMessage($file, $expected, $actual));
        }

        expect($actual)->toBe($expected);
    })->with(array_keys(GoldenCases::all()));

    // A fixture nothing generates is a baseline nothing checks
    it('stores no golden file without a case', function (): void {
        $stored = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(GOLDEN_DIRECTORY, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);
            $stored[] = str_replace('\\', '/', substr($file->getPathname(), strlen(GOLDEN_DIRECTORY) + 1));
        }
        sort($stored);

        $cases = array_keys(GoldenCases::all());
        sort($cases);

        expect($stored)->toBe($cases);
    });

    it('round-trips the stored Schedule payload through fromJson', function (): void {
        $payload = goldenWirePayload(GoldenCases::WIRE_SCHEDULE);

        expect(Schedule::fromJson($payload)->toJson())->toBe($payload);
    });

    it('round-trips the stored StageState payload through fromJson', function (): void {
        $payload = goldenWirePayload(GoldenCases::WIRE_STAGE_STATE);

        expect(StageState::fromJson($payload)->toJson())->toBe($payload);
    });
});
