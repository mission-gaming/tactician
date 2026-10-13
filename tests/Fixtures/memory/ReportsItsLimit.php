<?php

declare(strict_types=1);

use MissionGaming\Tactician\Tests\Support\SuiteMemoryLimit;

// Not a test of the suite: its name does not end in `Test.php`, so no run
// collects it. tests/Feature/SuiteMemoryLimitTest.php runs it by path, in
// Pest processes of their own that PHP starts with one memory limit or
// another, and names the limit the process must have by the time a test
// runs. Where the test cannot know it (a worker of a parallel run has the
// limit PHP is configured with, not the one of the process that started
// it), it names none, and the limit must only not be below the floor.

it('has the memory limit a process of a test run is to have', function (): void {
    $limit = (string) ini_get('memory_limit');
    $expected = getenv('TACTICIAN_EXPECTED_MEMORY_LIMIT');

    if ($expected === false) {
        expect(SuiteMemoryLimit::isBelowFloor($limit))->toBeFalse("The limit is {$limit}.");
    } else {
        expect($limit)->toBe($expected);
    }
});
