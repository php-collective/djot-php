# Custom Syntax Patterns

Extend Djot with custom inline and block syntax patterns.

## Table of Contents

- [Working with the AST](#working-with-the-ast)
- [Combining Multiple Customizations](#combining-multiple-customizations)
- [Custom Inline Patterns](#custom-inline-patterns)
- [Custom Block Patterns](#custom-block-patterns)

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

Support `!!! type` admonition syntax:

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
