<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

use MissionGaming\Tactician\DTO\Decider;

/**
 * Thrown when a decision that orders level entries cannot be applied.
 *
 * A decision (a `DTO\Decider`: participant IDs, best first) settles the
 * order of one set of entries that results leave level: a tied set of a
 * standings table, or the level entrants of a comparison across pools. It
 * never overrides a result. A decision that names anything else is
 * rejected whole, and nothing is reordered: the reason
 * ({@see self::getReason()}) says which rule it broke, and the accessors
 * say which decision, which participants are at fault and where.
 *
 * It extends `\InvalidArgumentException`, as {@see InvalidInputException}
 * does: a decision is data the application supplies, and one that does not
 * fit the table is unacceptable input, not a configuration that cannot
 * work.
 *
 * @api
 */
final class RejectedDecisionException extends \InvalidArgumentException implements TacticianException
{
    /** @var list<string> */
    private readonly array $participantIds;

    /**
     * @param RejectedDecisionReason $reason Which rule the decision broke
     * @param Decider $decider The decision that was rejected
     * @param array<string> $participantIds The IDs at fault (see getParticipantIds())
     * @param string|null $pool The label of the pool whose table the decision was applied to;
     *                          null for a table that is not read as a pool's, and for an
     *                          order across pools
     * @param int|null $rankAcrossPools The rank whose holders in every pool the decision was to
     *                                  order; null when it was applied to one table
     */
    public function __construct(
        private readonly RejectedDecisionReason $reason,
        private readonly Decider $decider,
        array $participantIds,
        private readonly ?string $pool = null,
        private readonly ?int $rankAcrossPools = null,
        ?\Throwable $previous = null
    ) {
        $this->participantIds = array_values($participantIds);

        $where = match (true) {
            $rankAcrossPools !== null => "the entrants placed {$rankAcrossPools} in their pools",
            $pool === null => 'the standings',
            default => "pool {$pool}",
        };
        $ordered = implode(', ', $decider->getParticipantIds());
        $atFault = implode(', ', $this->participantIds);

        parent::__construct(match ($reason) {
            RejectedDecisionReason::UnknownParticipant => "The decision for {$ordered} names a participant with no entry in {$where}: {$atFault}",
            RejectedDecisionReason::SetAlreadyDecided => "More than one decision names {$atFault} in {$where}: a set of level entries takes one decision",
            RejectedDecisionReason::ParticipantsNotLevel => "The decision for {$ordered} does not order one set of level entries of {$where}"
                . " (not level with the others it names: {$atFault}), and a decision never moves an entry that results place",
            RejectedDecisionReason::PartOfLevelSet => "The decision for {$ordered} orders only part of a set of level entries of {$where}:"
                . " it leaves out {$atFault}, and a decision orders the whole set",
            RejectedDecisionReason::Stale => "The table carries a decision for {$ordered} that no longer orders one whole set of level entries"
                . " ({$atFault}): calculate the table again and apply the decisions last",
        }, 0, $previous);
    }

    /**
     * Which rule the decision broke.
     */
    public function getReason(): RejectedDecisionReason
    {
        return $this->reason;
    }

    /**
     * The decision that was rejected, as it was given.
     */
    public function getDecider(): Decider
    {
        return $this->decider;
    }

    /**
     * The IDs at fault, which depend on the reason: the IDs with no entry
     * (`UnknownParticipant`); the IDs another decision names as well
     * (`SetAlreadyDecided`); the IDs of the decision that are not level
     * with the first it names, or that first ID when it names no other
     * (`ParticipantsNotLevel`); the members of the set the decision leaves
     * out, in table order (`PartOfLevelSet`); and for `Stale`, what the
     * rejection of the decision against the changed table lists.
     *
     * @return list<string>
     */
    public function getParticipantIds(): array
    {
        return $this->participantIds;
    }

    /**
     * The label of the pool whose table the decision was applied to, when
     * a selector applied it to a pool's table; otherwise null.
     */
    public function getPool(): ?string
    {
        return $this->pool;
    }

    /**
     * The rank whose holders in every pool the decision was to order, when
     * a best-of-rank selection applied it to its comparison across pools;
     * otherwise null.
     */
    public function getRankAcrossPools(): ?int
    {
        return $this->rankAcrossPools;
    }
}
