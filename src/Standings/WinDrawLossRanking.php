<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Serialization\PlainData;
use Override;

/**
 * Ranks participants by points earned from wins, draws, and losses.
 *
 * The first RankingStrategy implementation, covering the two-outcome game
 * family. Sport conventions are named constructors rather than API
 * surface: threeOneZero() for the association-football 3/1/0 convention,
 * oneHalfZero() for the chess 1/0.5/0 convention.
 *
 * A bye has a value too (RankingStrategyWithByeValue): the value of a win
 * unless another is given. It is added where a stage credits byes and
 * nowhere else; rank() reads results only.
 *
 * @experimental
 */
final readonly class WinDrawLossRanking implements RankingStrategyWithByeValue
{
    /**
     * The values are used as given; nothing requires a win to be worth more
     * than a draw, or a loss to be worth nothing.
     *
     * @param float $winValue Added for each result that names the participant the winner
     * @param float $drawValue Added for each of its results that names no winner
     * @param float $lossValue Added for each of its results that names another winner
     * @param float|null $byeValue What one credited bye is worth, a finite number; null for
     *                             the value of a win
     */
    public function __construct(
        private float $winValue = 3.0,
        private float $drawValue = 1.0,
        private float $lossValue = 0.0,
        private ?float $byeValue = null
    ) {}

    /**
     * The association-football convention: 3 for a win, 1 for a draw.
     */
    public static function threeOneZero(): self
    {
        return new self(3.0, 1.0, 0.0);
    }

    /**
     * The chess convention: 1 for a win, half for a draw.
     */
    public static function oneHalfZero(): self
    {
        return new self(1.0, 0.5, 0.0);
    }

    /**
     * Build from plain configuration data: ['win' => 3, 'draw' => 1, 'loss' => 0].
     * Omitted keys, and keys set to null, use the 3/1/0 defaults. Any
     * other key is refused, so that a misspelt value is not silently read
     * as its default.
     *
     * The optional key `bye` is the value of a bye: a number, or one of
     * the strings 'win', 'draw' and 'loss' for the value the same array
     * gives that outcome (['win' => 3, 'draw' => 1, 'bye' => 'draw'] values
     * a bye at 1). Left out or null, a bye is worth a win.
     *
     * @param array<array-key, mixed> $config
     * @throws InvalidConfigurationException When the format version is not one this release reads
     *                                       (reason `UnsupportedFormatVersion`), a key is unknown
     *                                       (`UnknownOptionKey`), a value is neither an int nor a
     *                                       float (a numeric string is refused; `WrongValueType`),
     *                                       `bye` is a string other than the three above
     *                                       (`UnknownIdentifier`), or `bye` is not a finite number
     *                                       (`ValueOutOfRange`)
     */
    public static function fromArray(array $config): self
    {
        PlainData::checkOptions($config, ['win', 'draw', 'loss', 'bye'], 'win-draw-loss ranking');

        $values = [];
        foreach (['win' => 3.0, 'draw' => 1.0, 'loss' => 0.0] as $key => $default) {
            $value = $config[$key] ?? $default;
            if (!is_int($value) && !is_float($value)) {
                throw new InvalidConfigurationException(
                    "Ranking value '{$key}' must be a number",
                    ['key' => $key, 'value' => $value],
                    reason: InvalidConfigurationReason::WrongValueType
                );
            }
            $values[$key] = (float) $value;
        }

        $bye = $config['bye'] ?? null;
        if (is_string($bye)) {
            $bye = $values[$bye] ?? throw new InvalidConfigurationException(
                "Unknown value of a bye '{$bye}'",
                ['bye' => $bye, 'known' => array_keys($values)],
                reason: InvalidConfigurationReason::UnknownIdentifier
            );
        } elseif ($bye !== null && !is_int($bye) && !is_float($bye)) {
            throw new InvalidConfigurationException(
                "Ranking value 'bye' must be a number, or one of 'win', 'draw' and 'loss'",
                ['key' => 'bye', 'value' => $bye],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        if ($bye !== null && !is_finite((float) $bye)) {
            throw new InvalidConfigurationException(
                'The value of a bye must be a finite number',
                ['bye' => $bye],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }

        return new self($values['win'], $values['draw'], $values['loss'], $bye === null ? null : (float) $bye);
    }

    /**
     * The plain-data form fromArray() accepts: `format_version`, the
     * version of this form (1), then the three values. A ranking that was
     * given a value for a bye writes it after them, as a number under
     * `bye`; one that was given none writes no such key.
     *
     * @return array{format_version: int, win: float, draw: float, loss: float, bye?: float}
     */
    public function toArray(): array
    {
        $data = [
            PlainData::VERSION_KEY => PlainData::VERSION,
            'win' => $this->winValue,
            'draw' => $this->drawValue,
            'loss' => $this->lossValue,
        ];
        if ($this->byeValue !== null) {
            $data['bye'] = $this->byeValue;
        }

        return $data;
    }

    /**
     * The same ranking with a bye worth the given value: the value of a
     * draw (getDrawValue()), 0.0 for a bye that scores nothing, or any
     * other finite amount on the scale of the three values.
     */
    public function withByeValue(float $byeValue): self
    {
        return new self($this->winValue, $this->drawValue, $this->lossValue, $byeValue);
    }

    /**
     * What one credited bye adds to a ranking value: the value given for
     * it, or the value of a win when none was given.
     */
    #[Override]
    public function getByeValue(): float
    {
        return $this->byeValue ?? $this->winValue;
    }

    /**
     * The value of a win.
     */
    public function getWinValue(): float
    {
        return $this->winValue;
    }

    /**
     * The value of a draw.
     */
    public function getDrawValue(): float
    {
        return $this->drawValue;
    }

    /**
     * The value of a loss.
     */
    public function getLossValue(): float
    {
        return $this->lossValue;
    }

    /**
     * The sum of the win, draw and loss values over the results whose event
     * the participant is in: the draw value for a result with no winner,
     * the win value for one that names the participant, the loss value for
     * any other. Results of other events are ignored; no result gives 0.0.
     */
    #[Override]
    public function rank(Participant $participant, array $results): float
    {
        $value = 0.0;

        foreach ($results as $result) {
            if (!$result->getEvent()->hasParticipant($participant)) {
                continue;
            }

            if ($result->isDraw()) {
                $value += $this->drawValue;
            } elseif ($result->isWinFor($participant)) {
                $value += $this->winValue;
            } else {
                $value += $this->lossValue;
            }
        }

        return $value;
    }
}
