<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\TieDecision;

/**
 * A field in seeding order: position 1 is the top entrant.
 *
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

/**
 * Play every event of a pairing with the given winner picker and record
 * the round onto the state.
 *
 * @param callable(Event): Participant $pickWinner
 * @throws InvalidConfigurationException
 */
function playBracketRound(StageState $state, \MissionGaming\Tactician\Stage\RoundPairing $pairing, callable $pickWinner): StageState
{
    $results = [];
    foreach ($pairing->getEvents() as $event) {
        $results[] = new Result($event, $pickWinner($event));
    }

    return $state->withRoundPlayed($pairing, $results);
}

/**
 * The better (lower position) entrant always wins.
 */
function favouriteWins(Event $event): Participant
{
    [$first, $second] = $event->getParticipants();

    return ($first->getSeed() ?? PHP_INT_MAX) < ($second->getSeed() ?? PHP_INT_MAX) ? $first : $second;
}

describe('SingleEliminationEngine', function (): void {
    it('is a stage engine', function (): void {
        expect(new SingleEliminationEngine())->toBeInstanceOf(StageEngineInterface::class);
    });

    it('pairs round 1 with standard fold seeding over list position', function (): void {
        $pairing = (new SingleEliminationEngine())->pairNextRound(StageState::start(seededField(8)));

        expect($pairing->getRoundNumber())->toBe(1);
        expect($pairing->getLabel())->toBe('quarterfinal');
        expect($pairing->hasByes())->toBeFalse();
        expect(eventIdPairs($pairing->getEvents()))->toBe([
            ['s1', 's8'],
            ['s4', 's5'],
            ['s2', 's7'],
            ['s3', 's6'],
        ]);
    });

    it('gives byes to the top positions when the field is not a power of two', function (): void {
        $pairing = (new SingleEliminationEngine())->pairNextRound(StageState::start(seededField(6)));

        expect($pairing->getLabel())->toBe('quarterfinal');
        expect(array_map(fn (Participant $p) => $p->getId(), $pairing->getByes()))->toBe(['s1', 's2']);
        expect(eventIdPairs($pairing->getEvents()))->toBe([
            ['s4', 's5'],
            ['s3', 's6'],
        ]);
    });

    // Position is authoritative: carried seed attributes are display facts,
    // not pairing inputs, so consumer-derived lists and selector outputs
    // behave identically by construction
    it('seeds from list position and ignores carried seed attributes', function (): void {
        $participants = [
            new Participant('first', 'Listed First', 99),
            new Participant('second', 'Listed Second'),
            new Participant('third', 'Listed Third', 1),
        ];

        $pairing = (new SingleEliminationEngine())->pairNextRound(StageState::start($participants));

        // Bracket of 4 over positions: (first, bye), (second, third)
        expect(array_map(fn (Participant $p) => $p->getId(), $pairing->getByes()))->toBe(['first']);
        expect(eventIdPairs($pairing->getEvents()))->toBe([['second', 'third']]);
    });

    it('advances winners and bye recipients into the next round in bracket order', function (): void {
        $engine = new SingleEliminationEngine();
        $participants = seededField(6);
        $state = StageState::start($participants);
        $round1 = $engine->pairNextRound($state);

        // Upset: seed 5 beats seed 4; seed 3 holds
        $state = $state->withRoundPlayed($round1, [
            new Result($round1->getEvents()[0], $participants[4]), // s5 beats s4
            new Result($round1->getEvents()[1], $participants[2]), // s3 beats s6
        ]);

        $round2 = $engine->pairNextRound($state);

        expect($round2->getRoundNumber())->toBe(2);
        expect($round2->getLabel())->toBe('semifinal');
        expect($round2->hasByes())->toBeFalse();
        expect(eventIdPairs($round2->getEvents()))->toBe([
            ['s1', 's5'],
            ['s2', 's3'],
        ]);
    });

    it('declares its plan and labels rounds through to the final', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(seededField(16));

        $plan = $engine->getPlan($state);
        expect($plan->getAlgorithm())->toBe('single-elimination');
        expect($plan->getTotalRounds())->toBe(4);
        expect($plan->getExpectedEventCount())->toBe(15);
        expect($plan->getLegs())->toBeNull();
        expect($plan->getLegsPerTie())->toBe(1);

        expect($engine->pairNextRound($state)->getLabel())->toBe('round of 16');
    });

    it('completes once the final is resolved, with bracket-placement standings', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(seededField(4));

        expect($engine->isComplete($state))->toBeFalse();
        expect($engine->getOutcome($state))->toBeNull();

        while (!$engine->isComplete($state)) {
            $state = playBracketRound($state, $engine->pairNextRound($state), favouriteWins(...));
        }

        $outcome = $engine->getOutcome($state);
        expect($outcome)->not->toBeNull();
        assert($outcome !== null);

        // Rank 1 of the standings is the consumer's "champion"
        $entries = $outcome->getStandings()->getEntries();
        expect($entries[0]->getParticipant()->getId())->toBe('s1');
        expect($entries[0]->getWins())->toBe(2);
        expect($entries[1]->getParticipant()->getId())->toBe('s2'); // 1-1, better score diff? no: 1 win 1 loss
        expect($outcome->getFinalRound()?->getLabel())->toBe('final');

        expect(fn () => $engine->pairNextRound($state))
            ->toThrow(InvalidConfigurationException::class, 'complete');
    });

    it('rejects drawn results in single-leg ties', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(seededField(4));
        $round1 = $engine->pairNextRound($state);

        $state = $state->withRoundPlayed($round1, [
            new Result($round1->getEvents()[0]), // draw
            new Result($round1->getEvents()[1], $round1->getEvents()[1]->getParticipants()[0]),
        ]);

        expect(fn () => $engine->pairNextRound($state))
            ->toThrow(InvalidConfigurationException::class, 'draw');
    });

    it('rejects pairing the next round while the current round is partially resolved', function (): void {
        $engine = new SingleEliminationEngine();
        $participants = seededField(8);
        $state = StageState::start($participants);
        $round1 = $engine->pairNextRound($state);

        $state = $state->withRoundPlayed($round1, [
            new Result($round1->getEvents()[0], $participants[0]),
        ]);

        expect(fn () => $engine->pairNextRound($state))
            ->toThrow(InvalidConfigurationException::class, 'partially resolved');
    });

    it('completes a partially recorded round via additional results', function (): void {
        $engine = new SingleEliminationEngine();
        $participants = seededField(4);
        $state = StageState::start($participants);
        $round1 = $engine->pairNextRound($state);

        $state = $state->withRoundPlayed($round1, [
            new Result($round1->getEvents()[0], $participants[0]),
        ]);
        $state = $state->withAdditionalResults([
            new Result($round1->getEvents()[1], $participants[1]),
        ]);

        $final = $engine->pairNextRound($state);
        expect(eventIdPairs($final->getEvents()))->toBe([['s1', 's2']]);
    });

    it('rejects two results for the same match', function (): void {
        $engine = new SingleEliminationEngine();
        $participants = seededField(4);
        $state = StageState::start($participants);
        $round1 = $engine->pairNextRound($state);

        $state = $state->withRoundPlayed($round1, [
            new Result($round1->getEvents()[0], $participants[0]),
            new Result($round1->getEvents()[0], $participants[3]),
            new Result($round1->getEvents()[1], $participants[1]),
        ]);

        expect(fn () => $engine->pairNextRound($state))
            ->toThrow(InvalidConfigurationException::class, 'same elimination match');
    });

    // Normal recording cannot produce these, but hand-crafted serialized
    // state can: results must reference round-numbered pairwise events
    it('rejects malformed results arriving via rehydrated state', function (): void {
        $engine = new SingleEliminationEngine();

        $base = [
            'participants' => [
                ['id' => 's1', 'label' => 'Seed 1', 'seed' => null, 'metadata' => []],
                ['id' => 's2', 'label' => 'Seed 2', 'seed' => null, 'metadata' => []],
            ],
            'active' => ['s1', 's2'],
            'rounds' => [],
        ];

        $roundless = StageState::fromArray([...$base, 'results' => [[
            'event' => ['participants' => ['s1', 's2'], 'round' => null, 'metadata' => []],
            'winner' => 's1',
            'scores' => [],
            'metadata' => [],
        ]]]);
        expect(fn () => $engine->pairNextRound($roundless))
            ->toThrow(InvalidConfigurationException::class, 'round number');
    });

    it('rejects fewer than two participants', function (): void {
        $engine = new SingleEliminationEngine();

        expect(fn () => $engine->pairNextRound(StageState::start([new Participant('p1', 'Solo')])))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('runs a full bracket where favourites hold to a 1 vs 2 final', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(seededField(8));

        while (!$engine->isComplete($state)) {
            $state = playBracketRound($state, $engine->pairNextRound($state), favouriteWins(...));
        }

        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        expect($outcome->getResults())->toHaveCount(7); // 4 + 2 + 1

        $finalEvent = $outcome->getFinalRound()?->getEvents()[0];
        assert($finalEvent !== null);
        $finalIds = array_map(fn (Participant $p) => $p->getId(), $finalEvent->getParticipants());
        sort($finalIds);
        expect($finalIds)->toBe(['s1', 's2']);
        expect($finalEvent->getRound()?->getMetadataValue('label'))->toBe('final');

        // Conventional bracket classification: 3-0, 2-1, joint 1-1, joint 0-1
        $wins = array_map(
            fn ($entry) => [$entry->getParticipant()->getId(), $entry->getWins(), $entry->getLosses()],
            $outcome->getStandings()->getEntries()
        );
        expect($wins[0])->toBe(['s1', 3, 0]);
        expect($wins[1])->toBe(['s2', 2, 1]);
        expect([$wins[2][1], $wins[2][2]])->toBe([1, 1]);
        expect([$wins[3][1], $wins[3][2]])->toBe([1, 1]);
    });

    it('re-seeds survivors by standings when configured', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));
        $participants = seededField(8);
        $state = StageState::start($participants);

        $round1 = $engine->pairNextRound($state);
        // Upset: s8 beats s1; everyone else holds
        $state = $state->withRoundPlayed($round1, array_map(
            fn (Event $event) => new Result(
                $event,
                in_array('s1', array_map(fn (Participant $p) => $p->getId(), $event->getParticipants()), true)
                    ? $event->getParticipants()[1]
                    : favouriteWins($event)
            ),
            $round1->getEvents()
        ));

        $round2 = $engine->pairNextRound($state);

        // Survivors s8, s4, s2, s3 all sit at 1-0; the re-seed orders them
        // by the standings' deterministic tiebreak (seed attribute), so the
        // fold pairs s2 vs s8 and s3 vs s4 instead of the fixed path
        expect(eventIdPairs($round2->getEvents()))->toBe([
            ['s2', 's8'],
            ['s3', 's4'],
        ]);
    });

    describe('two-legged ties', function (): void {
        it('emits two mirrored legs per tie and expects doubled events', function (): void {
            $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));
            $state = StageState::start(seededField(4));

            $plan = $engine->getPlan($state);
            expect($plan->getLegsPerTie())->toBe(2);
            expect($plan->getExpectedEventCount())->toBe(6); // 3 ties x 2 legs

            $round1 = $engine->pairNextRound($state);
            expect(eventIdPairs($round1->getEvents()))->toBe([
                ['s1', 's4'],
                ['s4', 's1'],
                ['s2', 's3'],
                ['s3', 's2'],
            ]);
            expect($round1->getEvents()[0]->getMetadataValue('tie_leg'))->toBe(1);
            expect($round1->getEvents()[1]->getMetadataValue('tie_leg'))->toBe(2);
        });

        it('advances the participant with more leg wins', function (): void {
            $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));
            $participants = seededField(4);
            $state = StageState::start($participants);
            $round1 = $engine->pairNextRound($state);
            [$leg1a, $leg2a, $leg1b, $leg2b] = $round1->getEvents();

            $state = $state->withRoundPlayed($round1, [
                new Result($leg1a, $participants[0]), // s1 wins leg 1
                new Result($leg2a),                   // leg 2 drawn: s1 advances 1-0
                new Result($leg1b, $participants[2]), // s3 wins leg 1
                new Result($leg2b, $participants[2]), // s3 wins leg 2: advances 2-0
            ]);

            $final = $engine->pairNextRound($state);
            expect(eventIdPairs($final->getEvents()))->toBe([['s1', 's3'], ['s3', 's1']]);
        });

        it('requires an explicit tie decision when the legs are level', function (): void {
            $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));
            $participants = seededField(2);
            $state = StageState::start($participants);
            $final = $engine->pairNextRound($state);
            [$leg1, $leg2] = $final->getEvents();

            // Each side wins a leg: the aggregate is the application's call
            $undecided = $state->withRoundPlayed($final, [
                new Result($leg1, $participants[0]),
                new Result($leg2, $participants[1]),
            ]);

            expect(fn () => $engine->isComplete($undecided))
                ->toThrow(InvalidConfigurationException::class, 'tie_winner');

            // The decision is recorded as tie_winner metadata on a leg result
            $decided = $state->withRoundPlayed($final, [
                new Result($leg1, $participants[0]),
                new Result($leg2, $participants[1], [], [TieDecision::TIE_WINNER_KEY => 's2']),
            ]);

            expect($engine->isComplete($decided))->toBeTrue();
            $outcome = $engine->getOutcome($decided);
            assert($outcome !== null);

            // A split two-legged final leaves both finalists 1-1 in the
            // standings; the advancer is derived from the recorded outcome,
            // which is tie-decision aware
            $winners = \MissionGaming\Tactician\Stage\MatchOutcomeSelector::winners()->select($outcome);
            expect(array_map(fn (Participant $p) => $p->getId(), $winners))->toBe(['s2']);
        });
    });

    it('rejects results referencing non-pairwise events via rehydrated state', function (): void {
        $engine = new SingleEliminationEngine();

        $state = StageState::fromArray([
            'participants' => [
                ['id' => 's1', 'label' => 'Seed 1', 'seed' => null, 'metadata' => []],
                ['id' => 's2', 'label' => 'Seed 2', 'seed' => null, 'metadata' => []],
                ['id' => 's3', 'label' => 'Seed 3', 'seed' => null, 'metadata' => []],
            ],
            'active' => ['s1', 's2', 's3'],
            'rounds' => [],
            'results' => [[
                'event' => ['participants' => ['s1', 's2', 's3'], 'round' => ['number' => 1, 'metadata' => []], 'metadata' => []],
                'winner' => 's1',
                'scores' => [],
                'metadata' => [],
            ]],
        ]);

        expect(fn () => $engine->pairNextRound($state))
            ->toThrow(InvalidConfigurationException::class, 'two-participant');
    });
});
