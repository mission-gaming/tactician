<?php

declare(strict_types=1);

// Not a test of the suite: its name does not end in `Test.php`, so no run
// collects it. tests/Feature/MutationMemoryLimitTest.php runs it by path, in
// a Pest process of its own that has a memory limit, as a stand-in for the
// tests of a mutation that makes the code under test allocate without end.
//
// "Without end" as far as that limit can tell: the test gives the process 48
// megabytes, and this stops by itself at 192. If the limit is ever not
// installed, the process must not go on to take the memory of the machine,
// which on a CI runner ends the job with nothing said. PHP's own
// `memory_limit` cannot be relied on for that: a worker of a parallel run
// does not get the `-d` options of the process that started it, and PHP has
// no limit on a CI runner.

it('allocates without end', function (): void {
    $held = [];
    while (memory_get_usage() < 192 * 1024 * 1024) {
        $held[] = str_repeat('x', 4096);
    }

    throw new RuntimeException('No memory limit ended this process: it holds ' . count($held) . ' blocks.');
});
