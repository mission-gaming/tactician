<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use Closure;
use Generator;
use MissionGaming\Tactician\DTO\Event;

/**
 * Says whether a round robin of several legs exists that keeps a rule about
 * roles, by trying every choice there is.
 *
 * It is the reference the scheduler's role searches are compared with, so
 * it shares nothing with them: no constraint class, no scheduling context,
 * no leg strategy, no search order and no bound. An event here is three
 * values, `[round, first-named ID, second-named ID]`, and a rule is a
 * closure that says whether a list of such events, in the order they are
 * generated, breaks it.
 *
 * Every rule built here is closed under prefixes: a list that breaks one
 * still breaks it when events are added. A schedule that every event was
 * accepted into, one after the other, is therefore exactly a schedule the
 * rule does not break, which is what the library's constraints promise
 * (docs/USAGE.md, "What a constraint may rely on"); and the enumeration may
 * give up a choice as soon as the events so far break the rule, without
 * missing a schedule.
 *
 * What a leg strategy leaves free is stated here in plain terms, as the
 * enumeration: a later leg has the pairings of the first in the same rounds
 * of the leg, and each of its events has
 *
 * - `mirrored`: the roles of its first-leg event, reversed in the
 *   even-numbered legs;
 * - `repeated`: the roles of its first-leg event;
 * - `shuffled`: either role.
 */
final class RoleScheduleOracle
{
    /**
     * Whether roles exist for the legs after the first, and for the first
     * leg too when its roles are free, so that the whole schedule keeps the
     * rule.
     *
     * @param list<array{int, string, string}> $legOne The first leg in generation order, in the roles it
     *                                                 has when they are not free
     * @param 'mirrored'|'repeated'|'shuffled' $strategy
     * @param bool $legOneRolesFree Whether each event of the first leg may be reversed
     * @param Closure(list<array{int, string, string}>): bool $breaks
     */
    public static function rolesExist(
        array $legOne,
        int $roundsPerLeg,
        int $legs,
        string $strategy,
        bool $legOneRolesFree,
        Closure $breaks
    ): bool {
        return self::extend([], $legOne, $roundsPerLeg, $legs, $strategy, $legOneRolesFree, $breaks);
    }

    /**
     * @param list<array{int, string, string}> $placed
     * @param list<array{int, string, string}> $legOne
     * @param Closure(list<array{int, string, string}>): bool $breaks
     */
    private static function extend(
        array $placed,
        array $legOne,
        int $roundsPerLeg,
        int $legs,
        string $strategy,
        bool $legOneRolesFree,
        Closure $breaks
    ): bool {
        $perLeg = count($legOne);
        $position = count($placed);
        if ($position === $perLeg * $legs) {
            return true;
        }

        $leg = intdiv($position, $perLeg) + 1;
        $source = $position % $perLeg;

        if ($leg === 1) {
            [$round, $first, $second] = $legOne[$source];
            $choices = $legOneRolesFree ? [[$first, $second], [$second, $first]] : [[$first, $second]];
        } else {
            // The first-leg event of the pairing, in the roles chosen for it
            [$legRound, $first, $second] = $placed[$source];
            $round = $legRound + ($leg - 1) * $roundsPerLeg;
            $choices = [[$first, $second], [$second, $first]];
            if ($strategy === 'mirrored') {
                $choices = $leg % 2 === 0 ? [[$second, $first]] : [[$first, $second]];
            } elseif ($strategy === 'repeated') {
                $choices = [[$first, $second]];
            }
        }

        foreach ($choices as [$chosenFirst, $chosenSecond]) {
            $next = [...$placed, [$round, $chosenFirst, $chosenSecond]];
            if ($breaks($next)) {
                continue;
            }
            if (self::extend($next, $legOne, $roundsPerLeg, $legs, $strategy, $legOneRolesFree, $breaks)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every way to play one leg of the field: every split of its pairings
     * into rounds, in every order of the rounds. In a field of odd size a
     * round leaves one participant out.
     *
     * Each leg is listed round by round. The order of the events inside a
     * round, and which participant of a pairing is listed first, are fixed
     * and mean nothing: no rule here reads the first, and the caller frees
     * the second.
     *
     * @param list<string> $ids
     * @return Generator<int, list<array{int, string, string}>>
     */
    public static function everyLeg(array $ids): Generator
    {
        $seats = $ids;
        if (count($seats) % 2 === 1) {
            $seats[] = null;
        }

        yield from self::rounds($seats, count($seats) - 1, [], []);
    }

    /**
     * @param list<string|null> $seats
     * @param array<string, true> $used Pairings played so far, the bye seat included
     * @param list<array{int, string, string}> $events
     * @return Generator<int, list<array{int, string, string}>>
     */
    private static function rounds(array $seats, int $roundCount, array $used, array $events): Generator
    {
        $round = intdiv(count($used), intdiv(count($seats), 2)) + 1;
        if ($round > $roundCount) {
            yield $events;

            return;
        }

        foreach (self::matchings($seats, $used) as $matching) {
            $nextUsed = $used;
            $nextEvents = $events;
            foreach ($matching as [$one, $other]) {
                $nextUsed[self::seatKey($one, $other)] = true;
                if ($one !== null && $other !== null) {
                    $nextEvents[] = [$round, $one, $other];
                }
            }

            yield from self::rounds($seats, $roundCount, $nextUsed, $nextEvents);
        }
    }

    /**
     * Every way to pair all the seats off without a pairing already played.
     *
     * @param list<string|null> $seats
     * @param array<string, true> $used
     * @return Generator<int, list<array{string|null, string|null}>>
     */
    private static function matchings(array $seats, array $used): Generator
    {
        if ($seats === []) {
            yield [];

            return;
        }

        $one = array_shift($seats);
        foreach ($seats as $index => $other) {
            if (isset($used[self::seatKey($one, $other)])) {
                continue;
            }

            $rest = $seats;
            unset($rest[$index]);

            foreach (self::matchings(array_values($rest), $used) as $matching) {
                yield [[$one, $other], ...$matching];
            }
        }
    }

    private static function seatKey(?string $one, ?string $other): string
    {
        $pair = [$one ?? '(bye)', $other ?? '(bye)'];
        sort($pair, SORT_STRING);

        return implode(' + ', $pair);
    }

    /**
     * The events of a schedule as the oracle reads them.
     *
     * @param iterable<Event> $events
     * @return list<array{int, string, string}>
     */
    public static function read(iterable $events): array
    {
        $read = [];
        foreach ($events as $event) {
            $pair = $event->getParticipants();
            $read[] = [$event->getRound()?->getNumber() ?? 0, $pair[0]->getId(), $pair[1]->getId()];
        }

        return $read;
    }

    /**
     * Broken when a participant plays more than $limit events in a row in
     * the same role. A round it sits out neither counts nor ends a run.
     *
     * @return Closure(list<array{int, string, string}>): bool
     */
    public static function streakRule(int $limit): Closure
    {
        return static function (array $events) use ($limit): bool {
            $lastRole = [];
            $run = [];
            foreach ($events as [, $first, $second]) {
                foreach ([$first, $second] as $role => $id) {
                    $run[$id] = ($lastRole[$id] ?? null) === $role ? ($run[$id] ?? 0) + 1 : 1;
                    $lastRole[$id] = $role;
                    if ($run[$id] > $limit) {
                        return true;
                    }
                }
            }

            return false;
        };
    }

    /**
     * Broken when, after any event, a participant has played more than
     * $limit more events in one role than in the other.
     *
     * @return Closure(list<array{int, string, string}>): bool
     */
    public static function balanceRule(int $limit): Closure
    {
        return static function (array $events) use ($limit): bool {
            $difference = [];
            foreach ($events as [, $first, $second]) {
                $difference[$first] = ($difference[$first] ?? 0) + 1;
                $difference[$second] = ($difference[$second] ?? 0) - 1;
                if (abs($difference[$first]) > $limit || abs($difference[$second]) > $limit) {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * Broken when $second is first-named in a meeting with $first that is
     * in one of the given rounds.
     *
     * @param list<int> $rounds
     * @return Closure(list<array{int, string, string}>): bool
     */
    public static function fixedRoleRule(string $first, string $second, array $rounds): Closure
    {
        return static function (array $events) use ($first, $second, $rounds): bool {
            foreach ($events as [$round, $eventFirst, $eventSecond]) {
                if ($eventFirst === $second && $eventSecond === $first && in_array($round, $rounds, true)) {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * Broken when $second is first-named in any meeting with $first, in
     * whichever round and leg it is played.
     *
     * @return Closure(list<array{int, string, string}>): bool
     */
    public static function pinnedRule(string $first, string $second): Closure
    {
        return static function (array $events) use ($first, $second): bool {
            foreach ($events as [, $eventFirst, $eventSecond]) {
                if ($eventFirst === $second && $eventSecond === $first) {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * Broken when the first leg cannot end balanced any more: a participant
     * has one role in it more often than half the events of a leg, rounded
     * up. A leg of a round robin gives each of $participants one event
     * against every other, so a whole first leg that does not break this
     * has every participant's two role counts at most 1 apart, and 0 apart
     * in a field of odd size.
     *
     * This is what the balanced role assignment promises of the leg it
     * decides, stated as counting and nothing else.
     *
     * @return Closure(list<array{int, string, string}>): bool
     */
    public static function balancedFirstLegRule(int $participants, int $roundsPerLeg): Closure
    {
        $most = intdiv($participants, 2);

        return static function (array $events) use ($most, $roundsPerLeg): bool {
            $firstNamed = [];
            $secondNamed = [];
            foreach ($events as [$round, $first, $second]) {
                if ($round > $roundsPerLeg) {
                    continue;
                }
                $firstNamed[$first] = ($firstNamed[$first] ?? 0) + 1;
                $secondNamed[$second] = ($secondNamed[$second] ?? 0) + 1;
                if ($firstNamed[$first] > $most || $secondNamed[$second] > $most) {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * Broken when any of the rules is.
     *
     * @param Closure(list<array{int, string, string}>): bool ...$rules
     * @return Closure(list<array{int, string, string}>): bool
     */
    public static function every(Closure ...$rules): Closure
    {
        return static fn(array $events): bool => array_any($rules, fn($rule) => $rule(array_values($events)));
    }
}
