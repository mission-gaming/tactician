<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\SonnebornBergerTiebreaker;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use MissionGaming\Tactician\Standings\WinsTiebreaker;

/**
 * Every order of the given values.
 *
 * @param list<string> $values
 * @return list<list<string>>
 */
function standingsPermutations(array $values): array
{
    if (count($values) <= 1) {
        return [$values];
    }

    $permutations = [];
    foreach ($values as $index => $value) {
        $rest = $values;
        unset($rest[$index]);
        foreach (standingsPermutations(array_values($rest)) as $permutation) {
            $permutations[] = [$value, ...$permutation];
        }
    }

    return $permutations;
}

describe('Result', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->carol = new Participant('p3', 'Carol');
        $this->event = new Event([$this->alice, $this->bob], new Round(1));
    });

    it('records a winner', function (): void {
        $result = new Result($this->event, $this->alice);

        expect($result->getWinner())->toBe($this->alice);
        expect($result->isDraw())->toBeFalse();
        expect($result->isWinFor($this->alice))->toBeTrue();
        expect($result->isWinFor($this->bob))->toBeFalse();
    });

    it('records a draw when no winner is given', function (): void {
        $result = new Result($this->event);

        expect($result->isDraw())->toBeTrue();
        expect($result->getWinner())->toBeNull();
    });

    it('records optional scores per participant', function (): void {
        $result = new Result($this->event, $this->alice, ['p1' => 3, 'p2' => 1]);

        expect($result->getScoreFor($this->alice))->toBe(3);
        expect($result->getScoreFor($this->bob))->toBe(1);
        expect($result->getScoreFor($this->carol))->toBeNull();
    });

    it('accepts scores keyed by numeric-string participant IDs', function (): void {
        // PHP canonicalizes numeric-string array keys to ints; validation
        // must still recognize them as the participants' string IDs
        $one = new Participant('1', 'Alice');
        $two = new Participant('2', 'Bob');
        $event = new Event([$one, $two], new Round(1));

        $result = new Result($event, $one, ['1' => 21, '2' => 15]);

        expect($result->getScoreFor($one))->toBe(21);
        expect($result->getScoreFor($two))->toBe(15);
    });

    it('rejects a winner who is not in the event', function (): void {
        expect(fn() => new Result($this->event, $this->carol))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects scores for participants not in the event', function (): void {
        expect(fn() => new Result($this->event, null, ['p3' => 1]))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('StandingsCalculator', function (): void {
    it('gives its tiebreakers in the order they are applied, and none by default', function (): void {
        $buchholz = new BuchholzTiebreaker();
        $wins = new WinsTiebreaker();

        expect((new StandingsCalculator())->getTiebreakers())->toBe([])
            ->and((new StandingsCalculator(tiebreakers: [$buchholz, $wins]))->getTiebreakers())->toBe([$buchholz, $wins])
            ->and((new StandingsCalculator(tiebreakers: [$wins, $buchholz]))->getTiebreakers())->toBe([$wins, $buchholz]);
    });

    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->carol = new Participant('p3', 'Carol');
        $this->dave = new Participant('p4', 'Dave');
        $this->participants = [$this->alice, $this->bob, $this->carol, $this->dave];
    });

    it('tallies wins, draws, losses, and points', function (): void {
        $results = [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1))), // draw
            new Result(new Event([$this->alice, $this->carol], new Round(2)), $this->alice),
        ];

        $standings = (new StandingsCalculator())->calculate($this->participants, $results);

        $aliceEntry = $standings->getEntryFor($this->alice);
        expect($aliceEntry?->getPlayed())->toBe(2);
        expect($aliceEntry?->getWins())->toBe(2);
        expect($aliceEntry?->getRankingValue())->toBe(6.0);
        expect($standings->getPosition($this->alice))->toBe(1);

        $carolEntry = $standings->getEntryFor($this->carol);
        expect($carolEntry?->getDraws())->toBe(1);
        expect($carolEntry?->getLosses())->toBe(1);
        expect($carolEntry?->getRankingValue())->toBe(1.0);

        // Bob never played a result... except the round 1 loss
        $bobEntry = $standings->getEntryFor($this->bob);
        expect($bobEntry?->getLosses())->toBe(1);
        expect($bobEntry?->getRankingValue())->toBe(0.0);
    });

    it('includes participants with no results at zero', function (): void {
        $standings = (new StandingsCalculator())->calculate($this->participants, []);

        expect($standings)->toHaveCount(4);
        foreach ($standings as $entry) {
            expect($entry->getPlayed())->toBe(0);
            expect($entry->getRankingValue())->toBe(0.0);
        }
    });

    it('tallies scores for and against', function (): void {
        $results = [
            new Result(
                new Event([$this->alice, $this->bob], new Round(1)),
                $this->alice,
                ['p1' => 3, 'p2' => 1]
            ),
        ];

        $standings = (new StandingsCalculator())->calculate($this->participants, $results);

        $aliceEntry = $standings->getEntryFor($this->alice);
        expect($aliceEntry?->getScoreFor())->toBe(3.0);
        expect($aliceEntry?->getScoreAgainst())->toBe(1.0);
        expect($aliceEntry?->getScoreDifference())->toBe(2.0);
    });

    it('supports a custom points system', function (): void {
        $results = [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1))), // draw
        ];

        $calculator = new StandingsCalculator(WinDrawLossRanking::oneHalfZero());
        $standings = $calculator->calculate($this->participants, $results);

        expect($standings->getEntryFor($this->alice)?->getRankingValue())->toBe(1.0);
        expect($standings->getEntryFor($this->carol)?->getRankingValue())->toBe(0.5);
    });

    it('rejects two results for the same event', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(1));
        $results = [
            new Result($event, $this->alice),
            new Result($event, $this->bob), // amended result appended instead of replaced
        ];

        $calculator = new StandingsCalculator();

        expect(fn() => $calculator->calculate($this->participants, $results))
            ->toThrow(InvalidArgumentException::class, 'same event');
    });

    it('rejects results referencing unknown participants', function (): void {
        $stranger = new Participant('p9', 'Stranger');
        $results = [
            new Result(new Event([$this->alice, $stranger], new Round(1)), $this->alice),
        ];

        $calculator = new StandingsCalculator();

        expect(fn() => $calculator->calculate($this->participants, $results))
            ->toThrow(InvalidArgumentException::class);
    });

    it('breaks ties with configured tiebreakers in order', function (): void {
        // Alice, Bob, and Carol all finish on 3 points with 1 win each.
        // Buchholz compares opponent strength: Alice faced Bob (3 points),
        // Bob faced Alice and Dave (3 points), Carol faced only Dave (0).
        $results = [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->carol),
            new Result(new Event([$this->bob, $this->dave], new Round(2)), $this->bob),
        ];

        $calculator = new StandingsCalculator(
            WinDrawLossRanking::threeOneZero(),
            [new WinsTiebreaker(), new BuchholzTiebreaker()]
        );
        $standings = $calculator->calculate($this->participants, $results);

        // Alice and Bob tie on Buchholz (3.0) and fall back to id order;
        // Carol's weaker opposition (0.0) ranks her below both.
        expect($standings->getPosition($this->alice))->toBe(1);
        expect($standings->getPosition($this->bob))->toBe(2);
        expect($standings->getPosition($this->carol))->toBe(3);
        expect($standings->getPosition($this->dave))->toBe(4);

        $aliceEntry = $standings->getEntryFor($this->alice);
        expect($aliceEntry?->getTiebreakerValue('wins'))->toBe(1.0);
        expect($aliceEntry?->getTiebreakerValue('buchholz'))->toBe(3.0);
        expect($standings->getEntryFor($this->carol)?->getTiebreakerValue('buchholz'))->toBe(0.0);
    });

    it('calculates Sonneborn-Berger from defeated and drawn opponents', function (): void {
        $results = [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->alice, $this->carol], new Round(2))), // draw
            new Result(new Event([$this->bob, $this->carol], new Round(3)), $this->bob),
        ];

        $calculator = new StandingsCalculator(
            WinDrawLossRanking::oneHalfZero(),
            [new SonnebornBergerTiebreaker()]
        );
        $standings = $calculator->calculate($this->participants, $results);

        // Chess points: Alice 1.5 (win + draw), Bob 1 (loss + win), Carol 0.5 (draw + loss)
        // Alice SB: beat Bob (1.0) + drew Carol (0.5 * 0.5) = 1.25
        $aliceEntry = $standings->getEntryFor($this->alice);
        expect($aliceEntry?->getRankingValue())->toBe(1.5);
        expect($aliceEntry?->getTiebreakerValue('sonneborn-berger'))->toBe(1.25);
    });

    it('orders deterministically by id when fully tied and unseeded', function (): void {
        $standings = (new StandingsCalculator())->calculate($this->participants, []);

        $labels = array_map(
            fn($entry) => $entry->getParticipant()->getLabel(),
            $standings->getEntries()
        );

        expect($labels)->toBe(['Alice', 'Bob', 'Carol', 'Dave']);
    });

    it('breaks full ties by seed before id', function (): void {
        $participants = [
            new Participant('z', 'Zeta', 2),
            new Participant('a', 'Alpha'),      // unseeded sorts last
            new Participant('m', 'Mu', 1),
        ];

        $standings = (new StandingsCalculator())->calculate($participants, []);

        $ids = array_map(fn($entry) => $entry->getParticipant()->getId(), $standings->getEntries());
        expect($ids)->toBe(['m', 'z', 'a']);
    });

    it('never reads a label to order a full tie', function (): void {
        // The labels are in the opposite order to the ids, in natural
        // order and in byte order alike
        $participants = [
            new Participant('b', 'Player 2'),
            new Participant('a', 'Player 10'),
        ];

        $standings = (new StandingsCalculator())->calculate($participants, []);

        $ids = array_map(fn($entry) => $entry->getParticipant()->getId(), $standings->getEntries());
        expect($ids)->toBe(['a', 'b']);
    });

    // The last step of the fallback. PHP's own comparison of two strings
    // reads numeric strings as numbers, which is no order at all for ids
    // that mix the two kinds: '2' < '10' < '1a' < '2'. A table sorted by
    // it depended on the order its participants were given in.
    it('gives a full tie the same order for every order of the participants array', function (array $ids, array $expected): void {
        $orders = [];
        foreach (standingsPermutations(array_values(array_map(strval(...), $ids))) as $permutation) {
            $standings = (new StandingsCalculator())->calculate(
                array_map(fn(string $id): Participant => new Participant($id, 'Same label'), $permutation),
                []
            );
            $orders[implode(' ', array_map(
                fn($entry): string => $entry->getParticipant()->getId(),
                $standings->getEntries()
            ))] = true;
        }

        expect(array_keys($orders))->toBe([implode(' ', $expected)]);
    })->with([
        'numeric and other ids mixed' => [['2', '10', '1a'], ['2', '10', '1a']],
        'ids that are equal as numbers' => [['01', '1', '1.0', '1e0'], ['1', '01', '1.0', '1e0']],
        'whole numbers of any length, in the order of their values' => [
            ['9', '10', '100', '99999999999999999999', '99999999999999999998'],
            ['9', '10', '100', '99999999999999999998', '99999999999999999999'],
        ],
        'a zero, a signed number and an empty-looking one' => [['0', '-1', '00', ' 1'], ['0', ' 1', '-1', '00']],
        'plain names' => [['p10', 'p2', 'P2', 'p1'], ['P2', 'p1', 'p10', 'p2']],
    ]);

    it('puts a seeded participant before an unseeded one whatever their ids, and seeds in order', function (): void {
        $orders = [];
        foreach (standingsPermutations(['a', 'b', 'c', 'd']) as $permutation) {
            $seeds = ['a' => null, 'b' => 2, 'c' => 1, 'd' => null];
            $standings = (new StandingsCalculator())->calculate(
                array_map(fn(string $id): Participant => new Participant($id, strtoupper($id), $seeds[$id]), $permutation),
                []
            );
            $orders[implode(' ', array_map(
                fn($entry): string => $entry->getParticipant()->getId(),
                $standings->getEntries()
            ))] = true;
        }

        expect(array_keys($orders))->toBe(['c b a d']);
    });

    it('returns null entries and positions for absentees', function (): void {
        $standings = (new StandingsCalculator())->calculate([$this->alice, $this->bob], []);
        $stranger = new Participant('x1', 'Stranger');

        expect($standings->getEntryFor($stranger))->toBeNull();
        expect($standings->getPosition($stranger))->toBeNull();
        expect($standings->getPosition($this->alice))->toBe(1);
        expect($standings->getEntryFor($this->alice)?->getTiebreakers())->toBe([]);
    });

    // With everything else level, seeds order the table; with identical
    // labels the participant ID is the final deterministic fallback
    it('falls back to seed and then ID for otherwise level participants', function (): void {
        $seeded = [
            new Participant('pB', 'Same Name', 2),
            new Participant('pA', 'Same Name', 1),
        ];
        $bySeed = (new StandingsCalculator())->calculate($seeded, []);
        expect($bySeed->getEntries()[0]->getParticipant()->getId())->toBe('pA');

        $unseeded = [
            new Participant('z9', 'Same Name'),
            new Participant('a1', 'Same Name'),
        ];
        $byId = (new StandingsCalculator())->calculate($unseeded, []);
        expect($byId->getEntries()[0]->getParticipant()->getId())->toBe('a1');
    });

    it('breaks equal score differences by score for', function (): void {
        $high = new Participant('hi', 'High Scorer');
        $low = new Participant('lo', 'Low Scorer');
        $foilA = new Participant('fa', 'Foil A');
        $foilB = new Participant('fb', 'Foil B');

        // Both draw once: same points, both 0 score difference, but the
        // high scorer's draw was 2-2 against 1-1
        $results = [
            new Result(new Event([$high, $foilA]), null, ['hi' => 2, 'fa' => 2]),
            new Result(new Event([$low, $foilB]), null, ['lo' => 1, 'fb' => 1]),
        ];

        $standings = (new StandingsCalculator())->calculate([$high, $low, $foilA, $foilB], $results);

        $highPosition = $standings->getPosition($high);
        $lowPosition = $standings->getPosition($low);
        expect($highPosition)->not->toBeNull();
        expect($lowPosition)->not->toBeNull();
        assert($highPosition !== null && $lowPosition !== null);
        expect($highPosition)->toBeLessThan($lowPosition);
    });
});
