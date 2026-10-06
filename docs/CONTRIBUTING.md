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

Read the score with two things in mind. The runner counts a change whose
tests ran into its time limit as tested, although no test failed on it: a
change that makes a search run for ever is "noticed" only in that sense.
The summary script therefore also gives the share that a failing test
noticed. And leave the number of processes to `--parallel`, which the
script passes: with `--processes` added, the test process of every change
fails to start, and the run reports every change as tested, a score of
100% that means nothing.

It is not part of the gate and it enforces no minimum. A run over both
directories takes hours, because every one of some 3,400 changes starts a
test process of its own, and a change that makes a search run for ever is
only stopped by a timeout; `src/Repack/Internal` alone takes a few minutes
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

The scores have not been confirmed in CI yet. The workflow runs on the
default branch only, so its first run is started by hand there after the
change that adds it is merged; the size of the shards rests on one local
measurement until then.

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
- DTOs are readonly.
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
