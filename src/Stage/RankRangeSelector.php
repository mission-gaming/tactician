<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use Override;

/**
 * Standings-based progression: selects rank slices from an outcome.
 *
 * Three modes, all reading Tactician's standings (the ranking authority
 * for this selection decision):
 *
 * - overall: ranks from..to of the combined standings ('best 8 across all
 *   pools', or simply 'top 8' of an unpooled stage)
 * - per-group: ranks from..to of each pool, emitted block by block in pool
 *   order — winners of every pool first, then runners-up, and so on, which
 *   is exactly the ordering fold seeding wants for cross-pool knockout
 *   pairings
 * - topPerGroup(n) is the per-group 1..n convenience
 */
final readonly class RankRangeSelector implements ProgressionSelector
{
    private const MODE_OVERALL = 'overall';
    private const MODE_PER_GROUP = 'per-group';

    /**
     * @param 'overall'|'per-group' $mode
     * @param int $from 1-based first rank, inclusive
     * @param int $to 1-based last rank, inclusive
     * @throws InvalidConfigurationException When the range is not 1 <= from <= to
     */
    private function __construct(
        private string $mode,
        private int $from,
        private int $to
    ) {
        if ($from < 1 || $to < $from) {
            throw new InvalidConfigurationException(
                'Rank range must satisfy 1 <= from <= to',
                ['from' => $from, 'to' => $to]
            );
        }
    }

    /**
     * The top N of each pool, pool winners first: today's "qualifiers".
     *
     * @throws InvalidConfigurationException When count is below 1
     */
    public static function topPerGroup(int $count): self
    {
        return new self(self::MODE_PER_GROUP, 1, $count);
    }

    /**
     * Ranks from..to of each pool — e.g. perGroup(from: 3, to: 4) feeds a
     * losers' route.
     *
     * @throws InvalidConfigurationException When the range is not 1 <= from <= to
     */
    public static function perGroup(int $from, int $to): self
    {
        return new self(self::MODE_PER_GROUP, $from, $to);
    }

    /**
     * Ranks from..to of the combined standings — the best N across all
     * pools, or a slice of an unpooled table.
     *
     * @throws InvalidConfigurationException When the range is not 1 <= from <= to
     */
    public static function overall(int $from, int $to): self
    {
        return new self(self::MODE_OVERALL, $from, $to);
    }

    /**
     * Build from plain configuration data:
     * ['mode' => 'per-group', 'from' => 1, 'to' => 2].
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When the mode or range is invalid
     */
    public static function fromArray(array $config): self
    {
        $mode = $config['mode'] ?? null;
        if (!in_array($mode, [self::MODE_OVERALL, self::MODE_PER_GROUP], true)) {
            throw new InvalidConfigurationException(
                'Unknown rank range mode',
                ['mode' => $mode, 'known' => [self::MODE_OVERALL, self::MODE_PER_GROUP]]
            );
        }

        $from = $config['from'] ?? 1;
        $to = $config['to'] ?? null;
        if (!is_int($from) || !is_int($to)) {
            throw new InvalidConfigurationException(
                'Rank range bounds must be integers',
                ['from' => $from, 'to' => $to]
            );
        }

        return new self($mode, $from, $to);
    }

    /**
     * @return array{mode: string, from: int, to: int}
     */
    public function toArray(): array
    {
        return ['mode' => $this->mode, 'from' => $this->from, 'to' => $this->to];
    }

    #[Override]
    public function select(StageOutcome $outcome): array
    {
        if ($this->mode === self::MODE_OVERALL) {
            return $this->sliceStandings($outcome, 'the combined standings');
        }

        if (!$outcome->hasPools()) {
            throw new InvalidConfigurationException(
                'Per-group rank selection requires a pooled outcome',
                ['mode' => $this->mode]
            );
        }

        // Block by block: every pool's rank 1, then every pool's rank 2...
        // so pool winners head the list in pool order.
        $selected = [];
        for ($rank = $this->from; $rank <= $this->to; ++$rank) {
            foreach ($outcome->getPools() as $label => $poolOutcome) {
                $entries = $poolOutcome->getStandings()->getEntries();
                if (!isset($entries[$rank - 1])) {
                    throw new InvalidConfigurationException(
                        "Pool {$label} has no rank {$rank}",
                        ['pool' => $label, 'rank' => $rank, 'pool_size' => count($entries)]
                    );
                }
                $selected[] = $entries[$rank - 1]->getParticipant();
            }
        }

        return $selected;
    }

    #[Override]
    public function getSelectionSize(): ?int
    {
        // Per-group counts depend on how many pools the outcome carries
        return $this->mode === self::MODE_OVERALL ? $this->to - $this->from + 1 : null;
    }

    /**
     * @return array<Participant>
     * @throws InvalidConfigurationException When the standings are shorter than the range
     */
    private function sliceStandings(StageOutcome $outcome, string $description): array
    {
        $entries = $outcome->getStandings()->getEntries();

        if (count($entries) < $this->to) {
            throw new InvalidConfigurationException(
                "Rank range extends past {$description}",
                ['to' => $this->to, 'available' => count($entries)]
            );
        }

        $selected = [];
        for ($rank = $this->from; $rank <= $this->to; ++$rank) {
            $selected[] = $entries[$rank - 1]->getParticipant();
        }

        return $selected;
    }
}
