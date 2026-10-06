<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
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
use MissionGaming\Tactician\Stage\EngineFingerprint;
use MissionGaming\Tactician\Stage\FingerprintedEngine;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StagePlan;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\RankingStrategy;
use MissionGaming\Tactician\Standings\SonnebornBergerTiebreaker;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\TiebreakerInterface;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use MissionGaming\Tactician\Tests\Support\DecimalCommaLocale;

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
        $stamped = $state->withEngineFingerprint('ladder:rungs=3');

        expect($stamped->getEngineFingerprint())->toBe('ladder:rungs=3')
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
        $stamped = StageState::start($this->participants)->withEngineFingerprint('ladder:rungs=3');

        $data = $stamped->toArray();
        expect(array_keys($data))->toBe(['participants', 'active', 'rounds', 'results', 'engine_fingerprint'])
            ->and($data['engine_fingerprint'] ?? null)->toBe('ladder:rungs=3');

        expect(StageState::fromArray($data)->getEngineFingerprint())->toBe('ladder:rungs=3')
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
            expect($exception->getContext())->toBe([
                'recorded' => 'one',
                'engine' => 'another',
                'differences' => ["the stamp is not this engine's"],
            ]);

            return;
        }

        throw new LogicException('A state stamped by one engine was accepted by another.');
    });
});

/**
 * A ranking strategy of the application's: the library cannot describe it.
 */
function fingerprintCustomRanking(): RankingStrategy
{
    return new class implements RankingStrategy {
        #[Override]
        public function rank(Participant $participant, array $results): float
        {
            return (float) count($results);
        }
    };
}

/**
 * A tiebreaker of the application's, with the name it gives itself.
 */
function fingerprintTiebreakerNamed(string $name): TiebreakerInterface
{
    return new readonly class ($name) implements TiebreakerInterface {
        public function __construct(private string $name) {}

        #[Override]
        public function getName(): string
        {
            return $this->name;
        }

        #[Override]
        public function calculate(Participant $participant, array $results, array $entries): float
        {
            return 0.0;
        }
    };
}

/**
 * A Swiss engine that pairs by the chess scale.
 */
function fingerprintSwissOnTheChessScale(int $plannedRounds): SwissPairingEngine
{
    return new SwissPairingEngine(
        standingsCalculator: new StandingsCalculator(WinDrawLossRanking::oneHalfZero()),
        plannedRounds: $plannedRounds
    );
}

/**
 * Every configuration the tests below go through, by name.
 *
 * @return array<string, array{Closure(): (FingerprintedEngine&StageEngineInterface)}>
 */
function fingerprintedEngines(): array
{
    return [
        'Swiss' => [fn() => new SwissPairingEngine(plannedRounds: 3)],
        'Swiss on the chess scale with tiebreakers' => [fn() => new SwissPairingEngine(
            standingsCalculator: new StandingsCalculator(WinDrawLossRanking::oneHalfZero(), [new BuchholzTiebreaker()]),
            plannedRounds: 3
        )],
        'single elimination' => [fn() => new SingleEliminationEngine()],
        'two-legged, re-seeded single elimination' => [
            fn() => new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2, reseedEachRound: true)),
        ],
        'double elimination' => [fn() => new DoubleEliminationEngine()],
        'double elimination without a reset' => [
            fn() => new DoubleEliminationEngine(new EliminationOptions(legsPerTie: 2, grandFinalReset: false)),
        ],
    ];
}

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
    // of them refuses every state stamped before it. They are pinned here
    // and nowhere promised: callers compare fingerprints, they do not read
    // them.
    it('gives each configuration the string stored states carry', function (
        FingerprintedEngine $engine,
        string $fingerprint
    ): void {
        expect($engine->getFingerprint())->toBe($fingerprint);
    })->with([
        'Swiss' => [fn() => new SwissPairingEngine(), 'tactician:v1:swiss'],
        'Swiss on the 3/1/0 scale, stated' => [
            fn() => new SwissPairingEngine(standingsCalculator: new StandingsCalculator(WinDrawLossRanking::threeOneZero())),
            'tactician:v1:swiss',
        ],
        'Swiss on the chess scale' => [
            fn() => fingerprintSwissOnTheChessScale(3),
            'tactician:v1:swiss;ranking=win-draw-loss,1,0.5,0',
        ],
        'Swiss on a scale of fractions and a negative loss' => [
            fn() => new SwissPairingEngine(standingsCalculator: new StandingsCalculator(new WinDrawLossRanking(0.3, 0.1, -1.5))),
            'tactician:v1:swiss;ranking=win-draw-loss,0.3,0.1,-1.5',
        ],
        'Swiss with a ranking strategy of the application' => [
            fn() => new SwissPairingEngine(standingsCalculator: new StandingsCalculator(fingerprintCustomRanking())),
            'tactician:v1:swiss;ranking=custom',
        ],
        'Swiss with tiebreakers' => [
            fn() => new SwissPairingEngine(standingsCalculator: new StandingsCalculator(
                tiebreakers: [new BuchholzTiebreaker(), new SonnebornBergerTiebreaker()]
            )),
            'tactician:v1:swiss;tiebreakers=buchholz,sonneborn-berger',
        ],
        'Swiss with a tiebreaker whose name holds separators' => [
            fn() => new SwissPairingEngine(standingsCalculator: new StandingsCalculator(
                tiebreakers: [fingerprintTiebreakerNamed('head to head;x=1,y')]
            )),
            'tactician:v1:swiss;tiebreakers=head%20to%20head%3Bx%3D1%2Cy',
        ],
        'Swiss with a calculator of the application' => [
            fn() => new SwissPairingEngine(standingsCalculator: new readonly class extends StandingsCalculator {}),
            'tactician:v1:swiss;standings=custom',
        ],
        'a subclass of the Swiss engine' => [
            fn() => new readonly class extends SwissPairingEngine {},
            'tactician:v1:swiss;subclass=yes',
        ],
        'single elimination' => [fn() => new SingleEliminationEngine(), 'tactician:v1:single-elimination'],
        'two-legged, re-seeded single elimination' => [
            fn() => new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2, reseedEachRound: true)),
            'tactician:v1:single-elimination;legs-per-tie=2;reseed-each-round=yes',
        ],
        're-seeded single elimination on the chess scale' => [
            fn() => new SingleEliminationEngine(
                new EliminationOptions(reseedEachRound: true),
                new StandingsCalculator(WinDrawLossRanking::oneHalfZero(), [new BuchholzTiebreaker()])
            ),
            'tactician:v1:single-elimination;ranking=win-draw-loss,1,0.5,0;reseed-each-round=yes;tiebreakers=buchholz',
        ],
        'double elimination' => [fn() => new DoubleEliminationEngine(), 'tactician:v1:double-elimination'],
        'double elimination without a reset' => [
        'Swiss with a calculator of the application, built with rules' => [
            fn() => new SwissPairingEngine(standingsCalculator: new readonly class (WinDrawLossRanking::oneHalfZero(), [new BuchholzTiebreaker()]) extends StandingsCalculator {}),
            'tactician:v1:swiss;standings=custom',
        ],
            fn() => new DoubleEliminationEngine(new EliminationOptions(legsPerTie: 2, grandFinalReset: false)),
            'tactician:v1:double-elimination;grand-final-reset=no;legs-per-tie=2',
        ],
    ]);

    it('names the format only when every option is at its default', function (
        FingerprintedEngine $engine,
        string $format
    ): void {
        expect($engine->getFingerprint())->toBe('tactician:v1:' . $format);
    })->with([
        'Swiss' => [fn() => new SwissPairingEngine(), 'swiss'],
        'single elimination' => [fn() => new SingleEliminationEngine(), 'single-elimination'],
        'double elimination' => [fn() => new DoubleEliminationEngine(), 'double-elimination'],
        'single elimination, every default stated' => [
            fn() => new SingleEliminationEngine(
                new EliminationOptions(legsPerTie: 1, reseedEachRound: false, grandFinalReset: true),
                new StandingsCalculator(new WinDrawLossRanking(3.0, 1.0, 0.0), [])
            ),
            'single-elimination',
        ],
        'double elimination, built from plain data without a key' => [
            fn() => new DoubleEliminationEngine(EliminationOptions::fromArray([])),
            'double-elimination',
        ],
    ]);

    // The rule that keeps stored stamps valid. An engine states its options
    // through EngineFingerprint::with(), which is also how a later option
    // will be stated: the option is added here to the options each engine
    // really has, read back from its fingerprint.
    it('keeps its fingerprint when it gains an option that is left at its default', function (
        FingerprintedEngine&StageEngineInterface $engine
    ): void {
        $before = $engine->getFingerprint();
        $options = EngineFingerprint::parse($before);
        assert($options !== null);

        expect($options->toString())->toBe($before);

        foreach ([[false, false], [true, true], [0, 0], ['none', 'none'], [[], []], [1.0, 1.0]] as [$value, $default]) {
            expect($options->with('a-later-option', $value, $default)->toString())->toBe($before);
        }

        // A state stamped before the option existed is paired by the engine
        // that has it.
        $state = StageState::start($this->participants)->withEngineFingerprint($before);
        $state->requireEngineFingerprint($options->with('a-later-option', false, false)->toString());
        expect($engine->pairNextRound($state)->getRoundNumber())->toBe(1);
    })->with(fingerprintedEngines());

    it('changes its fingerprint when the option it gained is used', function (
        FingerprintedEngine&StageEngineInterface $engine
    ): void {
        $before = $engine->getFingerprint();
        $options = EngineFingerprint::parse($before);
        assert($options !== null);

        $after = $options->with('a-later-option', true, false)->toString();
        $state = StageState::start($this->participants)->withEngineFingerprint($after);

        expect($after)->not->toBe($before)
            ->and(fn() => $engine->pairNextRound($state))->toThrow(
                InvalidConfigurationException::class,
                'a-later-option: recorded yes, this engine the default'
            );
    })->with(fingerprintedEngines());

    it('builds its fingerprint through the one builder', function (string $file): void {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Scheduling/' . $file);

        expect(substr_count($source, 'EngineFingerprint::of('))->toBe(1)
            ->and($source)->not->toContain('tactician:v');
    })->with(['SwissPairingEngine.php', 'SingleEliminationEngine.php', 'DoubleEliminationEngine.php']);

    it('can be stamped by code that holds the engine interface', function (StageEngineInterface $engine): void {
        expect($engine)->toBeInstanceOf(FingerprintedEngine::class);
        assert($engine instanceof FingerprintedEngine);

        $state = StageState::start($this->participants)->withEngineFingerprint($engine->getFingerprint());

        expect($engine->pairNextRound($state)->getRoundNumber())->toBe(1);
    })->with(fingerprintedEngines());

    it('drives a stage stamped with its own fingerprint to the end', function (
        FingerprintedEngine&StageEngineInterface $engine
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
            ->and($engine->getPlan($state))->toBeInstanceOf(StagePlan::class)
            ->and($state->getEngineFingerprint())->toBe($engine->getFingerprint());
    })->with([
        'Swiss' => [fn() => new SwissPairingEngine(plannedRounds: 3)],
        'Swiss on the chess scale' => [fn() => fingerprintSwissOnTheChessScale(3)],
        'single elimination' => [fn() => new SingleEliminationEngine()],
        'double elimination' => [fn() => new DoubleEliminationEngine()],
    ]);

    it('refuses a state stamped by another engine, in every method', function (
        FingerprintedEngine&StageEngineInterface $recorder,
        FingerprintedEngine&StageEngineInterface $reader,
        string $difference
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
                "The stage state was recorded by a different engine or configuration ({$difference});"
                . ' stamp it again with withEngineFingerprint() if the change is deliberate'
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
            'format: recorded swiss, this engine single-elimination',
        ],
        'single elimination read as double' => [
            fn() => new SingleEliminationEngine(),
            fn() => new DoubleEliminationEngine(),
            'format: recorded single-elimination, this engine double-elimination',
        ],
        'double elimination read as Swiss' => [
            fn() => new DoubleEliminationEngine(),
            fn() => new SwissPairingEngine(plannedRounds: 3),
            'format: recorded double-elimination, this engine swiss',
        ],
        'one leg read as two' => [
            fn() => new SingleEliminationEngine(),
            fn() => new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2)),
            'legs-per-tie: recorded the default, this engine 2',
        ],
        'two legs read as one' => [
            fn() => new DoubleEliminationEngine(new EliminationOptions(legsPerTie: 2)),
            fn() => new DoubleEliminationEngine(),
            'legs-per-tie: recorded 2, this engine the default',
        ],
        'a fixed path read as re-seeded' => [
            fn() => new SingleEliminationEngine(),
            fn() => new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true)),
            'reseed-each-round: recorded the default, this engine yes',
        ],
        'a reset grand final read as none' => [
            fn() => new DoubleEliminationEngine(),
            fn() => new DoubleEliminationEngine(new EliminationOptions(grandFinalReset: false)),
            'grand-final-reset: recorded the default, this engine no',
        ],
        // Neither side at its defaults: the state is still refused.
        'two legs and no reset read as two legs with one' => [
            fn() => new DoubleEliminationEngine(new EliminationOptions(legsPerTie: 2, grandFinalReset: false)),
            fn() => new DoubleEliminationEngine(new EliminationOptions(legsPerTie: 2)),
            'grand-final-reset: recorded no, this engine the default',
        ],
        'two re-seeded legs read as one leg on a fixed path, with two options apart' => [
            fn() => new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2, reseedEachRound: true)),
            fn() => new SingleEliminationEngine(),
            'legs-per-tie: recorded 2, this engine the default; reseed-each-round: recorded yes, this engine the default',
        ],
        'the 3/1/0 scale read as the chess scale' => [
            fn() => new SwissPairingEngine(plannedRounds: 3),
            fn() => fingerprintSwissOnTheChessScale(3),
            'ranking: recorded the default, this engine win-draw-loss,1,0.5,0',
        ],
        'the chess scale read as another scale' => [
            fn() => fingerprintSwissOnTheChessScale(3),
            fn() => new SwissPairingEngine(standingsCalculator: new StandingsCalculator(new WinDrawLossRanking(2.0, 1.0, 0.0))),
            'ranking: recorded win-draw-loss,1,0.5,0, this engine win-draw-loss,2,1,0',
        ],
        'the 3/1/0 scale read by a ranking strategy of the application' => [
            fn() => new SwissPairingEngine(),
            fn() => new SwissPairingEngine(standingsCalculator: new StandingsCalculator(fingerprintCustomRanking())),
            'ranking: recorded the default, this engine custom',
        ],
        'no tiebreaker read as Buchholz' => [
            fn() => new SwissPairingEngine(),
            fn() => new SwissPairingEngine(standingsCalculator: new StandingsCalculator(tiebreakers: [new BuchholzTiebreaker()])),
            'tiebreakers: recorded the default, this engine buchholz',
        ],
        'two tiebreakers read in the other order' => [
            fn() => new SwissPairingEngine(standingsCalculator: new StandingsCalculator(
                tiebreakers: [new BuchholzTiebreaker(), new SonnebornBergerTiebreaker()]
            )),
            fn() => new SwissPairingEngine(standingsCalculator: new StandingsCalculator(
                tiebreakers: [new SonnebornBergerTiebreaker(), new BuchholzTiebreaker()]
            )),
            'tiebreakers: recorded buchholz,sonneborn-berger, this engine sonneborn-berger,buchholz',
        ],
        'the library calculator read by one of the application' => [
            fn() => new SwissPairingEngine(),
            fn() => new SwissPairingEngine(standingsCalculator: new readonly class extends StandingsCalculator {}),
            'standings: recorded the default, this engine custom',
        ],
        'a re-seeded bracket read on another scale' => [
            fn() => new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true)),
            fn() => new SingleEliminationEngine(
                new EliminationOptions(reseedEachRound: true),
                new StandingsCalculator(WinDrawLossRanking::oneHalfZero())
            ),
            'ranking: recorded the default, this engine win-draw-loss,1,0.5,0',
        ],
        'the Swiss engine read by a subclass' => [
            fn() => new SwissPairingEngine(),
            fn() => new readonly class extends SwissPairingEngine {},
            'subclass: recorded the default, this engine yes',
        ],
        'a subclass read by the Swiss engine' => [
            fn() => new readonly class extends SwissPairingEngine {},
            fn() => new SwissPairingEngine(),
            'subclass: recorded yes, this engine the default',
        ],
    ]);

    // What does not shape a round is not part of the fingerprint, so a
    // state stamped under one value is read under another.
    it('accepts a state stamped under a setting that shapes no round', function (
        FingerprintedEngine&StageEngineInterface $recorder,
        FingerprintedEngine&StageEngineInterface $reader
    ): void {
        $state = StageState::start($this->participants)->withEngineFingerprint($recorder->getFingerprint());

        expect($reader->getFingerprint())->toBe($recorder->getFingerprint())
            ->and($reader->pairNextRound($state)->getRoundNumber())->toBe(1);
    })->with([
        'five Swiss rounds extended to six' => [
            fn() => new SwissPairingEngine(plannedRounds: 5),
            fn() => new SwissPairingEngine(plannedRounds: 6),
        ],
        'a planned Swiss stage left open-ended' => [
            fn() => new SwissPairingEngine(plannedRounds: 5),
            fn() => new SwissPairingEngine(),
        ],
        'a Swiss engine given constraints and a randomizer' => [
            fn() => new SwissPairingEngine(plannedRounds: 3),
            fn() => new SwissPairingEngine(
                ConstraintSet::create()->noRepeatPairings()->build(),
                plannedRounds: 3,
                randomizer: new Random\Randomizer(new Random\Engine\Mt19937(1))
            ),
        ],
        'a bracket on a fixed path with another calculator' => [
            fn() => new SingleEliminationEngine(),
            fn() => new SingleEliminationEngine(
                new EliminationOptions(),
                new StandingsCalculator(WinDrawLossRanking::oneHalfZero(), [new BuchholzTiebreaker()])
            ),
        ],
        'a double elimination bracket with another calculator' => [
            fn() => new DoubleEliminationEngine(),
            fn() => new DoubleEliminationEngine(
                new EliminationOptions(),
                new StandingsCalculator(fingerprintCustomRanking())
            ),
        ],
        'single elimination with the grand final option it does not read' => [
            fn() => new SingleEliminationEngine(),
            fn() => new SingleEliminationEngine(new EliminationOptions(grandFinalReset: false)),
        ],
    ]);

    it('keeps the stamp of a Swiss stage that is extended after rounds were played', function (): void {
        $fiveRounds = new SwissPairingEngine(plannedRounds: 2);
        $state = StageState::start($this->participants)->withEngineFingerprint($fiveRounds->getFingerprint());
        while (!$fiveRounds->isComplete($state)) {
            $pairing = $fiveRounds->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, array_map(
                fn(Event $event): Result => new Result($event, $event->getParticipants()[0]),
                $pairing->getEvents()
            ));
        }

        $extended = new SwissPairingEngine(plannedRounds: 3);
        $restored = StageState::fromJson($state->toJson());

        expect($extended->isComplete($restored))->toBeFalse()
            ->and($extended->pairNextRound($restored)->getRoundNumber())->toBe(3);
    });

    it('accepts a state that is stamped again for a deliberate change of configuration', function (): void {
        $oneLeg = new SingleEliminationEngine();
        $twoLegs = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));
        $state = StageState::start($this->participants)->withEngineFingerprint($oneLeg->getFingerprint());

        $restamped = $state->withEngineFingerprint($twoLegs->getFingerprint());

        expect($twoLegs->pairNextRound($restamped)->getEvents())->toHaveCount(4);
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

        foreach ([new SingleEliminationEngine(), new DoubleEliminationEngine(), fingerprintSwissOnTheChessScale(3)] as $reader) {
            expect(fn() => $reader->pairNextRound($restored))->toThrow(
                InvalidConfigurationException::class,
                'The stage state was recorded by a different engine or configuration'
            );
        }

        expect($swiss->pairNextRound($restored)->getRoundNumber())->toBe(3);
    });

    it('reports the stamp before anything else is wrong with the state', function (
        FingerprintedEngine&StageEngineInterface $reader
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
                "The stage state was recorded by a different engine or configuration (the stamp was not written by one of the library's engines)"
            );
        }
    })->with([
        'Swiss' => [fn() => new SwissPairingEngine()],
        'single elimination' => [fn() => new SingleEliminationEngine()],
        'double elimination' => [fn() => new DoubleEliminationEngine()],
    ]);

    it('states the two fingerprints and where they differ in the context of the error', function (): void {
        $recorder = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));
        $reader = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));
        $state = StageState::start($this->participants)->withEngineFingerprint($recorder->getFingerprint());

        try {
            $reader->isComplete($state);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getContext())->toBe([
                'recorded' => $recorder->getFingerprint(),
                'engine' => $reader->getFingerprint(),
                'differences' => [
                    'legs-per-tie: recorded 2, this engine the default',
                    'reseed-each-round: recorded the default, this engine yes',
                ],
            ])->and($exception->getDiagnosticReport())->toContain(
                '• differences: ["legs-per-tie: recorded 2, this engine the default",'
                . ' "reseed-each-round: recorded the default, this engine yes"]'
            );

            return;
        }

        throw new LogicException('A two-legged bracket was read as a re-seeded one.');
    });

    it('gives two engines of one configuration one fingerprint', function (Closure $build): void {
        expect($build()->getFingerprint())->toBe($build()->getFingerprint());
    })->with(fingerprintedEngines());

    it('gives every configuration that pairs differently its own fingerprint', function (): void {
        $fingerprints = [];
        foreach ([1, 2] as $legs) {
            foreach ([true, false] as $flag) {
                $fingerprints[] = (new SingleEliminationEngine(new EliminationOptions(legsPerTie: $legs, reseedEachRound: $flag)))
                    ->getFingerprint();
                $fingerprints[] = (new DoubleEliminationEngine(new EliminationOptions(legsPerTie: $legs, grandFinalReset: $flag)))
                    ->getFingerprint();
            }
        }
        foreach ([
            new StandingsCalculator(),
            new StandingsCalculator(WinDrawLossRanking::oneHalfZero()),
            new StandingsCalculator(fingerprintCustomRanking()),
            new StandingsCalculator(tiebreakers: [new BuchholzTiebreaker()]),
            new StandingsCalculator(tiebreakers: [fingerprintTiebreakerNamed('')]),
            new StandingsCalculator(tiebreakers: [fingerprintTiebreakerNamed(''), fingerprintTiebreakerNamed('')]),
            new StandingsCalculator(tiebreakers: [fingerprintTiebreakerNamed('custom')]),
            new StandingsCalculator(tiebreakers: [fingerprintTiebreakerNamed('buchholz,wins')]),
            new StandingsCalculator(tiebreakers: [fingerprintTiebreakerNamed('buchholz'), fingerprintTiebreakerNamed('wins')]),
            new StandingsCalculator(tiebreakers: [fingerprintTiebreakerNamed('buchholz;subclass=yes')]),
        ] as $calculator) {
            $fingerprints[] = (new SwissPairingEngine(standingsCalculator: $calculator))->getFingerprint();
        }
        $fingerprints[] = (new readonly class extends SwissPairingEngine {})->getFingerprint();

        expect(array_unique($fingerprints))->toHaveCount(19);
    });

    it('lets a subclass give a fingerprint of its own, which the inherited methods then require', function (): void {
        $mine = new readonly class (plannedRounds: 3) extends SwissPairingEngine {
            #[Override]
            public function getFingerprint(): string
            {
                return 'my-swiss';
            }
        };

        $own = StageState::start($this->participants)->withEngineFingerprint('my-swiss');
        $librarys = StageState::start($this->participants)
            ->withEngineFingerprint((new SwissPairingEngine(plannedRounds: 3))->getFingerprint());

        expect($mine->pairNextRound($own)->getRoundNumber())->toBe(1)
            ->and(fn() => $mine->pairNextRound($librarys))->toThrow(
                InvalidConfigurationException::class,
                "the state is stamped by one of the library's engines and this engine is not one"
            );
    });

    it('does not tell two subclasses, or two ranking strategies of the application, apart', function (): void {
        // The limit of a fingerprint the library writes: it cannot describe
        // code it does not own. Stated here so that a change to it is seen.
        $oneSubclass = new readonly class extends SwissPairingEngine {};
        $anotherSubclass = new readonly class extends SwissPairingEngine {};
        $anotherRanking = new class implements RankingStrategy {
            #[Override]
            public function rank(Participant $participant, array $results): float
            {
                return 1.0;
            }
        };

        expect($oneSubclass::class)->not->toBe($anotherSubclass::class)
            ->and($oneSubclass->getFingerprint())->toBe($anotherSubclass->getFingerprint())
            ->and((new SwissPairingEngine(standingsCalculator: new StandingsCalculator($anotherRanking)))->getFingerprint())
            ->toBe((new SwissPairingEngine(standingsCalculator: new StandingsCalculator(fingerprintCustomRanking())))->getFingerprint());
    });

    it('writes a ranking value the same way whatever the float settings and the locale', function (): void {
        $build = fn(): string => (new SwissPairingEngine(
            standingsCalculator: new StandingsCalculator(new WinDrawLossRanking(1 / 3, 0.1 + 0.2, -0.0))
        ))->getFingerprint();
        $expected = 'tactician:v1:swiss;ranking=win-draw-loss,0.3333333333333333,0.30000000000000004,0';

        expect($build())->toBe($expected);

        $precision = ini_set('precision', '3');
        $serializePrecision = ini_set('serialize_precision', '3');
        try {
            expect($build())->toBe($expected);
        } finally {
            ini_set('precision', (string) $precision);
            ini_set('serialize_precision', (string) $serializePrecision);
        }

        expect(DecimalCommaLocale::during($build))->toBe($expected);
    });

    it('tells apart two scales that differ in the last bit of a value', function (): void {
        $build = fn(float $draw): string => (new SwissPairingEngine(
            standingsCalculator: new StandingsCalculator(new WinDrawLossRanking(1.0, $draw, 0.0))
        ))->getFingerprint();

        expect($build(0.1 + 0.2))->not->toBe($build(0.3))
            ->and($build(0.3))->toBe('tactician:v1:swiss;ranking=win-draw-loss,1,0.3,0')
            ->and($build(1.0e15))->not->toBe($build(1.0e15 + 1.0))
            ->and($build(INF))->not->toBe($build(-INF))
            ->and($build(NAN))->toBe('tactician:v1:swiss;ranking=win-draw-loss,1,nan,0');
    });
});

describe('the fingerprint builder', function (): void {
    it('orders the options by name, so the order they are stated in means nothing', function (): void {
        $one = EngineFingerprint::of('ladder')->with('rungs', 3, 1)->with('challenges', true, false);
        $other = EngineFingerprint::of('ladder')->with('challenges', true, false)->with('rungs', 3, 1);

        expect($one->toString())->toBe('tactician:v1:ladder;challenges=yes;rungs=3')
            ->and($other->toString())->toBe($one->toString());
    });

    it('takes the last statement of an option', function (): void {
        $fingerprint = EngineFingerprint::of('ladder')->with('rungs', 3, 1);

        expect($fingerprint->with('rungs', 4, 1)->toString())->toBe('tactician:v1:ladder;rungs=4')
            ->and($fingerprint->with('rungs', 1, 1)->toString())->toBe('tactician:v1:ladder')
            ->and($fingerprint->toString())->toBe('tactician:v1:ladder;rungs=3');
    });

    it('writes each kind of value so that no two values are one string', function (mixed $value, string $written): void {
        expect(EngineFingerprint::of('ladder')->with('option', $value, 'the default')->toString())
            ->toBe('tactician:v1:ladder;option=' . $written);
    })->with([
        'true' => [true, 'yes'],
        'false' => [false, 'no'],
        'an integer' => [-12, '-12'],
        'a whole float' => [3.0, '3'],
        'a fraction' => [0.5, '0.5'],
        'ten' => [10.0, '10'],
        'a hundred' => [100.0, '100'],
        'a negative fraction' => [-1.5, '-1.5'],
        'negative zero' => [-0.0, '0'],
        'a third' => [1 / 3, '0.3333333333333333'],
        'a sum that is not three tenths' => [0.1 + 0.2, '0.30000000000000004'],
        'a millionth' => [0.000001, '0.000001'],
        'a ten-millionth' => [1.0e-7, '1e-7'],
        'a small float with digits' => [1.5e-10, '1.5e-10'],
        'a large float' => [1.0e15, '1000000000000000'],
        'a float past the whole numbers a float holds' => [123456789012345678.0, '123456789012345680'],
        'the last float written plainly' => [1.0e20, '100000000000000000000'],
        'the first float written with a power' => [1.0e21, '1e+21'],
        'a float written with a power and digits' => [-2.5e30, '-2.5e+30'],
        'the largest float' => [PHP_FLOAT_MAX, '1.7976931348623157e+308'],
        'the smallest float' => [5.0e-324, '5e-324'],
        'infinity' => [INF, 'inf'],
        'negative infinity' => [-INF, '-inf'],
        'not a number' => [NAN, 'nan'],
        'a word' => ['custom', 'custom'],
        'the empty string' => ['', "''"],
        'two quotes' => ["''", '%27%27'],
        'a string of separators' => ['a;b=c,d:e', 'a%3Bb%3Dc%2Cd%3Ae'],
        'the empty list' => [[], ''],
        'a list of the empty string' => [[''], "''"],
        'a list' => [['buchholz', 'wins'], 'buchholz,wins'],
        'one entry that holds a comma' => [['buchholz,wins'], 'buchholz%2Cwins'],
        'a list of mixed kinds' => [['win-draw-loss', 3.0, true, 2], 'win-draw-loss,3,yes,2'],
    ]);

    it('reads back what it wrote', function (string $fingerprint): void {
        expect(EngineFingerprint::parse($fingerprint)?->toString())->toBe($fingerprint);
    })->with([
        'tactician:v1:swiss',
        'tactician:v1:double-elimination;grand-final-reset=no;legs-per-tie=2',
        'tactician:v1:swiss;tiebreakers=head%20to%20head%3Bx%3D1%2Cy',
        'tactician:v1:ladder;option=',
    ]);

    it('reads no string it did not write', function (string $fingerprint): void {
        expect(EngineFingerprint::parse($fingerprint))->toBeNull();
    })->with([
        'a stamp of the application' => ['my-swiss'],
        'the format alone' => ['swiss'],
        'another version of the encoding' => ['tactician:v2:swiss'],
        'the prefix alone' => ['tactician:v1:'],
        'an option without a value' => ['tactician:v1:swiss;ranking'],
        'an option without a name' => ['tactician:v1:swiss;=custom'],
        'another case' => ['Tactician:v1:swiss'],
    ]);

    it('says where two fingerprints differ', function (string $recorded, string $engine, array $differences): void {
        expect(EngineFingerprint::differences($recorded, $engine))->toBe($differences);
    })->with([
        'the same fingerprint' => ['tactician:v1:swiss', 'tactician:v1:swiss', []],
        'two formats, whatever their options' => [
            'tactician:v1:swiss;ranking=custom',
            'tactician:v1:single-elimination;legs-per-tie=2',
            ['format: recorded swiss, this engine single-elimination'],
        ],
        'options on either side, in name order' => [
            'tactician:v1:ladder;b=1;c=2',
            'tactician:v1:ladder;a=yes;c=3',
            [
                'a: recorded the default, this engine yes',
    it('reads nothing from a calculator of the application', function (): void {
        // A subclass whose constructor does not call the one it inherits
        // has no ranking strategy and no tiebreakers to read: reading them
        // is an Error. Such a calculator ordered a re-seeded bracket before
        // the fingerprint existed, and still does, stamped or not. The
        // object is built without its constructor here to be in that state.
        $subclass = new readonly class extends StandingsCalculator {
            #[Override]
            public function calculate(array $participants, array $results): Standings
            {
                return (new StandingsCalculator())->calculate($participants, $results);
            }
        };
        $calculator = (new ReflectionClass($subclass))->newInstanceWithoutConstructor();
        expect(fn() => $calculator->getTiebreakers())->toThrow(Error::class);

        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true), $calculator);

        $state = StageState::start($this->participants);
        $pairing = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($pairing, array_map(
            fn(Event $event): Result => new Result($event, $event->getParticipants()[0]),
            $pairing->getEvents()
        ));

        expect($engine->getFingerprint())->toBe('tactician:v1:single-elimination;reseed-each-round=yes;standings=custom')
            ->and($engine->pairNextRound($state)->getRoundNumber())->toBe(2)
            ->and($engine->pairNextRound($state->withEngineFingerprint($engine->getFingerprint()))->getRoundNumber())->toBe(2);
    });

    it('does not tell two calculators of the application apart, whatever they were built with', function (): void {
        // The same limit as for a ranking strategy of the application: the
        // library cannot know which of the rules a subclass still applies.
        $build = fn(StandingsCalculator $calculator): string => (new SwissPairingEngine(standingsCalculator: $calculator))
            ->getFingerprint();

        $onTheChessScale = $build(new readonly class (WinDrawLossRanking::oneHalfZero()) extends StandingsCalculator {});
        $withATiebreaker = $build(new readonly class (tiebreakers: [new BuchholzTiebreaker()]) extends StandingsCalculator {});

        expect($onTheChessScale)->toBe($withATiebreaker)
            ->and($onTheChessScale)->not->toBe($build(new StandingsCalculator()));
    });

                'b: recorded 1, this engine the default',
                'c: recorded 2, this engine 3',
            ],
        ],
        'a stamp of the application read by an engine of the library' => [
            'my-swiss',
            'tactician:v1:swiss',
            ["the stamp was not written by one of the library's engines"],
        ],
        'a stamp in an encoding this version does not know' => [
            'tactician:v2:swiss',
            'tactician:v1:swiss',
            ['the stamp was written by a version of the library this one cannot read'],
        ],
        'a stamp of the library read by an engine of the application' => [
            'tactician:v1:swiss',
            'my-swiss',
            ["the state is stamped by one of the library's engines and this engine is not one"],
        ],
        'two stamps of the application' => ['my-swiss', 'my-ladder', ["the stamp is not this engine's"]],
    ]);
});
    it('writes every float so that it reads back as the same float, with no character of the structure', function (): void {
        $randomizer = new Random\Randomizer(new Random\Engine\Mt19937(20260203));
        $written = [];
        $floats = [];

        for ($sample = 0; $sample < 5000; ++$sample) {
            // Any eight bytes are a float; one in two thousand is not finite.
            $unpacked = unpack('e', $randomizer->getBytes(8));
            $float = is_array($unpacked) ? $unpacked[1] : null;
            assert(is_float($float));
            if (!is_finite($float)) {
                continue;
            }

            $text = substr(
                EngineFingerprint::of('ladder')->with('option', $float, 'the default')->toString(),
                strlen('tactician:v1:ladder;option=')
            );

            expect($text)->toMatch('/^-?(0|[1-9]\d*)(\.\d*[1-9])?(e[+-][1-9]\d*)?$/')
                ->and((float) $text)->toBe($float);
            $written[$text] = true;
            $floats[pack('e', $float)] = true;
        }

        // No two floats share a string.
        expect(count($written))->toBe(count($floats));
    });

