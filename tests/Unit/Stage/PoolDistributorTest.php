<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Stage\PoolDistributor;

/**
 * A field in seeding order: position 1 is the top entrant.
 *
 * @return array<Participant>
 */
function poolField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("t{$i}", "Team {$i}", $i);
    }

    return $participants;
}

describe('PoolDistributor', function (): void {
    it('distributes participants into serpentine-seeded pools by position', function (): void {
        $pools = PoolDistributor::serpentine(poolField(8), 2);

        $idsByPool = array_map(
            fn (array $pool) => array_map(fn (Participant $p) => $p->getId(), $pool),
            $pools
        );

        expect($idsByPool)->toBe([
            'A' => ['t1', 't4', 't5', 't8'],
            'B' => ['t2', 't3', 't6', 't7'],
        ]);
    });

    it('balances three pools with the snake pattern', function (): void {
        $pools = PoolDistributor::serpentine(poolField(6), 3);

        $idsByPool = array_map(
            fn (array $pool) => array_map(fn (Participant $p) => $p->getId(), $pool),
            $pools
        );

        expect($idsByPool)->toBe([
            'A' => ['t1', 't6'],
            'B' => ['t2', 't5'],
            'C' => ['t3', 't4'],
        ]);
    });

    // Position is authoritative: the list is dealt as given, so a consumer
    // whose own ordering differs from carried seed attributes gets exactly
    // the pools its ordering implies
    it('deals from list position, not carried seed attributes', function (): void {
        $participants = [
            new Participant('first', 'Listed First', 99),
            new Participant('second', 'Listed Second'),
            new Participant('third', 'Listed Third', 1),
            new Participant('fourth', 'Listed Fourth', 2),
        ];

        $pools = PoolDistributor::serpentine($participants, 2);

        expect(array_map(fn (Participant $p) => $p->getId(), $pools['A']))->toBe(['first', 'fourth']);
        expect(array_map(fn (Participant $p) => $p->getId(), $pools['B']))->toBe(['second', 'third']);
    });

    it('rejects invalid pool configurations', function (): void {
        expect(fn () => PoolDistributor::serpentine(poolField(8), 0))
            ->toThrow(InvalidConfigurationException::class);
        expect(fn () => PoolDistributor::serpentine(poolField(8), 27))
            ->toThrow(InvalidConfigurationException::class);
        expect(fn () => PoolDistributor::serpentine(poolField(3), 2))
            ->toThrow(InvalidConfigurationException::class);

        $duplicates = [new Participant('t1', 'One'), new Participant('t1', 'Clone'), new Participant('t2', 'Two'), new Participant('t3', 'Three')];
        expect(fn () => PoolDistributor::serpentine($duplicates, 2))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('splits results by pool and rejects cross-pool results', function (): void {
        $pools = PoolDistributor::serpentine(poolField(8), 2);

        $resultA = new Result(new Event([$pools['A'][0], $pools['A'][1]]), $pools['A'][0]);
        $resultB = new Result(new Event([$pools['B'][0], $pools['B'][1]]), $pools['B'][0]);

        $split = PoolDistributor::splitResults($pools, [$resultA, $resultB]);
        expect($split['A'])->toBe([$resultA]);
        expect($split['B'])->toBe([$resultB]);

        $crossPool = new Result(new Event([$pools['A'][0], $pools['B'][0]]), $pools['A'][0]);
        expect(fn () => PoolDistributor::splitResults($pools, [$crossPool]))
            ->toThrow(InvalidConfigurationException::class, 'spans multiple pools');

        $stranger = new Result(
            new Event([new Participant('x1', 'X'), new Participant('x2', 'Y')]),
            null
        );
        expect(fn () => PoolDistributor::splitResults($pools, [$stranger]))
            ->toThrow(InvalidConfigurationException::class, 'not in any pool');
    });
});
