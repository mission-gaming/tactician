<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Award;
use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidInputException;

// A decider is the record of a decision made outside the results: who it is
// for, as a list, and an optional label and reason. It is optional on Result
// and absent from the array of a result without one, so that every result
// stored before it existed reads unchanged.

describe('Decider', function (): void {
    it('holds the participants it is for, a label and a reason, and neither of the two by default', function (): void {
        $decider = new Decider(['nova'], 'drawn by lot', 'Level after the event');

        expect($decider->getParticipantIds())->toBe(['nova'])
            ->and($decider->getLabel())->toBe('drawn by lot')
            ->and($decider->getReason())->toBe('Level after the event')
            ->and((new Decider(['nova']))->getLabel())->toBeNull()
            ->and((new Decider(['nova']))->getReason())->toBeNull();
    });

    // ADR 0003: nothing in the type assumes two sides. It holds a list, of
    // any length from one, in the order given
    it('holds a list of participants, of one or of many, in the order given', function (array $ids): void {
        $decider = new Decider($ids);

        expect($decider->getParticipantIds())->toBe($ids)
            ->and(array_is_list($decider->getParticipantIds()))->toBeTrue();
    })->with([
        'one' => [['nova']],
        'two' => [['orbit', 'nova']],
        'five, as the first five of a race would be' => [['e', 'a', 'd', 'b', 'c']],
    ]);

    it('has no accessor that names a side, a winner or a loser', function (): void {
        $methods = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(Decider::class))->getMethods(ReflectionMethod::IS_PUBLIC)
        );
        sort($methods);

        expect($methods)->toBe(['__construct', 'fromArray', 'getLabel', 'getParticipantIds', 'getReason', 'toArray']);
    });

    it('does not keep the keys of the array it is given', function (): void {
        expect((new Decider(['first' => 'nova', 7 => 'orbit']))->getParticipantIds())->toBe(['nova', 'orbit']);
    });

    it('refuses a list that names nobody', function (): void {
        new Decider([]);
    })->throws(InvalidInputException::class, 'A decider needs the ID of at least one participant it is for');

    it('refuses an entry that is not a non-empty ID', function (mixed $entry): void {
        new Decider(['nova', $entry]);
    })->with([
        'an empty string' => [''],
        'an integer, which a database key that was not cast is' => [7],
        'null' => [null],
        'a participant, where its ID is asked for' => [new Participant('orbit', 'Orbit')],
    ])->throws(InvalidInputException::class, 'Each participant of a decider must be a non-empty participant ID');

    it('refuses a participant listed twice', function (): void {
        new Decider(['nova', 'orbit', 'nova']);
    })->throws(InvalidInputException::class, 'A decider lists participant nova twice');

    it('round-trips through its array form, with the version and all three keys always written', function (): void {
        $decider = new Decider(['orbit', 'nova'], 'ruling', 'Board decision 14');

        expect($decider->toArray())->toBe([
            'format_version' => 1,
            'participants' => ['orbit', 'nova'],
            'label' => 'ruling',
            'reason' => 'Board decision 14',
        ])
            ->and((new Decider(['nova']))->toArray())->toBe(['format_version' => 1, 'participants' => ['nova'], 'label' => null, 'reason' => null])
            ->and(Decider::fromArray($decider->toArray()))->toEqual($decider)
            ->and(Decider::fromArray(['participants' => ['nova']]))->toEqual(new Decider(['nova']))
            ->and(Decider::fromArray(['participants' => ['nova'], 'reason' => 'only a reason']))->toEqual(new Decider(['nova'], null, 'only a reason'));
    });

    it('refuses data without its participants', function (array $data): void {
        Decider::fromArray($data);
    })->with([
        'no key at all' => [[]],
        'null' => [['participants' => null]],
        'one ID where a list is asked for' => [['participants' => 'nova']],
    ])->throws(InvalidInputException::class, 'Decider data requires a list of participant IDs');

    it('refuses data whose participants the constructor refuses', function (array $participants, string $message): void {
        expect(fn() => Decider::fromArray(['participants' => $participants]))->toThrow(InvalidInputException::class, $message);
    })->with([
        'an empty list' => [[], 'at least one participant'],
        'an integer' => [[7], 'must be a non-empty participant ID'],
        'a repeat' => [['nova', 'nova'], 'lists participant nova twice'],
    ]);

    it('refuses a key it does not have', function (): void {
        Decider::fromArray(['participants' => ['nova'], 'advancing' => ['nova']]);
    })->throws(InvalidInputException::class, "Unknown key in decider data: 'advancing'");

    it('refuses a label or a reason that is not a string', function (array $data): void {
        Decider::fromArray(['participants' => ['nova'], ...$data]);
    })->with([
        'a number as the label' => [['label' => 7]],
        'a list as the reason' => [['reason' => ['late']]],
    ])->throws(InvalidInputException::class, 'Decider label and reason must each be a string or null');
});

describe('A Result with a decider', function (): void {
    beforeEach(function (): void {
        $this->nova = new Participant('nova', 'Nova');
        $this->orbit = new Participant('orbit', 'Orbit');
        $this->event = new Event([$this->nova, $this->orbit], new Round(2));
        $this->registry = ['nova' => $this->nova, 'orbit' => $this->orbit];
    });

    it('has none unless it is given one', function (): void {
        $decider = new Decider(['orbit']);

        expect((new Result($this->event))->getDecider())->toBeNull()
            ->and((new Result($this->event, null, [], [], null, $decider))->getDecider())->toBe($decider)
            ->and((new Result($this->event, decider: $decider))->getDecider())->toBe($decider);
    });

    // The acceptance criterion: the result still reads 1-1
    it('is the result it is without it: the same winner, the same draw, the same scores', function (): void {
        $scores = ['nova' => 1, 'orbit' => 1];
        $plain = new Result($this->event, null, $scores, ['note' => 'kept']);
        $decided = new Result($this->event, null, $scores, ['note' => 'kept'], null, new Decider(['orbit'], 'drawn by lot'));

        expect($decided->isDraw())->toBeTrue()
            ->and($decided->getWinner())->toBeNull()
            ->and($decided->isWinFor($this->orbit))->toBeFalse()
            ->and($decided->getScores())->toBe($scores)
            ->and($decided->getScoreFor($this->orbit))->toBe(1)
            ->and($decided->getMetadata())->toBe($plain->getMetadata())
            ->and($decided->isAwarded())->toBeFalse();
    });

    it('takes a decider beside a winner and beside an award: what they mean together is the reader\'s to say', function (): void {
        // A leg of a two-legged tie has a winner and may carry the decision
        // of the tie; an awarded event that names nobody may carry one too
        $leg = new Result($this->event, $this->nova, [], [], null, new Decider(['orbit']));
        $awarded = new Result($this->event, null, [], [], new Award('not played'), new Decider(['orbit']));

        expect($leg->getWinner())->toBe($this->nova)
            ->and($leg->getDecider()?->getParticipantIds())->toBe(['orbit'])
            ->and($awarded->isAwarded())->toBeTrue()
            ->and($awarded->getDecider()?->getParticipantIds())->toBe(['orbit']);
    });

    it('refuses a decider that names a participant who is not in the event', function (array $ids): void {
        new Result($this->event, null, [], [], null, new Decider($ids));
    })->with([
        'nobody of the event' => [['ghost']],
        'one of the event and one who is not' => [['nova', 'ghost']],
    ])->throws(InvalidInputException::class, 'Decider references participant ghost who is not in the event');

    it('accepts a decider for every participant of an event of more than two', function (): void {
        $third = new Participant('pulse', 'Pulse');
        $event = new Event([$this->nova, $this->orbit, $third], new Round(1));

        $result = new Result($event, null, [], [], null, new Decider(['pulse', 'nova']));

        expect($result->getDecider()?->getParticipantIds())->toBe(['pulse', 'nova']);
    });

    it('writes the decider key only when it has one, after the award', function (): void {
        $plain = new Result($this->event, $this->nova);
        $decided = new Result($this->event, null, [], [], null, new Decider(['orbit'], 'drawn by lot', 'Level'));
        $both = new Result($this->event, null, [], [], new Award(), new Decider(['orbit']));

        // The decider is a part of the result: it carries no version of its own
        expect(array_keys($plain->toArray()))->toBe(['format_version', 'event', 'winner', 'scores', 'metadata'])
            ->and(array_keys($decided->toArray()))->toBe(['format_version', 'event', 'winner', 'scores', 'metadata', 'decider'])
            ->and($decided->toArray()['decider'] ?? null)->toBe(['participants' => ['orbit'], 'label' => 'drawn by lot', 'reason' => 'Level'])
            ->and(array_keys($both->toArray()))->toBe(['format_version', 'event', 'winner', 'scores', 'metadata', 'award', 'decider']);
    });

    it('round-trips through its array form and through JSON', function (): void {
        $decided = new Result(
            $this->event,
            null,
            ['nova' => 1, 'orbit' => 1],
            ['note' => 'kept'],
            null,
            new Decider(['orbit'], 'drawn by lot', 'Level after the event')
        );

        $fromArray = Result::fromArray($decided->toArray(), $this->registry);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($decided->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $fromJson = Result::fromArray($decoded, $this->registry);

        expect($fromArray->getDecider())->toEqual(new Decider(['orbit'], 'drawn by lot', 'Level after the event'))
            ->and($fromArray->getScores())->toBe(['nova' => 1, 'orbit' => 1])
            ->and($fromArray->isDraw())->toBeTrue()
            ->and($fromArray->toArray())->toBe($decided->toArray())
            ->and($fromJson->toArray())->toBe($decided->toArray());
    });

    it('reads an array written before the key existed as a result without a decider', function (): void {
        // The four keys 0.2.x wrote, as it wrote them, with the metadata
        // key that carried the decision then
        $stored = [
            'event' => ['participants' => ['nova', 'orbit'], 'round' => ['number' => 2, 'metadata' => []], 'metadata' => []],
            'winner' => null,
            'scores' => ['nova' => 1, 'orbit' => 1],
            'metadata' => ['tie_winner' => 'orbit'],
        ];

        $result = Result::fromArray($stored, $this->registry);

        expect($result->getDecider())->toBeNull()
            ->and($result->getMetadataValue('tie_winner'))->toBe('orbit')
            ->and($result->toArray())->toBe(['format_version' => 1, ...$stored]);
    });

    it('reads a null decider as none', function (): void {
        $data = (new Result($this->event))->toArray();

        expect(Result::fromArray([...$data, 'decider' => null], $this->registry)->getDecider())->toBeNull();
    });

    it('refuses a decider that is not an array', function (mixed $decider): void {
        $data = (new Result($this->event))->toArray();

        Result::fromArray([...$data, 'decider' => $decider], $this->registry);
    })->with([
        'true' => [true],
        'an ID alone' => ['orbit'],
    ])->throws(InvalidInputException::class, 'Result decider must be an array or null');

    it('refuses stored data whose decider names a participant who is not in the event', function (): void {
        $data = (new Result($this->event))->toArray();

        Result::fromArray([...$data, 'decider' => ['participants' => ['ghost']]], $this->registry);
    })->throws(InvalidInputException::class, 'Decider references participant ghost who is not in the event');

    it('refuses an empty decider, where an empty award is an award', function (): void {
        $data = (new Result($this->event))->toArray();

        Result::fromArray([...$data, 'decider' => []], $this->registry);
    })->throws(InvalidInputException::class, 'Decider data requires a list of participant IDs');

    it('refuses the decider key misspelt, where it would read the tie as one nobody decided', function (): void {
        $data = (new Result($this->event))->toArray();

        Result::fromArray([...$data, 'decidr' => ['participants' => ['orbit']]], $this->registry);
    })->throws(InvalidInputException::class, "Unknown key in result data: 'decidr'");

    it('reads a decider that was serialized by itself, with its version, inside a result', function (): void {
        $data = (new Result($this->event))->toArray();

        $result = Result::fromArray([...$data, 'decider' => (new Decider(['orbit']))->toArray()], $this->registry);

        expect($result->getDecider())->toEqual(new Decider(['orbit']))
            ->and($result->toArray()['decider'] ?? null)->toBe(['participants' => ['orbit'], 'label' => null, 'reason' => null]);
    });
});
