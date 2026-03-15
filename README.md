# Djot PHP

[![CI](https://img.shields.io/github/actions/workflow/status/php-collective/djot-php/ci.yml?branch=master&style=flat-square)](https://github.com/php-collective/djot-php/actions)
[![Coverage](https://codecov.io/gh/php-collective/djot-php/branch/master/graph/badge.svg)](https://codecov.io/gh/php-collective/djot-php)
[![Latest Stable Version](https://img.shields.io/packagist/v/php-collective/djot?style=flat-square)](https://packagist.org/packages/php-collective/djot)
[![Total Downloads](https://img.shields.io/packagist/dt/php-collective/djot?style=flat-square)](https://packagist.org/packages/php-collective/djot)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg?style=flat-square)](https://phpstan.org/)
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
- **Extensions**: Built-in extensions for external links, TOC, heading permalinks, @mentions, autolinks, default attributes
- **Extensible**: Custom inline/block patterns, render events
- **File support**: Parse and convert files directly

## Example

```php
use Djot\DjotConverter;
use Djot\Extension\ExternalLinksExtension;
use Djot\Extension\DefaultAttributesExtension;

$converter = new DjotConverter();

// Add extensions for common features
$converter
    ->addExtension(new ExternalLinksExtension())
    ->addExtension(new DefaultAttributesExtension([
        'table' => ['class' => 'table'],
    ]));

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
<p>This is <em>emphasized</em> and <strong>strong</strong> text with a <a href="https://example.com" target="_blank" rel="noopener noreferrer">link</a>.</p>
<table class="table">
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

## Documentation

Full documentation is available at **https://php-collective.github.io/djot-php/**

- [Getting Started](https://php-collective.github.io/djot-php/guide/) - Installation and quick start
- [Why Djot?](https://php-collective.github.io/djot-php/guide/why-djot) - Comparison with Markdown
- [Syntax Reference](https://php-collective.github.io/djot-php/guide/syntax) - Complete Djot syntax guide
- [Extensions](https://php-collective.github.io/djot-php/extensions/) - Built-in extensions
- [API Reference](https://php-collective.github.io/djot-php/reference/api) - Classes and methods
- [Cookbook](https://php-collective.github.io/djot-php/cookbook/) - Customization recipes

## Demo

- [Interactive Playground](https://php-collective.github.io/djot-php/playground) - Try djot-php in your browser
- [Sandbox](https://sandbox.dereuromark.de/sandbox/djot) - Full-featured sandbox with all options

## Security

When processing untrusted user input, enable safe mode for XSS protection:

```php
$converter = new DjotConverter(safeMode: true);
$html = $converter->convert($untrustedInput);
```

Safe mode automatically blocks dangerous URL schemes (`javascript:`, etc.), strips event handler attributes (`onclick`, etc.), and escapes raw HTML.

See [Safe Mode](https://php-collective.github.io/djot-php/guide/safe-mode) for details and advanced configuration.


## Implementations

- [php-collective/wp-djot](https://github.com/php-collective/wp-djot) - WordPress plugin for Djot support
- [dereuromark/cakephp-markup](https://github.com/dereuromark/cakephp-markup) - CakePHP integration with Djot helper and view class

## See Also

- [Djot](https://djot.net/) - Official Djot website with syntax reference and playground
- [jgm/djot](https://github.com/jgm/djot) - Reference implementation in JavaScript by John MacFarlane
- [JetBrains IDE support](https://github.com/php-collective/djot-intellij) - Plugin for PhpStorm, IntelliJ IDEA, WebStorm, etc.
