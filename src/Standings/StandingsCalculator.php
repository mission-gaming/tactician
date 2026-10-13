<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;

/**
 * Calculates an ordered standings table from recorded results.
 *
 * Entries are ordered, best first, by the ranking strategy's primary value
 * (higher first), then by each configured tiebreaker in order (higher
 * first), then by score difference and by scores-for (higher first). What
 * is still level after that is ordered by a fallback that reflects no
 * result: seed (lower first, unseeded participants last), then participant
 * ID. Standings::getTiedSets() names the entries only the fallback
 * separates.
 *
 * The fallback is there so that no two entries compare equal and the order
 * the participants are given in does not show in the table: a table
 * calculated on its own is the same for every order of its participants
 * array. A label is never compared. IDs are ordered as a total order over
 * every string: an ID written as a whole number without a sign or a leading
 * zero comes first, in the order of the values ('9' before '10'), and every
 * other ID after those, byte by byte ('10' before '01', 'p10' before 'p2').
 *
 * That fallback is for a table that stands alone. Where the participants
 * are the entrant list of a stage, the position in that list decides
 * between level entries, and neither the seed attribute nor the ID does:
 * the engines order their tables that way, and
 * Standings::withTiedSetsOrderedBy() does it for a table of your own
 * (docs/adr/0004-position-decides-where-results-do-not.md).
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
     * Results are told apart by their event object: two results for one
     * Event instance are refused, and two results for two equal Event
     * instances are two events played.
     *
     * @param array<Participant> $participants Everyone the table lists, in any order. Of two
     *                                         participants with the same ID the later is kept
     * @param array<Result> $results The recorded results, in any order
     *
     * @throws InvalidInputException When a result's event has a participant who is not
     *                               among the given ones, or two results reference the
     *                               same event object
     */
    public function calculate(array $participants, array $results): Standings
    {
        $seenEvents = [];
        foreach ($results as $result) {
            $eventId = spl_object_id($result->getEvent());
            if (isset($seenEvents[$eventId])) {
                throw new InvalidInputException(
                    'Two results reference the same event; each event can have only one result'
                );
            }
            $seenEvents[$eventId] = true;
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

        $tiebreakerNames = $this->tiebreakerNames();

        $ordered = array_values($entries);
        usort($ordered, static function (StandingEntry $a, StandingEntry $b) use ($tiebreakerNames): int {
            $comparison = EntryOrder::compareFigures($a, $b, $tiebreakerNames);
            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = ($a->getParticipant()->getSeed() ?? PHP_INT_MAX) <=> ($b->getParticipant()->getSeed() ?? PHP_INT_MAX);
            if ($comparison !== 0) {
                return $comparison;
            }

            return EntryOrder::compareIds($a->getParticipant()->getId(), $b->getParticipant()->getId());
        });

        return new Standings($ordered);
    }

    /**
     * The table with byes credited: each bye adds the ranking value of the
     * given number of wins to the entry of the participant who had it, and
     * the table is put in order again.
     *
     * A bye is no result, so calculate() counts it nowhere. A stage in
     * which sitting out a round is worth what winning it is (a Swiss
     * stage, and a bracket when it ranks the entrants who are still in it)
     * credits it here. Only the ranking value changes, and
     * StandingEntry::getByes() says how many byes are in it: played, wins,
     * draws, losses, the scores and the tiebreaker values are those of the
     * results. A tiebreaker that reads the ranking values of opponents
     * (Buchholz, Sonneborn-Berger) was calculated before the credit and
     * counts an opponent's bye as nothing.
     *
     * The credited table is ordered as calculate() orders one, by ranking
     * value, tiebreakers, score difference and scores-for. Entries that
     * are level after that keep the order they have in the table given.
     * A table with tiers keeps them: an entry is ordered among the entries
     * of its own tier only (Standings::getTiers()). Without a bye to
     * credit the table is returned as it is.
     *
     * @param array<int|string, int> $byes Bye counts keyed by participant ID; an ID with no
     *                                     entry in the table is ignored
     * @param int $winsPerBye How many wins one bye is worth (the legs of a two-legged tie)
     *
     * @throws InvalidConfigurationException When there is a bye to credit and the ranking strategy
     *                                       is not a WinDrawLossRanking: what a win is worth is
     *                                       not known on another scale (reason
     *                                       `IncompatibleOptions`)
     */
    public function withByesCredited(Standings $standings, array $byes, int $winsPerBye = 1): Standings
    {
        $credited = [];
        $any = false;
        foreach ($standings->getEntries() as $entry) {
            $count = $byes[$entry->getParticipant()->getId()] ?? 0;
            if ($count < 1) {
                $credited[] = $entry;
                continue;
            }

            if (!$this->rankingStrategy instanceof WinDrawLossRanking) {
                throw new InvalidConfigurationException(
                    'Crediting a bye requires a win/draw/loss ranking strategy; counting a bye as a win is undefined under other ranking scales',
                    ['ranking_strategy' => $this->rankingStrategy::class],
                    reason: InvalidConfigurationReason::IncompatibleOptions
                );
            }

            $any = true;
            $credited[] = $entry->withByes(
                $entry->getByes() + $count,
                $entry->getRankingValue() + $count * $winsPerBye * $this->rankingStrategy->getWinValue()
            );
        }

        if (!$any) {
            return $standings;
        }

        $tiebreakerNames = $this->tiebreakerNames();
        $tiers = $standings->getTiers();
        $rows = [];
        foreach ($credited as $index => $entry) {
            $rows[] = ['tier' => $tiers[$index] ?? 0, 'entry' => $entry];
        }
        // usort() is stable: level entries keep the order of the table given
        usort(
            $rows,
            static fn(array $a, array $b): int => $a['tier'] !== $b['tier']
                ? $a['tier'] <=> $b['tier']
                : EntryOrder::compareFigures($a['entry'], $b['entry'], $tiebreakerNames)
        );

        // The tiers never fall down a table, and no entry left its tier, so
        // they are the tiers of the credited table as they stand
        return new Standings(array_map(static fn(array $row): StandingEntry => $row['entry'], $rows), $tiers);
    }

    /**
     * @return list<string>
     */
    private function tiebreakerNames(): array
    {
        return array_values(array_map(
            static fn(TiebreakerInterface $tiebreaker): string => $tiebreaker->getName(),
            $this->tiebreakers
        ));
    }
}
