<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * Which side of the last round's ties a MatchOutcomeSelector reads.
 *
 * The backing strings are the identifiers of the selector's plain-data form
 * (`mode`): `MatchOutcomeMode::from($config['mode'])` reads stored
 * configuration, and `MatchOutcomeSelector::getMode()->value` is what
 * `toArray()` writes.
 *
 * @experimental
 */
enum MatchOutcomeMode: string
{
    /**
     * The winners of the round's ties, then its byes
     * (MatchOutcomeSelector::winners()).
     */
    case Winners = 'winners';

    /**
     * The losers of the round's ties, and never a bye
     * (MatchOutcomeSelector::losers()).
     */
    case Losers = 'losers';
}
