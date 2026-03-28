# Rendering Recipes

Common recipes for customizing HTML output using events.

## Table of Contents

**Built-in Features**

- [Boolean Attributes](#boolean-attributes)

**Render Customization**

- [Custom Footnotes](#custom-footnotes)
- [Extended Task List States](#extended-task-list-states)
- [Custom Link Attributes](#custom-link-attributes)
- [Custom Emoji/Symbols](#custom-emoji-symbols)
- [Unicode Codepoints](#unicode-codepoints)
- [Image Processing](#image-processing)
- [Custom Heading IDs](#custom-heading-ids)
- [Conditional Image Attributes](#conditional-image-attributes)
- [Math Rendering](#math-rendering)

**Security**

- [Link Validation](#link-validation)
- [Content Sanitization](#content-sanitization)

**Integrations**

- [Multipart Email](#multipart-email)
- [Video Embeds](#video-embeds)
- [Progress Bars](#progress-bars)

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

## Custom Link Attributes

For standard external link handling (target="_blank", rel="noopener"), use [ExternalLinksExtension](/extensions/#externallinksextension).

For custom logic based on specific domains or conditions, use events:

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

    // Custom logic: specific domains get different attributes
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

    $src = htmlspecialchars($image->getSource(), ENT_QUOTES, 'UTF-8');
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

## Custom Heading IDs

For permalink anchors with clickable links, use [HeadingPermalinksExtension](/extensions/#headingpermalinksextension).

For custom ID generation logic, use events:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;

$converter = new DjotConverter();

$converter->on('render.heading', function (RenderEvent $event): void {
    $heading = $event->getNode();
    // Custom ID generation logic
    $heading->setAttribute('id', 'custom-' . uniqid());
});
```

## Conditional Image Attributes

For static attributes on all images, use [DefaultAttributesExtension](/extensions/#defaultattributesextension).

For conditional logic based on image source, use events:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;

$converter = new DjotConverter();

$converter->on('render.image', function (RenderEvent $event): void {
    $image = $event->getNode();
    $src = $image->getSource();

    // Only lazy load external images
    if (str_starts_with($src, 'http')) {
        $image->setAttribute('loading', 'lazy');
    }
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

## Content Sanitization

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

::: tip Safe Mode
For comprehensive content security, use [Safe Mode](/guide/safe-mode) which provides built-in protection against XSS and other security vulnerabilities.
:::

## Multipart Email

Parse once and render both HTML and plain text for multipart email clients:

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

$converter = new DjotConverter();
$document = $converter->parse($template);

// Render HTML for rich email clients
$htmlBody = (new HtmlRenderer())->render($document);

// Render plain text for fallback clients
$textBody = (new PlainTextRenderer())->render($document);

// Send multipart email with your mailer
$email = new YourMailer();
$email->setSubject('Order Confirmation');
$email->setHtmlBody($htmlBody);
$email->setTextBody($textBody);
$email->send();
```

This pattern keeps the HTML and plain-text versions synchronized because both are rendered from the same parsed document.

## Video Embeds

Embed videos from YouTube, Vimeo, and 50+ other providers using the [dereuromark/media-embed](https://github.com/dereuromark/media-embed) library.

### Installation

```bash
composer require dereuromark/media-embed
```

### Basic Integration

Register an event listener that transforms video divs:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Div;
use MediaEmbed\MediaEmbed;

$converter = new DjotConverter();
$mediaEmbed = new MediaEmbed();

$converter->on('render.div', function (RenderEvent $event) use ($mediaEmbed): void {
    $node = $event->getNode();
    if (!$node instanceof Div) {
        return;
    }

    // Check for 'video' class
    $classes = preg_split('/\s+/', trim((string)$node->getAttribute('class')));
    if (!in_array('video', $classes, true)) {
        return;
    }

    // Extract URL from div content
    $url = trim(strip_tags($event->getChildrenHtml()));
    $object = $mediaEmbed->parseUrl($url);

    if ($object === null) {
        return; // Not a recognized video URL
    }

    $html = '<figure class="video-embed">' . "\n";
    $html .= $object->getEmbedCode();
    $html .= "</figure>\n";

    $event->setHtml($html);
});
```

### Usage

```djot
::: video
https://www.youtube.com/watch?v=dQw4w9WgXcQ
:::
```

Output:

```html
<figure class="video-embed">
  <iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"
          allowfullscreen></iframe>
</figure>
```

### Supported Providers

media-embed supports 50+ providers including YouTube, Vimeo, Dailymotion, Twitch, TikTok, Instagram, SoundCloud, Spotify, and many more. See the [full provider list](https://github.com/dereuromark/media-embed#supported-providers).

### Customizing the Embed

```php
$object = $mediaEmbed->parseUrl($url);
$object->setWidth(800);
$object->setHeight(450);
$object->setAttribute('loading', 'lazy');
$html = $object->getEmbedCode();

// Privacy-enhanced mode for YouTube
$html = str_replace('youtube.com', 'youtube-nocookie.com', $html);
```

### Responsive CSS

```css
.video-embed {
  position: relative;
  padding-bottom: 56.25%; /* 16:9 */
  height: 0;
  overflow: hidden;
}

.video-embed iframe {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  border: 0;
}
```

### As a Reusable Extension

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Extension\ExtensionInterface;
use Djot\Node\Block\Div;
use MediaEmbed\MediaEmbed;

class VideoExtension implements ExtensionInterface
{
    public function __construct(
        protected ?MediaEmbed $mediaEmbed = null,
        protected string $figureClass = 'video-embed',
        protected bool $lazy = true,
    ) {
        $this->mediaEmbed ??= new MediaEmbed();
    }

    public function register(DjotConverter $converter): void
    {
        $converter->on('render.div', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof Div || !$this->hasClass($node, 'video')) {
                return;
            }

            $url = trim(strip_tags($event->getChildrenHtml()));
            $object = $this->mediaEmbed->parseUrl($url);

            if ($object === null) {
                return;
            }

            if ($this->lazy) {
                $object->setAttribute('loading', 'lazy');
            }

            $html = '<figure class="' . $this->escape($this->figureClass) . "\">\n";
            $html .= $object->getEmbedCode();
            $html .= "</figure>\n";

            $event->setHtml($html);
        });
    }

    protected function hasClass(Div $node, string $className): bool
    {
        $classes = preg_split('/\s+/', trim((string)$node->getAttribute('class')));

        return is_array($classes) && in_array($className, $classes, true);
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
```

Usage:

```php
$converter = new DjotConverter();
$converter->addExtension(new VideoExtension());
```

## Progress Bars

Render progress bars using spans with a `progress` attribute:

```djot
Project completion: [75%]{progress}
Loading: [50%]{progress="Half done"}
Status: [100%]{progress .success}
```

### Native HTML5 Progress

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Span;

$converter = new DjotConverter();

$converter->on('render.span', function (RenderEvent $event): void {
    $node = $event->getNode();
    if (!$node instanceof Span || !$node->hasAttribute('progress')) {
        return;
    }

    $text = $node->getTextContent();
    $value = (int) filter_var($text, FILTER_SANITIZE_NUMBER_INT);
    $value = max(0, min(100, $value)); // Clamp 0-100

    // Use attribute value as label, or fall back to text content
    $attrValue = $node->getAttribute('progress');
    $label = is_string($attrValue) && $attrValue !== '' ? $attrValue : $text;

    // Collect CSS classes
    $classAttr = $node->getAttribute('class');
    $classHtml = '';
    if (is_string($classAttr) && $classAttr !== '') {
        $classHtml = ' class="' . htmlspecialchars($classAttr) . '"';
    }

    $html = sprintf(
        '<progress value="%d" max="100"%s title="%s">%s</progress>',
        $value,
        $classHtml,
        htmlspecialchars($label),
        htmlspecialchars($label),
    );

    $event->setHtml($html);
});

echo $converter->convert('Project: [75%]{progress}');
// <p>Project: <progress value="75" max="100" title="75%">75%</progress></p>
```

**CSS for styled variants:**

```css
progress.success::-webkit-progress-value { background: #28a745; }
progress.warning::-webkit-progress-value { background: #ffc107; }
progress.danger::-webkit-progress-value { background: #dc3545; }

/* Firefox */
progress.success::-moz-progress-bar { background: #28a745; }
progress.warning::-moz-progress-bar { background: #ffc107; }
progress.danger::-moz-progress-bar { background: #dc3545; }
```

### Custom Div-Based Progress

For more styling control, render as a div-based progress bar:

```php
$converter->on('render.span', function (RenderEvent $event): void {
    $node = $event->getNode();
    if (!$node instanceof Span || !$node->hasAttribute('progress')) {
        return;
    }

    $text = $node->getTextContent();
    $value = (int) filter_var($text, FILTER_SANITIZE_NUMBER_INT);
    $value = max(0, min(100, $value));

    $attrValue = $node->getAttribute('progress');
    $label = is_string($attrValue) && $attrValue !== '' ? $attrValue : $text;

    $classes = ['progress'];
    $classAttr = $node->getAttribute('class');
    if (is_string($classAttr) && $classAttr !== '') {
        $classes = array_merge($classes, preg_split('/\s+/', $classAttr));
    }

    $html = sprintf(
        '<div class="%s" role="progressbar" aria-valuenow="%d" aria-valuemin="0" aria-valuemax="100">' .
        '<div class="progress-fill" style="width: %d%%">%s</div>' .
        '</div>',
        htmlspecialchars(implode(' ', $classes)),
        $value,
        $value,
        htmlspecialchars($label),
    );

    $event->setHtml($html);
});
```

**CSS:**

```css
.progress {
  width: 100%;
  height: 1.5em;
  background: #e9ecef;
  border-radius: 0.25rem;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: #0d6efd;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.875em;
  transition: width 0.3s ease;
}

.progress.success .progress-fill { background: #28a745; }
.progress.warning .progress-fill { background: #ffc107; color: #000; }
.progress.danger .progress-fill { background: #dc3545; }
```

### Integration Example

```djot
# Project Status

| Feature | Progress |
|---------|----------|
| Backend API | [100%]{progress .success} |
| Frontend UI | [75%]{progress} |
| Documentation | [50%]{progress .warning} |
| Testing | [25%]{progress .danger} |

## Sprint Progress

Current sprint: [60%]{progress="6 of 10 tasks complete"}
```
