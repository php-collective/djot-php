# Custom Syntax Patterns

Extend Djot with custom inline and block syntax patterns.

## Table of Contents

- [Working with the AST](#working-with-the-ast)
- [Combining Multiple Customizations](#combining-multiple-customizations)
- [Custom Inline Patterns](#custom-inline-patterns)
- [Custom Block Patterns](#custom-block-patterns)
- [Extracting Content Metadata](#extracting-content-metadata)

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
        if ($child instanceof \Djot\Node\ContentNodeInterface) {
            $text .= $child->getContent();
        }
    }
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($text)));
    $heading->setAttribute('id', $slug);
});

// Now convert
$html = $converter->convert($djotContent);
```

## Custom Inline Patterns

Extend Djot with custom inline syntax by registering patterns on the InlineParser.

### @Mentions

For standard @mention handling, use [MentionsExtension](/extensions/#mentionsextension).

For custom mention logic (user lookup, validation, etc.), use inline patterns:

```php
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Text;

$parser = $converter->getParser()->getInlineParser();

$parser->addInlinePattern('/@([a-zA-Z0-9_]+)/', function ($match, $groups) {
    $username = $groups[1];
    // Custom logic: lookup user, validate, etc.
    $link = new Link('/profile/' . strtolower($username));
    $link->appendChild(new Text('@' . $username));
    return $link;
});
```

### Wiki Links

Support wiki-style links using a `wiki:` URL scheme. This approach uses standard
djot link syntax and avoids ambiguity with nested spans (since `[[x]{.a}]{.b}` is valid djot).

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;

$converter = new DjotConverter();

// Handle wiki: scheme in links
$converter->on('render.link', function (RenderEvent $event): void {
    $link = $event->getNode();
    $url = $link->getDestination() ?? '';

    if (str_starts_with($url, 'wiki:')) {
        $target = substr($url, 5); // Remove 'wiki:' prefix

        // If empty, use the link text as target
        if ($target === '') {
            $text = '';
            foreach ($link->getChildren() as $child) {
                if ($child instanceof \Djot\Node\Inline\Text) {
                    $text .= $child->getContent();
                }
            }
            $target = $text;
        }

        // Convert to URL slug
        $slug = strtolower(str_replace(' ', '-', $target));
        $link->setDestination('/wiki/' . rawurlencode($slug));
        $link->setAttribute('class', 'wikilink');
    }
});

echo $converter->convert('See [Home Page](wiki:) and [the API docs](wiki:API Reference).');
```

Output:
```html
<p>See <a href="/wiki/home-page" class="wikilink">Home Page</a> and <a href="/wiki/api-reference" class="wikilink">the API docs</a>.</p>
```

The syntax:
- `[Page Name](wiki:)` - link text becomes the target
- `[display text](wiki:Page Name)` - explicit target with custom display text

#### Configurable Base URL

```php
$wikiBaseUrl = '/docs/';  // or 'https://wiki.example.com/'

$converter->on('render.link', function (RenderEvent $event) use ($wikiBaseUrl): void {
    $link = $event->getNode();
    $url = $link->getDestination() ?? '';

    if (str_starts_with($url, 'wiki:')) {
        $target = substr($url, 5);

        if ($target === '') {
            $text = '';
            foreach ($link->getChildren() as $child) {
                if ($child instanceof \Djot\Node\Inline\Text) {
                    $text .= $child->getContent();
                }
            }
            $target = $text;
        }

        $slug = strtolower(str_replace(' ', '-', $target));
        $link->setDestination($wikiBaseUrl . rawurlencode($slug));
    }
});
```

#### With File Extension

```php
use Djot\Event\RenderEvent;

// Add .html extension for static sites
$converter->on('render.link', function (RenderEvent $event): void {
    $link = $event->getNode();
    $url = $link->getDestination() ?? '';

    if (str_starts_with($url, 'wiki:')) {
        $target = substr($url, 5);

        if ($target === '') {
            $text = '';
            foreach ($link->getChildren() as $child) {
                if ($child instanceof \Djot\Node\Inline\Text) {
                    $text .= $child->getContent();
                }
            }
            $target = $text;
        }

        $slug = strtolower(str_replace(' ', '-', $target));
        $link->setDestination('/pages/' . $slug . '.html');
    }
});

// [Installation Guide](wiki:) → <a href="/pages/installation-guide.html">Installation Guide</a>
```

### Hashtags

Convert `#hashtag` to tag links:

```php
$parser->addInlinePattern('/#([a-zA-Z][a-zA-Z0-9_]*)/', function ($match, $groups, $p) {
    $tag = $groups[1];
    $link = new Link('/tags/' . strtolower($tag));
    $link->appendChild(new Text('#' . $tag));
    $link->setAttribute('class', 'hashtag');
    return $link;
});

echo $converter->convert('Check out #PHP and #WebDev!');
```

### Root-Relative Links

Support `<~/path>` and `<~/path|display text>` for site-root-relative links:

```php
use Djot\DjotConverter;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Text;

$converter = new DjotConverter();
$parser = $converter->getParser()->getInlineParser();

// Pattern matches <~/path> or <~/path|display text>
$parser->addInlinePattern('/<~([^>|]+)(?:\|([^>]+))?>/', function ($match, $groups, $p) {
    $path = trim($groups[1]);
    $display = isset($groups[2]) ? trim($groups[2]) : basename($path);

    // Build root-relative URL
    $url = '/' . ltrim($path, '/');

    $link = new Link($url);
    $link->appendChild(new Text($display));
    $link->setAttribute('class', 'internal-link');
    return $link;
});

echo $converter->convert('See <~/docs/installation> and <~/api/users|the API>.');
```

Output:
```html
<p>See <a href="/docs/installation" class="internal-link">installation</a> and <a href="/api/users" class="internal-link">the API</a>.</p>
```

#### Configurable Base Path

```php
$basePath = '/docs/v2';  // Prepend to all paths

$parser->addInlinePattern('/<~([^>|]+)(?:\|([^>]+))?>/', function ($match, $groups, $p) use ($basePath) {
    $path = trim($groups[1]);
    $display = isset($groups[2]) ? trim($groups[2]) : basename($path);

    $url = $basePath . '/' . ltrim($path, '/');

    $link = new Link($url);
    $link->appendChild(new Text($display));
    return $link;
});

// <~/guide> → <a href="/docs/v2/guide">guide</a>
```

### Conditional Patterns

Return `null` to fall back to default parsing:

```php
$parser->addInlinePattern('/@([a-zA-Z0-9_]+)/', function ($match, $groups, $p) {
    // Only handle @admin specially
    if ($groups[1] === 'admin') {
        $link = new Link('/admin');
        $link->appendChild(new Text('Administrator'));
        return $link;
    }
    return null;  // Let default parsing handle other @mentions
});
```

### Override Built-in Syntax

Custom patterns are checked before built-in syntax, allowing overrides:

```php
// Replace **bold** with custom brackets
$parser->addInlinePattern('/\*\*([^*]+)\*\*/', function ($match, $groups, $p) {
    return new Text('【' . $groups[1] . '】');
});

echo $converter->convert('This is **important** text.');
// Output: <p>This is 【important】 text.</p>
```

## Custom Block Patterns

Extend Djot with custom block-level syntax by registering patterns on the BlockParser.

### Admonition Blocks

For standard admonition support (note, warning, tip, etc.), use [AdmonitionExtension](/extensions/#admonitionextension).

For custom admonition syntax (like `!!! type`), use block patterns:

```php
use Djot\DjotConverter;
use Djot\Node\Block\Div;

$converter = new DjotConverter();
$parser = $converter->getParser();

$parser->addBlockPattern('/^!!!\s*(note|warning|danger)\s*$/', function ($lines, $start, $parent, $p) {
    preg_match('/^!!!\s*(note|warning|danger)\s*$/', $lines[$start], $m);
    $type = $m[1];

    // Collect indented content
    $content = [];
    $i = $start + 1;
    while ($i < count($lines) && preg_match('/^\s+(.*)$/', $lines[$i], $contentMatch)) {
        $content[] = $contentMatch[1];
        $i++;
    }

    $div = new Div();
    $div->setAttribute('class', 'admonition ' . $type);
    $p->parseBlockContent($div, $content);  // Parse nested content
    $parent->appendChild($div);

    return $i - $start;  // Return lines consumed
});

$djot = <<<'DJOT'
!!! warning
    Be careful with this feature.
    It may cause issues.

Regular paragraph.
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<div class="admonition warning">
<p>Be careful with this feature.
It may cause issues.</p>
</div>
<p>Regular paragraph.</p>
```

### Spoiler/Collapsible Blocks

Support `:::spoiler ... :::` syntax:

```php
$parser->addBlockPattern('/^:::spoiler\s*$/', function ($lines, $start, $parent, $p) {
    $content = [];
    $i = $start + 1;

    // Collect until closing :::
    while ($i < count($lines) && !preg_match('/^:::\s*$/', $lines[$i])) {
        $content[] = $lines[$i];
        $i++;
    }

    $div = new Div();
    $div->setAttribute('class', 'spoiler');
    $p->parseBlockContent($div, $content);
    $parent->appendChild($div);

    // +1 for closing :::
    return ($i < count($lines)) ? $i - $start + 1 : $i - $start;
});

$djot = <<<'DJOT'
:::spoiler
This content is hidden by default.

It can contain **formatted** text.
:::
DJOT;
```

### Tab Containers

Support `=== Tab Title` syntax:

```php
$parser->addBlockPattern('/^===\s+(.+)$/', function ($lines, $start, $parent, $p) {
    preg_match('/^===\s+(.+)$/', $lines[$start], $m);
    $title = trim($m[1]);

    // Collect content until next === or end
    $content = [];
    $i = $start + 1;
    while ($i < count($lines) && !preg_match('/^===\s+/', $lines[$i])) {
        $content[] = $lines[$i];
        $i++;
    }

    $div = new Div();
    $div->setAttribute('class', 'tab');
    $div->setAttribute('data-title', $title);
    $p->parseBlockContent($div, $content);
    $parent->appendChild($div);

    return $i - $start;
});

$djot = <<<'DJOT'
=== First Tab
Content of first tab.

=== Second Tab
Content of second tab.
DJOT;
```

### Combining Inline and Block Patterns

Use both pattern types together:

```php
$converter = new DjotConverter();
$parser = $converter->getParser();
$inlineParser = $parser->getInlineParser();

// Inline: @mentions
$inlineParser->addInlinePattern('/@(\w+)/', function ($m, $g, $p) {
    $link = new Link('/u/' . $g[1]);
    $link->appendChild(new Text('@' . $g[1]));
    return $link;
});

// Block: NOTE: admonitions
$parser->addBlockPattern('/^NOTE:\s*$/', function ($lines, $start, $parent, $p) {
    $content = [];
    $i = $start + 1;
    while ($i < count($lines) && $lines[$i] !== '' && !preg_match('/^[A-Z]+:\s*$/', $lines[$i])) {
        $content[] = $lines[$i];
        $i++;
    }

    $div = new Div();
    $div->setAttribute('class', 'note');
    $p->parseBlockContent($div, $content);  // @mentions work inside!
    $parent->appendChild($div);

    return $i - $start;
});

$djot = <<<'DJOT'
NOTE:
Remember to contact @support for help.

Regular paragraph with @mention.
DJOT;

echo $converter->convert($djot);
```

## Extracting Content Metadata

Extract metadata from Djot documents for social sharing, SEO, or other purposes by traversing the AST.

### Social Meta Tags

Extract title, description, and image for Open Graph and Twitter Card tags:

```php
use Djot\DjotConverter;
use Djot\Node\Block\Heading;
use Djot\Node\Block\Paragraph;
use Djot\Node\Document;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Text;

function extractSocialMeta(Document $document): array
{
    $meta = [
        'title' => null,
        'description' => null,
        'image' => null,
    ];

    foreach ($document->getChildren() as $node) {
        // First heading becomes title
        if ($meta['title'] === null && $node instanceof Heading) {
            $meta['title'] = getTextContent($node);
        }

        // First paragraph becomes description
        if ($meta['description'] === null && $node instanceof Paragraph) {
            $text = getTextContent($node);
            $meta['description'] = mb_strlen($text) > 160
                ? mb_substr($text, 0, 157) . '...'
                : $text;
        }

        // First image becomes preview image
        if ($meta['image'] === null) {
            $meta['image'] = findFirstImage($node);
        }

        // Stop once we have everything
        if ($meta['title'] !== null && $meta['description'] !== null && $meta['image'] !== null) {
            break;
        }
    }

    return $meta;
}

function getTextContent($node): string
{
    $text = '';
    foreach ($node->getChildren() as $child) {
        if ($child instanceof Text) {
            $text .= $child->getContent();
        } elseif ($child instanceof SoftBreak || $child instanceof HardBreak) {
            $text .= ' ';
        } else {
            $text .= getTextContent($child);
        }
    }
    return trim($text);
}

function findFirstImage($node): ?string
{
    if ($node instanceof Image) {
        return $node->getSource();
    }
    foreach ($node->getChildren() as $child) {
        $image = findFirstImage($child);
        if ($image !== null) {
            return $image;
        }
    }
    return null;
}

// Usage
$converter = new DjotConverter();
$document = $converter->parse($djot);
$meta = extractSocialMeta($document);
```

### Generating HTML Meta Tags

Generate Open Graph and Twitter Card markup:

```php
function generateMetaTags(array $meta, string $url, string $siteName = ''): string
{
    $tags = [];
    $title = $meta['title'] ?? null;
    $description = $meta['description'] ?? null;
    $image = $meta['image'] ?? null;

    // Open Graph
    if ($title) {
        $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $tags[] = "<meta property=\"og:title\" content=\"{$title}\">";
        $tags[] = "<meta name=\"twitter:title\" content=\"{$title}\">";
    }

    if ($description) {
        $desc = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
        $tags[] = "<meta property=\"og:description\" content=\"{$desc}\">";
        $tags[] = "<meta name=\"twitter:description\" content=\"{$desc}\">";
        $tags[] = "<meta name=\"description\" content=\"{$desc}\">";
    }

    if ($image) {
        $image = htmlspecialchars($image, ENT_QUOTES, 'UTF-8');
        $tags[] = "<meta property=\"og:image\" content=\"{$image}\">";
        $tags[] = "<meta name=\"twitter:image\" content=\"{$image}\">";
        $tags[] = "<meta name=\"twitter:card\" content=\"summary_large_image\">";
    } else {
        $tags[] = "<meta name=\"twitter:card\" content=\"summary\">";
    }

    $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $tags[] = "<meta property=\"og:url\" content=\"{$url}\">";
    $tags[] = "<meta property=\"og:type\" content=\"article\">";

    if ($siteName) {
        $siteName = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
        $tags[] = "<meta property=\"og:site_name\" content=\"{$siteName}\">";
    }

    return implode("\n", $tags);
}

// Usage
$meta = extractSocialMeta($document);
$metaTags = generateMetaTags($meta, 'https://example.com/article', 'My Blog');
```

Output:
```html
<meta property="og:title" content="Article Title">
<meta name="twitter:title" content="Article Title">
<meta property="og:description" content="First paragraph of the article...">
<meta name="twitter:description" content="First paragraph of the article...">
<meta name="description" content="First paragraph of the article...">
<meta property="og:image" content="https://example.com/image.jpg">
<meta name="twitter:image" content="https://example.com/image.jpg">
<meta name="twitter:card" content="summary_large_image">
<meta property="og:url" content="https://example.com/article">
<meta property="og:type" content="article">
<meta property="og:site_name" content="My Blog">
```

### Custom Extraction with Div Attributes

Override basic extraction with explicit div attributes:

```php
use Djot\Node\Block\Div;
use Djot\Node\Document;

function extractSocialMetaWithOverrides(Document $document): array
{
    // Start with basic content extraction
    $meta = extractSocialMeta($document);

    // Override with explicit div attributes if present
    foreach ($document->getChildren() as $node) {
        if ($node instanceof Div) {
            // Use div attributes: ::: {og-title="Custom Title"}
            if (($ogTitle = $node->getAttribute('og-title')) !== null) {
                $meta['title'] = $ogTitle;
            }
            if (($ogDesc = $node->getAttribute('og-description')) !== null) {
                $meta['description'] = $ogDesc;
            }
            if (($ogImage = $node->getAttribute('og-image')) !== null) {
                $meta['image'] = $ogImage;
            }
            break;
        }
    }

    return $meta;
}
```

Usage in Djot:
```djot
::: {og-title="Custom Social Title" og-description="A custom description for social sharing"}

# Article Title

This is the article content...

:::
```
