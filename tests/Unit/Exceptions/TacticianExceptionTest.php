<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MetadataConstraint;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\Constraints\RoleBalanceConstraint;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\InvariantViolationException;
use MissionGaming\Tactician\Exceptions\JsonConversionException;
use MissionGaming\Tactician\Exceptions\SchedulingException;
use MissionGaming\Tactician\Exceptions\TacticianException;
use MissionGaming\Tactician\Exceptions\UnavailableValueException;
use MissionGaming\Tactician\Repack\SlotAssignment;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Timeline\ScheduledEvent;
use MissionGaming\Tactician\Timeline\ScheduledSchedule;

// What a caller sees: one catch clause for the whole library, and every
// catch clause written before the marker existed still matching, with the
// message it matched on. tests/Feature/ExceptionMarkerTest.php proves the
// rule for every throw in `src/`; this file shows it through the public API
// on a set of sites from each area.
//
// The messages below are the ones these sites had when they threw the SPL
// type directly. They are written out, not read back from the code: a
// downstream consumer matches on them.
//
// Gap left knowingly - the three InvariantViolationException sites
// (RoundRobinScheduler, DoubleEliminationEngine) guard states the code rules
// out, so no input reaches them. Their class is tested below and their
// throw sites by the architecture test.

/**
 * What a call throws, and which catch clauses catch it. Each type is tried
 * with a real catch clause, because that is the construct a caller's code
 * depends on.
 *
 * @param Closure(): mixed $call
 * @return array{thrown: ?Throwable, caughtBy: list<class-string<Throwable>>}
 */
function tacticianOutcome(Closure $call): array
{
    $thrown = null;
    $caughtBy = [];

    try {
        $call();
    } catch (TacticianException $exception) {
        $thrown = $exception;
        $caughtBy[] = TacticianException::class;
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    try {
        $call();
    } catch (SchedulingException) {
        $caughtBy[] = SchedulingException::class;
    } catch (Throwable) {
        // Not caught by this clause.
    }

    try {
        $call();
    } catch (InvalidArgumentException) {
        $caughtBy[] = InvalidArgumentException::class;
    } catch (Throwable) {
        // Not caught by this clause.
    }

    try {
        $call();
    } catch (LogicException) {
        $caughtBy[] = LogicException::class;
    } catch (Throwable) {
        // Not caught by this clause.
    }

    try {
        $call();
    } catch (JsonException) {
        $caughtBy[] = JsonException::class;
    } catch (Throwable) {
        // Not caught by this clause.
    }

    return ['thrown' => $thrown, 'caughtBy' => $caughtBy];
}

describe('TacticianException', function (): void {
    beforeEach(function (): void {
        $this->field = [new Participant('p1', 'Alice'), new Participant('p2', 'Bob'), new Participant('p3', 'Carol')];
    });

    it('catches a rejected argument, which is still an InvalidArgumentException with its message', function (Closure $call, string $message): void {
        $outcome = tacticianOutcome(fn() => $call(...$this->field));
        $thrown = $outcome['thrown'];

        expect($thrown)->toBeInstanceOf(InvalidInputException::class);
        expect($thrown?->getMessage())->toBe($message);
        expect($thrown?->getCode())->toBe(0);
        expect($thrown?->getPrevious())->toBeNull();

        // Not a SchedulingException: a rejected argument never was one.
        expect($outcome['caughtBy'])->toBe([TacticianException::class, InvalidArgumentException::class, LogicException::class]);
    })->with([
        'Event: too few participants' => [
            fn(Participant $alice) => new Event([$alice]),
            'An event must have at least 2 participants',
        ],
        'Event::fromArray: no participants' => [
            fn() => Event::fromArray([], []),
            'Event data requires a participants array',
        ],
        'Round: number not positive' => [
            fn() => new Round(0),
            'Round number must be positive',
        ],
        'Round::fromArray: no number' => [
            fn() => Round::fromArray([]),
            'Round data requires an integer number',
        ],
        'Result: winner outside the event' => [
            fn(Participant $alice, Participant $bob, Participant $carol) => new Result(new Event([$alice, $bob]), $carol),
            'Winner must be a participant in the event',
        ],
        'Result::fromArray: no event' => [
            fn() => Result::fromArray([], []),
            'Result data requires an event array',
        ],
        'Participant::fromArray: no id' => [
            fn() => Participant::fromArray([]),
            'Participant data requires a non-empty string id and a string label',
        ],
        'Schedule::fromArray: participants not an array' => [
            fn() => Schedule::fromArray(['participants' => 'nope']),
            'Schedule participants must be an array',
        ],
        'Schedule::fromJson: valid JSON of the wrong shape' => [
            fn() => Schedule::fromJson('"nope"'),
            'Schedule JSON must decode to an object',
        ],
        'StageState::fromArray: participants not an array' => [
            fn() => StageState::fromArray(['participants' => 'nope']),
            'Stage state participants must be an array',
        ],
        'StageState::fromJson: valid JSON of the wrong shape' => [
            fn() => StageState::fromJson('"nope"'),
            'Stage state JSON must decode to an array',
        ],
        'RoundPairing::fromArray: no round number' => [
            fn() => RoundPairing::fromArray([], []),
            'Round pairing data requires an integer round number',
        ],
        'ScheduledEvent::fromArray: no event' => [
            fn() => ScheduledEvent::fromArray([], []),
            'Scheduled event data requires an event array',
        ],
        'ScheduledSchedule::fromArray: participants not an array' => [
            fn() => ScheduledSchedule::fromArray(['participants' => 'nope']),
            'Scheduled schedule participants must be an array',
        ],
        'ScheduledSchedule::fromJson: valid JSON of the wrong shape' => [
            fn() => ScheduledSchedule::fromJson('"nope"'),
            'Scheduled schedule JSON must decode to an array',
        ],
        'MinimumRestPeriodsConstraint: below 1' => [
            fn() => new MinimumRestPeriodsConstraint(0),
            'Minimum rest periods must be at least 1',
        ],
        'ConsecutiveRoleConstraint: limit below 1' => [
            fn() => new ConsecutiveRoleConstraint(0, fn(): string => 'home'),
            'Max consecutive must be at least 1',
        ],
        'ConsecutiveRoleConstraint: extractor not callable' => [
            fn() => new ConsecutiveRoleConstraint(1, 'not a callable'),
            'Role extractor must be callable',
        ],
        'SeedProtectionConstraint: no seed' => [
            fn() => new SeedProtectionConstraint(0, 0.5),
            'Must protect at least 1 seed',
        ],
        'SeedProtectionConstraint: period out of range' => [
            fn() => new SeedProtectionConstraint(1, 1.5),
            'Protection period must be between 0.0 and 1.0',
        ],
        'MetadataConstraint: validator not callable' => [
            fn() => new MetadataConstraint('region', 'not a callable'),
            'Validator must be callable',
        ],
        'RoleBalanceConstraint: imbalance below 1' => [
            fn() => new RoleBalanceConstraint(0),
            'Max imbalance must be at least 1',
        ],
        'SwissPairingEngine: planned rounds below 1' => [
            fn() => new SwissPairingEngine(plannedRounds: 0),
            'Planned rounds must be at least 1',
        ],
        'StandingsCalculator: a result for a participant outside the table' => [
            fn(Participant $alice, Participant $bob, Participant $carol) => (new StandingsCalculator())->calculate(
                [$alice, $bob],
                [new Result(new Event([$alice, $carol]), $alice)],
            ),
            'Result references participant p3 who is not in the standings',
        ],
        'StandingsCalculator: two results for one event' => [
            function (Participant $alice, Participant $bob) {
                $event = new Event([$alice, $bob]);

                return (new StandingsCalculator())->calculate(
                    [$alice, $bob],
                    [new Result($event, $alice), new Result($event, $bob)],
                );
            },
            'Two results reference the same event; each event can have only one result',
        ],
    ]);

    it('keeps the cause of an unparseable kickoff as the previous exception', function (): void {
        [$alice, $bob] = $this->field;
        $outcome = tacticianOutcome(fn() => ScheduledEvent::fromArray(
            ['event' => ['participants' => ['p1', 'p2']], 'kickoff' => 'not a date'],
            ['p1' => $alice, 'p2' => $bob],
        ));
        $thrown = $outcome['thrown'];

        expect($thrown)->toBeInstanceOf(InvalidInputException::class);
        expect($thrown?->getMessage())->toBe('Scheduled event kickoff is not parseable');
        expect($thrown?->getCode())->toBe(0);
        // PHP's own parse failure, as before.
        expect($thrown?->getPrevious())->toBeInstanceOf(Exception::class);
        expect($thrown?->getPrevious())->not->toBeInstanceOf(TacticianException::class);

        expect($outcome['caughtBy'])->toBe([TacticianException::class, InvalidArgumentException::class, LogicException::class]);
    });

    it('catches unreadable JSON, which is still a JsonException with PHP\'s message and code', function (Closure $call): void {
        $outcome = tacticianOutcome($call);
        $thrown = $outcome['thrown'];

        expect($thrown)->toBeInstanceOf(JsonConversionException::class);
        expect($thrown?->getMessage())->toBe('Syntax error');
        expect($thrown?->getCode())->toBe(JSON_ERROR_SYNTAX);

        // The exception PHP raised, itself and not a wrapper of it.
        $previous = $thrown?->getPrevious();
        expect($previous === null ? null : $previous::class)->toBe(JsonException::class);
        expect($previous?->getMessage())->toBe('Syntax error');
        expect($previous?->getCode())->toBe(JSON_ERROR_SYNTAX);

        // Not an InvalidArgumentException: invalid JSON never was one.
        expect($outcome['caughtBy'])->toBe([TacticianException::class, JsonException::class]);
    })->with([
        'Schedule::fromJson' => [fn() => Schedule::fromJson('{not json')],
        'StageState::fromJson' => [fn() => StageState::fromJson('{not json')],
        'ScheduledSchedule::fromJson' => [fn() => ScheduledSchedule::fromJson('{not json')],
    ]);

    it('catches a value JSON cannot represent, which is still a JsonException with PHP\'s message and code', function (Closure $call): void {
        $outcome = tacticianOutcome(fn() => $call(...$this->field));
        $thrown = $outcome['thrown'];

        expect($thrown)->toBeInstanceOf(JsonConversionException::class);
        expect($thrown?->getMessage())->toBe('Malformed UTF-8 characters, possibly incorrectly encoded');
        expect($thrown?->getCode())->toBe(JSON_ERROR_UTF8);

        $previous = $thrown?->getPrevious();
        expect($previous === null ? null : $previous::class)->toBe(JsonException::class);

        expect($outcome['caughtBy'])->toBe([TacticianException::class, JsonException::class]);
    })->with([
        // "\xB1" is not valid UTF-8, so the metadata has no JSON form.
        'Schedule::toJson' => [
            fn(Participant $alice, Participant $bob) => (new Schedule([new Event([$alice, $bob])], ['note' => "\xB1"]))->toJson(),
        ],
        'StageState::toJson' => [
            fn(Participant $alice) => StageState::start([$alice, new Participant('bad', 'Bad', null, ['note' => "\xB1"])])->toJson(),
        ],
        'ScheduledSchedule::toJson' => [
            fn(Participant $alice) => (new ScheduledSchedule([
                new ScheduledEvent(
                    new Event([$alice, new Participant('bad', 'Bad', null, ['note' => "\xB1"])]),
                    new DateTimeImmutable('2026-01-01T12:00:00Z'),
                ),
            ]))->toJson(),
        ],
    ]);

    it('keeps whichever message and code PHP reported for the JSON', function (Closure $call, string $message, int $code): void {
        $thrown = tacticianOutcome(fn() => $call(...$this->field))['thrown'];

        expect($thrown)->toBeInstanceOf(JsonConversionException::class);
        expect($thrown?->getMessage())->toBe($message);
        expect($thrown?->getCode())->toBe($code);
        expect($thrown?->getPrevious()?->getCode())->toBe($code);
    })->with([
        // Nested deeper than the 512 levels json_decode() reads.
        'fromJson: nested too deep' => [
            fn() => Schedule::fromJson(str_repeat('[', 600) . str_repeat(']', 600)),
            'Maximum stack depth exceeded',
            JSON_ERROR_DEPTH,
        ],
        'fromJson: an empty string' => [fn() => StageState::fromJson(''), 'Syntax error', JSON_ERROR_SYNTAX],
        'toJson: a number JSON has no form for' => [
            fn(Participant $alice, Participant $bob) => (new Schedule([new Event([$alice, $bob])], ['ratio' => INF]))->toJson(),
            'Inf and NaN cannot be JSON encoded',
            JSON_ERROR_INF_OR_NAN,
        ],
    ]);

    it('reports serialized data as an InvalidInputException wherever it is malformed', function (Closure $build, Closure $restore): void {
        // Every field of a valid serialized form, in turn, removed and then
        // replaced by each of a set of wrong values. A `fromArray()` method
        // must either accept the result or reject it with the library's
        // exception: a \TypeError or a warning from deeper in is a failure.
        $valid = json_decode($build(...$this->field)->toJson(), true, 512, JSON_THROW_ON_ERROR);
        $wrong = [
            null, 0, -1, 1, 1.5, '', 'x', 'p1', '7', 7, true, false, PHP_INT_MAX, "\0",
            [], [1], [[1]], ['a' => 1], ['id' => 'p1', 'label' => 'Alice'], ['participants' => ['p1', 'p2']], ['number' => 1],
        ];

        $paths = function (array $data, array $prefix = []) use (&$paths): array {
            $found = [];
            foreach ($data as $key => $value) {
                $found[] = [...$prefix, $key];
                if (is_array($value)) {
                    $found = [...$found, ...$paths($value, [...$prefix, $key])];
                }
            }

            return $found;
        };
        $changed = function (array $data, array $path, mixed $value, bool $remove) {
            $cursor = &$data;
            $last = array_pop($path);
            foreach ($path as $key) {
                $cursor = &$cursor[$key];
            }
            if ($remove) {
                unset($cursor[$last]);
            } else {
                $cursor[$last] = $value;
            }

            return $data;
        };

        expect($restore($valid)->toArray())->toEqual($valid);

        $rejected = 0;
        foreach ($paths($valid) as $path) {
            $variants = [$changed($valid, $path, null, true)];
            foreach ($wrong as $value) {
                $variants[] = $changed($valid, $path, $value, false);
            }

            foreach ($variants as $variant) {
                try {
                    $restore($variant);
                } catch (InvalidInputException) {
                    ++$rejected;
                } catch (Throwable $escaped) {
                    $this->fail(sprintf(
                        '%s escaped for %s at %s: %s',
                        $escaped::class,
                        json_encode($variant, JSON_PARTIAL_OUTPUT_ON_ERROR),
                        implode('.', $path),
                        $escaped->getMessage(),
                    ));
                }
            }
        }

        // Most wrong values are rejected, so the loop above did run its catch.
        expect($rejected)->toBeGreaterThan(100);
    })->with([
        'Schedule' => [
            fn(Participant $alice, Participant $bob, Participant $carol) => new Schedule([
                new Event([$alice, $bob], new Round(1, ['stage' => 'group']), ['court' => 1]),
                new Event([$bob, $carol], new Round(2)),
            ], ['season' => 2026]),
            Schedule::fromArray(...),
        ],
        'StageState' => [
            function (Participant $alice, Participant $bob, Participant $carol) {
                $event = new Event([$alice, $bob], new Round(1));

                return StageState::start([$alice, $bob, $carol])->withRoundPlayed(
                    new RoundPairing(1, 'round 1', [$event], [$carol]),
                    [new Result($event, $alice, ['p1' => 2, 'p2' => 1], ['note' => 'walkover'])],
                );
            },
            StageState::fromArray(...),
        ],
        'ScheduledSchedule' => [
            fn(Participant $alice, Participant $bob, Participant $carol) => new ScheduledSchedule([
                new ScheduledEvent(new Event([$alice, $bob], new Round(1)), new DateTimeImmutable('2026-01-01T12:00:00Z'), 'court 1'),
                new ScheduledEvent(new Event([$bob, $carol], new Round(2)), new DateTimeImmutable('2026-01-08T12:00:00Z')),
            ]),
            ScheduledSchedule::fromArray(...),
        ],
    ]);

    it('leaves an exception from code the caller supplied as it is', function (): void {
        [$alice, $bob, $carol] = $this->field;

        // A constraint predicate of the caller's own.
        $failing = ConstraintSet::create()
            ->custom(fn(Event $event): bool => throw new DomainException('predicate failed'), 'Failing')
            ->build();
        $fromPredicate = tacticianOutcome(fn() => (new RoundRobinScheduler($failing))->schedule([$alice, $bob, $carol]));

        expect($fromPredicate['thrown'])->toBeInstanceOf(DomainException::class);
        expect($fromPredicate['thrown']?->getMessage())->toBe('predicate failed');
        expect($fromPredicate['caughtBy'])->toBe([LogicException::class]);

        // The jsonSerialize() of an object placed in metadata: PHP hands its
        // exception on unwrapped, so it is not a JsonConversionException.
        $unserializable = new class implements JsonSerializable {
            /**
             * @throws UnexpectedValueException Always
             */
            #[Override]
            public function jsonSerialize(): never
            {
                throw new UnexpectedValueException('not serializable');
            }
        };
        $fromMetadata = tacticianOutcome(fn() => (new Schedule([new Event([$alice, $bob])], ['object' => $unserializable]))->toJson());

        expect($fromMetadata['thrown'])->toBeInstanceOf(UnexpectedValueException::class);
        expect($fromMetadata['thrown']?->getMessage())->toBe('not serializable');
        expect($fromMetadata['caughtBy'])->toBe([]);
    });

    it('catches the scheduling failures, which are still SchedulingExceptions', function (): void {
        [$alice, $bob, $carol] = $this->field;

        $tooFew = tacticianOutcome(fn() => (new RoundRobinScheduler())->schedule([$alice]));

        expect($tooFew['thrown'])->toBeInstanceOf(InvalidConfigurationException::class);
        expect($tooFew['caughtBy'])->toBe([TacticianException::class, SchedulingException::class]);

        // A round robin needs every pair to meet; forbidding one cannot be satisfied.
        $forbidden = ConstraintSet::create()
            ->custom(fn(Event $event): bool => !($event->hasParticipant($alice) && $event->hasParticipant($bob)), 'Ban')
            ->build();
        $incomplete = tacticianOutcome(fn() => (new RoundRobinScheduler($forbidden))->schedule([$alice, $bob, $carol]));

        expect($incomplete['thrown'])->toBeInstanceOf(IncompleteScheduleException::class);
        expect($incomplete['caughtBy'])->toBe([TacticianException::class, SchedulingException::class]);
    });

    it('gives each library exception the parent type it replaces', function (): void {
        expect(get_parent_class(InvalidInputException::class))->toBe(InvalidArgumentException::class);
        expect(get_parent_class(JsonConversionException::class))->toBe(JsonException::class);
        expect(get_parent_class(SchedulingException::class))->toBe(Exception::class);
        // LogicException itself: an internal defect is not a rejected argument.
        expect(get_parent_class(InvariantViolationException::class))->toBe(LogicException::class);
        // LogicException as well, and so unchecked: a caller's mistake, which
        // the object's own has...() method would have prevented.
        expect(get_parent_class(UnavailableValueException::class))->toBe(LogicException::class);
        expect((new ReflectionClass(UnavailableValueException::class))->isFinal())->toBeTrue();
    });

    it('is caught as a TacticianException and as a LogicException when an object is asked for a value it does not hold', function (): void {
        $outcome = tacticianOutcome(fn() => (new SlotAssignment('e1', 0, 0, null))->getKickoff());

        expect($outcome['thrown'])->toBeInstanceOf(UnavailableValueException::class);
        expect($outcome['caughtBy'])->toBe([TacticianException::class, LogicException::class]);
    });

    it('constructs the SPL subclasses with the SPL arguments', function (): void {
        $cause = new RuntimeException('cause');
        $exceptions = [
            new InvalidInputException('message', 7, $cause),
            new InvariantViolationException('message', 7, $cause),
            new JsonConversionException('message', 7, $cause),
            new UnavailableValueException('message', 7, $cause),
        ];

        foreach ($exceptions as $exception) {
            expect($exception)->toBeInstanceOf(TacticianException::class);
            expect($exception->getMessage())->toBe('message');
            expect($exception->getCode())->toBe(7);
            expect($exception->getPrevious())->toBe($cause);
        }
    });

    it('is caught as a TacticianException and as a LogicException when an invariant is broken', function (): void {
        $outcome = tacticianOutcome(fn() => throw new InvariantViolationException('Grand final resolved without a winner'));

        expect($outcome['caughtBy'])->toBe([TacticianException::class, LogicException::class]);
    });

    it('adds no method to the exceptions that implement it', function (): void {
        expect((new ReflectionClass(TacticianException::class))->getMethods())
            ->toHaveCount(count((new ReflectionClass(Throwable::class))->getMethods()));
    });
});
