<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use MissionGaming\Tactician\Exceptions\TacticianException;
use PhpToken;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

/**
 * Finds every place a PHP source file throws or builds an exception, and
 * says whether what it throws is a {@see TacticianException}.
 *
 * The source is read with PHP's tokenizer, not with a pattern over its text,
 * so a `throw` in a comment or a string is not a site, and a class name is
 * resolved the way PHP resolves it: against the file's namespace and its
 * `use` imports (aliases and group imports included), with `self`, `static`
 * and `parent` resolved against the enclosing class.
 *
 * Four kinds of site are reported (the KIND_* constants):
 *
 * - `throw new Name(...)`: the class must implement the marker.
 * - `throw Name::method(...)`: a static factory. Its declared return type
 *   must implement the marker; a factory with no return type is a problem.
 * - `throw $variable`: a rethrow. Every type the file binds to that name (in
 *   a `catch` clause, as the class type of a parameter, by
 *   `$variable = new Name`, or by assignment from another such variable)
 *   must implement the marker. The binding is looked up in the whole file,
 *   not in the enclosing function. So that this is never looser than PHP's
 *   scoping, a name the file also fills in a way the scan cannot read is a
 *   problem wherever it is thrown: a parameter of any function with no
 *   class type, a `foreach` variable, or an assignment of anything but
 *   `new Name`, another variable or `null`.
 * - `new Name(...)` anywhere else, when `Name` is a `Throwable`: an exception
 *   built in one place to be thrown from another.
 *
 * Anything else after `throw` (`throw $this->make()`, `throw new $class`, an
 * anonymous class, a call chained onto a factory) is reported as a problem,
 * because the class cannot be read from the source: the rule is that a site
 * is proven, not assumed.
 *
 * The classes named must be loadable, since the check is made by reflection.
 *
 * Gap left knowingly: an exception that reaches the caller without any
 * `throw` in the scanned source (one PHP raises inside a built-in function,
 * or one raised by a callable the caller supplied) is not a site. Those are
 * listed, with the decision taken for each, in docs/USAGE.md ("What the
 * marker does not cover").
 */
final class ThrowSites
{
    public const string KIND_THROW_NEW = 'throw new';

    public const string KIND_THROW_FACTORY = 'throw factory';

    public const string KIND_RETHROW = 'rethrow';

    public const string KIND_NEW = 'new';

    private const array NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_STATIC];

    /**
     * @return list<array{file: string, line: int, kind: self::KIND_*, subject: string, problem: ?string}>
     *     One entry per site, in source order. `subject` is the class, the
     *     `Class::method` or the variable the site names; `problem` is null
     *     when the site throws a TacticianException and says what is wrong
     *     otherwise.
     */
    public static function inSource(string $code, string $file): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($code),
            static fn(PhpToken $token): bool => !$token->isIgnorable(),
        ));
        $count = count($tokens);

        $namespace = '';
        /** @var array<string, string> $imports lower-case alias => fully qualified name */
        $imports = [];
        /** @var list<array{name: ?string, depth: int}> $classes the class bodies the cursor is inside */
        $classes = [];
        /** @var array{name: ?string}|null $pendingClass a class header whose body has not opened yet */
        $pendingClass = null;
        $depth = 0;
        /** @var int $parametersEnd the closing `)` of the last parameter list the cursor entered */
        $parametersEnd = -1;

        /** @var array<string, list<string>> $bindings variable => the classes the file binds to it */
        $bindings = [];
        /** @var array<string, list<string>> $copies variable => the variables assigned to it */
        $copies = [];
        /** @var array<string, string> $opaque variable => how the file fills it in a way the scan cannot read */
        $opaque = [];
        /** @var list<array{line: int, kind: self::KIND_*, subject: string, classes: list<string>, variable: ?string, problem: ?string}> $found */
        $found = [];

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            $next = $tokens[$i + 1] ?? null;
            $previous = $i > 0 ? $tokens[$i - 1] : null;
            $current = $classes === [] ? null : $classes[count($classes) - 1]['name'];

            if ($token->text === '{' || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$depth;
                if ($pendingClass !== null && $token->text === '{') {
                    $classes[] = ['name' => $pendingClass['name'], 'depth' => $depth];
                    $pendingClass = null;
                }

                continue;
            }
            if ($token->text === '}') {
                if ($classes !== [] && $classes[count($classes) - 1]['depth'] === $depth) {
                    array_pop($classes);
                }
                --$depth;

                continue;
            }

            if ($token->id === T_NAMESPACE && $next !== null && in_array($next->id, [T_STRING, T_NAME_QUALIFIED], true)) {
                $namespace = $next->text;
                $imports = [];

                continue;
            }

            if (
                in_array($token->id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                && $previous?->id !== T_DOUBLE_COLON
            ) {
                $declared = $next !== null && $next->id === T_STRING && $previous?->id !== T_NEW;
                $pendingClass = ['name' => $declared ? ltrim($namespace . '\\' . $next->text, '\\') : null];

                continue;
            }

            // An import: `use` outside a class body (inside one it applies a
            // trait) and not the `use (...)` of a closure.
            if ($token->id === T_USE && $classes === [] && $next?->text !== '(') {
                $i = self::readImports($tokens, $i + 1, $imports);

                continue;
            }

            if ($token->id === T_CATCH) {
                $i = self::readCatch($tokens, $i + 1, $namespace, $imports, $current, $bindings);

                continue;
            }

            // A parameter of a function, a closure or an arrow function holds
            // whatever the caller passes: its declared classes when it has
            // some, anything at all when it has none.
            if ($token->id === T_FUNCTION || $token->id === T_FN) {
                $open = $i + 1;
                while ($open < $count && $tokens[$open]->text !== '(') {
                    ++$open;
                }
                $parametersEnd = self::closingParenthesis($tokens, $open);

                foreach (self::parameters($tokens, $open, $parametersEnd) as $variable => $typeTokens) {
                    $types = [];
                    foreach ($typeTokens as $typeToken) {
                        $class = self::resolve($typeToken, $namespace, $imports, $current);
                        if ($class === null || (!class_exists($class) && !interface_exists($class))) {
                            // `mixed`, `object`, a scalar, or a name that cannot be loaded.
                            $types = [];

                            break;
                        }
                        $types[] = $class;
                    }

                    if ($types === []) {
                        $opaque[$variable] = 'is a parameter with no class type';
                    } else {
                        $bindings[$variable] = [...($bindings[$variable] ?? []), ...$types];
                    }
                }

                continue;
            }

            // `foreach (... as $v)` and `foreach (... as $k => $v)`.
            if (
                $token->id === T_VARIABLE
                && ($previous?->id === T_AS || ($previous?->id === T_DOUBLE_ARROW && ($tokens[$i - 3] ?? null)?->id === T_AS))
            ) {
                $opaque[$token->text] = 'is a foreach variable';
            }

            if ($token->id === T_VARIABLE && $next?->id === T_COALESCE_EQUAL) {
                $opaque[$token->text] = 'is assigned with ??=';
            }

            // `$a = $b;` and `$a = new Name`: what a later `throw $a` holds.
            // The default value of a parameter is not such an assignment.
            if ($token->id === T_VARIABLE && $next?->text === '=' && $i > $parametersEnd && $previous?->id !== T_DOUBLE_COLON && $previous?->id !== T_OBJECT_OPERATOR) {
                $value = $tokens[$i + 2] ?? null;
                $after = $tokens[$i + 3] ?? null;
                if ($value?->id === T_VARIABLE && $after?->text === ';') {
                    $copies[$token->text][] = $value->text;
                } elseif ($value?->id === T_NEW && $after !== null && in_array($after->id, self::NAME_TOKENS, true)) {
                    $class = self::resolve($after, $namespace, $imports, $current);
                    if ($class !== null) {
                        $bindings[$token->text][] = $class;
                    }
                } elseif (!($value?->id === T_STRING && strtolower($value->text) === 'null' && $after?->text === ';')) {
                    $opaque[$token->text] = 'is assigned something other than `new Name`, a variable or null';
                }

                continue;
            }

            if ($token->id === T_THROW) {
                $after = $tokens[$i + 2] ?? null;
                $third = $tokens[$i + 3] ?? null;

                if ($next?->id === T_NEW && $after !== null && in_array($after->id, self::NAME_TOKENS, true)) {
                    $class = self::resolve($after, $namespace, $imports, $current);
                    $found[] = [
                        'line' => $token->line,
                        'kind' => self::KIND_THROW_NEW,
                        'subject' => $class ?? $after->text,
                        'classes' => $class === null ? [] : [$class],
                        'variable' => null,
                        'problem' => $class === null ? "cannot resolve {$after->text} outside a class" : null,
                    ];
                    // The `new` is this site; do not report it a second time.
                    $i += 2;
                } elseif ($next?->id === T_VARIABLE && in_array($after?->text, [';', ')', ',', ']'], true)) {
                    $found[] = [
                        'line' => $token->line,
                        'kind' => self::KIND_RETHROW,
                        'subject' => $next->text,
                        'classes' => [],
                        'variable' => $next->text,
                        'problem' => null,
                    ];
                } elseif (
                    $next !== null && in_array($next->id, self::NAME_TOKENS, true)
                    && $after?->id === T_DOUBLE_COLON && $third?->id === T_STRING
                    && ($tokens[$i + 4] ?? null)?->text === '('
                ) {
                    $class = self::resolve($next, $namespace, $imports, $current);
                    [$returned, $problem] = $class === null
                        ? [[], "cannot resolve {$next->text} outside a class"]
                        : self::factoryReturnTypes($class, $third->text);
                    // `throw Name::factory()->other()` throws what `other()`
                    // returns, which the factory's return type does not say.
                    $closing = self::closingParenthesis($tokens, $i + 4);
                    if ($problem === null && !in_array(($tokens[$closing + 1] ?? null)?->text, [';', ')', ',', ']'], true)) {
                        $problem = 'the class thrown cannot be read from the source; something is chained onto the factory call';
                    }
                    $found[] = [
                        'line' => $token->line,
                        'kind' => self::KIND_THROW_FACTORY,
                        'subject' => ($class ?? $next->text) . '::' . $third->text,
                        'classes' => $returned,
                        'variable' => null,
                        'problem' => $problem,
                    ];
                } else {
                    $found[] = [
                        'line' => $token->line,
                        'kind' => self::KIND_THROW_NEW,
                        'subject' => $next === null ? '' : $next->text,
                        'classes' => [],
                        'variable' => null,
                        'problem' => 'the class thrown cannot be read from the source; throw `new Name`, a static factory with a declared return type, or a caught variable',
                    ];
                }

                continue;
            }

            if ($token->id === T_NEW && $next !== null && in_array($next->id, self::NAME_TOKENS, true)) {
                $class = self::resolve($next, $namespace, $imports, $current);
                if ($class === null) {
                    continue;
                }
                // A class that cannot be loaded is reported too: without it there
                // is no telling whether it is an exception.
                if ((!class_exists($class) && !interface_exists($class)) || is_a($class, Throwable::class, true)) {
                    $found[] = [
                        'line' => $token->line,
                        'kind' => self::KIND_NEW,
                        'subject' => $class,
                        'classes' => [$class],
                        'variable' => null,
                        'problem' => null,
                    ];
                }
            }
        }

        $sites = [];
        foreach ($found as $site) {
            $problem = $site['problem'];
            $classes = $site['classes'];

            if ($problem === null && $site['variable'] !== null) {
                [$classes, $unreadable] = self::boundClasses($site['variable'], $bindings, $copies, $opaque);
                if ($classes === []) {
                    $problem = "nothing in the file says what {$site['variable']} holds (no catch clause, no `= new`)";
                } elseif ($unreadable !== null) {
                    $problem = "the file does not say everything {$site['variable']} can hold: {$unreadable}";
                }
            }

            $problem ??= self::firstProblem($classes);

            $sites[] = [
                'file' => $file,
                'line' => $site['line'],
                'kind' => $site['kind'],
                'subject' => $site['subject'],
                'problem' => $problem,
            ];
        }

        return $sites;
    }

    /**
     * The fully qualified class a name token stands for, by PHP's rules.
     *
     * @param array<string, string> $imports lower-case alias => fully qualified name
     * @param string|null $current The enclosing class, for `self`, `static` and `parent`
     * @return string|null Null for `self`, `static` or `parent` outside a class
     */
    private static function resolve(PhpToken $token, string $namespace, array $imports, ?string $current): ?string
    {
        $text = $token->text;
        $lower = strtolower($text);

        if ($token->id === T_STATIC || $lower === 'self') {
            return $current;
        }
        if ($lower === 'parent') {
            $parent = $current !== null && class_exists($current) ? get_parent_class($current) : false;

            return $parent === false ? null : $parent;
        }
        if ($token->id === T_NAME_FULLY_QUALIFIED) {
            return substr($text, 1);
        }
        if ($token->id === T_NAME_RELATIVE) {
            return ltrim($namespace . substr($text, strlen('namespace')), '\\');
        }

        $segments = explode('\\', $text);
        $alias = strtolower($segments[0]);
        if (isset($imports[$alias])) {
            $segments[0] = $imports[$alias];

            return implode('\\', $segments);
        }

        return $namespace === '' ? $text : $namespace . '\\' . $text;
    }

    /**
     * Whether a type implements the marker, as a problem text when it does not.
     *
     * @param list<string> $classes
     */
    private static function firstProblem(array $classes): ?string
    {
        foreach ($classes as $class) {
            if (!class_exists($class) && !interface_exists($class)) {
                return "{$class} does not exist (is the name imported?)";
            }
            if (!is_a($class, TacticianException::class, true)) {
                return "{$class} does not implement " . TacticianException::class;
            }
        }

        return null;
    }

    /**
     * Every class the file binds to a variable, following assignments from
     * other variables, and the first way one of those variables is filled
     * that the scan cannot read.
     *
     * @param array<string, list<string>> $bindings
     * @param array<string, list<string>> $copies
     * @param array<string, string> $opaque
     * @return array{list<string>, ?string} The classes, and what cannot be read (null when all of it can)
     */
    private static function boundClasses(string $variable, array $bindings, array $copies, array $opaque): array
    {
        $classes = [];
        $unreadable = null;
        $seen = [];
        $queue = [$variable];
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $classes = [...$classes, ...($bindings[$name] ?? [])];
            $queue = [...$queue, ...($copies[$name] ?? [])];
            if (isset($opaque[$name])) {
                $unreadable ??= "{$name} {$opaque[$name]}";
            }
        }

        return [array_values(array_unique($classes)), $unreadable];
    }

    /**
     * The parameters in the list that follows a `function` or `fn` token,
     * each with the name tokens of its declared type (`null` left out). A
     * default value cannot hold a variable, so every variable between the
     * parentheses is a parameter.
     *
     * @param list<PhpToken> $tokens
     * @param int $open The index of the list's `(`
     * @param int $closing The index of its `)`
     * @return array<string, list<PhpToken>> variable => the names in its type; empty when it declares none
     */
    private static function parameters(array $tokens, int $open, int $closing): array
    {
        $parameters = [];
        for ($i = $open + 1; $i < $closing; ++$i) {
            if ($tokens[$i]->id !== T_VARIABLE) {
                continue;
            }

            // The type is what stands between the variable and the `,` or
            // `(` before it. `array` and `callable` are not name tokens, so
            // their presence is recorded as a name no class has.
            $type = [];
            for ($j = $i - 1; $j > $open && $tokens[$j]->text !== ','; --$j) {
                $isName = in_array($tokens[$j]->id, self::NAME_TOKENS, true) || in_array($tokens[$j]->id, [T_ARRAY, T_CALLABLE], true);
                if ($isName && strtolower($tokens[$j]->text) !== 'null') {
                    $type[] = $tokens[$j];
                }
            }
            $parameters[$tokens[$i]->text] = $type;
        }

        return $parameters;
    }

    /**
     * The index of the `)` that closes the `(` at an index (the number of
     * tokens when it is never closed).
     *
     * @param list<PhpToken> $tokens
     */
    private static function closingParenthesis(array $tokens, int $open): int
    {
        $count = count($tokens);
        $depth = 0;
        for ($i = $open; $i < $count; ++$i) {
            if ($tokens[$i]->text === '(') {
                ++$depth;
            } elseif ($tokens[$i]->text === ')' && --$depth === 0) {
                return $i;
            }
        }

        return $count;
    }

    /**
     * The classes a static factory declares it returns.
     *
     * @return array{list<string>, ?string} The classes, and a problem when they cannot be read
     */
    private static function factoryReturnTypes(string $class, string $method): array
    {
        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (ReflectionException) {
            return [[], "{$class}::{$method}() does not exist"];
        }

        $type = $reflection->getReturnType();
        if (!$type instanceof ReflectionType) {
            return [[], "{$class}::{$method}() declares no return type"];
        }

        $named = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        $classes = [];
        foreach ($named as $part) {
            if (!$part instanceof ReflectionNamedType) {
                return [[], "{$class}::{$method}() has a return type that cannot be checked"];
            }
            $classes[] = match (strtolower($part->getName())) {
                'self' => $reflection->getDeclaringClass()->getName(),
                'static' => $class,
                default => $part->getName(),
            };
        }

        return [$classes, null];
    }

    /**
     * Read one `use` statement into the import table.
     *
     * @param list<PhpToken> $tokens
     * @param array<string, string> $imports
     * @return int The index of the statement's closing `;`
     */
    private static function readImports(array $tokens, int $i, array &$imports): int
    {
        $count = count($tokens);
        // `use function` and `use const` import no class.
        $classes = !isset($tokens[$i]) || !in_array($tokens[$i]->id, [T_FUNCTION, T_CONST], true);
        $prefix = '';

        for (; $i < $count && $tokens[$i]->text !== ';'; ++$i) {
            $token = $tokens[$i];
            if (!in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                if ($token->text === '}') {
                    $prefix = '';
                }

                continue;
            }

            $name = ltrim($token->text, '\\');
            $next = $tokens[$i + 1] ?? null;

            // `use Prefix\{A, B as C};`
            if ($next?->id === T_NS_SEPARATOR && ($tokens[$i + 2] ?? null)?->text === '{') {
                $prefix = $name . '\\';
                $i += 2;

                continue;
            }

            $full = $prefix . $name;
            $alias = substr((string) strrchr('\\' . $name, '\\'), 1);
            if ($next?->id === T_AS && isset($tokens[$i + 2])) {
                $alias = $tokens[$i + 2]->text;
                $i += 2;
            }

            if ($classes) {
                $imports[strtolower($alias)] = $full;
            }
        }

        return $i;
    }

    /**
     * Read the types and the variable of one `catch (A|B $e)` clause.
     *
     * @param list<PhpToken> $tokens
     * @param array<string, string> $imports
     * @param array<string, list<string>> $bindings
     * @return int The index of the clause's closing `)`
     */
    private static function readCatch(array $tokens, int $i, string $namespace, array $imports, ?string $current, array &$bindings): int
    {
        $count = count($tokens);
        $types = [];

        for (; $i < $count && $tokens[$i]->text !== ')'; ++$i) {
            $token = $tokens[$i];
            if (in_array($token->id, self::NAME_TOKENS, true)) {
                $class = self::resolve($token, $namespace, $imports, $current);
                if ($class !== null) {
                    $types[] = $class;
                }
            } elseif ($token->id === T_VARIABLE) {
                $bindings[$token->text] = [...($bindings[$token->text] ?? []), ...$types];
            }
        }

        return $i;
    }
}
