<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

/**
 * Thrown when a rank selection depends on an order that the results do not
 * decide.
 *
 * A standings table gives every entry a place of its own, also where no
 * result separates two entries. A selection that cuts such a table at a
 * rank, or seeds a next stage from the order inside it, would act on an
 * order that reflects no result. The selector refuses unless it was told
 * to go on (see `RankRangeSelector`), and this exception says what is
 * undecided: which participants are level, which places they hold between
 * them, and in which pool.
 *
 * The reason says which of three cases it is:
 *
 * - {@see InvalidConfigurationReason::UnplayedStage}: the table has no
 *   recorded result at all, so every place in it is undecided;
 * - {@see InvalidConfigurationReason::TieAcrossSelection}: the level
 *   participants hold places on both sides of a boundary of the selection,
 *   so the results do not say who is selected;
 * - {@see InvalidConfigurationReason::TieWithinSelection}: the level
 *   participants are all selected, and only the order they are selected in
 *   is undecided.
 *
 * It is an {@see InvalidConfigurationException}, so a catch clause written
 * for that class still matches. Its context holds the same values as the
 * accessors, under the keys `participants`, `first_place`, `last_place`,
 * `pool`, `from` and `to`.
 *
 * @api
 */
final class UndecidedSelectionException extends InvalidConfigurationException
{
    /** @var list<string> */
    private readonly array $participantIds;

    /**
     * @param array<string> $participantIds The IDs of the level participants, in table order
     * @param int $firstPlace The first of the places they hold between them (1-based)
     * @param int $lastPlace The last of those places
     * @param string|null $pool The label of the pool whose table it is; null for the table of
     *                          the whole outcome
     * @param int $from The first selected rank
     * @param int $to The last selected rank
     * @param bool $played Whether the table has any recorded result
     */
    public function __construct(
        array $participantIds,
        private readonly int $firstPlace,
        private readonly int $lastPlace,
        private readonly ?string $pool,
        private readonly int $from,
        private readonly int $to,
        bool $played = true
    ) {
        $this->participantIds = array_values($participantIds);

        $where = $pool === null ? 'the standings' : "pool {$pool}";
        $who = implode(', ', $this->participantIds);
        $across = $firstPlace < $from || $lastPlace > $to;

        parent::__construct(
            match (true) {
                !$played => "No result is recorded in {$where}, so no place in it is decided"
                    . " (places {$firstPlace} to {$lastPlace}: {$who})",
                $across => "No result separates the participants in places {$firstPlace} to {$lastPlace} of {$where}"
                    . " ({$who}), and ranks {$from} to {$to} take only some of them",
                default => "No result separates the participants in places {$firstPlace} to {$lastPlace} of {$where}"
                    . " ({$who}), so the order they are selected in is not decided",
            },
            [
                'participants' => $this->participantIds,
                'first_place' => $firstPlace,
                'last_place' => $lastPlace,
                'pool' => $pool,
                'from' => $from,
                'to' => $to,
            ],
            reason: match (true) {
                !$played => InvalidConfigurationReason::UnplayedStage,
                $across => InvalidConfigurationReason::TieAcrossSelection,
                default => InvalidConfigurationReason::TieWithinSelection,
            }
        );
    }

    /**
     * The IDs of the participants no result separates, in table order.
     *
     * @return list<string>
     */
    public function getParticipantIds(): array
    {
        return $this->participantIds;
    }

    /**
     * The first of the places the level participants hold between them
     * (1-based).
     */
    public function getFirstPlace(): int
    {
        return $this->firstPlace;
    }

    /**
     * The last of the places the level participants hold between them.
     */
    public function getLastPlace(): int
    {
        return $this->lastPlace;
    }

    /**
     * The label of the pool whose table holds the tie, or null when it is
     * the table of the whole outcome.
     */
    public function getPool(): ?string
    {
        return $this->pool;
    }

    /**
     * The first rank the selection asked for.
     */
    public function getFrom(): int
    {
        return $this->from;
    }

    /**
     * The last rank the selection asked for.
     */
    public function getTo(): int
    {
        return $this->to;
    }
}
