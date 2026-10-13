<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Timeline\BlackoutRule;
use MissionGaming\Tactician\Timeline\MinimumRestRule;
use MissionGaming\Tactician\Timeline\ScheduledEvent;
use MissionGaming\Tactician\Timeline\ScheduledSchedule;
use MissionGaming\Tactician\Timeline\TimelineAssigner;
use MissionGaming\Tactician\Timeline\TimelineDefinition;
use MissionGaming\Tactician\Timeline\TimelineRule;

/**
 * @throws DateMalformedStringException
 */
function utc(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time, new DateTimeZone('UTC'));
}

/**
 * @param array<array{0: Participant, 1: Participant, 2: string, 3?: int}> $fixtures [home, away, kickoff, round]
 * @throws DateMalformedStringException
 */
function scheduledFixtures(array $fixtures): ScheduledSchedule
{
    $scheduledEvents = [];
    foreach ($fixtures as $i => [$home, $away, $kickoff]) {
        $round = $fixtures[$i][3] ?? $i + 1;
        $scheduledEvents[] = new ScheduledEvent(new Event([$home, $away], new Round($round)), utc($kickoff));
    }

    return new ScheduledSchedule($scheduledEvents);
}

describe('MinimumRestRule', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->carol = new Participant('p3', 'Carol');
        $this->dave = new Participant('p4', 'Dave');
    });

    it('holds when every participant gets the declared rest', function (): void {
        $rule = new MinimumRestRule(new DateInterval('PT48H'));
        $scheduled = scheduledFixtures([
            [$this->alice, $this->bob, '2026-08-01 18:00'],
            [$this->alice, $this->carol, '2026-08-03 18:00'], // exactly 48h: allowed
        ]);

        expect($rule->validate($scheduled))->toBe([]);
    });

    it('reports each participant whose consecutive kickoffs are too close', function (): void {
        $rule = new MinimumRestRule(new DateInterval('PT48H'));
        $scheduled = scheduledFixtures([
            [$this->alice, $this->bob, '2026-08-01 18:00'],
            [$this->alice, $this->carol, '2026-08-02 18:00'], // 24h for Alice
            [$this->bob, $this->dave, '2026-08-05 18:00'],
        ]);

        $violations = $rule->validate($scheduled);

        expect($violations)->toHaveCount(1);
        expect($violations[0])->toContain('Alice');
        expect($violations[0])->toContain('minimum rest is PT48H');
    });

    // Rest compares UTC instants, so any positive rest forbids two
    // kickoffs at the same instant - double-booking is a special case
    it('treats simultaneous kickoffs as a rest violation', function (): void {
        $rule = new MinimumRestRule(new DateInterval('PT1H'));
        $scheduled = scheduledFixtures([
            [$this->alice, $this->bob, '2026-08-01 18:00'],
            [$this->alice, $this->carol, '2026-08-01 18:00', 2],
        ]);

        expect($rule->validate($scheduled))->toHaveCount(1);
    });

    it('is config-constructible and rejects hollow durations', function (): void {
        $rule = MinimumRestRule::fromArray(['rest' => 'PT48H']);
        expect($rule->toArray())->toBe(['rest' => 'PT48H']);
        expect($rule->getName())->toBe('Minimum Rest (PT48H)');

        expect(fn() => MinimumRestRule::fromArray(['rest' => 'whenever']))
            ->toThrow(InvalidConfigurationException::class, 'ISO 8601');
        expect(fn() => new MinimumRestRule(new DateInterval('PT0S')))
            ->toThrow(InvalidConfigurationException::class, 'move time forward');
    });

    // The rest was written as formatInterval() shows it, and that method
    // shows a fraction, a sign and a relative date string in forms
    // fromArray() refuses: the rule was written as data that did not load.
    it('refuses to write a rest that fromArray() could not read', function (DateInterval $rest, string $shown): void {
        $rule = new MinimumRestRule($rest);

        try {
            $rule->toArray();
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::NotSerializable)
                ->and($exception->getContext())->toBe(['rest' => $shown])
                // Refusing to write it does not refuse to use it
                ->and($rule->getName())->toBe("Minimum Rest ({$shown})");

            return;
        }

        $this->fail('A rest was written that fromArray() refuses: ' . json_encode($rule->toArray()));
    })->with([
        'a relative date string that is not amounts of time' => [DateInterval::createFromDateString('next weekday'), 'next weekday'],
        'a fraction of a second' => [(static function (): DateInterval {
            $rest = new DateInterval('PT1S');
            $rest->f = 0.5;

            return $rest;
        })(), 'PT1.5S'],
        'a negative part' => [(static function (): DateInterval {
            $rest = new DateInterval('P2D');
            $rest->h = -1;

            return $rest;
        })(), 'P2DT-1H'],
    ]);

    // The rule adds its rest once, in UTC, where local time and elapsed
    // time are one: the amounts of such an interval are its duration
    it('writes a rest of amounts of time from a relative date string or from diff(), and reads it back', function (
        DateInterval $rest,
        string $written
    ): void {
        $rule = new MinimumRestRule($rest);
        $readBack = MinimumRestRule::fromArray($rule->toArray());

        [$a, $b] = [new Participant('a', 'A'), new Participant('b', 'B')];
        $from = utc('2026-10-24 12:00');
        $timeline = static fn(DateTimeImmutable $second): ScheduledSchedule => new ScheduledSchedule([
            new ScheduledEvent(new Event([$a, $b], new Round(1)), $from),
            new ScheduledEvent(new Event([$b, $a], new Round(2)), $second),
        ]);
        $exactly = $from->add($rest);

        expect($rule->toArray())->toBe(['rest' => $written])
            ->and($readBack->validate($timeline($exactly)))->toBe([])
            ->and($readBack->validate($timeline($exactly->modify('-1 second'))))->toHaveCount(2);
    })->with([
        'hours from a relative date string' => [DateInterval::createFromDateString('48 hours'), 'PT48H'],
        'a day from a relative date string' => [DateInterval::createFromDateString('1 day'), 'P1D'],
        'hours from diff()' => [(new DateTimeImmutable('2026-01-01 00:00'))->diff(new DateTimeImmutable('2026-01-03 06:00')), 'P2DT6H'],
    ]);
});

describe('BlackoutRule', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
    });

    it('reports kickoffs inside a window with half-open bounds', function (): void {
        $rule = new BlackoutRule([[
            'from' => utc('2026-11-09 00:00'),
            'to' => utc('2026-11-17 00:00'),
            'label' => 'international break',
        ]]);

        $inside = scheduledFixtures([[$this->alice, $this->bob, '2026-11-12 18:00']]);
        $violations = $rule->validate($inside);
        expect($violations)->toHaveCount(1);
        expect($violations[0])->toContain('international break');

        // Half-open: the window start is blacked out, its end is not
        expect($rule->validate(scheduledFixtures([[$this->alice, $this->bob, '2026-11-09 00:00']])))->toHaveCount(1);
        expect($rule->validate(scheduledFixtures([[$this->alice, $this->bob, '2026-11-17 00:00']])))->toBe([]);
        expect($rule->validate(scheduledFixtures([[$this->alice, $this->bob, '2026-11-08 23:59']])))->toBe([]);
    });

    it('normalizes zoned windows to UTC instants', function (): void {
        $london = new DateTimeZone('Europe/London');
        $rule = new BlackoutRule([[
            'from' => new DateTimeImmutable('2026-08-01 19:00', $london), // 18:00 UTC (BST)
            'to' => new DateTimeImmutable('2026-08-01 21:00', $london),   // 20:00 UTC
        ]]);

        expect($rule->validate(scheduledFixtures([[$this->alice, $this->bob, '2026-08-01 18:30']])))->toHaveCount(1);
        expect($rule->validate(scheduledFixtures([[$this->alice, $this->bob, '2026-08-01 17:30']])))->toBe([]);
    });

    it('round-trips through plain configuration data', function (): void {
        $rule = BlackoutRule::fromArray(['windows' => [[
            'from' => '2026-11-09 00:00:00',
            'to' => '2026-11-17 00:00:00',
            'timezone' => 'UTC',
            'label' => 'international break',
        ]]]);

        expect($rule->toArray())->toBe(['windows' => [[
            'from' => '2026-11-09 00:00:00',
            'to' => '2026-11-17 00:00:00',
            'timezone' => 'UTC',
            'label' => 'international break',
        ]]]);
        expect($rule->getName())->toBe('Blackout Windows (1)');
    });

    it('rejects malformed windows', function (): void {
        expect(fn() => new BlackoutRule([]))
            ->toThrow(InvalidConfigurationException::class, 'at least one window');
        expect(fn() => new BlackoutRule([[
            'from' => utc('2026-11-17 00:00'),
            'to' => utc('2026-11-09 00:00'),
        ]]))->toThrow(InvalidConfigurationException::class, 'end after they start');

        expect(fn() => BlackoutRule::fromArray(['windows' => []]))
            ->toThrow(InvalidConfigurationException::class, 'non-empty');
        expect(fn() => BlackoutRule::fromArray(['windows' => ['nope']]))
            ->toThrow(InvalidConfigurationException::class, 'must be an array');
        expect(fn() => BlackoutRule::fromArray(['windows' => [[
            'from' => '2026-11-09 00:00:00Z',
            'to' => '2026-11-17 00:00:00',
            'timezone' => 'Europe/London',
        ]]]))->toThrow(InvalidConfigurationException::class, 'carries its own timezone');
        expect(fn() => BlackoutRule::fromArray(['windows' => [[
            'from' => '2026-11-09 00:00:00',
            'to' => '2026-11-17 00:00:00',
            'timezone' => 'UTC',
            'label' => 42,
        ]]]))->toThrow(InvalidConfigurationException::class, 'labels must be strings');
    });

    // Nothing forecloses N-participant events: diagnostics name every
    // participant rather than assuming a pairwise event
    it('names every participant of an N-participant event in violations', function (): void {
        $carol = new Participant('p3', 'Carol');
        $rule = new BlackoutRule([[
            'from' => utc('2026-11-09 00:00'),
            'to' => utc('2026-11-17 00:00'),
        ]]);

        $scheduled = new ScheduledSchedule([new ScheduledEvent(
            new Event([$this->alice, $this->bob, $carol], new Round(1)),
            utc('2026-11-10 18:00')
        )]);

        $violations = $rule->validate($scheduled);
        expect($violations)->toHaveCount(1);
        expect($violations[0])->toContain('Alice vs Bob vs Carol');
    });

    // The default label counts the windows; it added 1 to the array key,
    // which is a number only in a list.
    it('labels an unlabelled window by its position, whatever the array keys are', function (array $keys, array $labels): void {
        $windows = [];
        foreach ($keys as $offset => $key) {
            $windows[$key] = [
                'from' => utc(sprintf('2026-11-%02d 00:00', 2 * $offset + 1)),
                'to' => utc(sprintf('2026-11-%02d 00:00', 2 * $offset + 2)),
            ];
        }

        expect(array_column((new BlackoutRule($windows))->toArray()['windows'], 'label'))->toBe($labels);
    })->with([
        'a list' => [[0, 1], ['blackout 1', 'blackout 2']],
        'string keys' => [['break', 'closure'], ['blackout 1', 'blackout 2']],
        'sparse integer keys' => [[3, 7], ['blackout 1', 'blackout 2']],
        'a numeric and a string key' => [[5, 'closure'], ['blackout 1', 'blackout 2']],
    ]);

    it('keeps a label that is given beside one that is not, under string keys', function (): void {
        $rule = new BlackoutRule([
            'break' => ['from' => utc('2026-11-01 00:00'), 'to' => utc('2026-11-02 00:00'), 'label' => 'international break'],
            'closure' => ['from' => utc('2026-11-03 00:00'), 'to' => utc('2026-11-04 00:00')],
        ]);

        expect(array_column($rule->toArray()['windows'], 'label'))->toBe(['international break', 'blackout 2']);
    });

    // A bound was written to the second, so a window inside one second was
    // written as one that ends when it starts, which fromArray() refuses
    it('writes a fraction of a second of a bound, and reads the window back', function (): void {
        $rule = new BlackoutRule([[
            'from' => new DateTimeImmutable('2026-11-01 12:00:00.250000', new DateTimeZone('Europe/London')),
            'to' => new DateTimeImmutable('2026-11-01 12:00:00.750000', new DateTimeZone('Europe/London')),
        ]]);

        $data = $rule->toArray();

        expect($data['windows'][0]['from'])->toBe('2026-11-01 12:00:00.250000')
            ->and($data['windows'][0]['to'])->toBe('2026-11-01 12:00:00.750000')
            ->and(BlackoutRule::fromArray($data)->toArray())->toBe($data);
    });

    it('writes a bound on a whole second as it did', function (): void {
        $rule = new BlackoutRule([['from' => utc('2026-11-01 00:00'), 'to' => utc('2026-11-02 00:00')]]);

        expect($rule->toArray()['windows'][0]['from'])->toBe('2026-11-01 00:00:00');
    });

    it('names the key of a window that does not end after it starts', function (): void {
        try {
            new BlackoutRule(['closure' => ['from' => utc('2026-11-02 00:00'), 'to' => utc('2026-11-01 00:00')]]);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getContext()['window'])->toBe('closure');

            return;
        }

        $this->fail('A window that ends before it starts was accepted.');
    });
});

describe('TimelineAssigner with rules', function (): void {
    it('fails assignment loudly when a rule is violated', function (): void {
        $participants = [];
        for ($i = 1; $i <= 4; ++$i) {
            $participants[] = new Participant("t{$i}", "Team {$i}", $i);
        }
        $schedule = (new RoundRobinScheduler())->schedule($participants);

        // Daily rounds but a 48h rest demand: unsatisfiable by definition
        $timeline = new TimelineDefinition(
            utc('2026-08-01 18:00'),
            new DateInterval('P1D'),
            slotsPerRound: 2,
            slotInterval: new DateInterval('PT2H')
        );
        $assigner = new TimelineAssigner([new MinimumRestRule(new DateInterval('PT48H'))]);

        try {
            $assigner->assign($schedule, $timeline);
            expect(false)->toBeTrue('Expected InvalidConfigurationException was not thrown');
        } catch (InvalidConfigurationException $e) {
            expect($e->getMessage())->toContain('time-rule violation');
            $violations = $e->getContext()['violations'];
            expect($violations)->not->toBeEmpty();
            expect($violations[0])->toContain('[Minimum Rest (PT48H)]');
        }
    });

    it('assigns cleanly when every rule holds', function (): void {
        $participants = [];
        for ($i = 1; $i <= 4; ++$i) {
            $participants[] = new Participant("t{$i}", "Team {$i}", $i);
        }
        $schedule = (new RoundRobinScheduler())->schedule($participants);

        $timeline = new TimelineDefinition(
            utc('2026-08-01 18:00'),
            new DateInterval('P7D'),
            slotsPerRound: 2,
            slotInterval: new DateInterval('PT2H')
        );
        $assigner = new TimelineAssigner([
            new MinimumRestRule(new DateInterval('PT48H')),
            new BlackoutRule([[
                'from' => utc('2026-12-24 00:00'),
                'to' => utc('2026-12-27 00:00'),
                'label' => 'holidays',
            ]]),
        ]);

        expect($assigner->assign($schedule, $timeline))->toHaveCount(6);
    });

    it('collects violations across every configured rule', function (): void {
        $alice = new Participant('p1', 'Alice');
        $bob = new Participant('p2', 'Bob');
        $schedule = new MissionGaming\Tactician\DTO\Schedule([
            new Event([$alice, $bob], new Round(1)),
            new Event([$bob, $alice], new Round(2)),
        ]);

        $timeline = new TimelineDefinition(utc('2026-11-10 18:00'), new DateInterval('P1D'));
        $assigner = new TimelineAssigner([
            new MinimumRestRule(new DateInterval('PT48H')),
            new BlackoutRule([[
                'from' => utc('2026-11-09 00:00'),
                'to' => utc('2026-11-17 00:00'),
                'label' => 'international break',
            ]]),
        ]);

        try {
            $assigner->assign($schedule, $timeline);
            expect(false)->toBeTrue('Expected InvalidConfigurationException was not thrown');
        } catch (InvalidConfigurationException $e) {
            $violations = $e->getContext()['violations'];
            // Two rest violations (both participants) + two blackout hits
            expect(count($violations))->toBe(4);
            $joined = implode(' ', $violations);
            expect($joined)->toContain('Minimum Rest');
            expect($joined)->toContain('international break');
        }
    });

    it('accepts custom rules', function (): void {
        $alice = new Participant('p1', 'Alice');
        $bob = new Participant('p2', 'Bob');
        $schedule = new MissionGaming\Tactician\DTO\Schedule([new Event([$alice, $bob], new Round(1))]);
        $timeline = new TimelineDefinition(utc('2026-08-01 13:00'), new DateInterval('P7D'));

        $noLunchKickoffs = new readonly class implements TimelineRule {
            #[Override]
            public function getName(): string
            {
                return 'No Lunchtime Kickoffs';
            }

            #[Override]
            public function validate(ScheduledSchedule $scheduled): array
            {
                $violations = [];
                foreach ($scheduled->getScheduledEvents() as $scheduledEvent) {
                    if ((int) $scheduledEvent->getKickoff()->format('H') === 13) {
                        $violations[] = 'A 13:00 kickoff is assigned.';
                    }
                }

                return $violations;
            }
        };

        expect(fn() => (new TimelineAssigner([$noLunchKickoffs]))->assign($schedule, $timeline))
            ->toThrow(InvalidConfigurationException::class, 'No Lunchtime Kickoffs');
    });

    it('rejects rule entries that are not timeline rules', function (): void {
        // Config-driven platforms build this array from plain data, so a
        // wrong entry must fail at construction, not fatal mid-assignment
        $notARule = unserialize(serialize('just a string'));

        new TimelineAssigner([$notARule]);
    })->throws(InvalidConfigurationException::class, 'must implement TimelineRule');
});
