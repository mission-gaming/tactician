<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Standings\RankingStrategy;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;

describe('WinDrawLossRanking', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->carol = new Participant('p3', 'Carol');
    });

    it('is a ranking strategy with 3/1/0 defaults', function (): void {
        $ranking = new WinDrawLossRanking();

        expect($ranking)->toBeInstanceOf(RankingStrategy::class);
        expect($ranking->getWinValue())->toBe(3.0);
        expect($ranking->getDrawValue())->toBe(1.0);
        expect($ranking->getLossValue())->toBe(0.0);
    });

    it('ranks from wins, draws, and losses', function (): void {
        $ranking = new WinDrawLossRanking();

        $results = [
            new Result(new Event([$this->alice, $this->bob]), $this->alice),
            new Result(new Event([$this->alice, $this->carol])),
            new Result(new Event([$this->alice, $this->bob]), $this->bob),
        ];

        expect($ranking->rank($this->alice, $results))->toBe(4.0); // win + draw + loss
        expect($ranking->rank($this->bob, $results))->toBe(3.0);   // loss + win
        expect($ranking->rank($this->carol, $results))->toBe(1.0); // draw
    });

    // The contract allows passing a whole result set: results not involving
    // the participant contribute nothing
    it('ignores results the participant is not part of', function (): void {
        $ranking = new WinDrawLossRanking();

        $results = [
            new Result(new Event([$this->bob, $this->carol]), $this->bob),
        ];

        expect($ranking->rank($this->alice, $results))->toBe(0.0);
    });

    it('provides the sport conventions as named constructors', function (): void {
        $football = WinDrawLossRanking::threeOneZero();
        expect($football->toArray())->toBe(['win' => 3.0, 'draw' => 1.0, 'loss' => 0.0]);

        $chess = WinDrawLossRanking::oneHalfZero();
        expect($chess->toArray())->toBe(['win' => 1.0, 'draw' => 0.5, 'loss' => 0.0]);
    });

    it('round-trips through plain configuration data', function (): void {
        $original = new WinDrawLossRanking(2.0, 1.0, -1.0);

        $rebuilt = WinDrawLossRanking::fromArray($original->toArray());

        expect($rebuilt->toArray())->toBe($original->toArray());
    });

    it('builds from partial configuration with 3/1/0 defaults', function (): void {
        $ranking = WinDrawLossRanking::fromArray(['win' => 2]);

        expect($ranking->toArray())->toBe(['win' => 2.0, 'draw' => 1.0, 'loss' => 0.0]);
    });

    it('rejects non-numeric configuration values', function (): void {
        WinDrawLossRanking::fromArray(['draw' => 'lots']);
    })->throws(InvalidConfigurationException::class);
});
