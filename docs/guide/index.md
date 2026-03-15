# Getting Started

djot-php is a PHP parser and converter for [Djot](https://djot.net/), a lightweight markup language created by John MacFarlane (author of CommonMark/Pandoc).

## Installation

Install via Composer:

```bash
composer require php-collective/djot
```

**Requirements:** PHP 8.2+

## Quick Start

### Basic Conversion

```php
use Djot\DjotConverter;

$converter = new DjotConverter();

$djot = <<<'DJOT'
# Hello World

This is _emphasized_ and *strong* text.

- List item 1
- List item 2
DJOT;

echo $converter->convert($djot);
```

Output:

```html
<h1>Hello World</h1>
<p>This is <em>emphasized</em> and <strong>strong</strong> text.</p>
<ul>
<li>List item 1</li>
<li>List item 2</li>
</ul>
```

### Safe Mode for User Input

When processing untrusted input, enable safe mode:

```php
$converter = new DjotConverter(safeMode: true);
$html = $converter->convert($userInput);
```

This blocks XSS vectors like `javascript:` URLs and event handlers.

### Using Extensions

Add built-in extensions for common features:

```php
use Djot\DjotConverter;
use Djot\Extension\ExternalLinksExtension;
use Djot\Extension\TableOfContentsExtension;

$converter = new DjotConverter();
$converter
    ->addExtension(new ExternalLinksExtension())
    ->addExtension(new TableOfContentsExtension());

echo $converter->convert($djot);
```

## Why Djot?

Djot offers several advantages over Markdown:

| Feature | Djot | Markdown |
|---------|------|----------|
| Emphasis | `_text_` | `*text*` or `_text_` |
| Strong | `*text*` | `**text**` or `__text__` |
| Strikethrough | `{-text-}` | `~~text~~` (extension) |
| Highlight | `{=text=}` | Not supported |
| Subscript | `H~2~O` | Not supported |
| Superscript | `E=mc^2^` | Not supported |
| Attributes | `{.class #id}` | Limited |

See [Why Djot?](./why-djot) for a detailed comparison.

## Output Formats

djot-php supports multiple renderers:

::: code-group

```php [HTML (default)]
$converter = new DjotConverter();
echo $converter->convert($djot);
```

```php [Plain Text]
use Djot\Renderer\PlainTextRenderer;

$document = $converter->parse($djot);
$renderer = new PlainTextRenderer();
echo $renderer->render($document);
```

```php [Markdown]
use Djot\Renderer\MarkdownRenderer;

$document = $converter->parse($djot);
$renderer = new MarkdownRenderer();
echo $renderer->render($document);
```

```php [ANSI (Terminal)]
use Djot\Renderer\AnsiRenderer;

$document = $converter->parse($djot);
$renderer = new AnsiRenderer();
echo $renderer->render($document);
```

:::

## CLI Tool

djot-php includes a command-line tool:

```bash
# Convert file to HTML
./vendor/bin/djot convert document.djot

# Convert to plain text
./vendor/bin/djot convert document.djot --format=text

# Output to file
./vendor/bin/djot convert document.djot -o output.html
```

## Next Steps

- [Syntax Reference](./syntax) - Learn Djot syntax
- [Extensions](/extensions/) - Add features like TOC, external links
- [Cookbook](/cookbook/) - Customization recipes
- [Playground](/playground) - Try it live in your browser
