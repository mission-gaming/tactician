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

`composer ci` is the gate: it must exit 0 before every commit. CI runs it on
every pull request that changes more than documentation, on PHP 8.3, 8.4, and
8.5.

```bash
# Run every check
composer ci

# The checks it runs, one by one
composer norm             # composer.json is normalized
composer phpstan          # Static analysis (level 8, zero errors)
composer rector           # Modernization check
composer cs-fixer         # Code style check
composer test             # Pest suite
composer examples         # Smoke-run every example script

# Fix findings automatically
composer norm-fix         # Normalize composer.json
composer rector-fix       # Apply modernization
composer cs-fixer-fix     # Fix code style
```

## Testing

```bash
# Run the test suite
composer test

# Run with coverage (needs a coverage driver: Xdebug with
# XDEBUG_MODE=coverage, or PCOV)
XDEBUG_MODE=coverage composer test-coverage

# Run one test file
vendor/bin/pest tests/Unit/Scheduling/RoundRobinSchedulerTest.php
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
- Example scripts in `examples/` are executable documentation.
  `tests/Feature/ExamplesTest.php` discovers and runs every example under full
  error reporting as part of `composer test`, so a new example is covered the
  moment it is added: a non-zero exit, warning, notice, or deprecation fails
  the suite. `composer examples` smoke-runs them directly for a faster loop.
  An example that needs interactive input or an external service does not
  belong in `examples/`.

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
- PHPStan level 8 reports zero errors.
- Code style is PSR-12 with the additions in `.php-cs-fixer.dist.php`;
  `composer cs-fixer-fix` applies it.
- A new file or directory at the repository root needs an `export-ignore` rule
  in `.gitattributes`, so that it stays out of the archive consumers install.
  `tests/Feature/DistArchiveTest.php` fails until the rule is committed.
