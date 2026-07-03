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
 */
interface ProgressionSelector
{
    /**
     * Select and order the progressing participants from an outcome.
     *
     * @return array<Participant> Ordered best first; position determines destination seeding
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When the outcome cannot satisfy this selection
     */
    public function select(StageOutcome $outcome): array;

    /**
     * How many participants this selector yields, when knowable without
     * the outcome — used by ahead-of-time composition validation. Null
     * means the count depends on the outcome (per-pool selections, round
     * sizes).
     */
    public function getSelectionSize(): ?int;
}
