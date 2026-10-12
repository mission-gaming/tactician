<?php

declare(strict_types=1);

// Not a test of the suite: its name does not end in `Test.php`, so no run
// collects it. tests/Feature/MutationMemoryLimitTest.php runs it by path, in
// a Pest process of its own that has a memory limit, as a stand-in for the
// tests of a mutation that makes the code under test allocate without end.

it('allocates without end', function (): void {
    $held = [];
    for (; ;) {
        $held[] = str_repeat('x', 4096);
    }
});
