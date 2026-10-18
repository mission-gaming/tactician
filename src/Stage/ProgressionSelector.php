<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;

/**
 * The hand-off between stages: consumes a StageOutcome and produces the
 * ordered participant list a destination stage takes as its entrants.
 *
 * Order is authoritative — a stage seeds its entrants from their position
 * in the returned list (position 1 is seed 1), so library selectors and
 * consumer-derived lists behave identically by construction.
 *
 * Selectors are optional machinery, not a gate: the entry contract of any
 * stage is simply an ordered participant list, and consumers that compute
 * their own qualification hand the next stage that list directly with no
 * penalty. Per selection decision, use one ranking authority — Tactician's
 * standings or your own tables, not both for the same pool.
 *
 * @experimental
 */
interface ProgressionSelector
{
    /**
     * Select and order the progressing participants from an outcome.
     *
     * The same outcome always gives the same list. The order is the
     * selector's own and each implementation states it (by rank for a rank
     * selection, bracket order for an outcome selection); whatever it is,
     * the destination stage seeds from it.
     *
     * @return array<Participant> A list in the selector's order; position determines destination seeding
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When the outcome cannot satisfy this selection
     */
    public function select(StageOutcome $outcome): array;

    /**
     * How many participants this selector yields, as a statement made
     * ahead of any outcome: a fixed count, a count per pool, the winners or
     * the losers of the last round of the stage before, or a number only the
     * outcome gives. CompositionValidator checks a chain with it, through
     * SelectionYield::countFrom(), and reads no other method of a selector.
     *
     * The statement is about select(): for an outcome of the shape it is
     * given in which nobody has withdrawn, the list select() returns has
     * that many participants, or select() refuses. A withdrawal is not
     * known ahead of time, and it can make the list shorter: a bracket tie
     * whose two entrants both withdrew sends nobody on (SettledTie), so the
     * winners of a last round can be fewer, and an entrant who stands alone
     * in it because of that has no loser beside it, so the losers can be
     * fewer too.
     */
    public function getYield(): SelectionYield;

    /**
     * How many participants this selector yields whatever the outcome: the
     * count of getYield() when its kind is SelectionYieldKind::Fixed, and
     * null for every other kind (a per-pool selection, the winners or
     * losers of a round), whose number depends on the stage before.
     */
    public function getSelectionSize(): ?int;
}
