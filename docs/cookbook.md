# Cookbook

Common recipes and customizations for djot-php.

## Table of Contents

- [External Links](#external-links)
- [Custom Emoji/Symbols](#custom-emojisymbols)
- [Syntax Highlighting](#syntax-highlighting)
- [Table of Contents Generation](#table-of-contents-generation)
- [Image Processing](#image-processing)
- [Custom Admonitions](#custom-admonitions)
- [Heading Anchors](#heading-anchors)
- [Link Validation](#link-validation)
- [Content Security](#content-security)
- [Lazy Loading Images](#lazy-loading-images)
- [Custom Footnotes](#custom-footnotes)
- [Math Rendering](#math-rendering)
- [Working with the AST](#working-with-the-ast)

## External Links

Open external links in a new tab with security attributes:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Link;

$converter = new DjotConverter();

$converter->on('render.link', function (RenderEvent $event): void {
    $link = $event->getNode();
    if (!$link instanceof Link) {
        return;
    }

    $href = $link->getDestination();

    // Check if external link
    if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
        $link->setAttribute('target', '_blank');
        $link->setAttribute('rel', 'noopener noreferrer');
    }
});

echo $converter->convert('[External](https://example.com) and [internal](/page)');
```

Output:
```html
<p><a href="https://example.com" target="_blank" rel="noopener noreferrer">External</a> and <a href="/page">internal</a></p>
```

## Custom Emoji/Symbols

Convert `:name:` symbols to emoji or custom HTML:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Symbol;

$converter = new DjotConverter();

$emojis = [
    'heart' => '❤️',
    'star' => '⭐',
    'check' => '✅',
    'x' => '❌',
    'warning' => '⚠️',
    'info' => 'ℹ️',
    'fire' => '🔥',
    'rocket' => '🚀',
];

$converter->on('render.symbol', function (RenderEvent $event) use ($emojis): void {
    $symbol = $event->getNode();
    if (!$symbol instanceof Symbol) {
        return;
    }

    $name = $symbol->getName();
    if (isset($emojis[$name])) {
        $event->setHtml('<span class="emoji" title="' . $name . '">' . $emojis[$name] . '</span>');
    } else {
        // Keep original for unknown symbols
        $event->setHtml(':' . $name . ':');
    }
});

echo $converter->convert('I :heart: this :rocket: feature!');
```

Output:
```html
<p>I <span class="emoji" title="heart">❤️</span> this <span class="emoji" title="rocket">🚀</span> feature!</p>
```

## Syntax Highlighting

Add syntax highlighting classes for a JS library like Prism or Highlight.js:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\CodeBlock;

$converter = new DjotConverter();

$converter->on('render.code_block', function (RenderEvent $event): void {
    $block = $event->getNode();
    if (!$block instanceof CodeBlock) {
        return;
    }

    $lang = $block->getLanguage();
    $code = htmlspecialchars($block->getContent(), ENT_QUOTES, 'UTF-8');

    if ($lang) {
        // For Prism.js
        $html = '<pre class="language-' . $lang . '"><code class="language-' . $lang . '">' . $code . '</code></pre>' . "\n";
    } else {
        $html = '<pre><code>' . $code . '</code></pre>' . "\n";
    }

    $event->setHtml($html);
});
```

## Table of Contents Generation

Generate a table of contents from headings:

```php
use Djot\Parser\BlockParser;
use Djot\Renderer\HtmlRenderer;
use Djot\Node\Block\Heading;

function generateToc(string $djot): array
{
    $parser = new BlockParser();
    $document = $parser->parse($djot);

    $toc = [];
    foreach ($document->getChildren() as $node) {
        if ($node instanceof Heading) {
            $text = '';
            foreach ($node->getChildren() as $child) {
                if (method_exists($child, 'getContent')) {
                    $text .= $child->getContent();
                }
            }

            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text));
            $slug = trim($slug, '-');

            $toc[] = [
                'level' => $node->getLevel(),
                'text' => $text,
                'slug' => $slug,
            ];
        }
    }

    return $toc;
}

function renderTocHtml(array $toc): string
{
    $html = '<nav class="toc"><ul>';
    foreach ($toc as $item) {
        $indent = str_repeat('  ', $item['level'] - 1);
        $html .= $indent . '<li><a href="#' . $item['slug'] . '">' . htmlspecialchars($item['text']) . '</a></li>';
    }
    $html .= '</ul></nav>';

    return $html;
}

$djot = <<<'DJOT'
# Introduction
## Getting Started
## Installation
# Usage
## Basic Example
## Advanced Features
DJOT;

$toc = generateToc($djot);
echo renderTocHtml($toc);
```

## Image Processing

Add lazy loading, responsive images, or wrap images in figures:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Image;

$converter = new DjotConverter();

// Lazy loading
$converter->on('render.image', function (RenderEvent $event): void {
    $image = $event->getNode();
    if (!$image instanceof Image) {
        return;
    }

    $image->setAttribute('loading', 'lazy');
    $image->setAttribute('decoding', 'async');
});

// Or wrap in figure with caption
$converter->on('render.image', function (RenderEvent $event): void {
    $image = $event->getNode();
    if (!$image instanceof Image) {
        return;
    }

    $src = htmlspecialchars($image->getDestination(), ENT_QUOTES, 'UTF-8');
    $alt = htmlspecialchars($image->getAlt(), ENT_QUOTES, 'UTF-8');

    $html = '<figure>';
    $html .= '<img src="' . $src . '" alt="' . $alt . '" loading="lazy">';
    if ($alt) {
        $html .= '<figcaption>' . $alt . '</figcaption>';
    }
    $html .= '</figure>';

    $event->setHtml($html);
});

echo $converter->convert('![A beautiful sunset](sunset.jpg)');
```

Output:
```html
<figure><img src="sunset.jpg" alt="A beautiful sunset" loading="lazy"><figcaption>A beautiful sunset</figcaption></figure>
```

## Custom Admonitions

Style div blocks as admonitions (note, warning, tip, etc.):

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Div;

$converter = new DjotConverter();

$admonitionIcons = [
    'note' => 'ℹ️',
    'tip' => '💡',
    'warning' => '⚠️',
    'danger' => '🚨',
    'success' => '✅',
];

$converter->on('render.div', function (RenderEvent $event) use ($admonitionIcons): void {
    $div = $event->getNode();
    if (!$div instanceof Div) {
        return;
    }

    $class = $div->getAttribute('class') ?? '';
    foreach ($admonitionIcons as $type => $icon) {
        if (str_contains($class, $type)) {
            $div->setAttribute('class', 'admonition ' . $class);
            $div->setAttribute('data-icon', $icon);

            return;
        }
    }
});

$djot = <<<'DJOT'
::: warning
Be careful with this operation!
:::

::: tip
Here's a helpful hint.
:::
DJOT;

echo $converter->convert($djot);
```

## Heading Anchors

Add anchor links to headings:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Heading;
use Djot\Renderer\HtmlRenderer;

$converter = new DjotConverter();

$converter->on('render.heading', function (RenderEvent $event): void {
    $heading = $event->getNode();
    if (!$heading instanceof Heading) {
        return;
    }

    // Extract text content for slug
    $text = '';
    foreach ($heading->getChildren() as $child) {
        if (method_exists($child, 'getContent')) {
            $text .= $child->getContent();
        }
    }

    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text));
    $slug = trim($slug, '-');

    // Set ID for anchor
    $heading->setAttribute('id', $slug);

    // Note: To add the anchor link inside, you'd need to render children manually
    // This example just adds the ID attribute
});

echo $converter->convert('## Getting Started');
```

Output:
```html
<h2 id="getting-started">Getting Started</h2>
```

## Link Validation

Validate or transform links:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Link;

$converter = new DjotConverter();

// Add UTM parameters to external links
$converter->on('render.link', function (RenderEvent $event): void {
    $link = $event->getNode();
    if (!$link instanceof Link) {
        return;
    }

    $href = $link->getDestination();

    if (str_starts_with($href, 'https://')) {
        $separator = str_contains($href, '?') ? '&' : '?';
        $link->setDestination($href . $separator . 'utm_source=docs&utm_medium=link');
    }
});

// Or warn about potentially broken links
$brokenLinks = [];

$converter->on('render.link', function (RenderEvent $event) use (&$brokenLinks): void {
    $link = $event->getNode();
    if (!$link instanceof Link) {
        return;
    }

    $href = $link->getDestination();

    // Check for common issues
    if (str_starts_with($href, 'http://')) {
        $brokenLinks[] = "Insecure HTTP link: $href";
    }
    if (preg_match('/\s/', $href)) {
        $brokenLinks[] = "Link contains whitespace: $href";
    }
});
```

## Content Security

Sanitize or restrict certain content:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\RawBlock;
use Djot\Node\Inline\RawInline;

$converter = new DjotConverter();

// Disable raw HTML entirely
$converter->on('render.raw_block', function (RenderEvent $event): void {
    $event->setHtml('<!-- raw HTML disabled -->');
});

$converter->on('render.raw_inline', function (RenderEvent $event): void {
    $event->setHtml('<!-- raw HTML disabled -->');
});

// Or allow only certain tags
$allowedTags = ['span', 'div', 'p', 'br', 'strong', 'em'];

$converter->on('render.raw_inline', function (RenderEvent $event) use ($allowedTags): void {
    $raw = $event->getNode();
    if (!$raw instanceof RawInline) {
        return;
    }

    $content = $raw->getContent();
    $sanitized = strip_tags($content, $allowedTags);
    $event->setHtml($sanitized);
});
```

## Lazy Loading Images

Add native lazy loading to all images:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Image;

$converter = new DjotConverter();

$converter->on('render.image', function (RenderEvent $event): void {
    $image = $event->getNode();
    if (!$image instanceof Image) {
        return;
    }

    // Add lazy loading attributes
    $image->setAttribute('loading', 'lazy');
    $image->setAttribute('decoding', 'async');

    // Add dimensions if known (prevents layout shift)
    // $image->setAttribute('width', '800');
    // $image->setAttribute('height', '600');
});
```

## Custom Footnotes

Customize footnote rendering:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Footnote;
use Djot\Node\Inline\FootnoteRef;

$converter = new DjotConverter();

// Custom footnote reference style
$converter->on('render.footnote_ref', function (RenderEvent $event): void {
    $ref = $event->getNode();
    if (!$ref instanceof FootnoteRef) {
        return;
    }

    $label = htmlspecialchars($ref->getLabel(), ENT_QUOTES, 'UTF-8');
    $event->setHtml('<sup class="footnote-ref"><a href="#fn-' . $label . '" id="fnref-' . $label . '">[' . $label . ']</a></sup>');
});
```

## Math Rendering

Integrate with MathJax or KaTeX:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Math;

$converter = new DjotConverter();

// For KaTeX (renders on page load)
$converter->on('render.math', function (RenderEvent $event): void {
    $math = $event->getNode();
    if (!$math instanceof Math) {
        return;
    }

    $content = htmlspecialchars($math->getContent(), ENT_QUOTES, 'UTF-8');

    if ($math->isDisplay()) {
        $event->setHtml('<div class="math-display">$$' . $content . '$$</div>');
    } else {
        $event->setHtml('<span class="math-inline">$' . $content . '$</span>');
    }
});

// Don't forget to include KaTeX in your HTML:
// <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.0/dist/katex.min.css">
// <script src="https://cdn.jsdelivr.net/npm/katex@0.16.0/dist/katex.min.js"></script>
// <script src="https://cdn.jsdelivr.net/npm/katex@0.16.0/dist/contrib/auto-render.min.js"></script>
// <script>renderMathInElement(document.body);</script>
```

## Working with the AST

For complex transformations, work directly with the AST:

```php
use Djot\Parser\BlockParser;
use Djot\Renderer\HtmlRenderer;
use Djot\Node\Block\Heading;
use Djot\Node\Inline\Text;

$parser = new BlockParser();
$renderer = new HtmlRenderer();

$djot = <<<'DJOT'
# Hello World

This is a paragraph.

## Section One

Content here.
DJOT;

// Parse to AST
$document = $parser->parse($djot);

// Traverse and modify
foreach ($document->getChildren() as $node) {
    if ($node instanceof Heading) {
        // Add prefix to all headings
        $children = $node->getChildren();
        if (!empty($children) && $children[0] instanceof Text) {
            $text = $children[0];
            $text->setContent('📌 ' . $text->getContent());
        }

        // Add class to all headings
        $node->addClass('section-heading');
    }
}

// Render modified AST
echo $renderer->render($document);
```

Output:
```html
<h1 class="section-heading">📌 Hello World</h1>
<p>This is a paragraph.</p>
<h2 class="section-heading">📌 Section One</h2>
<p>Content here.</p>
```

## Combining Multiple Customizations

Chain multiple event handlers for comprehensive customization:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;

$converter = new DjotConverter();

// External links
$converter->on('render.link', function (RenderEvent $event): void {
    $link = $event->getNode();
    $href = $link->getDestination();
    if (str_starts_with($href, 'http')) {
        $link->setAttribute('target', '_blank');
        $link->setAttribute('rel', 'noopener');
    }
});

// Lazy images
$converter->on('render.image', function (RenderEvent $event): void {
    $event->getNode()->setAttribute('loading', 'lazy');
});

// Custom symbols
$converter->on('render.symbol', function (RenderEvent $event): void {
    $name = $event->getNode()->getName();
    $emoji = match ($name) {
        'check' => '✓',
        'x' => '✗',
        default => ':' . $name . ':',
    };
    $event->setHtml($emoji);
});

// Heading IDs
$converter->on('render.heading', function (RenderEvent $event): void {
    $heading = $event->getNode();
    $text = '';
    foreach ($heading->getChildren() as $child) {
        if (method_exists($child, 'getContent')) {
            $text .= $child->getContent();
        }
    }
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($text)));
    $heading->setAttribute('id', $slug);
});

// Now convert
$html = $converter->convert($djotContent);
```
