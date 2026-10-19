<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\RejectedDecisionException;
use MissionGaming\Tactician\Exceptions\RejectedDecisionReason;
use MissionGaming\Tactician\Exceptions\UndecidedSelectionException;
use MissionGaming\Tactician\Serialization\PlainData;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;

/**
 * What the rank selectors do with an order that results do not decide, in
 * one place, so that a selection from one table and a selection across
 * pools refuse and accept the same things.
 *
 * A policy is one of three identifiers, which are the values of the `ties`
 * key of a selector's plain data: refuse every undecided order (the
 * default), accept level entries that are all selected, or accept the
 * order as it stands.
 *
 * @internal Not public API: the contract is stated on RankRangeSelector and
 *           BestOfRankSelector.
 */
final class SelectionTies
{
    public const string REFUSED = SelectionTiePolicy::Refused->value;
    public const string INSIDE_ACCEPTED = SelectionTiePolicy::InsideSelectionAccepted->value;
    public const string ORDER_ACCEPTED = SelectionTiePolicy::TableOrderAccepted->value;

    public const array POLICIES = [self::REFUSED, self::INSIDE_ACCEPTED, self::ORDER_ACCEPTED];

    /**
     * The policy a plain-data value names; the default when it is null.
     *
     * @param string $selector What the data configures, for the message: 'rank range'
     * @return 'refuse'|'accept-inside-selection'|'accept-table-order'
     * @throws InvalidConfigurationException When the value is not a known policy (reason
     *                                       `UnknownIdentifier`)
     */
    public static function policyFrom(mixed $ties, string $selector): string
    {
        $ties ??= self::REFUSED;

        return match ($ties) {
            self::REFUSED => self::REFUSED,
            self::INSIDE_ACCEPTED => self::INSIDE_ACCEPTED,
            self::ORDER_ACCEPTED => self::ORDER_ACCEPTED,
            default => throw new InvalidConfigurationException(
                "Unknown {$selector} tie policy",
                ['ties' => $ties, 'known' => self::POLICIES],
                reason: InvalidConfigurationReason::UnknownIdentifier
            ),
        };
    }

    /**
     * Refuse a table whose order at the ranks from..to results do not
     * decide, unless the policy accepts it.
     *
     * @param bool $played Whether the table has any recorded result
     * @param string|null $pool The label of the pool whose table it is; null for the table of
     *                          the whole outcome
     * @throws UndecidedSelectionException When the table is unplayed, or a tied set holds one of
     *                                     the ranks in a way the policy does not accept
     */
    public static function requireTableDecided(
        Standings $standings,
        bool $played,
        ?string $pool,
        int $from,
        int $to,
        string $ties
    ): void {
        if ($ties === self::ORDER_ACCEPTED) {
            return;
        }

        if (!$played && count($standings) > 1) {
            throw new UndecidedSelectionException(
                array_map(
                    static fn(StandingEntry $entry): string => $entry->getParticipant()->getId(),
                    $standings->getEntries()
                ),
                1,
                count($standings),
                $pool,
                $from,
                $to,
                false
            );
        }

        foreach ($standings->getTiedSets() as $tiedSet) {
            $first = $tiedSet->getFirstPosition();
            $last = $tiedSet->getLastPosition();

            // A set that a decision settled has an order to act on
            if ($last < $from || $first > $to || $tiedSet->getDecision() instanceof Decider) {
                continue;
            }

            $inside = $first >= $from && $last <= $to;
            if ($inside && $ties === self::INSIDE_ACCEPTED) {
                continue;
            }

            throw new UndecidedSelectionException(
                array_map(
                    static fn(Participant $participant): string => $participant->getId(),
                    $tiedSet->getParticipants()
                ),
                $first,
                $last,
                $pool,
                $from,
                $to
            );
        }
    }

    /**
     * Refuse an order across pools in which a group of level entries holds
     * one of the places from..to, unless the policy accepts it.
     *
     * @param list<list<StandingEntry>> $groups The order as CrossPoolRanking::levelGroups() gives
     *                                          it: every group holds as many consecutive places
     *                                          as it has entries
     * @param int|null $rank The rank whose holders in each pool the order is of; null when it is
     *                       an order of every entry of the outcome's table per event played
     * @param array<int, Decider> $decided The groups a decision settled, by group index: they
     *                                     have an order to act on and are not refused
     * @throws UndecidedSelectionException When a group of two or more holds one of the places in
     *                                     a way the policy does not accept
     */
    public static function requireOrderDecided(
        array $groups,
        int $from,
        int $to,
        string $ties,
        ?int $rank,
        array $decided = []
    ): void {
        if ($ties === self::ORDER_ACCEPTED) {
            return;
        }

        $first = 1;
        foreach ($groups as $index => $group) {
            $last = $first + count($group) - 1;

            if (count($group) > 1 && !isset($decided[$index]) && $last >= $from && $first <= $to) {
                $inside = $first >= $from && $last <= $to;
                if (!$inside || $ties !== self::INSIDE_ACCEPTED) {
                    throw new UndecidedSelectionException(
                        array_map(
                            static fn(StandingEntry $entry): string => $entry->getParticipant()->getId(),
                            $group
                        ),
                        $first,
                        $last,
                        null,
                        $from,
                        $to,
                        true,
                        $rank,
                        $rank === null
                    );
                }
            }

            $first = $last + 1;
        }
    }

    /**
     * The decisions a selector was given, checked to be decisions.
     *
     * @param array<array-key, mixed> $decisions
     * @param string $selector What takes them, for the message: 'rank range'
     * @return list<Decider>
     * @throws InvalidConfigurationException When an entry is not a Decider (reason `WrongValueType`)
     */
    public static function decisions(array $decisions, string $selector): array
    {
        $checked = [];
        foreach ($decisions as $decision) {
            if (!$decision instanceof Decider) {
                throw new InvalidConfigurationException(
                    "Each decision of a {$selector} selector must be a Decider",
                    ['decision' => get_debug_type($decision)],
                    reason: InvalidConfigurationReason::WrongValueType
                );
            }
            $checked[] = $decision;
        }

        return $checked;
    }

    /**
     * The decisions a plain-data value holds: a list of decider arrays;
     * none when it is null.
     *
     * @param string $selector What the data configures, for the message: 'rank range'
     * @return list<Decider>
     * @throws InvalidConfigurationException When the value is not a list of arrays, or one of them
     *                                       is not the array of a decider (reason `WrongValueType`;
     *                                       the previous exception says what is wrong with it)
     */
    public static function decisionsFrom(mixed $decisions, string $selector): array
    {
        if ($decisions === null) {
            return [];
        }

        if (!is_array($decisions) || !array_is_list($decisions)) {
            throw new InvalidConfigurationException(
                "The decisions of a {$selector} selector must be a list",
                ['decisions' => get_debug_type($decisions)],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        $read = [];
        foreach ($decisions as $index => $decision) {
            try {
                if (!is_array($decision)) {
                    throw new InvalidInputException('A decision must be an array, ' . get_debug_type($decision) . ' given');
                }
                $read[] = Decider::fromArray($decision);
            } catch (InvalidInputException $invalid) {
                throw new InvalidConfigurationException(
                    "Decision {$index} of a {$selector} selector cannot be read: {$invalid->getMessage()}",
                    ['index' => $index],
                    previous: $invalid,
                    reason: InvalidConfigurationReason::WrongValueType
                );
            }
        }

        return $read;
    }

    /**
     * The decisions as a selector writes them in its plain data: each as
     * the array of a decider, without a format version of its own.
     *
     * @param list<Decider> $decisions
     * @return list<array{participants: list<string>, label: string|null, reason: string|null}>
     */
    public static function decisionsData(array $decisions): array
    {
        return array_map(static function (Decider $decision): array {
            $data = $decision->toArray();
            unset($data[PlainData::VERSION_KEY]);

            return $data;
        }, $decisions);
    }

    /**
     * The decisions sorted by the pool whose table each is for: the pool
     * that every participant it names has an entry in.
     *
     * @param list<Decider> $decisions
     * @param int|null $rank The rank of a best-of-rank selection, which also orders the holders
     *                       of that rank across pools; null for a selection that reads the
     *                       pools' tables and nothing else
     * @return array{pools: array<string, list<Decider>>, across: list<Decider>} By pool label;
     *                                                                           and the decisions
     *                                                                           that name
     *                                                                           participants of
     *                                                                           more than one pool
     * @throws RejectedDecisionException When a decision names a participant no pool has an entry
     *                                   for (reason `UnknownParticipant`), or participants of
     *                                   more than one pool where no order across pools is read
     *                                   (`ParticipantsNotLevel`)
     */
    public static function byPool(StageOutcome $outcome, array $decisions, ?int $rank): array
    {
        $sorted = ['pools' => [], 'across' => []];
        if ($decisions === []) {
            return $sorted;
        }

        $poolOf = [];
        foreach ($outcome->getPools() as $label => $poolOutcome) {
            foreach ($poolOutcome->getStandings()->getEntries() as $entry) {
                $poolOf[$entry->getParticipant()->getId()] ??= (string) $label;
            }
        }

        // Every decision is held to the first rule before any is held to the
        // next, as DecidedOrder does it: the reason does not depend on the
        // order the decisions are given in
        foreach ($decisions as $decision) {
            $unknown = array_values(array_filter(
                $decision->getParticipantIds(),
                static fn(string $id): bool => !isset($poolOf[$id])
            ));
            if ($unknown !== []) {
                throw new RejectedDecisionException(RejectedDecisionReason::UnknownParticipant, $decision, $unknown);
            }
        }

        foreach ($decisions as $decision) {
            $ids = $decision->getParticipantIds();
            $pool = $poolOf[$ids[0]];
            $elsewhere = array_values(array_filter($ids, static fn(string $id): bool => $poolOf[$id] !== $pool));
            if ($elsewhere === []) {
                $sorted['pools'][$pool][] = $decision;
            } elseif ($rank !== null) {
                $sorted['across'][] = $decision;
            } else {
                // Entrants of different pools are in no table this selection
                // reads together, so nothing can leave them level
                throw new RejectedDecisionException(RejectedDecisionReason::ParticipantsNotLevel, $decision, $elsewhere, $pool);
            }
        }

        return $sorted;
    }

    /**
     * A pool's table with the decisions for it applied.
     *
     * @param list<Decider> $decisions
     * @throws RejectedDecisionException When a decision is rejected; the exception names the pool
     */
    public static function decidedTable(Standings $standings, array $decisions, string $pool): Standings
    {
        try {
            return $standings->withDecisions($decisions);
        } catch (RejectedDecisionException $rejected) {
            throw new RejectedDecisionException(
                $rejected->getReason(),
                $rejected->getDecider(),
                $rejected->getParticipantIds(),
                $pool,
                previous: $rejected
            );
        }
    }

    /**
     * For each of the participants, by ID, the decision that settled its
     * place in the table; a participant that results placed has none.
     *
     * @param array<Participant> $participants
     * @return array<string, list<Decider>>
     */
    public static function marksIn(Standings $standings, array $participants): array
    {
        $marks = [];
        if ($standings->getDecisions() === []) {
            return $marks;
        }

        foreach ($participants as $participant) {
            $decision = $standings->getDecisionFor($participant);
            if ($decision instanceof Decider) {
                $marks[$participant->getId()] = [$decision];
            }
        }

        return $marks;
    }
}
