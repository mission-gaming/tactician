<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Standings\StandingsCalculator;

describe('StageOutcome pooling', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->carol = new Participant('p3', 'Carol');
        $this->dave = new Participant('p4', 'Dave');
        $calculator = new StandingsCalculator();

        // Pool A: Alice beats Bob twice; pool B: Carol beats Dave once
        $this->poolAResults = [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->bob, $this->alice], new Round(2)), $this->alice),
        ];
        $this->poolA = new StageOutcome(
            $calculator->calculate([$this->alice, $this->bob], $this->poolAResults),
            $this->poolAResults,
            ['p2' => 1]
        );

        $this->poolBResults = [
            new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->carol),
        ];
        $this->poolB = new StageOutcome(
            $calculator->calculate([$this->carol, $this->dave], $this->poolBResults),
            $this->poolBResults,
            ['p2' => 1, 'p4' => 2]
        );
    });

    it('is unpooled by default', function (): void {
        expect($this->poolA->hasPools())->toBeFalse();
        expect($this->poolA->getPools())->toBe([]);
        expect($this->poolA->getPool('A'))->toBeNull();
    });

    it('combines pool outcomes into one pooled outcome', function (): void {
        $combined = StageOutcome::combining(['A' => $this->poolA, 'B' => $this->poolB]);

        expect($combined->hasPools())->toBeTrue();
        expect(array_keys($combined->getPools()))->toBe(['A', 'B']);
        expect($combined->getPool('A'))->toBe($this->poolA);

        // Results merge; byes sum across pools; no single final round exists
        expect($combined->getResults())->toHaveCount(3);
        expect($combined->getByes())->toBe(['p2' => 2, 'p4' => 2]);
        expect($combined->getFinalRound())->toBeNull();

        // The combined standings rank everyone in one table: Alice (2 wins)
        // ahead of Carol (1 win) ahead of the losers
        $entries = $combined->getStandings()->getEntries();
        expect($entries[0]->getParticipant()->getId())->toBe('p1');
        expect($entries[1]->getParticipant()->getId())->toBe('p3');
        expect($entries)->toHaveCount(4);
    });
});
