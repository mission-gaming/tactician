<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\SchedulingException;

// Every concrete class of the library is final: the extension points are
// the interfaces (README, "Versioning and stability"). A class that is not
// final can be extended, and then each with-er, each check of a class and
// each private assumption of the class has a subclass to consider.
//
// The one exception is a class the library itself extends:
// InvalidConfigurationException is the base of PinConflictException,
// UnresolvedRoundException and UndecidedSelectionException, and is thrown
// itself. An abstract class is not instantiated and stays open.

/**
 * Every class, interface, trait and enum declared under src/, by name.
 *
 * @return list<class-string>
 *
 * @throws UnexpectedValueException Never: src/ exists and can be read
 */
function finalClassesTypeNames(): array
{
    $root = dirname(__DIR__, 2) . '/src';
    $names = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        $name = 'MissionGaming\\Tactician\\' . str_replace('/', '\\', $relative);
        if (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name)) {
            $names[] = $name;
        }
    }
    sort($names);

    return $names;
}

describe('every class of src/', function (): void {
    it('is final, abstract, or the one base class the library extends itself', function (): void {
        $open = [];
        $classes = 0;
        foreach (finalClassesTypeNames() as $name) {
            $class = new ReflectionClass($name);
            if ($class->isInterface() || $class->isTrait() || $class->isEnum()) {
                continue;
            }
            ++$classes;
            if (!$class->isFinal() && !$class->isAbstract()) {
                $open[] = $name;
            }
        }

        expect($classes)->toBeGreaterThan(150)
            ->and($open)->toBe([InvalidConfigurationException::class]);
    });

    it('is extended only where the library extends it', function (): void {
        $extended = [];
        foreach (finalClassesTypeNames() as $name) {
            $parent = new ReflectionClass($name)->getParentClass();
            if ($parent !== false && str_starts_with($parent->getName(), 'MissionGaming\\Tactician\\')) {
                $extended[$parent->getName()] = true;
            }
        }

        expect(array_keys($extended))->toEqualCanonicalizing([
            InvalidConfigurationException::class,
            SchedulingException::class,
        ]);
    });
});
