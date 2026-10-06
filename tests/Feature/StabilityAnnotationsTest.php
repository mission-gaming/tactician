<?php

declare(strict_types=1);

use MissionGaming\Tactician\Tests\Support\StabilityPolicy;
use PHPUnit\Framework\Assert;

// The rule: every class, interface, trait and enum in `src/` says in its
// docblock how far a consumer may rely on it, with exactly one of
//
// - `@api`: stable surface;
// - `@experimental`: public, and expected to change in a 0.x minor release;
// - `@internal`: not public API.
//
// Which of the first two a type gets is not decided here. The README's
// "Versioning and stability" section lists the stable and the experimental
// namespaces, and Support\StabilityPolicy reads those lists: a type under a
// stable entry must not be `@experimental`, a type under an experimental one
// must not be `@api`, and a type in a namespace the README does not mention
// fails until the README classifies it. `@internal` is required under
// `Repack\Internal`, allowed for a type the README makes experimental, and
// refused for a type it makes stable: the README promises that a stable type
// does not change, so one leaves the public surface only by an exclusion
// written into the "Stable" list.
//
// Gap left knowingly - whether `@internal` is the right call for a type in
// an experimental namespace is a judgement about what consumers are told to
// use; no test can make it. The internal types outside `Repack\Internal` are
// pinned below instead, so that one more is a change somebody wrote down.

$root = dirname(__DIR__, 2);

/**
 * Every PHP file under `src/`, as paths relative to the repository root.
 * Enumerated the way tests/Feature/ExceptionMarkerTest.php does it.
 *
 * @return list<string>
 */
$sourceFiles = function () use ($root): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($files);

    return $files;
};

$policy = fn(): StabilityPolicy => StabilityPolicy::fromReadme((string) file_get_contents($root . '/README.md'));

/**
 * The types of `src/` with the stability tags of each, keyed by the name
 * relative to `MissionGaming\Tactician`. Fails when a file does not declare
 * exactly the one type the autoloader expects of it, so no type is skipped.
 *
 * @return array<string, list<string>>
 */
$sourceTypes = function () use ($root, $sourceFiles): array {
    $prefix = 'MissionGaming\\Tactician\\';
    $types = [];

    foreach ($sourceFiles() as $file) {
        $expected = $prefix . str_replace('/', '\\', substr($file, strlen('src/'), -strlen('.php')));
        $declared = StabilityPolicy::declaredIn((string) file_get_contents($root . '/' . $file));

        if (array_column($declared, 'name') !== [$expected]) {
            Assert::fail("{$file} must declare {$expected} and nothing else, so that it can be checked.");
        }

        if (!class_exists($expected) && !interface_exists($expected) && !trait_exists($expected) && !enum_exists($expected)) {
            Assert::fail("{$file} does not declare a loadable {$expected}.");
        }

        $types[substr($expected, strlen($prefix))] = $declared[0]['tags'];
    }

    return $types;
};

/**
 * The problems the policy finds in a piece of sample source.
 *
 * @return list<string>
 */
$problemsIn = function (string $php) use ($policy): array {
    $problems = [];

    foreach (StabilityPolicy::declaredIn("<?php\n" . $php) as $type) {
        $name = (string) preg_replace('/^MissionGaming\\\\Tactician\\\\/', '', $type['name']);
        $problems = [...$problems, ...$policy()->problemsWith($name, $type['tags'])];
    }

    return $problems;
};

describe('every type in src/', function () use ($policy, $sourceFiles, $sourceTypes): void {
    it('carries exactly one stability annotation, the one README.md makes it', function () use ($policy, $sourceTypes): void {
        $problems = [];

        foreach ($sourceTypes() as $name => $tags) {
            $problems = [...$problems, ...$policy()->problemsWith($name, $tags)];
        }

        expect($problems)->toBe([]);
    });

    it('is in a namespace README.md classifies', function () use ($policy, $sourceTypes): void {
        $unclassified = [];

        foreach (array_keys($sourceTypes()) as $name) {
            if ($policy()->expectedFor($name) === null) {
                $unclassified[] = $name;
            }
        }

        expect($unclassified)->toBe([]);
    });

    it('was found, so the rule cannot pass by finding nothing', function () use ($sourceFiles, $sourceTypes): void {
        $types = $sourceTypes();
        $counts = array_count_values(array_merge(...array_values($types)));

        // 129 types when this was written: 41 @api, 70 @experimental and
        // 18 @internal. The floors are loose enough to survive ordinary
        // change and far above what a broken scan returns.
        expect(count($types))->toBe(count($sourceFiles()))
            ->and(count($types))->toBeGreaterThan(100)
            ->and($counts[StabilityPolicy::API] ?? 0)->toBeGreaterThan(30)
            ->and($counts[StabilityPolicy::EXPERIMENTAL] ?? 0)->toBeGreaterThan(50)
            ->and($counts[StabilityPolicy::INTERNAL] ?? 0)->toBeGreaterThan(12);

        // One known type of each kind, read to the tag it carries
        expect($types['DTO\Participant'])->toBe([StabilityPolicy::API])
            ->and($types['Scheduling\RoundRobinScheduler'])->toBe([StabilityPolicy::API])
            ->and($types['Scheduling\SwissScheduler'])->toBe([StabilityPolicy::EXPERIMENTAL])
            ->and($types['Stage\StagePlan'])->toBe([StabilityPolicy::EXPERIMENTAL])
            ->and($types['Exceptions\InvalidConfigurationReason'])->toBe([StabilityPolicy::API])
            ->and($types['Validation\ValidatesScheduleCompleteness'])->toBe([StabilityPolicy::INTERNAL])
            ->and($types['Repack\Internal\StepBudget'])->toBe([StabilityPolicy::INTERNAL]);
    });

    // Marking a public type internal tells its users to stop and takes the
    // compatibility promise away from it, and no rule can say whether that is
    // right. So the set is pinned: a type joins or leaves it in a change that
    // edits this list, with an entry in the changelog when the type was
    // public in a release.
    it('is internal outside Repack\Internal only where this list says so', function () use ($sourceTypes): void {
        $internal = array_keys(array_filter(
            $sourceTypes(),
            static fn(array $tags, string $name): bool => $tags === [StabilityPolicy::INTERNAL] && !str_starts_with($name, 'Repack\Internal\\'),
            ARRAY_FILTER_USE_BOTH
        ));
        sort($internal);

        expect($internal)->toBe([
            'Diagnostics\SchedulingDiagnostics',
            'Scheduling\BacktrackingRoundRobinGenerator',
            'Scheduling\ConstraintPurity',
            'Scheduling\EliminationBracketSupport',
            'Scheduling\EventIndex',
            'Scheduling\PerfectMatching',
            'Scheduling\SwissRoundSearch',
            'Stage\EngineFingerprint',
            'Stage\PairKey',
            'Timeline\DateTimeString',
            'Timeline\ZonedTime',
            'Validation\ScheduleValidator',
            'Validation\ValidatesScheduleCompleteness',
        ]);
    });

    // A consumer may be told not to use a type only if the public surface
    // never hands it one or asks for one. The declared types of every
    // parameter, return value and property a consumer can reach are read:
    // public ones, and protected ones of a class that can be extended.
    //
    // Gap left knowingly - a type named only in a docblock (`@return`,
    // `@param`, `@throws`) is not read. None is today; a reviewer has to
    // look.
    it('is not an internal type handed out or asked for by a public one', function () use ($sourceTypes): void {
        $prefix = 'MissionGaming\\Tactician\\';
        $types = $sourceTypes();
        $internal = array_keys(array_filter($types, static fn(array $tags): bool => $tags === [StabilityPolicy::INTERNAL]));

        $named = function (?ReflectionType $type) use (&$named): array {
            if ($type instanceof ReflectionNamedType) {
                return [$type->getName()];
            }

            $names = [];
            if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
                foreach ($type->getTypes() as $part) {
                    $names = [...$names, ...$named($part)];
                }
            }

            return $names;
        };

        $leaks = [];
        $members = 0;

        foreach (array_keys($types) as $name) {
            if (in_array($name, $internal, true)) {
                continue;
            }

            $type = $prefix . $name;
            if (!class_exists($type) && !interface_exists($type) && !trait_exists($type) && !enum_exists($type)) {
                Assert::fail("{$type} cannot be loaded, so its signatures cannot be read.");
            }

            $class = new ReflectionClass($type);
            $reachable = static fn(ReflectionMethod|ReflectionProperty $member): bool => $member->isPublic()
                || ($member->isProtected() && !$class->isFinal());
            $exposed = [];

            foreach ($class->getMethods() as $method) {
                if (!$reachable($method)) {
                    continue;
                }

                $exposed["{$method->getName()}()"] = $named($method->getReturnType());
                foreach ($method->getParameters() as $parameter) {
                    $exposed["{$method->getName()}()"] = [...$exposed["{$method->getName()}()"], ...$named($parameter->getType())];
                }
            }

            foreach ($class->getProperties() as $property) {
                if ($reachable($property)) {
                    $exposed['$' . $property->getName()] = $named($property->getType());
                }
            }

            foreach ($class->getInterfaceNames() as $interface) {
                $exposed['implements'][] = $interface;
            }
            if ($class->getParentClass() !== false) {
                $exposed['extends'] = [$class->getParentClass()->getName()];
            }

            foreach ($exposed as $member => $memberTypes) {
                ++$members;
                foreach ($memberTypes as $memberType) {
                    if (in_array(substr($memberType, strlen($prefix)), $internal, true) && str_starts_with($memberType, $prefix)) {
                        $leaks[] = "{$name}::{$member} is typed " . substr($memberType, strlen($prefix));
                    }
                }
            }
        }

        sort($leaks);

        // The three known ones: the validator each whole-schedule generator
        // holds comes from the internal trait ValidatesScheduleCompleteness
        // as a protected property, and the three classes are not final. The
        // README says that the protected members such a trait supplies are
        // internal, so a subclass may not rely on them. Nothing else may
        // join this list.
        expect($members)->toBeGreaterThan(500)
            ->and($leaks)->toBe([
                'Scheduling\PotDrawScheduler::$validator is typed Validation\ScheduleValidator',
                'Scheduling\RoundRobinScheduler::$validator is typed Validation\ScheduleValidator',
                'Scheduling\SwissScheduler::$validator is typed Validation\ScheduleValidator',
            ]);
    });

    it('is internal wherever README.md names it as an internal class', function () use ($policy, $sourceTypes): void {
        // "Neither does a class in any other namespace whose docblock is
        // marked `@internal` (`Stage\PairKey`, ...)."
        if (preg_match('/whose\s+docblock\s+is\s+marked\s+`@internal`\s+\(([^)]+)\)/', $policy()->section(), $matches) !== 1) {
            Assert::fail('The stability section no longer names its examples of internal classes.');
        }

        preg_match_all('/`([^`]+)`/', $matches[1], $names);
        $types = $sourceTypes();

        expect($names[1])->not->toBeEmpty();

        foreach ($names[1] as $name) {
            expect($types[$name] ?? null)->toBe([StabilityPolicy::INTERNAL], "README.md names {$name} as an internal class.");
        }
    });
});

describe('the policy read from README.md', function () use ($policy): void {
    it('reads both lists and the one exclusion', function () use ($policy): void {
        expect($policy()->classified('Stable'))->toContain('DTO', 'Repack', 'Scheduling\RoundRobinScheduler')
            ->and($policy()->classified('Experimental'))->toContain('Stage', 'Scheduling\SwissScheduler')
            ->and($policy()->excluded('Stable'))->toBe(['Repack\Internal'])
            ->and($policy()->excluded('Experimental'))->toBe([]);
    });

    it('expects the annotation of the most specific entry', function (string $name, ?string $expected) use ($policy): void {
        expect($policy()->expectedFor($name))->toBe($expected);
    })->with([
        'a stable namespace' => ['DTO\Event', StabilityPolicy::API],
        'a class nested under a stable namespace' => ['Repack\Deeper\Thing', StabilityPolicy::API],
        'the excluded part of a stable namespace' => ['Repack\Internal\StepBudget', StabilityPolicy::INTERNAL],
        'a stable class in a namespace that is otherwise experimental' => ['Scheduling\RoundRobinScheduler', StabilityPolicy::API],
        'an experimental class named by the README' => ['Scheduling\SwissPairingEngine', StabilityPolicy::EXPERIMENTAL],
        'the rest of that namespace' => ['Scheduling\SchedulingContext', StabilityPolicy::EXPERIMENTAL],
        'a class whose name only starts like a stable one' => ['Scheduling\RoundRobinSchedulerFactory', StabilityPolicy::EXPERIMENTAL],
        'an experimental namespace' => ['Stage\StageState', StabilityPolicy::EXPERIMENTAL],
        'a namespace the README does not mention' => ['Brackets\Bracket', null],
        'a namespace whose name only starts like a listed one' => ['DTOs\Event', null],
    ]);

    it('says that anything not listed as stable is experimental', function () use ($policy): void {
        // The rule expectedFor() applies to the rest of `Scheduling`
        expect($policy()->section())->toContain('Anything not listed as stable is experimental');
    });
});

// The rule is proven on sample source, so a scan that stopped reading
// annotations could not pass by agreeing with whatever `src/` holds.
describe('the rule, on sample source', function () use ($problemsIn): void {
    it('accepts one annotation that agrees with README.md', function (string $php) use ($problemsIn): void {
        expect($problemsIn($php))->toBe([]);
    })->with([
        '@api in a stable namespace' => ["namespace MissionGaming\\Tactician\\DTO;\n/**\n * A sample.\n *\n * @api\n */\nfinal readonly class Sample {}"],
        '@experimental in an experimental namespace' => ["namespace MissionGaming\\Tactician\\Stage;\n/**\n * A sample.\n *\n * @experimental\n */\ninterface Sample {}"],
        '@internal in an experimental namespace' => ["namespace MissionGaming\\Tactician\\Stage;\n/**\n * @internal\n */\nenum Sample {}"],
        '@internal with its reason, in the rest of Scheduling' => ["namespace MissionGaming\\Tactician\\Scheduling;\n/**\n * @internal Not public API\n */\ntrait Sample {}"],
        '@internal under Repack\Internal' => ["namespace MissionGaming\\Tactician\\Repack\\Internal;\n/**\n * @internal\n */\nfinal class Sample {}"],
        'a one-line docblock' => ["namespace MissionGaming\\Tactician\\DTO;\n/** @api */\nclass Sample {}"],
        'an attribute between the docblock and the class' => ["namespace MissionGaming\\Tactician\\DTO;\n/**\n * @api\n */\n#[\\AllowDynamicProperties]\nabstract class Sample {}"],
        'a second tag that is only mentioned in the prose' => ["namespace MissionGaming\\Tactician\\DTO;\n/**\n * Not {@internal}, and never `@experimental`.\n *\n * @api\n */\nclass Sample {}"],
        'an anonymous class and a ::class constant inside' => ["namespace MissionGaming\\Tactician\\DTO;\n/**\n * @api\n */\nclass Sample { function f(): object { return new class { public string \$n = Sample::class; }; } }"],
    ]);

    it('rejects a type with no annotation', function (string $php) use ($problemsIn): void {
        expect($problemsIn($php))->toBe([
            'DTO\Sample has no stability annotation: its docblock needs one of @api, @experimental or @internal.',
        ]);
    })->with([
        'no docblock' => ["namespace MissionGaming\\Tactician\\DTO;\nclass Sample {}"],
        'a docblock without a tag' => ["namespace MissionGaming\\Tactician\\DTO;\n/**\n * A sample.\n */\nclass Sample {}"],
        'the tag on a method only' => ["namespace MissionGaming\\Tactician\\DTO;\nclass Sample {\n    /**\n     * @api\n     */\n    public function f(): void {}\n}"],
        'the tag in the docblock of the file' => ["/**\n * @api\n */\nnamespace MissionGaming\\Tactician\\DTO;\nclass Sample {}"],
        'the tag in an ordinary comment' => ["namespace MissionGaming\\Tactician\\DTO;\n/*\n * @api\n */\nclass Sample {}"],
        'the tag only inline' => ["namespace MissionGaming\\Tactician\\DTO;\n/**\n * A sample, {@api}.\n */\nclass Sample {}"],
        'a tag that only starts like one' => ["namespace MissionGaming\\Tactician\\DTO;\n/**\n * @apiVersion 2\n */\nclass Sample {}"],
    ]);

    it('rejects a type with two annotations', function (string $php, string $problem) use ($problemsIn): void {
        expect($problemsIn($php))->toBe([$problem]);
    })->with([
        'two different ones' => [
            "namespace MissionGaming\\Tactician\\DTO;\n/**\n * @api\n * @internal\n */\nclass Sample {}",
            'DTO\Sample has 2 stability annotations (@api, @internal); it needs exactly one.',
        ],
        'the same one twice' => [
            "namespace MissionGaming\\Tactician\\Stage;\n/**\n * @experimental\n *\n * @experimental\n */\nclass Sample {}",
            'Stage\Sample has 2 stability annotations (@experimental, @experimental); it needs exactly one.',
        ],
        'all three' => [
            "namespace MissionGaming\\Tactician\\Stage;\n/**\n * @api\n * @experimental\n * @internal\n */\nclass Sample {}",
            'Stage\Sample has 3 stability annotations (@api, @experimental, @internal); it needs exactly one.',
        ],
    ]);

    it('rejects an annotation that contradicts README.md', function (string $php, string $problem) use ($problemsIn): void {
        expect($problemsIn($php))->toBe([$problem]);
    })->with([
        '@experimental in a stable namespace' => [
            "namespace MissionGaming\\Tactician\\DTO;\n/**\n * @experimental\n */\nclass Sample {}",
            'DTO\Sample is @experimental, but README.md makes it @api.',
        ],
        '@api in an experimental namespace' => [
            "namespace MissionGaming\\Tactician\\Stage;\n/**\n * @api\n */\nclass Sample {}",
            'Stage\Sample is @api, but README.md makes it @experimental.',
        ],
        '@api in the rest of Scheduling' => [
            "namespace MissionGaming\\Tactician\\Scheduling;\n/**\n * @api\n */\nclass Sample {}",
            'Scheduling\Sample is @api, but README.md makes it @experimental.',
        ],
        '@experimental on a class the README lists as stable' => [
            "namespace MissionGaming\\Tactician\\Scheduling;\n/**\n * @experimental\n */\nclass RoundRobinOptions {}",
            'Scheduling\RoundRobinOptions is @experimental, but README.md makes it @api.',
        ],
        '@api under Repack\Internal' => [
            "namespace MissionGaming\\Tactician\\Repack\\Internal;\n/**\n * @api\n */\nclass Sample {}",
            'Repack\Internal\Sample is @api, but README.md makes it @internal.',
        ],
        '@experimental under Repack\Internal' => [
            "namespace MissionGaming\\Tactician\\Repack\\Internal;\n/**\n * @experimental\n */\nclass Sample {}",
            'Repack\Internal\Sample is @experimental, but README.md makes it @internal.',
        ],
    ]);

    // Marking a type internal withdraws it from the public surface. For a
    // stable type that is the breaking change the README rules out.
    it('rejects @internal on a type README.md lists as stable', function (string $php, string $name) use ($problemsIn): void {
        expect($problemsIn($php))->toBe([
            "{$name} is @internal, but README.md lists it as stable: a stable type leaves the public surface only when the \"Stable\" list excludes it.",
        ]);
    })->with([
        'in a stable namespace' => ["namespace MissionGaming\\Tactician\\DTO;\n/**\n * @internal Not public API\n */\ntrait Sample {}", 'DTO\Sample'],
        'nested under a stable namespace' => ["namespace MissionGaming\\Tactician\\Repack\\Deeper;\n/**\n * @internal\n */\nclass Sample {}", 'Repack\Deeper\Sample'],
        'a class the README lists as stable by name' => ["namespace MissionGaming\\Tactician\\Scheduling;\n/**\n * @internal\n */\nclass RoundRobinScheduler {}", 'Scheduling\RoundRobinScheduler'],
    ]);

    it('reads a tag only where it stands alone', function (string $docblock, array $tags): void {
        $declared = StabilityPolicy::declaredIn("<?php\nnamespace MissionGaming\\Tactician\\DTO;\n{$docblock}\nclass Sample {}");

        expect($declared)->toBe([['name' => 'MissionGaming\Tactician\DTO\Sample', 'tags' => $tags]]);
    })->with([
        'a tag with a suffix' => ["/**\n * @api-note\n * @internalNote\n * @experimental_feature\n * @api\\Something\n */", []],
        'a tag followed by a full stop or a reason' => ["/**\n * @internal.\n */", ['internal']],
        'a tag in a code sample of the docblock counts, so the sample must not start a line with one' => ["/**\n * @api\n *\n *     @internal\n */", ['api', 'internal']],
        'a tag after other text on its line' => ["/**\n * See also @api and @internal.\n * @experimental\n */", ['experimental']],
        'the docblock of an earlier statement' => ["/**\n * @api\n */\nconst UNRELATED = 1;\n/**\n * A sample.\n */", []],
        'a docblock, then a comment, an attribute and modifiers' => ["/**\n * @api\n */\n// a note\n#[\\Attribute(\\Attribute::TARGET_CLASS)]\nfinal readonly", ['api']],
    ]);

    it('rejects a type in a namespace README.md does not classify', function () use ($problemsIn): void {
        expect($problemsIn("namespace MissionGaming\\Tactician\\Brackets;\n/**\n * @experimental\n */\nclass Sample {}"))->toBe([
            'Brackets\Sample is in a namespace that README.md lists as neither stable nor experimental.',
        ]);
    });

    it('checks every type a file declares', function () use ($problemsIn): void {
        expect($problemsIn("namespace MissionGaming\\Tactician\\DTO;\n/**\n * @api\n */\nclass First {}\nclass Second {}"))->toBe([
            'DTO\Second has no stability annotation: its docblock needs one of @api, @experimental or @internal.',
        ]);
    });
});
