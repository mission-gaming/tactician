<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Award;
use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Stage\TieDecision;
use MissionGaming\Tactician\Tests\Support\TieWinnerDeprecation;

/**
 * The exception a reading of a tie ends in, or null when it ends in none.
 *
 * @param array<Result> $results
 */
function tieDecisionRefusal(array $results, Participant $first, Participant $second, int $legsPerTie): ?InvalidConfigurationException
{
    try {
        TieDecision::advancer($results, $first, $second, $legsPerTie);
    } catch (InvalidConfigurationException $exception) {
        return $exception;
    }

    return null;
}

describe('TieDecision', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->single = new Event([$this->alice, $this->bob], new Round(1));
        $this->leg1 = new Event([$this->alice, $this->bob], new Round(1), ['tie_leg' => 1]);
        $this->leg2 = new Event([$this->bob, $this->alice], new Round(1), ['tie_leg' => 2]);
    });

    it('advances the single-leg winner and rejects single-leg draws', function (): void {
        expect(TieDecision::advancer([new Result($this->single, $this->bob)], $this->alice, $this->bob, 1))
            ->toBe($this->bob);

        expect(fn() => TieDecision::advancer([new Result($this->single)], $this->alice, $this->bob, 1))
            ->toThrow(InvalidConfigurationException::class, 'draw');
    });

    it('says how to record the decision of a level single-leg event', function (): void {
        $thrown = tieDecisionRefusal([new Result($this->single)], $this->alice, $this->bob, 1);

        // The words the message had before it named the decider still begin it.
        expect($thrown?->getMessage())->toBe(
            'Invalid scheduler configuration: Elimination events cannot end in a draw (Alice vs Bob)'
            . ': the event is level, so record who advances as the decider of its result'
        );
        expect($thrown?->getReason())->toBe(InvalidConfigurationReason::UndecidedTie);
        expect($thrown?->getContext())->toBe(['participants' => ['p1', 'p2']]);
    });

    it('says how to record the decision of level legs', function (): void {
        $thrown = tieDecisionRefusal(
            [new Result($this->leg1, $this->alice), new Result($this->leg2, $this->bob)],
            $this->alice,
            $this->bob,
            2
        );

        expect($thrown?->getMessage())->toBe(
            'Invalid scheduler configuration: Two-legged tie between Alice and Bob is undecided'
            . ": the legs are level, so record who advances as the decider of one leg's result"
        );
        expect($thrown?->getReason())->toBe(InvalidConfigurationReason::UndecidedTie);
        expect($thrown?->getContext())->toBe(['participants' => ['p1', 'p2']]);
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

    // With two legs this input was always refused. With one, the winner the
    // result records was returned although neither side of the tie.
    it('rejects a single-leg result naming a winner outside the tie', function (): void {
        $carol = new Participant('p3', 'Carol');
        $foreign = new Event([$this->alice, $carol], new Round(1));

        $thrown = tieDecisionRefusal([new Result($foreign, $carol)], $this->alice, $this->bob, 1);

        expect($thrown)->not->toBeNull('A participant outside the tie advanced from it.')
            ->and($thrown?->getReason())->toBe(InvalidConfigurationReason::InvalidResult)
            ->and($thrown?->getConfigurationIssue())->toBe('Leg result names a winner who is not in the tie')
            ->and($thrown?->getContext())->toBe(['winner' => 'p3', 'participants' => ['p1', 'p2']]);
    });

    it('advances a single-leg winner who is an equal copy of one side', function (): void {
        $winner = new Participant('p2', 'Bob');

        expect(TieDecision::advancer([new Result($this->single, $winner)], $this->alice, $this->bob, 1)?->getId())->toBe('p2');
    });
});

describe('TieDecision, a level tie with a decider', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->single = new Event([$this->alice, $this->bob], new Round(1));
        $this->leg1 = new Event([$this->alice, $this->bob], new Round(1), ['tie_leg' => 1]);
        $this->leg2 = new Event([$this->bob, $this->alice], new Round(1), ['tie_leg' => 2]);
    });

    it('advances the participant the decider of a level single-leg event names', function (string $id): void {
        $level = [new Result($this->single, null, ['p1' => 1, 'p2' => 1], [], null, new Decider([$id]))];

        // The side is returned as the object given, whichever way round
        expect(TieDecision::advancer($level, $this->alice, $this->bob, 1)?->getId())->toBe($id)
            ->and(TieDecision::advancer($level, $this->bob, $this->alice, 1)?->getId())->toBe($id)
            ->and(TieDecision::advancer($level, $this->alice, $this->bob, 1))->toBeIn([$this->alice, $this->bob]);
    })->with(['p1', 'p2']);

    it('advances the participant the decider of level legs names, from whichever leg carries it', function (
        int $carrier,
        string $id
    ): void {
        $decider = new Decider([$id], 'the rule of the competition');
        $legs = [
            new Result($this->leg1, $this->alice, ['p1' => 2, 'p2' => 1], [], null, $carrier === 1 ? $decider : null),
            new Result($this->leg2, $this->bob, ['p2' => 1, 'p1' => 0], [], null, $carrier === 2 ? $decider : null),
        ];

        expect(TieDecision::advancer($legs, $this->alice, $this->bob, 2)?->getId())->toBe($id)
            // The results may be handed over in any order
            ->and(TieDecision::advancer(array_reverse($legs), $this->alice, $this->bob, 2)?->getId())->toBe($id);
    })->with([1, 2])->with(['p1', 'p2']);

    it('reads the decider of two drawn legs as it reads the one of a leg each', function (): void {
        $legs = [new Result($this->leg1), new Result($this->leg2, null, [], [], null, new Decider(['p2']))];

        expect(TieDecision::advancer($legs, $this->alice, $this->bob, 2))->toBe($this->bob);
    });

    it('does not read a decider while a leg has no result', function (): void {
        $first = new Result($this->leg1, $this->alice, [], [], null, new Decider(['p2']));

        expect(TieDecision::advancer([$first], $this->alice, $this->bob, 2))->toBeNull();
    });

    it('settles a level awarded result by the decider it carries', function (): void {
        $awarded = new Result($this->single, null, [], [], new Award('not played'), new Decider(['p1'], 'drawn by lot'));

        expect(TieDecision::advancer([$awarded], $this->alice, $this->bob, 1))->toBe($this->alice);
    });

    it('refuses a decider on a tie that its results already decide', function (string $case, int $legsPerTie, string $advancer): void {
        $decider = new Decider(['p2']);
        $results = match ($case) {
            'won by the named' => [new Result($this->single, $this->bob, [], [], null, $decider)],
            'won by the other' => [new Result($this->single, $this->alice, [], [], null, $decider)],
            'awarded' => [new Result($this->single, $this->alice, [], [], new Award(), $decider)],
            'first leg' => [new Result($this->leg1, $this->alice, [], [], null, $decider), new Result($this->leg2)],
            default => [new Result($this->leg1, $this->bob), new Result($this->leg2, $this->bob, [], [], null, $decider)],
        };

        $thrown = tieDecisionRefusal($results, $this->alice, $this->bob, $legsPerTie);

        expect($thrown?->getReason())->toBe(InvalidConfigurationReason::InvalidResult)
            ->and($thrown?->getConfigurationIssue())->toBe(
                'A decider is recorded on a tie that its results already decide: a decider settles a level tie and'
                . ' cannot confirm or overturn a result'
            )
            ->and($thrown?->getContext())->toBe(['decider' => ['p2'], 'advancer' => $advancer, 'participants' => ['p1', 'p2']]);
    })->with([
        'a single-leg event won by the participant it names' => ['won by the named', 1, 'p2'],
        'a single-leg event won by the other participant' => ['won by the other', 1, 'p1'],
        'an awarded single-leg event that names a winner' => ['awarded', 1, 'p1'],
        'two legs that the leg wins decide, on the first leg' => ['first leg', 2, 'p1'],
        'two legs that the leg wins decide, on the second leg' => ['second leg', 2, 'p2'],
    ]);

    it('refuses a decider that names more than the one participant a tie sends on', function (): void {
        $thrown = tieDecisionRefusal(
            [new Result($this->single, null, [], [], null, new Decider(['p1', 'p2']))],
            $this->alice,
            $this->bob,
            1
        );

        expect($thrown?->getReason())->toBe(InvalidConfigurationReason::InvalidResult)
            ->and($thrown?->getConfigurationIssue())->toBe('A tie between two participants sends one of them on, and its decider names 2')
            ->and($thrown?->getContext())->toBe(['decider' => ['p1', 'p2'], 'participants' => ['p1', 'p2']]);
    });

    it('refuses a decider that names a participant outside the tie', function (): void {
        // A result refuses a decider for a participant who is not in its
        // event, so this takes results of another event than the tie's:
        // the contract of advancer() is that the caller passes the tie's
        $carol = new Participant('p3', 'Carol');
        $foreign = new Event([$this->alice, $carol], new Round(1));

        $thrown = tieDecisionRefusal(
            [new Result($foreign, null, [], [], null, new Decider(['p3']))],
            $this->alice,
            $this->bob,
            1
        );

        expect($thrown?->getReason())->toBe(InvalidConfigurationReason::InvalidResult)
            ->and($thrown?->getConfigurationIssue())->toBe('Decider names a participant who is not in the tie')
            ->and($thrown?->getContext())->toBe(['decider' => ['p3'], 'participants' => ['p1', 'p2']]);
    });

    it('refuses a tie that carries a decider on each leg, agreeing or not', function (string $onFirst, string $onSecond): void {
        $thrown = tieDecisionRefusal(
            [
                new Result($this->leg1, $this->alice, [], [], null, new Decider([$onFirst])),
                new Result($this->leg2, $this->bob, [], [], null, new Decider([$onSecond])),
            ],
            $this->alice,
            $this->bob,
            2
        );

        expect($thrown?->getReason())->toBe(InvalidConfigurationReason::InvalidResult)
            ->and($thrown?->getConfigurationIssue())->toBe(
                'A tie has one decider, and 2 of its results carry one: record who advances on one result of the tie'
            )
            ->and($thrown?->getContext())->toBe(['deciders' => 2, 'participants' => ['p1', 'p2']]);
    })->with([
        'the same participant' => ['p1', 'p1'],
        'one each' => ['p1', 'p2'],
    ]);

    it('raises no deprecation: the decider is not the deprecated metadata', function (): void {
        $level = [new Result($this->single, null, [], [], null, new Decider(['p2']))];

        expect(TieWinnerDeprecation::countIn(fn() => TieDecision::advancer($level, $this->alice, $this->bob, 1)))->toBe(0);
    });
});

describe('TieDecision, a decider beside tie_winner metadata', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->single = new Event([$this->alice, $this->bob], new Round(1));
        $this->leg1 = new Event([$this->alice, $this->bob], new Round(1), ['tie_leg' => 1]);
        $this->leg2 = new Event([$this->bob, $this->alice], new Round(1), ['tie_leg' => 2]);
    });

    it('reads the decider, and raises nothing for metadata that names the same participant', function (): void {
        $level = [new Result($this->single, null, [], [TieWinnerDeprecation::KEY => 'p2'], null, new Decider(['p2']))];
        $advancer = null;

        $raised = TieWinnerDeprecation::countIn(function () use ($level, &$advancer): void {
            $advancer = TieDecision::advancer($level, $this->alice, $this->bob, 1);
        });

        expect($advancer)->toBe($this->bob)
            ->and($raised)->toBe(0);
    });

    it('refuses metadata that names another participant than the decider', function (int $legsPerTie, mixed $metadata): void {
        $decider = new Decider(['p2']);
        $results = $legsPerTie === 1
            // On the result that carries the decider
            ? [new Result($this->single, null, [], [TieWinnerDeprecation::KEY => $metadata], null, $decider)]
            // On the other leg of the tie
            : [
                new Result($this->leg1, null, [], [TieWinnerDeprecation::KEY => $metadata]),
                new Result($this->leg2, null, [], [], null, $decider),
            ];

        $thrown = tieDecisionRefusal($results, $this->alice, $this->bob, $legsPerTie);

        expect($thrown?->getReason())->toBe(InvalidConfigurationReason::InvalidResult)
            ->and($thrown?->getConfigurationIssue())->toBe(
                "The decider of a tie and the 'tie_winner' metadata of its results name different participants:"
                . ' record who advances once, as the decider, and remove the metadata key'
            )
            ->and($thrown?->getContext())->toBe(['decider' => ['p2'], 'tie_winner' => $metadata, 'participants' => ['p1', 'p2']]);
    })->with([
        'on the result that carries the decider' => [1],
        'on the other leg of the tie' => [2],
    ])->with([
        'the other side' => ['p1'],
        'nobody in the tie' => ['ghost'],
        'a value that is not an ID' => [2],
    ]);
});

describe('TieDecision, the deprecated tie_winner metadata', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->single = new Event([$this->alice, $this->bob], new Round(1));
        $this->leg1 = new Event([$this->alice, $this->bob], new Round(1), ['tie_leg' => 1]);
        $this->leg2 = new Event([$this->bob, $this->alice], new Round(1), ['tie_leg' => 2]);
    });

    it('still decides a level single-leg event, and says once that it is deprecated', function (): void {
        $level = fn(mixed $decision): array => [
            new Result($this->single, null, ['p1' => 1, 'p2' => 1], [TieWinnerDeprecation::KEY => $decision]),
        ];

        expect(TieWinnerDeprecation::once(fn() => TieDecision::advancer($level('p1'), $this->alice, $this->bob, 1)))->toBe($this->alice);
        expect(TieWinnerDeprecation::once(fn() => TieDecision::advancer($level('p2'), $this->alice, $this->bob, 1)))->toBe($this->bob);

        // The sides may be given in either order.
        expect(TieWinnerDeprecation::once(fn() => TieDecision::advancer($level('p2'), $this->bob, $this->alice, 1)))->toBe($this->bob);
    });

    it('still decides level legs, from either leg, and says once that it is deprecated', function (int $carrier): void {
        $legs = [
            new Result($this->leg1, $this->alice, [], $carrier === 1 ? [TieWinnerDeprecation::KEY => 'p1'] : []),
            new Result($this->leg2, $this->bob, [], $carrier === 2 ? [TieWinnerDeprecation::KEY => 'p1'] : []),
        ];

        expect(TieWinnerDeprecation::once(fn() => TieDecision::advancer($legs, $this->alice, $this->bob, 2)))->toBe($this->alice);
    })->with([1, 2]);

    it('gives the advancer the decider gives', function (int $legsPerTie, string $id): void {
        $events = $legsPerTie === 1 ? [$this->single] : [$this->leg1, $this->leg2];
        $last = count($events) - 1;

        $byDecider = [];
        $byMetadata = [];
        foreach ($events as $index => $event) {
            $byDecider[] = new Result($event, null, [], [], null, $index === $last ? new Decider([$id]) : null);
            $byMetadata[] = new Result($event, null, [], $index === $last ? [TieWinnerDeprecation::KEY => $id] : []);
        }

        expect(TieWinnerDeprecation::once(fn() => TieDecision::advancer($byMetadata, $this->alice, $this->bob, $legsPerTie)))
            ->toBe(TieDecision::advancer($byDecider, $this->alice, $this->bob, $legsPerTie));
    })->with([1, 2])->with(['p1', 'p2']);

    it('rejects a decision naming a participant outside the tie, for one leg as for two', function (): void {
        $oneLeg = tieDecisionRefusal([new Result($this->single, null, [], [TieWinnerDeprecation::KEY => 'ghost'])], $this->alice, $this->bob, 1);
        $twoLegs = tieDecisionRefusal([
            new Result($this->leg1),
            new Result($this->leg2, null, [], [TieWinnerDeprecation::KEY => 'ghost']),
        ], $this->alice, $this->bob, 2);

        expect($oneLeg?->getMessage())
            ->toBe('Invalid scheduler configuration: Tie decision names a participant who is not in the tie');
        expect($oneLeg?->getReason())->toBe(InvalidConfigurationReason::InvalidResult);
        expect($oneLeg?->getContext())->toBe(['tie_winner' => 'ghost', 'participants' => ['p1', 'p2']]);

        expect($twoLegs?->getMessage())->toBe($oneLeg?->getMessage());
        expect($twoLegs?->getReason())->toBe($oneLeg?->getReason());
        expect($twoLegs?->getContext())->toBe($oneLeg?->getContext());
    });

    it('is not read, and raises nothing, for a single-leg event that has a winner', function (mixed $decision): void {
        $result = new Result($this->single, $this->alice, [], [TieWinnerDeprecation::KEY => $decision]);
        $advancer = null;

        $raised = TieWinnerDeprecation::countIn(function () use ($result, &$advancer): void {
            $advancer = TieDecision::advancer([$result], $this->alice, $this->bob, 1);
        });

        expect($advancer)->toBe($this->alice)
            ->and($raised)->toBe(0);
    })->with([
        'the winner' => ['p1'],
        'the loser' => ['p2'],
        'nobody in the tie' => ['ghost'],
    ]);

    it('is not read, and raises nothing, for a two-legged tie the legs decide', function (mixed $decision): void {
        $legs = [
            new Result($this->leg1, $this->alice),
            new Result($this->leg2, null, [], [TieWinnerDeprecation::KEY => $decision]),
        ];
        $advancer = null;

        $raised = TieWinnerDeprecation::countIn(function () use ($legs, &$advancer): void {
            $advancer = TieDecision::advancer($legs, $this->alice, $this->bob, 2);
        });

        expect($advancer)->toBe($this->alice)
            ->and($raised)->toBe(0);
    })->with([
        'the winner' => ['p1'],
        'the loser' => ['p2'],
        'nobody in the tie' => ['ghost'],
    ]);

    it('is no decision when its value is null: the tie is undecided, and nothing is raised', function (): void {
        $thrown = null;
        $raised = TieWinnerDeprecation::countIn(function () use (&$thrown): void {
            $thrown = tieDecisionRefusal(
                [new Result($this->single, null, [], [TieWinnerDeprecation::KEY => null])],
                $this->alice,
                $this->bob,
                1
            );
        });

        expect($thrown?->getReason())->toBe(InvalidConfigurationReason::UndecidedTie)
            ->and($raised)->toBe(0);
    });
});
