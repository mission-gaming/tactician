<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Serialization\PlainData;

/**
 * Builds the tiebreakers of a standings calculator from plain data, and
 * writes them as it: a chain is a list, in the order the tiebreakers apply.
 *
 *     $tiebreakers = TiebreakerChain::fromIdentifiers([
 *         'between-tied:ranking-value',
 *         'between-tied:score-difference',
 *         'score-difference',
 *         'scores-for',
 *         'wins',
 *     ]);
 *     $calculator = new StandingsCalculator(WinDrawLossRanking::threeOneZero(), $tiebreakers);
 *
 * An entry of the list is an identifier, or a map that names one under
 * `tiebreaker` and gives its options:
 *
 * - 'wins', 'score-difference', 'scores-for', 'ranking-value': WinsTiebreaker,
 *   ScoreDifferenceTiebreaker, ScoresForTiebreaker and RankingValueTiebreaker.
 *   They take no option.
 * - 'buchholz', 'sonneborn-berger': BuchholzTiebreaker and
 *   SonnebornBergerTiebreaker. As a map either takes `byes`, a ByeTreatment
 *   by its value: ['tiebreaker' => 'buchholz', 'byes' => 'own-ranking-value'].
 *   Without it, or as a bare identifier, the treatment is the default,
 *   'uncounted'.
 * - 'between-tied:' followed by one of the six above: that tiebreaker as
 *   the measure of a BetweenTiedParticipants. It takes no option.
 *
 * An identifier is the name the tiebreaker gives itself
 * (TiebreakerInterface::getName()), which is the key of its value on an
 * entry. A name may be in a chain once.
 *
 * Only the library's tiebreakers have identifiers. A chain that holds one
 * of your own is built in code, and identifiersOf() refuses it.
 *
 * @experimental
 */
final class TiebreakerChain
{
    private const string BETWEEN_TIED = 'between-tied:';

    /**
     * The tiebreakers a list of plain data names, in its order.
     *
     * @param array<array-key, mixed> $chain Identifiers, and maps of an identifier with options
     *
     * @return list<TiebreakerInterface>
     *
     * @throws InvalidConfigurationException When an entry is neither a string nor a map (reason
     *                                       `WrongValueType`), an identifier is not known
     *                                       (`UnknownIdentifier`; the context lists the known
     *                                       ones), a map has a key it does not read
     *                                       (`UnknownOptionKey`) or a version this release does
     *                                       not read (`UnsupportedFormatVersion`), an option is
     *                                       given to a tiebreaker that has none
     *                                       (`IncompatibleOptions`), or a name is in the chain
     *                                       twice (`DuplicateName`)
     */
    public static function fromIdentifiers(array $chain): array
    {
        $tiebreakers = [];
        foreach ($chain as $entry) {
            $tiebreaker = self::tiebreakerOf($entry);
            $name = $tiebreaker->getName();
            if (isset($tiebreakers[$name])) {
                throw new InvalidConfigurationException(
                    "The tiebreaker '{$name}' is in the chain twice",
                    ['tiebreaker' => $name],
                    reason: InvalidConfigurationReason::DuplicateName
                );
            }
            $tiebreakers[$name] = $tiebreaker;
        }

        return array_values($tiebreakers);
    }

    /**
     * The plain data fromIdentifiers() reads for the given tiebreakers: an
     * identifier for each, or a map where it has an option that is not at
     * its default.
     *
     * @param array<TiebreakerInterface> $tiebreakers
     *
     * @return list<string|array{tiebreaker: string, byes: string}>
     *
     * @throws InvalidConfigurationException When a tiebreaker is not one of the library's: it has
     *                                       no identifier (reason `NotSerializable`)
     */
    public static function identifiersOf(array $tiebreakers): array
    {
        $chain = [];
        foreach ($tiebreakers as $tiebreaker) {
            if ($tiebreaker instanceof BetweenTiedParticipants) {
                $measure = self::identifierOf($tiebreaker->getMeasure());
                if (!is_string($measure)) {
                    throw new InvalidConfigurationException(
                        'A tiebreaker between the tied participants whose measure has options cannot be written as plain data',
                        ['tiebreaker' => $tiebreaker->getName()],
                        reason: InvalidConfigurationReason::NotSerializable
                    );
                }

                $chain[] = self::BETWEEN_TIED . $measure;

                continue;
            }

            $chain[] = self::identifierOf($tiebreaker);
        }

        return $chain;
    }

    /**
     * Every identifier fromIdentifiers() reads.
     *
     * @return list<string>
     */
    public static function identifiers(): array
    {
        $measures = array_keys(self::measures());

        return [
            ...$measures,
            ...array_map(static fn(string $measure): string => self::BETWEEN_TIED . $measure, $measures),
        ];
    }

    /**
     * @return array<string, class-string<TiebreakerInterface>>
     */
    private static function measures(): array
    {
        return [
            'wins' => WinsTiebreaker::class,
            'score-difference' => ScoreDifferenceTiebreaker::class,
            'scores-for' => ScoresForTiebreaker::class,
            'ranking-value' => RankingValueTiebreaker::class,
            'buchholz' => BuchholzTiebreaker::class,
            'sonneborn-berger' => SonnebornBergerTiebreaker::class,
        ];
    }

    /**
     * @return string|array{tiebreaker: string, byes: string}
     *
     * @throws InvalidConfigurationException When the tiebreaker has no identifier
     */
    private static function identifierOf(TiebreakerInterface $tiebreaker): string|array
    {
        $identifier = array_search($tiebreaker::class, self::measures(), true);
        if ($identifier === false) {
            throw new InvalidConfigurationException(
                'Only the tiebreakers of the library can be written as plain data',
                ['tiebreaker' => $tiebreaker::class],
                reason: InvalidConfigurationReason::NotSerializable
            );
        }

        if ($tiebreaker instanceof TiebreakerWithByeTreatment && $tiebreaker->getByeTreatment() !== ByeTreatment::Uncounted) {
            return ['tiebreaker' => $identifier, 'byes' => $tiebreaker->getByeTreatment()->value];
        }

        return $identifier;
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function tiebreakerOf(mixed $entry): TiebreakerInterface
    {
        $byes = null;
        $identifier = $entry;
        if (is_array($entry)) {
            PlainData::checkOptions($entry, ['tiebreaker', 'byes'], 'tiebreaker');
            $identifier = $entry['tiebreaker'] ?? null;
            $byes = $entry['byes'] ?? null;
        }

        if (!is_string($identifier)) {
            throw new InvalidConfigurationException(
                "A tiebreaker is named by a string, or by a map with the string under 'tiebreaker'",
                ['tiebreaker' => $identifier],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        $between = str_starts_with($identifier, self::BETWEEN_TIED);
        $measure = $between ? substr($identifier, strlen(self::BETWEEN_TIED)) : $identifier;
        if (!isset(self::measures()[$measure])) {
            throw new InvalidConfigurationException(
                "Unknown tiebreaker '{$identifier}'",
                ['tiebreaker' => $identifier, 'known' => self::identifiers()],
                reason: InvalidConfigurationReason::UnknownIdentifier
            );
        }

        $takesByes = !$between && ($measure === 'buchholz' || $measure === 'sonneborn-berger');
        if ($byes !== null && !$takesByes) {
            throw new InvalidConfigurationException(
                "The tiebreaker '{$identifier}' takes no bye treatment",
                ['tiebreaker' => $identifier, 'byes' => $byes],
                reason: InvalidConfigurationReason::IncompatibleOptions
            );
        }

        $tiebreaker = match ($measure) {
            'wins' => new WinsTiebreaker(),
            'score-difference' => new ScoreDifferenceTiebreaker(),
            'scores-for' => new ScoresForTiebreaker(),
            'ranking-value' => new RankingValueTiebreaker(),
            'buchholz' => new BuchholzTiebreaker(self::byeTreatmentOf($byes, $identifier)),
            default => new SonnebornBergerTiebreaker(self::byeTreatmentOf($byes, $identifier)),
        };

        return $between ? new BetweenTiedParticipants($tiebreaker) : $tiebreaker;
    }

    /**
     * @throws InvalidConfigurationException When the value is not a string (reason
     *                                       `WrongValueType`) or names no treatment
     *                                       (`UnknownIdentifier`)
     */
    private static function byeTreatmentOf(mixed $byes, string $identifier): ByeTreatment
    {
        if ($byes === null) {
            return ByeTreatment::Uncounted;
        }

        if (!is_string($byes)) {
            throw new InvalidConfigurationException(
                "The bye treatment of the tiebreaker '{$identifier}' must be a string",
                ['byes' => $byes],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        return ByeTreatment::tryFrom($byes) ?? throw new InvalidConfigurationException(
            "Unknown bye treatment '{$byes}' of the tiebreaker '{$identifier}'",
            ['byes' => $byes, 'known' => array_map(static fn(ByeTreatment $case): string => $case->value, ByeTreatment::cases())],
            reason: InvalidConfigurationReason::UnknownIdentifier
        );
    }
}
