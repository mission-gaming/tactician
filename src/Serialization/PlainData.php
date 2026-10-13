<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Serialization;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;

/**
 * What every `fromArray()` checks before it reads a value: that the array
 * holds no key the form does not have.
 *
 * A key that is not known is a mistake the reader cannot see past: a
 * misspelt option would be read as its default, and a misspelt field of
 * stored data as the field left out (a result whose `winer` is ignored is a
 * draw). Both are refused, with the keys that are not known and the ones
 * that are.
 *
 * The forms are of two kinds, and each reports the fault with the exception
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
     * Refuse configuration that holds a key the form does not have.
     *
     * @param array<array-key, mixed> $config The array given to `fromArray()`
     * @param list<string> $known Every key the form reads
     * @param string $form What the array configures, as a noun phrase: 'round-robin options'
     * @param list<string> $requirements The requirements block of the form's errors, when it has one
     *
     * @throws InvalidConfigurationException When a key is not known (reason `UnknownOptionKey`); the
     *                                       context lists the keys under `unknown` and `known`
     */
    public static function checkOptions(array $config, array $known, string $form, array $requirements = []): void
    {
        $unknown = self::unknownKeys($config, $known);
        if ($unknown === []) {
            return;
        }

        throw new InvalidConfigurationException(
            sprintf('Unknown key in %s: %s', $form, self::quoted($unknown)),
            ['unknown' => $unknown, 'known' => $known],
            reason: InvalidConfigurationReason::UnknownOptionKey,
            requirements: $requirements
        );
    }

    /**
     * Refuse data that holds a key the form does not have.
     *
     * @param array<array-key, mixed> $data The array given to `fromArray()`
     * @param list<string> $known Every key the form reads
     * @param string $form What the array is, as a noun phrase: 'schedule data'
     *
     * @throws InvalidInputException When a key is not known; the message names the keys that are
     *                               not known and the ones that are
     */
    public static function checkFields(array $data, array $known, string $form): void
    {
        $unknown = self::unknownKeys($data, $known);
        if ($unknown === []) {
            return;
        }

        throw new InvalidInputException(sprintf(
            'Unknown key in %s: %s (the known keys are %s)',
            $form,
            self::quoted($unknown),
            self::quoted($known)
        ));
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
