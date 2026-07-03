# Contributing

We welcome contributions! Here's how to get started with development and contribute to the project.

## Development Setup

```bash
# Clone the repository
git clone git@github.com:mission-gaming/tactician.git
cd tactician

# Install dependencies
composer install
```

## Quality Assurance

```bash
# Run all quality checks
composer ci

# Individual commands
composer test              # Run tests
composer phpstan          # Static analysis
composer cs-fixer         # Code style check
composer rector           # Modernization check
composer examples         # Smoke-run every example script

# Fix issues automatically
composer cs-fixer-fix     # Fix code style
composer rector-fix       # Apply modernization
composer norm-fix         # Normalize composer.json
```

## Testing

```bash
# Run test suite
composer test

# Run with coverage
composer test-coverage

# Run specific tests
./vendor/bin/pest tests/Unit/Scheduling/RoundRobinSchedulerTest.php
```

## Examples

Example scripts in `examples/` are executable documentation and must never
ship without tests validating them. `tests/Feature/ExamplesTest.php`
auto-discovers and runs every example under full error reporting as part of
`composer test`, so a new example is covered the moment it is added —
failures (non-zero exit, warnings, notices, deprecations) fail the suite.
`composer examples` smoke-runs them directly for a faster loop. If an
example would need interactive input or external services to run, it does
not belong in `examples/`.

## Contributing Guidelines

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Ensure tests pass (`composer ci`)
4. Commit your changes (`git commit -m 'Add amazing feature'`)
5. Push to the branch (`git push origin feature/amazing-feature`)
6. Open a Pull Request

## Development Guidelines

- Follow PSR-12 coding standards
- Write tests for all new features
- Update documentation for API changes
- Ensure PHPStan level 8 compliance
- Use meaningful commit messages
