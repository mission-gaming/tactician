<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;

/**
 * A field in seeding order: position 1 is the top entrant.
 *
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
 * Record wins for the better (lower position) entrant in every event.
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

/**
 * The consumer's champion derivation: the winner of the outcome's final
 * round (grand final or reset).
 *
 * @throws InvalidConfigurationException When the final round is unresolved
 */
function bracketTitleHolder(StageOutcome $outcome): string
{
    $winners = MatchOutcomeSelector::winners()->select($outcome);

    return $winners[0]->getId();
}

describe('DoubleEliminationEngine', function (): void {
    it('is a stage engine', function (): void {
        expect(new DoubleEliminationEngine())->toBeInstanceOf(StageEngineInterface::class);
    });

    it('declares a plan with unknowable totals', function (): void {
        $engine = new DoubleEliminationEngine();
        $plan = $engine->getPlan(StageState::start(doubleElimField(8)));

        expect($plan->getAlgorithm())->toBe('double-elimination');
        // The grand final may or may not reset, so neither the round count
        // nor the event count is knowable up front
        expect($plan->getTotalRounds())->toBeNull();
        expect($plan->getExpectedEventCount())->toBeNull();
        expect($plan->getLegs())->toBeNull();
    });

    it('rejects re-seeding, a single-elimination preset parameter', function (): void {
        new DoubleEliminationEngine(new EliminationOptions(reseedEachRound: true));
    })->throws(InvalidConfigurationException::class, 'Re-seeding');

    it('sequences winners, losers, and grand final stages for four participants', function (): void {
        $engine = new DoubleEliminationEngine();
        $state = StageState::start(doubleElimField(4));

        $stages = [];
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $stages[$pairing->getRoundNumber()] = $pairing->getLabel();
            $state = $state->withRoundPlayed($pairing, doubleElimFavourites($pairing->getEvents()));
        }

        expect($stages)->toBe([
            1 => 'winners round 1',
            2 => 'losers round 1',
            3 => 'winners final',
            4 => 'losers final',
            5 => 'grand final',
        ]);

        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        expect(bracketTitleHolder($outcome))->toBe('s1');
        expect($outcome->getResults())->toHaveCount(6); // 2n - 2 with no reset
    });

    it('routes first-round losers through the losers bracket', function (): void {
        $engine = new DoubleEliminationEngine();
        $state = StageState::start(doubleElimField(4));

        $round1 = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($round1, doubleElimFavourites($round1->getEvents()));

        $losersRound = $engine->pairNextRound($state);

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
        $state = StageState::start($participants);

        // Winners round: s1 beats s2, sending s2 to the losers side
        $winnersRound = $engine->pairNextRound($state);
        expect($winnersRound->getLabel())->toBe('winners final');
        $state = $state->withRoundPlayed($winnersRound, doubleElimFavourites($winnersRound->getEvents()));

        // Grand final: s2 wins, so both finalists have one loss
        $grandFinal = $engine->pairNextRound($state);
        expect($grandFinal->getLabel())->toBe('grand final');
        $state = $state->withRoundPlayed($grandFinal, [new Result($grandFinal->getEvents()[0], $participants[1])]);

        expect($engine->isComplete($state))->toBeFalse();

        $reset = $engine->pairNextRound($state);
        expect($reset->getLabel())->toBe('grand final reset');
        $state = $state->withRoundPlayed($reset, [new Result($reset->getEvents()[0], $participants[1])]);

        expect($engine->isComplete($state))->toBeTrue();
        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        expect(bracketTitleHolder($outcome))->toBe('s2');
    });

    it('skips the reset when configured for a single grand final', function (): void {
        $engine = new DoubleEliminationEngine(new EliminationOptions(grandFinalReset: false));
        $participants = doubleElimField(2);
        $state = StageState::start($participants);

        $winnersRound = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($winnersRound, doubleElimFavourites($winnersRound->getEvents()));

        $grandFinal = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($grandFinal, [new Result($grandFinal->getEvents()[0], $participants[1])]);

        expect($engine->isComplete($state))->toBeTrue();
        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        expect(bracketTitleHolder($outcome))->toBe('s2');
    });

    it('does not play a reset when the winners champion wins the grand final', function (): void {
        $engine = new DoubleEliminationEngine();
        $participants = doubleElimField(2);
        $state = StageState::start($participants);

        $winnersRound = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($winnersRound, doubleElimFavourites($winnersRound->getEvents()));

        $grandFinal = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($grandFinal, [new Result($grandFinal->getEvents()[0], $participants[0])]);

        expect($engine->isComplete($state))->toBeTrue();
        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        expect(bracketTitleHolder($outcome))->toBe('s1');
        expect(fn () => $engine->pairNextRound($state))
            ->toThrow(InvalidConfigurationException::class, 'complete');
    });

    it('propagates byes through the losers bracket without consuming rounds', function (): void {
        $engine = new DoubleEliminationEngine();
        $state = StageState::start(doubleElimField(3));

        $stages = [];
        $byesByStage = [];
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $stages[$pairing->getRoundNumber()] = $pairing->getLabel();
            $byesByStage[$pairing->getLabel()] = array_map(
                fn (Participant $p) => $p->getId(),
                $pairing->getByes()
            );
            $state = $state->withRoundPlayed($pairing, doubleElimFavourites($pairing->getEvents()));
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

        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        expect(bracketTitleHolder($outcome))->toBe('s1');
    });

    it('runs a full eight-participant bracket in 2n - 2 matches when favourites hold', function (): void {
        $engine = new DoubleEliminationEngine();
        $state = StageState::start(doubleElimField(8));

        $stages = [];
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $stages[] = $pairing->getLabel();
            $state = $state->withRoundPlayed($pairing, doubleElimFavourites($pairing->getEvents()));
        }

        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        expect(bracketTitleHolder($outcome))->toBe('s1');
        expect($outcome->getResults())->toHaveCount(14); // 2n - 2 with no reset
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

        $state = StageState::start($participants);
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $results = [];
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
            $state = $state->withRoundPlayed($pairing, $results);
        }

        // s1 dropped to the losers bracket, won the losers final, took the
        // grand final off s2, and won the reset.
        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        expect(bracketTitleHolder($outcome))->toBe('s1');
        expect($outcome->getResults())->toHaveCount(7); // 2n - 1 with a reset
        expect($outcome->getFinalRound()?->getLabel())->toBe('grand final reset');
    });

    it('supports two-legged ties through the whole bracket', function (): void {
        $engine = new DoubleEliminationEngine(new EliminationOptions(legsPerTie: 2));
        $state = StageState::start(doubleElimField(4));

        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            // Every stage emits two mirrored legs per tie
            expect(count($pairing->getEvents()) % 2)->toBe(0);
            $state = $state->withRoundPlayed($pairing, doubleElimFavourites($pairing->getEvents()));
        }

        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        expect(bracketTitleHolder($outcome))->toBe('s1');
        expect($outcome->getResults())->toHaveCount(12); // (2n - 2) ties x 2 legs
    });

    it('rejects drawn results and partially resolved stages', function (): void {
        $engine = new DoubleEliminationEngine();
        $participants = doubleElimField(4);
        $state = StageState::start($participants);
        $round1 = $engine->pairNextRound($state);

        $drawn = $state->withRoundPlayed($round1, [
            new Result($round1->getEvents()[0]),
            new Result($round1->getEvents()[1], $round1->getEvents()[1]->getParticipants()[0]),
        ]);
        expect(fn () => $engine->pairNextRound($drawn))
            ->toThrow(InvalidConfigurationException::class, 'draw');

        $partial = $state->withRoundPlayed($round1, [
            new Result($round1->getEvents()[0], $round1->getEvents()[0]->getParticipants()[0]),
        ]);
        expect(fn () => $engine->pairNextRound($partial))
            ->toThrow(InvalidConfigurationException::class, 'partially resolved');
    });

    it('rejects fewer than two participants', function (): void {
        $engine = new DoubleEliminationEngine();

        expect(fn () => $engine->pairNextRound(StageState::start([new Participant('p1', 'Solo')])))
            ->toThrow(InvalidConfigurationException::class);
    });
});
