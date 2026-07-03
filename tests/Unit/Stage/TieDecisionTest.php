<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Stage\TieDecision;

describe('TieDecision', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->leg1 = new Event([$this->alice, $this->bob], new Round(1), ['tie_leg' => 1]);
        $this->leg2 = new Event([$this->bob, $this->alice], new Round(1), ['tie_leg' => 2]);
    });

    it('advances the single-leg winner and rejects single-leg draws', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(1));

        expect(TieDecision::advancer([new Result($event, $this->bob)], $this->alice, $this->bob, 1))
            ->toBe($this->bob);

        expect(fn () => TieDecision::advancer([new Result($event)], $this->alice, $this->bob, 1))
            ->toThrow(InvalidConfigurationException::class, 'draw');
    });

    it('returns null while legs are missing results', function (): void {
        expect(TieDecision::advancer([], $this->alice, $this->bob, 2))->toBeNull();
        expect(TieDecision::advancer([new Result($this->leg1, $this->alice)], $this->alice, $this->bob, 2))
            ->toBeNull();
    });

    it('advances the participant with more leg wins', function (): void {
        // A win and a draw decide 1-0
        $advancer = TieDecision::advancer(
            [new Result($this->leg1, $this->alice), new Result($this->leg2)],
            $this->alice,
            $this->bob,
            2
        );
        expect($advancer)->toBe($this->alice);
    });

    it('reads the recorded decision when the legs are level', function (): void {
        $legs = [
            new Result($this->leg1, $this->alice),
            new Result($this->leg2, $this->bob, [], [TieDecision::TIE_WINNER_KEY => 'p1']),
        ];

        expect(TieDecision::advancer($legs, $this->alice, $this->bob, 2))->toBe($this->alice);
    });

    it('throws for level legs without a recorded decision', function (): void {
        $legs = [
            new Result($this->leg1, $this->alice),
            new Result($this->leg2, $this->bob),
        ];

        expect(fn () => TieDecision::advancer($legs, $this->alice, $this->bob, 2))
            ->toThrow(InvalidConfigurationException::class, 'tie_winner');
    });

    it('rejects a leg result naming a winner outside the tie', function (): void {
        $carol = new Participant('p3', 'Carol');
        $foreignLeg = new Event([$this->alice, $carol], new Round(1), ['tie_leg' => 2]);

        $legs = [
            new Result($this->leg1, $this->alice),
            new Result($foreignLeg, $carol),
        ];

        expect(fn () => TieDecision::advancer($legs, $this->alice, $this->bob, 2))
            ->toThrow(InvalidConfigurationException::class, 'not in the tie');
    });

    it('rejects a decision naming a participant outside the tie', function (): void {
        $legs = [
            new Result($this->leg1),
            new Result($this->leg2, null, [], [TieDecision::TIE_WINNER_KEY => 'ghost']),
        ];

        expect(fn () => TieDecision::advancer($legs, $this->alice, $this->bob, 2))
            ->toThrow(InvalidConfigurationException::class, 'not in the tie');
    });
});
