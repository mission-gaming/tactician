<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Stage\EventKey;
use MissionGaming\Tactician\Stage\FormatMetadataKey;

describe('the key of a recorded event', function (): void {
    beforeEach(function (): void {
        $this->a = new Participant('a', 'A');
        $this->b = new Participant('b', 'B');
        $this->c = new Participant('c', 'C');
    });

    it('is the same whichever participant is named first, and whatever else the event carries', function (): void {
        $key = EventKey::of(new Event([$this->a, $this->b], new Round(2)));

        expect(EventKey::of(new Event([$this->b, $this->a], new Round(2))))->toBe($key)
            ->and(EventKey::of(new Event(['x' => $this->b, 'y' => $this->a], new Round(2, ['label' => 'Second']), ['venue' => 'North'])))->toBe($key)
            // A tie leg of 1 is the leg of an event that names none
            ->and(EventKey::of(new Event([$this->a, $this->b], new Round(2), [FormatMetadataKey::TIE_LEG => 1])))->toBe($key);
    });

    it('tells events apart by round, participants and tie leg', function (): void {
        $keys = [
            EventKey::of(new Event([$this->a, $this->b], new Round(2))),
            EventKey::of(new Event([$this->a, $this->b], new Round(3))),
            EventKey::of(new Event([$this->a, $this->c], new Round(2))),
            EventKey::of(new Event([$this->a, $this->b], new Round(2), [FormatMetadataKey::TIE_LEG => 2])),
            // An event without a round number is keyed as round 0
            EventKey::of(new Event([$this->a, $this->b])),
        ];

        expect(array_unique($keys))->toHaveCount(count($keys));
    });
});
