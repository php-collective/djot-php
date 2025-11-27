# Djot PHP

A PHP parser for [Djot](https://djot.net/), a modern light markup language created by John MacFarlane (author of CommonMark/Pandoc).

## Installation

```bash
composer require php-collective/djot
```

## Quick Start

```php
use Djot\DjotConverter;

$converter = new DjotConverter();
$html = $converter->convert('Hello *world*!');
// Output: <p>Hello <strong>world</strong>!</p>
```

## Basic Usage

```php
use Djot\DjotConverter;

$djot = <<<DJOT
# Welcome

This is _emphasized_ and *strong* text.

- List item 1
- List item 2

[Visit Example](https://example.com)
DJOT;

$converter = new DjotConverter();
echo $converter->convert($djot);
```

## XHTML Mode

```php
$converter = new DjotConverter(xhtml: true);
echo $converter->convert('---'); // Output: <hr />
```

## Features

- **Block elements**: Headings, paragraphs, code blocks, block quotes, lists, tables, divs, definition lists
- **Inline elements**: Emphasis, strong, links, images, code, superscript, subscript
- **Advanced**: Footnotes, math, symbols, block attributes, raw HTML blocks
- **Smart typography**: Curly quotes, en/em dashes, ellipsis

## Documentation

See [docs/](docs/) for detailed documentation:

- [Syntax Reference](docs/syntax.md)
- [API Reference](docs/api.md)
- [Architecture](docs/architecture.md)

## Requirements

- PHP 8.2+

## License

MIT
