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
