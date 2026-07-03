<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;

/**
 * @return array<Participant>
 */
function seededField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("s{$i}", "Seed {$i}", $i);
    }

    return $participants;
}

/**
 * @param array<Event> $events
 * @return array<array<string>>
 */
function eventIdPairs(array $events): array
{
    return array_map(
        fn (Event $event) => array_map(fn (Participant $p) => $p->getId(), $event->getParticipants()),
        $events
    );
}

describe('SingleEliminationEngine', function (): void {
    it('pairs round 1 with standard fold seeding for a full bracket', function (): void {
        $pairing = (new SingleEliminationEngine())->pairNextRound(seededField(8), []);

        expect($pairing->getRoundNumber())->toBe(1);
        expect($pairing->getStage())->toBe('quarterfinal');
        expect($pairing->hasByes())->toBeFalse();
        expect(eventIdPairs($pairing->getEvents()))->toBe([
            ['s1', 's8'],
            ['s4', 's5'],
            ['s2', 's7'],
            ['s3', 's6'],
        ]);
    });

    it('gives byes to the top seeds when the field is not a power of two', function (): void {
        $pairing = (new SingleEliminationEngine())->pairNextRound(seededField(6), []);

        expect($pairing->getStage())->toBe('quarterfinal');
        expect(array_map(fn (Participant $p) => $p->getId(), $pairing->getByes()))->toBe(['s1', 's2']);
        expect(eventIdPairs($pairing->getEvents()))->toBe([
            ['s4', 's5'],
            ['s3', 's6'],
        ]);
    });

    it('advances winners and bye recipients into the next round in bracket order', function (): void {
        $engine = new SingleEliminationEngine();
        $participants = seededField(6);
        $round1 = $engine->pairNextRound($participants, []);

        // Upset: seed 5 beats seed 4; seed 3 holds
        $results = [
            new Result($round1->getEvents()[0], $participants[4]), // s5 beats s4
            new Result($round1->getEvents()[1], $participants[2]), // s3 beats s6
        ];

        $round2 = $engine->pairNextRound($participants, $results);

        expect($round2->getRoundNumber())->toBe(2);
        expect($round2->getStage())->toBe('semifinal');
        expect($round2->hasByes())->toBeFalse();
        expect(eventIdPairs($round2->getEvents()))->toBe([
            ['s1', 's5'],
            ['s2', 's3'],
        ]);
    });

    it('names stages through to the final', function (): void {
        $engine = new SingleEliminationEngine();
        $participants = seededField(16);

        expect($engine->getTotalRounds($participants))->toBe(4);
        expect($engine->pairNextRound($participants, [])->getStage())->toBe('round of 16');
    });

    it('crowns a champion once the final is resolved', function (): void {
        $engine = new SingleEliminationEngine();
        $participants = seededField(4);

        expect($engine->getChampion($participants, []))->toBeNull();

        $round1 = $engine->pairNextRound($participants, []);
        $results = [
            new Result($round1->getEvents()[0], $participants[0]), // s1 beats s4
            new Result($round1->getEvents()[1], $participants[1]), // s2 beats s3
        ];

        expect($engine->getChampion($participants, $results))->toBeNull();

        $final = $engine->pairNextRound($participants, $results);
        expect($final->getStage())->toBe('final');
        expect(eventIdPairs($final->getEvents()))->toBe([['s1', 's2']]);

        $results[] = new Result($final->getEvents()[0], $participants[1]); // s2 wins the final

        expect($engine->getChampion($participants, $results)?->getId())->toBe('s2');
        expect(fn () => $engine->pairNextRound($participants, $results))
            ->toThrow(InvalidConfigurationException::class, 'complete');
    });

    it('rejects drawn results', function (): void {
        $engine = new SingleEliminationEngine();
        $participants = seededField(4);
        $round1 = $engine->pairNextRound($participants, []);

        $results = [
            new Result($round1->getEvents()[0]), // draw
            new Result($round1->getEvents()[1], $participants[1]),
        ];

        expect(fn () => $engine->pairNextRound($participants, $results))
            ->toThrow(InvalidConfigurationException::class, 'draw');
    });

    it('rejects pairing the next round while the current round is partially resolved', function (): void {
        $engine = new SingleEliminationEngine();
        $participants = seededField(8);
        $round1 = $engine->pairNextRound($participants, []);

        $results = [
            new Result($round1->getEvents()[0], $participants[0]),
        ];

        expect(fn () => $engine->pairNextRound($participants, $results))
            ->toThrow(InvalidConfigurationException::class, 'partially resolved');
    });

    it('places unseeded participants in input order behind seeded ones', function (): void {
        $participants = [
            new Participant('u1', 'Unseeded One'),
            new Participant('u2', 'Unseeded Two'),
            new Participant('top', 'Top Seed', 1),
        ];

        $pairing = (new SingleEliminationEngine())->pairNextRound($participants, []);

        // Order: top (seed 1), u1, u2 -> bracket of 4: (top, bye), (u1, u2)
        expect(array_map(fn (Participant $p) => $p->getId(), $pairing->getByes()))->toBe(['top']);
        expect(eventIdPairs($pairing->getEvents()))->toBe([['u1', 'u2']]);
    });

    it('rejects two results for the same match', function (): void {
        $engine = new SingleEliminationEngine();
        $participants = seededField(4);
        $round1 = $engine->pairNextRound($participants, []);

        // A conflicting second result for the same match must not silently win
        $results = [
            new Result($round1->getEvents()[0], $participants[0]),
            new Result($round1->getEvents()[0], $participants[3]),
            new Result($round1->getEvents()[1], $participants[1]),
        ];

        expect(fn () => $engine->pairNextRound($participants, $results))
            ->toThrow(InvalidConfigurationException::class, 'same elimination match');
    });

    it('rejects results whose event has no round number', function (): void {
        $engine = new SingleEliminationEngine();
        $participants = seededField(4);

        // A self-constructed, round-less event must error rather than be
        // silently treated as unplayed (which would hang driver loops)
        $results = [
            new Result(new Event([$participants[0], $participants[3]]), $participants[0]),
        ];

        expect(fn () => $engine->pairNextRound($participants, $results))
            ->toThrow(InvalidConfigurationException::class, 'round number');
    });

    it('rejects fewer than two participants and duplicate IDs', function (): void {
        $engine = new SingleEliminationEngine();

        expect(fn () => $engine->pairNextRound([new Participant('p1', 'Solo')], []))
            ->toThrow(InvalidConfigurationException::class);

        expect(fn () => $engine->pairNextRound(
            [new Participant('p1', 'One'), new Participant('p1', 'Clone')],
            []
        ))->toThrow(InvalidConfigurationException::class);
    });

    it('runs a full bracket where seeds hold to a seed 1 vs seed 2 final', function (): void {
        $engine = new SingleEliminationEngine();
        $participants = seededField(8);
        $participantsById = [];
        foreach ($participants as $participant) {
            $participantsById[$participant->getId()] = $participant;
        }

        $results = [];
        $champion = $engine->getChampion($participants, $results);
        while ($champion === null) {
            $pairing = $engine->pairNextRound($participants, $results);
            foreach ($pairing->getEvents() as $event) {
                // The better (lower) seed always wins
                $eventParticipants = $event->getParticipants();
                $winner = ($eventParticipants[0]->getSeed() ?? PHP_INT_MAX) < ($eventParticipants[1]->getSeed() ?? PHP_INT_MAX)
                    ? $eventParticipants[0]
                    : $eventParticipants[1];
                $results[] = new Result($event, $winner);
            }
            $champion = $engine->getChampion($participants, $results);
        }

        expect($champion?->getId())->toBe('s1');
        expect($results)->toHaveCount(7); // 4 + 2 + 1

        // The final was seed 1 vs seed 2
        $finalEvent = $results[6]->getEvent();
        $finalIds = array_map(fn (Participant $p) => $p->getId(), $finalEvent->getParticipants());
        sort($finalIds);
        expect($finalIds)->toBe(['s1', 's2']);
        expect($finalEvent->getRound()?->getMetadataValue('stage'))->toBe('final');
    });
});
