<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Serialization;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;

/**
 * What every `fromArray()` checks before it reads a value, and the one key
 * every `toArray()` writes first.
 *
 * Two things are checked, in this order.
 *
 * The format version. Every array a `toArray()` returns says, under
 * {@see self::VERSION_KEY}, which version of its form it is written in. An
 * array without the key is read as version 1, which is the form the releases
 * before the key existed wrote, so data stored by them loads. A version the
 * reader does not know is refused: it was written by a later release, and
 * reading it by the rules of this one could only be a guess.
 * ADR 0005 (`docs/adr/0005-serialized-forms-carry-a-format-version.md`)
 * has the whole rule, and what changing a form takes.
 *
 * The keys. A key that is not known is a mistake the reader cannot see
 * past: a misspelt option would be read as its default, and a misspelt
 * field of stored data as the field left out (a result whose `winer` is
 * ignored is a draw). Both are refused, with the keys that are not known
 * and the ones that are.
 *
 * The forms are of two kinds, and each reports a fault with the exception
 * it reports every other fault of its array with: configuration (options, a
 * timeline, a grid, a rule, a selector) with an
 * {@see InvalidConfigurationException} that carries a reason, and data (a
 * schedule, a stage state and their parts) with an
 * {@see InvalidInputException}.
 *
 * @internal Not public API: the checks behind the `fromArray()` methods. It
 *           carries no compatibility guarantee.
 */
final class PlainData
{
    /**
     * The key that holds the format version: the first key of every array a
     * `toArray()` returns.
     */
    public const string VERSION_KEY = 'format_version';

    /**
     * The version every form is written in today. The forms are versioned
     * one by one: a form that changes takes the next number for itself and
     * passes it to the check below as the newest version it reads.
     */
    public const int VERSION = 1;

    /**
     * Check configuration before it is read: its format version, then its
     * keys.
     *
     * @param array<array-key, mixed> $config The array given to `fromArray()`
     * @param list<string> $known Every key the form reads, the version key excluded
     * @param string $form What the array configures, as a noun phrase: 'round-robin options'
     * @param list<string> $requirements The requirements block of the form's errors, when it has one
     * @param int $newest The newest version of the form this release reads; it reads every
     *                    version from 1 up to it
     *
     * @return int The format version of the array: the one it states, or 1 when it states none
     *
     * @throws InvalidConfigurationException When the version is not one this release reads (reason
     *                                       `UnsupportedFormatVersion`), or a key is not known
     *                                       (`UnknownOptionKey`; the context lists the keys under
     *                                       `unknown` and `known`)
     */
    public static function checkOptions(
        array $config,
        array $known,
        string $form,
        array $requirements = [],
        int $newest = self::VERSION
    ): int {
        if (!self::readsVersion($config, $newest)) {
            throw new InvalidConfigurationException(
                self::versionFault($config[self::VERSION_KEY] ?? null, $form, $newest),
                [self::VERSION_KEY => $config[self::VERSION_KEY] ?? null, 'newest_supported' => $newest],
                reason: InvalidConfigurationReason::UnsupportedFormatVersion,
                requirements: $requirements
            );
        }

        $known = [self::VERSION_KEY, ...$known];
        $unknown = self::unknownKeys($config, $known);
        if ($unknown !== []) {
            throw new InvalidConfigurationException(
                sprintf('Unknown key in %s: %s', $form, self::quoted($unknown)),
                ['unknown' => $unknown, 'known' => $known],
                reason: InvalidConfigurationReason::UnknownOptionKey,
                requirements: $requirements
            );
        }

        return self::versionOf($config);
    }

    /**
     * Check data before it is read: its format version, then its keys.
     *
     * @param array<array-key, mixed> $data The array given to `fromArray()`
     * @param list<string> $known Every key the form reads, the version key excluded
     * @param string $form What the array is, as a noun phrase: 'schedule data'
     * @param int $newest The newest version of the form this release reads; it reads every
     *                    version from 1 up to it
     *
     * @return int The format version of the array: the one it states, or 1 when it states none
     *
     * @throws InvalidInputException When the version is not one this release reads, or a key is
     *                               not known; the message names the keys that are not known
     *                               and the ones that are
     */
    public static function checkFields(array $data, array $known, string $form, int $newest = self::VERSION): int
    {
        if (!self::readsVersion($data, $newest)) {
            throw new InvalidInputException(self::versionFault($data[self::VERSION_KEY] ?? null, $form, $newest));
        }

        $known = [self::VERSION_KEY, ...$known];
        $unknown = self::unknownKeys($data, $known);
        if ($unknown !== []) {
            throw new InvalidInputException(sprintf(
                'Unknown key in %s: %s (the known keys are %s)',
                $form,
                self::quoted($unknown),
                self::quoted($known)
            ));
        }

        return self::versionOf($data);
    }

    /**
     * True when the array states no version, or one from 1 to the newest.
     * A version of null is not the key left out: the key is written by the
     * library and never as null.
     *
     * @param array<array-key, mixed> $data
     */
    private static function readsVersion(array $data, int $newest): bool
    {
        if (!array_key_exists(self::VERSION_KEY, $data)) {
            return true;
        }

        $version = $data[self::VERSION_KEY];

        return is_int($version) && $version >= 1 && $version <= $newest;
    }

    /**
     * The version of an array that passed readsVersion().
     *
     * @param array<array-key, mixed> $data
     */
    private static function versionOf(array $data): int
    {
        $version = $data[self::VERSION_KEY] ?? null;

        return is_int($version) ? $version : 1;
    }

    private static function versionFault(mixed $version, string $form, int $newest): string
    {
        $reads = $newest === 1 ? 'version 1' : "versions 1 to {$newest}";

        if (!is_int($version)) {
            return sprintf(
                'The format version of %s must be an integer, %s given; this version of the library reads %s',
                $form,
                get_debug_type($version),
                $reads
            );
        }

        if ($version > $newest) {
            return sprintf(
                'Unsupported format version %d in %s: this version of the library reads %s.'
                    . ' The data was written by a later release; upgrade the library to read it',
                $version,
                $form,
                $reads
            );
        }

        return sprintf(
            'Unsupported format version %d in %s: format versions start at 1, and this version of the library reads %s',
            $version,
            $form,
            $reads
        );
    }

    /**
     * The keys of the array that are not among the known ones, in the order
     * the array has them. A key PHP holds as an integer (the `0` of a list,
     * or a numeric string) is reported as that integer.
     *
     * @param array<array-key, mixed> $data
     * @param list<string> $known
     *
     * @return list<int|string>
     */
    private static function unknownKeys(array $data, array $known): array
    {
        $unknown = [];
        foreach (array_keys($data) as $key) {
            if (!in_array((string) $key, $known, true)) {
                $unknown[] = $key;
            }
        }

        return $unknown;
    }

    /**
     * @param list<int|string> $keys
     */
    private static function quoted(array $keys): string
    {
        return implode(', ', array_map(static fn(int|string $key): string => "'{$key}'", $keys));
    }
}
