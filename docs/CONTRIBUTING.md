# Contributing

Contributions are welcome. This page covers the development setup, the checks
a change must pass, and the conventions a pull request follows.

To report a security vulnerability, follow the
[security policy](../SECURITY.md) instead of opening an issue.

## Development setup

Tactician needs PHP 8.3 or later and Composer.

```bash
# Clone the repository
git clone git@github.com:mission-gaming/tactician.git
cd tactician

# Install dependencies
composer install
```

## The gate

`composer ci` is the gate: it must exit 0 before every commit. It needs no
network. CI runs it on every pull request, a draft one included, on PHP 8.3,
8.4, and 8.5.

```bash
# Run every check
composer ci

# The checks it runs, one by one
composer norm             # composer.json is normalized
composer phpstan          # Static analysis (src/ at level 9, tests at level 8, zero errors)
composer rector           # Modernization and dead code check
composer cs-fixer         # Code style check (PER Coding Style)
composer test             # Pest suite
composer examples         # Smoke-run every example script

# Fix findings automatically
composer norm-fix         # Normalize composer.json
composer rector-fix       # Apply modernization
composer cs-fixer-fix     # Fix code style
```

CI also runs one check that is not part of the gate, because it needs the
network and its result can change without a commit:

```bash
composer security-audit   # Known vulnerabilities in the locked dependencies
```

It reads `composer.lock`, so it works before `composer install`. It fails on a
security advisory. It reports an abandoned package without failing, because no
change to the pull request can repair that. In CI it is the `Dependency audit`
job, which runs on every pull request and every push to `main`. That job is
not a required check: a new advisory against a development tool shows as a
failed job on every pull request, and does not stop the others from merging
while the tool is updated.

## Benchmarks

The benchmark suite (`tests/Benchmark/`, run by
[phpbench](https://phpbench.readthedocs.io/)) times the operations whose
cost has mattered: schedule repack at 24 and 40 participants, a round robin
with no constraints, one that needs retries and one that cannot be
completed, and Swiss pairing under a constraint. A timing depends on the
machine, so the suite is not part of the gate.

phpbench is not one of the library's development dependencies. It is
installed from `tools/phpbench/`, which has a `composer.json` and a
`composer.lock` of its own, because it requires a package that is abandoned
with no replacement, and the weekly workflow fails when the library's lock
file holds an abandoned package
([`tools/phpbench/README.md`](../tools/phpbench/README.md)). `composer
install` therefore does not install it, and the two benchmark scripts say so
when it is missing. The benchmark classes name nothing of phpbench: how a
subject is run (one call at a time, five iterations, a time limit) is in
`tests/Benchmark/phpbench.json`, so the gate analyses the classes without
the runner.

```bash
# Install the benchmark runner (once, and after its lock file changes)
composer bench-install

# Time every benchmark on this machine
composer bench

# Compare the working tree with another commit, on this machine
mkdir -p build/base
git archive origin/main src | tar -x -C build/base
composer bench-compare -- build/base/src
```

`composer bench-compare` runs the benchmarks against the copy of `src/` it
is given and against the working tree, by turns, three times each. It
compares the fastest revolution each side managed, and fails when the
working tree takes more than 1.5 times as long. Both sides are measured on
one machine minutes apart, and the fastest of many runs is the one least
disturbed by whatever else the machine was doing. That takes most of the
noise out, not all of it: on a machine that is busy throughout, two runs of
the same code have differed by nearly the margin, so run a flagged
benchmark again before believing it. A margin and a number of rounds may
follow the directory. A benchmark that throws or times out on the working
tree fails the comparison, whether or not the other side could run it; one
that only the other side cannot run is listed as having no baseline.

In CI the same comparison is the `Benchmarks` job: a pull request against
its base, on one runner. That job is not a required check. If it fails, look
at the table it prints before anything else: a benchmark that takes longer
because it now does more is a result to explain in the pull request, not a
defect.

When a change is about cost, measure it: add a benchmark for the operation
if there is none, and state the before and after figures in the changelog
entry. Where a test can count work instead of timing it (the number of
constraint evaluations, of searches started), prefer that: a count is the
same on every machine and belongs in the gate
(`tests/Feature/FailureAnalysisCostTest.php` is an example).

A weekly scheduled workflow (`.github/workflows/scheduled.yml`) runs the gate
against dependencies resolved afresh, without the lock file, and the test
suite against the next PHP version. It does not run on pull requests. If it fails,
the cause is a new release of a tool or of PHP, not your change.

## Testing

```bash
# Run the test suite
composer test

# Run with coverage (needs a coverage driver: Xdebug or PCOV). The script
# sets XDEBUG_MODE=coverage itself and writes build/clover.xml
composer test-coverage

# Run one test file
vendor/bin/pest tests/Unit/Scheduling/RoundRobinSchedulerTest.php
```

A run fails on more than a failed assertion: a warning, a notice, a
deprecation, or a risky test (one that asserts nothing or prints output) fails
it too. Tests run in random order, so a test must not depend on another one
having run first. Every run prints its seed; to repeat the order of a run that
failed:

```bash
vendor/bin/pest --order-by=random --random-order-seed=<seed>
```

A run does not depend on the `memory_limit` PHP is configured with. A plain
run needs about as much as PHP's default of 128M allows and a run with
coverage about 1.1 GB, so every process that runs tests raises a limit below
4G to 4G (`tests/Pest.php` calls `tests/Support/SuiteMemoryLimit.php`). It
leaves a higher limit, and no limit, as they are. Do not state a
`memory_limit` in `phpunit.xml`: PHPUnit sets the value outright, which
lowers the limit of a run that has none, as a run on CI has, and that ended
the `Coverage` job once.

Every feature and every path carries automated tests. Where a genuine
technical or harness limitation prevents one, record the reason next to the
gap, so that the absence is not mistaken for an oversight. When a change
touches generation logic, extend the property tests
(`tests/Feature/EliminationInvariantsTest.php`,
`tests/Feature/ScheduleCompletenessTest.php`) and do not only pin single
examples.

### Mutation testing

```bash
# Change the source one thing at a time and see whether a test notices
# (needs a coverage driver; the script sets XDEBUG_MODE=coverage itself)
composer mutation

# One directory or one file: the path replaces the two of the script
composer mutation -- --path=src/Repack/Internal
```

Line coverage says that a line was executed, not that a test would notice
if it were wrong. Mutation testing asks the second question. Pest's
mutation runner makes one small change at a time to `src/Scheduling` and
`src/Repack/Internal` (a `<` becomes a `<=`, a call is removed, a constant
is one more or one less), runs the tests that cover the changed line, and
counts the change as tested when a test fails and as untested when they all
still pass. The score is the share that was tested. The run prints every
untested change with its diff.

Read the score with three things in mind. The runner counts a change whose
tests ran into its time limit as tested, although no test failed on it: a
change that makes a search run for ever is "noticed" only in that sense.
It counts a change whose test process ran out of memory in the same way
(see below). The summary script therefore also gives the share that a
failing test noticed, which leaves both out. And leave the number of
processes to `--parallel`, which the script passes: with `--processes`
added, the test process of every change fails to start, and the run reports
every change as tested, a score of 100% that means nothing.

The test process of every change has a memory limit of one gigabyte. A
change can make the code allocate without end (a loop that counts the wrong
way and adds to an array on every pass), and the runner's time limit for a
test process, the duration of the first, unmutated run of the tests and a
fifth more, comes too late for that: on a CI runner such a process took all
the memory there was, and the job ended with a runner shutdown and no
summary. A process that reaches the limit ends with PHP's fatal error and
writes a line to `build/mutation-memory.log`; the summary script reads that
file and says how many changes ended that way and in which source files.
A line names the source file and the changed copy of it that the runner
wrote (a file in `vendor/pestphp/pest-plugin-mutate/.temp/mutations/`, to
compare with the source); that name is not the ID the run prints for an
untested change, which `--id` takes. The count is not the same on every
run of the same source: the tests of a change run in random order and stop
at the first failure, so a change that one test fails on and another runs
out of memory on can end either way (one change of `IntervalPlacement`
does; the same holds for the time limit).

The limit comes with `composer mutation`, and with nothing else. The command
runs `tests/bin/run-mutation.php`, which writes a one-line PHP configuration
file to `build/mutation-ini/` and starts Pest with that directory added to
the environment variable `PHP_INI_SCAN_DIR`. The file names
`tests/mutation-memory.php` as `auto_prepend_file`, so PHP runs it before
the script of every process of the run: in the test process of a change it
sets the limit, whatever `memory_limit` PHP is configured with, in the
process that starts the run it starts the log afresh, and in any other it
does nothing. Three things follow:

- A run started in another way, `vendor/bin/pest --mutate` typed directly,
  has no limit and writes no log, as before there was one. The summary
  script says so for such a run ("The test processes of this run had no
  memory limit"), where a run that had the limit says how many changes
  ended there, or that none did. Delete a `build/mutation-memory.log` that
  an earlier run left before you summarise a run of that kind: nothing in
  it removes the file.
- A `PHP_INI_SCAN_DIR` of your own is kept: the directory is added after
  what the variable holds, and when it is not set, after the directory PHP
  was built with. A file of yours that sets `auto_prepend_file` loses to
  this one for the run.
- It is used on Linux (the CI runners) and on macOS. Nothing in it is
  particular to them, but it has not been run on Windows. Where the
  configuration file cannot be written, the run goes on without the limit
  and its summary says that it had none.

The environment variable `TACTICIAN_MUTATION_MEMORY_LIMIT` replaces the
limit (`512M`, `2G`), and `TACTICIAN_MUTATION_MEMORY_LOG` names another file
for the log; give that file to the summary script as its fourth argument. A
change that ends at the limit is not a defect of the library: a search that
is unbounded once its bound is removed is as designed. A search that is
unbounded for some input of the unchanged code would be one.

It is not part of the gate and it enforces no minimum. A run over both
directories takes hours, because every one of some 3,400 changes starts a
test process of its own, and a change that makes a search run for ever is
only stopped by the time limit; `src/Repack/Internal` alone takes a few minutes
on a fast machine. Some changes cannot be noticed at all, because they
change nothing a caller can see (the order in which two equal candidates
are tried, a guard that cannot be reached).

In CI it has a workflow of its own, `.github/workflows/mutation.yml`, which
runs once a week and which a maintainer can start by hand. It does not run
for a pull request, and it is apart from the scheduled workflow above so
that a slow run cannot hide what that one found. The source is mutated in
shards, one `Mutation testing, <shard>` job each, so that every job ends
well inside its limit on a standard runner. A shard is a list of files, and
every PHP file of the two directories is in exactly one:
`tests/Feature/CiConfigurationTest.php` fails when a file is in none or in
two, so when you add a file to either directory, add it to a shard of that
workflow. Each job publishes its shard's score, the counts and the first
untested changes in its job summary. A job that is stopped at its time
limit is red and has no score; its summary says how many changes it got
through and what it found in them, and the shard is then to be split. Read
the summaries when you change generation or repack logic: an untested
change in the lines you touched is a test to write.

The weekly run is of the default branch. A maintainer can start the
workflow for another branch, to see what a change to the tests or to the
workflow does before it is merged:
`gh workflow run "Mutation testing" --ref <branch>`.

The run does not use the whole suite. Every change starts a test process
of its own, so the tests are the ones named in `phpunit.mutation.xml`: the
unit tests of the two directories and the feature tests of the same code
that run in a few seconds or less. The longer sweeps are left out, and a
change that only they would notice is reported as untested: check a change
on that list against them before you write a test for it. When you add a
fast test of generation or repack logic under `tests/Feature/`, name it in
that file.

To see the summary of a local run:

```bash
composer mutation -- --path=src/Repack/Internal 2>&1 | tee build/mutation.log
php tests/bin/mutation-summary.php build/mutation.log
```

The tool is Pest's own (`pestphp/pest-plugin-mutate`, installed with Pest),
not Infection: Infection has no adapter for Pest, and through its PHPUnit
adapter it cannot tell a passing Pest run from a failing one, so it reports
every change as tested.

## Golden fixtures

`tests/Feature/GoldenOutputTest.php` compares generated schedules, bracket
pairings, repack assignments, and the JSON wire shapes with the text fixtures
in `tests/Fixtures/golden/`. A golden diff is an output change: the library
now produces different output for a fixed input and seed. If your change makes
the test fail:

1. Decide whether the change is intended. If it is not, fix the code.
2. If it is intended, describe it in the changelog. The README's
   [Versioning and stability](../README.md#versioning-and-stability) section
   says which releases may carry it.
3. Regenerate the fixtures and commit the fixture diff with the change, so
   that it is reviewed:

   ```bash
   composer golden-update
   ```

Never regenerate only to make the suite pass. A regenerating run cannot fail,
so the test refuses it on CI.

## Documentation and examples

- Every feature ships documented: usage documentation with snippets, contracts
  in docblocks, and glossary entries in [`USAGE.md`](USAGE.md) for new terms.
  Say "participant", not "team", in library code and documentation prose.
- Execute every documentation snippet before you commit it. Snippets that were
  never run have caused real bugs in this repository.
- Example scripts in `examples/` are executable documentation. An example
  computes its results and hands them to the shared renderer; it does not
  print or draw anything itself. `tests/Feature/ExamplesTest.php` runs every
  example under full error reporting as part of `composer test` (a non-zero
  exit, warning, notice, or deprecation fails the suite), asserts what each
  example is there to demonstrate, and checks that the examples index and
  README list every script; the golden test pins what each example computes.
  A new example fails the suite until it has all of these:
  [`examples/README.md`](../examples/README.md) lists the steps.
  `composer examples` smoke-runs the scripts directly for a faster loop. An
  example that needs interactive input or an external service does not belong
  in `examples/`.

## Changelog

Every change needs an entry under `Unreleased` in
[`CHANGELOG.md`](../CHANGELOG.md), added in the same pull request. The
[stability policy](../README.md#versioning-and-stability) says what a patch
and a minor release may change. Maintainers cut releases with the
[release checklist](RELEASING.md).

## Branches and commits

Never commit to `main`. Every change reaches `main` through a pull request.

- **Branch name:** `<type>/<short-description>`, lower case, words separated
  by hyphens. The type is one of `feature`, `fix`, `docs`, `test`, or `chore`.
  Example: `docs/versioning-policy-and-changelog`.
- **Commit subject:** one sentence in the imperative mood that starts with a
  capital letter and ends with a period, with no type prefix. Example:
  `Add the release checklist.` Use the body to say why, when the subject does
  not.
- **Commits:** small and single-purpose.

## Pull requests

1. Fork the repository, or create a branch if you have write access.
2. Make the change, with its tests, documentation, and changelog entry.
3. Run `composer ci` until it exits 0.
4. Open a pull request against `main` and fill in the template: description,
   approach, compatibility impact, and test steps.

The compatibility section asks two questions: does the change alter the output
for an existing input or seed, and does it change a public signature? Answer
both, even when the answer is no.

## Code rules

- Every PHP file declares `strict_types=1`.
- Value objects are immutable: a change returns a new instance. The DTOs
  are `readonly` classes.
- Every concrete class in `src/` is `final`; the extension points are the
  interfaces. The one exception is a class the library extends itself
  (`InvalidConfigurationException`); `tests/Feature/FinalClassesTest.php`
  enforces it.
- Every class, interface, trait and enum in `src/` carries exactly one
  stability annotation in its docblock: `@api`, `@experimental` or
  `@internal`. The README's
  [Versioning and stability](../README.md#versioning-and-stability) section
  says what each means and which namespaces are stable;
  `tests/Feature/StabilityAnnotationsTest.php` fails for a type without one
  and for a new namespace the README does not classify. A new `@internal`
  type outside `Repack\Internal` goes into the list that test pins, and may
  not appear in a public or protected signature of a type that is not
  internal. A type the README lists as stable cannot be marked `@internal`.
- A deprecated method carries the `@deprecated` docblock tag
  (`@deprecated since <version>, removed in 1.0.0.`, then why and what to use
  instead) and the `#[\Deprecated]` attribute, and gets a row under
  "Deprecations" in [`USAGE.md`](USAGE.md) and an entry under `Deprecated` in
  the changelog. `tests/Feature/DeprecationsTest.php` pins the set. Nothing in
  `src/`, the examples or the documentation may call it. A test that still
  covers it calls it through `Tests\Support\DeprecatedCall`, which expects
  the notice PHP 8.4 and later emit for the call.
- PHPStan reports zero errors: `src/` at level 9 (`phpstan.neon`), `tests/`
  and `examples/support/` at level 8 (`phpstan-tests.neon`), both with the
  strict rules and the deprecation rules (`phpstan/common.neon`). The two
  baseline files beside it list what the strict rules found in code that
  existed when they were enabled. Fix a finding in new code; do not add it to a baseline or
  to `ignoreErrors`.
- Rector (`rector.php`) applies the PHP sets up to 8.3, the dead code set and
  the early return set. A rule is skipped there only where it would change a
  public or protected signature, or remove something written on purpose; the
  reason is next to each entry.
- Code style is PER Coding Style (`@PER-CS`) and the PHP 8.3 migration set,
  with the additions in `.php-cs-fixer.dist.php`; `composer cs-fixer-fix`
  applies it.
- A new file or directory at the repository root needs an `export-ignore` rule
  in `.gitattributes`, so that it stays out of the archive consumers install.
  `tests/Feature/DistArchiveTest.php` fails until the rule is committed.
