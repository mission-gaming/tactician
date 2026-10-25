<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\Exceptions\InvariantViolationException;
use MissionGaming\Tactician\Standings\ByeTreatment;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\TiebreakerInterface;
use MissionGaming\Tactician\Standings\TiebreakerWithByeTreatment;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;

/**
 * The one place that writes and reads the fingerprints of the library's
 * engines (see FingerprintedEngine for the contract they follow, and ADR
 * 0015 for why it has this form).
 *
 * An engine names its format and the version of the rules that pair its
 * configuration, then states every option that shapes its rounds together
 * with that option's default:
 *
 *     EngineFingerprint::of('single-elimination', 1)
 *         ->with('legs-per-tie', $options->legsPerTie, 1)
 *         ->with('reseed-each-round', $options->reseedEachRound, false)
 *         ->toString();
 *
 * with() leaves out an option whose value is its default. That is what
 * keeps stored stamps valid when an option is added: an engine that gains
 * an option adds one with() call, and every configuration that leaves the
 * new option alone has the fingerprint it had before. A new option must
 * therefore be given here with the value that reproduces the behaviour
 * before it existed as its default, and a default, once released, is not
 * changed: changing it would change what every stored stamp without the
 * option means.
 *
 * The version of the rules is the engine's to state, per configuration. It
 * starts at 1 and is raised, in a minor release, for exactly the
 * configurations that the release pairs differently from the same
 * recorded state; every other configuration keeps its string, so a state
 * it stamped before is still accepted. 0.3.0 raised it to 2 for Swiss and
 * for a re-seeded single elimination bracket (ADR 0004); a single
 * elimination bracket on a fixed path and a double elimination bracket
 * pair as 0.2.x paired them and are at 1, which is what 0.2.x wrote for
 * them. A version is never lowered: a stamp of a higher version than the
 * engine's is one a later release wrote.
 *
 * The string is `tactician:v`, the version of the rules, `:`, the format,
 * and one `;name=value` per stated option, ordered by name so that the
 * order of the with() calls means nothing. `tactician:` separates the
 * library's fingerprints from the strings an application gives its own
 * engines. A string value is percent-encoded, so no value can be read as a
 * separator, and two configurations have the same string only when every
 * stated option is the same. This spelling is the one 0.2.x wrote (where
 * the version was one number for every format), and it is not changed:
 * a different spelling would change every string at once.
 *
 * Format and option names are literals of this library, in lower case with
 * hyphens; they are not encoded.
 *
 * @internal Not public API. The string an engine returns does not change
 *           in a patch release, because states are stored with it; how it
 *           is spelled is not a contract for anything but this class.
 *           Compare fingerprints for equality.
 */
final readonly class EngineFingerprint
{
    private const string PREFIX = 'tactician:';

    /**
     * @param positive-int $rules The version of the rules that pair the configuration
     * @param array<string, string> $options Encoded values of the options that are not at their default, by name
     */
    private function __construct(
        private string $format,
        private int $rules,
        private array $options = []
    ) {}

    /**
     * Begin the fingerprint of a format ('swiss', 'single-elimination'),
     * paired by the given version of its rules.
     *
     * @param int $rules At least 1; see the class docblock for when it is raised
     *
     * @throws InvariantViolationException When the version is below 1 (a defect of the engine)
     */
    public static function of(string $format, int $rules): self
    {
        if ($rules < 1) {
            throw new InvariantViolationException('The version of the rules of a format is at least 1');
        }

        return new self($format, $rules);
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

        return new self($this->format, $this->rules, $options);
    }

    /**
     * State the rules a table is ordered by, for an engine that pairs from
     * the table: the ranking scale and the tiebreakers in order, or that
     * the calculator is a class of the application's.
     *
     * A WinDrawLossRanking is described by its three values, and the 3/1/0
     * of a calculator built without arguments is the default. Any other
     * RankingStrategy has no description the library could write, so it is
     * stated as `custom`: told apart from every win/draw/loss scale, and
     * not from another strategy of the application's. A tiebreaker is
     * stated by the name it gives itself, so two tiebreakers with one name
     * are one tiebreaker here. The rule for an engine's options holds for
     * these too: a tiebreaker or a ranking strategy of the library that
     * gains an option states it here only when it is not at its default,
     * so that what is written today for one without options stays as it
     * is.
     *
     * Two options of the library's own are stated by that rule. The value
     * of a bye of a WinDrawLossRanking is part of the fingerprint
     * (`bye-value`) only when it is not the value of a win, which is what
     * a bye was worth before it could be chosen. The bye treatment of a
     * tiebreaker (TiebreakerWithByeTreatment) is part of it
     * (`tiebreaker-byes`, as `name:treatment`) only when it is not
     * ByeTreatment::Uncounted. Both change the table a bye gives, and so
     * the rounds paired from it. A ranking strategy of the application's
     * is `custom` whatever it says a bye is worth.
     *
     * The two choices of the calculator's awarded result policy are stated
     * by that rule: each is part of the fingerprint only when it is off,
     * which is not its default, so every calculator built before the policy
     * existed has the fingerprint it had. They change the table an awarded
     * result gives, and so the rounds paired from it.
     *
     * A subclass of StandingsCalculator may order the table by rules of
     * its own, so it is stated as `custom` and nothing is read from it: it
     * need not use the ranking strategy and the tiebreakers it was built
     * with, and one with a constructor of its own need not have any. It is
     * told apart from the library's calculator and not from another
     * subclass.
     */
    public function withStandingsRules(StandingsCalculator $calculator): self
    {
        if ($calculator::class !== StandingsCalculator::class) {
            return $this->with('standings', 'custom', 'library');
        }

        $ranking = $calculator->getRankingStrategy();
        $awardedResults = $calculator->getAwardedResultPolicy();

        // A treatment of byes that is not the default, by tiebreaker
        $byeTreatments = [];
        foreach ($calculator->getTiebreakers() as $tiebreaker) {
            if ($tiebreaker instanceof TiebreakerWithByeTreatment && $tiebreaker->getByeTreatment() !== ByeTreatment::Uncounted) {
                $byeTreatments[] = $tiebreaker->getName() . ':' . $tiebreaker->getByeTreatment()->value;
            }
        }

        // The value of a bye is stated where it is not the value of a win
        $byeValue = $ranking instanceof WinDrawLossRanking ? $ranking->getByeValue() : 0.0;
        $winValue = $ranking instanceof WinDrawLossRanking ? $ranking->getWinValue() : 0.0;

        return $this
            ->with('awarded-scores', $awardedResults->countScores, true)
            ->with('awarded-between-tied', $awardedResults->countBetweenTiedParticipants, true)
            ->with('bye-value', $byeValue, $winValue)
            ->with(
                'ranking',
                $ranking instanceof WinDrawLossRanking
                    ? ['win-draw-loss', $ranking->getWinValue(), $ranking->getDrawValue(), $ranking->getLossValue()]
                    : 'custom',
                ['win-draw-loss', 3.0, 1.0, 0.0]
            )
            ->with('tiebreaker-byes', $byeTreatments, [])
            ->with(
                'tiebreakers',
                array_map(
                    fn(TiebreakerInterface $tiebreaker): string => $tiebreaker->getName(),
                    array_values($calculator->getTiebreakers())
                ),
                []
            );
    }

    /**
     * @return non-empty-string
     */
    public function toString(): string
    {
        $fingerprint = self::PREFIX . 'v' . $this->rules . ':' . $this->format;
        foreach ($this->options as $option => $value) {
            $fingerprint .= ';' . $option . '=' . $value;
        }

        return $fingerprint;
    }

    /**
     * Read a fingerprint this class wrote, of any version of the rules;
     * null for any other string: an application's own stamp, and one that
     * has the prefix and is not spelled as toString() spells it (a version
     * that is not a whole number from 1 without leading zeros, options out
     * of order, an option twice, an option without a value). The last
     * keeps a string that was put together by hand from being compared
     * option by option as though an engine had written it.
     */
    public static function parse(string $fingerprint): ?self
    {
        if (preg_match('/^tactician:v([1-9][0-9]{0,8}):(.*)$/s', $fingerprint, $match) !== 1) {
            return null;
        }

        $rules = (int) $match[1];
        $parts = explode(';', $match[2]);
        $format = array_shift($parts);
        if ($format === '' || $rules < 1) {
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

        $parsed = new self($format, $rules, $options);

        return $parsed->toString() === $fingerprint ? $parsed : null;
    }

    /**
     * Say in words where two fingerprints differ, for the error an engine
     * raises on a state stamped with another one: the format when it is
     * another format; otherwise each option that differs, with its value
     * on either side (an option absent from a fingerprint is at its
     * default there); and where no option differs, the version of the
     * rules, naming the release that wrote the stamp as earlier or later.
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
                str_starts_with($recorded, self::PREFIX) => 'the stamp begins with the library\'s prefix and is not one this version of the library writes',
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

        // The version of the rules is stated per configuration, so where
        // the options differ the versions may differ with them and say
        // nothing more. With the same options, another version is another
        // release.
        if ($differences !== []) {
            return $differences;
        }

        return [$stamp->rules < $own->rules
            ? 'rules: the stamp was written by an earlier release of the library, whose engine paired this'
                . " configuration by other rules (version {$stamp->rules} of the {$own->format} rules, this engine"
                . " version {$own->rules}); see the changelog of the release that changed them"
            : "rules: the stamp was written by a later release of the library (version {$stamp->rules}"
                . " of the {$own->format} rules, this engine version {$own->rules})"];
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
     * A float as a decimal that reads back as the same float, laid out by
     * this method alone, so that the string depends on no setting
     * (`precision`, `serialize_precision`, the locale) and on no PHP
     * version's way of printing a float.
     *
     * The digits are those of the shortest correctly rounded decimal that
     * reads back as the value. They are laid out as ECMAScript lays out a
     * number: plainly from 0.000001 up to 21 digits before the point (`3`,
     * `0.5`, `100`, `0.1`), and beyond that as `1e+21` and `1e-7`. Both
     * zeros are `0`.
     */
    private static function encodeFloat(float $value): string
    {
        if ($value === 0.0) {
            return '0';
        }

        if (!is_finite($value)) {
            return is_nan($value) ? 'nan' : ($value > 0 ? 'inf' : '-inf');
        }

        $magnitude = abs($value);
        $digits = '';
        $exponent = 0;
        for ($precision = 0; $precision <= 16; ++$precision) {
            // One digit, a decimal point however it is spelled, $precision
            // more digits, `E` and the power of ten.
            [$mantissa, $power] = explode('E', sprintf('%.' . $precision . 'E', $magnitude));
            $digits = (string) preg_replace('/\D/', '', $mantissa);
            $exponent = (int) $power;
            if ((float) ($digits . 'E' . ($exponent - $precision)) === $magnitude) {
                break;
            }
        }

        $digits = rtrim($digits, '0');
        $count = strlen($digits);
        // The decimal point stands after this many of the digits.
        $point = $exponent + 1;

        $text = match (true) {
            $point >= $count && $point <= 21 => $digits . str_repeat('0', $point - $count),
            $point > 0 && $point <= 21 => substr($digits, 0, $point) . '.' . substr($digits, $point),
            $point > -6 && $point <= 0 => '0.' . str_repeat('0', -$point) . $digits,
            default => $digits[0] . ($count > 1 ? '.' . substr($digits, 1) : '')
                . 'e' . ($exponent < 0 ? '-' : '+') . abs($exponent),
        };

        return ($value < 0 ? '-' : '') . $text;
    }
}
