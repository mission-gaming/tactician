<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Standings\EntryOrder;
use MissionGaming\Tactician\Standings\RankingStrategy;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use MissionGaming\Tactician\Standings\WinsTiebreaker;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * @return list<string>
 */
function entrantOrderIds(Standings $standings): array
{
    return array_values(array_map(
        fn(StandingEntry $entry): string => $entry->getParticipant()->getId(),
        $standings->getEntries()
    ));
}

describe('a table ordered by an entrant list', function (): void {
    beforeEach(function (): void {
        // Listed d, c, b, a: against the order of the ids, the labels and
        // the seed attributes
        $this->d = new Participant('d', 'Zed', 4);
        $this->c = new Participant('c', 'Yan', 3);
        $this->b = new Participant('b', 'Bob', 2);
        $this->a = new Participant('a', 'Amy', 1);
        $this->list = [$this->d, $this->c, $this->b, $this->a];
    });

    it('puts level entries in list order and leaves the others where results put them', function (): void {
        // c beats a 2-0: c is first and a last by results; d and b have not
        // played and are level between them
        $results = [new Result(new Event([$this->c, $this->a]), $this->c, ['c' => 2, 'a' => 0])];
        $table = (new StandingsCalculator())->calculate($this->list, $results);

        expect(entrantOrderIds($table))->toBe(['c', 'b', 'd', 'a']);

        $ordered = $table->withTiedSetsOrderedBy($this->list);

        expect(entrantOrderIds($ordered))->toBe(['c', 'd', 'b', 'a'])
            // The table it was called on is not changed, and the sets are
            // the same sets at the same positions
            ->and(entrantOrderIds($table))->toBe(['c', 'b', 'd', 'a'])
            ->and(count($ordered->getTiedSets()))->toBe(1)
            ->and($ordered->getTiedSets()[0]->getFirstPosition())->toBe(2)
            ->and($ordered->getTiedSets()[0]->getLastPosition())->toBe(3)
            ->and($ordered->getPosition($this->d))->toBe(2);
    });

    it('puts entries that are not in the list after those that are, in the order they had', function (): void {
        $table = (new StandingsCalculator())->calculate($this->list, []);

        expect(entrantOrderIds($table->withTiedSetsOrderedBy([$this->b, $this->d])))->toBe(['b', 'd', 'a', 'c'])
            ->and(entrantOrderIds($table->withTiedSetsOrderedBy([])))->toBe(['a', 'b', 'c', 'd']);
    });

    it('matches participants by ID and takes the first position of an ID listed twice', function (): void {
        $table = (new StandingsCalculator())->calculate($this->list, []);
        $copyOfB = Participant::fromArray($this->b->toArray());

        expect(entrantOrderIds($table->withTiedSetsOrderedBy([$copyOfB, $this->d, $this->c, $this->a, $this->b])))
            ->toBe(['b', 'd', 'c', 'a']);
    });

    it('is what an outcome does to its table when it is given the entrant list', function (): void {
        $results = [new Result(new Event([$this->c, $this->a]), $this->c, ['c' => 2, 'a' => 0])];
        $table = (new StandingsCalculator())->calculate($this->list, $results);

        $withList = new StageOutcome($table, $results, [], null, [], $this->list);
        $without = new StageOutcome($table, $results);

        expect(entrantOrderIds($withList->getStandings()))->toBe(['c', 'd', 'b', 'a'])
            ->and($withList->getEntrants())->toBe($this->list)
            ->and(entrantOrderIds($without->getStandings()))->toBe(['c', 'b', 'd', 'a'])
            ->and($without->getEntrants())->toBe([]);
    });

    it('orders the combined table of pools by the entrant list it is given, and by the fallback without one', function (): void {
        $calculator = new StandingsCalculator();
        $pools = [
            'A' => new StageOutcome($calculator->calculate([$this->d, $this->a], []), [], [], null, [], [$this->d, $this->a]),
            'B' => new StageOutcome($calculator->calculate([$this->c, $this->b], []), [], [], null, [], [$this->c, $this->b]),
        ];

        expect(entrantOrderIds(StageOutcome::combining($pools, $calculator, $this->list)->getStandings()))->toBe(['d', 'c', 'b', 'a'])
            ->and(entrantOrderIds(StageOutcome::combining($pools, $calculator)->getStandings()))->toBe(['a', 'b', 'c', 'd'])
            ->and(StageOutcome::combining($pools, $calculator)->getEntrants())->toBe([]);
    });
});

describe('the tiers of a table', function (): void {
    beforeEach(function (): void {
        $entry = fn(string $id, float $value): StandingEntry => new StandingEntry(new Participant($id, strtoupper($id)), 1, 0, 0, 0, $value);
        $this->entries = [$entry('a', 3.0), $entry('b', 3.0), $entry('c', 3.0), $entry('d', 0.0)];
    });

    it('has none unless they are given', function (): void {
        expect((new Standings($this->entries))->getTiers())->toBe([])
            ->and((new StandingsCalculator())->calculate([new Participant('a', 'A')], [])->getTiers())->toBe([]);
    });

    it('keeps entries of different tiers out of one tied set, whatever their records', function (): void {
        // a, b and c have the same record; a is alone in the first tier
        $table = new Standings($this->entries, [0, 1, 1, 2]);

        expect($table->getTiers())->toBe([0, 1, 1, 2])
            ->and(count($table->getTiedSets()))->toBe(1)
            ->and(entrantOrderIds(new Standings($table->getTiedSets()[0]->getEntries())))->toBe(['b', 'c'])
            ->and($table->getTiedSets()[0]->getFirstPosition())->toBe(2)
            // Without tiers the three are one set
            ->and(count((new Standings($this->entries))->getTiedSets()[0]))->toBe(3);
    });

    it('orders level entries by an entrant list inside a tier only, and keeps the tiers', function (): void {
        $table = new Standings($this->entries, [0, 1, 1, 2]);
        $reversed = array_reverse(array_map(fn(StandingEntry $entry): Participant => $entry->getParticipant(), $this->entries));

        $ordered = $table->withTiedSetsOrderedBy($reversed);

        expect(entrantOrderIds($ordered))->toBe(['a', 'c', 'b', 'd'])
            ->and($ordered->getTiers())->toBe([0, 1, 1, 2]);
    });

    it('refuses tiers that do not fit the entries', function (array $tiers, string $message): void {
        expect(fn() => new Standings($this->entries, $tiers))->toThrow(InvalidInputException::class, $message);
    })->with([
        'too few' => [[0, 1, 1], 'needs one tier for each entry'],
        'too many' => [[0, 1, 1, 2, 3], 'needs one tier for each entry'],
        'falling' => [[0, 1, 0, 2], 'must not fall from one entry to the next'],
    ]);
});

describe('crediting byes in a table', function (): void {
    beforeEach(function (): void {
        $this->a = new Participant('a', 'A');
        $this->b = new Participant('b', 'B');
        $this->c = new Participant('c', 'C');
        // a beats b; c sat out
        $this->results = [new Result(new Event([$this->a, $this->b]), $this->a, ['a' => 2, 'b' => 0])];
        $this->participants = [$this->a, $this->b, $this->c];
    });

    it('adds the value of a win to the ranking value and nothing to the record', function (): void {
        $calculator = new StandingsCalculator();
        $table = $calculator->calculate($this->participants, $this->results);
        expect(entrantOrderIds($table))->toBe(['a', 'c', 'b']);

        $credited = $calculator->withByesCredited($table, ['c' => 1]);
        $c = $credited->getEntryFor($this->c);

        // a has three points and a score difference of two; c has three
        // points from the bye and no score
        expect(entrantOrderIds($credited))->toBe(['a', 'c', 'b'])
            ->and($c?->getRankingValue())->toBe(3.0)
            ->and($c?->getByes())->toBe(1)
            ->and([$c?->getPlayed(), $c?->getWins(), $c?->getDraws(), $c?->getLosses()])->toBe([0, 0, 0, 0])
            ->and($credited->getEntryFor($this->a)?->getByes())->toBe(0)
            // The table it was given is not changed
            ->and($table->getEntryFor($this->c)?->getRankingValue())->toBe(0.0);
    });

    it('puts the table in order again, by the criteria of the calculator', function (): void {
        // Two byes for b lift it from last to first
        $calculator = new StandingsCalculator();
        $credited = $calculator->withByesCredited($calculator->calculate($this->participants, $this->results), ['b' => 2]);

        expect(entrantOrderIds($credited))->toBe(['b', 'a', 'c'])
            ->and($credited->getEntryFor($this->b)?->getRankingValue())->toBe(6.0)
            ->and($credited->getEntryFor($this->b)?->getByes())->toBe(2);

        // With a tiebreaker on wins, a's win ranks it above c's bye
        // although neither recorded a score
        $byWins = new StandingsCalculator(tiebreakers: [new WinsTiebreaker()]);
        $noScores = [new Result(new Event([$this->a, $this->b]), $this->a)];
        $table = $byWins->withByesCredited($byWins->calculate([$this->c, $this->a, $this->b], $noScores), ['c' => 1]);
        expect(entrantOrderIds($table))->toBe(['a', 'c', 'b']);
    });

    it('leaves entries that are level after the credit in the order they had', function (): void {
        // No score recorded: a's win and c's bye are level, and a was
        // above c in the table given
        $calculator = new StandingsCalculator();
        $noScores = [new Result(new Event([$this->a, $this->b]), $this->a)];
        $credited = $calculator->withByesCredited($calculator->calculate($this->participants, $noScores), ['c' => 1]);

        expect(entrantOrderIds($credited))->toBe(['a', 'c', 'b'])
            ->and($credited->getEntries()[0]->isLevelWith($credited->getEntries()[1]))->toBeTrue()
            // which an entrant list then decides
            ->and(entrantOrderIds($credited->withTiedSetsOrderedBy([$this->c, $this->b, $this->a])))->toBe(['c', 'a', 'b']);
    });

    it('credits a bye as several wins when asked, on the scale of the calculator', function (): void {
        $calculator = new StandingsCalculator(WinDrawLossRanking::oneHalfZero());
        $credited = $calculator->withByesCredited($calculator->calculate($this->participants, $this->results), ['c' => 1], 2);

        expect($credited->getEntryFor($this->c)?->getRankingValue())->toBe(2.0)
            ->and(entrantOrderIds($credited))->toBe(['c', 'a', 'b']);
    });

    it('returns the table it was given when there is no bye to credit', function (): void {
        $calculator = new StandingsCalculator();
        $table = $calculator->calculate($this->participants, $this->results);

        expect($calculator->withByesCredited($table, []))->toBe($table)
            ->and($calculator->withByesCredited($table, ['stranger' => 1, 'c' => 0]))->toBe($table);
    });

    it('refuses to credit a bye on a scale that has no value for a win, and only when there is one to credit', function (): void {
        $calculator = new StandingsCalculator(new class implements RankingStrategy {
            #[Override]
            public function rank(Participant $participant, array $results): float
            {
                return (float) count($results);
            }
        });
        $table = $calculator->calculate($this->participants, $this->results);

        expect($calculator->withByesCredited($table, []))->toBe($table);

        try {
            $calculator->withByesCredited($table, ['c' => 1]);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions);

            return;
        }

        throw new LogicException('A bye was credited on a scale with no value for a win.');
    });

    // A tier orders a table before any record does, so a bye credited to
    // an entry of a lower tier does not lift it over a higher one, and the
    // credited table still has its tiers.
    it('keeps the tiers of a table, and credits a bye inside a tier only', function (): void {
        $entry = fn(string $id, float $value): StandingEntry => new StandingEntry(new Participant($id, $id), 1, 0, 0, 0, $value);
        $table = new Standings(
            [$entry('first', 3.0), $entry('second', 0.0), $entry('third', 3.0), $entry('fourth', 0.0)],
            [0, 1, 2, 2]
        );

        $credited = (new StandingsCalculator())->withByesCredited($table, ['second' => 1, 'fourth' => 2]);

        expect(entrantOrderIds($credited))->toBe(['first', 'second', 'fourth', 'third'])
            ->and($credited->getTiers())->toBe([0, 1, 2, 2])
            ->and($credited->getEntries()[2]->getRankingValue())->toBe(6.0);
    });

    it('keeps the credited byes of an entry that is given tiebreaker values', function (): void {
        $entry = (new StandingEntry($this->a, 1, 1, 0, 0, 3.0))->withByes(2, 9.0)->withTiebreakers(['wins' => 1.0]);

        expect($entry->getByes())->toBe(2)
            ->and($entry->getRankingValue())->toBe(9.0)
            ->and($entry->getTiebreakerValue('wins'))->toBe(1.0)
            ->and((new StandingEntry($this->a, 1, 1, 0, 0, 3.0))->getByes())->toBe(0);
    });
});

// The ID step of the fallback has to be an order: for any two IDs one answer,
// the opposite answer the other way round, equal only for the same string,
// and no three IDs in a circle. PHP's own comparison of two strings fails
// each of these for some of the IDs below.
describe('the order of participant IDs', function (): void {
    beforeEach(function (): void {
        $this->ids = [
            // whole numbers as written, among them values past the integer range
            '0', '1', '2', '7', '9', '10', '100', '1000', '9223372036854775807', '9223372036854775808',
            '99999999999999999999999999', '100000000000000000000000000',
            // equal to one of those as numbers, and not written as it is
            '01', '00', '007', '1.0', '1e0', '+1', ' 1', '1 ', "\t1", "1\n", '1e3', '0x1A', '-0', '-1', '.5', '0.5', '1e1000', '0e0',
            // not numbers
            '', ' ', '1a', '1_0', 'a', 'A', 'ab', 'b', 'e', 'E', 'p2', 'p10', 'P2', 'null', 'true', 'INF', 'NAN',
            // outside ASCII
            'é', '١', '１',
        ];
    });

    it('answers every pair one way, and equal only for the same string', function (): void {
        foreach ($this->ids as $first) {
            foreach ($this->ids as $second) {
                $forward = EntryOrder::compareIds($first, $second);

                expect($forward)->toBeIn([-1, 0, 1])
                    ->and(EntryOrder::compareIds($second, $first))->toBe(-$forward)
                    ->and($forward === 0)->toBe($first === $second);
            }
        }
    });

    it('puts no three IDs in a circle', function (): void {
        $circles = [];
        foreach ($this->ids as $first) {
            foreach ($this->ids as $second) {
                if (EntryOrder::compareIds($first, $second) >= 0) {
                    continue;
                }
                foreach ($this->ids as $third) {
                    if (EntryOrder::compareIds($second, $third) < 0 && EntryOrder::compareIds($first, $third) >= 0) {
                        $circles[] = json_encode([$first, $second, $third]);
                    }
                }
            }
        }

        expect($circles)->toBe([]);
    });

    it('puts IDs written as whole numbers first, in the order of their values', function (): void {
        $ordered = $this->ids;
        usort($ordered, EntryOrder::compareIds(...));

        expect(array_slice($ordered, 0, 12))->toBe([
            '0', '1', '2', '7', '9', '10', '100', '1000', '9223372036854775807', '9223372036854775808',
            '99999999999999999999999999', '100000000000000000000000000',
        ]);

        // The others follow byte by byte, as strcmp() has them
        $others = array_slice($ordered, 12);
        $byBytes = $others;
        usort($byBytes, static fn(string $first, string $second): int => strcmp($first, $second) <=> 0);
        expect($others)->toBe($byBytes);
    });

    it('sorts every arrangement of a set of awkward IDs to one list', function (int $seed): void {
        $randomizer = new Randomizer(new Mt19937($seed));
        $set = array_map(fn(int|string $key): string => $this->ids[$key], $randomizer->pickArrayKeys($this->ids, 6));
        // The empty string is compared above and is no participant's ID
        $set = array_values(array_filter($set, static fn(string $id): bool => $id !== ''));

        $permute = static function (array $items) use (&$permute): array {
            if (count($items) <= 1) {
                return [$items];
            }
            $all = [];
            foreach ($items as $index => $item) {
                $rest = $items;
                unset($rest[$index]);
                foreach ($permute(array_values($rest)) as $tail) {
                    $all[] = [$item, ...$tail];
                }
            }

            return $all;
        };

        // Through the calculator: unseeded participants with no results, so
        // that nothing but the ID decides
        $calculator = new StandingsCalculator();
        $answers = [];
        foreach ($permute($set) as $arrangement) {
            $table = entrantOrderIds($calculator->calculate(
                array_map(static fn(string $id): Participant => new Participant($id, 'Entry'), $arrangement),
                []
            ));
            $answers[json_encode($table, JSON_INVALID_UTF8_SUBSTITUTE)] = true;
        }

        expect($answers)->toHaveCount(1);
    })->with([[1], [2], [3], [4], [5], [6], [7], [8], [9], [10], [11], [12]]);
});

// A table calculated on its own has no entrant list to fall back on. It is
// the same table for every order of the participants array it is given.
it('gives a standalone table the same order for every order of its participants array', function (int $seed): void {
    $randomizer = new Randomizer(new Mt19937($seed));

    // Ids of every awkward kind, labels that repeat, seeds missing and shared
    $ids = ['2', '10', '1a', '01', '1', 'a', 'B', '1e1', '100'];
    $participants = [];
    foreach ($ids as $id) {
        $participants[] = new Participant(
            $id,
            ['Same', 'same', 'Other'][$randomizer->getInt(0, 2)],
            $randomizer->getInt(0, 1) === 0 ? null : $randomizer->getInt(1, 3)
        );
    }

    // A few results, so that some entries are placed by them and most are level
    $results = [];
    for ($game = 0; $game < 4; ++$game) {
        [$one, $other] = $randomizer->pickArrayKeys($participants, 2);
        $pair = [$participants[$one], $participants[$other]];
        $results[] = new Result(new Event($pair), [$pair[0], $pair[1], null][$randomizer->getInt(0, 2)]);
    }

    $calculator = new StandingsCalculator(tiebreakers: [new WinsTiebreaker()]);
    $reference = entrantOrderIds($calculator->calculate($participants, $results));

    for ($shuffle = 0; $shuffle < 60; ++$shuffle) {
        expect(entrantOrderIds($calculator->calculate($randomizer->shuffleArray($participants), $randomizer->shuffleArray($results))))
            ->toBe($reference);
    }
})->with([[1], [2], [3], [4], [5], [6], [7], [8]]);
