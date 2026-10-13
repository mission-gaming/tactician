<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Tests\Support\PlainDataForms;

// Two rules that every `fromArray()` holds to, checked for every form at
// once. The forms are listed in Tests\Support\PlainDataForms, each with a
// sample that holds every key it reads.
//
// A key the form does not have is refused. A misspelt option used to be read
// as its default, and a misspelt field of stored data as the field left out,
// with nothing to say so. The tests mistype every key of every sample in
// every way the helper knows and expect each array to be refused.
//
// An array says which version of its form it is written in, under
// `format_version`; without the key it is version 1, and a version the
// release does not know is refused (ADR 0005).

/**
 * What reading an array ends in: the value, or the exception.
 *
 * @param Closure(): mixed $read
 *
 * @return array{value: mixed}|array{error: class-string, message: string}
 */
function plainDataOutcome(Closure $read): array
{
    try {
        return ['value' => $read()];
    } catch (InvalidConfigurationException|InvalidInputException $exception) {
        return ['error' => $exception::class, 'message' => $exception->getMessage()];
    }
}

/**
 * The classes under `src/` that declare the method.
 *
 * @return list<string>
 *
 * @throws UnexpectedValueException When `src/` cannot be read
 */
function classesDeclaring(string $method): array
{
    $root = dirname(__DIR__, 2) . '/src';
    $classes = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        if (preg_match('/^(?:abstract\s+)?(?:final\s+)?(?:readonly\s+)?class\s+\w+/m', $source) !== 1) {
            // An interface declares the method and neither reads nor writes
            continue;
        }
        if (preg_match('/function\s+' . $method . '\s*\(/', $source) === 1) {
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $classes[] = 'MissionGaming\\Tactician\\' . str_replace('/', '\\', $relative);
        }
    }
    sort($classes);

    return $classes;
}

describe('the forms read from plain data', function (): void {
    it('are all listed, one entry at least for every fromArray() in src/', function (): void {
        $listed = array_values(array_unique(array_map(
            static fn(array $form): string => $form['class'],
            PlainDataForms::all()
        )));
        sort($listed);

        expect($listed)->toBe(classesDeclaring('fromArray'))
            ->and(count($listed))->toBeGreaterThan(15);
    });

    it('are all listed, with the forms that are only written, for every toArray() in src/', function (): void {
        $listed = array_values(array_unique([
            ...array_map(static fn(array $form): string => $form['class'], array_values(PlainDataForms::all())),
            ...array_map(static fn(array $form): string => $form['class'], array_values(PlainDataForms::writtenOnly())),
        ]));
        sort($listed);

        expect($listed)->toBe(classesDeclaring('toArray'));
    });

    it('have a sample that is read, and that holds every key of the form', function (string $name): void {
        $form = PlainDataForms::all()[$name];

        $outcome = plainDataOutcome(fn(): object => $form['read']($form['sample'], PlainDataForms::registry()));

        expect($outcome)->toHaveKey('value')
            ->and(array_diff(array_keys($form['sample']), $form['keys']))->toBe([]);

        // The session grid has two forms that share one list of keys
        $sampled = [];
        foreach (PlainDataForms::all() as $other) {
            if ($other['class'] === $form['class'] && $other['keys'] === $form['keys']) {
                $sampled = [...$sampled, ...array_keys($other['sample'])];
            }
        }
        expect(array_values(array_diff($form['keys'], $sampled)))->toBe([]);
    })->with(fn(): array => array_keys(PlainDataForms::all()));
});

describe('a key the form does not have', function (): void {
    it('is refused, however a known key is mistyped', function (string $name): void {
        $form = PlainDataForms::all()[$name];
        $known = ['format_version', ...$form['keys']];
        $checked = 0;

        foreach (array_keys($form['sample']) as $key) {
            foreach (PlainDataForms::typosOf($key, $known) as $typo) {
                $mistyped = PlainDataForms::withKeyRenamed($form['sample'], $key, $typo);

                try {
                    $form['read']($mistyped, PlainDataForms::registry());
                } catch (InvalidConfigurationException $exception) {
                    expect($form['kind'])->toBe(PlainDataForms::CONFIGURATION, "'{$typo}' for '{$key}'")
                        ->and($exception->getReason())->toBe(InvalidConfigurationReason::UnknownOptionKey, "'{$typo}' for '{$key}'")
                        ->and($exception->getContext())->toBe(['unknown' => [$typo], 'known' => $known])
                        ->and($exception->getMessage())->toContain("'{$typo}'");
                    ++$checked;

                    continue;
                } catch (InvalidInputException $exception) {
                    expect($form['kind'])->toBe(PlainDataForms::DATA, "'{$typo}' for '{$key}'")
                        ->and($exception->getMessage())->toContain('Unknown key in ')
                        ->and($exception->getMessage())->toContain("'{$typo}'");
                    foreach ($known as $knownKey) {
                        expect($exception->getMessage())->toContain("'{$knownKey}'");
                    }
                    ++$checked;

                    continue;
                }

                $this->fail("{$name} read an array in which '{$key}' is written '{$typo}'.");
            }
        }

        // Every key has more spellings than it has characters
        expect($checked)->toBeGreaterThan(count($form['sample']) * 3);
    })->with(fn(): array => array_keys(PlainDataForms::all()));

    it('is refused beside a full set of known keys', function (string $name): void {
        $form = PlainDataForms::all()[$name];

        $outcome = plainDataOutcome(fn(): object => $form['read']([...$form['sample'], 'notes' => 'ours'], PlainDataForms::registry()));

        expect($outcome)->toHaveKey('error')
            ->and($outcome['message'] ?? '')->toContain("'notes'");
    })->with(fn(): array => array_keys(PlainDataForms::all()));

    it('is refused in a form nested in another', function (): void {
        $schedule = PlainDataForms::all()['schedule']['sample'];
        assert(is_array($schedule['events']) && is_array($schedule['events'][0]) && is_array($schedule['participants']));

        $inEvent = $schedule;
        $inEvent['events'][0]['rond'] = ['number' => 2, 'metadata' => []];
        $inRound = $schedule;
        $inRound['events'][0]['round'] = ['number' => 1, 'metadata' => [], 'label' => 'final'];
        $inParticipant = $schedule;
        $inParticipant['participants'][0] = ['id' => 'a', 'label' => 'Alice', 'seeding' => 1];

        $read = fn(array $data): object => PlainDataForms::all()['schedule']['read']($data, []);

        expect(fn(): object => $read($inEvent))->toThrow(InvalidInputException::class, "Unknown key in event data: 'rond'")
            ->and(fn(): object => $read($inRound))->toThrow(InvalidInputException::class, "Unknown key in round data: 'label'")
            ->and(fn(): object => $read($inParticipant))->toThrow(InvalidInputException::class, "Unknown key in participant data: 'seeding'");
    });

    // The defect as it was reported: a misspelt strategy gave the defaults
    it('no longer turns a misspelt option into its default', function (): void {
        $read = PlainDataForms::all()['round-robin options']['read'];

        try {
            $read(['legs' => 2, 'stratgy' => 'repeated'], []);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::UnknownOptionKey)
                ->and($exception->getConfigurationIssue())->toBe("Unknown key in round-robin options: 'stratgy'")
                ->and($exception->getContext()['unknown'])->toBe(['stratgy'])
                ->and($exception->getRequirements())->toBe(InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS);

            return;
        }

        $this->fail('The misspelt key was read.');
    });

    // A result with a misspelt winner was a draw
    it('no longer turns a misspelt winner into a draw', function (): void {
        $form = PlainDataForms::all()['result'];
        $mistyped = PlainDataForms::withKeyRenamed($form['sample'], 'winner', 'winer');

        expect(fn(): object => $form['read']($mistyped, PlainDataForms::registry()))->toThrow(
            InvalidInputException::class,
            "Unknown key in result data: 'winer' (the known keys are 'format_version', 'event', 'winner', 'scores', 'metadata', 'award', 'decider')"
        );
    });
});

describe('a known key given as null', function (): void {
    // Unchanged by the rule above: null is the key left out wherever it was
    // before. A column that stores an unset option as null keeps loading.
    // The exceptions are listed, and they are unchanged too.
    $refusesNull = [
        'pot draw options' => ['pots', 'opponents_per_pot', 'seed'],
        // With no `sessions` key, the presence of `session_count` selects the form
        'session grid of a shape only' => ['session_count'],
    ];

    it('is read as the key left out', function (string $name) use ($refusesNull): void {
        $form = PlainDataForms::all()[$name];
        $sample = $form['sample'];
        if ($name === 'round-robin options') {
            // A seed beside a strategy that draws nothing is refused, so the
            // strategy cannot be left out of the sample that names a seed
            $sample = ['legs' => 2, 'strategy' => 'repeated', 'backtracking' => true, 'strategy_seed' => null, 'role_assignment' => 'balanced'];
        }

        $compared = 0;
        foreach (array_keys($sample) as $key) {
            if (in_array($key, $refusesNull[$name] ?? [], true)) {
                continue;
            }

            $without = $sample;
            unset($without[$key]);

            expect(plainDataOutcome(fn(): object => $form['read']([...$sample, $key => null], PlainDataForms::registry())))
                ->toEqual(plainDataOutcome(fn(): object => $form['read']($without, PlainDataForms::registry())), "{$name}: {$key}");
            ++$compared;
        }

        expect($compared)->toBe(count($sample) - count($refusesNull[$name] ?? []));
    })->with(fn(): array => array_keys(PlainDataForms::all()));

    it('is refused where it always was', function () use ($refusesNull): void {
        foreach ($refusesNull as $name => $keys) {
            $form = PlainDataForms::all()[$name];
            foreach ($keys as $key) {
                $outcome = plainDataOutcome(fn(): object => $form['read']([...$form['sample'], $key => null], PlainDataForms::registry()));

                expect($outcome)->toHaveKey('error', InvalidConfigurationException::class);
            }
        }
    });
});

describe('the format version of an array that is read', function (): void {
    it('may be left out, and the array is then version 1', function (string $name): void {
        $form = PlainDataForms::all()[$name];
        $read = fn(array $data): object => $form['read']($data, PlainDataForms::registry());

        expect($read(['format_version' => 1, ...$form['sample']]))->toEqual($read($form['sample']));
    })->with(fn(): array => array_keys(PlainDataForms::all()));

    it('is refused when it is not a version this release reads', function (string $name, mixed $version, string $fault): void {
        $form = PlainDataForms::all()[$name];

        try {
            $form['read'](['format_version' => $version, ...$form['sample']], PlainDataForms::registry());
        } catch (InvalidConfigurationException $exception) {
            expect($form['kind'])->toBe(PlainDataForms::CONFIGURATION)
                ->and($exception->getReason())->toBe(InvalidConfigurationReason::UnsupportedFormatVersion)
                ->and($exception->getContext())->toBe(['format_version' => $version, 'newest_supported' => 1])
                ->and($exception->getConfigurationIssue())->toContain($fault)
                ->and($exception->getConfigurationIssue())->toContain('this version of the library reads version 1');

            return;
        } catch (InvalidInputException $exception) {
            expect($form['kind'])->toBe(PlainDataForms::DATA)
                ->and($exception->getMessage())->toContain($fault)
                ->and($exception->getMessage())->toContain('this version of the library reads version 1');

            return;
        }

        $this->fail("{$name} read an array of format version " . var_export($version, true) . '.');
    })->with(fn(): array => array_keys(PlainDataForms::all()))->with([
        'the next version' => [2, 'Unsupported format version 2 in '],
        'a far later version' => [PHP_INT_MAX, 'The data was written by a later release'],
        'zero' => [0, 'format versions start at 1'],
        'a negative number' => [-1, 'format versions start at 1'],
        'a numeric string' => ['1', 'must be an integer, string given'],
        'a float' => [1.0, 'must be an integer, float given'],
        'null' => [null, 'must be an integer, null given'],
        'true' => [true, 'must be an integer, bool given'],
    ]);

    // A later release may add keys. The version is what the reader is told
    // about, not the keys it does not know.
    it('is reported before a key the form does not have', function (): void {
        $read = PlainDataForms::all()['schedule']['read'];

        expect(fn(): object => $read(['format_version' => 2, 'entrants' => [], 'events' => []], []))->toThrow(
            InvalidInputException::class,
            'Unsupported format version 2 in schedule data: this version of the library reads version 1.'
            . ' The data was written by a later release; upgrade the library to read it'
        );
    });

    it('is accepted on a form nested in another, where an array was put together by hand', function (): void {
        $form = PlainDataForms::all()['schedule'];
        $sample = $form['sample'];
        assert(is_array($sample['events']) && is_array($sample['events'][0]) && is_array($sample['participants']) && is_array($sample['participants'][0]));

        $byHand = $sample;
        $byHand['participants'][0] = ['format_version' => 1, ...$sample['participants'][0]];
        $byHand['events'][0] = ['format_version' => 1, ...$sample['events'][0]];
        $later = $sample;
        $later['events'][0] = ['format_version' => 3, ...$sample['events'][0]];

        expect($form['read']($byHand, []))->toEqual($form['read']($sample, []))
            ->and(fn(): object => $form['read']($later, []))->toThrow(InvalidInputException::class, 'Unsupported format version 3 in event data');
    });
});
