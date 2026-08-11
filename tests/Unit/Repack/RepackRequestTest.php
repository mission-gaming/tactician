<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\SessionGrid;

/**
 * @throws InvalidConfigurationException
 * @throws Exception
 */
function repackRequestGrid(int $capacityPerSlot = 1): SessionGrid
{
    return new SessionGrid(
        [new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('UTC'))],
        new DateInterval('PT25M'),
        4,
        [],
        $capacityPerSlot
    );
}

describe('RepackRequest', function (): void {
    it('rejects a movable event with an empty id', function (): void {
        new MovableEvent('', new Participant('a', 'A'), new Participant('b', 'B'));
    })->throws(InvalidConfigurationException::class);

    it('rejects an event whose participants are the same', function (): void {
        new MovableEvent('e1', new Participant('a', 'A'), new Participant('a', 'A again'));
    })->throws(InvalidConfigurationException::class);

    it('rejects a pinned event with negative indexes', function (): void {
        new PinnedEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'), -1, 0);
    })->throws(InvalidConfigurationException::class);

    it('rejects duplicate event ids across movable and pinned lists', function (): void {
        new RepackRequest(
            [new MovableEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'))],
            [new PinnedEvent('e1', new Participant('c', 'C'), new Participant('d', 'D'), 0, 0)],
            repackRequestGrid()
        );
    })->throws(InvalidConfigurationException::class);

    it('rejects a pin that is not on a grid position', function (): void {
        new RepackRequest(
            [],
            [new PinnedEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'), 0, 4)],
            repackRequestGrid()
        );
    })->throws(InvalidConfigurationException::class);

    it('rejects pins overflowing a slot capacity', function (): void {
        new RepackRequest(
            [],
            [
                new PinnedEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'), 0, 0),
                new PinnedEvent('e2', new Participant('c', 'C'), new Participant('d', 'D'), 0, 0),
            ],
            repackRequestGrid(1)
        );
    })->throws(InvalidConfigurationException::class);

    it('rejects a participant pinned twice at one position', function (): void {
        new RepackRequest(
            [],
            [
                new PinnedEvent('e1', new Participant('a', 'A'), new Participant('b', 'B'), 0, 0),
                new PinnedEvent('e2', new Participant('a', 'A'), new Participant('c', 'C'), 0, 0),
            ],
            repackRequestGrid(2)
        );
    })->throws(InvalidConfigurationException::class);

    it('accepts corrupt-free input and exposes it unchanged', function (): void {
        $movable = new MovableEvent('m1', new Participant('a', 'A'), new Participant('b', 'B'));
        $pinned = new PinnedEvent('p1', new Participant('a', 'A'), new Participant('c', 'C'), 0, 2);

        $request = new RepackRequest([$movable], [$pinned], repackRequestGrid(2));

        expect($request->getMovableEvents())->toBe([$movable]);
        expect($request->getPinnedEvents())->toBe([$pinned]);
        expect($request->getOptions()->consolidationWeight)->toBe(3);
    });
});
