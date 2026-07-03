<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

// uses(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Stage\RoundRobinPlan;
use MissionGaming\Tactician\Stage\SwissPlan;

/**
 * Build a scheduling context carrying a round-robin stage plan.
 *
 * @param array<Participant> $participants
 * @param array<Event> $events
 */
function roundRobinContext(
    array $participants,
    array $events = [],
    int $legs = 1,
    int $currentLeg = 1
): SchedulingContext {
    return new SchedulingContext(
        $participants,
        new RoundRobinPlan($participants, $legs),
        $events,
        $currentLeg
    );
}

/**
 * Build a scheduling context carrying a Swiss stage plan.
 *
 * @param array<Participant> $participants
 * @param array<Event> $events
 */
function swissContext(
    array $participants,
    array $events = [],
    ?int $rounds = null
): SchedulingContext {
    return new SchedulingContext(
        $participants,
        new SwissPlan($participants, $rounds),
        $events
    );
}
