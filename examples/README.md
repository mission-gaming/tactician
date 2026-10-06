# Tactician examples

Runnable examples of the library. Each one is a single script that reads top
to bottom as ordinary library usage and works in two ways:

- **On the command line** it prints its results as text.
- **Under a web server** it shows the same results as an HTML page, followed
  by the code that produced them.

There are no separate "browser" and "command-line" examples: all 21 scripts
are both. `index.php` is the one exception, a page of links for the browser.

## Running them

Install the dependencies once, from the project root:

```bash
composer install
```

**Command line**, from the project root:

```bash
php examples/01-basic-round-robin.php
```

**Browser**, with PHP's built-in server, then open <http://localhost:8000>:

```bash
php -S localhost:8000 -t examples
```

On PHP 8.3 with the tracing JIT switched on (`opcache.jit=tracing` or `1235`
with a JIT buffer; PHP leaves the JIT off by default), the JIT can crash the
built-in server after a number of pages, so that a request gets no response
until the server is started again: start it with
`php -d opcache.jit=disable -S localhost:8000 -t examples` there.

`composer examples` runs every script once and fails if any of them exits
with an error.

## The examples

| Script | What it shows |
| --- | --- |
| [01-basic-round-robin.php](01-basic-round-robin.php) | The smallest schedule: four participants, every pair meets once |
| [02-participants-and-metadata.php](02-participants-and-metadata.php) | Seeds and free-form metadata on participants |
| [03-iterating-schedules.php](03-iterating-schedules.php) | Five ways to read a schedule: iterate, count, list, group by round, metadata (a schedule holds all of its events in memory) |
| [04-basic-constraints.php](04-basic-constraints.php) | Building a constraint set; a custom rule that moves one pairing |
| [05-seed-protection.php](05-seed-protection.php) | Keeping the top seeds apart for a fraction of the rounds |
| [06-rest-periods.php](06-rest-periods.php) | A minimum number of rounds between repeat meetings of a pair |
| [07-metadata-constraints.php](07-metadata-constraints.php) | Rules over participant metadata, and how an impossible rule fails |
| [08-custom-constraints.php](08-custom-constraints.php) | Your own rule as a closure or as a class |
| [09-multi-leg-home-away.php](09-multi-leg-home-away.php) | Two legs with the mirrored, repeated and shuffled leg strategies |
| [10-complex-tournament.php](10-complex-tournament.php) | Seed protection, rest and a custom rule in one two-leg season |
| [11-error-handling.php](11-error-handling.php) | The exceptions the scheduler throws and what they carry |
| [12-performance-patterns.php](12-performance-patterns.php) | How a round robin grows with the field, and measured generation times |
| [13-swiss-stage-engine.php](13-swiss-stage-engine.php) | A Swiss stage paired round by round from the results |
| [14-groups-to-knockout.php](14-groups-to-knockout.php) | Pools, qualification and a single-elimination bracket composed together |
| [15-timeline-assignment.php](15-timeline-assignment.php) | Kickoff times and resources for every event, under time rules |
| [16-backtracking-generation.php](16-backtracking-generation.php) | Constraints the default generator cannot solve, solved by the opt-in search |
| [17-schedule-optimization.php](17-schedule-optimization.php) | Scoring schedule quality and keeping the best of many samples |
| [18-stateless-web-flow.php](18-stateless-web-flow.php) | A stage kept as JSON between stateless requests |
| [19-repacking-a-season.php](19-repacking-a-season.php) | Repacking outstanding events onto an irregular grid of sessions |
| [20-double-elimination.php](20-double-elimination.php) | A double-elimination bracket with a grand final reset |
| [21-standings-and-tiebreakers.php](21-standings-and-tiebreakers.php) | A standings table and a chain of tiebreakers |

The sample data uses sports teams and players because that is what most
schedules are for. The library itself only knows participants.

## How an example is built

An example computes; it does not format. Its last statement hands a named set
of results to `Example::present()`, from
[`support/Example.php`](support/Example.php):

```php
return Example::present(__FILE__, 'Basic round robin', 'What the example shows.', [
    'Participants' => $participants,
    'Schedule' => $schedule,
]);
```

`Example::present()` returns that set unchanged. It displays it, as text or as a page,
only when the script is the one PHP was started with. When the script is
included from somewhere else it displays nothing, which is how the test suite
reads the results.

A result can be a library object (`Schedule`, `ScheduledSchedule`,
`RoundPairing`, `Result`, `Standings`, `RepackOutcome`, `Participant`,
`Event`), an exception, a scalar, or an array of those. `support/Example.php`
is the only file that knows how to draw them, so changing the look of the
pages touches no example and no test fixture.

A value that cannot be the same on every run, such as a measured duration, is
wrapped in `Measured` with the reason. Example 12 does this for its timings.

## How the examples are checked

`tests/Feature/ExamplesTest.php` covers every script in this directory:

1. It runs the script in a PHP process of its own under `E_ALL` and fails on
   any warning, notice or deprecation.
2. It reads the script's results and asserts what the example is there to
   show: for instance, that the constrained schedule of example 04 really
   keeps seeds 1 and 6 apart in the first two rounds.
3. `tests/Feature/GoldenOutputTest.php` pins the results as readable text in
   `tests/Fixtures/golden/examples/`, in the form the other golden fixtures
   use. A `Measured` value is pinned as its unit and reason, not its value.

The same test fails when a script is missing from the table above or from
`index.php`, or when either lists a script that does not exist.

## Adding an example

1. Create `NN-short-name.php` with the next number, ending in
   `return Example::present(__FILE__, ...)`. Use closures rather than named functions
   for helpers: the test suite includes every example in one process.
2. Add it to the table above and to `index.php`.
3. Add its assertions to the `demonstrations` list in
   `tests/Feature/ExamplesTest.php`, and its fixture name to the matrix in
   `tests/Feature/GoldenOutputTest.php`.
4. Run `composer golden-update` from the project root and review the new
   fixture before committing it.

The suite fails until all four are done.

## More documentation

- [Usage guide](../docs/USAGE.md)
- [Architecture](../docs/ARCHITECTURE.md)
- [Main README](../README.md)
- [Contributing](../docs/CONTRIBUTING.md)
