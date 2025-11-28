# Djot PHP

[![CI](https://img.shields.io/github/actions/workflow/status/php-collective/djot-php/ci.yml?branch=master&style=flat-square)](https://github.com/php-collective/djot-php/actions)
[![codecov](https://img.shields.io/codecov/c/github/php-collective/djot-php?style=flat-square)](https://codecov.io/gh/php-collective/djot-php)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg?style=flat)](https://phpstan.org/)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg?style=flat-square)](https://php.net)
[![Software License](https://img.shields.io/badge/license-MIT-green.svg?style=flat-square)](LICENSE)

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

## Features

- **Block elements**: Headings, paragraphs, code blocks, block quotes, lists, tables, divs, definition lists, line blocks
- **Inline elements**: Emphasis, strong, links, images, code, superscript, subscript, highlight, insert, delete
- **Advanced**: Footnotes, math expressions, symbols, block attributes, raw HTML blocks, comments
- **Smart typography**: Curly quotes, en/em dashes, ellipsis
- **Multiple renderers**: HTML, plain text, Markdown output
- **Extensible**: Custom inline/block patterns, render events
- **File support**: Parse and convert files directly

## Example

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;

$converter = new DjotConverter();

// Customize link rendering
$converter->on('render.link', function (RenderEvent $event): void {
    $link = $event->getNode();
    if (str_starts_with($link->getDestination(), 'http')) {
        $link->setAttribute('target', '_blank');
    }
});

$djot = <<<'DJOT'
# Welcome

This is _emphasized_ and *strong* text with a [link](https://example.com).

| Name  | Role       |
|-------|------------|
| Alice | Developer  |
| Bob   | Designer   |

> "Djot is a light markup syntax."

```php
echo "Hello World";
DJOT;

echo $converter->convert($djot);
```

Output:

```html
<h1>Welcome</h1>
<p>This is <em>emphasized</em> and <strong>strong</strong> text with a <a href="https://example.com" target="_blank">link</a>.</p>
<table>
<thead>
<tr><th>Name</th><th>Role</th></tr>
</thead>
<tbody>
<tr><td>Alice</td><td>Developer</td></tr>
<tr><td>Bob</td><td>Designer</td></tr>
</tbody>
</table>
<blockquote>
<p>"Djot is a light markup syntax."</p>
</blockquote>
<pre><code class="language-php">echo "Hello World";
</code></pre>
```

## Demo: Sandbox with live preview
https://sandbox.dereuromark.de/sandbox/djot

## Documentation

- [Examples](docs/README.md) - Comprehensive usage examples
- [Syntax Reference](docs/syntax.md) - Complete Djot syntax guide
- [API Reference](docs/api.md) - Classes and methods
- [Converters](docs/converters.md) - Markdown/BBCode to Djot conversion
- [Cookbook](docs/cookbook.md) - Common customizations and recipes
- [Architecture](docs/architecture.md) - Internal design

## Security

When processing untrusted user input, enable safe mode for XSS protection:

```php
$converter = new DjotConverter(safeMode: true);
$html = $converter->convert($untrustedInput);
```

Safe mode automatically blocks dangerous URL schemes (`javascript:`, etc.), strips event handler attributes (`onclick`, etc.), and escapes raw HTML.

See [Security Considerations](docs/README.md#security-considerations) for details and advanced configuration.

## See Also

- [Djot](https://djot.net/) - Official Djot website with syntax reference and playground
- [jgm/djot](https://github.com/jgm/djot) - Reference implementation in JavaScript by John MacFarlane
