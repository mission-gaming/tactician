<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\Exceptions\RejectedDecisionException;
use MissionGaming\Tactician\Exceptions\RejectedDecisionReason;

/**
 * Decisions applied to an order that results leave partly open, in one
 * place, so that a standings table and a comparison across pools accept
 * and reject the same decisions.
 *
 * The order is given as groups of level entries, best first: a group of
 * one is an entry that results place. A decision orders exactly one group
 * of two or more, whole. It moves entries inside that group and nowhere
 * else, so no entry passes one it is not level with.
 *
 * @internal Not public API: the contract is stated on
 *           Standings::withDecisions() and on the rank selectors.
 */
final class DecidedOrder
{
    /**
     * The groups with every decided group in the order of its decision,
     * and the decision of each decided group.
     *
     * The result does not depend on the order the decisions are given in,
     * and neither does the reason for a rejection: the rules are held to
     * every decision one rule at a time, in the order of the cases of
     * RejectedDecisionReason, so a list in which one decision names an
     * unknown participant is rejected for that whichever decision comes
     * first. Nothing is applied when a decision is rejected.
     *
     * @param list<list<StandingEntry>> $groups The order as groups of level entries, best first
     * @param array<Decider> $decisions One for each group to settle
     * @param string|null $pool The label of the pool whose table the order is, for the exception
     * @param int|null $rankAcrossPools The rank whose holders the order is of, for the exception
     * @return array{groups: list<list<StandingEntry>>, decided: array<int, Decider>} The decided
     *                                                                                groups by
     *                                                                                group index
     * @throws RejectedDecisionException When a decision does not order one whole group of two or
     *                                   more, or two decisions name one participant
     */
    public static function apply(array $groups, array $decisions, ?string $pool = null, ?int $rankAcrossPools = null): array
    {
        $groupOf = [];
        foreach ($groups as $index => $group) {
            foreach ($group as $entry) {
                $groupOf[$entry->getParticipant()->getId()] = $index;
            }
        }

        // One rule at a time over every decision, so that the reason reported
        // is the first rule any of them breaks, whatever order they are in
        foreach ($decisions as $decision) {
            $unknown = array_values(array_filter(
                $decision->getParticipantIds(),
                static fn(string $id): bool => !isset($groupOf[$id])
            ));
            if ($unknown !== []) {
                throw new RejectedDecisionException(RejectedDecisionReason::UnknownParticipant, $decision, $unknown, $pool, $rankAcrossPools);
            }
        }

        $named = [];
        foreach ($decisions as $decision) {
            $ids = $decision->getParticipantIds();

            $repeated = array_values(array_filter($ids, static fn(string $id): bool => isset($named[$id])));
            if ($repeated !== []) {
                throw new RejectedDecisionException(RejectedDecisionReason::SetAlreadyDecided, $decision, $repeated, $pool, $rankAcrossPools);
            }
            foreach ($ids as $id) {
                $named[$id] = true;
            }
        }

        foreach ($decisions as $decision) {
            $ids = $decision->getParticipantIds();

            $index = $groupOf[$ids[0]];
            $apart = array_values(array_filter($ids, static fn(string $id): bool => $groupOf[$id] !== $index));
            if ($apart !== [] || count($groups[$index]) < 2) {
                throw new RejectedDecisionException(
                    RejectedDecisionReason::ParticipantsNotLevel,
                    $decision,
                    $apart === [] ? [$ids[0]] : $apart,
                    $pool,
                    $rankAcrossPools
                );
            }
        }

        $decided = [];
        foreach ($decisions as $decision) {
            $ids = $decision->getParticipantIds();
            $index = $groupOf[$ids[0]];

            $members = [];
            foreach ($groups[$index] as $entry) {
                $members[$entry->getParticipant()->getId()] = $entry;
            }
            $leftOut = array_values(array_diff(array_map(strval(...), array_keys($members)), $ids));
            if ($leftOut !== []) {
                throw new RejectedDecisionException(RejectedDecisionReason::PartOfLevelSet, $decision, $leftOut, $pool, $rankAcrossPools);
            }

            $decided[$index] = $decision;
        }

        foreach ($decided as $index => $decision) {
            $members = [];
            foreach ($groups[$index] as $entry) {
                $members[$entry->getParticipant()->getId()] = $entry;
            }

            $groups[$index] = array_map(
                static fn(string $id): StandingEntry => $members[$id],
                $decision->getParticipantIds()
            );
        }
        ksort($decided);

        return ['groups' => $groups, 'decided' => $decided];
    }
}
