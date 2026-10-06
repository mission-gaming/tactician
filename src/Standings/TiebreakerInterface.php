<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;

/**
 * A tiebreaker producing a comparable value per participant (higher is better).
 *
 * The calculator asks every tiebreaker for the value of every participant
 * of the table, stores the values on the entries, and compares them, in the
 * order the tiebreakers were given, where ranking values are equal.
 *
 * @experimental
 */
interface TiebreakerInterface
{
    /**
     * The name this tiebreaker's values are stored and looked up under
     * (StandingEntry::getTiebreakerValue()).
     *
     * It must differ from the name of every other tiebreaker of the same
     * calculator: of two with one name, the value of the later replaces the
     * value of the earlier.
     */
    public function getName(): string;

    /**
     * This tiebreaker's value for one participant; higher places it above.
     *
     * The value must be a number: an entry whose value is NAN has no defined
     * place in the table.
     *
     * @param Participant $participant A participant of the table
     * @param array<Result> $results Every result the table is calculated from, not only this
     *                               participant's
     * @param array<string, StandingEntry> $entries The entry of every participant of the table,
     *                                              keyed by participant ID, with its record,
     *                                              ranking value and scores and without
     *                                              tiebreaker values
     */
    public function calculate(Participant $participant, array $results, array $entries): float;
}
