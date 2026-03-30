# MarkdownRenderer Cookbook

Common recipes and customizations for the MarkdownRenderer.

## Table of Contents

- [Basic Usage](#basic-usage)
- [Djot to Markdown Conversion](#djot-to-markdown-conversion)
- [Content Migration](#content-migration)
- [Custom Symbol Handling](#custom-symbol-handling)
- [Footnote Formatting](#footnote-formatting)
- [Link Transformation](#link-transformation)
- [Code Block Language Mapping](#code-block-language-mapping)
- [Stripping Unsupported Features](#stripping-unsupported-features)

## Basic Usage

The MarkdownRenderer converts Djot AST to CommonMark-compatible Markdown:

```php
use Djot\DjotConverter;
use Djot\Renderer\MarkdownRenderer;

$converter = new DjotConverter();
$renderer = new MarkdownRenderer();

$djot = '# Welcome

This is *bold* and _italic_ text with a [link](https://example.com).

- Item one
- Item two';

$document = $converter->parse($djot);
$markdown = $renderer->render($document);

echo $markdown;
```

Output:
```markdown
# Welcome

This is **bold** and *italic* text with a [link](https://example.com).

- Item one
- Item two
```

Note: Djot uses `*` for strong and `_` for emphasis, while Markdown uses `**` for strong and `*` for emphasis. The renderer handles this conversion automatically.

## Djot to Markdown Conversion

Convert Djot documents to Markdown for compatibility with Markdown-based systems:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Symbol;
use Djot\Renderer\MarkdownRenderer;

$converter = new DjotConverter();
$renderer = new MarkdownRenderer();

// Convert Djot symbols to Markdown-compatible alternatives
$renderer->on('render.symbol', function (RenderEvent $event): void {
    $symbol = $event->getNode();
    if (!$symbol instanceof Symbol) {
        return;
    }

    // GitHub-style emoji shortcodes work in many Markdown renderers
    $name = $symbol->getName();
    $event->setHtml(':' . $name . ':');
});

$djot = '# Project Status :rocket:

All tests passing :check:

## Features

- {=Highlighted=} important text
- E=mc^2^ formula
- H~2~O molecule';

$document = $converter->parse($djot);
echo $renderer->render($document);
```

Output:
```markdown
# Project Status :rocket:

All tests passing :check:

## Features

- <mark>Highlighted</mark> important text
- E=mc<sup>2</sup> formula
- H<sub>2</sub>O molecule
```

Note: Features like highlight, superscript, and subscript don't have native Markdown equivalents, so they're rendered as HTML which most Markdown processors support.

## Content Migration

Migrate content from Djot to a Markdown-based CMS:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\ContentNodeInterface;
use Djot\Node\Block\Div;
use Djot\Node\Inline\Span;
use Djot\Renderer\MarkdownRenderer;

$converter = new DjotConverter();
$renderer = new MarkdownRenderer();

// Convert Djot divs to Markdown blockquotes with labels
$renderer->on('render.div', function (RenderEvent $event): void {
    $div = $event->getNode();
    if (!$div instanceof Div) {
        return;
    }

    $class = $div->getAttribute('class') ?? '';

    // Render children first
    $content = '';
    foreach ($div->getChildren() as $child) {
        foreach ($child->getChildren() as $inline) {
            if ($inline instanceof ContentNodeInterface) {
                $content .= $inline->getContent();
            }
        }
    }

    if (str_contains($class, 'warning')) {
        $event->setHtml("> **Warning:** " . trim($content) . "\n\n");
    } elseif (str_contains($class, 'note')) {
        $event->setHtml("> **Note:** " . trim($content) . "\n\n");
    }
});

$djot = '# Migration Guide

::: warning
This is a breaking change.
:::

Follow these steps:

1. Update dependencies
2. Run migrations';

$document = $converter->parse($djot);
echo $renderer->render($document);
```

Output:
```markdown
# Migration Guide

> **Warning:** This is a breaking change.

Follow these steps:

1. Update dependencies
2. Run migrations
```

## Custom Symbol Handling

Map Djot symbols to GitHub-compatible emoji or remove them:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Symbol;
use Djot\Renderer\MarkdownRenderer;

$converter = new DjotConverter();
$renderer = new MarkdownRenderer();

// Map to GitHub emoji shortcodes
$emojiMap = [
    'heart' => ':heart:',
    'star' => ':star:',
    'check' => ':white_check_mark:',
    'x' => ':x:',
    'warning' => ':warning:',
    'info' => ':information_source:',
    'fire' => ':fire:',
    'rocket' => ':rocket:',
    'thumbsup' => ':+1:',
    'thumbsdown' => ':-1:',
];

$renderer->on('render.symbol', function (RenderEvent $event) use ($emojiMap): void {
    $symbol = $event->getNode();
    if (!$symbol instanceof Symbol) {
        return;
    }

    $name = $symbol->getName();
    $replacement = $emojiMap[$name] ?? ':' . $name . ':';
    $event->setHtml($replacement);
});

$djot = 'Status: :check: Complete :rocket:

Feedback: :thumbsup: Approved';

$document = $converter->parse($djot);
echo $renderer->render($document);
```

Output:
```markdown
Status: :white_check_mark: Complete :rocket:

Feedback: :+1: Approved
```

## Footnote Formatting

Customize footnote output for different Markdown flavors:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\ContentNodeInterface;
use Djot\Node\Block\Footnote;
use Djot\Node\Inline\FootnoteRef;
use Djot\Renderer\MarkdownRenderer;

$converter = new DjotConverter();
$renderer = new MarkdownRenderer();

// Some systems prefer numbered footnotes
$footnoteCounter = 0;
$footnoteMap = [];

$renderer->on('render.footnote_ref', function (RenderEvent $event) use (&$footnoteCounter, &$footnoteMap): void {
    $ref = $event->getNode();
    if (!$ref instanceof FootnoteRef) {
        return;
    }

    $label = $ref->getLabel();
    if (!isset($footnoteMap[$label])) {
        $footnoteCounter++;
        $footnoteMap[$label] = $footnoteCounter;
    }

    $event->setHtml('[^' . $footnoteMap[$label] . ']');
});

$renderer->on('render.footnote', function (RenderEvent $event) use (&$footnoteMap): void {
    $footnote = $event->getNode();
    if (!$footnote instanceof Footnote) {
        return;
    }

    $label = $footnote->getLabel();
    $num = $footnoteMap[$label] ?? $label;

    // Get content
    $content = '';
    foreach ($footnote->getChildren() as $child) {
        foreach ($child->getChildren() as $inline) {
            if ($inline instanceof ContentNodeInterface) {
                $content .= $inline->getContent();
            }
        }
    }

    $event->setHtml('[^' . $num . ']: ' . trim($content) . "\n");
});

$djot = 'See the documentation[^docs] and examples[^examples].

[^docs]: Official documentation at example.com
[^examples]: Code examples repository';

$document = $converter->parse($djot);
echo $renderer->render($document);
```

## Link Transformation

Transform links during conversion:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\ContentNodeInterface;
use Djot\Node\Inline\Link;
use Djot\Renderer\MarkdownRenderer;

$converter = new DjotConverter();
$renderer = new MarkdownRenderer();

// Convert internal wiki-style links to standard Markdown
$renderer->on('render.link', function (RenderEvent $event): void {
    $link = $event->getNode();
    if (!$link instanceof Link) {
        return;
    }

    $url = $link->getDestination();

    // Convert relative .djot links to .md
    if (str_ends_with($url, '.djot')) {
        $newUrl = substr($url, 0, -5) . '.md';

        // Get link text
        $text = '';
        foreach ($link->getChildren() as $child) {
            if ($child instanceof ContentNodeInterface) {
                $text .= $child->getContent();
            }
        }

        $event->setHtml('[' . $text . '](' . $newUrl . ')');
    }
});

$djot = 'See [Getting Started](getting-started.djot) and [API Reference](api.djot).';

$document = $converter->parse($djot);
echo $renderer->render($document);
```

Output:
```markdown
See [Getting Started](getting-started.md) and [API Reference](api.md).
```

## Code Block Language Mapping

Map language identifiers between systems:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\CodeBlock;
use Djot\Renderer\MarkdownRenderer;

$converter = new DjotConverter();
$renderer = new MarkdownRenderer();

// Map language aliases
$languageMap = [
    'js' => 'javascript',
    'ts' => 'typescript',
    'py' => 'python',
    'rb' => 'ruby',
    'sh' => 'bash',
    'yml' => 'yaml',
];

$renderer->on('render.code_block', function (RenderEvent $event) use ($languageMap): void {
    $codeBlock = $event->getNode();
    if (!$codeBlock instanceof CodeBlock) {
        return;
    }

    $lang = $codeBlock->getLanguage() ?? '';
    $content = $codeBlock->getContent();

    // Map language if needed
    $mappedLang = $languageMap[$lang] ?? $lang;

    $event->setHtml("```" . $mappedLang . "\n" . $content . "\n```\n\n");
});

$djot = '```js
console.log("Hello");
```

```py
print("Hello")
```';

$document = $converter->parse($djot);
echo $renderer->render($document);
```

Output:
````markdown
```javascript
console.log("Hello");
```

```python
print("Hello")
```
````

## Stripping Unsupported Features

Remove Djot-specific features that don't translate to Markdown:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\ContentNodeInterface;
use Djot\Node\Block\Div;
use Djot\Node\Inline\Highlight;
use Djot\Node\Inline\Insert;
use Djot\Node\Inline\Span;
use Djot\Renderer\MarkdownRenderer;

$converter = new DjotConverter();
$renderer = new MarkdownRenderer();

// Strip divs, just render content
$renderer->on('render.div', function (RenderEvent $event): void {
    // Let default rendering handle children, div wrapper is removed
});

// Strip spans, just render content
$renderer->on('render.span', function (RenderEvent $event): void {
    // Let default rendering handle children, span wrapper is removed
});

// Convert highlight to bold (approximate)
$renderer->on('render.highlight', function (RenderEvent $event): void {
    $node = $event->getNode();

    $content = '';
    foreach ($node->getChildren() as $child) {
        if ($child instanceof ContentNodeInterface) {
            $content .= $child->getContent();
        }
    }

    $event->setHtml('**' . $content . '**');
});

// Convert insert to italic (approximate)
$renderer->on('render.insert', function (RenderEvent $event): void {
    $node = $event->getNode();

    $content = '';
    foreach ($node->getChildren() as $child) {
        if ($child instanceof ContentNodeInterface) {
            $content .= $child->getContent();
        }
    }

    $event->setHtml('*' . $content . '*');
});

$djot = 'This has {=highlighted=} and {+inserted+} text.

::: note
This is a note.
:::';

$document = $converter->parse($djot);
echo $renderer->render($document);
```

Output:
```markdown
This has **highlighted** and *inserted* text.

This is a note.
```
