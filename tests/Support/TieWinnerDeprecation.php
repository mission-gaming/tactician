<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use Closure;
use MissionGaming\Tactician\Stage\TieResolution;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Runs code that settles a level elimination tie through the deprecated
 * `tie_winner` metadata of a result, on purpose, and checks what the
 * library said about it.
 *
 * The key is read until 1.0.0, so its tests still have to record it. A
 * call of the library that takes an advancer from the key raises one
 * `E_USER_DEPRECATED` notice, on every PHP version, and the suite fails on
 * any deprecation (`failOnDeprecation` in `phpunit.xml`). The methods here
 * take the notices of the code they run, and no others: a deprecation that
 * says anything else, a warning and a notice go on to whoever was listening
 * before, and still fail the run.
 *
 * It is to a deprecated metadata key what {@see DeprecatedCall} is to a
 * deprecated method, and every use of this class is a test that still
 * covers the key. tests/Feature/DeprecationsTest.php pins the deprecation.
 */
final class TieWinnerDeprecation
{
    /** The metadata key, written out: the constant that names it is deprecated with it. */
    public const string KEY = 'tie_winner';

    /**
     * Runs one call of the library and returns what it returned, having
     * checked that the call raised the deprecation exactly once: a call
     * says it once however many ties the key decided in it. A call that
     * throws owes the notice too, when it read the key before it threw.
     *
     * @template T
     *
     * @param Closure(): T $call
     *
     * @return T
     *
     * @throws Throwable Whatever the call throws, once its notices have been checked
     */
    public static function once(Closure $call): mixed
    {
        return self::run(
            $call,
            static function (int $raised): void {
                Assert::assertSame(1, $raised, 'One call that reads the tie_winner metadata must raise its deprecation exactly once.');
            }
        );
    }

    /**
     * Runs code that makes any number of such calls and returns what it
     * returned, having checked that the deprecation was raised at all.
     *
     * @template T
     *
     * @param Closure(): T $code
     *
     * @return T
     *
     * @throws Throwable Whatever the code throws
     */
    public static function throughout(Closure $code): mixed
    {
        return self::run(
            $code,
            static function (int $raised, bool $threw): void {
                if (!$threw) {
                    Assert::assertGreaterThan(0, $raised, 'Code that settles a tie through the tie_winner metadata must raise its deprecation.');
                }
            }
        );
    }

    /**
     * How many times the code raised the deprecation: 0 for code that does
     * not read the key. Anything the code throws is thrown on.
     *
     * @param Closure(): mixed $code
     *
     * @throws Throwable Whatever the code throws
     */
    public static function countIn(Closure $code): int
    {
        $counted = 0;
        self::run(
            $code,
            static function (int $raised) use (&$counted): void {
                $counted = $raised;
            }
        );

        return $counted;
    }

    /**
     * Runs the code with the notices of the key taken, and hands their
     * number to the check before anything is returned or thrown on.
     *
     * @template T
     *
     * @param Closure(): T $code
     * @param Closure(int, bool): void $check Given how often the deprecation was raised, and
     *                                        whether the code threw
     *
     * @return T
     *
     * @throws Throwable Whatever the code throws, after the check
     */
    private static function run(Closure $code, Closure $check): mixed
    {
        $raised = 0;
        $previous = null;

        // Every level is listened to, for the reason DeprecatedCall gives:
        // PHP hands an error the newest handler does not listen to to its
        // own built-in handler and not to the handler before it.
        $previous = set_error_handler(
            static function (int $level, string $message, string $file = '', int $line = 0) use (&$raised, &$previous): bool {
                if ($level === E_USER_DEPRECATED && $message === TieResolution::TIE_WINNER_DEPRECATION) {
                    ++$raised;

                    return true;
                }

                return is_callable($previous) && $previous($level, $message, $file, $line) !== false;
            }
        );

        try {
            $result = $code();
        } catch (Throwable $throwable) {
            restore_error_handler();
            $check($raised, true);

            throw $throwable;
        }

        restore_error_handler();
        $check($raised, false);

        return $result;
    }
}
