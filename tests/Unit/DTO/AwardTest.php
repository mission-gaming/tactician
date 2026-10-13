<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Award;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidInputException;

// An awarded result is a result of an event that was not played. The mark is
// optional on Result and absent from the array of a played result, so that
// every result stored before the mark existed reads as played.

describe('Award', function (): void {
    it('holds a label and a reason, and neither by default', function (): void {
        $award = new Award('no-show', 'The squad did not check in before the deadline');

        expect($award->getLabel())->toBe('no-show')
            ->and($award->getReason())->toBe('The squad did not check in before the deadline')
            ->and((new Award())->getLabel())->toBeNull()
            ->and((new Award())->getReason())->toBeNull();
    });

    it('round-trips through its array form, with the version and both keys always written', function (): void {
        $award = new Award('ruling', 'Board decision 14');

        expect($award->toArray())->toBe(['format_version' => 1, 'label' => 'ruling', 'reason' => 'Board decision 14'])
            ->and((new Award())->toArray())->toBe(['format_version' => 1, 'label' => null, 'reason' => null])
            ->and(Award::fromArray($award->toArray()))->toEqual($award)
            ->and(Award::fromArray([]))->toEqual(new Award())
            ->and(Award::fromArray(['reason' => 'only a reason']))->toEqual(new Award(null, 'only a reason'));
    });

    it('refuses a key it does not have', function (): void {
        Award::fromArray(['label' => 'ruling', 'reson' => 'misspelt']);
    })->throws(InvalidInputException::class, "Unknown key in award data: 'reson'");

    it('refuses a label or a reason that is not a string', function (array $data): void {
        Award::fromArray($data);
    })->with([
        'a number as the label' => [['label' => 7]],
        'a list as the reason' => [['reason' => ['late']]],
    ])->throws(InvalidInputException::class, 'Award label and reason must each be a string or null');
});

describe('An awarded Result', function (): void {
    beforeEach(function (): void {
        $this->nova = new Participant('nova', 'Nova');
        $this->orbit = new Participant('orbit', 'Orbit');
        $this->event = new Event([$this->nova, $this->orbit], new Round(2));
        $this->registry = ['nova' => $this->nova, 'orbit' => $this->orbit];
    });

    it('is played unless it carries the mark', function (): void {
        $played = new Result($this->event, $this->nova, ['nova' => 2, 'orbit' => 1]);
        $award = new Award('no-show');
        $awarded = new Result($this->event, $this->nova, ['nova' => 3, 'orbit' => 0], award: $award);

        expect($played->isAwarded())->toBeFalse()
            ->and($played->getAward())->toBeNull()
            ->and($awarded->isAwarded())->toBeTrue()
            ->and($awarded->getAward())->toBe($award);
    });

    it('is a result in every other respect: a winner or a draw, and the scores given', function (): void {
        $awarded = new Result($this->event, $this->orbit, ['nova' => 0, 'orbit' => 3], award: new Award());
        $awardedLevel = new Result($this->event, award: new Award());

        expect($awarded->getWinner())->toBe($this->orbit)
            ->and($awarded->isWinFor($this->orbit))->toBeTrue()
            ->and($awarded->isWinFor($this->nova))->toBeFalse()
            ->and($awarded->isDraw())->toBeFalse()
            ->and($awarded->getScores())->toBe(['nova' => 0, 'orbit' => 3])
            ->and($awardedLevel->isDraw())->toBeTrue()
            ->and($awardedLevel->isAwarded())->toBeTrue();
    });

    it('still refuses a winner who is not in the event', function (): void {
        new Result($this->event, new Participant('x', 'Outsider'), award: new Award());
    })->throws(InvalidInputException::class, 'Winner must be a participant in the event');

    it('writes the award key only when it is awarded', function (): void {
        $played = new Result($this->event, $this->nova);
        $awarded = new Result($this->event, $this->nova, award: new Award('no-show', 'Did not check in'));

        // The award is a part of the result: it carries no version of its own
        expect(array_keys($played->toArray()))->toBe(['format_version', 'event', 'winner', 'scores', 'metadata'])
            ->and(array_keys($awarded->toArray()))->toBe(['format_version', 'event', 'winner', 'scores', 'metadata', 'award'])
            ->and($awarded->toArray()['award'] ?? null)->toBe(['label' => 'no-show', 'reason' => 'Did not check in']);
    });

    it('round-trips through its array form and through JSON', function (): void {
        $awarded = new Result(
            $this->event,
            $this->nova,
            ['nova' => 3, 'orbit' => 0],
            ['note' => 'kept'],
            new Award('no-show', 'Did not check in')
        );

        $fromArray = Result::fromArray($awarded->toArray(), $this->registry);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($awarded->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $fromJson = Result::fromArray($decoded, $this->registry);

        expect($fromArray->isAwarded())->toBeTrue()
            ->and($fromArray->getAward())->toEqual(new Award('no-show', 'Did not check in'))
            ->and($fromArray->toArray())->toBe($awarded->toArray())
            ->and($fromJson->toArray())->toBe($awarded->toArray());
    });

    it('keeps an award without a label or a reason through its array form', function (): void {
        $awarded = new Result($this->event, $this->orbit, award: new Award());

        $rebuilt = Result::fromArray($awarded->toArray(), $this->registry);

        expect($rebuilt->isAwarded())->toBeTrue()
            ->and($rebuilt->getAward())->toEqual(new Award());
    });

    it('reads an array written before the key existed as a played result', function (): void {
        // The four keys 0.2.x wrote, as it wrote them
        $stored = [
            'event' => ['participants' => ['nova', 'orbit'], 'round' => ['number' => 2, 'metadata' => []], 'metadata' => []],
            'winner' => 'nova',
            'scores' => ['nova' => 2, 'orbit' => 1],
            'metadata' => [],
        ];

        $result = Result::fromArray($stored, $this->registry);

        expect($result->isAwarded())->toBeFalse()
            ->and($result->toArray())->toBe(['format_version' => 1, ...$stored]);
    });

    it('reads a null award as a played result, and an empty array as an awarded one', function (): void {
        $data = (new Result($this->event, $this->nova))->toArray();

        expect(Result::fromArray([...$data, 'award' => null], $this->registry)->isAwarded())->toBeFalse()
            ->and(Result::fromArray([...$data, 'award' => []], $this->registry)->isAwarded())->toBeTrue();
    });

    it('refuses an award that is not an array', function (mixed $award): void {
        $data = (new Result($this->event, $this->nova))->toArray();

        Result::fromArray([...$data, 'award' => $award], $this->registry);
    })->with([
        'true' => [true],
        'a label alone' => ['no-show'],
    ])->throws(InvalidInputException::class, 'Result award must be an array or null');

    it('refuses an award with a key the mark does not have', function (): void {
        $data = (new Result($this->event, $this->nova))->toArray();

        Result::fromArray([...$data, 'award' => ['winner' => 'nova']], $this->registry);
    })->throws(InvalidInputException::class, 'Unknown key in award data');

    it('refuses the award key misspelt, where it used to read the result as played', function (): void {
        $data = (new Result($this->event, $this->nova))->toArray();

        Result::fromArray([...$data, 'awrad' => ['label' => 'no-show', 'reason' => null]], $this->registry);
    })->throws(InvalidInputException::class, "Unknown key in result data: 'awrad'");

    it('reads an award that was serialized by itself, with its version, inside a result', function (): void {
        $data = (new Result($this->event, $this->nova))->toArray();

        $result = Result::fromArray([...$data, 'award' => (new Award('no-show'))->toArray()], $this->registry);

        expect($result->getAward())->toEqual(new Award('no-show'))
            ->and($result->toArray()['award'] ?? null)->toBe(['label' => 'no-show', 'reason' => null]);
    });
});
