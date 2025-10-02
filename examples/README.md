# Tactician Examples

This directory contains interactive examples demonstrating the capabilities of the Tactician tournament scheduling library. These examples are designed to run in your browser using PHP's built-in development server.

## Quick Start

1. **Navigate to the examples directory:**
   ```bash
   cd examples
   ```

2. **Start the PHP development server:**
   ```bash
   php -S localhost:8000
   ```

3. **Open your browser and visit:**
   ```
   http://localhost:8000
   ```

## Examples Overview

### 🎯 Basic Examples
- **[01-basic-round-robin.php](01-basic-round-robin.php)** - Simple 4-team tournament demonstrating core scheduling
- **[02-participants-and-metadata.php](02-participants-and-metadata.php)** - Working with seeded participants and custom data
- **[03-iterating-schedules.php](03-iterating-schedules.php)** - Different ways to access and display schedule data

### 🔧 Constraint System
- **[04-basic-constraints.php](04-basic-constraints.php)** - NoRepeatPairings and simple constraint usage
- **[05-seed-protection.php](05-seed-protection.php)** - Protecting high-seeded participants from early meetings
- **[06-rest-periods.php](06-rest-periods.php)** - Ensuring minimum rest between participant encounters
- **[07-metadata-constraints.php](07-metadata-constraints.php)** - Region-based and skill-level matching rules
- **[08-custom-constraints.php](08-custom-constraints.php)** - Creating custom constraint functions

### 🚀 Advanced Features
- **[09-multi-leg-home-away.php](09-multi-leg-home-away.php)** - Premier League style home and away seasons
- **[10-complex-tournament.php](10-complex-tournament.php)** - Gaming tournament with multiple constraint types
- **[11-error-handling.php](11-error-handling.php)** - Validation failures and exception demonstrations
- **[12-performance-patterns.php](12-performance-patterns.php)** - Memory-efficient iteration for large tournaments

## Features

### Interactive Interface
- Clean, responsive design using Tailwind CSS
- Visual representations of tournament data
- Code examples with syntax highlighting
- Navigation between examples

### Educational Content
- Progressive complexity from basic to advanced
- Real-world scenarios and use cases
- Detailed explanations of concepts
- Live code demonstrations

### Browser Compatibility
- Works in all modern browsers
- No additional dependencies required
- Pure PHP with HTML/CSS output

## Alternative Server Commands

If port 8000 is busy, you can use a different port:

```bash
# Use port 8080
php -S localhost:8080

# Use any available port
php -S localhost:0
```

## Requirements

- **PHP 8.2+** - Required for the Tactician library
- **Composer dependencies** - Run `composer install` from the project root before starting
- **Modern web browser** - For optimal viewing experience

## Troubleshooting

### Common Issues

**"Class not found" errors:**
```bash
# Make sure Composer dependencies are installed
cd ..
composer install
cd examples
php -S localhost:8000
```

**Port already in use:**
```bash
# Try a different port
php -S localhost:8001
```

**Permission denied:**
```bash
# Make sure you have read permissions on the project files
ls -la
```

### Server Output

You should see output similar to:
```
PHP 8.2.x Development Server (http://localhost:8000) started
```

### Stopping the Server

Press `Ctrl+C` (or `Cmd+C` on macOS) to stop the development server.

## Development

### Adding New Examples

1. Create a new PHP file following the naming pattern: `##-example-name.php`
2. Use the existing examples as templates for consistent structure
3. Include navigation links to maintain flow between examples
4. Update the main `index.php` to include your new example

### Design Guidelines

- **Responsive Layout**: Use Tailwind's responsive utilities
- **Consistent Navigation**: Include back/next links
- **Code Examples**: Always include working code snippets
- **Visual Feedback**: Use colors and icons to enhance understanding
- **Progressive Disclosure**: Start simple, add complexity gradually

## Documentation

For more information about the Tactician library:

- **[Usage Guide](../docs/USAGE.md)** - Comprehensive examples and patterns
- **[Architecture](../docs/ARCHITECTURE.md)** - Technical design and core components
- **[Main README](../README.md)** - Project overview and installation
- **[Contributing](../docs/CONTRIBUTING.md)** - Development setup and guidelines

## License

These examples are part of the Tactician project and are licensed under the MIT License.
