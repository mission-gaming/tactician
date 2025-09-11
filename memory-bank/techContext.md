# Technical Context: Tactician

## Technologies Used
- **Core Language**: PHP 8.2+ (supports 8.2, 8.3, 8.4)
- **Testing Framework**: Pest (primary), PHPUnit (fallback)
- **Static Analysis**: PHPStan
- **Code Modernization**: Rector
- **Code Style**: PHP-CS-Fixer
- **Package Management**: Composer
- **Namespace**: MissionGaming\Tactician

## Development Setup
- **Autoloading**: PSR-4 standard
- **License**: MIT

## Technical Constraints
- PHP 8.2+ minimum version requirement
- Performance target: competitions up to ~50 participants
- Deterministic algorithms with seeded randomness
- Separation of fixture generation from timeline assignment
- Generator/iterable-based scheduling for memory efficiency

## Dependencies
### Production
- php: ^8.2

### Development
- pestphp/pest: ^2.0
- phpstan/phpstan: ^1.10
- rector/rector: ^1.2
- friendsofphp/php-cs-fixer: ^3.0
- phpunit/phpunit: ^10.0
- ergebnis/composer-normalize: ^2.39
- fakerphp/faker: ^1.23
- nunomaduro/collision: ^8.5

## Tool Usage Patterns
- Memory bank documentation following .clinerules structure
- Test-driven development approach using Pest
- Automated CI pipeline with composer scripts
- Code quality enforcement via static analysis and formatting

## Development Workflow
1. Read memory bank files at start of each session
2. Run `composer ci` for quality checks before commits
3. Use `composer test` for test-driven development  
4. Apply `composer rector-fix` and `composer cs-fixer-fix` for maintenance
5. Update documentation as development progresses
6. Maintain clear context for future sessions

## Implementation Quality Standards
- **Test Coverage**: 100% coverage of all implemented features using Pest framework
- **Static Analysis**: Zero PHPStan errors at maximum level  
- **Code Style**: Consistent formatting with PHP-CS-Fixer
- **Modern PHP**: Readonly classes, strict typing, constructor property promotion
- **Immutability**: All DTOs are immutable value objects
- **Memory Efficiency**: Iterator-based patterns for large data sets
- **Deterministic Results**: Seeded randomization for reproducible outcomes

## Architecture Principles
- **SOLID Principles**: Single responsibility, interface segregation, dependency injection
- **Strategy Pattern**: Pluggable algorithms through clean interfaces
- **Builder Pattern**: Fluent APIs for complex object construction
- **Separation of Concerns**: Clear boundaries between data, logic, and constraints
- **Extensibility**: Clean extension points for additional tournament formats

---
*Last Updated: 2025-09-11*
