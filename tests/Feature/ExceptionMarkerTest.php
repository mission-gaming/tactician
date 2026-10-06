<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\InvariantViolationException;
use MissionGaming\Tactician\Exceptions\JsonConversionException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Exceptions\RepackViolationsException;
use MissionGaming\Tactician\Exceptions\SchedulingException;
use MissionGaming\Tactician\Exceptions\TacticianException;
use MissionGaming\Tactician\Repack\Internal\BudgetExhausted;
use MissionGaming\Tactician\Tests\Support\ThrowSites;
use PHPUnit\Framework\Assert;

// The rule: everything `src/` throws on purpose implements
// Exceptions\TacticianException, so one catch clause covers the library.
//
// It is checked three ways, because a `throw` is not the only way an
// exception leaves a class:
//
// - every `throw` in `src/`, and every exception built with `new` to be
//   thrown from somewhere else (Support\ThrowSites reads the tokens and
//   resolves the names);
// - every class and interface in `src/` that is a Throwable;
// - every method in `src/` whose declared return type is a Throwable (a
//   factory, whoever calls it).
//
// Gap left knowingly - an exception PHP itself raises inside a built-in
// function has no `throw` in `src/`, so no reading of the source finds it.
// The sites where that can happen were audited by hand: the JSON ones are
// wrapped (tests/Unit/Exceptions/TacticianExceptionTest.php), and the ones
// left alone are listed in docs/USAGE.md ("What the marker does not cover").

$root = dirname(__DIR__, 2);

/**
 * Every PHP file under `src/`, as paths relative to the repository root.
 *
 * @return list<string>
 */
$sourceFiles = function () use ($root): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($files);

    return $files;
};

/**
 * Every site ThrowSites finds under `src/`.
 *
 * @return list<array{file: string, line: int, kind: ThrowSites::KIND_*, subject: string, problem: ?string}>
 */
$sourceSites = function () use ($root, $sourceFiles): array {
    $sites = [];
    foreach ($sourceFiles() as $file) {
        $sites = [...$sites, ...ThrowSites::inSource((string) file_get_contents($root . '/' . $file), $file)];
    }

    return $sites;
};

/**
 * The classes, interfaces, traits and enums of `src/`, by the PSR-4 rule the
 * autoloader uses.
 *
 * @return list<class-string>
 */
$sourceTypes = function () use ($sourceFiles): array {
    $types = [];
    foreach ($sourceFiles() as $file) {
        $type = 'MissionGaming\\Tactician\\' . str_replace('/', '\\', substr($file, strlen('src/'), -strlen('.php')));
        if (!class_exists($type) && !interface_exists($type) && !trait_exists($type) && !enum_exists($type)) {
            Assert::fail("{$file} does not declare {$type}, so it cannot be checked.");
        }
        $types[] = $type;
    }

    return $types;
};

/**
 * @param list<array{file: string, line: int, kind: string, subject: string, problem: ?string}> $sites
 * @return list<string> One line per site that has a problem
 */
function exceptionMarkerProblems(array $sites): array
{
    $problems = [];
    foreach ($sites as $site) {
        if ($site['problem'] !== null) {
            $problems[] = "{$site['file']}:{$site['line']} ({$site['kind']} {$site['subject']}): {$site['problem']}";
        }
    }

    return $problems;
}

/**
 * The problems ThrowSites reports for a piece of sample source.
 *
 * @return list<string>
 */
function exceptionMarkerProblemsIn(string $php): array
{
    $problems = [];
    foreach (ThrowSites::inSource("<?php\n" . $php, 'sample.php') as $site) {
        if ($site['problem'] !== null) {
            $problems[] = $site['problem'];
        }
    }

    return $problems;
}

describe('everything src/ throws', function () use ($root, $sourceFiles, $sourceSites, $sourceTypes): void {
    it('implements TacticianException at every throw and wherever an exception is built', function () use ($sourceSites): void {
        expect(exceptionMarkerProblems($sourceSites()))->toBe([]);
    });

    it('accounts for every throw token in src/', function () use ($root, $sourceFiles, $sourceSites): void {
        // An independent count: the scanner must not have skipped a `throw`
        // it did not know how to classify.
        $throwTokens = 0;
        foreach ($sourceFiles() as $file) {
            foreach (PhpToken::tokenize((string) file_get_contents($root . '/' . $file)) as $token) {
                if ($token->id === T_THROW) {
                    ++$throwTokens;
                }
            }
        }

        $thrown = array_filter($sourceSites(), static fn(array $site): bool => $site['kind'] !== ThrowSites::KIND_NEW);

        expect(count($thrown))->toBe($throwTokens);
    });

    it('found the sites, so it cannot pass by finding nothing', function () use ($sourceFiles, $sourceSites): void {
        $sites = $sourceSites();
        $kinds = array_count_values(array_column($sites, 'kind'));

        // 210 throws in 114 files when this was written. The floors are loose enough to
        // survive ordinary change and far above what a broken scan returns.
        expect(count($sourceFiles()))->toBeGreaterThan(100);
        expect($kinds[ThrowSites::KIND_THROW_NEW] ?? 0)->toBeGreaterThan(150);
        // The rethrows of a caught failure (RoundRobinScheduler, ScheduleOptimizer).
        expect($kinds[ThrowSites::KIND_RETHROW] ?? 0)->toBeGreaterThanOrEqual(3);
        // JsonConversionException::from() at the six JSON methods.
        expect($kinds[ThrowSites::KIND_THROW_FACTORY] ?? 0)->toBeGreaterThanOrEqual(6);
        // The exceptions built in the static factories of SchedulingException.
        expect($kinds[ThrowSites::KIND_NEW] ?? 0)->toBeGreaterThanOrEqual(3);

        $subjects = array_count_values(array_column($sites, 'subject'));
        expect($subjects[InvalidInputException::class] ?? 0)->toBeGreaterThanOrEqual(60);
        expect($subjects[InvariantViolationException::class] ?? 0)->toBeGreaterThanOrEqual(3);
        expect($subjects[InvalidConfigurationException::class] ?? 0)->toBeGreaterThan(100);
        expect($subjects[IncompleteScheduleException::class] ?? 0)->toBeGreaterThanOrEqual(1);
        expect($subjects[NoValidPairingException::class] ?? 0)->toBeGreaterThanOrEqual(1);
        expect($subjects[BudgetExhausted::class] ?? 0)->toBe(1);
        expect($subjects[JsonConversionException::class . '::from'] ?? 0)->toBeGreaterThanOrEqual(6);
    });

    it('resolved a known site to its file, kind and class', function () use ($sourceSites): void {
        $inEvent = array_values(array_filter(
            $sourceSites(),
            static fn(array $site): bool => $site['file'] === 'src/DTO/Event.php',
        ));

        expect($inEvent)->not->toBe([]);
        expect(array_unique(array_column($inEvent, 'kind')))->toBe([ThrowSites::KIND_THROW_NEW]);
        expect(array_unique(array_column($inEvent, 'subject')))->toBe([InvalidInputException::class]);
    });

    it('declares no Throwable outside the marker', function () use ($sourceTypes): void {
        $throwables = [];
        foreach ($sourceTypes() as $type) {
            if (is_a($type, Throwable::class, true)) {
                $throwables[] = $type;
                expect(is_a($type, TacticianException::class, true))->toBeTrue("{$type} is a Throwable outside TacticianException.");
            }
        }

        sort($throwables);
        expect($throwables)->toBe([
            IncompleteScheduleException::class,
            InvalidConfigurationException::class,
            InvalidInputException::class,
            InvariantViolationException::class,
            JsonConversionException::class,
            NoValidPairingException::class,
            RepackViolationsException::class,
            SchedulingException::class,
            TacticianException::class,
            BudgetExhausted::class,
        ]);
    });

    it('returns no Throwable outside the marker from any method', function () use ($sourceTypes): void {
        $factories = [];
        foreach ($sourceTypes() as $type) {
            foreach ((new ReflectionClass($type))->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $type) {
                    continue;
                }

                $returnType = $method->getReturnType();
                $parts = match (true) {
                    $returnType instanceof ReflectionNamedType => [$returnType],
                    $returnType instanceof ReflectionUnionType, $returnType instanceof ReflectionIntersectionType => $returnType->getTypes(),
                    default => [],
                };

                foreach ($parts as $part) {
                    if (!$part instanceof ReflectionNamedType || ($part->isBuiltin() && $part->getName() !== 'static')) {
                        continue;
                    }
                    $returned = in_array($part->getName(), ['self', 'static'], true) ? $type : $part->getName();
                    if (!is_a($returned, Throwable::class, true)) {
                        continue;
                    }

                    $factories[] = "{$type}::{$method->getName()}";
                    expect(is_a($returned, TacticianException::class, true))
                        ->toBeTrue("{$type}::{$method->getName()}() returns {$returned}, a Throwable outside TacticianException.");
                }
            }
        }

        // The three factories of SchedulingException and JsonConversionException::from().
        expect($factories)->toContain(SchedulingException::class . '::invalidParticipantCount');
        expect($factories)->toContain(JsonConversionException::class . '::from');
    });
});

describe('ThrowSites', function (): void {
    it('rejects a bare SPL exception, however the name is written', function (string $php, string $class): void {
        expect(exceptionMarkerProblemsIn($php))->toBe(["{$class} does not implement " . TacticianException::class]);
    })->with([
        'fully qualified' => ['namespace App; function f(): never { throw new \InvalidArgumentException("x"); }', 'InvalidArgumentException'],
        'imported' => ['namespace App; use InvalidArgumentException; function f(): never { throw new InvalidArgumentException("x"); }', 'InvalidArgumentException'],
        'imported under an alias' => ['namespace App; use LogicException as Broken; function f(): never { throw new Broken("x"); }', 'LogicException'],
        'imported in a group' => ['namespace App; use Random\{Randomizer, RandomException as Unlucky}; function f(): never { throw new Unlucky("x"); }', \Random\RandomException::class],
        'in the global namespace' => ['function f(): never { throw new RuntimeException("x"); }', 'RuntimeException'],
        'as a throw expression' => ['namespace App; function f(?int $v): int { return $v ?? throw new \LogicException("x"); }', 'LogicException'],
        'in a match arm' => ['namespace App; function f(int $v): int { return match ($v) { 1 => 1, default => throw new \DomainException("x") }; }', 'DomainException'],
        'in a closure that captures a variable' => ['namespace App; $x = 1; $f = function () use ($x): never { throw new \OutOfRangeException("x"); };', 'OutOfRangeException'],
        'built in one place to be thrown from another' => ['namespace App; function f(): \Exception { return new \UnexpectedValueException("x"); }', 'UnexpectedValueException'],
        'rethrown from a catch' => ['namespace App; function f(callable $c): void { try { $c(); } catch (\RuntimeException $e) { throw $e; } }', 'RuntimeException'],
        'rethrown from a catch of several types' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\SchedulingException; '
                . 'function f(callable $c): void { try { $c(); } catch (SchedulingException|\JsonException $e) { throw $e; } }',
            'JsonException',
        ],
        'rethrown after being kept in another variable' => [
            'namespace App; function f(callable $c): void { $last = null; try { $c(); } catch (\Exception $e) { $last = $e; } if ($last !== null) { throw $last; } }',
            'Exception',
        ],
        'thrown from a parameter, by its declared type' => ['namespace App; function f(\Throwable $e): never { throw $e; }', 'Throwable'],
        // Bindings are read file-wide: the catch clause in g() must not
        // vouch for the parameter of f().
        'thrown from a parameter named like a variable caught elsewhere' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\TacticianException; '
                . 'function f(TacticianException|\RuntimeException $e): never { throw $e; } '
                . 'function g(callable $c): void { try { $c(); } catch (TacticianException $e) { } }',
            'RuntimeException',
        ],
        'from a static factory, by its return type' => [
            'namespace App; use MissionGaming\Tactician\Tests\Support\BareExceptionFactory; function f(): never { throw BareExceptionFactory::typed(); }',
            'RuntimeException',
        ],
    ]);

    it('rejects a name that resolves to no class', function (): void {
        // Unqualified in a namespace, PHP looks for App\InvalidArgumentException.
        expect(exceptionMarkerProblemsIn('namespace App; function f(): never { throw new InvalidArgumentException("x"); }'))
            ->toBe(['App\InvalidArgumentException does not exist (is the name imported?)']);
    });

    it('rejects a throw whose class cannot be read from the source', function (string $php, string $problem): void {
        $problems = exceptionMarkerProblemsIn($php);

        expect($problems)->toHaveCount(1);
        expect($problems[0])->toContain($problem);
    })->with([
        'a variable nothing binds' => ['namespace App; function f(): never { global $e; throw $e; }', 'nothing in the file says what $e holds'],
        'a method call' => ['namespace App; final class A { public function f(): never { throw $this->make(); } }', 'cannot be read from the source'],
        'a class held in a variable' => ['namespace App; function f(string $c): never { throw new $c("x"); }', 'cannot be read from the source'],
        'a factory with no return type' => [
            'namespace App; use MissionGaming\Tactician\Tests\Support\BareExceptionFactory; function f(): never { throw BareExceptionFactory::untyped(); }',
            'declares no return type',
        ],
        'a factory that does not exist' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\SchedulingException; function f(): never { throw SchedulingException::nope(); }',
            'does not exist',
        ],
        'a call chained onto a factory' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\JsonConversionException; '
                . 'function f(\JsonException $e): never { throw JsonConversionException::from($e)->getPrevious(); }',
            'something is chained onto the factory call',
        ],
        // Bindings are read file-wide, so each of these would pass on the
        // strength of the catch clause in g() if only that clause were read.
        'an untyped parameter named like a variable caught elsewhere' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\TacticianException; '
                . 'function f($e): never { throw $e; } '
                . 'function g(callable $c): void { try { $c(); } catch (TacticianException $e) { } }',
            '$e is a parameter with no class type',
        ],
        'a parameter typed as any object' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\TacticianException; '
                . 'final class A { public function __construct(private readonly ?object $e = null) {} } '
                . 'function g(callable $c): void { try { $c(); } catch (TacticianException $e) { throw $e; } }',
            '$e is a parameter with no class type',
        ],
        'an untyped parameter of an arrow function' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\TacticianException; '
                . '$f = fn($e): never => throw $e; '
                . 'function g(callable $c): void { try { $c(); } catch (TacticianException $e) { } }',
            '$e is a parameter with no class type',
        ],
        'a variable also assigned from a call' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\TacticianException; '
                . 'function f(object $o): never { $e = $o->make(); throw $e; } '
                . 'function g(callable $c): void { try { $c(); } catch (TacticianException $e) { } }',
            '$e is assigned something other than',
        ],
        'a variable copied from one assigned from a call' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\TacticianException; '
                . 'function f(object $o, callable $c): never { $made = $o->make(); try { $c(); } catch (TacticianException $e) { } $e = $made; throw $e; }',
            '$made is assigned something other than',
        ],
        'a variable also assigned with ??=' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\TacticianException; '
                . 'function f(object $o, callable $c): never { $e = null; try { $c(); } catch (TacticianException $e) { } $e ??= $o->make(); throw $e; }',
            '$e is assigned with ??=',
        ],
        'a foreach variable named like a variable caught elsewhere' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\TacticianException; '
                . 'function f(array $all): never { foreach ($all as $key => $e) { throw $e; } } '
                . 'function g(callable $c): void { try { $c(); } catch (TacticianException $e) { } }',
            '$e is a foreach variable',
        ],
    ]);

    it('accepts the library exceptions', function (string $php, array $kinds): void {
        $sites = ThrowSites::inSource("<?php\n" . $php, 'sample.php');

        expect(array_column($sites, 'problem'))->toBe(array_fill(0, count($kinds), null));
        expect(array_column($sites, 'kind'))->toBe($kinds);
    })->with([
        'imported' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\InvalidInputException; function f(): never { throw new InvalidInputException("x"); }',
            [ThrowSites::KIND_THROW_NEW],
        ],
        'fully qualified' => [
            'namespace App; function f(): never { throw new \MissionGaming\Tactician\Exceptions\InvariantViolationException("x"); }',
            [ThrowSites::KIND_THROW_NEW],
        ],
        'by a qualified name through an imported namespace' => [
            'namespace App; use MissionGaming\Tactician\Exceptions; function f(): never { throw new Exceptions\InvalidInputException("x"); }',
            [ThrowSites::KIND_THROW_NEW],
        ],
        'relative to the namespace' => [
            'namespace MissionGaming\Tactician; function f(): never { throw new namespace\Exceptions\InvalidInputException("x"); }',
            [ThrowSites::KIND_THROW_NEW],
        ],
        'from a static factory' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\JsonConversionException; '
                . 'function f(\JsonException $e): never { throw JsonConversionException::from($e); }',
            [ThrowSites::KIND_THROW_FACTORY],
        ],
        'from a factory that returns self' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\SchedulingException; function f(): never { throw SchedulingException::invalidParticipantCount(1); }',
            [ThrowSites::KIND_THROW_FACTORY],
        ],
        'rethrown from a catch' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\TacticianException; function f(callable $c): void { try { $c(); } catch (TacticianException $e) { throw $e; } }',
            [ThrowSites::KIND_RETHROW],
        ],
        'thrown from a parameter typed as a library exception' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\IncompleteScheduleException; '
                . 'function f(?IncompleteScheduleException $failure = null, int ...$rest): void { if ($failure !== null) { throw $failure; } }',
            [ThrowSites::KIND_RETHROW],
        ],
        'rethrown after being kept in a variable that starts as null' => [
            'namespace App; use MissionGaming\Tactician\Exceptions\SchedulingException; '
                . 'function f(callable $c): void { $last = null; try { $c(); } catch (SchedulingException $e) { $last = $e; } if ($last !== null) { throw $last; } }',
            [ThrowSites::KIND_RETHROW],
        ],
        'built and returned' => [
            'namespace MissionGaming\Tactician\Exceptions; function f(): SchedulingException { return new InvalidConfigurationException("x"); }',
            [ThrowSites::KIND_NEW],
        ],
    ]);

    it('ignores what only looks like a throw', function (string $php): void {
        expect(ThrowSites::inSource("<?php\n" . $php, 'sample.php'))->toBe([]);
    })->with([
        'a comment' => ['namespace App; // throw new \InvalidArgumentException("x");' . "\n" . 'function f(): void {}'],
        'a docblock' => ['namespace App; /** throw new \LogicException("x"); */ function f(): void {}'],
        'a string' => ['namespace App; function f(): string { return \'throw new \InvalidArgumentException("x");\'; }'],
        'an object that is not an exception' => ['namespace App; function f(): object { return new \ArrayObject([]); }'],
        'an import that is never thrown' => ['namespace App; use InvalidArgumentException; function f(): void {}'],
    ]);

    it('does not take a trait or a closure use for an import', function (): void {
        // Were the `use` in the class body read as an import, the name
        // thrown below would resolve to the library class and pass. PHP
        // resolves it to App\InvalidInputException, which is not one.
        $php = <<<'PHP'
            namespace App;

            final class A
            {
                use \MissionGaming\Tactician\Exceptions\InvalidInputException;

                public function f(int $value): \Closure
                {
                    return function () use ($value): never {
                        throw new InvalidInputException('x');
                    };
                }
            }
            PHP;

        expect(exceptionMarkerProblemsIn($php))->toBe(['App\InvalidInputException does not exist (is the name imported?)']);
    });

    it('resolves self and static to the enclosing class', function (string $keyword, ?string $problem): void {
        // Written in the namespace of a real class, so that the names resolve.
        $php = 'namespace MissionGaming\Tactician\Tests\Support; final class BareExceptionFactory extends \RuntimeException '
            . "{ public static function f(): never { throw new {$keyword}('x'); } }";

        expect(exceptionMarkerProblemsIn($php))->toBe($problem === null ? [] : [$problem]);
    })->with([
        'self' => ['self', 'MissionGaming\Tactician\Tests\Support\BareExceptionFactory does not implement ' . TacticianException::class],
        'static' => ['static', 'MissionGaming\Tactician\Tests\Support\BareExceptionFactory does not implement ' . TacticianException::class],
    ]);

    it('resolves parent to the class the enclosing one extends', function (): void {
        $php = 'namespace MissionGaming\Tactician\Exceptions; final class InvalidInputException extends \InvalidArgumentException '
            . "{ public static function f(): never { throw new parent('x'); } }";

        expect(exceptionMarkerProblemsIn($php))->toBe(['InvalidArgumentException does not implement ' . TacticianException::class]);
    });

    it('reports the line of each site', function (): void {
        $sites = ThrowSites::inSource("<?php\n\nnamespace App;\n\nfunction f(): never\n{\n    throw new \\LogicException('x');\n}\n", 'sample.php');

        expect($sites)->toHaveCount(1);
        expect($sites[0]['file'])->toBe('sample.php');
        expect($sites[0]['line'])->toBe(7);
        expect($sites[0]['subject'])->toBe('LogicException');
    });
});
