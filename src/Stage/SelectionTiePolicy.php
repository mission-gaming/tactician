<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * What a rank selection (RankRangeSelector, BestOfRankSelector) does with an
 * order that results do not decide.
 *
 * The backing strings are the identifiers of the selectors' plain-data
 * forms (`ties`); a form without the key holds the default, Refused. The
 * selectors say what each case accepts.
 *
 * @experimental
 */
enum SelectionTiePolicy: string
{
    /**
     * Every undecided order is refused with an UndecidedSelectionException.
     * The default.
     */
    case Refused = 'refuse';

    /**
     * Level entries that are all selected are accepted, and listed in the
     * order position gives them (withTiesInsideSelectionAccepted()); level
     * entries on both sides of a boundary of the selection are still
     * refused.
     */
    case InsideSelectionAccepted = 'accept-inside-selection';

    /**
     * The order is accepted as the table has it (withTableOrderAccepted()).
     */
    case TableOrderAccepted = 'accept-table-order';
}
