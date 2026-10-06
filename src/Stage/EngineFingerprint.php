<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\TiebreakerInterface;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;

/**
 * The one place that writes and reads the fingerprints of the library's
 * engines (see FingerprintedEngine for the contract they follow).
 *
 * An engine names its format, then states every option that shapes its
 * rounds together with that option's default:
 *
 *     EngineFingerprint::of('single-elimination')
 *         ->with('legs-per-tie', $options->legsPerTie, 1)
 *         ->with('reseed-each-round', $options->reseedEachRound, false)
 *         ->toString();
 *
 * with() leaves out an option whose value is its default. That is what
 * keeps stored stamps valid: an engine that gains an option adds one
 * with() call, and every configuration that leaves the new option alone
 * has the fingerprint it had before. A new option must therefore be given
 * here with the value that reproduces the behaviour before it existed as
 * its default, and a default, once released, is never changed: changing it
 * would change what every stored stamp without the option means.
 *
 * The string is `tactician:v1:` followed by the format and one
 * `;name=value` per stated option, ordered by name so that the order of
 * the with() calls means nothing. `tactician:` separates the library's
 * fingerprints from the strings an application gives its own engines.
 * `v1` is the version of this encoding, so that a later release that had
 * to encode differently could still recognise a stamp written by this one.
 * A string value is percent-encoded, so no value can be read as a
 * separator, and two configurations have the same string only when every
 * stated option is the same.
 *
 * Format and option names are literals of this library, in lower case with
 * hyphens; they are not encoded.
 *
 * @internal Not public API. The string an engine returns is stable, because
 *           states are stored with it; how it is spelled is not a contract
 *           for anything but this class. Compare fingerprints for equality.
 */
final readonly class EngineFingerprint
{
    private const string PREFIX = 'tactician:';

    private const string VERSION = 'v1';

    /**
     * @param array<string, string> $options Encoded values of the options that are not at their default, by name
     */
    private function __construct(
        private string $format,
        private array $options = []
    ) {}

    /**
     * Begin the fingerprint of a format ('swiss', 'single-elimination').
     */
    public static function of(string $format): self
    {
        return new self($format);
    }

    /**
     * State an option that shapes the rounds: it becomes part of the
     * fingerprint unless the value is the default.
     *
     * A list is ordered: the same entries in another order are another
     * value.
     *
     * @param bool|int|float|string|list<bool|int|float|string> $value
     * @param bool|int|float|string|list<bool|int|float|string> $default
     */
    public function with(string $option, bool|int|float|string|array $value, bool|int|float|string|array $default): self
    {
        $encoded = self::encode($value);
        $options = $this->options;
        unset($options[$option]);

        if ($encoded !== self::encode($default)) {
            $options[$option] = $encoded;
            ksort($options, SORT_STRING);
        }

        return new self($this->format, $options);
    }

    /**
     * State the rules a table is ordered by, for an engine that pairs from
     * the table: the ranking scale, the tiebreakers in order, and whether
     * the calculator is a class of the application's.
     *
     * A WinDrawLossRanking is described by its three values, and the 3/1/0
     * of a calculator built without arguments is the default. Any other
     * RankingStrategy has no description the library could write, so it is
     * stated as `custom`: told apart from every win/draw/loss scale, and
     * not from another strategy of the application's. A tiebreaker is
     * stated by the name it gives itself. A subclass of StandingsCalculator
     * may order the table by rules of its own and is stated as `custom`
     * as well.
     */
    public function withStandingsRules(StandingsCalculator $calculator): self
    {
        $ranking = $calculator->getRankingStrategy();

        return $this
            ->with(
                'ranking',
                $ranking instanceof WinDrawLossRanking
                    ? ['win-draw-loss', $ranking->getWinValue(), $ranking->getDrawValue(), $ranking->getLossValue()]
                    : 'custom',
                ['win-draw-loss', 3.0, 1.0, 0.0]
            )
            ->with(
                'tiebreakers',
                array_map(
                    fn(TiebreakerInterface $tiebreaker): string => $tiebreaker->getName(),
                    array_values($calculator->getTiebreakers())
                ),
                []
            )
            ->with('standings', $calculator::class === StandingsCalculator::class ? 'library' : 'custom', 'library');
    }

    /**
     * @return non-empty-string
     */
    public function toString(): string
    {
        $fingerprint = self::PREFIX . self::VERSION . ':' . $this->format;
        foreach ($this->options as $option => $value) {
            $fingerprint .= ';' . $option . '=' . $value;
        }

        return $fingerprint;
    }

    /**
     * Read a fingerprint this class wrote; null for any other string (an
     * application's own stamp, or one in an encoding this version does not
     * know).
     */
    public static function parse(string $fingerprint): ?self
    {
        $head = self::PREFIX . self::VERSION . ':';
        if (!str_starts_with($fingerprint, $head)) {
            return null;
        }

        $parts = explode(';', substr($fingerprint, strlen($head)));
        $format = array_shift($parts);
        if ($format === '') {
            return null;
        }

        $options = [];
        foreach ($parts as $part) {
            $separator = strpos($part, '=');
            if ($separator === false || $separator === 0) {
                return null;
            }
            $options[substr($part, 0, $separator)] = substr($part, $separator + 1);
        }
        ksort($options, SORT_STRING);

        return new self($format, $options);
    }

    /**
     * Say in words where two fingerprints differ, for the error an engine
     * raises on a state stamped with another one: the format when it is
     * another format, otherwise each option that differs with its value on
     * either side. An option absent from a fingerprint is at its default
     * there.
     *
     * The wording is for a person to read. It is not parsed by anything
     * and may change.
     *
     * @return list<string> At least one statement when the two strings differ
     */
    public static function differences(string $recorded, string $engine): array
    {
        if ($recorded === $engine) {
            return [];
        }

        $stamp = self::parse($recorded);
        $own = self::parse($engine);

        if ($stamp === null || $own === null) {
            return [match (true) {
                $stamp !== null => 'the state is stamped by one of the library\'s engines and this engine is not one',
                $own === null => 'the stamp is not this engine\'s',
                str_starts_with($recorded, self::PREFIX) => 'the stamp was written by a version of the library this one cannot read',
                default => 'the stamp was not written by one of the library\'s engines',
            }];
        }

        if ($stamp->format !== $own->format) {
            return ["format: recorded {$stamp->format}, this engine {$own->format}"];
        }

        $differences = [];
        $names = array_keys($stamp->options + $own->options);
        sort($names, SORT_STRING);
        foreach ($names as $name) {
            $was = $stamp->options[$name] ?? null;
            $is = $own->options[$name] ?? null;
            if ($was !== $is) {
                $differences[] = sprintf(
                    '%s: recorded %s, this engine %s',
                    $name,
                    $was ?? 'the default',
                    $is ?? 'the default'
                );
            }
        }

        return $differences;
    }

    /**
     * @param bool|int|float|string|list<bool|int|float|string> $value
     */
    private static function encode(bool|int|float|string|array $value): string
    {
        if (is_array($value)) {
            return implode(',', array_map(self::encodeScalar(...), $value));
        }

        return self::encodeScalar($value);
    }

    /**
     * No two values of one type share an encoding: rawurlencode() leaves
     * only letters, digits, `-`, `.`, `_` and `~` as they are, so `,`, `;`,
     * `=` and `'` in a string cannot be read as part of the structure, and
     * the empty string is written as two quotes so that a list holding it
     * is not the empty list.
     */
    private static function encodeScalar(bool|int|float|string $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            is_int($value) => (string) $value,
            is_float($value) => self::encodeFloat($value),
            $value === '' => "''",
            default => rawurlencode($value),
        };
    }

    /**
     * The shortest decimal that reads back as the same float, whatever the
     * `precision` and `serialize_precision` settings and the locale are:
     * `3`, `0.5`, `0.1`. Both zeros are `0`.
     */
    private static function encodeFloat(float $value): string
    {
        if ($value === 0.0) {
            return '0';
        }

        if (!is_finite($value)) {
            return is_nan($value) ? 'nan' : ($value > 0 ? 'inf' : '-inf');
        }

        $text = '';
        for ($digits = 1; $digits <= 17; ++$digits) {
            $text = sprintf('%.' . $digits . 'H', $value);
            if ((float) $text === $value) {
                break;
            }
        }

        return $text;
    }
}
