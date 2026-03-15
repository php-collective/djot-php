# Cookbook

Common recipes and customizations for djot-php.

## Table of Contents

- [External Links](#external-links)
- [Custom Emoji/Symbols](#custom-emojisymbols)
- [Unicode Codepoints](#unicode-codepoints)
- [Abbreviations](#abbreviations)
- [Syntax Highlighting](#syntax-highlighting)
- [Table of Contents Generation](#table-of-contents-generation)
- [Image Processing](#image-processing)
- [Custom Admonitions](#custom-admonitions)
- [Heading Anchors](#heading-anchors)
- [Link Validation](#link-validation)
- [Content Security](#content-security)
- [Lazy Loading Images](#lazy-loading-images)
- [Custom Footnotes](#custom-footnotes)
- [Extended Task List States](#extended-task-list-states)
- [Math Rendering](#math-rendering)
- [Working with the AST](#working-with-the-ast)
- [Custom Inline Patterns](#custom-inline-patterns)
- [Custom Block Patterns](#custom-block-patterns)
- [Boolean Attributes](#boolean-attributes)
- [Alternative Output Formats](#alternative-output-formats)
- [Soft Break Modes](#soft-break-modes)
- [Significant Newlines Mode](#significant-newlines-mode)
- [Social Meta Tags](#social-meta-tags)

## External Links

> **Tip:** Use the built-in [ExternalLinksExtension](/extensions/#externallinksextension) for common cases.

Open external links in a new tab with security attributes:

```php
use Djot\Extension\ExternalLinksExtension;

$converter = new DjotConverter();
$converter->addExtension(new ExternalLinksExtension());

echo $converter->convert('[External](https://example.com) and [internal](/page)');
```

Output:
```html
<p><a href="https://example.com" target="_blank" rel="noopener noreferrer">External</a> and <a href="/page">internal</a></p>
```

For more control (custom logic, different attributes), use the event system directly:

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

    // Custom logic: only external links to specific domains
    if (str_contains($href, 'untrusted-domain.com')) {
        $link->setAttribute('rel', 'nofollow noopener');
    }
});
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

## Unicode Codepoints

Insert Unicode characters by codepoint using the `:symbol:` syntax. This is useful for
hard-to-type characters like directional marks, variation selectors, zero-width joiners,
and other invisible or special Unicode characters.

See [djot issue #44](https://github.com/jgm/djot/issues/44) for background on this use case.

### Supported Formats

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Symbol;

$converter = new DjotConverter();

$converter->on('render.symbol', function (RenderEvent $event): void {
    $symbol = $event->getNode();
    if (!$symbol instanceof Symbol) {
        return;
    }

    $name = $symbol->getName();

    // Hex with U+ prefix: :U+2192: → "→"
    if (preg_match('/^U\+([0-9A-Fa-f]+)$/', $name, $m)) {
        $codepoint = hexdec($m[1]);
        if ($codepoint >= 0 && $codepoint <= 0x10FFFF) {
            $event->setHtml(mb_chr($codepoint, 'UTF-8'));

            return;
        }
    }

    // Hex with 0x prefix: :0x14b: → "ŋ"
    if (preg_match('/^0x([0-9A-Fa-f]+)$/', $name, $m)) {
        $codepoint = hexdec($m[1]);
        if ($codepoint >= 0 && $codepoint <= 0x10FFFF) {
            $event->setHtml(mb_chr($codepoint, 'UTF-8'));

            return;
        }
    }

    // Decimal: :331: → "ŋ"
    if (preg_match('/^[0-9]+$/', $name)) {
        $codepoint = (int) $name;
        if ($codepoint >= 0 && $codepoint <= 0x10FFFF) {
            $event->setHtml(mb_chr($codepoint, 'UTF-8'));

            return;
        }
    }

    // Unknown symbol - keep original
    $event->setHtml(':' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ':');
});

echo $converter->convert('Arrow: :U+2192: Eng: :0x14b: or :331:');
```

Output:
```html
<p>Arrow: → Eng: ŋ or ŋ</p>
```

### Use Cases

**Bidirectional text markers** (essential for mixed RTL/LTR content):
```djot
English text :U+200F: متن فارسی :U+200E: more English
```

- `:U+200E:` - Left-to-right mark (LRM)
- `:U+200F:` - Right-to-left mark (RLM)
- `:U+200B:` - Zero-width space (allows line breaks)
- `:U+2060:` - Word joiner (prevents line breaks)

**Variation selectors** (control glyph variants):
```djot
The character 㐂:U+E0102: uses the third registered variant.
```

**Soft hyphens** (invisible until line break needed):
```djot
super:U+AD:cali:U+AD:fragi:U+AD:listic
```

### Combining with Emoji

Handle both emoji names and codepoints:

```php
$emojis = [
    'heart' => '❤️',
    'star' => '⭐',
];

$converter->on('render.symbol', function (RenderEvent $event) use ($emojis): void {
    $symbol = $event->getNode();
    if (!$symbol instanceof Symbol) {
        return;
    }

    $name = $symbol->getName();

    // Check emoji map first
    if (isset($emojis[$name])) {
        $event->setHtml($emojis[$name]);

        return;
    }

    // Then try codepoint formats
    $codepoint = null;
    if (preg_match('/^U\+([0-9A-Fa-f]+)$/', $name, $m)) {
        $codepoint = hexdec($m[1]);
    } elseif (preg_match('/^0x([0-9A-Fa-f]+)$/', $name, $m)) {
        $codepoint = hexdec($m[1]);
    } elseif (preg_match('/^[0-9]+$/', $name)) {
        $codepoint = (int) $name;
    }

    if ($codepoint !== null && $codepoint >= 0 && $codepoint <= 0x10FFFF) {
        $event->setHtml(mb_chr($codepoint, 'UTF-8'));

        return;
    }

    // Unknown - keep original
    $event->setHtml(':' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ':');
});

echo $converter->convert('I :heart: arrows :U+2192: and :star:');
```

Output:
```html
<p>I ❤️ arrows → and ⭐</p>
```

### Alternatives

For simpler cases, djot provides built-in alternatives:

**Non-breaking space** - use escaped space (`\ `):
```djot
100\ km
```

Output: `<p>100&nbsp;km</p>`

**HTML entities** - use raw HTML syntax:
```djot
`&mdash;`{=html} for em-dash, `&copy;`{=html} for ©
```

Output: `<p>— for em-dash, © for ©</p>`

The codepoint approach above is most useful when you need:
- Invisible Unicode characters (directional marks, joiners)
- Characters without named HTML entities
- A consistent syntax for all special characters

## Abbreviations

Convert spans with `abbr` attribute to semantic `<abbr>` elements:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Span;

$converter = new DjotConverter();

$converter->on('render.span', function (RenderEvent $event): void {
    $span = $event->getNode();
    if (!$span instanceof Span) {
        return;
    }

    $abbrTitle = $span->getAttribute('abbr');
    if ($abbrTitle !== null) {
        // Remove abbr from attributes, use as title
        $span->removeAttribute('abbr');

        // Build abbr element with remaining attributes
        $attrs = '';
        foreach ($span->getAttributes() as $key => $value) {
            $attrs .= ' ' . $key . '="' . htmlspecialchars($value) . '"';
        }

        $event->setHtml(
            '<abbr title="' . htmlspecialchars($abbrTitle) . '"' . $attrs . '>'
            . $event->getChildrenHtml()
            . '</abbr>'
        );
    }
});

echo $converter->convert('The [HTML]{abbr="HyperText Markup Language"} standard.');
```

Output:
```html
<p>The <abbr title="HyperText Markup Language">HTML</abbr> standard.</p>
```

This uses standard djot span syntax with attributes, so no custom parsing is needed.
You can combine with other attributes: `[CSS]{abbr="Cascading Style Sheets" .tech-term}`.

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

> **Tip:** Use the built-in [TableOfContentsExtension](/extensions/#tableofcontentsextension) for common cases.

Generate a table of contents from headings:

```php
use Djot\DjotConverter;
use Djot\Extension\TableOfContentsExtension;

$converter = new DjotConverter();
$tocExtension = new TableOfContentsExtension(
    minLevel: 2,      // Skip h1
    maxLevel: 3,      // Only h2 and h3
    position: 'top',  // Auto-insert at top of output
);
$converter->addExtension($tocExtension);

$html = $converter->convert($djot);
```

For manual placement:

```php
$tocExtension = new TableOfContentsExtension();
$converter->addExtension($tocExtension);

$html = $converter->convert($djot);
$toc = $tocExtension->getTocHtml();

// Place TOC wherever you want
echo $toc;
echo $html;
```

For fully custom TOC rendering, access the raw data:

```php
$tocData = $tocExtension->getToc();
// Returns: [['level' => 2, 'text' => 'Getting Started', 'id' => 'Getting-Started'], ...]
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

> **Tip:** Use the built-in [HeadingPermalinksExtension](/extensions/#headingpermalinksextension) for clickable permalink anchors.

Add anchor links to headings:

```php
use Djot\DjotConverter;
use Djot\Extension\HeadingPermalinksExtension;

$converter = new DjotConverter();
$converter->addExtension(new HeadingPermalinksExtension(
    symbol: '#',         // Or '¶', '🔗', etc.
    position: 'after',   // 'before' or 'after'
    cssClass: 'anchor',
));

echo $converter->convert('## Getting Started');
```

Output:
```html
<section id="Getting-Started">
<h2>Getting Started <span class="permalink-wrapper"><a href="#Getting-Started" class="anchor" aria-label="Permalink">#</a></span></h2>
</section>
```

For custom anchor logic without the permalink link, use events:

```php
$converter->on('render.heading', function (RenderEvent $event): void {
    $heading = $event->getNode();
    // Custom ID generation logic
    $heading->setAttribute('id', 'custom-' . uniqid());
});
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

> **Tip:** Use the built-in [DefaultAttributesExtension](/extensions/#defaultattributesextension) for this.

Add native lazy loading to all images:

```php
use Djot\DjotConverter;
use Djot\Extension\DefaultAttributesExtension;

$converter = new DjotConverter();
$converter->addExtension(new DefaultAttributesExtension([
    'image' => ['loading' => 'lazy', 'decoding' => 'async'],
]));
```

For more complex logic (e.g., different attributes based on image source), use events:

```php
$converter->on('render.image', function (RenderEvent $event): void {
    $image = $event->getNode();
    $src = $image->getDestination();

    // Only lazy load external images
    if (str_starts_with($src, 'http')) {
        $image->setAttribute('loading', 'lazy');
    }
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

## Extended Task List States

Djot task lists support more than just checked and unchecked states. The parser captures
the raw character inside brackets, enabling custom rendering for extended task states
like "in progress", "cancelled", "deferred", etc.

### Standard Task Lists

Standard task markers work as expected:

```djot
- [ ] Unchecked task
- [x] Completed task
- [X] Also completed (case insensitive)
```

```php
use Djot\DjotConverter;

$djot = <<<'DJOT'
- [ ] Unchecked task
- [x] Completed task
DJOT;

$converter = new DjotConverter();
echo $converter->convert($djot);
```

Output:
```html
<ul>
<li><input type="checkbox" disabled> Unchecked task</li>
<li><input type="checkbox" disabled checked> Completed task</li>
</ul>
```

### Accessing the Raw Marker

The `ListItem` node provides methods to access task state:

```php
use Djot\Parser\BlockParser;
use Djot\Node\Block\ListItem;

$parser = new BlockParser();
$document = $parser->parse('- [/] In progress task');

foreach ($document->getChildren() as $list) {
    foreach ($list->getChildren() as $item) {
        if ($item instanceof ListItem && $item->isTask()) {
            echo "Marker: " . $item->getTaskMarker() . "\n";  // "/"
            echo "Checked: " . ($item->getChecked() ? 'yes' : 'no') . "\n";  // "no"
            echo "Completed: " . ($item->isCompleted() ? 'yes' : 'no') . "\n";  // "no"
        }
    }
}
```

### Common Extended Markers

Popular conventions for extended task markers (inspired by tools like Logseq, Org-mode, and Obsidian):

| Marker | Meaning | Common Use |
|:------:|---------|------------|
| ` ` | Unchecked | Standard unchecked task |
| `x`/`X` | Completed | Standard checked task |
| `-` | Cancelled | Task no longer needed |
| `/` | In progress | Currently working on |
| `>` | Deferred | Forwarded/rescheduled |
| `?` | Question | Needs clarification |
| `!` | Important | High priority |
| `*` | Active | Currently focused |

The parser accepts **any single character** - these are just conventions. You can define your own markers.

### Alternative: Progress Indicators

Another approach (from [djot discussion #289](https://github.com/jgm/djot/discussions/289)) uses
visual progression where the marker fills in as the task progresses:

| Marker | Visual | Meaning |
|:------:|:------:|---------|
| ` ` | ` ` | Not started (0%) |
| `/` | `/` | Started (25%) |
| `-` | `-` | Halfway (50%) |
| `\` | `\` | Three-quarters (75%) |
| `x` | `x` | Complete (100%) |

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\ListItem;

$converter = new DjotConverter();

// Progress-based markers using pie chart symbols
$progressIcons = [
    ' ' => '○',      // Empty circle (0%)
    '/' => '◔',      // Quarter filled (25%)
    '-' => '◑',      // Half filled (50%)
    '\\' => '◕',     // Three-quarters filled (75%)
    'x' => '●',      // Full circle (100%)
];

$progressPercent = [
    ' ' => 0,
    '/' => 25,
    '-' => 50,
    '\\' => 75,
    'x' => 100,
];

$converter->on('render.list_item', function (RenderEvent $event) use ($progressIcons, $progressPercent): void {
    $item = $event->getNode();
    if (!$item instanceof ListItem || !$item->isTask()) {
        return;
    }

    $marker = $item->getTaskMarker();
    $icon = $progressIcons[$marker] ?? '○';
    $percent = $progressPercent[$marker] ?? 0;

    $html = '<li class="task-progress" data-progress="' . $percent . '">';
    $html .= '<span class="progress-icon">' . $icon . '</span> ';
    $html .= $event->getChildrenHtml();
    $html .= '</li>' . "\n";

    $event->setHtml($html);
});

$djot = <<<'DJOT'
- [ ] Not started
- [/] Just begun
- [-] Halfway there
- [\] Almost done
- [x] Complete!
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<ul>
<li class="task-progress" data-progress="0"><span class="progress-icon">○</span> Not started</li>
<li class="task-progress" data-progress="25"><span class="progress-icon">◔</span> Just begun</li>
<li class="task-progress" data-progress="50"><span class="progress-icon">◑</span> Halfway there</li>
<li class="task-progress" data-progress="75"><span class="progress-icon">◕</span> Almost done</li>
<li class="task-progress" data-progress="100"><span class="progress-icon">●</span> Complete!</li>
</ul>
```

With CSS progress bar styling:

```css
.task-progress {
    position: relative;
    list-style: none;
}

.task-progress::before {
    content: '';
    position: absolute;
    left: -100%;
    width: 80%;
    height: 3px;
    bottom: 0;
    background: linear-gradient(to right,
        #28a745 0%,
        #28a745 var(--progress),
        #e9ecef var(--progress),
        #e9ecef 100%);
}

.task-progress[data-progress="0"] { --progress: 0%; }
.task-progress[data-progress="25"] { --progress: 25%; }
.task-progress[data-progress="50"] { --progress: 50%; }
.task-progress[data-progress="75"] { --progress: 75%; }
.task-progress[data-progress="100"] { --progress: 100%; color: #28a745; }
```

### Custom Checkbox Rendering

Use render events to create custom checkbox appearances:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\ListItem;

$converter = new DjotConverter();

$taskIcons = [
    ' ' => '☐',      // Unchecked
    'x' => '☑',      // Completed
    'X' => '☑',      // Completed
    '-' => '☒',      // Cancelled
    '/' => '◐',      // In progress (half-filled)
    '>' => '→',      // Deferred
    '?' => '?',      // Question
    '!' => '⚠',      // Important
    '*' => '★',      // Active/starred
];

$converter->on('render.list_item', function (RenderEvent $event) use ($taskIcons): void {
    $item = $event->getNode();
    if (!$item instanceof ListItem || !$item->isTask()) {
        return;
    }

    $marker = $item->getTaskMarker();
    $icon = $taskIcons[$marker] ?? '○';

    // Get marker-specific class
    $stateClass = match ($marker) {
        ' ' => 'task-unchecked',
        'x', 'X' => 'task-completed',
        '-' => 'task-cancelled',
        '/' => 'task-in-progress',
        '>' => 'task-deferred',
        '?' => 'task-question',
        '!' => 'task-important',
        '*' => 'task-active',
        default => 'task-custom',
    };

    $html = '<li class="task-item ' . $stateClass . '">';
    $html .= '<span class="task-marker">' . $icon . '</span> ';
    $html .= $event->getChildrenHtml();
    $html .= '</li>' . "\n";

    $event->setHtml($html);
});

$djot = <<<'DJOT'
- [ ] Todo item
- [x] Done item
- [-] Cancelled item
- [/] In progress
- [>] Deferred to next week
- [?] Needs discussion
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<ul>
<li class="task-item task-unchecked"><span class="task-marker">☐</span> Todo item</li>
<li class="task-item task-completed"><span class="task-marker">☑</span> Done item</li>
<li class="task-item task-cancelled"><span class="task-marker">☒</span> Cancelled item</li>
<li class="task-item task-in-progress"><span class="task-marker">◐</span> In progress</li>
<li class="task-item task-deferred"><span class="task-marker">→</span> Deferred to next week</li>
<li class="task-item task-question"><span class="task-marker">?</span> Needs discussion</li>
</ul>
```

### CSS Styling for Extended States

Style the extended states with CSS:

```css
/* Base task styling */
.task-item {
    list-style: none;
    margin-left: -1.5em;
}

.task-marker {
    display: inline-block;
    width: 1.2em;
    text-align: center;
    margin-right: 0.3em;
}

/* State-specific styling */
.task-completed {
    color: #28a745;
    text-decoration: line-through;
    opacity: 0.7;
}

.task-cancelled {
    color: #6c757d;
    text-decoration: line-through;
    opacity: 0.5;
}

.task-in-progress {
    color: #007bff;
    font-weight: bold;
}

.task-deferred {
    color: #fd7e14;
    font-style: italic;
}

.task-question {
    color: #6f42c1;
    background: #f8f9fa;
}

.task-important {
    color: #dc3545;
    font-weight: bold;
}

.task-active {
    color: #ffc107;
    background: #fffbe6;
}
```

### HTML5 Checkbox with Data Attributes

Keep semantic HTML while adding extended state info:

```php
$converter->on('render.list_item', function (RenderEvent $event): void {
    $item = $event->getNode();
    if (!$item instanceof ListItem || !$item->isTask()) {
        return;
    }

    $marker = $item->getTaskMarker();
    $checked = $item->isCompleted() ? ' checked' : '';

    // Store marker as data attribute for CSS/JS
    $html = '<li>';
    $html .= '<input type="checkbox" disabled' . $checked;
    $html .= ' data-task-state="' . htmlspecialchars($marker) . '">';
    $html .= ' ' . $event->getChildrenHtml();
    $html .= '</li>' . "\n";

    $event->setHtml($html);
});
```

Then use CSS attribute selectors:

```css
input[data-task-state="-"] + * {
    text-decoration: line-through;
    color: gray;
}

input[data-task-state="/"]::before {
    content: "🔄 ";
}

input[data-task-state=">"] + * {
    font-style: italic;
    color: orange;
}
```

### Extracting Task Statistics

Analyze documents for task completion:

```php
use Djot\Parser\BlockParser;
use Djot\Node\Block\ListItem;

function getTaskStats(string $djot): array
{
    $parser = new BlockParser();
    $document = $parser->parse($djot);

    $stats = [
        'total' => 0,
        'completed' => 0,
        'cancelled' => 0,
        'in_progress' => 0,
        'unchecked' => 0,
        'by_marker' => [],
    ];

    // Recursive function to find all list items
    $findTasks = function ($node) use (&$findTasks, &$stats): void {
        if ($node instanceof ListItem && $node->isTask()) {
            $marker = $node->getTaskMarker();
            $stats['total']++;
            $stats['by_marker'][$marker] = ($stats['by_marker'][$marker] ?? 0) + 1;

            if ($node->isCompleted()) {
                $stats['completed']++;
            } elseif ($marker === '-') {
                $stats['cancelled']++;
            } elseif ($marker === '/') {
                $stats['in_progress']++;
            } elseif ($marker === ' ') {
                $stats['unchecked']++;
            }
        }

        if (method_exists($node, 'getChildren')) {
            foreach ($node->getChildren() as $child) {
                $findTasks($child);
            }
        }
    };

    $findTasks($document);

    return $stats;
}

$djot = <<<'DJOT'
## Project Tasks

- [x] Setup project
- [x] Write documentation
- [/] Implement feature A
- [ ] Implement feature B
- [-] Cancelled feature
- [>] Deferred to v2
DJOT;

$stats = getTaskStats($djot);
echo "Progress: {$stats['completed']}/{$stats['total']} completed\n";
echo "In progress: {$stats['in_progress']}\n";
echo "Remaining: {$stats['unchecked']}\n";
```

Output:
```
Progress: 2/6 completed
In progress: 1
Remaining: 1
```

### Backward Compatibility

The `getChecked()` method maintains backward compatibility:

- `' '` (space) returns `false`
- `'x'` or `'X'` returns `true`
- Any other marker returns `false` (safe default)

This means existing code continues to work while new code can access the full marker:

```php
// Old code - still works
if ($item->getChecked()) {
    echo "Task is done";
}

// New code - access extended states
$marker = $item->getTaskMarker();
if ($marker === '/') {
    echo "Task in progress";
}
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

## Custom Inline Patterns

Extend Djot with custom inline syntax by registering patterns on the InlineParser.

### @Mentions

> **Tip:** Use the built-in [MentionsExtension](/extensions/#mentionsextension) for common cases.

Convert `@username` to profile links:

```php
use Djot\DjotConverter;
use Djot\Extension\MentionsExtension;

$converter = new DjotConverter();
$converter->addExtension(new MentionsExtension(
    urlTemplate: '/users/{username}',
    cssClass: 'mention',
));

echo $converter->convert('Hello @john_doe, meet @jane_smith!');
```

Output:
```html
<p>Hello <a href="/users/john_doe" class="mention">@john_doe</a>, meet <a href="/users/jane_smith" class="mention">@jane_smith</a>!</p>
```

For custom mention logic, use inline patterns directly:

```php
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
$parser->addInlinePattern('/<~([^>|]+)(?:\|([^>]+))?>/​', function ($match, $groups, $p) {
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

$parser->addInlinePattern('/<~([^>|]+)(?:\|([^>]+))?>/​', function ($match, $groups, $p) use ($basePath) {
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

## Boolean Attributes

HTML boolean attributes (like `reversed`, `hidden`, `disabled`) can be specified as bare words in djot attribute blocks.

### Reversed Ordered Lists

Use `{reversed}` to create a descending ordered list:

```php
use Djot\DjotConverter;

$djot = <<<'DJOT'
{reversed}
3. Bronze medal
2. Silver medal
1. Gold medal
DJOT;

$converter = new DjotConverter();
echo $converter->convert($djot);
```

Output:
```html
<ol start="3" reversed="">
<li>Bronze medal</li>
<li>Silver medal</li>
<li>Gold medal</li>
</ol>
```

The browser displays this as:
```
3. Bronze medal
2. Silver medal
1. Gold medal
```

### Hidden Content

Use `{hidden}` to hide elements:

```djot
{hidden}
This paragraph is hidden.
```

Output:
```html
<p hidden="">This paragraph is hidden.</p>
```

### Combining Boolean Attributes

Boolean attributes can be combined with classes, IDs, and key-value attributes:

```djot
{#countdown .fancy reversed}
3. Third
2. Second
1. First
```

Output:
```html
<ol id="countdown" class="fancy" start="3" reversed="">
<li>Third</li>
<li>Second</li>
<li>First</li>
</ol>
```

### Inline Boolean Attributes

Boolean attributes also work on inline spans and links:

```djot
[Download PDF](report.pdf){download .btn}

[Hidden text]{hidden}
```

Output:
```html
<p><a href="report.pdf" class="btn" download="">Download PDF</a></p>
<p><span hidden="">Hidden text</span></p>
```

### Syntax Reference

| Syntax | Result |
|--------|--------|
| `{.class}` | `class="class"` |
| `{#id}` | `id="id"` |
| `{key=value}` | `key="value"` |
| `{key="value"}` | `key="value"` (quoted) |
| `{reversed}` | `reversed=""` (boolean) |
| `{hidden}` | `hidden=""` (boolean) |

Boolean attributes are rendered as `attr=""` which is valid HTML5. Browsers treat this identically to `attr` or `attr="attr"`.

### Common HTML Boolean Attributes

Useful boolean attributes for djot elements:

| Attribute | Elements | Effect |
|-----------|----------|--------|
| `reversed` | `<ol>` | Count down instead of up |
| `hidden` | Any | Hide element from display |
| `open` | `<details>` | Show details content by default |
| `download` | `<a>` (links) | Download linked resource |

## Alternative Output Formats

For detailed customization of alternative renderers, see:
- [PlainText Cookbook](./plaintext) - PlainTextRenderer customizations
- [Markdown Cookbook](./markdown) - MarkdownRenderer customizations
- [ANSI Cookbook](./ansi) - AnsiRenderer customizations

### Plain Text Extraction

Extract plain text for search indexing or SEO:

```php
use Djot\DjotConverter;
use Djot\Renderer\PlainTextRenderer;

$converter = new DjotConverter();
$renderer = new PlainTextRenderer();

$djot = <<<'DJOT'
# Welcome

This is *formatted* text. Visit [our website](https://example.com) for more.

- Item one
- Item two
DJOT;

$document = $converter->parse($djot);
$text = $renderer->render($document);

echo $text;
```

Output:
```
Welcome

This is formatted text. Visit https://example.com for more.

- Item one
- Item two
```

### Multipart Email (HTML + Plain Text)

Parse once, render to multiple formats for email clients:

```php
use Djot\DjotConverter;
use Djot\Renderer\HtmlRenderer;
use Djot\Renderer\PlainTextRenderer;

$template = <<<'DJOT'
# Order Confirmation

Thank you for your order, **John**!

## Order Details

| Item | Qty | Price |
|------|-----|-------|
| Widget Pro | 2 | $49.99 |
| Gadget X | 1 | $29.99 |

**Total:** $79.98

[Track Your Order](https://example.com/track/12345)

Questions? Reply to this email or visit our [help center](https://example.com/help).
DJOT;

// Parse once
$converter = new DjotConverter();
$document = $converter->parse($template);

// Render to HTML for rich email clients
$htmlRenderer = new HtmlRenderer();
$htmlBody = $htmlRenderer->render($document);

// Render to plain text for basic clients
$textRenderer = new PlainTextRenderer();
$textBody = $textRenderer->render($document);

// Send multipart email (using your preferred mail library)
$email = new YourMailer();
$email->setSubject('Order Confirmation');
$email->setHtmlBody($htmlBody);
$email->setTextBody($textBody);
$email->send();
```

HTML output (for rich clients):
```html
<h1>Order Confirmation</h1>
<p>Thank you for your order, <strong>John</strong>!</p>
<h2>Order Details</h2>
<table>
<tr><th>Item</th><th>Qty</th><th>Price</th></tr>
<tr><td>Widget Pro</td><td>2</td><td>$49.99</td></tr>
<tr><td>Gadget X</td><td>1</td><td>$29.99</td></tr>
</table>
...
```

Plain text output (for basic clients):
```
Order Confirmation

Thank you for your order, John!

Order Details

Item | Qty | Price
Widget Pro | 2 | $49.99
Gadget X | 1 | $29.99

Total: $79.98

https://example.com/track/12345

Questions? Reply to this email or visit our https://example.com/help.
```

### Markdown Conversion

Convert Djot to CommonMark Markdown:

```php
use Djot\DjotConverter;
use Djot\Renderer\MarkdownRenderer;

$converter = new DjotConverter();
$renderer = new MarkdownRenderer();

$djot = <<<'DJOT'
# Heading

This has _emphasis_ and *strong* text.

| Name | Age |
|:-----|----:|
| Alice | 30 |
| Bob | 25 |
DJOT;

$document = $converter->parse($djot);
$markdown = $renderer->render($document);

echo $markdown;
```

Output:
```markdown
# Heading

This has *emphasis* and **strong** text.

| Name | Age |
|:-----|----:|
| Alice | 30 |
| Bob | 25 |
```

### Converting Files

Work directly with files:

```php
use Djot\DjotConverter;

$converter = new DjotConverter();

// Convert file to HTML
$html = $converter->convertFile('/path/to/document.djot');

// Or parse file to AST for manipulation
$document = $converter->parseFile('/path/to/document.djot');
// ... modify AST ...
$html = $converter->render($document);
```

## Soft Break Modes

Control how soft breaks (single newlines in source) are rendered in HTML output.

### Available Modes

```php
use Djot\DjotConverter;
use Djot\Renderer\SoftBreakMode;

$converter = new DjotConverter();

// Newline mode (default) - renders as "\n" in HTML source
$converter->getRenderer()->setSoftBreakMode(SoftBreakMode::Newline);

// Space mode - renders as a single space
$converter->getRenderer()->setSoftBreakMode(SoftBreakMode::Space);

// Break mode - renders as <br> (visible line break)
$converter->getRenderer()->setSoftBreakMode(SoftBreakMode::Break);
```

### Example: Poetry or Lyrics

For content where line breaks should be visible (poetry, lyrics, addresses):

```php
use Djot\DjotConverter;
use Djot\Renderer\SoftBreakMode;

$converter = new DjotConverter();
$converter->getRenderer()->setSoftBreakMode(SoftBreakMode::Break);

$poem = "Roses are red
Violets are blue
Sugar is sweet
And so are you";

echo $converter->convert($poem);
```

Output:
```html
<p>Roses are red<br>
Violets are blue<br>
Sugar is sweet<br>
And so are you</p>
```

### Comparison

| Source | Mode | HTML Output | Browser Display |
|--------|------|-------------|-----------------|
| `Line 1↵Line 2` | Newline | `Line 1\nLine 2` | Line 1 Line 2 |
| `Line 1↵Line 2` | Space | `Line 1 Line 2` | Line 1 Line 2 |
| `Line 1↵Line 2` | Break | `Line 1<br>\nLine 2` | Line 1<br>Line 2 |

Note: Use `\` at end of line for hard breaks (always renders as `<br>`) regardless of soft break mode.

## Significant Newlines Mode

By default, djot-php follows the djot specification where block elements (lists, blockquotes, headings) **cannot interrupt paragraphs** - they require a blank line before them.

The "significant newlines" mode provides markdown-like behavior where block elements can interrupt paragraphs without blank lines. This is useful for chat messages, comments, and quick notes.

### Enabling Significant Newlines Mode

```php
use Djot\DjotConverter;

// Method 1: Factory method (also enables SoftBreakMode::Break)
$converter = DjotConverter::withSignificantNewlines();

// Method 2: Constructor parameter
$converter = new DjotConverter(significantNewlines: true);

// Method 3: Parser-level control
use Djot\Parser\BlockParser;
$parser = new BlockParser(significantNewlines: true);
```

### Behavior Comparison

**Default mode (spec-compliant):**
```php
$converter = new DjotConverter();
$result = $converter->convert("Here's a list:
- Item one
- Item two");
```

Output:
```html
<p>Here's a list:
- Item one
- Item two</p>
```

**Significant newlines mode:**
```php
$converter = DjotConverter::withSignificantNewlines();
$result = $converter->convert("Here's a list:
- Item one
- Item two");
```

Output:
```html
<p>Here's a list:</p>
<ul>
<li>Item one</li>
<li>Item two</li>
</ul>
```

### What Changes in Significant Newlines Mode

| Feature | Default Mode | Significant Newlines |
|---------|-------------|---------------------|
| Lists interrupt paragraphs | No | Yes |
| Blockquotes interrupt paragraphs | No | Yes |
| Headings interrupt paragraphs | No | Yes |
| Code fences interrupt paragraphs | No | Yes |
| Nested lists without blank lines | No | Yes |
| Soft breaks render as | `\n` | `<br>` |

### Preventing Block Interruption with Escaping

In significant newlines mode, if you want to include literal block markers without triggering block parsing, escape the first character with a backslash:

```php
$converter = DjotConverter::withSignificantNewlines();

// Without escaping - creates a list
$result = $converter->convert("Price:
- 10 dollars");
// Output: <p>Price:</p><ul><li>10 dollars</li></ul>

// With escaping - literal text
$result = $converter->convert("Price:
\\- 10 dollars");
// Output: <p>Price:<br>- 10 dollars</p>
```

Common escapes:
- `\-`, `\*`, `\+` - Prevent list interpretation
- `\>` - Prevent blockquote interpretation
- `\#` - Prevent heading interpretation
- `\|` - Prevent table interpretation
- `` \` `` - Prevent code fence interpretation

### Use Cases

**Chat/Messaging Applications:**
```php
$converter = DjotConverter::withSignificantNewlines();

$message = "Check out this quote:
> Important information here
And here's the follow-up";

echo $converter->convert($message);
```

**Quick Notes:**
```php
$converter = DjotConverter::withSignificantNewlines();

$note = "TODO:
- Buy groceries
- Call mom
- Finish report";

echo $converter->convert($note);
```

### Automatic Soft Break Configuration

When using `DjotConverter::withSignificantNewlines()` or the `significantNewlines` constructor parameter, the soft break mode is automatically set to `SoftBreakMode::Break` (renders as `<br>`). This is intentional since chat/messaging contexts typically expect visible line breaks.

To override this behavior:

```php
use Djot\DjotConverter;
use Djot\Renderer\SoftBreakMode;

$converter = DjotConverter::withSignificantNewlines();
$converter->getRenderer()->setSoftBreakMode(SoftBreakMode::Space); // Override if needed
```

**Note:** When using the `BlockParser` directly with a custom renderer (like `PlainTextRenderer`), the soft break mode is not automatically configured. You'll need to set it manually:

```php
use Djot\Parser\BlockParser;
use Djot\Renderer\PlainTextRenderer;
use Djot\Renderer\SoftBreakMode;

$parser = new BlockParser(significantNewlines: true);
$renderer = new PlainTextRenderer();
$renderer->setSoftBreakMode(SoftBreakMode::Newline); // Configure as needed

$doc = $parser->parse($input);
echo $renderer->render($doc);
```

### Combining with Other Options

```php
use Djot\DjotConverter;
use Djot\SafeMode;

// Significant newlines with safe mode for user-generated content
$converter = new DjotConverter(
    safeMode: new SafeMode(),
    significantNewlines: true,
);
```

## Social Meta Tags

Extract metadata from Djot documents for Open Graph and Twitter Card tags, useful for social sharing previews.

### Basic Extraction

Extract title, description, and image from a parsed document:

```php
use Djot\DjotConverter;
use Djot\Node\Block\Heading;
use Djot\Node\Block\Paragraph;
use Djot\Node\Inline\Image;
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
        if ($meta['title'] && $meta['description'] && $meta['image']) {
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
        } elseif (method_exists($child, 'getChildren')) {
            $text .= getTextContent($child);
        }
    }
    return trim($text);
}

function findFirstImage($node): ?string
{
    if ($node instanceof Image) {
        return $node->getDestination();
    }
    if (method_exists($node, 'getChildren')) {
        foreach ($node->getChildren() as $child) {
            $image = findFirstImage($child);
            if ($image !== null) {
                return $image;
            }
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

    // Open Graph
    if ($meta['title']) {
        $title = htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8');
        $tags[] = "<meta property=\"og:title\" content=\"{$title}\">";
        $tags[] = "<meta name=\"twitter:title\" content=\"{$title}\">";
    }

    if ($meta['description']) {
        $desc = htmlspecialchars($meta['description'], ENT_QUOTES, 'UTF-8');
        $tags[] = "<meta property=\"og:description\" content=\"{$desc}\">";
        $tags[] = "<meta name=\"twitter:description\" content=\"{$desc}\">";
        $tags[] = "<meta name=\"description\" content=\"{$desc}\">";
    }

    if ($meta['image']) {
        $image = htmlspecialchars($meta['image'], ENT_QUOTES, 'UTF-8');
        $tags[] = "<meta property=\"og:image\" content=\"{$image}\">";
        $tags[] = "<meta name=\"twitter:image\" content=\"{$image}\">";
        $tags[] = "<meta name=\"twitter:card\" content=\"summary_large_image\">";
    } else {
        $tags[] = "<meta name=\"twitter:card\" content=\"summary\">";
    }

    $tags[] = "<meta property=\"og:url\" content=\"{$url}\">";
    $tags[] = "<meta property=\"og:type\" content=\"article\">";

    if ($siteName) {
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

### Custom Extraction Rules

Override extraction for specific needs:

```php
function extractSocialMeta(Document $document, array $defaults = []): array
{
    $meta = array_merge([
        'title' => null,
        'description' => null,
        'image' => null,
    ], $defaults);

    // Check for explicit attributes on the document or first div
    foreach ($document->getChildren() as $node) {
        if ($node instanceof Div) {
            // Use div attributes as metadata: ::: {og-title="Custom Title"}
            if ($ogTitle = $node->getAttribute('og-title')) {
                $meta['title'] = $ogTitle;
            }
            if ($ogDesc = $node->getAttribute('og-description')) {
                $meta['description'] = $ogDesc;
            }
            if ($ogImage = $node->getAttribute('og-image')) {
                $meta['image'] = $ogImage;
            }
            break;
        }
    }

    // Fall back to content extraction...
    // (same logic as basic extraction)

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

### Fallback Values

Provide fallbacks for missing metadata:

```php
$meta = extractSocialMeta($document);

// Apply fallbacks
$meta['title'] ??= 'Untitled';
$meta['description'] ??= 'No description available.';
$meta['image'] ??= 'https://example.com/default-og-image.jpg';

echo generateMetaTags($meta, $currentUrl, 'My Site');
```

### Framework Integration

Example with a simple controller pattern:

```php
class ArticleController
{
    public function show(string $slug): Response
    {
        $djot = $this->loadArticle($slug);

        $converter = new DjotConverter();
        $document = $converter->parse($djot);
        $html = $converter->render($document);

        $meta = extractSocialMeta($document);
        $metaTags = generateMetaTags(
            $meta,
            "https://example.com/articles/{$slug}",
            'My Blog',
        );

        return $this->render('article.html', [
            'content' => $html,
            'metaTags' => $metaTags,
            'title' => $meta['title'] ?? 'Article',
        ]);
    }
}
```
