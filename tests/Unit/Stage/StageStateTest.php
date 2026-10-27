<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;

describe('StageState', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice', 1);
        $this->bob = new Participant('p2', 'Bob', 2);
        $this->carol = new Participant('p3', 'Carol', 3);
        $this->dave = new Participant('p4', 'Dave', 4);
        $this->participants = [$this->alice, $this->bob, $this->carol, $this->dave];
    });

    it('starts with the given participants and no history', function (): void {
        $state = StageState::start($this->participants);

        expect($state->getParticipants())->toBe($this->participants);
        expect($state->getRoundsPlayed())->toBe([]);
        expect($state->getResults())->toBe([]);
        expect($state->getLastRound())->toBeNull();
        expect($state->getNextRoundNumber())->toBe(1);
        expect($state->getPlayedEvents())->toBe([]);
        expect($state->getByeIds())->toBe([]);
        expect($state->getByeCounts())->toBe([]);
    });

    it('rejects duplicate participant IDs at the start', function (): void {
        StageState::start([$this->alice, new Participant('p1', 'Alice Clone')]);
    })->throws(InvalidConfigurationException::class);

    it('records a played round immutably', function (): void {
        $event1 = new Event([$this->alice, $this->bob], new Round(1));
        $event2 = new Event([$this->carol, $this->dave], new Round(1));
        $pairing = new RoundPairing(1, null, [$event1, $event2]);
        $results = [new Result($event1, $this->alice), new Result($event2, $this->carol)];

        $start = StageState::start($this->participants);
        $state = $start->withRoundPlayed($pairing, $results);

        // Original state unchanged
        expect($start->getRoundsPlayed())->toBe([]);

        expect($state->getRoundsPlayed())->toBe([$pairing]);
        expect($state->getLastRound())->toBe($pairing);
        expect($state->getNextRoundNumber())->toBe(2);
        expect($state->getResults())->toBe($results);
        expect($state->getPlayedEvents())->toBe([$event1, $event2]);
    });

    it('accepts a round recorded without results', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(1));
        $state = StageState::start($this->participants)
            ->withRoundPlayed(new RoundPairing(1, null, [$event]), []);

        expect($state->getPlayedEvents())->toBe([$event]);
        expect($state->getResults())->toBe([]);
        expect($state->getNextRoundNumber())->toBe(2);
    });

    // A round is recorded when it is drawn and its results are added as
    // they come: the state is the one a round recorded with no results is,
    // and stores the same
    it('records a round as paired, with none of its results, and its results added later', function (): void {
        $first = new Event([$this->alice, $this->bob], new Round(1));
        $second = new Event([$this->carol, $this->dave], new Round(1));
        $pairing = new RoundPairing(1, 'round 1', [$first, $second]);
        $start = StageState::start($this->participants);

        $paired = $start->withRoundPaired($pairing);
        $played = $paired->withAdditionalResults([new Result($first, $this->alice), new Result($second, $this->dave)]);

        expect($paired->getRoundsPlayed())->toBe([$pairing])
            ->and($paired->getResults())->toBe([])
            ->and($paired->getEventsWithoutResult())->toBe([$first, $second])
            ->and($paired->getNextRoundNumber())->toBe(2)
            ->and($paired->toArray())->toBe($start->withRoundPlayed($pairing, [])->toArray())
            ->and($played->toArray())->toBe($start->withRoundPlayed($pairing, [
                new Result($first, $this->alice),
                new Result($second, $this->dave),
            ])->toArray());
    });

    it('refuses to record as paired what it refuses to record as played', function (Closure $pairing, InvalidConfigurationReason $reason): void {
        $state = StageState::start($this->participants)->withRoundPaired(new RoundPairing(1, null, [new Event([$this->alice, $this->bob], new Round(1))]));

        try {
            $state->withRoundPaired($pairing($this));
            $this->fail('The pairing was recorded.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe($reason);
        }
    })->with([
        'a round that does not follow the last one' => [
            fn($test) => new RoundPairing(1, null, [new Event([$test->carol, $test->dave], new Round(1))]),
            InvalidConfigurationReason::RoundOutOfSequence,
        ],
        'a round that leaves one out' => [
            fn($test) => new RoundPairing(3, null, [new Event([$test->carol, $test->dave], new Round(3))]),
            InvalidConfigurationReason::RoundOutOfSequence,
        ],
        'an event of another round' => [
            fn($test) => new RoundPairing(2, null, [new Event([$test->carol, $test->dave], new Round(3))]),
            InvalidConfigurationReason::EventNotInRound,
        ],
        'an event without a round number' => [
            fn($test) => new RoundPairing(2, null, [new Event([$test->carol, $test->dave])]),
            InvalidConfigurationReason::EventWithoutRoundNumber,
        ],
    ]);

    // Every state the verbs build is one fromHistory() builds: the rounds
    // are numbered 1, 2, 3 and so on. A round that is not the next one is
    // refused by both, for the same reason; an engine read a state with a
    // round left out as if that round had been played.
    it('refuses a round that is not the next one, as fromHistory() refuses it', function (string $verb, array $earlier, int $number, array $context): void {
        $round = fn(int $number): RoundPairing => new RoundPairing($number, null, [new Event([$this->alice, $this->bob], new Round($number))]);
        $pairings = array_map($round, $earlier);
        $last = $round($number);
        $state = StageState::start($this->participants);
        foreach ($pairings as $pairing) {
            $state = $verb === 'played' ? $state->withRoundPlayed($pairing, []) : $state->withRoundPaired($pairing);
        }

        try {
            $verb === 'played' ? $state->withRoundPlayed($last, []) : $state->withRoundPaired($last);
            $this->fail('The round was recorded.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::RoundOutOfSequence)
                ->and($exception->getContext())->toBe($context);
        }

        try {
            StageState::fromHistory($this->participants, [...$pairings, $last]);
            $this->fail('The history was accepted.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::RoundOutOfSequence)
                ->and(array_intersect_key($exception->getContext(), $context))->toBe($context);
        }
    })->with(['played', 'paired'])->with([
        'a first round numbered 2' => [[], 2, ['last_round' => 0, 'pairing_round' => 2, 'expected_round' => 1]],
        'round 3 after round 1' => [[1], 3, ['last_round' => 1, 'pairing_round' => 3, 'expected_round' => 2]],
        'round 5 after rounds 1 and 2' => [[1, 2], 5, ['last_round' => 2, 'pairing_round' => 5, 'expected_round' => 3]],
    ]);

    // Issue #197: nobody is in a round twice, wherever a state is built. A
    // round that holds one event twice was recorded, and an engine and the
    // bracket view then matched one result to both events, because a result
    // names its event by round, pair and leg. The verbs refuse a participant
    // twice in a round where fromHistory() refuses it, with its exception;
    // the entrant check stays fromHistory()'s own.
    it('refuses a round that holds a participant twice, as fromHistory() refuses it', function (string $verb, int $number, Closure $round, string $twice): void {
        $earlier = [];
        $state = StageState::start($this->participants);
        for ($played = 1; $played < $number; ++$played) {
            $earlier[] = $pairing = new RoundPairing($played, null, [new Event([$this->alice, $this->bob], new Round($played))]);
            $state = $state->withRoundPaired($pairing);
        }
        [$events, $byes] = $round($this, new Round($number));
        $pairing = new RoundPairing($number, null, $events, $byes);

        $refusals = [];
        foreach ([
            'verb' => static fn() => $verb === 'played' ? $state->withRoundPlayed($pairing, []) : $state->withRoundPaired($pairing),
            'fromHistory()' => fn() => StageState::fromHistory($this->participants, [...$earlier, $pairing]),
        ] as $path => $build) {
            try {
                $build();
                $refusals[$path] = null;
            } catch (InvalidConfigurationException $exception) {
                $refusals[$path] = [$exception->getReason(), $exception->getConfigurationIssue(), $exception->getContext()];
            }
        }

        $refusal = [
            InvalidConfigurationReason::ParticipantTwiceInRound,
            "Participant {$twice} is in round {$number} twice",
            ['round' => $number, 'participant' => $twice],
        ];
        expect($refusals)->toBe(['verb' => $refusal, 'fromHistory()' => $refusal]);
    })->with(['played', 'paired'])->with(['round 1' => 1, 'round 3' => 3])->with([
        'one event listed twice' => [
            fn($test, Round $round) => [[new Event([$test->alice, $test->bob], $round), new Event([$test->alice, $test->bob], $round)], []],
            'p1',
        ],
        'one event listed twice, the participants named either way round' => [
            fn($test, Round $round) => [[new Event([$test->alice, $test->bob], $round), new Event([$test->bob, $test->alice], $round)], []],
            'p2',
        ],
        // An event without a tie leg is leg 1 of its tie
        'an event without a leg and the same pair as leg 1' => [
            fn($test, Round $round) => [[new Event([$test->alice, $test->bob], $round), new Event([$test->alice, $test->bob], $round, ['tie_leg' => 1])], []],
            'p1',
        ],
        'one leg of a tie listed twice' => [
            fn($test, Round $round) => [[
                new Event([$test->alice, $test->bob], $round, ['tie_leg' => 2]),
                new Event([$test->bob, $test->alice], $round, ['tie_leg' => 2]),
            ], []],
            'p2',
        ],
        'a participant in two events against different opponents' => [
            fn($test, Round $round) => [[new Event([$test->alice, $test->bob], $round), new Event([$test->carol, $test->alice], $round)], []],
            'p1',
        ],
        'a participant in an event and among the byes' => [
            fn($test, Round $round) => [[new Event([$test->alice, $test->bob], $round)], [$test->carol, $test->bob]],
            'p2',
        ],
        'a participant with two byes' => [
            fn($test, Round $round) => [[new Event([$test->alice, $test->bob], $round)], [$test->carol, $test->carol]],
            'p3',
        ],
    ]);

    // The two legs of a tie are one appearance: the verbs record them in one
    // round, as fromHistory() does, and each leg keeps a result of its own
    it('records the two legs of a tie in one round, as fromHistory() does', function (string $verb): void {
        $round = new Round(1);
        $legs = [new Event([$this->alice, $this->bob], $round, ['tie_leg' => 1]), new Event([$this->bob, $this->alice], $round, ['tie_leg' => 2])];
        $pairing = new RoundPairing(1, 'final', [...$legs, new Event([$this->carol, $this->dave], $round)]);
        $results = [new Result($legs[1], $this->bob)];

        $state = $verb === 'played'
            ? StageState::start($this->participants)->withRoundPlayed($pairing, $results)
            : StageState::start($this->participants)->withRoundPaired($pairing)->withAdditionalResults($results);

        expect($state->toArray())->toBe(StageState::fromHistory($this->participants, [$pairing], $results)->toArray())
            ->and($state->getEventsWithoutResult())->toBe([$legs[0], $pairing->getEvents()[2]]);
    })->with(['played', 'paired']);

    it('records the rounds 1, 2, 3 and so on, one after the other', function (): void {
        $state = StageState::start($this->participants);
        foreach ([1, 2, 3] as $number) {
            expect($state->getNextRoundNumber())->toBe($number);
            $state = $state->withRoundPaired(new RoundPairing($number, null, [new Event([$this->alice, $this->bob], new Round($number))]));
        }

        expect($state->getNextRoundNumber())->toBe(4)
            ->and(StageState::fromHistory($this->participants, $state->getRoundsPlayed())->toArray())->toBe($state->toArray());
    });

    it('rejects results from a different round than the pairing', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(2));
        $pairing = new RoundPairing(1, null, [new Event([$this->alice, $this->bob], new Round(1))]);

        StageState::start($this->participants)
            ->withRoundPlayed($pairing, [new Result($event, $this->alice)]);
    })->throws(InvalidConfigurationException::class);

    it('rejects rounds recorded out of play order', function (): void {
        $state = StageState::start($this->participants)
            ->withRoundPlayed(new RoundPairing(2, null, [
                new Event([$this->alice, $this->bob], new Round(2)),
            ]), []);

        $state->withRoundPlayed(new RoundPairing(2, null, [
            new Event([$this->carol, $this->dave], new Round(2)),
        ]), []);
    })->throws(InvalidConfigurationException::class, 'play order');

    it('rejects pairings whose events carry a different round number', function (): void {
        StageState::start($this->participants)->withRoundPlayed(
            new RoundPairing(1, null, [new Event([$this->alice, $this->bob], new Round(3))]),
            []
        );
    })->throws(InvalidConfigurationException::class, 'different round');

    // Accepting results for unrelated events (even in the right round)
    // would silently corrupt engine replay
    it('rejects results for events outside the recorded pairing', function (): void {
        $pairingEvent = new Event([$this->alice, $this->bob], new Round(1));
        $foreignEvent = new Event([$this->carol, $this->dave], new Round(1));
        $pairing = new RoundPairing(1, null, [$pairingEvent]);

        expect(fn() => StageState::start($this->participants)
            ->withRoundPlayed($pairing, [new Result($foreignEvent, $this->carol)]))
            ->toThrow(InvalidConfigurationException::class, 'not part of the pairing');

        $state = StageState::start($this->participants)->withRoundPlayed($pairing, []);
        expect(fn() => $state->withAdditionalResults([new Result($foreignEvent, $this->carol)]))
            ->toThrow(InvalidConfigurationException::class, 'not part of the pairing');
    });

    it('completes a round with additional results and rejects them without a round', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(1));
        $state = StageState::start($this->participants)
            ->withRoundPlayed(new RoundPairing(1, null, [$event]), [])
            ->withAdditionalResults([new Result($event, $this->alice)]);

        expect($state->getResults())->toHaveCount(1);

        expect(fn() => StageState::start($this->participants)->withAdditionalResults([]))
            ->toThrow(InvalidConfigurationException::class, 'No round');
    });

    it('accumulates byes across rounds', function (): void {
        $state = StageState::start($this->participants)
            ->withRoundPlayed(new RoundPairing(1, null, [
                new Event([$this->alice, $this->bob], new Round(1)),
            ], [$this->carol]), [])
            ->withRoundPlayed(new RoundPairing(2, null, [
                new Event([$this->alice, $this->carol], new Round(2)),
            ], [$this->bob]), [])
            ->withRoundPlayed(new RoundPairing(3, null, [
                new Event([$this->bob, $this->alice], new Round(3)),
            ], [$this->carol]), []);

        expect($state->getByeIds())->toBe(['p3', 'p2', 'p3']);
        expect($state->getByeCounts())->toBe(['p3' => 2, 'p2' => 1]);
    });

    it('withdraws a participant while keeping their history', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(1));
        $result = new Result($event, $this->alice);
        $state = StageState::start($this->participants)
            ->withRoundPlayed(new RoundPairing(1, null, [$event]), [$result])
            ->withoutParticipant($this->bob);

        expect(array_map(fn(Participant $p) => $p->getId(), $state->getParticipants()))
            ->toBe(['p1', 'p3', 'p4']);
        expect($state->getResults())->toBe([$result]);
        expect($state->getPlayedEvents())->toBe([$event]);
    });

    it('round-trips through its array representation', function (): void {
        $event1 = new Event([$this->alice, $this->bob], new Round(1));
        $event2 = new Event([$this->carol, $this->dave], new Round(1));
        $state = StageState::start($this->participants)
            ->withRoundPlayed(
                new RoundPairing(1, 'opening round', [$event1, $event2]),
                [new Result($event1, $this->alice), new Result($event2, null, ['p3' => 1, 'p4' => 1])]
            )
            ->withoutParticipant($this->dave);

        $rebuilt = StageState::fromArray($state->toArray());

        expect(array_map(fn(Participant $p) => $p->getId(), $rebuilt->getParticipants()))
            ->toBe(['p1', 'p2', 'p3']);
        expect($rebuilt->getNextRoundNumber())->toBe(2);
        expect($rebuilt->getLastRound()?->getLabel())->toBe('opening round');
        expect($rebuilt->getPlayedEvents())->toHaveCount(2);

        $results = $rebuilt->getResults();
        expect($results)->toHaveCount(2);
        expect($results[0]->getWinner()?->getId())->toBe('p1');
        expect($results[1]->isDraw())->toBeTrue();
        expect($results[1]->getScoreFor($this->carol))->toBe(1);

        // Withdrawn Dave is rehydrated inside the recorded round, seed intact
        $rebuiltDave = $rebuilt->getPlayedEvents()[1]->getParticipants()[1];
        expect($rebuiltDave->getId())->toBe('p4');
        expect($rebuiltDave->getSeed())->toBe(4);

        // The rebuilt state serializes identically
        expect($rebuilt->toArray())->toBe($state->toArray());
    });

    it('round-trips through JSON with byes intact', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(1));
        $state = StageState::start([$this->alice, $this->bob, $this->carol])
            ->withRoundPlayed(
                new RoundPairing(1, null, [$event], [$this->carol]),
                [new Result($event, $this->alice)]
            );

        $rebuilt = StageState::fromJson($state->toJson());

        expect($rebuilt->getByeIds())->toBe(['p3']);
        expect($rebuilt->getByeCounts())->toBe(['p3' => 1]);
        expect($rebuilt->getLastRound()?->getRoundNumber())->toBe(1);
        expect($rebuilt->toJson())->toBe($state->toJson());
    });

    it('rejects malformed serialized data', function (): void {
        expect(fn() => StageState::fromArray(['participants' => 'nope']))
            ->toThrow(InvalidArgumentException::class);
        expect(fn() => StageState::fromArray(['participants' => ['nope']]))
            ->toThrow(InvalidArgumentException::class, 'must be an array');
    });

    it('rejects malformed rounds and results collections', function (): void {
        $base = [
            'participants' => [['id' => 'p1', 'label' => 'Alice', 'seed' => null, 'metadata' => []]],
            'active' => ['p1'],
        ];

        expect(fn() => StageState::fromArray([...$base, 'rounds' => 'nope', 'results' => []]))
            ->toThrow(InvalidArgumentException::class, 'rounds');
        expect(fn() => StageState::fromArray([...$base, 'rounds' => ['nope'], 'results' => []]))
            ->toThrow(InvalidArgumentException::class, 'round');
        expect(fn() => StageState::fromArray([...$base, 'rounds' => [], 'results' => 'nope']))
            ->toThrow(InvalidArgumentException::class, 'results');
        expect(fn() => StageState::fromArray([...$base, 'rounds' => [], 'results' => ['nope']]))
            ->toThrow(InvalidArgumentException::class, 'result');
        expect(fn() => StageState::fromArray([...$base, 'active' => 'nope', 'rounds' => [], 'results' => []]))
            ->toThrow(InvalidArgumentException::class, 'active');
    });

    it('rejects JSON that does not decode to an array', function (): void {
        StageState::fromJson('"just a string"');
    })->throws(InvalidArgumentException::class);

    // start() enforces unique IDs; rehydration must uphold the same
    // invariant instead of silently letting later entries win
    it('rejects duplicate participant IDs in serialized data', function (): void {
        StageState::fromArray([
            'participants' => [
                ['id' => 'p1', 'label' => 'Alice', 'seed' => null, 'metadata' => []],
                ['id' => 'p1', 'label' => 'Alice Clone', 'seed' => null, 'metadata' => []],
            ],
            'active' => ['p1'],
            'rounds' => [],
            'results' => [],
        ]);
    })->throws(InvalidArgumentException::class, 'twice');

    it('rejects an active reference to an unknown participant', function (): void {
        StageState::fromArray([
            'participants' => [['id' => 'p1', 'label' => 'Alice', 'seed' => null, 'metadata' => []]],
            'active' => ['p1', 'ghost'],
            'rounds' => [],
            'results' => [],
        ]);
    })->throws(InvalidArgumentException::class);

    // start() refuses two participants with one ID. Stored data that lists
    // one ID twice as active made the same state, and an engine would pair
    // the participant against itself.
    it('rejects an ID listed twice as active in serialized data', function (): void {
        $data = [
            'participants' => [
                ['id' => 'p1', 'label' => 'Alice', 'seed' => null, 'metadata' => []],
                ['id' => 'p2', 'label' => 'Bob', 'seed' => null, 'metadata' => []],
            ],
            'active' => ['p1', 'p2', 'p1'],
            'rounds' => [],
            'results' => [],
        ];

        expect(fn() => StageState::fromArray($data))
            ->toThrow(InvalidInputException::class, "Stage state lists active participant 'p1' twice")
            ->and(fn() => StageState::fromJson((string) json_encode($data)))
            ->toThrow(InvalidInputException::class, "Stage state lists active participant 'p1' twice");
    });

    it('reads back the active list a state wrote, withdrawals included', function (): void {
        $state = StageState::start($this->participants)->withoutParticipant($this->bob);

        expect(array_map(
            fn(Participant $participant): string => $participant->getId(),
            StageState::fromArray($state->toArray())->getParticipants()
        ))->toBe(['p1', 'p3', 'p4']);
    });

    // Round numbers are 1-based. Every later round has to exceed the one
    // before it, so the first was the one that could be 0 or negative.
    it('rejects a first round numbered below 1', function (int $number): void {
        try {
            StageState::start($this->participants)->withRoundPlayed(new RoundPairing($number, null, []), []);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::ValueOutOfRange)
                ->and($exception->getConfigurationIssue())->toBe('Round numbers are 1-based: a round cannot be recorded with a number below 1')
                ->and($exception->getContext())->toBe(['pairing_round' => $number]);

            return;
        }

        $this->fail("A round numbered {$number} was recorded.");
    })->with([0, -1]);

    // Round 1 and no other: a first round numbered above it is out of
    // sequence (see 'refuses a round that is not the next one')
    it('records a first round numbered 1', function (): void {
        $state = StageState::start($this->participants)->withRoundPlayed(new RoundPairing(1, null, []), []);

        expect($state->getLastRound()?->getRoundNumber())->toBe(1);
    });
});
