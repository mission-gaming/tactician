<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * What the number of participants a progression selector yields depends on
 * (SelectionYield::getKind()).
 *
 * @experimental
 */
enum SelectionYieldKind: string
{
    /**
     * A count the selector states, whatever the outcome: an overall rank
     * range, the best few of one rank across pools.
     */
    case Fixed = 'fixed';

    /**
     * A count for each pool of the stage before: a per-group rank range. The
     * yield is that count times the number of pools.
     */
    case PerPool = 'per-pool';

    /**
     * The winners of the last round of the stage before, and its byes:
     * half its entrants rounded up after one knockout round, and 1 after a
     * whole bracket.
     */
    case WinnersOfLastRound = 'winners-of-last-round';

    /**
     * The losers of the last round of the stage before: half its entrants
     * rounded down after one knockout round, and 1 after a whole bracket.
     */
    case LosersOfLastRound = 'losers-of-last-round';

    /**
     * The selector cannot say ahead of the outcome how many it yields.
     */
    case DependsOnOutcome = 'depends-on-outcome';
}
