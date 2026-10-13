<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Tests\Support\PlainDataForms;

// The rule: a `fromArray()` refuses an array that holds a key its form does
// not have. A misspelt option used to be read as its default, and a misspelt
// field of stored data as the field left out, with nothing to say so.
//
// The forms are listed in Tests\Support\PlainDataForms, each with a sample
// that holds every key it reads. The tests below mistype every one of those
// keys in every way the helper knows and expect each array to be refused.

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
 * The classes under `src/` that declare a `fromArray()` method.
 *
 * @return list<string>
 *
 * @throws UnexpectedValueException When `src/` cannot be read
 */
function classesWithFromArray(): array
{
    $root = dirname(__DIR__, 2) . '/src';
    $classes = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        if (preg_match('/^\s*(?:abstract\s+)?(?:final\s+)?(?:readonly\s+)?class\s+\w+/m', $source) !== 1) {
            // An interface declares the method and reads nothing
            continue;
        }
        if (preg_match('/function\s+fromArray\s*\(/', $source) === 1) {
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

        expect($listed)->toBe(classesWithFromArray())
            ->and(count($listed))->toBeGreaterThan(15);
    });

    it('have a sample that is read, and that holds every key of the form', function (string $name): void {
        $form = PlainDataForms::all()[$name];

        $outcome = plainDataOutcome(fn(): mixed => $form['read']($form['sample']));

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
        $checked = 0;

        foreach (array_keys($form['sample']) as $key) {
            foreach (PlainDataForms::typosOf($key, $form['keys']) as $typo) {
                $mistyped = PlainDataForms::withKeyRenamed($form['sample'], $key, $typo);

                try {
                    $form['read']($mistyped);
                } catch (InvalidConfigurationException $exception) {
                    expect($form['kind'])->toBe(PlainDataForms::CONFIGURATION, "'{$typo}' for '{$key}'")
                        ->and($exception->getReason())->toBe(InvalidConfigurationReason::UnknownOptionKey, "'{$typo}' for '{$key}'")
                        ->and($exception->getContext())->toBe(['unknown' => [$typo], 'known' => $form['keys']])
                        ->and($exception->getMessage())->toContain("'{$typo}'");
                    ++$checked;

                    continue;
                } catch (InvalidInputException $exception) {
                    expect($form['kind'])->toBe(PlainDataForms::DATA, "'{$typo}' for '{$key}'")
                        ->and($exception->getMessage())->toContain('Unknown key in ')
                        ->and($exception->getMessage())->toContain("'{$typo}'");
                    foreach ($form['keys'] as $known) {
                        expect($exception->getMessage())->toContain("'{$known}'");
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

        $outcome = plainDataOutcome(fn(): mixed => $form['read']([...$form['sample'], 'notes' => 'ours']));

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

        $read = PlainDataForms::all()['schedule']['read'];

        expect(fn(): mixed => $read($inEvent))->toThrow(InvalidInputException::class, "Unknown key in event data: 'rond'")
            ->and(fn(): mixed => $read($inRound))->toThrow(InvalidInputException::class, "Unknown key in round data: 'label'")
            ->and(fn(): mixed => $read($inParticipant))->toThrow(InvalidInputException::class, "Unknown key in participant data: 'seeding'");
    });

    // The defect as it was reported: a misspelt strategy gave the defaults
    it('no longer turns a misspelt option into its default', function (): void {
        $read = PlainDataForms::all()['round-robin options']['read'];

        try {
            $read(['legs' => 2, 'stratgy' => 'repeated']);
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

        expect(fn(): mixed => $form['read']($mistyped))->toThrow(
            InvalidInputException::class,
            "Unknown key in result data: 'winer' (the known keys are 'event', 'winner', 'scores', 'metadata')"
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

            expect(plainDataOutcome(fn(): mixed => $form['read']([...$sample, $key => null])))
                ->toEqual(plainDataOutcome(fn(): mixed => $form['read']($without)), "{$name}: {$key}");
            ++$compared;
        }

        expect($compared)->toBe(count($sample) - count($refusesNull[$name] ?? []));
    })->with(fn(): array => array_keys(PlainDataForms::all()));

    it('is refused where it always was', function () use ($refusesNull): void {
        foreach ($refusesNull as $name => $keys) {
            $form = PlainDataForms::all()[$name];
            foreach ($keys as $key) {
                $outcome = plainDataOutcome(fn(): mixed => $form['read']([...$form['sample'], $key => null]));

                expect($outcome)->toHaveKey('error', InvalidConfigurationException::class);
            }
        }
    });
});
