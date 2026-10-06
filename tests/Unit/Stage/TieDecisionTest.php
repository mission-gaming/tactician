<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
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

        expect(fn() => TieDecision::advancer([new Result($event)], $this->alice, $this->bob, 1))
            ->toThrow(InvalidConfigurationException::class, 'draw');
    });

    it('reads the recorded decision when a single-leg event is level', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(1));
        $level = fn(mixed $decision): array => [
            new Result($event, null, ['p1' => 1, 'p2' => 1], [TieDecision::TIE_WINNER_KEY => $decision]),
        ];

        expect(TieDecision::advancer($level('p1'), $this->alice, $this->bob, 1))->toBe($this->alice);
        expect(TieDecision::advancer($level('p2'), $this->alice, $this->bob, 1))->toBe($this->bob);

        // The sides may be given in either order.
        expect(TieDecision::advancer($level('p2'), $this->bob, $this->alice, 1))->toBe($this->bob);
    });

    it('says how to record the decision of a level single-leg event', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(1));

        try {
            TieDecision::advancer([new Result($event)], $this->alice, $this->bob, 1);
            $thrown = null;
        } catch (InvalidConfigurationException $exception) {
            $thrown = $exception;
        }

        // The words the message had before it named the key still begin it.
        expect($thrown?->getMessage())->toBe(
            'Invalid scheduler configuration: Elimination events cannot end in a draw (Alice vs Bob)'
            . ": the event is level, so record who advances as 'tie_winner' metadata on its result"
        );
        expect($thrown?->getReason())->toBe(InvalidConfigurationReason::UndecidedTie);
        expect($thrown?->getContext())->toBe(['participants' => ['p1', 'p2']]);
    });

    it('rejects a single-leg decision naming a participant outside the tie as it does for two legs', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(1));
        $capture = function (array $results, int $legsPerTie): ?InvalidConfigurationException {
            try {
                TieDecision::advancer($results, $this->alice, $this->bob, $legsPerTie);
            } catch (InvalidConfigurationException $exception) {
                return $exception;
            }

            return null;
        };

        $oneLeg = $capture([new Result($event, null, [], [TieDecision::TIE_WINNER_KEY => 'ghost'])], 1);
        $twoLegs = $capture([
            new Result($this->leg1),
            new Result($this->leg2, null, [], [TieDecision::TIE_WINNER_KEY => 'ghost']),
        ], 2);

        expect($oneLeg?->getMessage())
            ->toBe('Invalid scheduler configuration: Tie decision names a participant who is not in the tie');
        expect($oneLeg?->getReason())->toBe(InvalidConfigurationReason::InvalidResult);
        expect($oneLeg?->getContext())->toBe(['tie_winner' => 'ghost', 'participants' => ['p1', 'p2']]);

        expect($twoLegs?->getMessage())->toBe($oneLeg?->getMessage());
        expect($twoLegs?->getReason())->toBe($oneLeg?->getReason());
        expect($twoLegs?->getContext())->toBe($oneLeg?->getContext());
    });

    it('does not read the decision of a single-leg event that has a winner', function (mixed $decision): void {
        $event = new Event([$this->alice, $this->bob], new Round(1));
        $result = new Result($event, $this->alice, [], [TieDecision::TIE_WINNER_KEY => $decision]);

        expect(TieDecision::advancer([$result], $this->alice, $this->bob, 1))->toBe($this->alice);
    })->with([
        'the winner' => ['p1'],
        'the loser' => ['p2'],
        'nobody in the tie' => ['ghost'],
    ]);

    it('does not read the decision of a two-legged tie the legs decide', function (mixed $decision): void {
        $legs = [
            new Result($this->leg1, $this->alice),
            new Result($this->leg2, null, [], [TieDecision::TIE_WINNER_KEY => $decision]),
        ];

        expect(TieDecision::advancer($legs, $this->alice, $this->bob, 2))->toBe($this->alice);
    })->with([
        'the winner' => ['p1'],
        'the loser' => ['p2'],
        'nobody in the tie' => ['ghost'],
    ]);

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

        expect(fn() => TieDecision::advancer($legs, $this->alice, $this->bob, 2))
            ->toThrow(InvalidConfigurationException::class, 'tie_winner');
    });

    it('rejects a leg result naming a winner outside the tie', function (): void {
        $carol = new Participant('p3', 'Carol');
        $foreignLeg = new Event([$this->alice, $carol], new Round(1), ['tie_leg' => 2]);

        $legs = [
            new Result($this->leg1, $this->alice),
            new Result($foreignLeg, $carol),
        ];

        expect(fn() => TieDecision::advancer($legs, $this->alice, $this->bob, 2))
            ->toThrow(InvalidConfigurationException::class, 'not in the tie');
    });

    it('rejects a decision naming a participant outside the tie', function (): void {
        $legs = [
            new Result($this->leg1),
            new Result($this->leg2, null, [], [TieDecision::TIE_WINNER_KEY => 'ghost']),
        ];

        expect(fn() => TieDecision::advancer($legs, $this->alice, $this->bob, 2))
            ->toThrow(InvalidConfigurationException::class, 'not in the tie');
    });
});
