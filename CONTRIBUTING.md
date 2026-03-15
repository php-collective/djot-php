# Contributing to djot-php

Thank you for your interest in contributing to djot-php!

## Getting Started

```bash
# Clone the repository
git clone https://github.com/php-collective/djot-php.git
cd djot-php

# Install dependencies
composer install
```

## Development Workflow

### Running Tests

```bash
# Run all tests
composer test

# Run only project tests (excludes official djot spec tests)
vendor/bin/phpunit --testsuite own

# Run only the official djot test suite
vendor/bin/phpunit --testsuite official

# Run a specific test file
vendor/bin/phpunit tests/TestCase/DjotConverterTest.php

# Run a specific test method
vendor/bin/phpunit --filter testHeading
```

### Test Suites

| Suite | Description |
|-------|-------------|
| `default` | All tests |
| `own` | Project tests only (recommended for development) |
| `official` | Official djot specification tests |

The `own` suite excludes the official test suite which may have expected failures due to implementation differences.

### Code Style

This project follows PHP Collective coding standards.

```bash
# Check code style
composer cs-check

# Auto-fix code style issues
composer cs-fix
```

### Static Analysis

```bash
# Run PHPStan (level 8)
composer stan
```

### Full Check

```bash
# Run all checks (code style + tests)
composer check
```

## Project Structure

```
src/
├── DjotConverter.php       # Main entry point
├── Parser/
│   ├── BlockParser.php     # Block-level parsing
│   └── InlineParser.php    # Inline content parsing
├── Renderer/
│   ├── HtmlRenderer.php    # HTML output
│   ├── PlainTextRenderer.php
│   └── MarkdownRenderer.php
├── Node/                   # AST node classes
│   ├── Block/              # Block-level nodes
│   └── Inline/             # Inline nodes
└── Event/
    └── RenderEvent.php     # Render customization events
```

## Writing Tests

Tests are located in `tests/TestCase/`. Follow the existing patterns:

```php
public function testFeatureName(): void
{
    $djot = "input text";
    $expected = "<p>expected output</p>\n";

    $this->assertSame($expected, $this->converter->convert($djot));
}
```

For tests that check partial output:

```php
public function testFeatureContains(): void
{
    $result = $this->converter->convert($djot);

    $this->assertStringContainsString('expected part', $result);
}
```

## Documentation Site

The documentation is built with [VitePress](https://vitepress.dev/).

### Local Development

```bash
cd docs
npm install    # Fetches Djot grammar from djot-intellij automatically
npm run docs:dev
```

This starts a dev server at `http://localhost:5173/djot-php/` with hot reload.

The Djot syntax highlighting grammar is fetched from [djot-intellij](https://github.com/php-collective/djot-intellij) during `npm install` (via postinstall hook), ensuring you always have the latest version.

### Building

```bash
cd docs
npm run docs:build
npm run docs:preview  # Preview the build
```

## Pull Request Guidelines

1. Create a feature branch from `master`
2. Write tests for new functionality
3. Ensure all tests pass: `vendor/bin/phpunit --testsuite own`
4. Ensure code style passes: `composer cs-check`
5. Ensure PHPStan passes: `composer stan`
6. Submit a pull request with a clear description

## Reporting Issues

When reporting bugs, please include:

- PHP version
- Minimal djot input that reproduces the issue
- Expected output
- Actual output

## Resources

- [Djot Syntax Reference](https://djot.net/)
- [Official Djot Repository](https://github.com/jgm/djot)
- [Project Documentation](docs/README.md)
