<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\PointsSystem;
use MissionGaming\Tactician\Standings\SonnebornBergerTiebreaker;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinsTiebreaker;

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

    it('rejects a winner who is not in the event', function (): void {
        expect(fn () => new Result($this->event, $this->carol))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects scores for participants not in the event', function (): void {
        expect(fn () => new Result($this->event, null, ['p3' => 1]))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('StandingsCalculator', function (): void {
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
        expect($aliceEntry?->getPoints())->toBe(6.0);
        expect($standings->getPosition($this->alice))->toBe(1);

        $carolEntry = $standings->getEntryFor($this->carol);
        expect($carolEntry?->getDraws())->toBe(1);
        expect($carolEntry?->getLosses())->toBe(1);
        expect($carolEntry?->getPoints())->toBe(1.0);

        // Bob never played a result... except the round 1 loss
        $bobEntry = $standings->getEntryFor($this->bob);
        expect($bobEntry?->getLosses())->toBe(1);
        expect($bobEntry?->getPoints())->toBe(0.0);
    });

    it('includes participants with no results at zero', function (): void {
        $standings = (new StandingsCalculator())->calculate($this->participants, []);

        expect($standings)->toHaveCount(4);
        foreach ($standings as $entry) {
            expect($entry->getPlayed())->toBe(0);
            expect($entry->getPoints())->toBe(0.0);
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

        $calculator = new StandingsCalculator(PointsSystem::chess());
        $standings = $calculator->calculate($this->participants, $results);

        expect($standings->getEntryFor($this->alice)?->getPoints())->toBe(1.0);
        expect($standings->getEntryFor($this->carol)?->getPoints())->toBe(0.5);
    });

    it('rejects results referencing unknown participants', function (): void {
        $stranger = new Participant('p9', 'Stranger');
        $results = [
            new Result(new Event([$this->alice, $stranger], new Round(1)), $this->alice),
        ];

        $calculator = new StandingsCalculator();

        expect(fn () => $calculator->calculate($this->participants, $results))
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
            PointsSystem::football(),
            [new WinsTiebreaker(), new BuchholzTiebreaker()]
        );
        $standings = $calculator->calculate($this->participants, $results);

        // Alice and Bob tie on Buchholz (3.0) and fall back to label order;
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
            PointsSystem::chess(),
            [new SonnebornBergerTiebreaker()]
        );
        $standings = $calculator->calculate($this->participants, $results);

        // Chess points: Alice 1.5 (win + draw), Bob 1 (loss + win), Carol 0.5 (draw + loss)
        // Alice SB: beat Bob (1.0) + drew Carol (0.5 * 0.5) = 1.25
        $aliceEntry = $standings->getEntryFor($this->alice);
        expect($aliceEntry?->getPoints())->toBe(1.5);
        expect($aliceEntry?->getTiebreakerValue('sonneborn-berger'))->toBe(1.25);
    });

    it('orders deterministically by label when fully tied', function (): void {
        $standings = (new StandingsCalculator())->calculate($this->participants, []);

        $labels = array_map(
            fn ($entry) => $entry->getParticipant()->getLabel(),
            $standings->getEntries()
        );

        expect($labels)->toBe(['Alice', 'Bob', 'Carol', 'Dave']);
    });
});
