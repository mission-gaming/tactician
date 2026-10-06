<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedWithMessageException;

/**
 * Runs a piece of a test under a numeric locale that writes a decimal comma.
 *
 * Text the library builds must not follow the locale. A test proves that by
 * building the text while such a locale is in force, and which locales are
 * installed differs by machine. Where none is, the test is skipped on a
 * developer's machine and fails on CI: the workflows generate `de_DE.UTF-8`
 * before the suite runs (pinned by tests/Feature/CiConfigurationTest.php), so
 * a skip there would let the check pass without having run.
 */
final class DecimalCommaLocale
{
    private const array CANDIDATES = ['de_DE.UTF-8', 'de_DE.utf8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR.utf8', 'fr_FR'];

    /**
     * Call $work with the locale in force and return what it returns. The
     * previous locale is restored afterwards, also when $work throws.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws AssertionFailedError When no such locale is installed on CI, or the locale set does not write a comma
     * @throws SkippedWithMessageException When no such locale is installed on a developer's machine
     */
    public static function during(callable $work): mixed
    {
        $previous = setlocale(LC_NUMERIC, '0');

        if ($previous === false) {
            self::unavailable('The current locale cannot be read, so it could not be restored.');
        }

        if (setlocale(LC_NUMERIC, self::CANDIDATES) === false) {
            self::unavailable('No locale with a decimal comma is installed.');
        }

        try {
            // Proof that the locale is in force and changes how a number is
            // written: without it the caller's assertions would prove nothing.
            Assert::assertSame(',', localeconv()['decimal_point']);
            Assert::assertSame('12,5', sprintf('%.1f', 12.5));

            return $work();
        } finally {
            setlocale(LC_NUMERIC, $previous);
        }
    }

    /**
     * @throws AssertionFailedError On CI
     * @throws SkippedWithMessageException Everywhere else
     */
    private static function unavailable(string $reason): never
    {
        if (CiEnvironment::isCi(getenv('CI'))) {
            Assert::fail($reason . ' On CI this check must run; a skip would hide it.');
        }

        Assert::markTestSkipped($reason);
    }
}
