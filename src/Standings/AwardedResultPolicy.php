<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Serialization\PlainData;

/**
 * The two things a standings calculator does with an awarded result that
 * are the caller's choice (DTO\Result::isAwarded()).
 *
 * An awarded result always counts in the table: it is one of the results of
 * its participants, a win for the winner it names and a loss for the others
 * (a draw when it names none), it is handed to the ranking strategy with
 * every other result, and StandingEntry::getAwarded() counts it. What a
 * competition decides for itself is:
 *
 * - countScores: whether the scores of an awarded result are added to
 *   scores-for and scores-against, and so move the score difference. They
 *   are the nominal score of the award (3-0, say), given by the application
 *   on the result. Off, an awarded result adds to no score figure, whatever
 *   scores it carries.
 * - countBetweenTiedParticipants: whether a tiebreaker that is computed
 *   between the tied participants only (TiedParticipantsTiebreaker) reads
 *   awarded results. Off, such a tiebreaker is handed the results without
 *   the awarded ones. Every other tiebreaker is handed all of them either
 *   way.
 *
 * Both are on by default: an awarded result is then counted exactly as the
 * same result without the mark. With no awarded result among the results,
 * neither choice changes anything.
 *
 * The ranking strategy and the tiebreakers are handed each result as it was
 * recorded, with its scores, under either choice of countScores: one that
 * reads scores can ask Result::isAwarded() and decide for itself.
 *
 * @experimental
 */
final readonly class AwardedResultPolicy
{
    public function __construct(
        public bool $countScores = true,
        public bool $countBetweenTiedParticipants = true
    ) {}

    /**
     * Build from plain configuration data:
     * ['count_scores' => false, 'count_between_tied_participants' => false].
     *
     * Either key may be left out, or be null, and is then on. Any other
     * key is refused, so that a misspelt choice is not silently read as
     * its default.
     *
     * @param array<array-key, mixed> $config
     *
     * @throws InvalidConfigurationException When the format version is not one this release reads
     *                                       (reason `UnsupportedFormatVersion`), a key is unknown
     *                                       (`UnknownOptionKey`), or a value is not a boolean
     *                                       (`WrongValueType`)
     */
    public static function fromArray(array $config): self
    {
        PlainData::checkOptions($config, ['count_scores', 'count_between_tied_participants'], 'awarded result policy');

        $countScores = $config['count_scores'] ?? true;
        $countBetweenTiedParticipants = $config['count_between_tied_participants'] ?? true;
        if (!is_bool($countScores) || !is_bool($countBetweenTiedParticipants)) {
            throw new InvalidConfigurationException(
                'count_scores and count_between_tied_participants must be booleans',
                [
                    'count_scores' => $countScores,
                    'count_between_tied_participants' => $countBetweenTiedParticipants,
                ],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        return new self($countScores, $countBetweenTiedParticipants);
    }

    /**
     * The plain-data form fromArray() accepts: both keys, always, after
     * `format_version`, the version of this form (1).
     *
     * @return array{format_version: int, count_scores: bool, count_between_tied_participants: bool}
     */
    public function toArray(): array
    {
        return [
            PlainData::VERSION_KEY => PlainData::VERSION,
            'count_scores' => $this->countScores,
            'count_between_tied_participants' => $this->countBetweenTiedParticipants,
        ];
    }
}
