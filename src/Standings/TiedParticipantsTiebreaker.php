<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

/**
 * A tiebreaker computed between the tied participants only: its value for a
 * participant comes from the results of that participant against the others
 * it is level with, and from no other result. A sport calls it head-to-head.
 *
 * The interface adds no method. It tells the standings calculator what kind
 * of tiebreaker this is, because one choice of a competition applies to this
 * kind alone: whether an awarded result counts in it
 * (AwardedResultPolicy::$countBetweenTiedParticipants). With that choice
 * off, the calculator hands a tiebreaker of this kind the results without
 * the awarded ones, so the tiebreaker needs no code for it; the entries it
 * is handed are those of the whole table, awarded results included.
 *
 * The library ships no tiebreaker of this kind yet. Implement the interface
 * on one of your own to have the choice applied to it.
 *
 * @experimental
 */
interface TiedParticipantsTiebreaker extends TiebreakerInterface {}
