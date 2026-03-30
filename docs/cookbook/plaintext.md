# PlainTextRenderer Cookbook

Common recipes and customizations for the PlainTextRenderer.

## Table of Contents

- [Basic Usage](#basic-usage)
- [Search Indexing](#search-indexing)
- [SEO Meta Descriptions](#seo-meta-descriptions)
- [Email Fallback](#email-fallback)
- [Custom Symbol Handling](#custom-symbol-handling)
- [Link URL Extraction](#link-url-extraction)
- [Word Count and Reading Time](#word-count-and-reading-time)
- [Content Summary](#content-summary)

## Basic Usage

The PlainTextRenderer converts Djot AST to plain text, stripping all formatting:

```php
use Djot\DjotConverter;
use Djot\Renderer\PlainTextRenderer;

$converter = new DjotConverter();
$renderer = new PlainTextRenderer();

$djot = '# Welcome

This is *bold* and _italic_ text with a [link](https://example.com).';

$document = $converter->parse($djot);
$text = $renderer->render($document);

echo $text;
```

Output:
```
Welcome

This is bold and italic text with a link.
```

## Search Indexing

Extract clean text for full-text search indexing:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\CodeBlock;
use Djot\Renderer\PlainTextRenderer;

$converter = new DjotConverter();
$renderer = new PlainTextRenderer();

// Skip code blocks from search index (they often contain noise)
$renderer->on('render.code_block', function (RenderEvent $event): void {
    $event->setHtml('');
});

// Normalize symbols to searchable text
$renderer->on('render.symbol', function (RenderEvent $event): void {
    $node = $event->getNode();
    // Convert :emoji: to nothing or descriptive text
    $event->setHtml('');
});

$djot = '# API Documentation

The `getUserById` function returns a user object.

```php
$user = getUserById(123);
```

See the :info: icon for more details.';

$document = $converter->parse($djot);
$searchableText = $renderer->render($document);

// Now index $searchableText in your search engine
echo $searchableText;
```

Output:
```
API Documentation

The getUserById function returns a user object.

See the  icon for more details.
```

## SEO Meta Descriptions

Generate meta descriptions from content:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Heading;
use Djot\Renderer\PlainTextRenderer;

$converter = new DjotConverter();
$renderer = new PlainTextRenderer();

// Skip headings from description
$renderer->on('render.heading', function (RenderEvent $event): void {
    $event->setHtml('');
});

function generateMetaDescription(string $djot, int $maxLength = 160): string
{
    global $converter, $renderer;

    $document = $converter->parse($djot);
    $text = $renderer->render($document);

    // Clean up whitespace
    $text = preg_replace('/\s+/', ' ', trim($text));

    // Truncate at word boundary
    if (strlen($text) > $maxLength) {
        $text = substr($text, 0, $maxLength);
        $text = substr($text, 0, strrpos($text, ' ')) . '...';
    }

    return $text;
}

$djot = '# Ultimate Guide to PHP

PHP is a popular server-side scripting language. It powers millions of websites
worldwide including WordPress, Facebook, and Wikipedia.

## Getting Started

First, install PHP on your system...';

echo generateMetaDescription($djot);
```

Output:
```
PHP is a popular server-side scripting language. It powers millions of websites worldwide including WordPress, Facebook, and Wikipedia. First, install PHP...
```

## Email Fallback

Generate plain text version of HTML emails:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\ContentNodeInterface;
use Djot\Node\Inline\Link;
use Djot\Renderer\PlainTextRenderer;

$converter = new DjotConverter();
$renderer = new PlainTextRenderer();

// Show URLs inline for links
$renderer->on('render.link', function (RenderEvent $event): void {
    $node = $event->getNode();
    if (!$node instanceof Link) {
        return;
    }

    $url = $node->getDestination();
    // Get link text by letting default render children
    $event->preventDefault();

    // We need to render children manually
    $text = '';
    foreach ($node->getChildren() as $child) {
        if ($child instanceof ContentNodeInterface) {
            $text .= $child->getContent();
        }
    }

    if ($text === $url) {
        $event->setHtml($url);
    } else {
        $event->setHtml($text . ' (' . $url . ')');
    }
});

// Convert thematic breaks to ASCII
$renderer->on('render.thematic_break', function (RenderEvent $event): void {
    $event->setHtml("\n" . str_repeat('-', 40) . "\n\n");
});

$djot = '# Newsletter

Check out our [new product](https://shop.example.com/new)!

***

Visit [https://example.com](https://example.com) for more.';

$document = $converter->parse($djot);
echo $renderer->render($document);
```

Output:
```
Newsletter

Check out our new product (https://shop.example.com/new)!

----------------------------------------

Visit https://example.com for more.
```

## Custom Symbol Handling

Convert symbols to plain text equivalents:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Symbol;
use Djot\Renderer\PlainTextRenderer;

$converter = new DjotConverter();
$renderer = new PlainTextRenderer();

$symbolMap = [
    'check' => '[OK]',
    'x' => '[FAIL]',
    'warning' => '[WARNING]',
    'info' => '[INFO]',
    'arrow_right' => '->',
    'arrow_left' => '<-',
    'heart' => '<3',
    'star' => '*',
];

$renderer->on('render.symbol', function (RenderEvent $event) use ($symbolMap): void {
    $symbol = $event->getNode();
    if (!$symbol instanceof Symbol) {
        return;
    }

    $name = $symbol->getName();
    $replacement = $symbolMap[$name] ?? '';
    $event->setHtml($replacement);
});

$djot = 'Status: :check: Tests passing, :warning: 2 deprecations

Navigate: :arrow_left: Previous | Next :arrow_right:';

$document = $converter->parse($djot);
echo $renderer->render($document);
```

Output:
```
Status: [OK] Tests passing, [WARNING] 2 deprecations

Navigate: <- Previous | Next ->
```

## Link URL Extraction

Extract all URLs from a document:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Image;
use Djot\Renderer\PlainTextRenderer;

$converter = new DjotConverter();
$renderer = new PlainTextRenderer();

$urls = [];

$renderer->on('render.link', function (RenderEvent $event) use (&$urls): void {
    $node = $event->getNode();
    if ($node instanceof Link) {
        $urls[] = ['type' => 'link', 'url' => $node->getDestination()];
    }
});

$renderer->on('render.image', function (RenderEvent $event) use (&$urls): void {
    $node = $event->getNode();
    if ($node instanceof Image) {
        $urls[] = ['type' => 'image', 'url' => $node->getSource()];
    }
});

$djot = 'Visit [our site](https://example.com) and see ![logo](https://example.com/logo.png).

More at [docs](https://docs.example.com).';

$document = $converter->parse($djot);
$renderer->render($document);

print_r($urls);
```

Output:
```
Array
(
    [0] => Array ( [type] => link, [url] => https://example.com )
    [1] => Array ( [type] => image, [url] => https://example.com/logo.png )
    [2] => Array ( [type] => link, [url] => https://docs.example.com )
)
```

## Word Count and Reading Time

Calculate word count and estimated reading time:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Renderer\PlainTextRenderer;

$converter = new DjotConverter();
$renderer = new PlainTextRenderer();

// Skip code blocks from word count
$renderer->on('render.code_block', function (RenderEvent $event): void {
    $event->setHtml('');
});

function getReadingStats(string $djot): array
{
    global $converter, $renderer;

    $document = $converter->parse($djot);
    $text = $renderer->render($document);

    // Count words
    $words = str_word_count($text);

    // Average reading speed: 200-250 words per minute
    $readingSpeed = 225;
    $minutes = ceil($words / $readingSpeed);

    return [
        'words' => $words,
        'characters' => strlen($text),
        'reading_time_minutes' => $minutes,
        'reading_time_display' => $minutes . ' min read',
    ];
}

$djot = '# Introduction

This is a comprehensive guide to building web applications with PHP.
It covers everything from basic syntax to advanced patterns.

## Chapter 1

Lorem ipsum dolor sit amet, consectetur adipiscing elit...';

$stats = getReadingStats($djot);
print_r($stats);
```

## Content Summary

Generate a summary from the first paragraph:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Paragraph;
use Djot\Renderer\PlainTextRenderer;

$converter = new DjotConverter();
$renderer = new PlainTextRenderer();

$paragraphCount = 0;
$maxParagraphs = 1;

$renderer->on('render.paragraph', function (RenderEvent $event) use (&$paragraphCount, $maxParagraphs): void {
    $paragraphCount++;
    if ($paragraphCount > $maxParagraphs) {
        $event->setHtml('');
    }
});

// Skip headings
$renderer->on('render.heading', function (RenderEvent $event): void {
    $event->setHtml('');
});

$djot = '# Article Title

This is the introduction paragraph that summarizes the entire article.
It contains the key points readers need to know.

## Details

Here are more details that we do not want in the summary...';

$document = $converter->parse($djot);
$summary = trim($renderer->render($document));

echo "Summary: " . $summary;
```

Output:
```
Summary: This is the introduction paragraph that summarizes the entire article.
It contains the key points readers need to know.
```
