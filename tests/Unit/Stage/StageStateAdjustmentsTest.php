<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Award;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\Adjustment;

// A stage state carries the adjustments the application recorded, so that
// every table an engine builds from it has them, and it stores them and the
// awarded results with the rest of the state.

describe('StageState adjustments', function (): void {
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
    });

    it('has none until one is recorded, and writes no key for none', function (): void {
        $state = StageState::start($this->participants);

        expect($state->getAdjustments())->toBe([])
            ->and(array_keys($state->toArray()))->toBe(['format_version', 'participants', 'active', 'rounds', 'results']);
    });

    it('records an adjustment on a new state and leaves the one it was called on', function (): void {
        $state = StageState::start($this->participants);
        $adjustment = new Adjustment('jun', -1.0, 'ruling', 'Late roster');

        $adjusted = $state->withAdjustment($adjustment);

        // Recorded with the moment it was made: before the first round
        expect($adjusted->getAdjustments())->toEqual([new Adjustment('jun', -1.0, 'ruling', 'Late roster', 0)])
            ->and($state->getAdjustments())->toBe([])
            ->and($adjusted->getParticipants())->toBe($this->participants);
    });

    it('records after which round each adjustment was made, with no argument for it', function (): void {
        $state = StageState::start($this->participants)
            ->withAdjustment(new Adjustment('jun', -1.0))
            ->withRoundPlayed(($this->round)(1), [])
            ->withAdjustment(new Adjustment('ines', 0.5))
            ->withRoundPlayed(($this->round)(2), [])
            ->withRoundPlayed(($this->round)(3), [])
            ->withAdjustment(new Adjustment('kofi', 2.0))
            ->withAdjustment(new Adjustment('jun', 1.0));

        expect(array_map(
            static fn(Adjustment $adjustment): array => [$adjustment->getParticipantId(), $adjustment->getAfterRound()],
            $state->getAdjustments()
        ))->toBe([['jun', 0], ['ines', 1], ['kofi', 3], ['jun', 3]]);
    });

    it('records after the number of the last round, not after how many rounds there are', function (): void {
        // The verbs accept a gap in the round numbers, and a round is known by its number
        $state = StageState::start($this->participants)
            ->withRoundPlayed(($this->round)(1), [])
            ->withRoundPlayed(($this->round)(4), [])
            ->withAdjustment(new Adjustment('jun', -1.0));

        expect($state->getAdjustments()[0]->getAfterRound())->toBe(4);
    });

    it('keeps the round an adjustment states, so that one made earlier can be recorded late', function (): void {
        $state = StageState::start($this->participants)
            ->withRoundPlayed(($this->round)(1), [])
            ->withRoundPlayed(($this->round)(2), []);
        $earlier = (new Adjustment('jun', -1.0, 'ruling'))->madeAfterRound(1);

        expect($state->withAdjustment($earlier)->getAdjustments())->toBe([$earlier])
            ->and($state->withAdjustment((new Adjustment('jun', -1.0))->madeAfterRound(0))->getAdjustments()[0]->getAfterRound())->toBe(0)
            ->and($state->withAdjustment((new Adjustment('jun', -1.0))->madeAfterRound(2))->getAdjustments()[0]->getAfterRound())->toBe(2);
    });

    it('refuses an adjustment said to be made after a round that is not recorded yet', function (): void {
        StageState::start($this->participants)
            ->withRoundPlayed(($this->round)(1), [])
            ->withAdjustment((new Adjustment('jun', -1.0))->madeAfterRound(2));
    })->throws(InvalidInputException::class, 'is said to be made after round 2, and the last round recorded is round 1');

    it('keeps adjustments in the order recorded and never merges two for one participant', function (): void {
        $first = new Adjustment('jun', -1.0, 'ruling');
        $second = new Adjustment('ines', 0.5);
        $third = new Adjustment('jun', 1.0, 'appeal');

        $state = StageState::start($this->participants)
            ->withAdjustment($first)
            ->withAdjustment($second)
            ->withAdjustment($third);

        expect($state->getAdjustments())->toEqual([$first->madeAfterRound(0), $second->madeAfterRound(0), $third->madeAfterRound(0)]);
    });

    it('rejects an adjustment for a participant the stage has not seen', function (): void {
        StageState::start($this->participants)->withAdjustment(new Adjustment('mona', -1.0));
    })->throws(InvalidInputException::class, 'Adjustment references participant mona who is not in the stage');

    it('keeps the adjustments through every other verb', function (): void {
        // One that states its round is kept as the object it is
        $adjustment = new Adjustment('jun', -1.0, null, null, 0);
        $pairing = ($this->round)(1);
        $first = new Result($pairing->getEvents()[0], $this->ines);
        $second = new Result($pairing->getEvents()[1], $this->kofi);

        $state = StageState::start($this->participants)->withAdjustment($adjustment);

        expect($state->withRoundPlayed($pairing, [$first])->getAdjustments())->toBe([$adjustment])
            ->and($state->withRoundPlayed($pairing, [$first])->withAdditionalResults([$second])->getAdjustments())->toBe([$adjustment])
            ->and($state->withRoundPlayed($pairing, [$first])->withResultReplaced(new Result($pairing->getEvents()[0], $this->jun))->getAdjustments())
            ->toBe([$adjustment])
            ->and($state->withoutParticipant($this->lena)->getAdjustments())->toBe([$adjustment])
            ->and($state->withEngineFingerprint('ladder:rungs=3')->getAdjustments())->toBe([$adjustment]);
    });

    it('accepts an adjustment for a participant who has withdrawn and is still on record', function (): void {
        $pairing = ($this->round)(1);
        $state = StageState::start($this->participants)
            ->withRoundPlayed($pairing, [])
            ->withoutParticipant($this->lena)
            ->withAdjustment(new Adjustment('lena', -2.0));

        expect(count($state->getAdjustments()))->toBe(1);
    });

    it('still sees a participant who withdrew with an adjustment and nothing else on record', function (): void {
        $state = StageState::start($this->participants)
            ->withAdjustment(new Adjustment('lena', -2.0))
            ->withoutParticipant($this->lena);

        $seen = array_map(static fn(Participant $participant): string => $participant->getId(), $state->getAllSeenParticipants());

        expect($seen)->toBe(['ines', 'jun', 'kofi', 'lena'])
            ->and(count($state->getParticipants()))->toBe(3)
            // And so it can be given a further adjustment, and stored
            ->and(count($state->withAdjustment(new Adjustment('lena', 1.0))->getAdjustments()))->toBe(2)
            ->and(StageState::fromJson($state->toJson())->toJson())->toBe($state->toJson());
    });

    it('does not see a participant who withdrew with nothing on record', function (): void {
        $state = StageState::start($this->participants)->withoutParticipant($this->lena);

        expect(fn() => $state->withAdjustment(new Adjustment('lena', -2.0)))->toThrow(InvalidInputException::class);
    });

    it('writes the adjustments as the last key and reads them back', function (): void {
        $state = StageState::start($this->participants)
            ->withEngineFingerprint('ladder:rungs=3')
            ->withAdjustment(new Adjustment('jun', -1.0, 'ruling', 'Late roster'))
            ->withAdjustment(new Adjustment('ines', 0.5));

        $data = $state->toArray();

        // An adjustment is a part of the state: it carries no version of its own
        expect(array_keys($data))->toBe(['format_version', 'participants', 'active', 'rounds', 'results', 'engine_fingerprint', 'adjustments'])
            ->and($data['adjustments'] ?? null)->toBe([
                ['participant' => 'jun', 'amount' => -1.0, 'label' => 'ruling', 'reason' => 'Late roster', 'after_round' => 0],
                ['participant' => 'ines', 'amount' => 0.5, 'label' => null, 'reason' => null, 'after_round' => 0],
            ])
            ->and(StageState::fromArray($data)->getAdjustments())->toEqual($state->getAdjustments())
            ->and(StageState::fromArray($data)->toArray())->toBe($data);
    });

    // What a revision before 0.3.0 that was never released stored: the four
    // keys and no more (0.2.x stored no adjustments). Nothing is assumed
    // about when it was made where that matters: a state with rounds is
    // refused, and one without is read as made after round 0, the only
    // time it can have been made.
    it('refuses an adjustment stored without its round in a state with rounds, and reads it as after round 0 in one without', function (): void {
        $withRounds = StageState::start($this->participants)
            ->withRoundPlayed(($this->round)(1), [])
            ->withAdjustment(new Adjustment('jun', -1.0, 'ruling'))
            ->toArray();
        assert(isset($withRounds['adjustments']));
        unset($withRounds['adjustments'][0]['after_round']);

        $withoutRounds = StageState::start($this->participants)
            ->withAdjustment(new Adjustment('jun', -1.0, 'ruling'))
            ->toArray();
        assert(isset($withoutRounds['adjustments']));
        $written = $withoutRounds;
        unset($withoutRounds['adjustments'][0]['after_round']);

        $read = StageState::fromArray($withoutRounds);

        expect(fn() => StageState::fromArray($withRounds))
            ->toThrow(InvalidInputException::class, 'Stage state adjustment for participant jun does not say after which round it was made')
            ->and($read->getAdjustments())->toEqual([(new Adjustment('jun', -1.0, 'ruling'))->madeAfterRound(0)])
            ->and($read->toArray())->toBe($written);
    });

    it('refuses stored data whose adjustment is made after a round the state does not hold', function (): void {
        $data = StageState::start($this->participants)
            ->withRoundPlayed(($this->round)(1), [])
            ->withAdjustment(new Adjustment('jun', -1.0))
            ->toArray();
        assert(isset($data['adjustments']));
        $data['adjustments'][0]['after_round'] = 2;

        StageState::fromArray($data);
    })->throws(InvalidInputException::class, 'is said to be made after round 2, and the last recorded round is round 1');

    it('round-trips awarded results and adjustments through the JSON of the state', function (): void {
        $pairing = ($this->round)(1);
        $state = StageState::start($this->participants)
            ->withRoundPlayed($pairing, [
                new Result($pairing->getEvents()[0], $this->ines, ['ines' => 3, 'jun' => 0], award: new Award('no-show', 'Jun did not check in')),
                new Result($pairing->getEvents()[1], $this->kofi, ['kofi' => 2, 'lena' => 1]),
            ])
            ->withAdjustment(new Adjustment('kofi', -1.0, 'ruling', 'Late roster'));

        $restored = StageState::fromJson($state->toJson());

        expect($restored->toJson())->toBe($state->toJson())
            ->and($restored->getAdjustments())->toEqual($state->getAdjustments())
            ->and($restored->getResults()[0]->isAwarded())->toBeTrue()
            ->and($restored->getResults()[0]->getAward())->toEqual(new Award('no-show', 'Jun did not check in'))
            ->and($restored->getResults()[0]->getScores())->toBe(['ines' => 3, 'jun' => 0])
            ->and($restored->getResults()[1]->isAwarded())->toBeFalse();
    });

    it('loads a state stored before adjustments existed, and writes it back unchanged', function (): void {
        $stored = rtrim((string) file_get_contents(__DIR__ . '/../../Fixtures/golden/wire/stage-state.json'), "\n");
        expect($stored)->not->toContain('adjustments');
        expect($stored)->not->toContain('award');

        $state = StageState::fromJson($stored);

        expect($state->getAdjustments())->toBe([])
            ->and($state->toJson())->toBe($stored);
        foreach ($state->getResults() as $result) {
            expect($result->isAwarded())->toBeFalse();
        }
    });

    it('reads null adjustments as none', function (): void {
        $data = [...StageState::start($this->participants)->toArray(), 'adjustments' => null];

        expect(StageState::fromArray($data)->getAdjustments())->toBe([]);
    });

    it('refuses stored adjustments it cannot read', function (mixed $adjustments, string $message): void {
        $data = [...StageState::start($this->participants)->toArray(), 'adjustments' => $adjustments];

        expect(fn() => StageState::fromArray($data))->toThrow(InvalidInputException::class, $message);
    })->with([
        'not a list' => ['jun', 'Stage state adjustments must be an array'],
        'an entry that is not an array' => [[-1.0], 'Each stage state adjustment must be an array'],
        'an entry without an amount' => [[['participant' => 'jun']], 'requires an amount'],
        'a participant the registry does not have' => [
            [['participant' => 'mona', 'amount' => -1]],
            'Stage state adjustment references unknown participant mona',
        ],
    ]);

    it('replaces a played result of the last round with an awarded one', function (): void {
        $pairing = ($this->round)(1);
        $state = StageState::start($this->participants)->withRoundPlayed($pairing, [
            new Result($pairing->getEvents()[0], $this->jun, ['ines' => 0, 'jun' => 1]),
        ]);

        // The result is annulled and the event awarded to the other side
        $corrected = $state->withResultReplaced(
            new Result($pairing->getEvents()[0], $this->ines, award: new Award('ruling'))
        );

        expect(count($corrected->getResults()))->toBe(1)
            ->and($corrected->getResults()[0]->isAwarded())->toBeTrue()
            ->and($corrected->getResults()[0]->getWinner())->toBe($this->ines);
    });
});
