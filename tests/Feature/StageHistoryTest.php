<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Exceptions\UnresolvedRoundException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use MissionGaming\Tactician\Stage\HistoryReadingEngine;
use MissionGaming\Tactician\Stage\PairingReadiness;
use MissionGaming\Tactician\Stage\RecordedRound;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\Withdrawal;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use Random\Engine\Mt19937;
use Random\Randomizer;

// A stage whose history the application holds (issue #57). The rules these
// tests state:
//
// 1. History is supplied, never recomputed: a recorded round is read as
//    recorded, and an engine derives only the round that does not exist yet.
// 2. A round with some of its results, or none, is normal for reading:
//    isComplete() is false and nothing throws.
// 3. A recorded round the engine would have paired otherwise is accepted
//    and reported as manual; a history that breaks a hard rule is refused
//    with a reason.
// 5. A withdrawal from a live bracket sends the opponent on without an
//    event, and draws nothing again.
// 6. The next round of an unfinished round is refused, with the events that
//    await a result.
// 7. Readiness is asked without catching anything; pairing early is asked
//    for in so many words and is marked.
// 8. Pairing without results is a mode of the engine, which the
//    whole-schedule preset sets.
//
// The sweeps are small on purpose: a few field sizes and seeds for each
// engine, every point of every stage.

/**
 * @return list<Participant>
 */
function historyField(int $size): array
{
    $field = [];
    for ($position = 1; $position <= $size; ++$position) {
        $field[] = new Participant("h{$position}", "Entrant {$position}");
    }

    return $field;
}

/**
 * The engines, by name: every format, and every option that changes how a
 * bracket is read.
 *
 * @return array<string, StageEngineInterface&HistoryReadingEngine>
 *
 * @throws InvalidConfigurationException When an engine refuses its options (not expected)
 * @throws InvalidInputException When the Swiss engine refuses its rounds (not expected)
 */
function historyEngines(): array
{
    return [
        'Swiss' => new SwissPairingEngine(plannedRounds: 3),
        'single elimination' => new SingleEliminationEngine(),
        'single elimination over two legs' => new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2)),
        'single elimination, re-seeded' => new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true)),
        'single elimination with a third-place tie' => new SingleEliminationEngine(new EliminationOptions(thirdPlace: true)),
        'double elimination' => new DoubleEliminationEngine(),
        'double elimination over two legs' => new DoubleEliminationEngine(new EliminationOptions(legsPerTie: 2)),
    ];
}

/**
 * A result for every event of a round, in the order of its events. Who
 * wins a tie is drawn once for the tie, so two legs agree.
 *
 * @return list<Result>
 */
function historyResults(RoundPairing $pairing, Randomizer $rng): array
{
    $advancing = [];
    $results = [];
    foreach ($pairing->getEvents() as $event) {
        $ids = historyIds($event->getParticipants());
        sort($ids);
        $tie = implode('|', $ids);
        $advancing[$tie] ??= $ids[$rng->getInt(0, 1)];

        foreach ($event->getParticipants() as $participant) {
            if ($participant->getId() === $advancing[$tie]) {
                $results[] = new Result($event, $participant);
            }
        }
    }

    return $results;
}

/**
 * @param array<Participant> $participants
 *
 * @return list<string>
 */
function historyIds(array $participants): array
{
    return array_values(array_map(static fn(Participant $participant): string => $participant->getId(), $participants));
}

/**
 * The participants of each event, sorted, and the events sorted: who meets
 * whom, whatever the roles and the order.
 *
 * @param array<Event> $events
 *
 * @return list<string>
 */
function historyPairs(array $events): array
{
    $pairs = [];
    foreach ($events as $event) {
        $ids = historyIds($event->getParticipants());
        sort($ids);
        $pairs[] = implode(' v ', $ids) . ' leg ' . (is_int($event->getMetadataValue('tie_leg')) ? $event->getMetadataValue('tie_leg') : 1);
    }
    sort($pairs);

    return $pairs;
}

/**
 * Every state a stage passes through when it is played to its end: after
 * each round is recorded with half of its results, with all of them, and
 * after an adjustment and a withdrawal where the stage has them.
 *
 * @param list<Participant> $entrants
 *
 * @return list<StageState>
 *
 * @throws InvalidConfigurationException When the engine or the state refuses a step
 * @throws InvalidInputException When an adjustment names nobody of the stage (not expected)
 */
function historyPoints(StageEngineInterface $engine, array $entrants, int $seed, bool $withdrawals, bool $adjustments): array
{
    $rng = new Randomizer(new Mt19937($seed));
    $state = StageState::start($entrants);
    $points = [$state];

    for ($round = 1; !$engine->isComplete($state); ++$round) {
        if ($round > 40) {
            throw new LogicException('The stage did not complete.');
        }

        try {
            $pairing = $engine->pairNextRound($state);
        } catch (NoValidPairingException) {
            // A small Swiss field that withdrawals have left without a
            // pairing nobody has played: the stage ends here
            break;
        }
        $results = historyResults($pairing, $rng);
        $half = intdiv(count($results), 2);

        $points[] = $state = $state->withRoundPlayed($pairing, array_slice($results, 0, $half));
        $points[] = $state = $state->withAdditionalResults(array_slice($results, $half));

        if ($adjustments) {
            $points[] = $state = $state->withAdjustment(
                new Adjustment($entrants[$rng->getInt(0, count($entrants) - 1)]->getId(), $rng->getInt(-4, 4) / 2.0)
            );
        }
        if ($withdrawals && $round <= 2) {
            $points[] = $state = $state->withoutParticipant($entrants[$rng->getInt(0, count($entrants) - 1)]);
        }
    }

    return $points;
}

/**
 * The same state, built from its history as an application holds it: the
 * rounds and the results in an order of their own.
 *
 * @param list<Participant> $entrants
 *
 * @throws InvalidConfigurationException When the history is refused
 * @throws InvalidInputException When an adjustment is refused
 */
function historyRebuilt(StageState $state, array $entrants, Randomizer $rng, bool $shuffleEvents = false): StageState
{
    $rounds = $state->getRoundsPlayed();
    if ($shuffleEvents) {
        $rounds = array_map(
            static fn(RoundPairing $pairing): RoundPairing => new RoundPairing(
                $pairing->getRoundNumber(),
                $pairing->getLabel(),
                $rng->shuffleArray($pairing->getEvents()),
                $rng->shuffleArray($pairing->getByes()),
                $pairing->isPairedOnIncompleteResults()
            ),
            $rounds
        );
    }

    return StageState::fromHistory(
        $entrants,
        $rng->shuffleArray($rounds),
        $rng->shuffleArray($state->getResults()),
        historyWithdrawals($state, $rng),
        $state->getAdjustments()
    );
}

/**
 * The withdrawals of a state as an application's rows hold them: each with
 * the round it happened after, and the rounds in an order of their own.
 * Those of one round stay in the order they happened in, which is the one
 * thing about their order that says something.
 *
 * @return list<Withdrawal>
 */
function historyWithdrawals(StageState $state, Randomizer $rng): array
{
    $byRound = [];
    foreach ($state->getWithdrawals() as $withdrawal) {
        $byRound[$withdrawal->getAfterRound()][] = $withdrawal;
    }

    return array_merge(...$rng->shuffleArray(array_values($byRound)), ...[[]]);
}

/**
 * Everything an engine says of a state, as plain values: whether it is
 * complete, what it waits for, the round it pairs next, its table and its
 * outcome.
 *
 * @return array<string, mixed>
 *
 * @throws InvalidConfigurationException When the engine refuses the state
 */
function historyReading(StageEngineInterface&HistoryReadingEngine $engine, StageState $state): array
{
    $readiness = $engine->getReadiness($state);

    // A small Swiss field can be left without a pairing nobody has played
    $next = null;
    if ($readiness->isReady()) {
        try {
            $pairing = $engine->pairNextRound($state);
            $next = [historyPairs($pairing->getEvents()), historyIds($pairing->getByes())];
        } catch (NoValidPairingException) {
            $next = 'no pairing';
        }
    }

    $table = static fn(Standings $standings): array => [
        array_map(
            static fn(StandingEntry $entry): string => $entry->getParticipant()->getId() . ' ' . $entry->getRankingValue(),
            $standings->getEntries()
        ),
        $standings->getTiers(),
    ];

    $outcome = $engine->getOutcome($state);

    return [
        'complete' => $engine->isComplete($state),
        'ready' => $readiness->isReady(),
        'round' => $readiness->getRoundNumber(),
        'unresolved' => historyPairs($readiness->getUnresolvedEvents()),
        'next' => $next,
        'standings' => $table($engine->getStandings($state)),
        'outcome' => $outcome instanceof StageOutcome ? $table($outcome->getStandings()) : null,
        'manual' => array_map(static fn(RecordedRound $round): bool => $round->isManual(), $engine->getRecordedRounds($state)),
    ];
}

/**
 * The recorded events an engine waits for: those without a result, and in
 * a bracket not those of a tie an entrant has withdrawn from.
 *
 * @return list<Event>
 */
function historyAwaited(StageEngineInterface $engine, StageState $state): array
{
    $withdrawn = historyIds($state->getWithdrawnEntrants());

    return array_values(array_filter(
        $state->getEventsWithoutResult(),
        static fn(Event $event): bool => $engine instanceof SwissPairingEngine
            || array_intersect(historyIds($event->getParticipants()), $withdrawn) === []
    ));
}

describe('a state built from a history', function (): void {
    it('is the state the verbs built, at every point of a stage', function (string $name): void {
        $engine = historyEngines()[$name];
        $points = 0;

        foreach ([3, 5, 8] as $size) {
            foreach ([1, 2] as $seed) {
                foreach ([[false, false], [true, true]] as [$withdrawals, $adjustments]) {
                    $entrants = historyField($size);
                    $rng = new Randomizer(new Mt19937(100 * $size + $seed));

                    foreach (historyPoints($engine, $entrants, $seed, $withdrawals, $adjustments) as $state) {
                        $rebuilt = historyRebuilt($state, $entrants, $rng);

                        expect($rebuilt->toArray())->toBe($state->toArray(), "{$name}, {$size} entrants, seed {$seed}")
                            ->and($rebuilt)->toEqual($state)
                            ->and(historyReading($engine, $rebuilt))->toBe(historyReading($engine, $state));
                        ++$points;
                    }
                }
            }
        }

        expect($points)->toBeGreaterThan(40);
    })->with(array_keys(historyEngines()));

    // An application's rows come back in whatever order the database gives
    // them. The order of the events inside a round is kept as supplied, and
    // no engine reads a recorded round by it.
    it('is read the same by its engine whatever order the events of its rounds are in', function (string $name): void {
        $engine = historyEngines()[$name];

        foreach ([5, 8] as $size) {
            $entrants = historyField($size);
            $rng = new Randomizer(new Mt19937($size));

            foreach (historyPoints($engine, $entrants, $size, true, false) as $state) {
                expect(historyReading($engine, historyRebuilt($state, $entrants, $rng, true)))
                    ->toBe(historyReading($engine, $state), "{$name}, {$size} entrants");
            }
        }
    })->with(array_keys(historyEngines()));

    // The verbs keep the results in the order they were recorded, which is
    // the order they came in; a history puts them in the order of the events.
    // The two are then not the same array, and they are the same stage: the
    // history is the array the verbs give when the results come in event
    // order, and every engine reads the two alike.
    it('is read as the state whose results were recorded in another order than the events', function (string $name): void {
        $engine = historyEngines()[$name];
        $points = 0;
        $reordered = 0;

        foreach ([4, 5, 8] as $size) {
            $entrants = historyField($size);
            $rng = new Randomizer(new Mt19937(7 * $size));
            $state = $inOrder = StageState::start($entrants);

            for ($round = 1; !$engine->isComplete($state); ++$round) {
                if ($round > 40) {
                    throw new LogicException('The stage did not complete.');
                }

                try {
                    $pairing = $engine->pairNextRound($state);
                } catch (NoValidPairingException) {
                    break;
                }
                $results = historyResults($pairing, $rng);
                $asTheyCame = $rng->shuffleArray($results);
                $early = array_slice($asTheyCame, 0, $rng->getInt(0, count($asTheyCame)));

                $partly = $state->withRoundPlayed($pairing, $early);
                $state = $partly->withAdditionalResults(array_slice($asTheyCame, count($early)));
                $steps = [
                    [$partly, $inOrder->withRoundPlayed($pairing, array_values(array_filter(
                        $results,
                        static fn(Result $result): bool => in_array($result, $early, true)
                    )))],
                    [$state, $inOrder = $inOrder->withRoundPlayed($pairing, $results)],
                ];

                foreach ($steps as [$recorded, $inEventOrder]) {
                    $rebuilt = historyRebuilt($recorded, $entrants, $rng);

                    expect($rebuilt->toArray())->toBe($inEventOrder->toArray(), "{$name}, {$size} entrants, round {$round}")
                        ->and(historyReading($engine, $rebuilt))->toBe(historyReading($engine, $recorded))
                        ->and(historyReading($engine, historyRebuilt($recorded, $entrants, $rng, true)))->toBe(historyReading($engine, $recorded));
                    ++$points;
                    $reordered += $recorded->toArray() === $rebuilt->toArray() ? 0 : 1;
                }
            }
        }

        expect($points)->toBeGreaterThan(10)
            ->and($reordered)->toBeGreaterThan(3);
    })->with(array_keys(historyEngines()));

    it('carries no engine fingerprint, and takes one as every state does', function (): void {
        $engine = new SingleEliminationEngine();
        $entrants = historyField(4);
        $first = $engine->pairNextRound(StageState::start($entrants));

        $state = StageState::fromHistory($entrants, [$first]);

        expect($state->getEngineFingerprint())->toBeNull()
            ->and($state->withEngineFingerprint($engine->getFingerprint())->getEngineFingerprint())->toBe($engine->getFingerprint());
    });

    it('removes an entrant who withdrew before any round, as the verb does', function (): void {
        [$one, $two, $three, $four, $five] = $entrants = historyField(5);

        $state = StageState::fromHistory($entrants, [], [], [$three]);

        expect($state->toArray())->toBe(StageState::start($entrants)->withoutParticipant($three)->toArray())
            ->and(historyIds($state->getEntrants()))->toBe(['h1', 'h2', 'h4', 'h5'])
            ->and($state->getWithdrawnEntrants())->toBe([]);
    });

    it('refuses a history that breaks a hard rule, with the reason', function (Closure $history, InvalidConfigurationReason $reason, array $context): void {
        try {
            $history();
        } catch (InvalidConfigurationException $exception) {
            // What is listed is in the context with that value. For a
            // participant who is no entrant the list is the whole context:
            // who, in which role, and in which round where there is one.
            expect($exception->getReason())->toBe($reason)
                ->and(array_intersect_key($exception->getContext(), $context))->toBe($context);
            if ($reason === InvalidConfigurationReason::ParticipantNotAnEntrant) {
                expect($exception->getContext())->toBe($context);
            }

            return;
        }

        $this->fail('The history was accepted.');
    })->with(function (): array {
        [$one, $two, $three, $four] = $entrants = historyField(4);
        $stranger = new Participant('z', 'Not an entrant');
        $first = new Round(1);
        $oneVersusTwo = new Event([$one, $two], $first);
        $threeVersusFour = new Event([$three, $four], $first);
        $roundOne = new RoundPairing(1, null, [$oneVersusTwo, $threeVersusFour]);
        $roundThree = new RoundPairing(3, null, [new Event([$one, $three], new Round(3)), new Event([$two, $four], new Round(3))]);

        return [
            'two entrants with one ID' => [
                fn() => StageState::fromHistory([$one, $two, new Participant('h1', 'Again')], []),
                InvalidConfigurationReason::DuplicateParticipantIds,
                [],
            ],
            'a round left out' => [
                fn() => StageState::fromHistory($entrants, [$roundOne, $roundThree]),
                InvalidConfigurationReason::RoundOutOfSequence,
                ['expected_round' => 2, 'round' => 3, 'round_numbers' => [1, 3]],
            ],
            'a round given twice' => [
                fn() => StageState::fromHistory($entrants, [$roundOne, $roundOne]),
                InvalidConfigurationReason::RoundOutOfSequence,
                ['expected_round' => 2, 'round' => 1],
            ],
            'no first round' => [
                fn() => StageState::fromHistory($entrants, [$roundThree]),
                InvalidConfigurationReason::RoundOutOfSequence,
                ['expected_round' => 1, 'round' => 3],
            ],
            'a round numbered 0' => [
                fn() => StageState::fromHistory($entrants, [new RoundPairing(0, null, [])]),
                InvalidConfigurationReason::ValueOutOfRange,
                ['pairing_round' => 0],
            ],
            'an event with a participant who is no entrant' => [
                fn() => StageState::fromHistory($entrants, [new RoundPairing(1, null, [new Event([$one, $stranger], $first)])]),
                InvalidConfigurationReason::ParticipantNotAnEntrant,
                ['participant' => 'z', 'role' => 'in an event', 'round' => 1],
            ],
            'a bye for a participant who is no entrant' => [
                fn() => StageState::fromHistory($entrants, [new RoundPairing(1, null, [$oneVersusTwo], [$stranger])]),
                InvalidConfigurationReason::ParticipantNotAnEntrant,
                ['participant' => 'z', 'role' => 'with a bye', 'round' => 1],
            ],
            'a result of a participant who is no entrant' => [
                fn() => StageState::fromHistory($entrants, [$roundOne], [new Result(new Event([$stranger, $one], $first), $one)]),
                InvalidConfigurationReason::ParticipantNotAnEntrant,
                ['participant' => 'z', 'role' => 'in a result', 'round' => 1],
            ],
            'a withdrawal of a participant who is no entrant' => [
                fn() => StageState::fromHistory($entrants, [$roundOne], [], [$stranger]),
                InvalidConfigurationReason::ParticipantNotAnEntrant,
                ['participant' => 'z', 'role' => 'as withdrawn', 'round' => null],
            ],
            'a withdrawal with its round of a participant who is no entrant' => [
                fn() => StageState::fromHistory($entrants, [$roundOne], [], [new Withdrawal($stranger, 1)]),
                InvalidConfigurationReason::ParticipantNotAnEntrant,
                ['participant' => 'z', 'role' => 'as withdrawn', 'round' => null],
            ],
            'an adjustment for a participant who is no entrant' => [
                fn() => StageState::fromHistory($entrants, [$roundOne], [], [], [new Adjustment('z', 1.0)]),
                InvalidConfigurationReason::ParticipantNotAnEntrant,
                ['participant' => 'z', 'role' => 'in an adjustment', 'round' => null],
            ],
            'a participant in two events of a round' => [
                fn() => StageState::fromHistory($entrants, [new RoundPairing(1, null, [$oneVersusTwo, new Event([$one, $three], $first)])]),
                InvalidConfigurationReason::ParticipantTwiceInRound,
                ['round' => 1, 'participant' => 'h1'],
            ],
            'a participant in an event and among the byes' => [
                fn() => StageState::fromHistory($entrants, [new RoundPairing(1, null, [$oneVersusTwo], [$two])]),
                InvalidConfigurationReason::ParticipantTwiceInRound,
                ['round' => 1, 'participant' => 'h2'],
            ],
            'a participant with two byes in a round' => [
                fn() => StageState::fromHistory($entrants, [new RoundPairing(1, null, [$oneVersusTwo], [$three, $three])]),
                InvalidConfigurationReason::ParticipantTwiceInRound,
                ['round' => 1, 'participant' => 'h3'],
            ],
            'one event listed twice' => [
                fn() => StageState::fromHistory($entrants, [new RoundPairing(1, null, [$oneVersusTwo, new Event([$two, $one], $first)])]),
                InvalidConfigurationReason::ParticipantTwiceInRound,
                ['round' => 1],
            ],
            'one leg of a tie listed twice' => [
                fn() => StageState::fromHistory($entrants, [new RoundPairing(1, null, [
                    new Event([$one, $two], $first, ['tie_leg' => 2]),
                    new Event([$two, $one], $first, ['tie_leg' => 2]),
                ])]),
                InvalidConfigurationReason::ParticipantTwiceInRound,
                ['round' => 1],
            ],
            'two results of one event, the participants named either way round' => [
                fn() => StageState::fromHistory($entrants, [$roundOne], [
                    new Result($oneVersusTwo, $one),
                    new Result(new Event([$two, $one], $first), $two),
                ]),
                InvalidConfigurationReason::DuplicateResult,
                ['round' => 1, 'participants' => ['h2', 'h1']],
            ],
            'a result of an event its round does not hold' => [
                fn() => StageState::fromHistory($entrants, [$roundOne], [new Result(new Event([$one, $three], $first), $one)]),
                InvalidConfigurationReason::EventNotInRound,
                [],
            ],
            'a result of a round that is not supplied' => [
                fn() => StageState::fromHistory($entrants, [$roundOne], [new Result(new Event([$one, $three], new Round(2)), $one)]),
                InvalidConfigurationReason::EventNotInRound,
                [],
            ],
            'an event that carries another round number than its round' => [
                fn() => StageState::fromHistory($entrants, [new RoundPairing(1, null, [new Event([$one, $two], new Round(2))])]),
                InvalidConfigurationReason::EventNotInRound,
                ['pairing_round' => 1, 'event_round' => 2],
            ],
        ];
    });

    it('takes the two legs of a tie as one appearance', function (): void {
        [$one, $two] = $entrants = historyField(2);
        $round = new Round(1);
        $legs = [new Event([$one, $two], $round, ['tie_leg' => 1]), new Event([$two, $one], $round, ['tie_leg' => 2])];

        $state = StageState::fromHistory($entrants, [new RoundPairing(1, 'final', $legs)], [new Result($legs[1], $two)]);

        expect($state->getEventsWithoutResult())->toBe([$legs[0]]);
    });
});

describe('a round with some of its results, or none', function (): void {
    // Rules 2, 6 and 7 together, for every engine and at every point of a
    // stage: reading never throws, the next round is refused with the events
    // that are awaited, and the readiness query says the same.
    it('is read without an exception, and only the next round is refused', function (string $name): void {
        $engine = historyEngines()[$name];
        $unfinished = 0;

        foreach ([4, 5, 8] as $size) {
            $entrants = historyField($size);

            foreach (historyPoints($engine, $entrants, $size, true, false) as $state) {
                $awaited = historyAwaited($engine, $state);
                $readiness = $engine->getReadiness($state);

                expect($engine->getStandings($state)->getEntries())->toHaveCount(count($state->getAllSeenParticipants()))
                    ->and(historyPairs($readiness->getUnresolvedEvents()))->toBe(historyPairs($awaited), "{$name}, {$size} entrants");

                if ($awaited === []) {
                    expect($readiness->isAwaitingResults())->toBeFalse()
                        ->and($readiness->isComplete())->toBe($engine->isComplete($state))
                        ->and($readiness->isReady())->toBe(!$engine->isComplete($state));

                    continue;
                }

                ++$unfinished;
                expect($engine->isComplete($state))->toBeFalse()
                    ->and($engine->getOutcome($state))->toBeNull()
                    ->and($readiness->isReady())->toBeFalse()
                    ->and($readiness->isComplete())->toBeFalse()
                    ->and($readiness->getRoundNumber())->toBe($state->getLastRound()?->getRoundNumber());

                try {
                    $engine->pairNextRound($state);
                    $this->fail("{$name} paired a round after one that is not finished.");
                } catch (UnresolvedRoundException $exception) {
                    expect($exception->getReason())->toBe(InvalidConfigurationReason::RoundPartiallyResolved)
                        ->and($exception->getRoundNumber())->toBe($readiness->getRoundNumber())
                        ->and($exception->getUnresolvedEvents())->toBe($readiness->getUnresolvedEvents())
                        // The context says the same as plain data: the round, and the
                        // participant IDs of each event that is awaited, in play order
                        ->and($exception->getContext())->toBe([
                            'round' => $readiness->getRoundNumber(),
                            'unresolved' => array_map(
                                static fn(Event $event): array => historyIds($event->getParticipants()),
                                $readiness->getUnresolvedEvents()
                            ),
                        ]);
                }
            }
        }

        expect($unfinished)->toBeGreaterThan(5);
    })->with(array_keys(historyEngines()));

    it('names a round that has none of its results, where a bracket engine used to pair it again', function (string $name): void {
        $engine = historyEngines()[$name];
        $state = StageState::start(historyField(4));
        $first = $engine->pairNextRound($state);
        $recorded = $state->withRoundPlayed($first, []);

        expect($engine->getReadiness($recorded)->getUnresolvedEvents())->toBe($first->getEvents())
            ->and(fn(): RoundPairing => $engine->pairNextRound($recorded))->toThrow(UnresolvedRoundException::class)
            // Asked of a state that has not recorded the round, it is the same round
            ->and($engine->pairNextRound($state)->toArray())->toBe($first->toArray());
    })->with(array_keys(historyEngines()));

    // The verbs accept two results of one event, so a state can hold them
    // (a history cannot). The Swiss engine pairs from the table, which such
    // a state has none of.
    it('does not call a Swiss stage ready whose table cannot be built: it refuses as the pairing does', function (): void {
        $engine = new SwissPairingEngine(plannedRounds: 3);
        $state = StageState::start(historyField(4));
        $first = $engine->pairNextRound($state);
        [$one, $other] = $first->getEvents();
        $state = $state->withRoundPlayed($first, [new Result($one, $one->getParticipants()[0]), new Result($other, $other->getParticipants()[0])])
            ->withAdditionalResults([new Result($one, $one->getParticipants()[1])]);

        $refusals = [];
        foreach ([fn() => $engine->getReadiness($state), fn() => $engine->pairNextRound($state), fn() => $engine->getStandings($state)] as $call) {
            try {
                $call();
                $refusals[] = null;
            } catch (InvalidInputException $exception) {
                $refusals[] = str_contains($exception->getMessage(), 'Two results reference the same event');
            }
        }

        expect($refusals)->toBe([true, true, true])
            // Reading whether the stage is over needs no table
            ->and($engine->isComplete($state))->toBeFalse();
    });

    it('says three things of a stage and exactly one of them', function (): void {
        $ready = PairingReadiness::ready(3);
        $event = new Event(historyField(2), new Round(2));
        $awaiting = PairingReadiness::awaitingResults(2, [7 => $event]);
        $complete = PairingReadiness::complete();

        expect([$ready->isReady(), $ready->isAwaitingResults(), $ready->isComplete(), $ready->getRoundNumber(), $ready->getUnresolvedEvents()])
            ->toBe([true, false, false, 3, []])
            ->and([$awaiting->isReady(), $awaiting->isAwaitingResults(), $awaiting->isComplete(), $awaiting->getRoundNumber(), $awaiting->getUnresolvedEvents()])
            ->toBe([false, true, false, 2, [$event]])
            ->and([$complete->isReady(), $complete->isAwaitingResults(), $complete->isComplete(), $complete->getRoundNumber(), $complete->getUnresolvedEvents()])
            ->toBe([false, false, true, null, []]);
    });
});

// A tie that has every result and finished level awaits the application's
// decision (DTO\Decider). That is a round that awaits a fact, like one that
// awaits a result: it is read, and only the next round is refused.
describe('a bracket tie that is level and awaits its decider', function (): void {
    it('is read without an exception, is listed apart from the events that await a result, and refuses only the next round', function (
        string $name,
        int $legs
    ): void {
        $engine = historyEngines()[$name];
        $entrants = historyField(4);
        $first = $engine->pairNextRound(StageState::start($entrants));
        $events = $first->getEvents();
        $level = array_slice($events, 0, $legs);
        $other = array_slice($events, $legs);

        // The first tie is level in every leg, and nobody has decided it;
        // the second tie has no result yet
        $drawn = array_map(static fn(Event $event): Result => new Result($event), $level);
        $state = StageState::fromHistory($entrants, [$first], $drawn);
        $readiness = $engine->getReadiness($state);
        $read = $engine->getRecordedRounds($state)[0];

        expect($engine->isComplete($state))->toBeFalse()
            ->and($engine->getOutcome($state))->toBeNull()
            ->and($engine->getStandings($state)->getEntries())->toHaveCount(4)
            ->and($readiness->isReady())->toBeFalse()
            ->and($readiness->isAwaitingResults())->toBeTrue()
            ->and($readiness->getRoundNumber())->toBe(1)
            ->and($readiness->getUndecidedEvents())->toBe($level)
            ->and($readiness->getUnresolvedEvents())->toBe($other)
            ->and($read->getUndecidedEvents())->toBe($level)
            ->and($read->getUnresolvedEvents())->toBe($other)
            ->and($read->isResolved())->toBeFalse()
            ->and($read->isManual())->toBeFalse();

        // The decision is what is missing, so that is what the refusal
        // says, also while the round awaits a result as well
        [$one, $two] = $level[0]->getParticipants();
        try {
            $engine->pairNextRound($state);
            $this->fail('The round after an undecided tie was paired.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception)->not->toBeInstanceOf(UnresolvedRoundException::class)
                ->and($exception->getReason())->toBe(InvalidConfigurationReason::UndecidedTie)
                ->and($exception->getContext())->toBe(['round' => 1, 'participants' => [$one->getId(), $two->getId()]]);
        }

        // With the other tie played the round awaits the decision alone
        $rng = new Randomizer(new Mt19937(11));
        $played = historyResults(new RoundPairing(1, null, $other), $rng);
        $state = StageState::fromHistory($entrants, [$first], [...$drawn, ...$played]);

        expect($engine->isComplete($state))->toBeFalse()
            ->and($engine->getReadiness($state)->getUndecidedEvents())->toBe($level)
            ->and($engine->getReadiness($state)->getUnresolvedEvents())->toBe([])
            ->and(fn() => $engine->pairNextRound($state))->toThrow(InvalidConfigurationException::class);

        // Decided on whichever leg, and with the results in any order: the
        // round is finished and the entrant the decider names goes on
        $nextRounds = [];
        foreach (array_keys($level) as $deciding) {
            $decided = [];
            foreach ($level as $leg => $event) {
                $decided[] = new Result($event, null, [], [], null, $leg === $deciding ? new Decider([$two->getId()]) : null);
            }
            $state = StageState::fromHistory($entrants, [$first], $rng->shuffleArray([...$decided, ...$played]));
            $next = $engine->pairNextRound($state);
            $nextRounds[] = $next->toArray();
            $named = [...historyIds($next->getByes())];
            foreach ($next->getEvents() as $event) {
                $named = [...$named, ...historyIds($event->getParticipants())];
            }

            expect($engine->getReadiness($state)->isReady())->toBeTrue()
                ->and($engine->getRecordedRounds($state)[0]->isResolved())->toBeTrue()
                ->and($engine->getRecordedRounds($state)[0]->getUndecidedEvents())->toBe([])
                // In a double elimination bracket the losers round is next,
                // and holds the entrant who did not advance
                ->and($named)->toContain($engine instanceof DoubleEliminationEngine ? $one->getId() : $two->getId());
        }

        expect(array_unique($nextRounds, SORT_REGULAR))->toHaveCount(1);
    })->with([
        ['single elimination', 1],
        ['single elimination over two legs', 2],
        ['single elimination, re-seeded', 1],
        ['single elimination with a third-place tie', 1],
        ['double elimination', 1],
        ['double elimination over two legs', 2],
    ]);

    it('is given away by an entrant of it who withdraws, like any tie the results do not decide', function (string $name): void {
        $engine = historyEngines()[$name];
        [$h1, , , $h4] = $entrants = historyField(4);
        $first = $engine->pairNextRound(StageState::start($entrants));
        [$h1VsH4, $h2VsH3] = $first->getEvents();
        $results = [new Result($h1VsH4), new Result($h2VsH3, $h2VsH3->getParticipants()[0])];

        $waiting = StageState::fromHistory($entrants, [$first], $results);
        $withdrawn = StageState::fromHistory($entrants, [$first], $results, [new Withdrawal($h4, 1)]);
        $read = $engine->getRecordedRounds($withdrawn)[0];
        $next = $engine->pairNextRound($withdrawn);
        $named = [...historyIds($next->getByes())];
        foreach ($next->getEvents() as $event) {
            $named = [...$named, ...historyIds($event->getParticipants())];
        }

        expect($engine->getReadiness($waiting)->getUndecidedEvents())->toBe([$h1VsH4])
            ->and($engine->getReadiness($withdrawn)->isReady())->toBeTrue()
            ->and($read->getUndecidedEvents())->toBe([])
            ->and($read->isResolved())->toBeTrue()
            ->and($named)->toContain($h1->getId())
            ->and(in_array($h4->getId(), $named, true))->toBeFalse();
    })->with(['single elimination', 'double elimination']);

    // Two ties of a round can await a decision at once. Which of them the
    // refusal names is the bracket's to say, the first in bracket order and
    // the final before the third-place tie, and not the order the rows of
    // the application came back in.
    it('is the tie the refusal names whatever order the events of the round are in', function (string $name, int $recordedRounds): void {
        $engine = historyEngines()[$name]
            ?? new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2, thirdPlace: true));
        $entrants = historyField(4);
        $rng = new Randomizer(new Mt19937(3));
        $state = StageState::start($entrants);
        for ($round = 1; $round <= $recordedRounds; ++$round) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, historyResults($pairing, $rng));
        }

        // Every tie of the round is level in every leg, and none is decided
        $last = $engine->pairNextRound($state);
        $events = $last->getEvents();
        $drawn = array_map(static fn(Event $event): Result => new Result($event), $events);
        [$one, $two] = $events[0]->getParticipants();
        if (!isset(historyEngines()[$name])) {
            // The final has a winner in both legs; the third-place tie is level
            $final = array_slice($events, 0, 2);
            $drawn = [new Result($final[0], $one), new Result($final[1], $one), ...array_slice($drawn, 2)];
            [$one, $two] = $events[2]->getParticipants();
        }

        $contexts = [];
        foreach ([$events, array_reverse($events)] as $supplied) {
            $history = StageState::fromHistory(
                $entrants,
                [...$state->getRoundsPlayed(), new RoundPairing($last->getRoundNumber(), $last->getLabel(), $supplied, $last->getByes())],
                [...$state->getResults(), ...$drawn]
            );

            try {
                $engine->pairNextRound($history);
                $this->fail('The round after an undecided tie was paired.');
            } catch (InvalidConfigurationException $exception) {
                expect($exception->getReason())->toBe(InvalidConfigurationReason::UndecidedTie);
                $contexts[] = $exception->getContext();
            }
        }

        $ties = array_unique(array_map(static function (Event $event): string {
            $ids = historyIds($event->getParticipants());
            sort($ids);

            return implode(' v ', $ids);
        }, $events));

        $first = ['round' => $last->getRoundNumber(), 'participants' => [$one->getId(), $two->getId()]];

        expect($ties)->toHaveCount(2)
            ->and($contexts)->toBe([$first, $first]);
    })->with([
        ['single elimination', 0],
        ['single elimination over two legs', 0],
        ['double elimination', 0],
        // The last round of a field of 4: the final, then the third-place tie
        ['single elimination with a third-place tie', 1],
        // The third-place tie alone awaits its decider, and its second leg
        // is supplied first: the two are named in the order of its first leg
        ['a third-place tie over two legs, the final decided', 1],
    ]);

    // The contract is one for every stage of a double elimination bracket:
    // both brackets, the grand final and its reset, which the same two play.
    it('is read and refuses only the next round in every stage of a double elimination bracket, the grand final and its reset included', function (): void {
        $engine = new DoubleEliminationEngine();
        $state = StageState::start(historyField(4));
        $stages = [];

        while (!$engine->isComplete($state)) {
            if (count($stages) > 10) {
                throw new LogicException('The bracket did not complete.');
            }

            $pairing = $engine->pairNextRound($state);
            $events = $pairing->getEvents();
            $stages[] = $pairing->getLabel();
            $level = $state->withRoundPlayed($pairing, array_map(static fn(Event $event): Result => new Result($event), $events));
            $readiness = $engine->getReadiness($level);
            $rounds = $engine->getRecordedRounds($level);

            expect($engine->isComplete($level))->toBeFalse()
                ->and($engine->getOutcome($level))->toBeNull()
                ->and($engine->getStandings($level)->getEntries())->toHaveCount(4)
                ->and($readiness->isAwaitingResults())->toBeTrue()
                ->and($readiness->getRoundNumber())->toBe($pairing->getRoundNumber())
                ->and($readiness->getUndecidedEvents())->toBe($events)
                ->and($readiness->getUnresolvedEvents())->toBe([])
                ->and($rounds[count($rounds) - 1]->getUndecidedEvents())->toBe($events)
                ->and($rounds[count($rounds) - 1]->isResolved())->toBeFalse();

            try {
                $engine->pairNextRound($level);
                $this->fail("The stage after '{$pairing->getLabel()}' was paired while its tie awaited a decision.");
            } catch (InvalidConfigurationException $exception) {
                expect($exception)->not->toBeInstanceOf(UnresolvedRoundException::class)
                    ->and($exception->getReason())->toBe(InvalidConfigurationReason::UndecidedTie)
                    ->and($exception->getContext())->toBe([
                        'round' => $pairing->getRoundNumber(),
                        'participants' => historyIds($events[0]->getParticipants()),
                    ]);
            }

            // The second-named entrant goes on from every tie, so the losers
            // champion wins the grand final and the reset is played
            $state = $level;
            foreach ($events as $event) {
                $state = $state->withResultReplaced(
                    new Result($event, null, [], [], null, new Decider([$event->getParticipants()[1]->getId()]))
                );
            }
        }

        expect($stages)->toBe(['winners round 1', 'losers round 1', 'winners final', 'losers final', 'grand final', 'grand final reset'])
            ->and($engine->getOutcome($state))->toBeInstanceOf(StageOutcome::class);
    });

    it('is still refused by every call where what is recorded cannot be read: a decider on a tie its results decide', function (string $name): void {
        $engine = historyEngines()[$name];
        [$h1, , , $h4] = $entrants = historyField(4);
        $first = $engine->pairNextRound(StageState::start($entrants));
        $state = StageState::fromHistory(
            $entrants,
            [$first],
            [new Result($first->getEvents()[0], $h1, [], [], null, new Decider([$h4->getId()]))]
        );

        foreach ([
            fn() => $engine->isComplete($state),
            fn() => $engine->getOutcome($state),
            fn() => $engine->getReadiness($state),
            fn() => $engine->getRecordedRounds($state),
            fn() => $engine->getStandings($state),
            fn() => $engine->pairNextRound($state),
        ] as $call) {
            try {
                $call();
                $this->fail('A decider that overturns a result was read.');
            } catch (InvalidConfigurationException $exception) {
                expect($exception->getReason())->toBe(InvalidConfigurationReason::InvalidResult);
            }
        }
    })->with(['single elimination', 'double elimination']);
});

describe('a Swiss round paired before the results are in', function (): void {
    beforeEach(function (): void {
        $this->entrants = historyField(6);
        $this->engine = new SwissPairingEngine(plannedRounds: 3);
        $first = $this->engine->pairNextRound(StageState::start($this->entrants));
        // Two of the three results of round 1
        $this->unfinished = StageState::start($this->entrants)->withRoundPlayed($first, [
            new Result($first->getEvents()[0], $first->getEvents()[0]->getParticipants()[0]),
            new Result($first->getEvents()[1], $first->getEvents()[1]->getParticipants()[0]),
        ]);
        $this->awaited = $first->getEvents()[2];
    });

    it('is paired only when it is asked for in so many words, and is marked', function (): void {
        expect(fn(): RoundPairing => $this->engine->pairNextRound($this->unfinished))
            ->toThrow(UnresolvedRoundException::class, 'pairNextRoundOnIncompleteResults()');

        $early = $this->engine->pairNextRoundOnIncompleteResults($this->unfinished);

        expect($early->isPairedOnIncompleteResults())->toBeTrue()
            ->and($early->getRoundNumber())->toBe(2)
            ->and($early->getEvents())->toHaveCount(3)
            ->and($early->toArray()['paired_on_incomplete_results'] ?? null)->toBeTrue();
    });

    it('is paired from the table as it stands, in which the missing result counts for nobody', function (): void {
        $early = $this->engine->pairNextRoundOnIncompleteResults($this->unfinished);
        // The same state read by an engine that waits for no result at all
        $resultsFree = (new SwissPairingEngine(plannedRounds: 3, resultsFree: true))->pairNextRound($this->unfinished);

        expect(historyPairs($early->getEvents()))->toBe(historyPairs($resultsFree->getEvents()))
            ->and($resultsFree->isPairedOnIncompleteResults())->toBeFalse();
    });

    it('keeps its mark when it is recorded, stored and read back, and when it is built from a history', function (): void {
        $early = $this->engine->pairNextRoundOnIncompleteResults($this->unfinished);
        $state = $this->unfinished->withRoundPlayed($early, []);

        $restored = StageState::fromJson($state->toJson());
        $rebuilt = StageState::fromHistory($this->entrants, $state->getRoundsPlayed(), $state->getResults());

        foreach ([$state, $restored, $rebuilt] as $read) {
            $rounds = $this->engine->getRecordedRounds($read);

            expect($rounds[0]->isPairedOnIncompleteResults())->toBeFalse()
                ->and($rounds[1]->isPairedOnIncompleteResults())->toBeTrue()
                ->and($rounds[1]->getPairing()->isPairedOnIncompleteResults())->toBeTrue()
                // Round 1 still awaits its last result, and round 2 all of its own
                ->and($rounds[0]->getUnresolvedEvents())->toEqual([$this->awaited])
                ->and($rounds[0]->isResolved())->toBeFalse()
                ->and($rounds[1]->getUnresolvedEvents())->toHaveCount(3);
        }

        expect($restored->toArray())->toBe($state->toArray())
            ->and($rebuilt->toArray())->toBe($state->toArray());
    });

    it('is not marked when no result was missing: it is the round pairNextRound() gives', function (): void {
        $finished = $this->unfinished->withAdditionalResults([new Result($this->awaited, $this->awaited->getParticipants()[1])]);

        $asked = $this->engine->pairNextRoundOnIncompleteResults($finished);

        expect($asked->isPairedOnIncompleteResults())->toBeFalse()
            ->and($asked->toArray())->toBe($this->engine->pairNextRound($finished)->toArray())
            ->and(array_key_exists('paired_on_incomplete_results', $asked->toArray()))->toBeFalse();
    });

    it('does not end the stage: the last planned round has to be finished', function (): void {
        $engine = new SwissPairingEngine(plannedRounds: 1);

        expect($engine->isComplete($this->unfinished))->toBeFalse()
            ->and($engine->getOutcome($this->unfinished))->toBeNull()
            ->and($engine->getReadiness($this->unfinished)->getUnresolvedEvents())->toBe([$this->awaited]);

        $finished = $this->unfinished->withAdditionalResults([new Result($this->awaited, $this->awaited->getParticipants()[1])]);

        expect($engine->isComplete($finished))->toBeTrue()
            ->and($engine->getReadiness($finished)->isComplete())->toBeTrue()
            ->and($engine->getOutcome($finished))->toBeInstanceOf(StageOutcome::class);
    });

    // The result that was missing still has to arrive, and the verb that
    // adds results reaches the last recorded round only.
    it('leaves the earlier event awaiting its result, which arrives with the history once the early round is recorded', function (): void {
        $early = $this->engine->pairNextRoundOnIncompleteResults($this->unfinished);
        $rng = new Randomizer(new Mt19937(2));
        $state = $this->unfinished->withRoundPlayed($early, historyResults($early, $rng));
        $late = new Result($this->awaited, $this->awaited->getParticipants()[1]);

        // Round 2 is played in full, and the stage still waits for round 1
        expect($this->engine->getReadiness($state)->getRoundNumber())->toBe(1)
            ->and($this->engine->getReadiness($state)->getUnresolvedEvents())->toBe([$this->awaited])
            ->and(fn(): RoundPairing => $this->engine->pairNextRound($state))->toThrow(UnresolvedRoundException::class)
            ->and(fn(): StageState => $state->withAdditionalResults([$late]))
            ->toThrow(InvalidConfigurationException::class, 'different round');

        // The refusal says which round the verb reaches and where the
        // result goes, with the reason and the context it always had
        try {
            $state->withAdditionalResults([$late]);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::EventNotInRound)
                ->and($exception->getContext())->toBe(['pairing_round' => 2, 'result_round' => 1])
                ->and($exception->getConfigurationIssue())->toContain('added to the last recorded round only (round 2)')
                ->and($exception->getConfigurationIssue())->toContain('StageState::fromHistory()');
        }

        $caughtUp = StageState::fromHistory($this->entrants, $state->getRoundsPlayed(), [...$state->getResults(), $late]);

        expect($this->engine->getReadiness($caughtUp)->isReady())->toBeTrue()
            ->and($this->engine->pairNextRound($caughtUp)->getRoundNumber())->toBe(3)
            ->and($this->engine->pairNextRound($caughtUp)->isPairedOnIncompleteResults())->toBeFalse()
            // The mark of round 2 stays: it says how the round was paired
            ->and($this->engine->getRecordedRounds($caughtUp)[1]->isPairedOnIncompleteResults())->toBeTrue()
            ->and($this->engine->getRecordedRounds($caughtUp)[0]->isResolved())->toBeTrue();
    });

    it('is settled by an awarded result for an event that is never played', function (): void {
        $awarded = $this->unfinished->withAdditionalResults([
            new Result($this->awaited, $this->awaited->getParticipants()[0], [], [], new MissionGaming\Tactician\DTO\Award('not played')),
        ]);

        expect($this->engine->getReadiness($awarded)->isReady())->toBeTrue()
            ->and($this->engine->pairNextRound($awarded)->isPairedOnIncompleteResults())->toBeFalse();
    });
});

describe('results-free mode of the Swiss engine', function (): void {
    it('pairs rounds recorded without results, which the engine otherwise waits for', function (): void {
        $entrants = historyField(6);
        $waiting = new SwissPairingEngine(plannedRounds: 2);
        $resultsFree = new SwissPairingEngine(plannedRounds: 2, resultsFree: true);

        $state = StageState::start($entrants);
        $first = $resultsFree->pairNextRound($state);
        $state = $state->withRoundPlayed($first, []);

        expect($resultsFree->getReadiness($state)->isReady())->toBeTrue()
            ->and($resultsFree->getRecordedRounds($state)[0]->getUnresolvedEvents())->toBe([])
            ->and(fn(): RoundPairing => $waiting->pairNextRound($state))->toThrow(UnresolvedRoundException::class);

        $second = $resultsFree->pairNextRound($state);
        $state = $state->withRoundPlayed($second, []);

        expect($second->isPairedOnIncompleteResults())->toBeFalse()
            ->and(array_intersect(historyPairs($second->getEvents()), historyPairs($first->getEvents())))->toBe([])
            ->and($resultsFree->isComplete($state))->toBeTrue()
            ->and($resultsFree->getOutcome($state))->toBeInstanceOf(StageOutcome::class)
            // The same state is an unfinished stage to an engine that waits
            ->and($waiting->isComplete($state))->toBeFalse();
    });

    it('is part of the fingerprint, so a stage with no results yet is not mistaken for one that has none', function (): void {
        $waiting = new SwissPairingEngine();
        $resultsFree = new SwissPairingEngine(resultsFree: true);
        $entrants = historyField(4);

        expect($resultsFree->getFingerprint())->toBe($waiting->getFingerprint() . ';results-free=yes');

        foreach ([[$waiting, $resultsFree], [$resultsFree, $waiting]] as [$stamping, $reading]) {
            $stamped = StageState::start($entrants)->withEngineFingerprint($stamping->getFingerprint());

            expect($stamping->getReadiness($stamped)->isReady())->toBeTrue();
            try {
                $reading->pairNextRound($stamped);
                $this->fail('A state stamped in one mode was read in the other.');
            } catch (InvalidConfigurationException $exception) {
                expect($exception->getReason())->toBe(InvalidConfigurationReason::EngineFingerprintMismatch);
            }
        }
    });

    it('is what the whole-schedule preset pairs in', function (): void {
        $entrants = historyField(7);
        $schedule = (new SwissScheduler())->schedule($entrants, new SwissOptions(rounds: 4));

        // The preset's schedule is the rounds of a results-free engine
        $engine = new SwissPairingEngine(plannedRounds: 4, resultsFree: true);
        $state = StageState::start($entrants);
        $events = [];
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $events = [...$events, ...$pairing->getEvents()];
            $state = $state->withRoundPlayed($pairing, []);
        }

        expect(historyPairs($schedule->getEvents()))->toBe(historyPairs($events))
            ->and($state->getRoundsPlayed())->toHaveCount(4);
    });
});

describe('a recorded round the engine would have paired otherwise', function (): void {
    /**
     * The round with the second-named entrants of its first two ties
     * exchanged: a pairing by hand that breaks no rule.
     */
    $exchanged = static function (RoundPairing $pairing): RoundPairing {
        $ties = [];
        foreach ($pairing->getEvents() as $event) {
            $ids = historyIds($event->getParticipants());
            sort($ids);
            $ties[implode('|', $ids)] ??= $event->getParticipants();
        }
        $ties = array_values($ties);
        if (count($ties) < 2) {
            return $pairing;
        }
        [$one, $other] = [$ties[0][1], $ties[1][1]];

        return new RoundPairing(
            $pairing->getRoundNumber(),
            $pairing->getLabel(),
            array_map(
                static fn(Event $event): Event => new Event(
                    array_map(
                        static fn(Participant $participant): Participant => match ($participant->getId()) {
                            $one->getId() => $other,
                            $other->getId() => $one,
                            default => $participant,
                        },
                        $event->getParticipants()
                    ),
                    $event->getRound(),
                    $event->getMetadata()
                ),
                $pairing->getEvents()
            ),
            $pairing->getByes()
        );
    };

    it('is never refused, is reported as manual with the engine\'s own round beside it, and is what the next round follows from', function (string $name) use ($exchanged): void {
        $engine = historyEngines()[$name];
        $manualRounds = 0;

        foreach ([4, 6, 8] as $size) {
            foreach ([1, 2] as $seed) {
                $entrants = historyField($size);
                $rng = new Randomizer(new Mt19937(10 * $size + $seed));
                $state = StageState::start($entrants);
                $expectedManual = [];

                for ($round = 1; !$engine->isComplete($state); ++$round) {
                    expect($round)->toBeLessThan(40);

                    $own = $engine->pairNextRound($state);
                    $byHand = $exchanged($own);
                    $expectedManual[] = $byHand !== $own;
                    $manualRounds += $byHand !== $own ? 1 : 0;

                    $state = $state->withRoundPlayed($byHand, historyResults($byHand, $rng));

                    $rounds = $engine->getRecordedRounds($state);
                    $last = $rounds[count($rounds) - 1];
                    $derivation = $last->getDerivation();
                    assert($derivation instanceof RoundPairing);

                    expect(array_map(static fn(RecordedRound $read): RoundPairing => $read->getPairing(), $rounds))->toBe($state->getRoundsPlayed())
                        ->and(array_map(static fn(RecordedRound $read): bool => $read->isManual(), $rounds))->toBe($expectedManual, "{$name}, {$size} entrants, seed {$seed}")
                        // What the engine would have paired is the round it did pair
                        ->and(historyPairs($derivation->getEvents()))->toBe(historyPairs($own->getEvents()))
                        ->and($last->isResolved())->toBeTrue();
                }

                expect($engine->getOutcome($state))->toBeInstanceOf(StageOutcome::class)
                    ->and($engine->getReadiness($state)->isComplete())->toBeTrue();
            }
        }

        expect($manualRounds)->toBeGreaterThan(5);
    })->with(array_keys(historyEngines()));

    it('is not reported for the rounds an engine paired itself', function (string $name): void {
        $engine = historyEngines()[$name];

        foreach ([5, 8] as $size) {
            $entrants = historyField($size);

            foreach (historyPoints($engine, $entrants, $size, true, false) as $state) {
                foreach ($engine->getRecordedRounds($state) as $round) {
                    expect($round->isManual())->toBeFalse("{$name}, {$size} entrants, round {$round->getRoundNumber()}");
                }
            }
        }
    })->with(array_keys(historyEngines()));

    it('sends the winners of the ties that were played into the next round of a bracket', function (bool $reseed): void {
        // Eight entrants. The bracket has 1 v 8 and 4 v 5 in its upper half;
        // the round as played had 1 v 5 and 4 v 8.
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: $reseed));
        [$h1, $h2, $h3, $h4, $h5, $h6, $h7, $h8] = $entrants = historyField(8);
        $round = new Round(1, ['label' => 'quarterfinal']);
        $played = [new Event([$h1, $h5], $round), new Event([$h4, $h8], $round), new Event([$h2, $h7], $round), new Event([$h3, $h6], $round)];

        $state = StageState::fromHistory(
            $entrants,
            [new RoundPairing(1, 'quarterfinal', $played)],
            [new Result($played[0], $h5), new Result($played[1], $h8), new Result($played[2], $h2), new Result($played[3], $h3)]
        );

        $semifinals = $engine->pairNextRound($state);
        $read = $engine->getRecordedRounds($state)[0];

        expect($read->isManual())->toBeTrue()
            ->and(historyPairs($read->getDerivation()?->getEvents() ?? []))->toBe(['h1 v h8 leg 1', 'h2 v h7 leg 1', 'h3 v h6 leg 1', 'h4 v h5 leg 1'])
            ->and($semifinals->getRoundNumber())->toBe(2)
            ->and($semifinals->getLabel())->toBe('semifinal');

        // On a fixed path a tie stands where the earlier of its entrants
        // stood: h5 takes the place of h1 and h8 that of h4, in one half.
        // A re-seeded bracket folds the four who advanced by the table,
        // level ones in list order: h2, h3, h5, h8.
        expect(historyPairs($semifinals->getEvents()))
            ->toBe($reseed ? ['h2 v h8 leg 1', 'h3 v h5 leg 1'] : ['h2 v h3 leg 1', 'h5 v h8 leg 1']);
    })->with([[false], [true]]);

    it('avoids, in a Swiss stage, the pairs that were played and not the pairs it would have made', function (): void {
        // Four entrants: the engine pairs 1 v 2 and 3 v 4 first. The round
        // as played was 1 v 3 and 2 v 4, with the first-named winning.
        $engine = new SwissPairingEngine();
        [$h1, $h2, $h3, $h4] = $entrants = historyField(4);
        $round = new Round(1);
        $played = [new Event([$h1, $h3], $round), new Event([$h2, $h4], $round)];

        $state = StageState::fromHistory(
            $entrants,
            [new RoundPairing(1, null, $played)],
            [new Result($played[0], $h1), new Result($played[1], $h2)]
        );

        $read = $engine->getRecordedRounds($state)[0];

        expect($read->isManual())->toBeTrue()
            ->and(historyPairs($read->getDerivation()?->getEvents() ?? []))->toBe(['h1 v h2 leg 1', 'h3 v h4 leg 1'])
            // The two winners meet, which the engine's own first round had done already
            ->and(historyPairs($engine->pairNextRound($state)->getEvents()))->toBe(['h1 v h2 leg 1', 'h3 v h4 leg 1']);
    });

    it('has no derivation to show where the engine draws its rounds', function (): void {
        $engine = new SwissPairingEngine(randomizer: new Randomizer(new Mt19937(7)));
        $entrants = historyField(6);
        $state = StageState::start($entrants);
        $first = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($first, historyResults($first, new Randomizer(new Mt19937(1))));

        $read = $engine->getRecordedRounds($state)[0];

        expect($read->getDerivation())->toBeNull()
            ->and($read->isManual())->toBeFalse()
            ->and($read->getPairing())->toBe($first);
    });

    it('is refused in a double elimination stage only where it leaves another number of places than the stage has', function (): void {
        // Four entrants: winners round 1 has two ties. Recorded with one,
        // it would send three entrants on where the bracket has two places.
        $engine = new DoubleEliminationEngine();
        [$h1, $h2, $h3, $h4] = $entrants = historyField(4);
        $round = new Round(1, ['label' => 'winners round 1']);
        $only = new Event([$h1, $h4], $round);

        $state = StageState::fromHistory($entrants, [new RoundPairing(1, 'winners round 1', [$only])], [new Result($only, $h1)]);

        try {
            $engine->isComplete($state);
            $this->fail('A stage with a tie missing was read.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::RecordedRoundMismatch)
                ->and($exception->getContext())->toBe(['round' => 1, 'stage' => 'winners round 1', 'places' => 2, 'recorded_places' => 3]);
        }
    });
});

describe('a withdrawal from a live bracket', function (): void {
    it('sends the opponent on without an event, and leaves every other tie where it was', function (string $name): void {
        $engine = historyEngines()[$name];
        $entrants = historyField(8);

        // The first round, won by the better-placed entrant of every tie
        $state = StageState::start($entrants);
        $first = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($first, array_map(
            static function (Event $event): Result {
                [$first, $second] = $event->getParticipants();

                return new Result($event, (int) substr($first->getId(), 1) < (int) substr($second->getId(), 1) ? $first : $second);
            },
            $first->getEvents()
        ));

        // The next round as it stands, and with an entrant of its first tie gone
        $before = $engine->pairNextRound($state);
        [$leaving, $opponent] = $before->getEvents()[0]->getParticipants();
        $after = $engine->pairNextRound($state->withoutParticipant($leaving));

        $otherTies = array_values(array_filter(
            $before->getEvents(),
            static fn(Event $event): bool => !$event->hasParticipant($leaving)
        ));
        $byes = [...historyIds($before->getByes()), $opponent->getId()];
        $byesAfter = historyIds($after->getByes());
        sort($byes);
        sort($byesAfter);

        expect($after->getRoundNumber())->toBe($before->getRoundNumber())
            ->and($after->getLabel())->toBe($before->getLabel())
            // Nothing is drawn again: the other ties are the ties they were,
            // event for event
            ->and(array_map(static fn(Event $event): array => $event->toArray(), $after->getEvents()))
            ->toBe(array_map(static fn(Event $event): array => $event->toArray(), $otherTies))
            ->and($byesAfter)->toBe($byes);
    })->with([
        'single elimination',
        'single elimination over two legs',
        'single elimination, re-seeded',
        'single elimination with a third-place tie',
        'double elimination',
        'double elimination over two legs',
    ]);

    it('settles a tie that was paired before the entrant withdrew, and waits for the others', function (string $name): void {
        $engine = historyEngines()[$name];
        [$h1, , , , , , , $h8] = $entrants = historyField(8);

        $state = StageState::start($entrants);
        $first = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($first, [])->withoutParticipant($h8);

        $abandoned = array_values(array_filter($first->getEvents(), static fn(Event $event): bool => $event->hasParticipant($h8)));
        $others = array_values(array_filter($first->getEvents(), static fn(Event $event): bool => !$event->hasParticipant($h8)));
        $read = $engine->getRecordedRounds($state)[0];

        expect($read->getUncontestedEvents())->toBe($abandoned)
            ->and($read->getUnresolvedEvents())->toBe($others)
            ->and($read->isManual())->toBeFalse()
            ->and($engine->getReadiness($state)->getUnresolvedEvents())->toBe($others);

        // The other ties are played; h1 is in the next round without having played
        $rng = new Randomizer(new Mt19937(3));
        $state = $state->withAdditionalResults(historyResults(new RoundPairing(1, null, $others), $rng));
        $next = $engine->pairNextRound($state);
        $inTheNextRound = [...historyIds($next->getByes())];
        foreach ($next->getEvents() as $event) {
            $inTheNextRound = [...$inTheNextRound, ...historyIds($event->getParticipants())];
        }

        expect($engine->getReadiness($state)->isReady())->toBeTrue()
            ->and($inTheNextRound)->toContain('h1')
            ->and($inTheNextRound)->not->toContain('h8')
            ->and($state->getResults())->toHaveCount(count($others));
    })->with([
        'single elimination',
        'single elimination over two legs',
        'single elimination, re-seeded',
        'single elimination with a third-place tie',
    ]);

    it('gives the withdrawn entrant of a double elimination tie the loss, so that it drops and gives the next tie away too', function (): void {
        $engine = new DoubleEliminationEngine();
        [$h1, , , $h4] = $entrants = historyField(4);

        // Winners round 1 is paired: h1 v h4 and h2 v h3. h4 withdraws
        // before it is played; h2 beats h3.
        $state = StageState::start($entrants);
        $first = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($first, [new Result($first->getEvents()[1], $first->getEvents()[1]->getParticipants()[0])])
            ->withoutParticipant($h4);

        // Losers round 1 would be h4 v h3: h3 advances without an event, so
        // the stage takes no round number and the winners final is next
        $next = $engine->pairNextRound($state);

        expect($next->getLabel())->toBe('winners final')
            ->and($next->getRoundNumber())->toBe(2)
            ->and(historyPairs($next->getEvents()))->toBe(['h1 v h2 leg 1']);

        // Played to its end, h4 is last: it went out first
        $rng = new Randomizer(new Mt19937(5));
        for ($round = 2; !$engine->isComplete($state); ++$round) {
            expect($round)->toBeLessThan(10);
            $pairing = $engine->pairNextRound($state);
            expect(array_filter($pairing->getEvents(), static fn(Event $event): bool => $event->hasParticipant($h4)))->toBe([]);
            $state = $state->withRoundPlayed($pairing, historyResults($pairing, $rng));
        }

        $entries = $engine->getOutcome($state)?->getStandings()->getEntries() ?? [];
        expect($entries[3]->getParticipant()->getId())->toBe('h4');
    });

    it('lets the results stand that an entrant recorded before it withdrew', function (): void {
        $engine = new SingleEliminationEngine();
        [$h1, , , $h4] = $entrants = historyField(4);

        $state = StageState::start($entrants);
        $first = $engine->pairNextRound($state);
        // h4 beats h1, then withdraws: h4 went on, and the final is not played
        $state = $state->withRoundPlayed($first, [
            new Result($first->getEvents()[0], $h4),
            new Result($first->getEvents()[1], $first->getEvents()[1]->getParticipants()[0]),
        ])->withoutParticipant($h4);

        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);

        expect($engine->isComplete($state))->toBeTrue()
            ->and($engine->getReadiness($state)->isComplete())->toBeTrue()
            // h2 takes the final without playing it; h4 is second, as the
            // finalist it was. h1 and h3 went out in one round with one
            // record, and are in the order of the entrant list.
            ->and(historyIds(array_map(
                static fn(StandingEntry $entry): Participant => $entry->getParticipant(),
                $outcome->getStandings()->getEntries()
            )))->toBe(['h2', 'h4', 'h1', 'h3'])
            ->and($outcome->getStandings()->getTiers())->toBe([0, 1, 2, 2]);
    });

    it('removes an entrant who withdraws before the first round, and folds those who remain', function (string $name): void {
        $engine = historyEngines()[$name];
        [, , $h3] = $entrants = historyField(6);
        $remaining = array_values(array_filter($entrants, static fn(Participant $entrant): bool => $entrant !== $h3));

        $withdrawn = StageState::start($entrants)->withoutParticipant($h3);
        $pairing = $engine->pairNextRound($withdrawn);

        // Once the first round is recorded without it, the entrant was never in the stage
        expect(historyIds($withdrawn->getEntrants()))->toBe(historyIds($remaining))
            ->and($pairing->toArray())->toBe($engine->pairNextRound(StageState::start($remaining))->toArray())
            ->and($withdrawn->withRoundPlayed($pairing, [])->toArray())->toBe(StageState::start($remaining)->withRoundPlayed($pairing, [])->toArray());
    })->with(array_keys(historyEngines()));

    // Any number of entrants withdrawing at any point. The bracket is
    // played to its end, no round that is recorded is ever read otherwise
    // than it was recorded, and nobody is paired after having withdrawn.
    it('is played to its end whoever withdraws and whenever', function (string $name): void {
        $engine = historyEngines()[$name];
        $uncontested = 0;

        foreach ([4, 5, 7, 8] as $size) {
            foreach ([1, 2, 3] as $seed) {
                $entrants = historyField($size);
                $rng = new Randomizer(new Mt19937(1000 * $size + $seed));
                $state = StageState::start($entrants);
                $gone = [];

                for ($round = 1; !$engine->isComplete($state); ++$round) {
                    expect($round)->toBeLessThan(40);

                    $pairing = $engine->pairNextRound($state);
                    foreach ($pairing->getEvents() as $event) {
                        expect(array_intersect(historyIds($event->getParticipants()), $gone))->toBe([], "{$name}, {$size} entrants, seed {$seed}");
                    }

                    // Paired, then somebody may leave before the round is played
                    $state = $state->withRoundPlayed($pairing, []);
                    if ($rng->getInt(0, 2) === 0) {
                        $leaving = $entrants[$rng->getInt(0, $size - 1)];
                        $gone[] = $leaving->getId();
                        $state = $state->withoutParticipant($leaving);
                    }

                    $awaited = $engine->getReadiness($state)->getUnresolvedEvents();
                    $uncontested += count($pairing->getEvents()) - count($awaited);
                    $state = $state->withAdditionalResults(historyResults(new RoundPairing($pairing->getRoundNumber(), null, $awaited), $rng));

                    // And somebody may leave between two rounds
                    if ($rng->getInt(0, 3) === 0) {
                        $leaving = $entrants[$rng->getInt(0, $size - 1)];
                        $gone[] = $leaving->getId();
                        $state = $state->withoutParticipant($leaving);
                    }

                    expect(array_map(
                        static fn(RecordedRound $read): RoundPairing => $read->getPairing(),
                        $engine->getRecordedRounds($state)
                    ))->toBe($state->getRoundsPlayed());
                }

                $rebuilt = historyRebuilt($state, $entrants, $rng);

                expect($engine->getOutcome($state))->toBeInstanceOf(StageOutcome::class)
                    ->and(historyIds($state->getEntrants()))->toBe(historyIds($entrants))
                    ->and($rebuilt->toArray())->toBe($state->toArray())
                    ->and(historyReading($engine, $rebuilt))->toBe(historyReading($engine, $state));
            }
        }

        // Ties were given away: these are not brackets nobody left
        expect($uncontested)->toBeGreaterThan(0);
    })->with([
        'single elimination',
        'single elimination over two legs',
        'single elimination, re-seeded',
        'single elimination with a third-place tie',
        'double elimination',
        'double elimination over two legs',
    ]);

    // A round takes no number where the bracket has no tie left to play in
    // it, so a recorded round has to be told from the round after it. A
    // round drawn by hand holds none of the bracket's own ties; it is known
    // by naming their entrants: an entrant a round names was in the bracket
    // when that round was paired, so the ties could be played then.
    it('reads a round drawn by hand as the round it was, when withdrawals have since left the bracket no tie to play in it', function (): void {
        [$h1, $h2, $h3, $h4] = $entrants = historyField(4);

        // The bracket pairs h1 v h4 and h2 v h3. Drawn by hand: h1 v h3 and
        // h2 v h4, won by h1 and h2, who then both withdraw. Each tie of
        // the bracket's own first round now has an entrant who is gone.
        $round = new Round(1, ['label' => 'semifinal']);
        $events = [new Event([$h1, $h3], $round), new Event([$h2, $h4], $round)];
        $state = StageState::fromHistory(
            $entrants,
            [new RoundPairing(1, 'semifinal', $events)],
            [new Result($events[0], $h1), new Result($events[1], $h2)],
            [new Withdrawal($h1, 1), new Withdrawal($h2, 1)]
        );

        $engine = new SingleEliminationEngine();
        $read = $engine->getRecordedRounds($state);

        expect($read)->toHaveCount(1)
            ->and($read[0]->isManual())->toBeTrue()
            ->and($read[0]->getDerivation()?->getLabel())->toBe('semifinal')
            // The round beside it is the one this engine pairs from who was
            // in the bracket then: the round names h1 and h2, so they were,
            // and it is the fold of the four
            ->and(historyPairs($read[0]->getDerivation()?->getEvents() ?? []))->toBe(['h1 v h4 leg 1', 'h2 v h3 leg 1'])
            ->and(historyIds($read[0]->getDerivation()?->getByes() ?? []))->toBe([])
            // The final is between two entrants who have withdrawn, h1
            // first: that gave h2 the final, and the bracket was over then,
            // h2's withdrawal after it. The two who lost the ties that were
            // played share the tier of the round before the final.
            ->and($engine->isComplete($state))->toBeTrue()
            ->and(historyIds(array_map(
                static fn(StandingEntry $entry): Participant => $entry->getParticipant(),
                $engine->getStandings($state)->getEntries()
            )))->toBe(['h2', 'h1', 'h3', 'h4'])
            ->and($engine->getStandings($state)->getTiers())->toBe([0, 1, 2, 2]);

        // Double elimination: the same first round, the losers round the
        // engine pairs from it, and then the two withdrawals. The state used
        // to be refused, the first round being read as the winners final.
        $double = new DoubleEliminationEngine();
        $winners = new Round(1, ['label' => 'winners round 1']);
        $events = [new Event([$h1, $h3], $winners), new Event([$h2, $h4], $winners)];
        $state = StageState::fromHistory(
            $entrants,
            [new RoundPairing(1, 'winners round 1', $events)],
            [new Result($events[0], $h1), new Result($events[1], $h2)]
        );
        $losers = $double->pairNextRound($state);
        $state = $state->withRoundPlayed($losers, [new Result($losers->getEvents()[0], $h3)])
            ->withoutParticipant($h1)
            ->withoutParticipant($h2);

        $read = $double->getRecordedRounds($state);

        expect(historyPairs($losers->getEvents()))->toBe(['h3 v h4 leg 1'])
            ->and(array_map(static fn(RecordedRound $round): int => $round->getRoundNumber(), $read))->toBe([1, 2])
            ->and($read[0]->isManual())->toBeTrue()
            ->and($read[0]->getDerivation()?->getLabel())->toBe('winners round 1')
            ->and($read[1]->isManual())->toBeFalse()
            // h3 is the one entrant left, and takes the bracket
            ->and($double->isComplete($state))->toBeTrue()
            ->and($double->getStandings($state)->getEntries()[0]->getParticipant()->getId())->toBe('h3');
    });

    // The other side of the same rule: the round after a round that was
    // never played names one entrant of each of its ties and not both.
    it('reads the round after a round nobody played as that round, also when one of its entrants withdraws later', function (): void {
        $engine = new SingleEliminationEngine();
        [$h1, $h2, $h3, $h4] = $entrants = historyField(8);

        $state = StageState::start($entrants);
        $first = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($first, array_map(
            static fn(Event $event): Result => new Result($event, $event->getParticipants()[0]),
            $first->getEvents()
        ));

        // h1 to h4 are in the semifinals, h1 v h4 and h2 v h3; h3 and h4
        // withdraw, so neither is played and the final is the second round
        $state = $state->withoutParticipant($h3)->withoutParticipant($h4);
        $final = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($final, [new Result($final->getEvents()[0], $h1)]);

        // ...and h2, who lost it, withdraws afterwards
        $state = $state->withoutParticipant($h2);
        $read = $engine->getRecordedRounds($state);

        expect($final->getLabel())->toBe('final')
            ->and($final->getRoundNumber())->toBe(2)
            ->and(historyPairs($final->getEvents()))->toBe(['h1 v h2 leg 1'])
            ->and(array_map(static fn(RecordedRound $round): ?string => $round->getDerivation()?->getLabel(), $read))
            ->toBe(['quarterfinal', 'final'])
            ->and($read[1]->isManual())->toBeFalse()
            ->and($engine->isComplete($state))->toBeTrue()
            ->and(array_slice(historyIds(array_map(
                static fn(StandingEntry $entry): Participant => $entry->getParticipant(),
                $engine->getStandings($state)->getEntries()
            )), 0, 4))->toBe(['h1', 'h2', 'h3', 'h4']);
    });

    it('puts out both entrants of a void tie, in the order of their slots, whatever order the round names them in', function (): void {
        $engine = new DoubleEliminationEngine(new EliminationOptions(legsPerTie: 2));
        [, $h2, $h3] = $entrants = historyField(3);

        // h1 has a bye; h2 v h3 is paired over two legs, and both withdraw
        // before either is played
        $first = $engine->pairNextRound(StageState::start($entrants));
        $legs = $first->getEvents();
        $reading = [];
        foreach ([$legs, array_reverse($legs)] as $order) {
            $state = StageState::fromHistory(
                $entrants,
                [new RoundPairing(1, $first->getLabel(), $order, $first->getByes())],
                [],
                [new Withdrawal($h3, 1), new Withdrawal($h2, 1)]
            );
            $reading[] = historyReading($engine, $state);
        }

        expect($legs)->toHaveCount(2)
            ->and($reading[1])->toBe($reading[0])
            ->and($reading[0]['complete'])->toBeTrue()
            // h1 went on three times without a tie (its bye, the winners
            // final and the grand final, which had nobody to meet it), each
            // credited as a win of a two-legged tie: 3 x 2 x 3 points
            ->and($reading[0]['standings'][0])->toBe(['h1 18', 'h2 0', 'h3 0']);
    });

    // A state stored before the entrant list was kept (0.2.x, and 0.3.0
    // before this) names a withdrawn participant in its rounds only.
    it('never pairs a participant who has withdrawn, also where the state does not know the place it had', function (string $name): void {
        $engine = historyEngines()[$name];
        [$h1] = $entrants = historyField(8);

        // The first round, won by the better-placed entrant of every tie
        $state = StageState::start($entrants);
        $first = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($first, array_map(
            static function (Event $event): Result {
                [$one, $other] = $event->getParticipants();

                return new Result($event, (int) substr($one->getId(), 1) < (int) substr($other->getId(), 1) ? $one : $other);
            },
            $first->getEvents()
        ));

        // h1 won its tie and then withdrew; stored without the entrant list
        // and the withdrawals, as 0.2.x stored it
        $stored = $state->withoutParticipant($h1)->toArray();
        unset($stored['entrants'], $stored['withdrawals']);
        $read = StageState::fromArray($stored);
        $rng = new Randomizer(new Mt19937(7));

        expect($read->getWithdrawnEntrants())->toBe([]);

        for ($round = 2; !$engine->isComplete($read); ++$round) {
            expect($round)->toBeLessThan(20);

            $pairing = $engine->pairNextRound($read);
            foreach ($pairing->getEvents() as $event) {
                expect(in_array('h1', historyIds($event->getParticipants()), true))->toBeFalse("{$name}, round {$round}");
            }
            expect(historyIds($pairing->getByes()))->not->toContain('h1');

            $read = $read->withRoundPlayed($pairing, historyResults($pairing, $rng));
        }

        expect($engine->getOutcome($read))->toBeInstanceOf(StageOutcome::class);
    })->with([
        'single elimination',
        'single elimination over two legs',
        'single elimination, re-seeded',
        'single elimination with a third-place tie',
        'double elimination',
        'double elimination over two legs',
    ]);
});

describe('the stored form of a state', function (): void {
    it('says the entrant list only when an entrant has withdrawn from the live stage', function (): void {
        $engine = new SingleEliminationEngine();
        [, $h2] = $entrants = historyField(4);

        $state = StageState::start($entrants);
        $live = $state->withRoundPlayed($engine->pairNextRound($state), []);
        $withdrawn = $live->withoutParticipant($h2);

        expect(array_key_exists('entrants', $state->toArray()))->toBeFalse()
            ->and(array_key_exists('entrants', $live->toArray()))->toBeFalse()
            ->and($withdrawn->toArray()['entrants'] ?? null)->toBe(['h1', 'h2', 'h3', 'h4'])
            ->and($withdrawn->toArray()['active'])->toBe(['h1', 'h3', 'h4'])
            ->and(historyIds($withdrawn->getParticipants()))->toBe(['h1', 'h3', 'h4'])
            ->and(historyIds($withdrawn->getWithdrawnEntrants()))->toBe(['h2'])
            // And when: after which round, the last key of the form
            ->and($withdrawn->toArray()['withdrawals'] ?? null)->toBe([['participant' => 'h2', 'after_round' => 1]])
            ->and(array_key_last($withdrawn->toArray()))->toBe('withdrawals');

        $restored = StageState::fromJson($withdrawn->toJson());

        expect($restored)->toEqual($withdrawn)
            ->and($restored->toArray())->toBe($withdrawn->toArray())
            ->and($engine->getRecordedRounds($restored)[0]->getUncontestedEvents())->toHaveCount(1);
    });

    it('reads data without the entrant list as it always did: the active list is the entrant list', function (): void {
        $engine = new SwissPairingEngine(resultsFree: true);
        [, $h2] = $entrants = historyField(4);
        $state = StageState::start($entrants);
        $stored = $state->withRoundPlayed($engine->pairNextRound($state), [])->withoutParticipant($h2)->toArray();
        unset($stored['entrants'], $stored['withdrawals']);

        $read = StageState::fromArray($stored);

        expect(historyIds($read->getEntrants()))->toBe(['h1', 'h3', 'h4'])
            ->and($read->getWithdrawnEntrants())->toBe([])
            ->and(historyIds($read->getAllSeenParticipants()))->toBe(['h1', 'h3', 'h4', 'h2'])
            ->and($read->toArray())->toBe($stored);
    });

    it('refuses an entrant list that is not the active list with the withdrawn in their places', function (array $entrantList, string $message): void {
        $engine = new SingleEliminationEngine();
        [, $h2] = $entrants = historyField(4);
        $state = StageState::start($entrants);
        $stored = $state->withRoundPlayed($engine->pairNextRound($state), [])->withoutParticipant($h2)->toArray();
        $stored['entrants'] = $entrantList;

        expect(fn(): StageState => StageState::fromArray($stored))->toThrow(InvalidInputException::class, $message);
    })->with([
        'an ID the registry does not hold' => [['h1', 'h2', 'h3', 'h4', 'zz'], "unknown entrant 'zz'"],
        'an ID twice' => [['h1', 'h2', 'h2', 'h3', 'h4'], "lists entrant 'h2' twice"],
        'an active participant left out' => [['h1', 'h2', 'h3'], 'must hold every active participant'],
        'the active participants in another order' => [['h3', 'h2', 'h1', 'h4'], 'must hold every active participant'],
        'something that is no ID' => [['h1', 2, 'h3', 'h4'], 'unknown entrant 2'],
    ]);

    it('refuses an entrant list that is no list', function (): void {
        $stored = StageState::start(historyField(2))->toArray();
        $stored['entrants'] = 'h1,h2';

        expect(fn(): StageState => StageState::fromArray($stored))->toThrow(InvalidInputException::class, 'entrant list must be an array');
    });

    it('refuses a mark on a round that is no boolean', function (): void {
        $data = (new RoundPairing(1, null, []))->toArray();
        $data['paired_on_incomplete_results'] = 'yes';

        expect(fn(): RoundPairing => RoundPairing::fromArray($data, []))
            ->toThrow(InvalidInputException::class, 'paired_on_incomplete_results must be a boolean');
    });
});
