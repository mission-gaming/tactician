# Technical Context: Tactician

## Technologies Used
- **Core Language**: PHP 8.3+ (CI matrix: 8.3, 8.4, 8.5)
- **Testing Framework**: Pest (on PHPUnit)
- **Static Analysis**: PHPStan (level 8, zero errors enforced)
- **Code Modernization**: Rector
- **Code Style**: PHP-CS-Fixer
- **Package Management**: Composer (normalized via ergebnis/composer-normalize)
- **Namespace**: MissionGaming\Tactician

## Development Setup
- **Autoloading**: PSR-4 standard
- **License**: MIT
- **Production dependencies**: none (php ^8.3 only); see composer.json for dev dependencies

## Technical Constraints
- PHP 8.3+ minimum version requirement
- Performance: comfortable into the hundreds of participants (a 200-participant two-leg round robin generates in well under a second)
- Deterministic algorithms with seeded randomness (`Random\Randomizer` + `Mt19937` in tests)
- Separation of fixture generation from timeline assignment (timeline is future work)
- Iterator-based schedules for memory efficiency

## Tool Usage Patterns
- `composer ci` before every commit: normalize check, PHPStan, Rector, CS-Fixer, tests, example smoke-run
- `composer test` during development; `composer examples` for a fast example check
- Auto-fixers: `composer cs-fixer-fix`, `composer rector-fix`, `composer norm-fix`
- Examples are validated automatically by `tests/Feature/ExamplesTest.php` — no example ships without it

## Development Workflow
1. Read the memory bank (start with projectbrief.md and activeContext.md) and AGENTS.md at the start of each session
2. Branch from main — never commit to main directly; open a PR
3. Test-driven development with Pest; extend the property/invariant suites when touching generation logic
4. Execute any documentation snippet you change before committing it
5. Run `composer ci` before commits; keep commits small and single-purpose
6. Update activeContext.md and progress.md after significant work

## Implementation Quality Standards
- **Static Analysis**: zero PHPStan errors at level 8 (checked exceptions enforced — keep @throws accurate)
- **Modern PHP**: readonly classes, strict typing, constructor property promotion
- **Immutability**: all DTOs are immutable value objects
- **Deterministic Results**: seeded randomization for reproducible outcomes; no Date/random calls in generation logic outside injected randomizers

## Status
- **Last Updated**: 2026-07-03
