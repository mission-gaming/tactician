<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\CompositionValidator;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\PoolDistributor;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\StageTransition;
use MissionGaming\Tactician\Standings\StandingsCalculator;

// The composition the retired GroupStageEngine hard-coded, built from the
// generic primitives: distributor -> per-pool round robin -> combined
// outcome -> rank selector -> knockout preset. What a pool plays is no
// longer fixed, and the hand-offs validate ahead of time.

/**
 * @return array<Participant>
 */
function tournamentField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("t{$i}", "Team {$i}", $i);
    }

    return $participants;
}

/**
 * Play out events with the better (lower position) entrant always winning.
 *
 * @param iterable<Event> $events
 * @return array<Result>
 */
function playFavourites(iterable $events): array
{
    $results = [];
    foreach ($events as $event) {
        $participants = $event->getParticipants();
        $winner = ($participants[0]->getSeed() ?? PHP_INT_MAX) < ($participants[1]->getSeed() ?? PHP_INT_MAX)
            ? $participants[0]
            : $participants[1];
        $results[] = new Result($event, $winner);
    }

    return $results;
}

it('runs a full groups-into-knockout tournament from the composition primitives', function (): void {
    $participants = tournamentField(8);
    $calculator = new StandingsCalculator();

    // The declared structure telescopes before any fixture exists
    $violations = (new CompositionValidator())->validateChain(8, [
        new StageTransition('knockout', 4, RankRangeSelector::topPerGroup(2)),
        new StageTransition('final', 2, MatchOutcomeSelector::winners()),
    ]);
    expect($violations)->toBe([]);

    // Stage 1: pools, each playing a round robin
    $pools = PoolDistributor::serpentine($participants, 2);
    expect(array_map(
        fn (array $pool) => array_map(fn (Participant $p) => $p->getId(), $pool),
        $pools
    ))->toBe([
        'A' => ['t1', 't4', 't5', 't8'],
        'B' => ['t2', 't3', 't6', 't7'],
    ]);

    $scheduler = new RoundRobinScheduler();
    $poolOutcomes = [];
    foreach ($pools as $label => $poolParticipants) {
        $schedule = $scheduler->schedule($poolParticipants);
        $results = playFavourites($schedule);

        // Progressing from a partial table would promote the wrong
        // participants; the plan's completeness check guards the hand-off
        $plan = $scheduler->getPlan($poolParticipants);
        expect($plan->findUnplayedPairings($results))->toBe([]);

        $poolOutcomes[$label] = new StageOutcome(
            $calculator->calculate($poolParticipants, $results),
            $results
        );
    }

    // The hand-off: top 2 per pool over the combined outcome, winners first
    $combined = StageOutcome::combining($poolOutcomes, $calculator);
    $qualifiers = RankRangeSelector::topPerGroup(2)->select($combined);
    expect(array_map(fn (Participant $p) => $p->getId(), $qualifiers))
        ->toBe(['t1', 't2', 't4', 't3']);

    // Stage 2: knockout preset seeded from list position
    $knockout = new SingleEliminationEngine();
    $state = StageState::start($qualifiers);

    $semifinals = $knockout->pairNextRound($state);
    expect($semifinals->getLabel())->toBe('semifinal');

    // Cross-pool semifinals: each pool winner meets the other pool's runner-up
    foreach ($semifinals->getEvents() as $event) {
        $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
        sort($ids);
        expect($ids)->toBeIn([['t1', 't3'], ['t2', 't4']]);
    }

    while (!$knockout->isComplete($state)) {
        $pairing = $knockout->pairNextRound($state);
        $state = $state->withRoundPlayed($pairing, playFavourites($pairing->getEvents()));
    }

    $outcome = $knockout->getOutcome($state);
    expect($outcome)->not->toBeNull();
    assert($outcome !== null);

    // The consumer's champion: winners of the final round
    $titleHolders = MatchOutcomeSelector::winners()->select($outcome);
    expect(array_map(fn (Participant $p) => $p->getId(), $titleHolders))->toBe(['t1']);
});

it('feeds a losers route from the same outcome as the winners route', function (): void {
    // Multi-route progression: several selector calls against one outcome
    $participants = tournamentField(4);
    $engine = new SingleEliminationEngine();
    $state = StageState::start($participants);

    $round1 = $engine->pairNextRound($state);
    $state = $state->withRoundPlayed($round1, playFavourites($round1->getEvents()));
    $final = $engine->pairNextRound($state);
    $state = $state->withRoundPlayed($final, playFavourites($final->getEvents()));

    $outcome = $engine->getOutcome($state);
    assert($outcome !== null);

    $winners = MatchOutcomeSelector::winners()->select($outcome);
    $losers = MatchOutcomeSelector::losers()->select($outcome);

    expect(array_map(fn (Participant $p) => $p->getId(), $winners))->toBe(['t1']);
    expect(array_map(fn (Participant $p) => $p->getId(), $losers))->toBe(['t2']);
});

it('surfaces incomplete pool play before qualification', function (): void {
    $participants = tournamentField(4);
    $scheduler = new RoundRobinScheduler();
    $schedule = $scheduler->schedule($participants);

    $results = playFavourites($schedule);
    array_pop($results);

    $unplayed = $scheduler->getPlan($participants)->findUnplayedPairings($results);
    expect($unplayed)->toHaveCount(1);

    // The stage-entry contract still catches structural errors at the
    // destination: a duplicated entrant is rejected loudly
    expect(fn () => StageState::start([$participants[0], $participants[0]]))
        ->toThrow(InvalidConfigurationException::class, 'unique IDs');
});
