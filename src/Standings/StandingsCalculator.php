<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Stage\PairKey;

/**
 * Calculates an ordered standings table from recorded results.
 *
 * Entries are ordered, best first, by the ranking strategy's primary value
 * (higher first), then by each configured tiebreaker in order (higher
 * first), then by score difference and by scores-for (higher first). What
 * is still level after that is ordered by a fallback that reflects no
 * result: seed (lower first, unseeded participants last), then label
 * (natural order, ignoring case), then participant ID.
 * Standings::getTiedSets() names the entries only the fallback separates.
 *
 * The fallback is there so that no two entries compare equal and the order
 * the participants are given in does not show in the table.
 *
 * Known limitation, not a rule to rely on: the ID step does not achieve
 * that for every set of IDs. IDs are compared as PHP's `<=>` compares
 * strings, which reads two numeric strings as numbers. IDs such as '1' and
 * '01' therefore compare equal, and the comparison is not transitive where
 * numeric and other IDs are mixed ('2' is below '10', '10' below '1a' and
 * '1a' below '2'). Participants that have such IDs and are level on
 * everything before the ID, label included, can stand in an order that
 * depends on the order they were given in.
 *
 * @experimental
 */
readonly class StandingsCalculator
{
    /**
     * @param RankingStrategy $rankingStrategy Computes the primary ranking value
     *                                         (default: 3/1/0 win-draw-loss points)
     * @param array<TiebreakerInterface> $tiebreakers Applied in order after the primary ranking value
     */
    public function __construct(
        private RankingStrategy $rankingStrategy = new WinDrawLossRanking(),
        private array $tiebreakers = []
    ) {}

    /**
     * The strategy that computes each entry's primary ranking value.
     */
    public function getRankingStrategy(): RankingStrategy
    {
        return $this->rankingStrategy;
    }

    /**
     * The tiebreakers, in the order they are applied.
     *
     * @return array<TiebreakerInterface>
     */
    public function getTiebreakers(): array
    {
        return $this->tiebreakers;
    }

    /**
     * Calculate the table of the given participants from the given results.
     *
     * The table has one entry per participant ID, in the order the class
     * describes. For each participant, over the results whose event it is
     * in: played counts them, a result that names it the winner is a win, a
     * result with no winner is a draw and any other is a loss (in an event
     * of three, both who did not win have a loss); scores-for adds its own
     * recorded scores and scores-against those of every other participant
     * of the event. A bye is not a result and is counted nowhere.
     *
     * A participant without results has a zeroed record and is placed by it
     * like any other, not last: it stands above a participant whose ranking
     * value is below zero, or whose ranking value is zero with a score
     * difference below zero.
     *
     * An event has one result. Two results are of one event, and are
     * refused, when they hold the same Event object, or when their events
     * carry the same round number, the same participants in any order and
     * the same tie leg (the `tie_leg` metadata of a two-legged tie; an
     * event without it is leg 1). The second rule is what holds after
     * storage: results read back from JSON hold Event objects of their
     * own, so a result recorded twice is two objects and one match. An
     * event with no round has nothing but the object to tell it from
     * another meeting of the same participants, so two such events are
     * two events played. Events of one round between the same participants
     * that are in fact two events need a `tie_leg` each to be counted
     * apart, and results of two schedules or stages that number their
     * rounds alike (two seasons, two stages that each count from 1) are
     * to be given one table each, or round numbers that continue; the
     * message of the exception says so.
     *
     * @param array<Participant> $participants Everyone the table lists, in any order. Of two
     *                                         participants with the same ID the later is kept
     * @param array<Result> $results The recorded results, in any order
     *
     * @throws InvalidInputException When a result's event has a participant who is not
     *                               among the given ones, or two results are of one event
     */
    public function calculate(array $participants, array $results): Standings
    {
        $seenEvents = [];
        $seenMatches = [];
        foreach ($results as $result) {
            $event = $result->getEvent();

            // The object does not survive storage: two results of one match
            // read back from JSON hold two Event objects that are equal.
            // What does survive is what the event says about itself, so a
            // result recorded twice is refused in the same words before and
            // after the state was stored.
            $eventId = spl_object_id($event);
            $match = self::matchOf($event);
            if (isset($seenEvents[$eventId]) || ($match !== null && isset($seenMatches[$match]))) {
                $round = $event->getRound()?->getNumber();

                // The same words whether the two results hold one object or
                // two: the hint is for the caller whose two events are in
                // fact two, which the calculator cannot tell from one.
                throw new InvalidInputException(
                    'Two results reference the same event; each event can have only one result'
                    . ($round === null ? '' : sprintf(
                        ' (round %d, %s). Events with the same round number, participants and tie leg are one event:'
                        . ' if these are two (one table over two stages or seasons, or two meetings in one round),'
                        . " calculate a table for each, continue the round numbers, or give each event a 'tie_leg'",
                        $round,
                        implode(' vs ', array_map(
                            fn(Participant $participant): string => $participant->getId(),
                            $event->getParticipants()
                        ))
                    ))
                );
            }

            $seenEvents[$eventId] = true;
            if ($match !== null) {
                $seenMatches[$match] = true;
            }
        }

        /** @var array<string, Participant> $participantsById */
        $participantsById = [];
        /** @var array<string, array<Result>> $resultsByParticipant */
        $resultsByParticipant = [];
        /** @var array<string, array{played: int, wins: int, draws: int, losses: int, for: float, against: float}> $tallies */
        $tallies = [];

        foreach ($participants as $participant) {
            $participantsById[$participant->getId()] = $participant;
            $tallies[$participant->getId()] = [
                'played' => 0,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
                'for' => 0.0,
                'against' => 0.0,
            ];
        }

        foreach ($results as $result) {
            $eventParticipants = $result->getEvent()->getParticipants();

            foreach ($eventParticipants as $participant) {
                $id = $participant->getId();
                if (!isset($tallies[$id])) {
                    throw new InvalidInputException(
                        "Result references participant {$id} who is not in the standings"
                    );
                }

                $resultsByParticipant[$id][] = $result;
                ++$tallies[$id]['played'];
                if ($result->isDraw()) {
                    ++$tallies[$id]['draws'];
                } elseif ($result->isWinFor($participant)) {
                    ++$tallies[$id]['wins'];
                } else {
                    ++$tallies[$id]['losses'];
                }

                $ownScore = $result->getScoreFor($participant);
                if ($ownScore !== null) {
                    $tallies[$id]['for'] += (float) $ownScore;
                }

                foreach ($eventParticipants as $opponent) {
                    if ($opponent->getId() === $id) {
                        continue;
                    }
                    $opponentScore = $result->getScoreFor($opponent);
                    if ($opponentScore !== null) {
                        $tallies[$id]['against'] += (float) $opponentScore;
                    }
                }
            }
        }

        /** @var array<string, StandingEntry> $entries */
        $entries = [];
        foreach ($tallies as $id => $tally) {
            $rankingValue = $this->rankingStrategy->rank(
                $participantsById[$id],
                $resultsByParticipant[$id] ?? []
            );

            $entries[$id] = new StandingEntry(
                $participantsById[$id],
                $tally['played'],
                $tally['wins'],
                $tally['draws'],
                $tally['losses'],
                $rankingValue,
                $tally['for'],
                $tally['against']
            );
        }

        if ($this->tiebreakers !== []) {
            $baseEntries = $entries;
            foreach ($entries as $id => $entry) {
                $values = [];
                foreach ($this->tiebreakers as $tiebreaker) {
                    $values[$tiebreaker->getName()] = $tiebreaker->calculate(
                        $participantsById[$id],
                        $results,
                        $baseEntries
                    );
                }
                $entries[$id] = $entry->withTiebreakers($values);
            }
        }

        $ordered = array_values($entries);
        usort($ordered, function (StandingEntry $a, StandingEntry $b): int {
            $comparison = $b->getRankingValue() <=> $a->getRankingValue();
            if ($comparison !== 0) {
                return $comparison;
            }

            foreach ($this->tiebreakers as $tiebreaker) {
                $name = $tiebreaker->getName();
                $comparison = ($b->getTiebreakerValue($name) ?? 0.0) <=> ($a->getTiebreakerValue($name) ?? 0.0);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            $comparison = $b->getScoreDifference() <=> $a->getScoreDifference();
            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = $b->getScoreFor() <=> $a->getScoreFor();
            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = ($a->getParticipant()->getSeed() ?? PHP_INT_MAX) <=> ($b->getParticipant()->getSeed() ?? PHP_INT_MAX);
            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = strnatcasecmp($a->getParticipant()->getLabel(), $b->getParticipant()->getLabel());
            if ($comparison !== 0) {
                return $comparison;
            }

            return $a->getParticipant()->getId() <=> $b->getParticipant()->getId();
        });

        return new Standings($ordered);
    }

    /**
     * What identifies the match an event is, where the event says enough
     * to identify one: its round number, its participants in any order and
     * its tie leg. Null for an event with no round, which nothing but the
     * object tells from another meeting of the same participants.
     *
     * The same three things identify an event to StageState and to the
     * bracket engines, so a result is one match to all of them.
     */
    private static function matchOf(Event $event): ?string
    {
        $round = $event->getRound()?->getNumber();
        if ($round === null) {
            return null;
        }

        $ids = array_map(fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
        $leg = $event->getMetadataValue('tie_leg');

        return $round . ':' . PairKey::of(...array_values($ids)) . ':' . (is_int($leg) ? $leg : 1);
    }
}
