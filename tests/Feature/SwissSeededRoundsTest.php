<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use MissionGaming\Tactician\Stage\RecordedRound;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\Adjustment;
use Random\Engine\Mt19937;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

// Rule 4 of issue #57: a seeded Swiss engine draws each round from the seed
// and the round number, and from nothing drawn for an earlier round. So any
// round can be paired from a state built from the rows of an application
// (StageState::fromHistory()) without the draws of the rounds before it
// being replayed, and that state pairs its next round exactly as the state
// built round by round does.
//
// The property is checked with two engines that share nothing: the one
// that plays the stage round by round, and a new one built from the same
// configuration for every rebuilt state, as an application builds one on
// every request.

/**
 * @return list<Participant>
 */
function seededSwissField(int $size): array
{
    $field = [];
    for ($position = 1; $position <= $size; ++$position) {
        $field[] = new Participant("s{$position}", "Entrant {$position}");
    }

    return $field;
}

/**
 * A result for every event of a round: a win for either side or a draw,
 * so that the table has groups of every size to shuffle.
 *
 * @return list<Result>
 */
function seededSwissResults(RoundPairing $pairing, Randomizer $play): array
{
    $results = [];
    foreach ($pairing->getEvents() as $event) {
        $outcome = $play->getInt(0, 2);
        $results[] = new Result($event, $outcome === 2 ? null : $event->getParticipants()[$outcome]);
    }

    return $results;
}

/**
 * A seeded Swiss stage played round by round by one engine, with every
 * state it is paired from and the round paired from it.
 *
 * @param list<Participant> $entrants
 * @return list<array{StageState, RoundPairing}>
 *
 * @throws InvalidConfigurationException When the engine refuses a step (not expected)
 * @throws InvalidInputException When the engine refuses its rounds (not expected)
 */
function seededSwissStage(array $entrants, int $rounds, int $seed, int $playSeed, bool $eventful): array
{
    $engine = new SwissPairingEngine(plannedRounds: $rounds, seed: $seed);
    $play = new Randomizer(new Mt19937($playSeed));
    $state = StageState::start($entrants);
    $steps = [];

    while (!$engine->isComplete($state)) {
        try {
            $pairing = $engine->pairNextRound($state);
        } catch (NoValidPairingException) {
            // A field that withdrawals have left without a pairing nobody
            // has played: the stage ends here
            break;
        }
        $steps[] = [$state, $pairing];

        $state = $state->withRoundPaired($pairing)->withAdditionalResults(seededSwissResults($pairing, $play));

        if ($eventful && $play->getInt(0, 2) === 0) {
            $state = $state->withAdjustment(new Adjustment(
                $entrants[$play->getInt(0, count($entrants) - 1)]->getId(),
                $play->getInt(-2, 2) / 2.0
            ));
        }
        if ($eventful && count($state->getParticipants()) > 3 && $play->getInt(0, 3) === 0) {
            $active = array_values($state->getParticipants());
            $state = $state->withoutParticipant($active[$play->getInt(0, count($active) - 1)]);
        }
    }

    return $steps;
}

/**
 * The state as an application holds it: its rows, in an order of their
 * own, given to fromHistory().
 *
 * @param list<Participant> $entrants
 *
 * @throws InvalidConfigurationException When the history is refused (not expected)
 * @throws InvalidInputException When an adjustment is refused (not expected)
 */
function seededSwissRebuilt(StageState $state, array $entrants, Randomizer $order): StageState
{
    return StageState::fromHistory(
        $entrants,
        $order->shuffleArray($state->getRoundsPlayed()),
        $order->shuffleArray($state->getResults()),
        $state->getWithdrawals(),
        $state->getAdjustments()
    );
}

/**
 * The Randomizer the documentation says round n of a seeded engine draws
 * from, built here by hand: Xoshiro256StarStar seeded with the seed and
 * advanced by n - 1 jumps.
 */
function seededSwissRoundRandomizer(int $seed, int $round): Randomizer
{
    $engine = new Xoshiro256StarStar($seed);
    for ($jump = 1; $jump < $round; ++$jump) {
        $engine->jump();
    }

    return new Randomizer($engine);
}

/**
 * The shapes the sweeps below go through: field size, planned rounds, the
 * seed of the draw, and whether adjustments and withdrawals happen.
 *
 * @return list<array{int, int, int, bool}>
 */
function seededSwissShapes(): array
{
    $shapes = [];
    foreach ([4, 5, 7, 8, 11, 12] as $size) {
        foreach ([1, 2026, -9] as $seed) {
            foreach ([false, true] as $eventful) {
                $shapes[] = [$size, min($size - 1, 5), $seed, $eventful];
            }
        }
    }

    return $shapes;
}

describe('a seeded Swiss engine', function (): void {
    it('pairs every round of a state rebuilt from its history as the state built round by round', function (): void {
        $order = new Randomizer(new Mt19937(57));
        $checked = 0;

        foreach (seededSwissShapes() as [$size, $rounds, $seed, $eventful]) {
            foreach ([1, 2] as $playSeed) {
                $entrants = seededSwissField($size);

                foreach (seededSwissStage($entrants, $rounds, $seed, $playSeed, $eventful) as [$state, $pairing]) {
                    $fresh = new SwissPairingEngine(plannedRounds: $rounds, seed: $seed);
                    $rebuilt = seededSwissRebuilt($state, $entrants, $order);

                    expect($fresh->pairNextRound($rebuilt)->toArray())->toBe(
                        $pairing->toArray(),
                        "{$size} entrants, seed {$seed}, play {$playSeed}, round {$pairing->getRoundNumber()}"
                    );
                    ++$checked;
                }
            }
        }

        expect($checked)->toBeGreaterThan(250);
    });

    it('reports no round it paired itself as manual, and shows each as its own derivation', function (): void {
        $order = new Randomizer(new Mt19937(4));
        $rounds = 0;

        foreach (seededSwissShapes() as [$size, $planned, $seed, $eventful]) {
            $entrants = seededSwissField($size);
            $steps = seededSwissStage($entrants, $planned, $seed, 3, $eventful);
            if ($steps === []) {
                continue;
            }

            // The last state paired from, with its round recorded
            [$last, $pairing] = $steps[count($steps) - 1];
            $state = seededSwissRebuilt($last->withRoundPaired($pairing), $entrants, $order);

            // Asked twice on one engine, as a long-lived engine is: the
            // second reading draws nothing the first one used up
            $engine = new SwissPairingEngine(plannedRounds: $planned, seed: $seed);
            $first = $engine->getRecordedRounds($state);
            expect(array_map(
                static fn(RecordedRound $recorded): ?array => $recorded->getDerivation()?->toArray(),
                $engine->getRecordedRounds($state)
            ))->toBe(array_map(
                static fn(RecordedRound $recorded): ?array => $recorded->getDerivation()?->toArray(),
                $first
            ));

            foreach ($first as $recorded) {
                expect($recorded)->toBeInstanceOf(RecordedRound::class)
                    ->and($recorded->isManual())->toBeFalse()
                    ->and($recorded->getDerivation()?->toArray())->toBe($recorded->getPairing()->toArray());
                ++$rounds;
            }
        }

        expect($rounds)->toBeGreaterThan(100);
    });

    it('reports a round drawn otherwise as manual, with its own draw beside it', function (): void {
        $entrants = seededSwissField(8);
        $engine = new SwissPairingEngine(plannedRounds: 3, seed: 7);
        $other = new SwissPairingEngine(plannedRounds: 3, seed: 8);
        $state = StageState::start($entrants);

        $ours = $engine->pairNextRound($state);
        $theirs = $other->pairNextRound($state);
        expect($theirs->toArray())->not->toBe($ours->toArray());

        $recorded = $engine->getRecordedRounds($state->withRoundPaired($theirs))[0];

        expect($recorded->isManual())->toBeTrue()
            ->and($recorded->getDerivation()?->toArray())->toBe($ours->toArray());
    });

    it('draws round n from the seed advanced by n - 1 jumps of Xoshiro256StarStar, and from nothing else', function (): void {
        $checked = 0;

        foreach (seededSwissShapes() as [$size, $rounds, $seed, $eventful]) {
            foreach (seededSwissStage(seededSwissField($size), $rounds, $seed, 5, $eventful) as [$state, $pairing]) {
                $handed = new SwissPairingEngine(
                    plannedRounds: $rounds,
                    randomizer: seededSwissRoundRandomizer($seed, $pairing->getRoundNumber())
                );

                expect($handed->pairNextRound($state)->toArray())->toBe($pairing->toArray());
                ++$checked;
            }
        }

        expect($checked)->toBeGreaterThan(100);
    });

    it('gives the same round for the same state on every call, on one engine or on many', function (): void {
        $engine = new SwissPairingEngine(plannedRounds: 4, seed: 11);
        $steps = seededSwissStage(seededSwissField(10), 4, 11, 6, false);
        [$state] = $steps[2];

        $first = $engine->pairNextRound($state)->toArray();

        expect($engine->pairNextRound($state)->toArray())->toBe($first)
            ->and(new SwissPairingEngine(plannedRounds: 4, seed: 11)->pairNextRound($state)->toArray())->toBe($first)
            ->and($engine->pairNextRound(StageState::fromJson($state->toJson()))->toArray())->toBe($first);
    });

    it('draws another field order from another seed', function (): void {
        $firstRounds = [];
        for ($seed = 1; $seed <= 12; ++$seed) {
            $firstRounds[] = json_encode(new SwissPairingEngine(seed: $seed)->pairNextRound(StageState::start(seededSwissField(8)))->toArray());
        }

        expect(count(array_unique($firstRounds)))->toBeGreaterThan(6);
    });

    it('is refused a seed and a randomizer together', function (): void {
        try {
            new SwissPairingEngine(randomizer: new Randomizer(new Mt19937(1)), seed: 1);
            $this->fail('An engine was built with two sources of draws.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions)
                ->and($exception->getContext())->toBe(['seed' => 1]);
        }
    });

    // The whole-schedule preset drives this engine in results-free mode, so
    // a seeded pre-drawn schedule is, round for round, the stage that engine
    // pairs, wherever the first draw goes through.
    it('pairs the schedule a seeded SwissScheduler draws', function (int $size, int $rounds, int $seed): void {
        $schedule = new SwissScheduler()->schedule(seededSwissField($size), new SwissOptions($rounds, $seed));

        $engine = new SwissPairingEngine(plannedRounds: $rounds, resultsFree: true, seed: $seed);
        $state = StageState::start(seededSwissField($size));
        $events = [];
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $events = [...$events, ...$pairing->getEvents()];
            $state = $state->withRoundPaired($pairing);
        }

        expect($schedule->getEvents())->toEqual($events);
    })->with([[8, 3, 2026], [9, 4, 1], [12, 5, -3], [16, 6, 99]]);

    // Where the first draw reaches a round with no pairing, draw k (from the
    // second on) is the stage of an engine seeded with the (k - 1)th integer
    // of a Xoshiro256StarStar sequence from the seed advanced by one long
    // jump, as SwissScheduler::redrawSequence() documents. The seeds are
    // ones whose first draws fail (found by running the engine): 30 needs
    // two draws at 8 participants and 6 rounds, 18 needs three, and 19
    // needs four at 10 and 9.
    it('draws a seeded schedule again from the seeds the long-jumped sequence of its seed gives', function (int $size, int $rounds, int $seed, int $draws): void {
        $sequenceEngine = new Xoshiro256StarStar($seed);
        $sequenceEngine->jumpLong();
        $sequence = new Randomizer($sequenceEngine);

        $seedOfDraw = $seed;
        $stage = null;
        for ($draw = 1; $draw <= $draws; ++$draw) {
            if ($draw > 1) {
                $seedOfDraw = $sequence->getInt(PHP_INT_MIN, PHP_INT_MAX);
            }

            $engine = new SwissPairingEngine(plannedRounds: $rounds, resultsFree: true, seed: $seedOfDraw);
            $state = StageState::start(seededSwissField($size));
            $events = [];
            try {
                while (!$engine->isComplete($state)) {
                    $pairing = $engine->pairNextRound($state);
                    $events = [...$events, ...$pairing->getEvents()];
                    $state = $state->withRoundPaired($pairing);
                }
                $stage = $events;
            } catch (NoValidPairingException) {
                $stage = null;
            }

            // Every draw before the last reaches a round with no pairing
            expect($stage === null)->toBe($draw < $draws, "draw {$draw} of seed {$seed}");
        }

        $schedule = new SwissScheduler()->schedule(seededSwissField($size), new SwissOptions($rounds, $seed));

        expect($schedule->getEvents())->toEqual($stage);
    })->with([[8, 6, 30, 2], [8, 6, 18, 3], [10, 9, 19, 4]]);
});
