<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;

// A state does not say which engine paired its rounds, so nothing stops a
// platform from restoring a Swiss state and handing it to a bracket engine,
// or to the right engine built from other options. The fingerprint is an
// optional stamp: a state that carries one is refused by every other engine.

describe('StageState engine fingerprint', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice', 1);
        $this->bob = new Participant('p2', 'Bob', 2);
        $this->carol = new Participant('p3', 'Carol', 3);
        $this->dave = new Participant('p4', 'Dave', 4);
        $this->participants = [$this->alice, $this->bob, $this->carol, $this->dave];
    });

    it('is absent until the state is stamped, and absent from the wire', function (): void {
        $state = StageState::start($this->participants);

        expect($state->getEngineFingerprint())->toBeNull()
            ->and(array_keys($state->toArray()))->toBe(['participants', 'active', 'rounds', 'results']);
    });

    it('stamps a new state and leaves the one it was called on', function (): void {
        $state = StageState::start($this->participants);
        $stamped = $state->withEngineFingerprint('swiss:planned-rounds=3');

        expect($stamped->getEngineFingerprint())->toBe('swiss:planned-rounds=3')
            ->and($state->getEngineFingerprint())->toBeNull()
            ->and($stamped->getParticipants())->toBe($this->participants);
    });

    it('removes the stamp when given null', function (): void {
        $stamped = StageState::start($this->participants)->withEngineFingerprint('anything');

        expect($stamped->withEngineFingerprint(null)->getEngineFingerprint())->toBeNull();
    });

    it('rejects an empty fingerprint', function (): void {
        StageState::start($this->participants)->withEngineFingerprint('');
    })->throws(InvalidConfigurationException::class, 'An engine fingerprint cannot be empty');

    it('writes the stamp as the last key and reads it back', function (): void {
        $stamped = StageState::start($this->participants)->withEngineFingerprint('swiss:planned-rounds=3');

        $data = $stamped->toArray();
        expect(array_keys($data))->toBe(['participants', 'active', 'rounds', 'results', 'engine_fingerprint'])
            ->and($data['engine_fingerprint'] ?? null)->toBe('swiss:planned-rounds=3');

        expect(StageState::fromArray($data)->getEngineFingerprint())->toBe('swiss:planned-rounds=3')
            ->and(StageState::fromJson($stamped->toJson())->toJson())->toBe($stamped->toJson());
    });

    it('loads a state stored before the stamp existed, and writes it back unchanged', function (): void {
        $stored = rtrim((string) file_get_contents(__DIR__ . '/../../Fixtures/golden/wire/stage-state.json'), "\n");
        expect($stored)->not->toContain('engine_fingerprint');

        $state = StageState::fromJson($stored);

        expect($state->getEngineFingerprint())->toBeNull()
            ->and($state->toJson())->toBe($stored);
    });

    it('reads a null stamp as no stamp', function (): void {
        $data = [...StageState::start($this->participants)->toArray(), 'engine_fingerprint' => null];

        expect(StageState::fromArray($data)->getEngineFingerprint())->toBeNull();
    });

    it('rejects a stamp that is not a non-empty string', function (mixed $fingerprint): void {
        $data = [...StageState::start($this->participants)->toArray(), 'engine_fingerprint' => $fingerprint];

        StageState::fromArray($data);
    })->with([
        'an empty string' => [''],
        'an integer' => [7],
        'a list' => [['swiss']],
    ])->throws(InvalidInputException::class, 'Stage state engine fingerprint must be a non-empty string');

    it('keeps a stamp of any text through storage', function (string $fingerprint): void {
        $stamped = StageState::start($this->participants)->withEngineFingerprint($fingerprint);

        expect(StageState::fromJson($stamped->toJson())->getEngineFingerprint())->toBe($fingerprint);
        $stamped->requireEngineFingerprint($fingerprint);
    })->with([
        'a space' => [' '],
        'quotes and a backslash' => ['my "engine" \\ v1'],
        'the text null' => ['null'],
        'a zero' => ['0'],
        'unicode' => ['ladder:étape=3 🏆'],
        'a line break' => ["two\nlines"],
    ]);

    it('writes no key again once the stamp is removed', function (): void {
        $unstamped = StageState::start($this->participants)->withEngineFingerprint('stamp')->withEngineFingerprint(null);

        expect($unstamped->toArray())->not->toHaveKey('engine_fingerprint')
            ->and($unstamped->toJson())->toBe(StageState::start($this->participants)->toJson());
    });

    it('compares the stamp exactly', function (string $recorded, string $required): void {
        StageState::start($this->participants)->withEngineFingerprint($recorded)->requireEngineFingerprint($required);
    })->with([
        'two numbers PHP calls equal' => ['1', '01'],
        'case' => ['Swiss', 'swiss'],
        'a trailing space' => ['swiss', 'swiss '],
        'an empty requirement' => ['swiss', ''],
    ])->throws(InvalidConfigurationException::class, 'The stage state was recorded by a different engine or configuration');

    it('keeps the stamp through every verb', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(1));
        $other = new Event([$this->carol, $this->dave], new Round(1));
        $pairing = new RoundPairing(1, null, [$event, $other]);

        $state = StageState::start($this->participants)->withEngineFingerprint('stamp');
        $played = $state->withRoundPlayed($pairing, [new Result($event, $this->alice)]);

        expect($played->getEngineFingerprint())->toBe('stamp')
            ->and($played->withAdditionalResults([new Result($other, $this->carol)])->getEngineFingerprint())->toBe('stamp')
            ->and($played->withResultReplaced(new Result($event, $this->bob))->getEngineFingerprint())->toBe('stamp')
            ->and($played->withoutParticipant($this->dave)->getEngineFingerprint())->toBe('stamp');
    });

    it('accepts any fingerprint while unstamped, and only its own once stamped', function (): void {
        $state = StageState::start($this->participants);
        $state->requireEngineFingerprint('one');
        $state->requireEngineFingerprint('another');

        $stamped = $state->withEngineFingerprint('one');
        $stamped->requireEngineFingerprint('one');

        try {
            $stamped->requireEngineFingerprint('another');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getMessage())->toContain(
                'The stage state was recorded by a different engine or configuration'
            );
            expect($exception->getContext())->toBe(['recorded' => 'one', 'engine' => 'another']);

            return;
        }

        throw new LogicException('A state stamped by one engine was accepted by another.');
    });
});

describe('engine fingerprints', function (): void {
    beforeEach(function (): void {
        $this->participants = [
            new Participant('p1', 'Alice', 1),
            new Participant('p2', 'Bob', 2),
            new Participant('p3', 'Carol', 3),
            new Participant('p4', 'Dave', 4),
        ];
    });

    // Written out: a stored state carries these strings, so a change to one
    // of them refuses every state stamped before it.
    it('names the format and the options that shape its rounds', function (
        SwissPairingEngine|SingleEliminationEngine|DoubleEliminationEngine $engine,
        string $fingerprint
    ): void {
        expect($engine->getFingerprint())->toBe($fingerprint);
    })->with([
        'open-ended Swiss' => [fn() => new SwissPairingEngine(), 'swiss:planned-rounds=none'],
        'Swiss of five rounds' => [fn() => new SwissPairingEngine(plannedRounds: 5), 'swiss:planned-rounds=5'],
        'single elimination' => [
            fn() => new SingleEliminationEngine(),
            'single-elimination:legs-per-tie=1,reseed-each-round=no',
        ],
        'two-legged, re-seeded single elimination' => [
            fn() => new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2, reseedEachRound: true)),
            'single-elimination:legs-per-tie=2,reseed-each-round=yes',
        ],
        'single elimination ignores the grand final option' => [
            fn() => new SingleEliminationEngine(new EliminationOptions(grandFinalReset: false)),
            'single-elimination:legs-per-tie=1,reseed-each-round=no',
        ],
        'double elimination' => [
            fn() => new DoubleEliminationEngine(),
            'double-elimination:legs-per-tie=1,grand-final-reset=yes',
        ],
        'double elimination without a reset' => [
            fn() => new DoubleEliminationEngine(new EliminationOptions(legsPerTie: 2, grandFinalReset: false)),
            'double-elimination:legs-per-tie=2,grand-final-reset=no',
        ],
    ]);

    it('drives a stage stamped with its own fingerprint to the end', function (
        SwissPairingEngine|SingleEliminationEngine|DoubleEliminationEngine $engine
    ): void {
        $state = StageState::start($this->participants)->withEngineFingerprint($engine->getFingerprint());

        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $results = array_map(
                fn(Event $event): Result => new Result($event, $event->getParticipants()[0]),
                $pairing->getEvents()
            );
            // Through storage after every round, as a platform would.
            $state = StageState::fromJson($state->withRoundPlayed($pairing, $results)->toJson());
        }

        expect($engine->getOutcome($state))->not->toBeNull()
            ->and($engine->getPlan($state)->getParticipants())->toHaveCount(4)
            ->and($state->getEngineFingerprint())->toBe($engine->getFingerprint());
    })->with([
        'Swiss' => [fn() => new SwissPairingEngine(plannedRounds: 3)],
        'single elimination' => [fn() => new SingleEliminationEngine()],
        'double elimination' => [fn() => new DoubleEliminationEngine()],
    ]);

    it('refuses a state stamped by another engine, in every method', function (
        SwissPairingEngine|SingleEliminationEngine|DoubleEliminationEngine $recorder,
        SwissPairingEngine|SingleEliminationEngine|DoubleEliminationEngine $reader
    ): void {
        $state = StageState::start($this->participants)->withEngineFingerprint($recorder->getFingerprint());

        foreach ([
            fn() => $reader->pairNextRound($state),
            fn() => $reader->isComplete($state),
            fn() => $reader->getOutcome($state),
            fn() => $reader->getPlan($state),
        ] as $call) {
            expect($call)->toThrow(
                InvalidConfigurationException::class,
                'The stage state was recorded by a different engine or configuration'
            );
        }

        // The same calls on the same state without the stamp go through.
        $unstamped = $state->withEngineFingerprint(null);
        expect($reader->isComplete($unstamped))->toBeFalse()
            ->and($reader->pairNextRound($unstamped)->getRoundNumber())->toBe(1);
    })->with([
        'Swiss read as single elimination' => [
            fn() => new SwissPairingEngine(plannedRounds: 3),
            fn() => new SingleEliminationEngine(),
        ],
        'single elimination read as double' => [
            fn() => new SingleEliminationEngine(),
            fn() => new DoubleEliminationEngine(),
        ],
        'double elimination read as Swiss' => [
            fn() => new DoubleEliminationEngine(),
            fn() => new SwissPairingEngine(plannedRounds: 3),
        ],
        'one leg read as two' => [
            fn() => new SingleEliminationEngine(),
            fn() => new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2)),
        ],
        'a fixed path read as re-seeded' => [
            fn() => new SingleEliminationEngine(),
            fn() => new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true)),
        ],
        'a reset grand final read as none' => [
            fn() => new DoubleEliminationEngine(),
            fn() => new DoubleEliminationEngine(new EliminationOptions(grandFinalReset: false)),
        ],
        'five Swiss rounds read as six' => [
            fn() => new SwissPairingEngine(plannedRounds: 5),
            fn() => new SwissPairingEngine(plannedRounds: 6),
        ],
    ]);

    it('accepts a state that is stamped again for a deliberate change of configuration', function (): void {
        $fiveRounds = new SwissPairingEngine(plannedRounds: 5);
        $sixRounds = new SwissPairingEngine(plannedRounds: 6);
        $state = StageState::start($this->participants)->withEngineFingerprint($fiveRounds->getFingerprint());

        $extended = $state->withEngineFingerprint($sixRounds->getFingerprint());

        expect($sixRounds->pairNextRound($extended)->getRoundNumber())->toBe(1);
    });

    it('refuses a state with recorded rounds that another engine paired', function (): void {
        // The case the stamp is for: a state that already holds rounds,
        // which an engine of another format would replay as its own.
        $swiss = new SwissPairingEngine(plannedRounds: 3);
        $state = StageState::start($this->participants)->withEngineFingerprint($swiss->getFingerprint());
        for ($round = 1; $round <= 2; ++$round) {
            $pairing = $swiss->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, array_map(
                fn(Event $event): Result => new Result($event, $event->getParticipants()[0]),
                $pairing->getEvents()
            ));
        }
        $restored = StageState::fromJson($state->toJson());

        foreach ([new SingleEliminationEngine(), new DoubleEliminationEngine(), new SwissPairingEngine()] as $reader) {
            expect(fn() => $reader->pairNextRound($restored))->toThrow(
                InvalidConfigurationException::class,
                'The stage state was recorded by a different engine or configuration'
            );
        }

        expect($swiss->pairNextRound($restored)->getRoundNumber())->toBe(3);
    });

    it('tells a planned Swiss stage from an open-ended one', function (): void {
        $state = StageState::start($this->participants)
            ->withEngineFingerprint((new SwissPairingEngine(plannedRounds: 1))->getFingerprint());

        expect(fn() => (new SwissPairingEngine())->isComplete($state))->toThrow(InvalidConfigurationException::class);
    });

    it('reports the stamp before anything else is wrong with the state', function (
        SwissPairingEngine|SingleEliminationEngine|DoubleEliminationEngine $reader
    ): void {
        // One participant is too few for every engine; the stamp is checked first.
        $state = StageState::start([$this->participants[0]])->withEngineFingerprint('an engine of my own');

        foreach ([
            fn() => $reader->pairNextRound($state),
            fn() => $reader->isComplete($state),
            fn() => $reader->getOutcome($state),
            fn() => $reader->getPlan($state),
        ] as $call) {
            expect($call)->toThrow(
                InvalidConfigurationException::class,
                'The stage state was recorded by a different engine or configuration'
            );
        }
    })->with([
        'Swiss' => [fn() => new SwissPairingEngine()],
        'single elimination' => [fn() => new SingleEliminationEngine()],
        'double elimination' => [fn() => new DoubleEliminationEngine()],
    ]);

    it('gives two engines of one configuration one fingerprint', function (Closure $build): void {
        expect($build()->getFingerprint())->toBe($build()->getFingerprint());
    })->with([
        'Swiss' => [fn() => new SwissPairingEngine(plannedRounds: 4)],
        'single elimination' => [fn() => new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2))],
        'double elimination' => [fn() => new DoubleEliminationEngine(new EliminationOptions(grandFinalReset: false))],
    ]);

    it('gives every configuration its own fingerprint', function (): void {
        $fingerprints = [
            (new SwissPairingEngine())->getFingerprint(),
            (new SwissPairingEngine(plannedRounds: 1))->getFingerprint(),
            (new SwissPairingEngine(plannedRounds: 11))->getFingerprint(),
        ];
        foreach ([1, 2] as $legs) {
            foreach ([true, false] as $flag) {
                $fingerprints[] = (new SingleEliminationEngine(new EliminationOptions(legsPerTie: $legs, reseedEachRound: $flag)))
                    ->getFingerprint();
                $fingerprints[] = (new DoubleEliminationEngine(new EliminationOptions(legsPerTie: $legs, grandFinalReset: $flag)))
                    ->getFingerprint();
            }
        }

        expect(array_unique($fingerprints))->toHaveCount(11);
    });

    it('leaves the standings calculator, constraints and randomizer out of the fingerprint', function (): void {
        $plain = new SwissPairingEngine(plannedRounds: 3);
        $seeded = new SwissPairingEngine(plannedRounds: 3, randomizer: new Random\Randomizer(new Random\Engine\Mt19937(1)));

        expect($seeded->getFingerprint())->toBe($plain->getFingerprint());
    });
});
