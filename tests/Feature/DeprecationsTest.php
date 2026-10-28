<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\SchedulingException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\EliminationPlan;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\SwissPlan;
use MissionGaming\Tactician\Stage\TieDecision;
use MissionGaming\Tactician\Stage\TieResolution;
use MissionGaming\Tactician\Tests\Support\DeprecatedCall;
use MissionGaming\Tactician\Tests\Support\DeprecatedCallProbe;
use MissionGaming\Tactician\Tests\Support\DocumentationSnippets;
use MissionGaming\Tactician\Tests\Support\TieWinnerDeprecation;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

// The deprecated symbols of the library are a pinned set: deprecating one
// more, or removing one, changes the list below on purpose and in the same
// change as the "Deprecations" section of docs/USAGE.md and the changelog.
//
// One of them is a constant and the others are methods; what is said of a
// method below holds for the constant with "use" for "call". The constant
// names a metadata key, and the key is deprecated with it. A key is no
// symbol, so nothing here can find it by reflection: the last group of this
// file pins what reading it does.
//
// Each deprecated method says so twice. The `@deprecated` docblock tag is
// what an IDE and PHPStan read, on every PHP version. The native
// `#[\Deprecated]` attribute is what PHP reads: it makes a call emit
// `E_USER_DEPRECATED`. The two must agree on the version.
//
// Nothing the library ships or documents may call one: the suite fails on a
// deprecation, and the examples and the documentation snippets run under
// E_ALL. PHPStan reports such a call in `src/` and in the tests; the scan
// below covers the examples and the snippets as well, without running them.
//
// Gap left knowingly - the scan matches a call by the method's name, so it
// cannot tell `$context->getTotalLegs()` from a method of the same name on
// another class. No such method exists; one would be reported here and the
// name would have to be told apart then.

$root = dirname(__DIR__, 2);

/**
 * Every deprecated symbol of the library, and the version its deprecation
 * dates from. A method is written with its parentheses, a constant without.
 */
const DEPRECATED_SINCE = [
    'MissionGaming\Tactician\Diagnostics\SchedulingDiagnostics::identifyConstraintConflicts()' => '0.2.2',
    'MissionGaming\Tactician\Diagnostics\SchedulingDiagnostics::suggestConstraintAdjustments()' => '0.2.2',
    'MissionGaming\Tactician\Exceptions\SchedulingException::constraintViolation()' => '0.2.2',
    'MissionGaming\Tactician\Exceptions\SchedulingException::invalidParticipantCount()' => '0.2.2',
    'MissionGaming\Tactician\Exceptions\SchedulingException::invalidSchedule()' => '0.2.2',
    'MissionGaming\Tactician\Scheduling\SchedulingContext::getTotalLegs()' => '0.2.2',
    'MissionGaming\Tactician\Stage\TieDecision::TIE_WINNER_KEY' => '0.3.0',
    'MissionGaming\Tactician\Validation\ScheduleValidator::generateConstraintSuggestions()' => '0.2.2',
    'MissionGaming\Tactician\Validation\ScheduleValidator::generateDiagnosticReport()' => '0.2.2',
];

/** Every deprecated symbol of the library. */
const DEPRECATED_SYMBOLS = [
    'MissionGaming\Tactician\Diagnostics\SchedulingDiagnostics::identifyConstraintConflicts()',
    'MissionGaming\Tactician\Diagnostics\SchedulingDiagnostics::suggestConstraintAdjustments()',
    'MissionGaming\Tactician\Exceptions\SchedulingException::constraintViolation()',
    'MissionGaming\Tactician\Exceptions\SchedulingException::invalidParticipantCount()',
    'MissionGaming\Tactician\Exceptions\SchedulingException::invalidSchedule()',
    'MissionGaming\Tactician\Scheduling\SchedulingContext::getTotalLegs()',
    'MissionGaming\Tactician\Stage\TieDecision::TIE_WINNER_KEY',
    'MissionGaming\Tactician\Validation\ScheduleValidator::generateConstraintSuggestions()',
    'MissionGaming\Tactician\Validation\ScheduleValidator::generateDiagnosticReport()',
];

/**
 * Every PHP file under a directory of the repository, as paths relative to
 * the repository root.
 *
 * @return list<string>
 */
$phpFiles = function (string $directory) use ($root): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($files);

    return $files;
};

/**
 * The classes, interfaces, traits and enums of `src/`, by the PSR-4 rule the
 * autoloader uses.
 *
 * @return list<class-string>
 */
$sourceTypes = function () use ($phpFiles): array {
    $types = [];
    foreach ($phpFiles('src') as $file) {
        $type = 'MissionGaming\\Tactician\\' . str_replace('/', '\\', substr($file, strlen('src/'), -strlen('.php')));
        if (!class_exists($type) && !interface_exists($type) && !trait_exists($type) && !enum_exists($type)) {
            Assert::fail("{$file} does not declare {$type}, so it cannot be checked.");
        }
        $types[] = $type;
    }

    return $types;
};

/**
 * Everything in `src/` that reflection can show to be deprecated, by its
 * docblock or by the attribute: types, methods, properties, constants and
 * enum cases. Each entry has the symbol's docblock and the arguments of its
 * `#[\Deprecated]` attribute, null when it has none. The attribute is read
 * by name and never instantiated.
 *
 * @return array<string, array{docblock: string, attribute: ?array<array-key, mixed>}>
 */
$deprecated = function () use ($sourceTypes): array {
    $found = [];

    $record = function (string $symbol, string|false $docblock, array $attributes) use (&$found): void {
        $attribute = null;
        foreach ($attributes as $candidate) {
            if ($candidate instanceof ReflectionAttribute && $candidate->getName() === 'Deprecated') {
                $attribute = $candidate->getArguments();
            }
        }

        $docblock = $docblock === false ? '' : $docblock;

        if ($attribute !== null || str_contains($docblock, '@deprecated')) {
            $found[$symbol] = ['docblock' => $docblock, 'attribute' => $attribute];
        }
    };

    foreach ($sourceTypes() as $type) {
        $class = new ReflectionClass($type);
        $record($type, $class->getDocComment(), $class->getAttributes());

        foreach ($class->getMethods() as $method) {
            // A method a trait supplies is recorded once, on the trait
            if ($method->getFileName() === $class->getFileName() && $method->getDeclaringClass()->getName() === $type) {
                $record("{$type}::{$method->getName()}()", $method->getDocComment(), $method->getAttributes());
            }
        }

        foreach ($class->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $type) {
                $record("{$type}::\${$property->getName()}", $property->getDocComment(), $property->getAttributes());
            }
        }

        foreach ($class->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() === $type) {
                $record("{$type}::{$constant->getName()}", $constant->getDocComment(), $constant->getAttributes());
            }
        }
    }

    ksort($found);

    return $found;
};

/**
 * The calls a piece of PHP source makes to a method with one of the given
 * names: `->name(`, `?->name(` and `::name(`, read from the tokens, so a
 * name in a comment, a string or a docblock is not a call.
 *
 * @param list<string> $methods
 *
 * @return list<string> One "name() on line N" per call
 */
$callsIn = function (string $php, array $methods): array {
    $tokens = array_values(array_filter(
        PhpToken::tokenize($php),
        static fn(PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
    ));
    $calls = [];

    foreach ($tokens as $index => $token) {
        if ($token->id !== T_STRING || !in_array($token->text, $methods, true)) {
            continue;
        }

        $before = $tokens[$index - 1] ?? null;
        $after = $tokens[$index + 1] ?? null;

        if ($before !== null && $before->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON]) && $after?->text === '(') {
            $calls[] = "{$token->text}() on line {$token->line}";
        }
    }

    return $calls;
};

/** @return list<string> The bare method names of the pinned symbols that are methods */
$deprecatedMethodNames = fn(): array => array_values(array_unique(array_map(
    static fn(string $symbol): string => substr($symbol, (int) strrpos($symbol, ':') + 1, -2),
    array_values(array_filter(DEPRECATED_SYMBOLS, static fn(string $symbol): bool => str_ends_with($symbol, '()')))
)));

/**
 * The uses a piece of PHP source makes of a class constant with one of the
 * given names (`::NAME` that is not a call), and the string literals it
 * holds that are one of the given strings, read from the tokens: a name or
 * a string in a comment or a docblock is neither.
 *
 * @param list<string> $constants
 * @param list<string> $strings
 *
 * @return list<string> One "NAME on line N" or "'string' on line N" per use
 */
$constantUsesIn = function (string $php, array $constants, array $strings = []): array {
    $tokens = array_values(array_filter(
        PhpToken::tokenize($php),
        static fn(PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
    ));
    $uses = [];

    foreach ($tokens as $index => $token) {
        if ($token->id === T_CONSTANT_ENCAPSED_STRING && in_array(substr($token->text, 1, -1), $strings, true)) {
            $uses[] = "{$token->text} on line {$token->line}";
        }

        if ($token->id !== T_STRING || !in_array($token->text, $constants, true)) {
            continue;
        }

        $before = $tokens[$index - 1] ?? null;
        $after = $tokens[$index + 1] ?? null;

        if ($before !== null && $before->is(T_DOUBLE_COLON) && $after?->text !== '(') {
            $uses[] = "{$token->text} on line {$token->line}";
        }
    }

    return $uses;
};

/**
 * The fenced `php` blocks of every Markdown file of the repository that
 * documents the library, as [document, code] pairs. The "Deprecations"
 * section of the usage guide is left out: it is the one place that shows
 * what a deprecated thing was, in order to say what replaces it. The
 * changelog is left out for the same reason.
 *
 * @return list<array{string, string}>
 */
$markdownPhpBlocks = function () use ($root): array {
    $documents = ['AGENTS.md', 'README.md', 'examples/README.md'];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/docs', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'md') {
            $documents[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($documents);

    $blocks = [];
    foreach ($documents as $document) {
        $text = (string) preg_replace('/^## Deprecations\n.*?(?=^## |\z)/ms', '', (string) file_get_contents($root . '/' . $document));
        preg_match_all('/^```php\n(.*?)^```/ms', $text, $matches);

        foreach ($matches[1] as $block) {
            $blocks[] = [$document, str_starts_with(ltrim($block), '<?php') ? $block : "<?php\n" . $block];
        }
    }

    return $blocks;
};

describe('the deprecated symbols', function () use ($root, $phpFiles, $deprecated): void {
    it('are exactly the pinned set', function () use ($deprecated): void {
        expect(array_keys($deprecated()))->toBe(DEPRECATED_SYMBOLS);
    });

    it('were all found by reflection: the source holds no other tag or attribute', function () use ($root, $phpFiles, $deprecated): void {
        // An independent count over the text of `src/`. A deprecation that
        // reflection cannot see (on a function, a parameter, a closure)
        // would make the two numbers differ.
        $tags = 0;
        $attributes = 0;

        foreach ($phpFiles('src') as $file) {
            foreach (PhpToken::tokenize((string) file_get_contents($root . '/' . $file)) as $token) {
                if ($token->id === T_DOC_COMMENT) {
                    $tags += (int) preg_match_all('/^[\s\/*]*@deprecated\b/m', $token->text);
                }
            }

            $attributes += (int) preg_match_all('/#\[\\\\?Deprecated\b/', (string) file_get_contents($root . '/' . $file));
        }

        expect($tags)->toBe(count($deprecated()))
            ->and($attributes)->toBe(count($deprecated()))
            ->and($tags)->toBe(count(DEPRECATED_SYMBOLS));
    });

    it('each have the version their deprecation dates from', function (): void {
        expect(array_keys(DEPRECATED_SINCE))->toBe(DEPRECATED_SYMBOLS);
    });

    it('each say since when, and that removal is at 1.0.0, in the docblock', function () use ($deprecated): void {
        foreach ($deprecated() as $symbol => $facts) {
            $since = DEPRECATED_SINCE[$symbol] ?? 'a version this test does not pin';

            expect($facts['docblock'])->toMatch(
                '/@deprecated since ' . preg_quote($since, '/') . ', removed in 1\.0\.0\. \S/',
                "{$symbol} must say \"@deprecated since " . $since . ', removed in 1.0.0." and then why and what to use instead.'
            );

            // Why, and the replacement or that there is none
            expect($facts['docblock'])->toMatch('/instead|no replacement/', "{$symbol} must name its replacement or say there is none.");
        }
    });

    it('each carry the native attribute, with the same version and a message', function () use ($deprecated): void {
        foreach ($deprecated() as $symbol => $facts) {
            expect($facts['attribute'])->not->toBeNull("{$symbol} has the docblock tag but no #[\\Deprecated] attribute.");

            $arguments = $facts['attribute'] ?? [];

            expect(array_keys($arguments))->toBe(['message', 'since'], "{$symbol} must name both arguments of the attribute.")
                ->and($arguments['since'] ?? null)->toBe(DEPRECATED_SINCE[$symbol] ?? null)
                ->and($arguments['message'] ?? null)->toBeString()
                ->and((string) ($arguments['message'] ?? ''))->toMatch('/instead|no replacement/');
        }
    });

    it('each exist as a public method or constant PHP reports as deprecated where it can', function (): void {
        foreach (DEPRECATED_SYMBOLS as $symbol) {
            if (!str_ends_with($symbol, '()')) {
                [$class, $constant] = explode('::', $symbol);
                $reflection = new ReflectionClassConstant($class, $constant);

                // Reflection reports a deprecated constant only from PHP
                // 8.4 (ReflectionClassConstant::isDeprecated()); the
                // attribute it acts on is there on every version
                expect($reflection->isPublic())->toBeTrue()
                    ->and($reflection->getAttributes('Deprecated'))->toHaveCount(1);

                continue;
            }

            [$class, $method] = explode('::', substr($symbol, 0, -2));
            $reflection = new ReflectionMethod($class, $method);

            expect($reflection->isPublic())->toBeTrue();

            // PHP acts on the attribute
            expect($reflection->isDeprecated())->toBeTrue();
        }
    });
});

describe('a deprecated method is called by nothing that ships or is documented', function () use ($root, $phpFiles, $callsIn, $deprecatedMethodNames): void {
    it('in src/ and examples/', function () use ($root, $phpFiles, $callsIn, $deprecatedMethodNames): void {
        $files = [...$phpFiles('src'), ...$phpFiles('examples')];
        $calls = [];

        foreach ($files as $file) {
            foreach ($callsIn((string) file_get_contents($root . '/' . $file), $deprecatedMethodNames()) as $call) {
                $calls[] = "{$file}: {$call}";
            }
        }

        expect(count($files))->toBeGreaterThan(130)
            ->and($calls)->toBe([]);
    });

    it('in the code blocks of a document', function (string $document) use ($root, $callsIn, $deprecatedMethodNames): void {
        $snippets = DocumentationSnippets::extract($document, (string) file_get_contents($root . '/' . $document))['snippets'];
        $calls = [];

        foreach ($snippets as $snippet) {
            foreach ($callsIn("<?php\n" . implode("\n", $snippet->code), $deprecatedMethodNames()) as $call) {
                $calls[] = "{$snippet->location()}: {$call}";
            }
        }

        expect($snippets)->not->toBeEmpty()
            ->and($calls)->toBe([]);
    })->with(DocumentationSnippets::DOCUMENTS);

    // The two documents above are the ones the snippet harness executes. The
    // architecture notes, the design notes, the integration guides and the
    // examples' own README are never run, so a call there would say nothing
    // on any PHP version; their fenced `php` blocks are scanned here.
    it('in the php blocks of every Markdown file, executed or not', function () use ($root, $callsIn, $deprecatedMethodNames): void {
        $documents = ['AGENTS.md', 'CHANGELOG.md', 'README.md', 'examples/README.md'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/docs', FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'md') {
                $documents[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($documents);

        $blocks = 0;
        $calls = [];

        foreach ($documents as $document) {
            preg_match_all('/^```php\n(.*?)^```/ms', (string) file_get_contents($root . '/' . $document), $matches);

            foreach ($matches[1] as $block) {
                ++$blocks;
                $code = str_starts_with(ltrim($block), '<?php') ? $block : "<?php\n" . $block;

                foreach ($callsIn($code, $deprecatedMethodNames()) as $call) {
                    $calls[] = "{$document}: {$call}";
                }
            }
        }

        expect($documents)->toContain('docs/ARCHITECTURE.md', 'docs/integrations/symfony.md', 'docs/integrations/laravel.md')
            // 107 blocks when this was written
            ->and($blocks)->toBeGreaterThan(50)
            ->and($calls)->toBe([]);
    });

    it('finds a call when there is one', function () use ($callsIn): void {
        $methods = ['getTotalLegs', 'invalidSchedule'];

        expect($callsIn('<?php $legs = $context->getTotalLegs();', $methods))->toBe(['getTotalLegs() on line 1'])
            ->and($callsIn("<?php\n\$legs = \$context?->getTotalLegs ();", $methods))->toBe(['getTotalLegs() on line 2'])
            ->and($callsIn('<?php throw SchedulingException::invalidSchedule("x");', $methods))->toBe(['invalidSchedule() on line 1'])
            // A declaration, a mention and another method are not calls
            ->and($callsIn('<?php class C { public function getTotalLegs(): int { return 1; } }', $methods))->toBe([])
            ->and($callsIn('<?php // $context->getTotalLegs()' . "\n" . '$text = "$context->getTotalLegs()";', $methods))->toBe([])
            ->and($callsIn('<?php $legs = $context->getPlan()->getLegs();', $methods))->toBe([]);
    });
});

describe('the deprecated constant is used by nothing that ships or is documented', function () use ($root, $phpFiles, $constantUsesIn, $markdownPhpBlocks): void {
    // The library reads the metadata key under a constant of its own
    // (TieResolution), because a use of the deprecated one emits a notice
    // from PHP 8.4. The examples and the documentation record a decider.
    it('in src/ and examples/', function () use ($root, $phpFiles, $constantUsesIn): void {
        $uses = [];
        foreach ([...$phpFiles('src'), ...$phpFiles('examples')] as $file) {
            foreach ($constantUsesIn((string) file_get_contents($root . '/' . $file), ['TIE_WINNER_KEY']) as $use) {
                $uses[] = "{$file}: {$use}";
            }
        }

        expect($uses)->toBe([]);
    });

    it('nor is the key it names written out, in examples/ and in the php blocks of every Markdown file', function () use ($root, $phpFiles, $constantUsesIn, $markdownPhpBlocks): void {
        $uses = [];
        foreach ($phpFiles('examples') as $file) {
            foreach ($constantUsesIn((string) file_get_contents($root . '/' . $file), ['TIE_WINNER_KEY'], [TieWinnerDeprecation::KEY]) as $use) {
                $uses[] = "{$file}: {$use}";
            }
        }

        $blocks = $markdownPhpBlocks();
        foreach ($blocks as [$document, $code]) {
            foreach ($constantUsesIn($code, ['TIE_WINNER_KEY'], [TieWinnerDeprecation::KEY]) as $use) {
                $uses[] = "{$document}: {$use}";
            }
        }

        expect(count($blocks))->toBeGreaterThan(50)
            ->and($uses)->toBe([]);
    });

    it('finds a use when there is one', function () use ($constantUsesIn): void {
        expect($constantUsesIn('<?php $key = TieDecision::TIE_WINNER_KEY;', ['TIE_WINNER_KEY']))->toBe(['TIE_WINNER_KEY on line 1'])
            ->and($constantUsesIn("<?php\n\$metadata = ['tie_winner' => 'p1'];", ['TIE_WINNER_KEY'], ['tie_winner']))->toBe(["'tie_winner' on line 2"])
            // A declaration, a mention and a method of the name are not uses
            ->and($constantUsesIn('<?php class C { public const TIE_WINNER_KEY = 1; }', ['TIE_WINNER_KEY']))->toBe([])
            ->and($constantUsesIn('<?php // TieDecision::TIE_WINNER_KEY and \'tie_winner\'', ['TIE_WINNER_KEY'], ['tie_winner']))->toBe([])
            ->and($constantUsesIn('<?php $x = C::TIE_WINNER_KEY();', ['TIE_WINNER_KEY']))->toBe([]);
    });
});

describe('the deprecations are documented', function () use ($root): void {
    it('lists every deprecated method, since when and until when, in the usage guide', function () use ($root): void {
        $guide = (string) file_get_contents($root . '/docs/USAGE.md');

        if (preg_match('/^## Deprecations\n(.*?)(?=^## |\z)/ms', $guide, $matches) !== 1) {
            Assert::fail('docs/USAGE.md has no "Deprecations" section.');
        }

        $section = $matches[1];

        foreach (array_unique(DEPRECATED_SINCE) as $since) {
            expect($section)->toContain('deprecated since `' . $since . '`');
        }
        expect($section)->toContain('removed in `1.0.0`');

        // One row of the table for each, and no row for anything else
        preg_match_all('/^\| `([^`]+)` \|/m', $section, $rows);
        $listed = array_map(static fn(string $name): string => 'MissionGaming\\Tactician\\' . $name, $rows[1]);
        sort($listed);

        expect($listed)->toBe(DEPRECATED_SYMBOLS);
    });

    it('names every deprecated method under Deprecated in the changelog of the release', function () use ($root): void {
        $changelog = (string) file_get_contents($root . '/CHANGELOG.md');

        foreach (array_unique(DEPRECATED_SINCE) as $since) {
            // Until the release is cut, the entries are under Unreleased
            $section = null;
            foreach ([$since, 'Unreleased'] as $version) {
                if (preg_match('/^## \[' . preg_quote($version, '/') . '\][^\n]*\n(.*?)(?=^## \[)/ms', $changelog, $matches) === 1) {
                    $section = $matches[1];

                    break;
                }
            }

            if ($section === null || preg_match('/^### Deprecated\n(.*?)(?=^### |\z)/ms', $section, $matches) !== 1) {
                Assert::fail('CHANGELOG.md has no "Deprecated" category for ' . $since . '.');
            }

            foreach (array_keys(DEPRECATED_SINCE, $since, true) as $symbol) {
                $method = substr($symbol, (int) strrpos($symbol, ':') + 1);

                // Written as `Class::method()` or as `method()`
                expect($matches[1])->toMatch('/[`:]' . preg_quote($method, '/') . '`/', "CHANGELOG.md does not name {$method} under Deprecated.");
            }
        }
    });
});

describe('SchedulingContext::getTotalLegs()', function (): void {
    // Why it is deprecated: the answer for a format without legs is made up
    it('answers 1 where the plan says the format has no legs', function (string $format): void {
        $participants = array_map(
            static fn(int $number): Participant => new Participant("p{$number}", "Participant {$number}"),
            range(1, 4)
        );
        $plan = $format === 'swiss' ? new SwissPlan($participants, 3) : new EliminationPlan($participants, $format);
        $context = new SchedulingContext($participants, $plan);

        expect($context->getPlan()->getLegs())->toBeNull()
            ->and(DeprecatedCall::to($context, 'getTotalLegs'))->toBe(1);
    })->with(['swiss', 'single-elimination', 'double-elimination']);
});

describe('the replacement the usage guide gives for a SchedulingException factory', function () use ($root): void {
    // The guide says how to build what each factory built: the sentence as
    // the issue and as the message, the context, the reason, and the
    // round-robin requirements. An application that follows it must get an
    // exception that reads the same everywhere, or the advice is wrong.
    it('builds the exception the factory built', function (string $factory, string $argument, string $sentence, array $context, InvalidConfigurationReason $reason, string $documented) use ($root): void {
        $built = DeprecatedCall::to(SchedulingException::class, $factory, $factory === 'invalidParticipantCount' ? (int) $argument : $argument);
        $replacement = new InvalidConfigurationException(
            $sentence,
            $context,
            $sentence,
            reason: $reason,
            requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
        );

        expect($built)->toBeInstanceOf(InvalidConfigurationException::class);
        assert($built instanceof InvalidConfigurationException);

        expect($replacement->getMessage())->toBe($built->getMessage())
            ->and($replacement->getConfigurationIssue())->toBe($built->getConfigurationIssue())
            ->and($replacement->getContext())->toBe($built->getContext())
            ->and($replacement->getReason())->toBe($built->getReason())
            ->and($replacement->getRequirements())->toBe($built->getRequirements())
            ->and($replacement->getCode())->toBe($built->getCode())
            ->and($replacement->getDiagnosticReport())->toBe($built->getDiagnosticReport());

        // The guide states the sentence and the reason of each factory
        // (line breaks of the prose folded, so that a rewrap changes nothing)
        $guide = (string) preg_replace('/\s+/', ' ', (string) file_get_contents($root . '/docs/USAGE.md'));

        expect($guide)->toContain($documented)
            ->and($guide)->toContain('the reason `' . $reason->name . '`');
    })->with([
        'invalidParticipantCount()' => [
            'invalidParticipantCount',
            '1',
            'Invalid participant count: 1. Must be at least 2.',
            ['participant_count' => 1, 'minimum_required' => 2],
            InvalidConfigurationReason::TooFewParticipants,
            '`"Invalid participant count: {$count}. Must be at least 2."`',
        ],
        'constraintViolation()' => [
            'constraintViolation',
            'no repeats',
            'Constraint violation: no repeats',
            ['constraint' => 'no repeats'],
            InvalidConfigurationReason::ConstraintViolation,
            '`"Constraint violation: {$constraint}"`',
        ],
        'invalidSchedule()' => [
            'invalidSchedule',
            'a round is short',
            'Invalid schedule: a round is short',
            ['reason' => 'a round is short'],
            InvalidConfigurationReason::InvalidSchedule,
            '`"Invalid schedule: {$reason}"`',
        ],
    ]);

    it('differs from the factory in the two ways the guide names when the message and the requirements are left out', function (): void {
        $short = new InvalidConfigurationException(
            'Invalid participant count: 1. Must be at least 2.',
            ['participant_count' => 1, 'minimum_required' => 2],
            reason: InvalidConfigurationReason::TooFewParticipants
        );

        expect($short->getMessage())->toBe('Invalid scheduler configuration: Invalid participant count: 1. Must be at least 2.')
            ->and($short->getRequirements())->toBe([])
            ->and($short->getDiagnosticReport())->not->toContain('REQUIREMENTS');
    });
});

describe('DeprecatedCall', function (): void {
    it('returns what the deprecated method returns and takes the notice of that call only', function (): void {
        $seen = [];
        set_error_handler(
            static function (int $level, string $message) use (&$seen): bool {
                $seen[] = $message;

                return true;
            },
            E_USER_DEPRECATED
        );

        try {
            $exception = DeprecatedCall::to(MissionGaming\Tactician\Exceptions\SchedulingException::class, 'invalidParticipantCount', 1);
            trigger_error('another deprecation of the same test', E_USER_DEPRECATED);
        } finally {
            restore_error_handler();
        }

        // The helper's own handler is gone again: what follows the call is
        // seen by whoever was listening before it.
        expect($exception)->toBeInstanceOf(MissionGaming\Tactician\Exceptions\InvalidConfigurationException::class)
            ->and($seen)->toBe(['another deprecation of the same test']);
    });

    // PHP gives an error the newest handler does not listen to to its own
    // built-in handler, never to the handler before it. A helper that only
    // listened for deprecations would therefore hide a warning raised inside
    // the deprecated method from PHPUnit, in a suite that fails on a warning.
    it('passes on whatever else the deprecated method raises while it runs', function (int $level, string $message): void {
        $seen = [];
        set_error_handler(
            static function (int $seenLevel, string $seenMessage) use (&$seen): bool {
                $seen[] = [$seenLevel, $seenMessage];

                return true;
            }
        );

        try {
            $result = DeprecatedCall::to(new DeprecatedCallProbe(), 'raising', $level, $message);
        } finally {
            restore_error_handler();
        }

        // The notice of the call itself is not among them: the helper took it
        expect($result)->toBe('returned')
            ->and($seen)->toBe([[$level, $message]]);
    })->with([
        'a warning' => [E_USER_WARNING, 'a warning from inside the deprecated method'],
        'a notice' => [E_USER_NOTICE, 'a notice from inside the deprecated method'],
        'a deprecation of something else' => [E_USER_DEPRECATED, 'Method Other::thing() is deprecated since 0.1.0'],
        'a deprecation that only mentions the method' => [E_USER_DEPRECATED, 'see Method ' . DeprecatedCallProbe::class . '::raising() is deprecated since 0.2.2'],
    ]);

    it('gives the handler back when the deprecated method throws', function (): void {
        $seen = [];
        $handler = static function (int $level, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        };
        set_error_handler($handler);

        try {
            expect(fn(): mixed => DeprecatedCall::to(new DeprecatedCallProbe(), 'throwing'))
                ->toThrow(RuntimeException::class, 'thrown by the deprecated method');

            trigger_error('raised after the method threw', E_USER_WARNING);
        } finally {
            $restored = set_error_handler(null);
            restore_error_handler();
            restore_error_handler();
        }

        // The handler in place after the call is the one this test set, and
        // not the helper's: the helper would have handed the warning to this
        // one as well, so the count alone would not tell them apart.
        expect($seen)->toBe(['raised after the method threw'])
            ->and($restored)->toBe($handler);
    });

    it('refuses a method that is not deprecated', function (): void {
        $context = new SchedulingContext([], new SwissPlan([new Participant('a', 'A'), new Participant('b', 'B')], 1));

        expect(fn(): mixed => DeprecatedCall::to($context, 'getPlan'))
            ->toThrow(AssertionFailedError::class, 'getPlan() is not deprecated; call it directly.');
    });
});

// The deprecated metadata key. Before the typed decider (DTO\Decider), who
// advances from a level elimination tie was `tie_winner` metadata on a
// result. The key is still read until 1.0.0. Reading it is no call of a
// deprecated method, so no attribute can say so: the library raises the
// notice itself, with trigger_error(). These tests pin what is raised, when,
// and how often; the tests of the key's behaviour are every use of
// Tests\Support\TieWinnerDeprecation.
describe("the deprecated 'tie_winner' metadata key", function () use ($root): void {
    /**
     * A four-entrant single-leg bracket whose two semifinals and final all
     * finished level, each settled the way asked for.
     *
     * @return array{SingleEliminationEngine, StageState}
     */
    $levelBracket = function (bool $asMetadata): array {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(array_map(
            static fn(int $number): Participant => new Participant("p{$number}", "Participant {$number}"),
            range(1, 4)
        ));

        $record = function (StageState $state) use ($engine, $asMetadata): StageState {
            $pairing = $engine->pairNextRound($state);
            $results = [];
            foreach ($pairing->getEvents() as $event) {
                $advances = $event->getParticipants()[1]->getId();
                $results[] = $asMetadata
                    ? new Result($event, null, [], [TieWinnerDeprecation::KEY => $advances])
                    : new Result($event, null, [], [], null, new Decider([$advances]));
            }

            return $state->withRoundPlayed($pairing, $results);
        };

        if (!$asMetadata) {
            return [$engine, $record($record($state))];
        }

        return [$engine, TieWinnerDeprecation::throughout(fn(): StageState => $record($record($state)))];
    };

    it('is named by a constant that is deprecated with it and still holds it', function (): void {
        // Read by reflection: a use of the constant emits its own notice
        expect(new ReflectionClassConstant(TieDecision::class, 'TIE_WINNER_KEY')->getValue())->toBe('tie_winner')
            ->and(TieWinnerDeprecation::KEY)->toBe('tie_winner');
    });

    it('says what is deprecated, since when, until when, and what to use instead', function (): void {
        expect(TieResolution::TIE_WINNER_DEPRECATION)->toBe(
            "The 'tie_winner' metadata of a result is deprecated since 0.3.0 and is no longer read from 1.0.0:"
            . ' record who advances from a level tie as the decider of the result instead'
            . ' (MissionGaming\Tactician\DTO\Decider, the sixth argument of Result)'
        );
    });

    it('is raised as E_USER_DEPRECATED, on every PHP version, by a call that reads the key', function (): void {
        $alice = new Participant('p1', 'Alice');
        $bob = new Participant('p2', 'Bob');
        $level = new Result(new Event([$alice, $bob], new Round(1)), null, [], [TieWinnerDeprecation::KEY => 'p2']);

        $seen = [];
        set_error_handler(
            static function (int $level, string $message) use (&$seen): bool {
                $seen[] = [$level, $message];

                return true;
            }
        );

        try {
            $advancer = TieDecision::advancer([$level], $alice, $bob, 1);
        } finally {
            restore_error_handler();
        }

        // Not the #[\Deprecated] attribute: the library raises it itself
        expect($advancer)->toBe($bob)
            ->and($seen)->toBe([[E_USER_DEPRECATED, TieResolution::TIE_WINNER_DEPRECATION]]);
    });

    it('is raised once by each call of an engine, however many ties of the bracket the key decided', function () use ($levelBracket): void {
        // Three level ties in the state, and each call replays all of them,
        // some more than once
        [$engine, $state] = $levelBracket(true);

        expect(TieWinnerDeprecation::once(fn(): bool => $engine->isComplete($state)))->toBeTrue()
            ->and(TieWinnerDeprecation::once(fn(): ?StageOutcome => $engine->getOutcome($state)))->not->toBeNull()
            ->and(fn() => TieWinnerDeprecation::once(fn() => $engine->pairNextRound($state)))
            ->toThrow(InvalidConfigurationException::class, 'Bracket is complete')
            // The calls that read a stage in progress walk the same bracket
            ->and(TieWinnerDeprecation::once(fn(): bool => $engine->getReadiness($state)->isComplete()))->toBeTrue()
            ->and(TieWinnerDeprecation::once(fn(): array => $engine->getRecordedRounds($state)))->toHaveCount(2)
            ->and(TieWinnerDeprecation::once(fn(): int => count($engine->getStandings($state)->getEntries())))->toBe(4);
    });

    // The outcome of an engine carries its last tie as the engine settled
    // it, and the selector reads that: the engine read the key, and raised
    // the deprecation, when it built the outcome. The selector reads the key
    // itself, and raises the deprecation, for an outcome that carries only
    // results, as one built by hand does.
    it('is raised once by each call of the double elimination engine, and by the selector where it reads the results itself', function (): void {
        $engine = new DoubleEliminationEngine(new EliminationOptions(legsPerTie: 2));
        $state = StageState::start([new Participant('p1', 'One'), new Participant('p2', 'Two'), new Participant('p3', 'Three')]);

        // Every tie level at a leg each, and the first-named of its first
        // leg advances: no reset is needed
        $rounds = TieWinnerDeprecation::throughout(function () use ($engine, &$state): int {
            $rounds = 0;
            while (!$engine->isComplete($state)) {
                $pairing = $engine->pairNextRound($state);
                $results = [];
                foreach ($pairing->getEvents() as $event) {
                    [$first, $second] = $event->getParticipants();
                    $results[] = new Result(
                        $event,
                        $first,
                        [],
                        $event->getMetadataValue('tie_leg') === 2 ? [TieWinnerDeprecation::KEY => $second->getId()] : []
                    );
                }
                $state = $state->withRoundPlayed($pairing, $results);
                ++$rounds;
            }

            return $rounds;
        });
        expect($rounds)->toBeGreaterThan(2);

        $outcome = TieWinnerDeprecation::once(fn(): ?StageOutcome => $engine->getOutcome($state));
        assert($outcome instanceof StageOutcome);

        $selected = [];
        $raisedForTheEngineOutcome = TieWinnerDeprecation::countIn(function () use ($outcome, &$selected): void {
            $selected = [MatchOutcomeSelector::winners()->select($outcome), MatchOutcomeSelector::losers()->select($outcome)];
        });

        $byHand = new StageOutcome($outcome->getStandings(), $outcome->getResults(), $outcome->getByes(), $outcome->getFinalRound());

        expect($raisedForTheEngineOutcome)->toBe(0)
            ->and($selected[0])->toHaveCount(1)
            ->and($selected[1])->toHaveCount(1)
            ->and($byHand->getSettledTies())->toBeNull()
            ->and(TieWinnerDeprecation::once(fn(): array => MatchOutcomeSelector::winners()->select($byHand)))->toBe($selected[0])
            ->and(TieWinnerDeprecation::once(fn(): array => MatchOutcomeSelector::losers()->select($byHand)))->toBe($selected[1]);
    });

    it('is not raised for a bracket that records the decider', function () use ($levelBracket): void {
        [$engine, $state] = $levelBracket(false);
        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);

        // The suite fails on a deprecation, so the calls above are the
        // test already; the count says it in so many words
        expect(TieWinnerDeprecation::countIn(function () use ($engine, $state, $outcome): void {
            $engine->isComplete($state);
            $engine->getOutcome($state);
            MatchOutcomeSelector::winners()->select($outcome);
        }))->toBe(0);
    });

    it('gives the bracket the decider gives', function () use ($levelBracket): void {
        [$engine, $byDecider] = $levelBracket(false);
        [, $byMetadata] = $levelBracket(true);

        $table = static fn(?StageOutcome $outcome): array => array_map(
            static fn($entry): array => [$entry->getParticipant()->getId(), $entry->getWins(), $entry->getDraws(), $entry->getLosses()],
            array_values($outcome?->getStandings()->getEntries() ?? [])
        );

        expect($table(TieWinnerDeprecation::once(fn(): ?StageOutcome => $engine->getOutcome($byMetadata))))
            ->toBe($table($engine->getOutcome($byDecider)))
            ->and($table($engine->getOutcome($byDecider)))->toBe([['p3', 2, 0, 0], ['p4', 1, 0, 1], ['p1', 0, 0, 1], ['p2', 0, 0, 1]]);
    });

    it('is documented with the constant: the usage guide and the changelog say when the key stops being read', function () use ($root): void {
        $guide = (string) preg_replace('/\s+/', ' ', (string) file_get_contents($root . '/docs/USAGE.md'));
        $changelog = (string) preg_replace('/\s+/', ' ', (string) file_get_contents($root . '/CHANGELOG.md'));

        expect($guide)->toContain('`tie_winner` metadata key')
            ->and($guide)->toContain('is no longer read from `1.0.0`')
            ->and($changelog)->toContain("`'tie_winner'` metadata")
            ->and($changelog)->toContain('is no longer read from `1.0.0`');
    });
});
