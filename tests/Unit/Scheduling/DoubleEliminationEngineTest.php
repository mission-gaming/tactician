<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;

/**
 * @return array<Participant>
 */
function doubleElimField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("s{$i}", "Seed {$i}", $i);
    }

    return $participants;
}

/**
 * Record wins for the better (lower) seed in every event.
 *
 * @param array<Event> $events
 * @return array<Result>
 */
function doubleElimFavourites(array $events): array
{
    return array_map(function (Event $event) {
        $participants = $event->getParticipants();
        $winner = ($participants[0]->getSeed() ?? PHP_INT_MAX) < ($participants[1]->getSeed() ?? PHP_INT_MAX)
            ? $participants[0]
            : $participants[1];

        return new Result($event, $winner);
    }, $events);
}

describe('DoubleEliminationEngine', function (): void {
    it('sequences winners, losers, and grand final stages for four participants', function (): void {
        $engine = new DoubleEliminationEngine();
        $participants = doubleElimField(4);

        $results = [];
        $stages = [];
        $champion = null;
        while ($champion === null) {
            $pairing = $engine->pairNextRound($participants, $results);
            $stages[$pairing->getRoundNumber()] = $pairing->getLabel();
            $results = [...$results, ...doubleElimFavourites($pairing->getEvents())];
            $champion = $engine->getChampion($participants, $results);
        }

        expect($stages)->toBe([
            1 => 'winners round 1',
            2 => 'losers round 1',
            3 => 'winners final',
            4 => 'losers final',
            5 => 'grand final',
        ]);
        expect($champion->getId())->toBe('s1');
        expect($results)->toHaveCount(6); // 2n - 2 with no reset
    });

    it('routes first-round losers through the losers bracket', function (): void {
        $engine = new DoubleEliminationEngine();
        $participants = doubleElimField(4);

        $round1 = $engine->pairNextRound($participants, []);
        $results = doubleElimFavourites($round1->getEvents());

        $losersRound = $engine->pairNextRound($participants, $results);

        expect($losersRound->getLabel())->toBe('losers round 1');
        $ids = array_map(
            fn (Participant $p) => $p->getId(),
            $losersRound->getEvents()[0]->getParticipants()
        );
        sort($ids);
        expect($ids)->toBe(['s3', 's4']);
    });

    it('plays a reset when the losers champion wins the grand final', function (): void {
        $engine = new DoubleEliminationEngine();
        $participants = doubleElimField(2);

        // Winners round: s1 beats s2, sending s2 to the losers side
        $winnersRound = $engine->pairNextRound($participants, []);
        expect($winnersRound->getLabel())->toBe('winners final');
        $results = doubleElimFavourites($winnersRound->getEvents());

        // Grand final: s2 wins, so both finalists have one loss
        $grandFinal = $engine->pairNextRound($participants, $results);
        expect($grandFinal->getLabel())->toBe('grand final');
        $results[] = new Result($grandFinal->getEvents()[0], $participants[1]);

        expect($engine->getChampion($participants, $results))->toBeNull();

        $reset = $engine->pairNextRound($participants, $results);
        expect($reset->getLabel())->toBe('grand final reset');
        $results[] = new Result($reset->getEvents()[0], $participants[1]);

        expect($engine->getChampion($participants, $results)?->getId())->toBe('s2');
    });

    it('skips the reset when configured for a single grand final', function (): void {
        $engine = new DoubleEliminationEngine(grandFinalReset: false);
        $participants = doubleElimField(2);

        $winnersRound = $engine->pairNextRound($participants, []);
        $results = doubleElimFavourites($winnersRound->getEvents());

        $grandFinal = $engine->pairNextRound($participants, $results);
        $results[] = new Result($grandFinal->getEvents()[0], $participants[1]);

        expect($engine->getChampion($participants, $results)?->getId())->toBe('s2');
    });

    it('does not play a reset when the winners champion wins the grand final', function (): void {
        $engine = new DoubleEliminationEngine();
        $participants = doubleElimField(2);

        $winnersRound = $engine->pairNextRound($participants, []);
        $results = doubleElimFavourites($winnersRound->getEvents());

        $grandFinal = $engine->pairNextRound($participants, $results);
        $results[] = new Result($grandFinal->getEvents()[0], $participants[0]);

        expect($engine->getChampion($participants, $results)?->getId())->toBe('s1');
        expect(fn () => $engine->pairNextRound($participants, $results))
            ->toThrow(InvalidConfigurationException::class, 'complete');
    });

    it('propagates byes through the losers bracket without consuming rounds', function (): void {
        $engine = new DoubleEliminationEngine();
        $participants = doubleElimField(3);

        $results = [];
        $stages = [];
        $byesByStage = [];
        $champion = null;
        while ($champion === null) {
            $pairing = $engine->pairNextRound($participants, $results);
            $stages[$pairing->getRoundNumber()] = $pairing->getLabel();
            $byesByStage[$pairing->getLabel()] = array_map(
                fn (Participant $p) => $p->getId(),
                $pairing->getByes()
            );
            $results = [...$results, ...doubleElimFavourites($pairing->getEvents())];
            $champion = $engine->getChampion($participants, $results);
        }

        // The losers bracket's first structural round has no playable match
        // (only one first-round loser exists), so round numbers stay dense.
        expect($stages)->toBe([
            1 => 'winners round 1',
            2 => 'winners final',
            3 => 'losers final',
            4 => 'grand final',
        ]);
        expect($byesByStage['winners round 1'])->toBe(['s1']);
        expect($champion->getId())->toBe('s1');
    });

    it('runs a full eight-participant bracket in 2n - 2 matches when seeds hold', function (): void {
        $engine = new DoubleEliminationEngine();
        $participants = doubleElimField(8);

        $results = [];
        $stages = [];
        $champion = null;
        while ($champion === null) {
            $pairing = $engine->pairNextRound($participants, $results);
            $stages[] = $pairing->getLabel();
            $results = [...$results, ...doubleElimFavourites($pairing->getEvents())];
            $champion = $engine->getChampion($participants, $results);
        }

        expect($champion->getId())->toBe('s1');
        expect($results)->toHaveCount(14); // 2n - 2 with no reset
        expect($stages)->toBe([
            'winners round 1',
            'losers round 1',
            'winners round 2',
            'losers round 2',
            'losers round 3',
            'winners final',
            'losers final',
            'grand final',
        ]);
    });

    it('lets a winners-final loser fight back to win the title through a reset', function (): void {
        $engine = new DoubleEliminationEngine();
        $participants = doubleElimField(4);
        $bySeed = [];
        foreach ($participants as $participant) {
            $bySeed[$participant->getId()] = $participant;
        }

        $results = [];
        $champion = null;
        while ($champion === null) {
            $pairing = $engine->pairNextRound($participants, $results);
            foreach ($pairing->getEvents() as $event) {
                $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
                sort($ids);
                // s2 beats s1 in the winners final; s1 wins everything else
                $winnerId = match (true) {
                    $pairing->getLabel() === 'winners final' => 's2',
                    in_array('s1', $ids, true) => 's1',
                    default => $ids[0],
                };
                $results[] = new Result($event, $bySeed[$winnerId]);
            }
            $champion = $engine->getChampion($participants, $results);
        }

        // s1 dropped to the losers bracket, won the losers final, took the
        // grand final off s2, and won the reset.
        expect($champion->getId())->toBe('s1');
        expect($results)->toHaveCount(7); // 2n - 1 with a reset
    });

    it('rejects drawn results and partially resolved stages', function (): void {
        $engine = new DoubleEliminationEngine();
        $participants = doubleElimField(4);
        $round1 = $engine->pairNextRound($participants, []);

        $drawn = [new Result($round1->getEvents()[0]), new Result($round1->getEvents()[1], $participants[1])];
        expect(fn () => $engine->pairNextRound($participants, $drawn))
            ->toThrow(InvalidConfigurationException::class, 'draw');

        $partial = [new Result($round1->getEvents()[0], $participants[0])];
        expect(fn () => $engine->pairNextRound($participants, $partial))
            ->toThrow(InvalidConfigurationException::class, 'partially resolved');
    });

    it('rejects invalid participant lists', function (): void {
        $engine = new DoubleEliminationEngine();

        expect(fn () => $engine->pairNextRound([new Participant('p1', 'Solo')], []))
            ->toThrow(InvalidConfigurationException::class);
        expect(fn () => $engine->pairNextRound(
            [new Participant('p1', 'One'), new Participant('p1', 'Clone')],
            []
        ))->toThrow(InvalidConfigurationException::class);
    });
});
