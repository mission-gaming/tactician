<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

// A position is an entry's place in the order of the table and is never
// shared. A rank is the place the results give it: the entries of a tied
// set share the rank of the set's first position, and the entry after the
// set has the rank of its own position.

describe('the ranks of a table', function (): void {
    beforeEach(function (): void {
        $this->field = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
            $this->field[] = new Participant($id, strtoupper($id));
        }
    });

    it('are shared by the entries of a tied set, and the entry after it has the rank of its position', function (): void {
        [$a, $b, $c, $d, $e] = $this->field;

        // A beat E. Nothing separates B, C and D, who have not played.
        $table = (new StandingsCalculator())->calculate($this->field, [new Result(new Event([$a, $e]), $a, ['a' => 1, 'e' => 0])]);

        expect($table->getRanks())->toBe([1, 2, 2, 2, 5])
            ->and([$table->getRank($a), $table->getRank($b), $table->getRank($c), $table->getRank($d), $table->getRank($e)])
            ->toBe([1, 2, 2, 2, 5])
            // Positions are never shared
            ->and([$table->getPosition($b), $table->getPosition($c), $table->getPosition($d)])->toBe([2, 3, 4]);
    });

    it('are the positions where results decide every place, and 1 for everyone where they decide none', function (): void {
        [$a, $b, $c] = $this->field;
        $decided = (new StandingsCalculator())->calculate([$a, $b, $c], [
            new Result(new Event([$a, $b]), $a),
            new Result(new Event([$b, $c]), $b),
            new Result(new Event([$a, $c]), $a),
        ]);

        expect($decided->getRanks())->toBe([1, 2, 3])
            ->and((new StandingsCalculator())->calculate($this->field, [])->getRanks())->toBe([1, 1, 1, 1, 1])
            ->and((new Standings([]))->getRanks())->toBe([]);
    });

    it('count two separate ties apart', function (): void {
        [$a, $b, $c, $d] = $this->field;

        // A and B drew, C and D drew with another score
        $table = (new StandingsCalculator())->calculate([$a, $b, $c, $d], [
            new Result(new Event([$a, $b]), null, ['a' => 2, 'b' => 2]),
            new Result(new Event([$c, $d]), null, ['c' => 0, 'd' => 0]),
        ]);

        expect($table->getRanks())->toBe([1, 1, 3, 3]);
    });

    it('is null for a participant the table does not have', function (): void {
        $table = (new StandingsCalculator())->calculate(array_slice($this->field, 0, 2), []);

        expect($table->getRank($this->field[4]))->toBeNull()
            ->and($table->getRank(new Participant('a', 'Another object with the ID')))->toBe(1);
    });

    it('stay as they are when the entries of a tied set are put in another order', function (): void {
        [$a, , , , $e] = $this->field;
        $table = (new StandingsCalculator())->calculate($this->field, [new Result(new Event([$a, $e]), $a, ['a' => 1, 'e' => 0])]);
        $reordered = $table->withTiedSetsOrderedBy(array_reverse($this->field));

        expect($reordered->getRanks())->toBe($table->getRanks());
        foreach ($this->field as $participant) {
            expect($reordered->getRank($participant))->toBe($table->getRank($participant), $participant->getId());
        }
        // The order inside the set did change
        expect($reordered->getPosition($this->field[1]))->toBe(4)
            ->and($table->getPosition($this->field[1]))->toBe(2);
    });

    it('are not shared across the tiers of a table', function (): void {
        $entry = static fn(Participant $participant): StandingEntry => new StandingEntry($participant, 0, 0, 0, 0, 0.0);
        [$a, $b, $c, $d] = $this->field;

        // Four entries with one record: two in the first tier, two in the second
        $table = new Standings([$entry($a), $entry($b), $entry($c), $entry($d)], [0, 0, 1, 1]);

        expect($table->getRanks())->toBe([1, 1, 3, 3]);
    });

    // What a rank is, said once more as a property: the rank of an entry is
    // one more than the number of entries the results place above it.
    it('are, for every entry, one more than the number of entries placed above its tied set', function (): void {
        $rng = new Randomizer(new Xoshiro256StarStar(58));

        for ($case = 0; $case < 60; ++$case) {
            $results = [];
            foreach ($this->field as $i => $first) {
                foreach (array_slice($this->field, $i + 1) as $second) {
                    $outcome = $rng->getInt(0, 3);
                    if ($outcome < 3) {
                        $results[] = new Result(new Event([$first, $second]), [$first, $second, null][$outcome]);
                    }
                }
            }

            $table = (new StandingsCalculator())->calculate($rng->shuffleArray($this->field), $results);
            $entries = array_values($table->getEntries());
            $ranks = $table->getRanks();

            foreach ($entries as $index => $entry) {
                $above = 0;
                foreach ($entries as $other) {
                    $above += $other->getRankingValue() > $entry->getRankingValue() ? 1 : 0;
                }

                // No scores and no tiebreakers: level is the same ranking value
                expect($ranks[$index])->toBe($above + 1, "case {$case}");
            }
        }
    });
});
