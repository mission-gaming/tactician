<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use LogicException;
use PhpToken;

/**
 * The stability policy of the README's "Versioning and stability" section,
 * read from the README, and the stability annotation it asks of a type in
 * `src/`.
 *
 * The README is the one place that says which namespaces are stable and
 * which are experimental. This class reads its two lists and answers, for a
 * type named relative to `MissionGaming\Tactician`, which annotation the
 * policy expects ({@see self::expectedFor()}):
 *
 * - a name the "Stable" list excludes (`Repack\Internal`) is `@internal`;
 * - otherwise the most specific entry that covers the name decides: an
 *   entry of the "Stable" list gives `@api`, one of the "Experimental" list
 *   gives `@experimental`. `Scheduling\RoundRobinScheduler` is therefore
 *   `@api`, although nothing else in `Scheduling` is;
 * - a name no entry covers, in a namespace some entry names a class of, is
 *   `@experimental`: the README says that anything not listed as stable is
 *   experimental;
 * - a name in a namespace the README does not mention has no answer. The
 *   namespace is unclassified, and the README needs an entry for it.
 *
 * A type the policy makes `@experimental` may be `@internal` instead: an
 * experimental type carries no promise that marking it internal would break.
 * A type the policy makes `@api` may not. The README promises that a stable
 * type does not change, so it leaves the public surface only by an exclusion
 * written into the "Stable" list, as `Repack\Internal` is.
 *
 * {@see self::declaredIn()} reads the types a source file declares and the
 * stability tags of each one's docblock with PHP's tokenizer, so a tag in a
 * method's docblock, in a string or in an inline `{@internal ...}` note is
 * not a class annotation.
 */
final readonly class StabilityPolicy
{
    public const string API = 'api';

    public const string EXPERIMENTAL = 'experimental';

    public const string INTERNAL = 'internal';

    /** The three stability annotations; a type carries exactly one. */
    public const array TAGS = [self::API, self::EXPERIMENTAL, self::INTERNAL];

    private function __construct(private string $section) {}

    /**
     * @throws LogicException When the README has no "Versioning and stability" section
     */
    public static function fromReadme(string $readme): self
    {
        if (preg_match('/^## Versioning and stability\n(.*?)(?=^## )/ms', $readme, $matches) !== 1) {
            throw new LogicException('README.md has no "Versioning and stability" section.');
        }

        return new self($matches[1]);
    }

    /**
     * The section's text, heading excluded.
     */
    public function section(): string
    {
        return $this->section;
    }

    /**
     * The backticked names in the list that follows a bold label, e.g. the
     * items under "**Stable**". Only the list is read: prose after it is not
     * a classification. A name the list excludes is among them.
     *
     * @return list<string>
     *
     * @throws LogicException When the section has no such list
     */
    public function classified(string $label): array
    {
        $names = [];

        foreach ($this->items($label) as $item) {
            preg_match_all('/`([^`]+)`/', $item, $matches);
            $names = [...$names, ...$matches[1]];
        }

        return $names;
    }

    /**
     * The names a list takes back out of one of its own entries: what
     * follows the word "excluding" in an item.
     *
     * @return list<string>
     *
     * @throws LogicException When the section has no such list
     */
    public function excluded(string $label): array
    {
        $names = [];

        foreach ($this->items($label) as $item) {
            $position = strpos($item, 'excluding');

            if ($position === false) {
                continue;
            }

            preg_match_all('/`([^`]+)`/', substr($item, $position), $matches);
            $names = [...$names, ...$matches[1]];
        }

        return $names;
    }

    /**
     * The annotation the policy expects of a type, or null when the type's
     * namespace is not classified.
     *
     * @param string $name The type's name relative to `MissionGaming\Tactician`
     *
     * @return self::API|self::EXPERIMENTAL|self::INTERNAL|null
     *
     * @throws LogicException When the section lacks one of the two lists
     */
    public function expectedFor(string $name): ?string
    {
        $excluded = $this->excluded('Stable');

        foreach ($excluded as $entry) {
            if (self::covers($entry, $name)) {
                return self::INTERNAL;
            }
        }

        $entries = [];
        foreach (array_diff($this->classified('Stable'), $excluded) as $entry) {
            $entries[$entry] = self::API;
        }
        foreach ($this->classified('Experimental') as $entry) {
            $entries[$entry] = self::EXPERIMENTAL;
        }

        $expected = null;
        $specificity = -1;
        foreach ($entries as $entry => $tag) {
            if (self::covers($entry, $name) && strlen($entry) > $specificity) {
                $expected = $tag;
                $specificity = strlen($entry);
            }
        }

        if ($expected !== null) {
            return $expected;
        }

        $namespace = explode('\\', $name)[0];
        foreach (array_keys($entries) as $entry) {
            if (explode('\\', $entry)[0] === $namespace) {
                return self::EXPERIMENTAL;
            }
        }

        return null;
    }

    /**
     * What is wrong with the stability annotations of one type; an empty
     * list when nothing is.
     *
     * @param string $name The type's name relative to `MissionGaming\Tactician`
     * @param list<string> $tags The stability tags of its docblock, as {@see self::declaredIn()} reports them
     *
     * @return list<string>
     *
     * @throws LogicException When the section lacks one of the two lists
     */
    public function problemsWith(string $name, array $tags): array
    {
        if ($tags === []) {
            return ["{$name} has no stability annotation: its docblock needs one of @api, @experimental or @internal."];
        }

        if (count($tags) > 1) {
            return ["{$name} has " . count($tags) . ' stability annotations (@' . implode(', @', $tags) . '); it needs exactly one.'];
        }

        $expected = $this->expectedFor($name);

        if ($expected === null) {
            return ["{$name} is in a namespace that README.md lists as neither stable nor experimental."];
        }

        if ($tags[0] === $expected) {
            return [];
        }

        if ($tags[0] !== self::INTERNAL) {
            return ["{$name} is @{$tags[0]}, but README.md makes it @{$expected}."];
        }

        if ($expected === self::API) {
            return ["{$name} is @internal, but README.md lists it as stable: a stable type leaves the public surface only when the \"Stable\" list excludes it."];
        }

        return [];
    }

    /**
     * The classes, interfaces, traits and enums a source file declares, each
     * with the stability tags of its own docblock in the order written. An
     * anonymous class is not a declared type.
     *
     * @return list<array{name: string, tags: list<string>}> Names are fully qualified
     */
    public static function declaredIn(string $php): array
    {
        $tokens = PhpToken::tokenize($php);
        $count = count($tokens);
        $namespace = '';
        $docblock = null;
        $declared = [];

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];

            if ($token->id === T_DOC_COMMENT) {
                $docblock = $token->text;

                continue;
            }

            // Between a docblock and the type it documents: blank space,
            // comments, the modifiers and attributes.
            if ($token->is([T_WHITESPACE, T_COMMENT, T_FINAL, T_ABSTRACT, T_READONLY])) {
                continue;
            }

            if ($token->id === T_ATTRIBUTE) {
                $index = self::endOfAttribute($tokens, $index);

                continue;
            }

            if ($token->id === T_NAMESPACE) {
                $next = self::nextMeaningful($tokens, $index);
                $namespace = $next !== null && $tokens[$next]->is([T_NAME_QUALIFIED, T_STRING]) ? $tokens[$next]->text . '\\' : '';
            }

            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                $next = self::nextMeaningful($tokens, $index);

                // `Name::class` and `new class` have no name after the keyword
                if ($next !== null && $tokens[$next]->id === T_STRING && ($tokens[$index - 1] ?? null)?->id !== T_DOUBLE_COLON) {
                    $declared[] = [
                        'name' => $namespace . $tokens[$next]->text,
                        'tags' => self::tagsOf($docblock ?? ''),
                    ];
                }
            }

            $docblock = null;
        }

        return $declared;
    }

    /**
     * The stability tags of a docblock: the ones that open a line of it.
     *
     * @return list<string>
     */
    private static function tagsOf(string $docblock): array
    {
        // Not followed by a character that would make it another tag
        // (`@apiVersion`, `@internal-note`, `@api\Something`)
        preg_match_all('/^[\s\/*]*@(' . implode('|', self::TAGS) . ')(?![\w\\\\-])/m', $docblock, $matches);

        return $matches[1];
    }

    /**
     * @param array<PhpToken> $tokens
     */
    private static function nextMeaningful(array $tokens, int $index): ?int
    {
        for ($next = $index + 1; $next < count($tokens); ++$next) {
            if (!$tokens[$next]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $next;
            }
        }

        return null;
    }

    /**
     * The index of the bracket that closes the attribute group opened at
     * the given index.
     *
     * @param array<PhpToken> $tokens
     */
    private static function endOfAttribute(array $tokens, int $index): int
    {
        $depth = 1;

        for ($end = $index + 1; $end < count($tokens); ++$end) {
            if ($tokens[$end]->text === '[') {
                ++$depth;
            } elseif ($tokens[$end]->text === ']' && --$depth === 0) {
                return $end;
            }
        }

        return count($tokens) - 1;
    }

    private static function covers(string $entry, string $name): bool
    {
        return $name === $entry || str_starts_with($name, $entry . '\\');
    }

    /**
     * The items of the list under a bold label, each with its continuation
     * lines joined to it.
     *
     * @return list<string>
     *
     * @throws LogicException When the section has no such list
     */
    private function items(string $label): array
    {
        $start = strpos($this->section, "**{$label}**");

        if ($start === false) {
            throw new LogicException("The stability section has no \"{$label}\" list.");
        }

        $items = [];

        foreach (explode("\n", substr($this->section, $start)) as $line) {
            $isItem = str_starts_with($line, '- ');

            if ($items === [] && !$isItem) {
                continue;
            }

            if ($isItem) {
                $items[] = $line;

                continue;
            }

            if (!str_starts_with($line, '  ')) {
                break;
            }

            $items[count($items) - 1] .= ' ' . trim($line);
        }

        return $items;
    }
}
