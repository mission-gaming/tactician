<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * Which table a RankRangeSelector takes its ranks from.
 *
 * The backing strings are the identifiers of the selector's plain-data form
 * (`mode`).
 *
 * @experimental
 */
enum RankRangeMode: string
{
    /**
     * The ranks of the outcome's own table, which ranks the entrants of
     * every pool together (RankRangeSelector::overall()).
     */
    case Overall = 'overall';

    /**
     * The same ranks of each pool's table, rank by rank
     * (RankRangeSelector::perGroup(), RankRangeSelector::topPerGroup()).
     */
    case PerGroup = 'per-group';
}
