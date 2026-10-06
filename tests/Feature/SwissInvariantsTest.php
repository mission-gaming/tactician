<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\JsonConversionException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\SonnebornBergerTiebreaker;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use MissionGaming\Tactician\Tests\Support\SwissStageAudit;
use Random\Engine\Mt19937;
use Random\Randomizer;

// The Swiss property suite. A stage is driven round by round with results
// drawn from a seeded generator (a win either way or a draw; an odd field
// adds a bye every round), the state goes through JSON after every round as
// a platform would store it, and every round the engine pairs is held to
// the rules the usage guide states. tests/Support/SwissStageAudit.php lists
// them and keeps its own books: it never asks the library who has met whom,
// who has had a bye or how many points anyone has.
//
// The sweep: every field size from 2 to 32, each of the six variants below,
// two seeds each, over up to seven rounds; then every field size from 2 to
// 16 to the last round the format has (n - 1, the most that repeat
// avoidance allows: see SwissScheduler), ten seeds each.
//
// Larger fields are not driven to the last round. On `main` the engine's
// search for a late round of a large field can take minutes (one stage of
// 30 participants took 75 seconds in round 28 here); the suite cannot carry
// that, and nothing about the rules changes with the field size.

/**
 * A field in which the participants come in pairs from the same club:
 * p1 and p2, p3 and p4, and so on.
 *
 * @return list<Participant>
 */
function swissInvariantsField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("p{$i}", "Participant {$i}", $i, ['club' => intdiv($i - 1, 2)]);
    }

    return $participants;
}

/**
 * Drive one stage of the named variant and audit it.
 *
 * @return array{violations: list<string>, transcript: list<string>, rounds: int, failedRound: int|null, byes: int, withdrawn: int}
 * @throws InvalidConfigurationException When the engine refuses the state
 * @throws JsonConversionException When the state does not survive JSON
 */
function swissInvariantsRun(string $variant, int $size, int $rounds, int $seed, bool $throughJson = true): array
{
    $everything = $variant === 'everything at once';
    $tiebreakers = $everything || $variant === 'tiebreakers on a 1/0.5/0 scale';

    // Clubmates do not meet: a constraint that removes one opponent each
    $differentClubs = static fn(Participant $one, Participant $other): bool => $one->getMetadataValue('club') !== $other->getMetadataValue('club');
    $mayMeet = $everything || $variant === 'a constraint' ? $differentClubs : null;

    $engine = new SwissPairingEngine(
        $mayMeet === null ? null : ConstraintSet::create()->custom(
            static fn(Event $event): bool => $differentClubs($event->getParticipants()[0], $event->getParticipants()[1]),
            'Clubmates do not meet'
        )->build(),
        $tiebreakers
            ? new StandingsCalculator(WinDrawLossRanking::oneHalfZero(), [new BuchholzTiebreaker(), new SonnebornBergerTiebreaker()])
            : new StandingsCalculator(),
        $rounds,
        $everything || $variant === 'a randomizer' ? new Randomizer(new Mt19937($seed)) : null
    );

    return SwissStageAudit::drive(
        $engine,
        swissInvariantsField($size),
        $rounds,
        $seed * 1000 + $size,
        $tiebreakers ? 1.0 : 3.0,
        $tiebreakers ? 0.5 : 1.0,
        $mayMeet,
        $everything || $variant === 'withdrawals',
        $throughJson
    );
}

/**
 * An engine that pairs as the real one does and then changes the round.
 *
 * @param Closure(RoundPairing, StageState): RoundPairing $tamper
 */
function swissInvariantsTamperedEngine(int $rounds, Closure $tamper): SwissPairingEngine
{
    return new readonly class ($rounds, $tamper) extends SwissPairingEngine {
        /**
         * @param Closure(RoundPairing, StageState): RoundPairing $tamper
         */
        public function __construct(int $rounds, private Closure $tamper)
        {
            parent::__construct(plannedRounds: $rounds);
        }

        #[Override]
        public function pairNextRound(StageState $state): RoundPairing
        {
            return ($this->tamper)(parent::pairNextRound($state), $state);
        }
    };
}

describe('Swiss invariants', function (): void {
    it('holds every rule at every field size from 2 to 32', function (string $variant): void {
        $violations = [];
        $byes = 0;
        $withdrawn = 0;
        $rounds = 0;

        for ($size = 2; $size <= 32; ++$size) {
            foreach ([1, 2] as $seed) {
                $run = swissInvariantsRun($variant, $size, min($size - 1, 7), $seed);

                foreach ($run['violations'] as $violation) {
                    $violations[] = "n={$size}, seed {$seed}: {$violation}";
                }
                $byes += $run['byes'];
                $withdrawn += $run['withdrawn'];
                $rounds += $run['rounds'];
            }
        }

        $withdraws = in_array($variant, ['withdrawals', 'everything at once'], true);

        expect($violations)->toBe([])
            // The sweep saw what it is there to see
            ->and($rounds)->toBeGreaterThan(300)
            ->and($byes)->toBeGreaterThan(100)
            ->and($withdrawn > 0)->toBe($withdraws);
    })->with([
        ['plain'],
        ['tiebreakers on a 1/0.5/0 scale'],
        ['a randomizer'],
        ['a constraint'],
        ['withdrawals'],
        ['everything at once'],
    ]);

    // To the last round. Monrad pairing is greedy from one round to the
    // next: nothing in round r looks ahead to keep round r + 1 possible, so
    // near the end the participants who have not met can be left with no
    // way to pair them all, and the engine then throws
    // NoValidPairingException, as the usage guide says it does "when repeat
    // avoidance leaves no complete pairing". That is a limit of the format,
    // not a defect: the audit proves for every failure that no complete
    // pairing existed.
    //
    // How often, measured over 200 stages per field size (seeds 1 to 200,
    // plain engine, n - 1 rounds planned; the round is the one that could
    // not be paired):
    //
    //   n      2-5   6     7   8     9     10    11    12    13    14    15    16
    //   failed 0     32    0   22    1     33    5     38    6     45    15    41
    //   rate   0%    16%   0%  11%   0.5%  16.5% 2.5%  19%   3%    22.5% 7.5%  20.5%
    //   round  -     4     -   6     8     8     10    10    12    12    14    14
    //
    // An even field fails in round n - 2 and an odd one in round n - 1. The
    // test pins the number of failures among the first ten of those stages
    // for every field size, so that a change to the pairing search that
    // moves the rate is seen here. These counts are a record of what the
    // engine does, not a requirement: a change that pairs more late rounds
    // is an improvement, and it changes these numbers and the changelog.
    it('pairs to the last round or fails with the typed exception, as often as recorded', function (): void {
        $violations = [];
        $failed = [];

        for ($size = 2; $size <= 16; ++$size) {
            $failed[$size] = 0;
            for ($seed = 1; $seed <= 10; ++$seed) {
                $run = swissInvariantsRun('plain', $size, $size - 1, $seed);

                foreach ($run['violations'] as $violation) {
                    $violations[] = "n={$size}, seed {$seed}: {$violation}";
                }
                if ($run['failedRound'] !== null) {
                    ++$failed[$size];
                    // Recorded with the counts, and no more of a rule than
                    // they are: none of these stages fails before its last
                    // two rounds. The format allows an earlier failure (ten
                    // participants, each of whom has met the five of the
                    // other half in five rounds, are left with two groups
                    // of five, and a group of five cannot be paired in a
                    // sixth), so a change that moves this is to be
                    // explained, not presumed wrong.
                    expect($run['failedRound'])->toBeGreaterThanOrEqual($size - 2);
                } else {
                    expect($run['rounds'])->toBe($size - 1);
                }
            }
        }

        expect($violations)->toBe([])
            ->and($failed)->toBe([
                2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 3, 7 => 0, 8 => 1, 9 => 0,
                10 => 4, 11 => 0, 12 => 0, 13 => 1, 14 => 3, 15 => 0, 16 => 3,
            ]);
    });

    // The planned rounds say when the stage ends and nothing else: no round
    // is paired differently for them (SwissPairingEngine::getFingerprint()).
    // So a stage planned for r rounds is the first r rounds of a longer one.
    it('pairs the same rounds whatever number of rounds is planned, from 1 to the limit', function (int $size): void {
        $longest = swissInvariantsRun('plain', $size, $size - 1, 3);

        for ($rounds = 1; $rounds < $size - 1; ++$rounds) {
            $run = swissInvariantsRun('plain', $size, $rounds, 3);

            expect($run['violations'])->toBe([])
                ->and($run['transcript'])->toBe(array_slice($longest['transcript'], 0, $rounds), "{$rounds} rounds planned");
        }
    })->with([[5], [8], [11], [12]]);

    it('pairs the same stage again from the same seeds, with the state stored as JSON or kept in memory', function (
        string $variant,
        int $size
    ): void {
        $run = swissInvariantsRun($variant, $size, 6, 11);

        expect($run['transcript'])->toHaveCount($run['rounds'] + $run['withdrawn'])
            ->and(swissInvariantsRun($variant, $size, 6, 11)['transcript'])->toBe($run['transcript'])
            ->and(swissInvariantsRun($variant, $size, 6, 11, throughJson: false)['transcript'])->toBe($run['transcript'])
            // Other results, another stage: the pairings follow the table
            ->and(swissInvariantsRun($variant, $size, 6, 12)['transcript'])->not->toBe($run['transcript']);
    })
        ->with([['plain'], ['tiebreakers on a 1/0.5/0 scale'], ['a randomizer'], ['everything at once']])
        ->with([[9], [16], [25]]);
});

// The audit must see what it is there to see. Each case pairs with the real
// engine and then spoils the round in one way.
describe('the Swiss audit', function (): void {
    it('reports a round that breaks a rule', function (int $size, Closure $tamper, string $expected): void {
        $run = SwissStageAudit::drive(
            swissInvariantsTamperedEngine(4, $tamper),
            swissInvariantsField($size),
            4,
            5
        );

        expect(implode("\n", $run['violations']))->toContain($expected);
    })->with([
        'a rematch' => [
            8,
            // Round 1 again in every round
            fn(RoundPairing $pairing, StageState $state): RoundPairing => $state->getRoundsPlayed() === []
                ? $pairing
                : new RoundPairing($pairing->getRoundNumber(), null, array_map(
                    fn(Event $event): Event => new Event($event->getParticipants(), $pairing->getEvents()[0]->getRound()),
                    $state->getRoundsPlayed()[0]->getEvents()
                )),
            'is a rematch',
        ],
        'a participant left out' => [
            8,
            fn(RoundPairing $pairing): RoundPairing => new RoundPairing($pairing->getRoundNumber(), null, array_slice($pairing->getEvents(), 1)),
            '2 active participant(s) neither paired nor given the bye',
        ],
        'a bye in an even field' => [
            8,
            fn(RoundPairing $pairing): RoundPairing => new RoundPairing(
                $pairing->getRoundNumber(),
                null,
                array_slice($pairing->getEvents(), 1),
                [$pairing->getEvents()[0]->getParticipants()[0]]
            ),
            '1 bye(s) in a field of 8',
        ],
        'the lowest score group paired first' => [
            8,
            fn(RoundPairing $pairing): RoundPairing => new RoundPairing($pairing->getRoundNumber(), null, array_reverse($pairing->getEvents())),
            'is paired before the highest score group is',
        ],
        'a leader paired down past an available opponent' => [
            8,
            // The first and the last event trade opponents
            function (RoundPairing $pairing): RoundPairing {
                $events = $pairing->getEvents();
                $last = count($events) - 1;
                [$top, $second] = $events[0]->getParticipants();
                [$third, $bottom] = $events[$last]->getParticipants();
                $events[0] = new Event([$top, $bottom], $events[0]->getRound());
                $events[$last] = new Event([$third, $second], $events[$last]->getRound());

                return new RoundPairing($pairing->getRoundNumber(), null, $events);
            },
            'was available',
        ],
        'the bye for a participant from the first event' => [
            9,
            function (RoundPairing $pairing): RoundPairing {
                $events = $pairing->getEvents();
                [$first, $second] = $events[0]->getParticipants();
                $events[0] = new Event([$pairing->getByes()[0], $second], $events[0]->getRound());

                return new RoundPairing($pairing->getRoundNumber(), null, $events, [$first]);
            },
            'took the bye',
        ],
        'a second bye for the same participant' => [
            9,
            function (RoundPairing $pairing, StageState $state): RoundPairing {
                if ($state->getRoundsPlayed() === []) {
                    return $pairing;
                }
                $again = $state->getRoundsPlayed()[0]->getByes()[0];
                $bye = $pairing->getByes()[0];
                $events = array_map(
                    fn(Event $event): Event => $event->hasParticipant($again)
                        ? new Event(
                            array_map(fn(Participant $participant): Participant => $participant->getId() === $again->getId() ? $bye : $participant, $event->getParticipants()),
                            $event->getRound()
                        )
                        : $event,
                    $pairing->getEvents()
                );

                return new RoundPairing($pairing->getRoundNumber(), null, $events, [$again]);
            },
            '(1 before,',
        ],
        'a failure when a pairing exists' => [
            8,
            fn(RoundPairing $pairing, StageState $state): RoundPairing => $state->getRoundsPlayed() === []
                ? $pairing
                : throw new NoValidPairingException($pairing->getRoundNumber(), $state->getParticipants()),
            'the engine found no valid pairing although one exists',
        ],
        'a failure that names another round' => [
            8,
            fn(RoundPairing $pairing, StageState $state): RoundPairing => throw new NoValidPairingException(9, $state->getParticipants()),
            'the failure names round 9',
        ],
    ]);

    it('reports a stage that does not end with its planned rounds', function (): void {
        $run = SwissStageAudit::drive(new SwissPairingEngine(plannedRounds: 3), swissInvariantsField(8), 4, 5);

        expect($run['violations'])->toBe(['round 4: the stage is reported complete after 3 of 4 rounds']);
    });

    it('decides whether a complete pairing exists', function (array $edges, int $vertices, bool $expected): void {
        $allowed = [];
        foreach ($edges as [$one, $other]) {
            $allowed[$one][$other] = true;
            $allowed[$other][$one] = true;
        }
        $blocked = static fn(int $one, int $other): bool => !isset($allowed[$one][$other]);

        expect(SwissStageAudit::completePairingExists(range(0, $vertices - 1), $blocked, new Randomizer(new Mt19937(1))))
            ->toBe($expected);
    })->with([
        'one pair' => [[[0, 1]], 2, true],
        'two strangers' => [[], 2, false],
        'a path of four' => [[[0, 1], [1, 2], [2, 3]], 4, true],
        'a star: three who have only met the fourth' => [[[0, 1], [0, 2], [0, 3]], 4, false],
        'a ring of six' => [[[0, 1], [1, 2], [2, 3], [3, 4], [4, 5], [5, 0]], 6, true],
        'two triangles' => [[[0, 1], [1, 2], [2, 0], [3, 4], [4, 5], [5, 3]], 6, false],
        'two triangles joined by one edge' => [[[0, 1], [1, 2], [2, 0], [3, 4], [4, 5], [5, 3], [2, 3]], 6, true],
        'a triangle: one sits out' => [[[0, 1], [1, 2], [2, 0]], 3, true],
        'three who can meet nobody' => [[], 3, false],
        'five where the only edge leaves two strangers' => [[[0, 1]], 5, false],
        'five on a path: an end sits out' => [[[0, 1], [1, 2], [2, 3], [3, 4]], 5, true],
    ]);
});
