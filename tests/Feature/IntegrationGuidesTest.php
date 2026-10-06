<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

/*
 * The guides in docs/integrations/ are not run by the snippet harness
 * (tests/Feature/DocumentationSnippetsTest.php): they hold framework code,
 * which needs a framework to run. A guide nothing checks goes stale, and
 * these did: they described an integration no application had, and said
 * every snippet in them was executed.
 *
 * So a guide may hold two kinds of `php` block, and says which each one is
 * in an HTML comment on the line before it:
 *
 *     <!-- excerpt: examples/23-application-adapter-and-repack.php -->
 *     <!-- schematic: framework code, not executed -->
 *
 * An excerpt is a run of consecutive lines of a script in examples/, with
 * the indentation they share removed. The examples are executed, asserted
 * on and pinned (tests/Feature/ExamplesTest.php), so an excerpt is library
 * usage that has run. This file fails when an excerpt no longer matches its
 * script, which is what keeps the guide from drifting.
 *
 * A schematic block is framework glue and is not executed by anything. It
 * is checked for what can be checked without the framework: it parses, and
 * every library class it imports exists.
 *
 * The prose is checked as well: a class, a method, a constructor argument
 * or an enum case named in backticks must exist in the library, and a link
 * to another document must lead to a file and a heading that exist.
 *
 * Not covered, and the reason: whether a schematic block does what its
 * guide says. That needs Symfony, Doctrine or Laravel installed, and the
 * library has no dependency on any of them.
 */

$integrationGuideFiles = glob(dirname(__DIR__, 2) . '/docs/integrations/*.md');
$integrationGuides = array_map(basename(...), $integrationGuideFiles === false ? [] : $integrationGuideFiles);

/**
 * The text of a guide.
 *
 * @throws PHPUnit\Framework\AssertionFailedError When the guide cannot be read
 */
function integrationGuide(string $guide): string
{
    $text = file_get_contents(dirname(__DIR__, 2) . '/docs/integrations/' . $guide);
    if ($text === false) {
        Assert::fail("docs/integrations/{$guide} cannot be read.");
    }

    return $text;
}

/**
 * The `php` blocks of a guide, each with the kind and the subject of the
 * marker on the line before it (both empty when there is no marker) and the
 * line its fence is on.
 *
 * @return list<array{kind: string, subject: string, code: string, line: int}>
 */
function integrationGuideBlocks(string $markdown): array
{
    $lines = explode("\n", $markdown);
    $blocks = [];

    for ($index = 0; $index < count($lines); ++$index) {
        if ($lines[$index] !== '```php') {
            continue;
        }

        $marker = [];
        preg_match('/^<!-- (excerpt|schematic): (.+) -->$/', $lines[$index - 1] ?? '', $marker);

        $code = [];
        $inner = $index + 1;
        while ($inner < count($lines) && $lines[$inner] !== '```') {
            $code[] = $lines[$inner];
            ++$inner;
        }

        $blocks[] = [
            'kind' => $marker[1] ?? '',
            'subject' => $marker[2] ?? '',
            'code' => implode("\n", $code),
            'line' => $index + 1,
        ];
        $index = $inner;
    }

    return $blocks;
}

/**
 * A guide without its fenced blocks: the prose, where symbols are named in
 * backticks.
 */
function integrationGuideProse(string $markdown): string
{
    return (string) preg_replace('/^```.*?^```$/ms', '', $markdown);
}

/**
 * Lines with the indentation they all share removed. Blank lines do not
 * count towards it.
 *
 * @param list<string> $lines
 * @return list<string>
 */
function integrationGuideDedent(array $lines): array
{
    $indents = [];
    foreach ($lines as $line) {
        if (trim($line) !== '') {
            $indents[] = strlen($line) - strlen(ltrim($line, ' '));
        }
    }
    $shared = $indents === [] ? 0 : min($indents);

    return array_map(static fn(string $line): string => trim($line) === '' ? '' : substr($line, $shared), $lines);
}

/**
 * Every class, interface, enum and trait of the library, by short name.
 *
 * @return array<string, class-string>
 *
 * @throws UnexpectedValueException When src/ cannot be read
 */
function integrationGuideLibraryTypes(): array
{
    $source = dirname(__DIR__, 2) . '/src';
    $types = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($source) + 1, -4);
        $type = 'MissionGaming\\Tactician\\' . str_replace('/', '\\', $relative);
        if (class_exists($type) || interface_exists($type) || enum_exists($type) || trait_exists($type)) {
            $types[$file->getBasename('.php')] = $type;
        }
    }

    return $types;
}

/**
 * Whether the library has a public method of this name, on any type.
 *
 * @param array<string, class-string> $types
 *
 * @throws ReflectionException
 */
function integrationGuideHasMethod(array $types, string $method): bool
{
    foreach ($types as $type) {
        if (method_exists($type, $method) && (new ReflectionMethod($type, $method))->isPublic()) {
            return true;
        }
    }

    return false;
}

/**
 * Whether one of the library's enums has a case of this name.
 *
 * @param array<string, class-string> $types
 */
function integrationGuideHasEnumCase(array $types, string $case): bool
{
    foreach ($types as $type) {
        if (enum_exists($type) && defined($type . '::' . $case)) {
            return true;
        }
    }

    return false;
}

/**
 * What is wrong with one backticked piece of prose, or null when it names
 * nothing the library lacks. Text that is not a symbol (a string, a key of
 * plain data, a PHP expression on a variable) is not judged.
 *
 * @param array<string, class-string> $types
 *
 * @throws ReflectionException
 */
function integrationGuideSymbolProblem(array $types, string $code): ?string
{
    // PHP's own, a framework's, or a class of the standard library under its short name
    $notTheLibrarys = ['Randomizer' => Random\Randomizer::class, 'config' => null];

    // A namespaced name: \LogicException, Random\Randomizer, Exceptions\TacticianException
    if (preg_match('/^\\\\?[A-Za-z]+(\\\\[A-Za-z]+)*$/', $code) === 1 && str_contains($code, '\\')) {
        $name = ltrim($code, '\\');
        foreach ([$name, 'MissionGaming\\Tactician\\' . $name] as $candidate) {
            if (class_exists($candidate) || interface_exists($candidate) || enum_exists($candidate)) {
                return null;
            }
        }

        return "names the type {$code}, which does not exist";
    }

    // A bare name in capitals: a type of the library, a case of one of its enums, or a PHP class
    if (preg_match('/^[A-Z][A-Za-z]+$/', $code) === 1) {
        $known = isset($types[$code])
            || integrationGuideHasEnumCase($types, $code)
            || class_exists($code)
            || interface_exists($code)
            || isset($notTheLibrarys[$code]);

        return $known ? null : "names {$code}, which is not a type or an enum case of the library";
    }

    // Type::member, with or without a call: SessionGrid::shapeOnly(), TieDecision::TIE_WINNER_KEY
    if (preg_match('/^([A-Z][A-Za-z]+)::(\$?[A-Za-z_]+)(\(.*\))?$/', $code, $member) === 1) {
        $type = $types[$member[1]] ?? null;
        if ($type === null) {
            return "names a member of {$member[1]}, which is not a type of the library";
        }

        $exists = ($member[3] ?? '') !== ''
            ? method_exists($type, $member[2])
            : defined($type . '::' . $member[2]) || method_exists($type, $member[2]);

        return $exists ? null : "names {$member[1]}::{$member[2]}, which {$member[1]} does not have";
    }

    // A construction with named arguments: RepackOptions(stepBudget: ...), new RoundPairing(...)
    if (preg_match('/^(?:new )?([A-Z][A-Za-z]+)\((.*)\)$/', $code, $construction) === 1) {
        $type = $types[$construction[1]] ?? null;
        if ($type === null) {
            return "constructs {$construction[1]}, which is not a type of the library";
        }

        $parameters = array_map(
            static fn(ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionClass($type))->getConstructor()?->getParameters() ?? []
        );
        preg_match_all('/(?<![\w:$])([a-z][A-Za-z]*):(?!:)/', $construction[2], $named);
        foreach ($named[1] as $argument) {
            if (!in_array($argument, $parameters, true)) {
                return "gives {$construction[1]} the argument {$argument}, which its constructor does not have";
            }
        }

        return null;
    }

    // One call or a chain of calls on no stated object: fromArray(), getAnalysis()?->getImpossiblePairings()
    if (preg_match('/^[a-z][A-Za-z]*\(.*\)(\??->[a-z][A-Za-z]*\(.*\))*$/', $code) === 1) {
        preg_match_all('/(?<![\w$])([a-z][A-Za-z]*)\(/', $code, $calls);
        foreach ($calls[1] as $method) {
            if (!array_key_exists($method, $notTheLibrarys) && !integrationGuideHasMethod($types, $method)) {
                return "names the method {$method}(), which no type of the library has";
            }
        }
    }

    return null;
}

/**
 * The anchor GitHub gives a heading: lower case, punctuation dropped,
 * spaces as hyphens.
 */
function integrationGuideAnchor(string $heading): string
{
    $text = strtolower(trim($heading));
    $text = (string) preg_replace('/[^a-z0-9 _-]/', '', $text);

    return str_replace(' ', '-', $text);
}

it('finds the integration guides', function () use ($integrationGuides): void {
    expect($integrationGuides)->toContain('symfony.md', 'laravel.md');
});

it('says of every php block whether it is an excerpt of an example or schematic', function (string $guide): void {
    $blocks = integrationGuideBlocks(integrationGuide($guide));

    expect($blocks)->not->toBe([]);
    foreach ($blocks as $block) {
        expect($block['kind'])->toBeIn(
            ['excerpt', 'schematic'],
            "The php block at line {$block['line']} of docs/integrations/{$guide} has no marker on the line before it. "
            . 'Write `<!-- excerpt: examples/NN-name.php -->` for lines copied from a runnable example, or '
            . '`<!-- schematic: framework code, not executed -->` for framework glue.'
        );
    }
})->with($integrationGuides);

// The reason the guide can be trusted: its library usage is the examples'
it('quotes the examples exactly', function (string $guide): void {
    $root = dirname(__DIR__, 2);
    $blocks = integrationGuideBlocks(integrationGuide($guide));
    $compared = 0;

    foreach ($blocks as $block) {
        if ($block['kind'] !== 'excerpt') {
            continue;
        }
        ++$compared;

        expect($block['subject'])->toMatch('/^examples\/\d\d-[a-z\d-]+\.php$/');
        $script = file_get_contents($root . '/' . $block['subject']);
        if ($script === false) {
            Assert::fail("docs/integrations/{$guide} quotes {$block['subject']}, which does not exist.");
        }

        $quoted = integrationGuideDedent(explode("\n", $block['code']));
        $source = explode("\n", $script);

        $found = false;
        for ($start = 0; $start + count($quoted) <= count($source); ++$start) {
            if (integrationGuideDedent(array_slice($source, $start, count($quoted))) === $quoted) {
                $found = true;

                break;
            }
        }

        expect($found)->toBeTrue(
            "The excerpt at line {$block['line']} of docs/integrations/{$guide} is not a run of consecutive lines of "
            . "{$block['subject']}. The example was edited, or the excerpt was: copy the lines again."
        );
        expect(count($quoted))->toBeGreaterThan(2);
    }

    // A guide with no excerpt (one that only refers to another) compares none
    expect($compared)->toBe(count(array_keys(array_column($blocks, 'kind'), 'excerpt', true)));
})->with($integrationGuides);

it('holds at least one excerpt in the guide the others refer to', function (): void {
    $kinds = array_column(integrationGuideBlocks(integrationGuide('symfony.md')), 'kind');

    expect(array_count_values($kinds)['excerpt'] ?? 0)->toBeGreaterThanOrEqual(8)
        ->and(array_count_values($kinds)['schematic'] ?? 0)->toBeGreaterThanOrEqual(1);
});

it('holds only blocks that parse, and imports only library classes that exist', function (string $guide): void {
    foreach (integrationGuideBlocks(integrationGuide($guide)) as $block) {
        try {
            $tokens = token_get_all("<?php\n" . $block['code'], TOKEN_PARSE);
        } catch (ParseError $error) {
            Assert::fail("The php block at line {$block['line']} of docs/integrations/{$guide} does not parse: {$error->getMessage()}");
        }
        expect(count($tokens))->toBeGreaterThan(1);

        preg_match_all('/^use (MissionGaming\\\\Tactician\\\\[A-Za-z\\\\]+);$/m', $block['code'], $imports);
        foreach ($imports[1] as $import) {
            expect(class_exists($import) || interface_exists($import) || enum_exists($import))->toBeTrue(
                "The php block at line {$block['line']} of docs/integrations/{$guide} imports {$import}, which does not exist."
            );
        }
    }
})->with($integrationGuides);

it('names in its prose only what the library has', function (string $guide): void {
    $types = integrationGuideLibraryTypes();
    preg_match_all('/`([^`\n]+)`/', integrationGuideProse(integrationGuide($guide)), $spans);

    expect($types)->toHaveKey('SessionGrid')
        ->and($spans[1])->not->toBe([]);

    $problems = [];
    foreach (array_unique($spans[1]) as $code) {
        $problem = integrationGuideSymbolProblem($types, $code);
        if ($problem !== null) {
            $problems[] = "`{$code}` {$problem}";
        }
    }

    expect($problems)->toBe([], "docs/integrations/{$guide}: " . implode('; ', $problems));
})->with($integrationGuides);

// The check above is only worth what it catches
it('catches a symbol the library does not have, and leaves alone what is not a symbol', function (string $code, ?string $expected): void {
    $problem = integrationGuideSymbolProblem(integrationGuideLibraryTypes(), $code);

    if ($expected === null) {
        expect($problem)->toBeNull();

        return;
    }

    expect($problem)->toBeString()->toContain($expected);
})->with([
    'a type of the library' => ['RepackOutcome', null],
    'an enum case' => ['EngineFingerprintMismatch', null],
    'a PHP class' => ['DateTimeImmutable', null],
    'a namespaced PHP class' => ['\LogicException', null],
    'a type under its sub-namespace' => ['Exceptions\TacticianException', null],
    'a method of a type' => ['SessionGrid::shapeOnly()', null],
    'a constant of a type' => ['TieDecision::TIE_WINNER_KEY', null],
    'a bare method' => ['withResultReplaced()', null],
    'a chain of methods' => ['getAnalysis()?->getImpossiblePairings()', null],
    'a construction with a named argument' => ['RepackOptions(stepBudget: ...)', null],
    'a string' => ["'unbounded'", null],
    'a key of plain data' => ['tie_leg', null],
    'an expression on a variable' => ['$kickoff->setTimezone(new DateTimeZone(\'Europe/London\'))', null],
    'a type that does not exist' => ['RepackResult', 'not a type or an enum case'],
    'a namespaced type that does not exist' => ['Exceptions\TacticalException', 'does not exist'],
    'a method the type does not have' => ['SessionGrid::shapeless()', 'does not have'],
    'a constant the type does not have' => ['TieDecision::WINNER', 'does not have'],
    'a member of a type that does not exist' => ['Grid::shapeOnly()', 'not a type of the library'],
    'a bare method nothing has' => ['withResultSwapped()', 'no type of the library has'],
    'a method nothing has, late in a chain' => ['getAnalysis()?->getBlockedPairings()', 'no type of the library has'],
    'a constructor argument that does not exist' => ['RepackOptions(budget: 5)', 'its constructor does not have'],
]);

it('links only to files and headings that exist', function (string $guide): void {
    $directory = dirname(__DIR__, 2) . '/docs/integrations';
    $markdown = integrationGuide($guide);
    preg_match_all('/\]\((?!https?:)([^)#\s]*)(?:#([^)\s]+))?\)/', integrationGuideProse($markdown), $links, PREG_SET_ORDER);

    expect($links)->not->toBe([]);
    foreach ($links as $link) {
        $path = $link[1] === '' ? "{$directory}/{$guide}" : "{$directory}/{$link[1]}";
        expect(is_file($path))->toBeTrue("docs/integrations/{$guide} links to {$link[1]}, which does not exist.");

        $anchor = $link[2] ?? '';
        if ($anchor === '') {
            continue;
        }

        preg_match_all('/^#{1,6} (.+)$/m', (string) file_get_contents($path), $headings);
        expect(in_array($anchor, array_map(integrationGuideAnchor(...), $headings[1]), true))->toBeTrue(
            "docs/integrations/{$guide} links to {$link[1]}#{$anchor}, and that document has no such heading."
        );
    }
})->with($integrationGuides);

// What the guides said before, which was false: nothing executes their
// framework code. The phrase may not come back.
it('does not claim that its framework code is executed', function (string $guide): void {
    $prose = (string) preg_replace('/\s+/', ' ', integrationGuideProse(integrationGuide($guide)));

    expect($prose)->not->toContain('executed by the test suite\'s snippet harness')
        ->and($prose)->not->toContain('harness-executed');
})->with($integrationGuides);
