<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use Override;

/**
 * Ranks participants by points earned from wins, draws, and losses.
 *
 * The first RankingStrategy implementation, covering the two-outcome game
 * family. Sport conventions are named constructors rather than API
 * surface: threeOneZero() for the association-football 3/1/0 convention,
 * oneHalfZero() for the chess 1/0.5/0 convention.
 */
final readonly class WinDrawLossRanking implements RankingStrategy
{
    public function __construct(
        private float $winValue = 3.0,
        private float $drawValue = 1.0,
        private float $lossValue = 0.0
    ) {
    }

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
     * Omitted keys use the 3/1/0 defaults.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When a value is not numeric
     */
    public static function fromArray(array $config): self
    {
        $values = [];
        foreach (['win' => 3.0, 'draw' => 1.0, 'loss' => 0.0] as $key => $default) {
            $value = $config[$key] ?? $default;
            if (!is_int($value) && !is_float($value)) {
                throw new InvalidConfigurationException(
                    "Ranking value '{$key}' must be a number",
                    ['key' => $key, 'value' => $value]
                );
            }
            $values[$key] = (float) $value;
        }

        return new self($values['win'], $values['draw'], $values['loss']);
    }

    /**
     * @return array{win: float, draw: float, loss: float}
     */
    public function toArray(): array
    {
        return [
            'win' => $this->winValue,
            'draw' => $this->drawValue,
            'loss' => $this->lossValue,
        ];
    }

    public function getWinValue(): float
    {
        return $this->winValue;
    }

    public function getDrawValue(): float
    {
        return $this->drawValue;
    }

    public function getLossValue(): float
    {
        return $this->lossValue;
    }

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
