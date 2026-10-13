<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\Withdrawal;
use MissionGaming\Tactician\Standings\Adjustment;

// A withdrawal from a live stage with its time: who, and after which
// recorded round. The state records it by itself; a history states it.

describe('Withdrawal', function (): void {
    it('holds who withdrew and after which round', function (): void {
        $ines = new Participant('ines', 'Ines');
        $withdrawal = new Withdrawal($ines, 3);

        expect($withdrawal->getParticipant())->toBe($ines)
            ->and($withdrawal->getAfterRound())->toBe(3);
    });

    it('refuses a round below 1: before the first round a withdrawn participant is left out of the list', function (int $round): void {
        new Withdrawal(new Participant('ines', 'Ines'), $round);
    })->with([0, -2])->throws(InvalidInputException::class, 'a withdrawal is recorded with the number of the last recorded round');
});

describe('StageState withdrawals', function (): void {
    beforeEach(function (): void {
        $this->ines = new Participant('ines', 'Ines');
        $this->jun = new Participant('jun', 'Jun');
        $this->kofi = new Participant('kofi', 'Kofi');
        $this->lena = new Participant('lena', 'Lena');
        $this->participants = [$this->ines, $this->jun, $this->kofi, $this->lena];

        $this->round = (fn(int $number): RoundPairing => new RoundPairing($number, null, [
            new Event([$this->ines, $this->jun], new Round($number)),
            new Event([$this->kofi, $this->lena], new Round($number)),
        ]));

        $this->timed = (static fn(StageState $state): array => array_map(
            static fn(Withdrawal $withdrawal): array => [$withdrawal->getParticipant()->getId(), $withdrawal->getAfterRound()],
            $state->getWithdrawals()
        ));
    });

    it('records after which round each participant withdrew, in the order they did, with no argument for it', function (): void {
        $state = StageState::start($this->participants)
            ->withRoundPlayed(($this->round)(1), [])
            ->withoutParticipant($this->lena)
            ->withRoundPlayed(($this->round)(2), [])
            ->withRoundPlayed(($this->round)(3), [])
            ->withoutParticipant($this->ines)
            ->withoutParticipant($this->jun);

        expect(($this->timed)($state))->toBe([['lena', 1], ['ines', 3], ['jun', 3]])
            ->and($state->getWithdrawals()[0]->getParticipant())->toBe($this->lena);
    });

    it('records none before the first round: the participant is removed and has no place to keep', function (): void {
        $state = StageState::start($this->participants)->withoutParticipant($this->lena);

        expect($state->getWithdrawals())->toBe([])
            ->and($state->getWithdrawnEntrants())->toBe([])
            ->and(array_key_exists('withdrawals', $state->toArray()))->toBeFalse();
    });

    it('records nothing for a participant who is not active: withdrawn already, or never in the stage', function (): void {
        $state = StageState::start($this->participants)
            ->withRoundPlayed(($this->round)(1), [])
            ->withoutParticipant($this->lena)
            ->withRoundPlayed(($this->round)(2), [])
            ->withoutParticipant($this->lena)
            ->withoutParticipant(new Participant('mona', 'Mona'));

        // The first withdrawal stands, with the round it happened after
        expect(($this->timed)($state))->toBe([['lena', 1]]);
    });

    it('keeps the withdrawals through every other verb', function (): void {
        $state = StageState::start($this->participants)
            ->withRoundPlayed(($this->round)(1), [])
            ->withoutParticipant($this->lena);
        $expected = [['lena', 1]];

        expect(($this->timed)($state->withRoundPlayed(($this->round)(2), [])))->toBe($expected)
            ->and(($this->timed)($state->withAdditionalResults([])))->toBe($expected)
            ->and(($this->timed)($state->withEngineFingerprint('ladder:rungs=3')))->toBe($expected)
            ->and(($this->timed)($state->withAdjustment(new Adjustment('jun', 1.0))))->toBe($expected);
    });

    it('writes the withdrawals as the last key, each with its round, and reads them back', function (): void {
        $state = StageState::start($this->participants)
            ->withRoundPlayed(($this->round)(1), [])
            ->withoutParticipant($this->lena)
            ->withRoundPlayed(($this->round)(2), [])
            ->withoutParticipant($this->ines);

        $data = $state->toArray();

        expect(array_keys($data))->toBe(['format_version', 'participants', 'active', 'rounds', 'results', 'entrants', 'withdrawals'])
            ->and($data['withdrawals'] ?? null)->toBe([
                ['participant' => 'lena', 'after_round' => 1],
                ['participant' => 'ines', 'after_round' => 2],
            ])
            ->and(StageState::fromArray($data))->toEqual($state)
            ->and(StageState::fromJson($state->toJson())->toArray())->toBe($data);
    });

    // What was stored before a withdrawal said its round: the entrant list
    // and no more. Nothing is assumed about when the participant left, and
    // the state is written back as it was read.
    it('reads a state stored without the withdrawals as one that does not know when anybody withdrew', function (): void {
        $data = StageState::start($this->participants)
            ->withRoundPlayed(($this->round)(1), [])
            ->withoutParticipant($this->lena)
            ->toArray();
        unset($data['withdrawals']);

        $read = StageState::fromArray($data);

        expect($read->getWithdrawals())->toBe([])
            ->and(array_map(static fn(Participant $participant): string => $participant->getId(), $read->getWithdrawnEntrants()))->toBe(['lena'])
            ->and($read->toArray())->toBe($data);
    });

    it('refuses stored withdrawals that no state could have recorded', function (mixed $withdrawals, string $message): void {
        $data = StageState::start($this->participants)
            ->withRoundPlayed(($this->round)(1), [])
            ->withRoundPlayed(($this->round)(2), [])
            ->withoutParticipant($this->lena)
            ->withoutParticipant($this->kofi)
            ->toArray();
        $data['withdrawals'] = $withdrawals;

        expect(fn() => StageState::fromArray($data))->toThrow(InvalidInputException::class, $message);
    })->with([
        'no list' => ['lena', 'Stage state withdrawals must be an array'],
        'an entry that is no array' => [['lena'], 'Each stage state withdrawal must be an array'],
        'a participant the state does not know' => [[['participant' => 'mona', 'after_round' => 1]], "references unknown participant 'mona'"],
        'a participant who is active' => [[['participant' => 'ines', 'after_round' => 1]], 'names participant ines, who is active'],
        'one participant twice' => [
            [['participant' => 'lena', 'after_round' => 1], ['participant' => 'lena', 'after_round' => 2]],
            'lists the withdrawal of participant lena twice',
        ],
        'no round' => [[['participant' => 'lena']], 'requires the round it happened after'],
        'a round that is no integer' => [[['participant' => 'lena', 'after_round' => '1']], 'requires the round it happened after'],
        'round 0' => [[['participant' => 'lena', 'after_round' => 0]], 'requires the round it happened after'],
        'a round after the last recorded' => [[['participant' => 'lena', 'after_round' => 3]], 'the last recorded round (2)'],
        'rounds that go back' => [
            [['participant' => 'lena', 'after_round' => 2], ['participant' => 'kofi', 'after_round' => 1]],
            'not below the round of the withdrawal listed',
        ],
        'a key it does not have' => [[['participant' => 'lena', 'after_round' => 1, 'reason' => 'injury']], "Unknown key in stage state withdrawal data: 'reason'"],
    ]);

    it('takes withdrawals from a history with their rounds, and puts them in the order of the rounds', function (): void {
        $state = StageState::fromHistory(
            $this->participants,
            [($this->round)(2), ($this->round)(1), ($this->round)(3)],
            [],
            // Rows come back in any order: the rounds order them, and those
            // of one round stay in the order given
            [new Withdrawal($this->jun, 3), new Withdrawal($this->lena, 1), new Withdrawal($this->ines, 3)]
        );

        $verbs = StageState::start($this->participants)
            ->withRoundPlayed(($this->round)(1), [])
            ->withoutParticipant($this->lena)
            ->withRoundPlayed(($this->round)(2), [])
            ->withRoundPlayed(($this->round)(3), [])
            ->withoutParticipant($this->jun)
            ->withoutParticipant($this->ines);

        expect(($this->timed)($state))->toBe([['lena', 1], ['jun', 3], ['ines', 3]])
            ->and($state->toArray())->toBe($verbs->toArray())
            ->and($state)->toEqual($verbs);
    });

    it('keeps a withdrawal given to a history as a plain participant without a round, and does not make one up', function (): void {
        $state = StageState::fromHistory(
            $this->participants,
            [($this->round)(1), ($this->round)(2)],
            [],
            [$this->lena, new Withdrawal($this->jun, 2)]
        );

        // Lena has withdrawn and keeps her place; when she did is not said
        expect(($this->timed)($state))->toBe([['jun', 2]])
            ->and(array_map(static fn(Participant $participant): string => $participant->getId(), $state->getWithdrawnEntrants()))->toBe(['jun', 'lena'])
            ->and($state->toArray()['withdrawals'] ?? null)->toBe([['participant' => 'jun', 'after_round' => 2]]);
    });

    it('refuses a history whose withdrawal or adjustment happened after a round it does not hold', function (Closure $history, array $context): void {
        try {
            $history($this);
            $this->fail('The history was accepted.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::ValueOutOfRange)
                ->and($exception->getContext())->toBe($context);
        }
    })->with([
        'a withdrawal after round 2 of a history of one round' => [
            fn($test) => StageState::fromHistory($test->participants, [($test->round)(1)], [], [new Withdrawal($test->lena, 2)]),
            ['participant' => 'lena', 'after_round' => 2, 'last_round' => 1],
        ],
        'a withdrawal with its round in a history of no round' => [
            fn($test) => StageState::fromHistory($test->participants, [], [], [new Withdrawal($test->lena, 1)]),
            ['participant' => 'lena', 'after_round' => 1, 'last_round' => 0],
        ],
        'an adjustment after round 3 of a history of two rounds' => [
            fn($test) => StageState::fromHistory(
                $test->participants,
                [($test->round)(1), ($test->round)(2)],
                [],
                [],
                [(new Adjustment('jun', 1.0))->madeAfterRound(3)]
            ),
            ['participant' => 'jun', 'after_round' => 3, 'last_round' => 2],
        ],
    ]);

    it('keeps an adjustment given to a history without a round as one of unknown time', function (): void {
        $state = StageState::fromHistory(
            $this->participants,
            [($this->round)(1), ($this->round)(2)],
            [],
            [],
            [new Adjustment('jun', 1.0), (new Adjustment('ines', -1.0))->madeAfterRound(1)]
        );

        // Not "after the last round", which is what the verb would have said
        expect(array_map(
            static fn(Adjustment $adjustment): ?int => $adjustment->getAfterRound(),
            $state->getAdjustments()
        ))->toBe([null, 1]);
    });
});
