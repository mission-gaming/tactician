<?php

declare(strict_types=1);

use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Repack\SessionGrid;

/*
 * Every public symbol of the Repack namespace is documented in
 * docs/USAGE.md: a feature has to be usable without reading the source,
 * and getters that were public and undocumented have shipped here before.
 *
 * The usage guide has a "Repack API Reference" with one `####` heading per
 * public type of src/Repack (the types under Repack\Internal are not
 * public API and are left out). This test compares that reference with
 * the code in both directions: a public type, method, constant, property
 * or enum case the reference does not name fails it, and so does a
 * heading or a method row the code does not have, so the reference cannot
 * keep describing something that was removed or renamed.
 *
 * What it does not check: that the description beside a name is right. It
 * checks only that a method's row has one.
 * The snippets of the section are executed, and the values they state are
 * pinned, by tests/Feature/DocumentationSnippetsTest.php.
 */

const REPACK_REFERENCE_HEADING = '### Repack API Reference';

/**
 * The public types of the Repack namespace, by the PSR-4 rule: one per
 * file directly under src/Repack.
 *
 * @return list<class-string>
 */
function publicRepackTypes(): array
{
    $files = glob(dirname(__DIR__, 2) . '/src/Repack/*.php');
    assert($files !== false);

    $types = [];
    foreach ($files as $file) {
        /** @var class-string $type */
        $type = 'MissionGaming\\Tactician\\Repack\\' . basename($file, '.php');
        $types[] = $type;
    }
    sort($types);

    return $types;
}

/**
 * The reference of a usage guide, as type name => the text under its
 * heading.
 *
 * @return array<string, string>|null Null when the guide has no reference
 */
function repackReferenceBlocks(string $markdown): ?array
{
    $start = strpos($markdown, "\n" . REPACK_REFERENCE_HEADING . "\n");
    if ($start === false) {
        return null;
    }

    $reference = substr($markdown, $start + strlen(REPACK_REFERENCE_HEADING) + 2);
    // The reference ends where the next heading of its own level or above begins
    if (preg_match('/^#{1,3} /m', $reference, $next, PREG_OFFSET_CAPTURE) === 1) {
        $reference = substr($reference, 0, $next[0][1]);
    }

    $parts = preg_split('/^#### `([A-Za-z]+)`\s*$/m', $reference, -1, PREG_SPLIT_DELIM_CAPTURE);
    assert($parts !== false);

    $blocks = [];
    for ($i = 1; $i < count($parts); $i += 2) {
        $blocks[$parts[$i]] = $parts[$i + 1];
    }

    return $blocks;
}

/**
 * What the reference of a usage guide gets wrong about the given types.
 *
 * @param list<class-string> $types
 *
 * @return list<string> One line per problem; empty when the reference and the code agree
 *
 * @throws ReflectionException
 */
function repackReferenceProblems(string $markdown, array $types): array
{
    $blocks = repackReferenceBlocks($markdown);
    if ($blocks === null) {
        return ['The usage guide has no "' . REPACK_REFERENCE_HEADING . '" section.'];
    }

    $problems = [];
    $known = [];

    foreach ($types as $type) {
        $reflection = new ReflectionClass($type);
        $name = $reflection->getShortName();
        $known[$name] = true;

        if (!isset($blocks[$name])) {
            $problems[] = "{$name} has no heading in the reference.";
            continue;
        }
        $block = $blocks[$name];

        $methods = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // An enum's cases(), from() and tryFrom() are PHP's, not the library's
            if ($method->isConstructor() || $method->isInternal() || $method->getDeclaringClass()->getName() !== $type) {
                continue;
            }
            $methods[$method->getName()] = true;
            if (!str_contains($block, "| `{$method->getName()}(")) {
                $problems[] = "{$name}::{$method->getName()}() has no row in the reference.";
            } elseif (preg_match('/^\| `' . preg_quote($method->getName(), '/') . '\(.*` \|\s*\|\s*$/m', $block) === 1) {
                // A row that names the method and says nothing about it
                $problems[] = "{$name}::{$method->getName()}() has a row in the reference with nothing in its second column.";
            }
        }

        preg_match_all('/^\| `([A-Za-z_]+)\(/m', $block, $rows);
        foreach ($rows[1] as $documented) {
            if (!isset($methods[$documented])) {
                $problems[] = "The reference lists {$name}::{$documented}(), which is not a public method of {$name}.";
            }
        }

        foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            $needle = $constant->isEnumCase() ? "`{$constant->getName()}`" : "`{$name}::{$constant->getName()}`";
            if (!str_contains($block, $needle)) {
                $problems[] = "{$name}::{$constant->getName()} is not named in the reference (expected {$needle}).";
            }
        }

        // An enum's $name and $value are PHP's as well
        foreach ($reflection->isEnum() ? [] : $reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (!str_contains($block, "`\${$property->getName()}`")) {
                $problems[] = "{$name}::\${$property->getName()} is not named in the reference.";
            }
        }

        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->isPublic() && !$reflection->isEnum() && !str_contains($block, "`new {$name}(")) {
            $problems[] = "{$name} has a public constructor the reference does not show (expected `new {$name}(...)`).";
        }
    }

    foreach (array_keys($blocks) as $heading) {
        if (!isset($known[$heading])) {
            $problems[] = "The reference has a heading for {$heading}, which is not a public type of the Repack namespace.";
        }
    }

    return $problems;
}

describe('The Repack API reference in docs/USAGE.md', function (): void {
    $usage = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/USAGE.md');

    it('finds the public types of the namespace', function (): void {
        $types = publicRepackTypes();

        // Guards the guard: a glob that matched nothing would pass everything
        expect(count($types))->toBeGreaterThanOrEqual(17);
        expect($types)->toContain(SessionGrid::class, RepackOutcome::class);
        foreach ($types as $type) {
            expect(class_exists($type) || interface_exists($type) || enum_exists($type))->toBeTrue("{$type} does not exist.");
            expect($type)->not->toContain('Internal');
        }
    });

    it('names every public type, method, constant, property and enum case, and nothing that does not exist', function () use ($usage): void {
        expect(repackReferenceProblems($usage, publicRepackTypes()))->toBe([]);
    });

    it('names every public type in the prose of the section as well', function () use ($usage): void {
        $start = (int) strpos($usage, "\n## Schedule Repacking\n");
        $prose = substr($usage, $start, (int) strpos($usage, "\n" . REPACK_REFERENCE_HEADING . "\n") - $start);

        foreach (publicRepackTypes() as $type) {
            $name = (new ReflectionClass($type))->getShortName();
            expect(str_contains($prose, $name))->toBeTrue("{$name} is in the reference and nowhere in the section's prose.");
        }
    });

    // The red routes: the same check, run over the guide with one thing taken out or put in
    it('reports what a doctored guide gets wrong', function (string $search, string $replace, string ...$problems) use ($usage): void {
        expect(substr_count($usage, $search))->toBe(1, "The guide no longer contains `{$search}` exactly once, so this case proves nothing.");

        expect(repackReferenceProblems(str_replace($search, $replace, $usage), publicRepackTypes()))->toBe(array_values($problems));
    })->with([
        'a method row removed' => [
            "| `fingerprint()` | The outcome's fingerprint |\n",
            '',
            'RepackOutcome::fingerprint() has no row in the reference.',
        ],
        'a getter of a violation removed' => [
            "| `getGapSlots()` | How many slots between the participant's first and last are empty |\n",
            '',
            'ContiguityBroken::getGapSlots() has no row in the reference.',
        ],
        'a method row with its description taken out' => [
            "| `fingerprint()` | The outcome's fingerprint |\n",
            "| `fingerprint()` | |\n",
            'RepackOutcome::fingerprint() has a row in the reference with nothing in its second column.',
        ],
        'a static method row removed' => [
            '| `shapeOnly(int $sessions,',
            '| `shapeless(int $sessions,',
            'SessionGrid::shapeOnly() has no row in the reference.',
            // Both directions fire: the method is missing and the row names nothing
            'The reference lists SessionGrid::shapeless(), which is not a public method of SessionGrid.',
        ],
        'a type heading removed' => [
            "#### `UnplacedEvent`\n",
            "Unplaced events:\n",
            // Its rows now sit under the heading before it, where they name nothing
            'The reference lists SlotAssignment::getReason(), which is not a public method of SlotAssignment.',
            'The reference lists SlotAssignment::getParticipant(), which is not a public method of SlotAssignment.',
            'UnplacedEvent has no heading in the reference.',
        ],
        'a row for a method that does not exist' => [
            "| `isClean()` | Whether nothing is unplaced and there is no violation |\n",
            "| `isClean()` | Whether nothing is unplaced and there is no violation |\n| `isPerfect()` | Never |\n",
            'The reference lists RepackOutcome::isPerfect(), which is not a public method of RepackOutcome.',
        ],
        'a heading for a type that does not exist' => [
            "#### `LateStart`\n",
            "#### `EarlyFinish`\n\nNothing.\n\n#### `LateStart`\n",
            'The reference has a heading for EarlyFinish, which is not a public type of the Repack namespace.',
        ],
        'an enum case removed' => [
            'Cases: `ParticipantOverCapacity` (`participant_over_capacity`)',
            'Cases: one (`participant_over_capacity`)',
            'UnplacedReason::ParticipantOverCapacity is not named in the reference (expected `ParticipantOverCapacity`).',
        ],
        'a constant removed' => [
            'The constant `RepackOutcome::FINGERPRINT_SCHEME` is the scheme',
            'A constant is the scheme',
            'RepackOutcome::FINGERPRINT_SCHEME is not named in the reference (expected `RepackOutcome::FINGERPRINT_SCHEME`).',
        ],
        'a public property removed' => [
            '`$earlyFillWeight`, `$stepBudget` and `$throwOnViolations`',
            '`$earlyFillWeight` and `$throwOnViolations`',
            'RepackOptions::$stepBudget is not named in the reference.',
        ],
        'a constructor removed' => [
            '`new MovableEvent(string $id,',
            '`MovableEvent(string $id,',
            'MovableEvent has a public constructor the reference does not show (expected `new MovableEvent(...)`).',
        ],
    ]);

    it('reports a guide with no reference at all', function () use ($usage): void {
        expect(repackReferenceProblems(str_replace(REPACK_REFERENCE_HEADING, '### Something Else', $usage), publicRepackTypes()))
            ->toBe(['The usage guide has no "### Repack API Reference" section.']);
    });
});
