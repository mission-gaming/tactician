<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Stage\BracketNode;
use MissionGaming\Tactician\Stage\BracketNodeStatus;
use MissionGaming\Tactician\Stage\BracketSide;
use MissionGaming\Tactician\Stage\BracketSlot;
use MissionGaming\Tactician\Stage\SlotSource;

// A node of the bracket view carries the result of each of its events, in
// leg order (issue #181). The engines build the nodes; these cases hold
// the node to its contract whoever builds it.

describe('BracketNode', function (): void {
    beforeEach(function (): void {
        $this->p1 = new Participant('p1', 'Entrant 1');
        $this->p2 = new Participant('p2', 'Entrant 2');
        $this->slots = [
            new BracketSlot($this->p1, null, SlotSource::Draw, [], false, 1),
            new BracketSlot($this->p2, null, SlotSource::Draw, [], false, 2),
        ];
        $this->first = new Event([$this->p1, $this->p2], new Round(1), ['tie_leg' => 1]);
        $this->second = new Event([$this->p2, $this->p1], new Round(1), ['tie_leg' => 2]);
        $this->node = fn(array $events, ?array $results, BracketNodeStatus $status = BracketNodeStatus::InPlay): BracketNode => new BracketNode(
            side: BracketSide::Main,
            round: 1,
            position: 1,
            kind: null,
            slots: $this->slots,
            status: $status,
            events: $events,
            results: $results,
            roundNumber: $events === [] ? null : 1
        );
    });

    it('holds a result or null for each event, in the order of the events', function (): void {
        $first = new Result($this->first, $this->p1, ['p1' => 2, 'p2' => 0]);
        $node = ($this->node)([$this->first, $this->second], [$first, null]);

        expect($node->getResults())->toBe([$first, null])
            ->and($node->getEvents())->toBe([$this->first, $this->second]);
    });

    it('reads the results of a list with keys as a list', function (): void {
        $second = new Result($this->second, $this->p2);
        $node = ($this->node)(['a' => $this->first, 'b' => $this->second], ['x' => null, 'y' => $second]);

        expect($node->getResults())->toBe([null, $second]);
    });

    it('has no result for any event when it is given none', function (): void {
        expect(($this->node)([$this->first, $this->second], null)->getResults())->toBe([null, null])
            ->and(($this->node)([], null, BracketNodeStatus::ToBePaired)->getResults())->toBe([])
            ->and(($this->node)([], [], BracketNodeStatus::ToBePaired)->getResults())->toBe([]);
    });

    it('matches a result to its event as the state does: round, participants in either order, and leg', function (): void {
        // The result names an equal event, not the same object, with its participants the other way round
        $equal = new Event([$this->p2, $this->p1], new Round(1), ['tie_leg' => 1]);
        $result = new Result($equal, $this->p1);

        expect(($this->node)([$this->first], [$result])->getResults())->toBe([$result]);
    });

    it('refuses a list of results that is not one per event', function (): void {
        $first = new Result($this->first, $this->p1);

        expect(fn() => ($this->node)([$this->first, $this->second], [$first]))
            ->toThrow(InvalidInputException::class, 'one result or null for each event')
            ->and(fn() => ($this->node)([], [null], BracketNodeStatus::ToBePaired))
            ->toThrow(InvalidInputException::class, 'one result or null for each event');
    });

    it('refuses a result of another event than the one at its place', function (): void {
        $first = new Result($this->first, $this->p1);
        $second = new Result($this->second, $this->p2);
        $otherRound = new Result(new Event([$this->p1, $this->p2], new Round(2), ['tie_leg' => 1]), $this->p1);

        expect(fn() => ($this->node)([$this->first, $this->second], [$second, $first]))
            ->toThrow(InvalidInputException::class, 'is not of the event at its place')
            ->and(fn() => ($this->node)([$this->first], [$otherRound]))
            ->toThrow(InvalidInputException::class, 'is not of the event at its place');
    });
});
