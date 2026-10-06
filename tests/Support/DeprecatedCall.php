<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Exception as PHPUnitException;
use ReflectionException;
use ReflectionMethod;
use Throwable;

/**
 * Calls a deprecated method of the library on purpose, and checks what PHP
 * said about the call.
 *
 * A deprecated method stays covered until it is removed, so its tests still
 * have to call it. Two things stand in the way, and this class is the one
 * place that deals with both:
 *
 * - The method carries the native `#[\Deprecated]` attribute. From PHP 8.4 a
 *   call emits `E_USER_DEPRECATED`, and the suite fails on any deprecation
 *   (`failOnDeprecation` in `phpunit.xml`). On PHP 8.3 the attribute does
 *   nothing. {@see self::to()} takes the notice of its own call and asserts
 *   it: exactly one, naming the method, from PHP 8.4, and none on PHP 8.3.
 *   Only that notice is taken. A deprecation from anywhere else in the test
 *   still reaches PHPUnit and still fails the run, and so does anything
 *   else raised while the method runs: a warning, a notice, or a second
 *   deprecation from inside its body.
 * - PHPStan reports a call to a deprecated method, in tests as in `src/`.
 *   The method is therefore named as a string, which also makes a test that
 *   covers a deprecated method easy to find: it is every use of this class.
 *
 * PHPUnit's own `expectUserDeprecationMessage()` is not used. It verifies
 * that a deprecation was triggered and does not stop the run from failing on
 * it; that takes the `#[IgnoreDeprecations]` attribute on the test method,
 * which a Pest closure cannot carry, and which would let through every other
 * deprecation of the test as well.
 *
 * tests/Feature/DeprecationsTest.php pins which methods are deprecated.
 */
final class DeprecatedCall
{
    /**
     * Calls the method and returns what it returned.
     *
     * @param object|class-string $target The object, or the class of a static method
     *
     * @throws ReflectionException When the target has no such method
     * @throws PHPUnitException When the method is not deprecated, or the call did not say what a deprecated call must
     * @throws Throwable Whatever the deprecated method throws, once the notice of the call has been checked
     */
    public static function to(object|string $target, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $name = $reflection->getDeclaringClass()->getName() . '::' . $reflection->getName() . '()';

        Assert::assertNotSame(
            [],
            $reflection->getAttributes('Deprecated'),
            "{$name} is not deprecated; call it directly."
        );

        $notice = "Method {$name} is deprecated since ";
        $deprecations = [];
        $previous = null;

        // The handler listens to every level, because PHP hands an error
        // that the newest handler does not listen to straight to its own
        // built-in handler and not to the handler before it: a mask of
        // E_USER_DEPRECATED would hide a warning or a notice raised inside
        // the method from PHPUnit. Everything but the notice of this one
        // call goes on to whoever was listening before.
        $previous = set_error_handler(
            static function (int $level, string $message, string $file = '', int $line = 0) use (&$deprecations, &$previous, $notice): bool {
                if ($level === E_USER_DEPRECATED && str_starts_with($message, $notice)) {
                    $deprecations[] = $message;

                    return true;
                }

                return is_callable($previous) && $previous($level, $message, $file, $line) !== false;
            }
        );

        $result = null;
        $thrown = null;

        try {
            $result = $reflection->invokeArgs(is_object($target) ? $target : null, $arguments);
        } catch (Throwable $throwable) {
            // Kept until the notice has been checked: PHP emits it before
            // the method's body runs, so a call that throws owes it too.
            $thrown = $throwable;
        } finally {
            restore_error_handler();
        }

        if (PHP_VERSION_ID < 80400) {
            // The attribute is inert before PHP 8.4
            Assert::assertSame([], $deprecations, "{$name} emitted a deprecation on a PHP that does not act on the attribute.");
        } else {
            Assert::assertCount(1, $deprecations, "A call to {$name} must emit exactly one deprecation that begins \"{$notice}\".");
        }

        if ($thrown !== null) {
            throw $thrown;
        }

        return $result;
    }
}
