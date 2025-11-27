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

## Examples

### Text Formatting

```php
$djot = <<<'DJOT'
This is _emphasized_ and *strong* text.

You can also use {=highlighted=}, {+inserted+}, and {-deleted-} text.

Superscript: E=mc^2^ and subscript: H~2~O
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<p>This is <em>emphasized</em> and <strong>strong</strong> text.</p>
<p>You can also use <mark>highlighted</mark>, <ins>inserted</ins>, and <del>deleted</del> text.</p>
<p>Superscript: E=mc<sup>2</sup> and subscript: H<sub>2</sub>O</p>
```

### Headings

```php
$djot = <<<'DJOT'
# Heading 1
## Heading 2
### Heading 3
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<h1>Heading 1</h1>
<h2>Heading 2</h2>
<h3>Heading 3</h3>
```

### Links and Images

```php
$djot = <<<'DJOT'
[Visit Example](https://example.com)

![Alt text](image.png)

Reference style: [Example][ex]

[ex]: https://example.com
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<p><a href="https://example.com">Visit Example</a></p>
<p><img src="image.png" alt="Alt text"></p>
<p>Reference style: <a href="https://example.com">Example</a></p>
```

### Lists

```php
$djot = <<<'DJOT'
Bullet list:

- Item 1
- Item 2
  - Nested item
- Item 3

Ordered list:

1. First
2. Second
3. Third

Task list:

- [ ] Todo
- [x] Done
DJOT;

echo $converter->convert($djot);
```

### Code Blocks

````php
$djot = <<<'DJOT'
Inline `code` works too.

```php
function hello(): void {
    echo "Hello World";
}
```
DJOT;

echo $converter->convert($djot);
````

Output:
```html
<p>Inline <code>code</code> works too.</p>
<pre><code class="language-php">function hello(): void {
    echo "Hello World";
}
</code></pre>
```

### Tables

```php
$djot = <<<'DJOT'
| Name    | Age | City     |
|:--------|:---:|---------:|
| Alice   | 30  | New York |
| Bob     | 25  | London   |
^ Table caption
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<table>
<caption>Table caption</caption>
<thead>
<tr><th style="text-align: left">Name</th><th style="text-align: center">Age</th><th style="text-align: right">City</th></tr>
</thead>
<tbody>
<tr><td style="text-align: left">Alice</td><td style="text-align: center">30</td><td style="text-align: right">New York</td></tr>
<tr><td style="text-align: left">Bob</td><td style="text-align: center">25</td><td style="text-align: right">London</td></tr>
</tbody>
</table>
```

### Block Quotes

```php
$djot = <<<'DJOT'
> This is a quote.
> It can span multiple lines.
>
> And include multiple paragraphs.
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<blockquote>
<p>This is a quote.
It can span multiple lines.</p>
<p>And include multiple paragraphs.</p>
</blockquote>
```

### Divs with Attributes

```php
$djot = <<<'DJOT'
::: warning
This is a warning message.
:::

{.highlight #important}
This paragraph has a class and ID.
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<div class="warning">
<p>This is a warning message.</p>
</div>
<p class="highlight" id="important">This paragraph has a class and ID.</p>
```

### Footnotes

```php
$djot = <<<'DJOT'
Here is a statement[^1] with a footnote.

[^1]: This is the footnote content.
DJOT;

echo $converter->convert($djot);
```

### Math Expressions

```php
$djot = <<<'DJOT'
Inline math: $`E = mc^2`$

Display math:

$$`\sum_{i=0}^{n} i = \frac{n(n+1)}{2}`$$
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<p>Inline math: <span class="math inline">\(E = mc^2\)</span></p>
<p>Display math:</p>
<span class="math display">\[\sum_{i=0}^{n} i = \frac{n(n+1)}{2}\]</span>
```

### Smart Typography

```php
$djot = <<<'DJOT'
"Quoted text" and 'single quotes'

Dashes: en--dash and em---dash

Ellipsis...
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<p>"Quoted text" and 'single quotes'</p>
<p>Dashes: en–dash and em—dash</p>
<p>Ellipsis…</p>
```

## Event System

Customize rendering by listening to events:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;

$converter = new DjotConverter();

// Add target="_blank" to external links
$converter->on('render.link', function (RenderEvent $event): void {
    $link = $event->getNode();
    $href = $link->getDestination();

    if (str_starts_with($href, 'http')) {
        $link->setAttribute('target', '_blank');
        $link->setAttribute('rel', 'noopener noreferrer');
    }
});

// Custom emoji rendering
$converter->on('render.symbol', function (RenderEvent $event): void {
    $symbol = $event->getNode();
    $emoji = match ($symbol->getName()) {
        'heart' => '❤️',
        'star' => '⭐',
        'check' => '✅',
        default => ':' . $symbol->getName() . ':',
    };
    $event->setHtml($emoji);
});

echo $converter->convert('[Example](https://example.com) I :heart: this!');
// Output: <p><a href="https://example.com" target="_blank" rel="noopener noreferrer">Example</a> I ❤️ this!</p>
```

## XHTML Mode

For XHTML-compatible output:

```php
$converter = new DjotConverter(xhtml: true);
echo $converter->convert("Line one\\\nLine two");
// Output: <p>Line one<br />
// Line two</p>
```

## Alternative Renderers

### Plain Text

Extract plain text for search indexing or SEO:

```php
use Djot\Renderer\PlainTextRenderer;

$document = $converter->parse('Hello *world*!');
$renderer = new PlainTextRenderer();
echo $renderer->render($document);
// Output: Hello world!
```

### Markdown

Convert Djot to CommonMark Markdown:

```php
use Djot\Renderer\MarkdownRenderer;

$document = $converter->parse('Hello *world*!');
$renderer = new MarkdownRenderer();
echo $renderer->render($document);
// Output: Hello **world**!
```

## File Operations

```php
// Convert file directly
$html = $converter->convertFile('/path/to/document.djot');

// Or parse to AST first
$document = $converter->parseFile('/path/to/document.djot');
$html = $converter->render($document);
```

## Custom Syntax Patterns

Extend Djot with custom inline and block patterns:

```php
// Add @mention support
$parser = $converter->getParser()->getInlineParser();
$parser->addInlinePattern('/@([a-zA-Z0-9_]+)/', function ($match, $groups, $p) {
    $link = new Link('/users/' . $groups[1]);
    $link->appendChild(new Text('@' . $groups[1]));
    return $link;
});

echo $converter->convert('Hello @john!');
// Output: <p>Hello <a href="/users/john">@john</a>!</p>
```

See the [Cookbook](docs/cookbook.md) for more examples including wiki links, hashtags, admonitions, and custom block patterns.

## Documentation

See [docs/](docs/) for detailed documentation:

- [Syntax Reference](docs/syntax.md) - Complete Djot syntax guide
- [API Reference](docs/api.md) - Classes and methods
- [Cookbook](docs/cookbook.md) - Common customizations and recipes
- [Architecture](docs/architecture.md) - Internal design

## Requirements

- PHP 8.2+

## See Also

- [Djot](https://djot.net/) - Official Djot website with syntax reference and playground
- [jgm/djot](https://github.com/jgm/djot) - Reference implementation in JavaScript by John MacFarlane

## License

MIT
