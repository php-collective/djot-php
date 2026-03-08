# Extensions

Extensions provide a clean way to bundle related customizations together. Each extension can register inline patterns, block patterns, and render event listeners.

## Available Extensions

| Extension | Description |
|-----------|-------------|
| [AutolinkExtension](#autolinkextension) | Auto-links bare URLs and email addresses |
| [DefaultAttributesExtension](#defaultattributesextension) | Adds default attributes to elements by type |
| [ExternalLinksExtension](#externallinksextension) | Adds `target="_blank"` and `rel` attributes to external links |
| [HeadingPermalinksExtension](#headingpermalinksextension) | Adds clickable anchor links to headings |
| [MentionsExtension](#mentionsextension) | Converts `@username` patterns to profile links |
| [SemanticSpanExtension](#semanticspanextension) | Converts span attributes to semantic HTML elements (`<kbd>`, `<dfn>`, `<abbr>`) |
| [SmartQuotesExtension](#smartquotesextension) | Configures locale-specific smart quote characters |
| [TableOfContentsExtension](#tableofcontentsextension) | Generates a table of contents from headings |

## Basic Usage

```php
use Djot\DjotConverter;
use Djot\Extension\ExternalLinksExtension;
use Djot\Extension\MentionsExtension;

$converter = new DjotConverter();

// Chain multiple extensions
$converter
    ->addExtension(new ExternalLinksExtension())
    ->addExtension(new MentionsExtension());

$html = $converter->convert($djot);
```

## Extension Order

Extensions are applied in registration order. Generally, order doesn't matter, but there are some considerations:

- **AutolinkExtension** should be registered before **ExternalLinksExtension** if you want auto-linked URLs to also get external link attributes
- **TableOfContentsExtension** should be registered before **HeadingPermalinksExtension** if you want clean heading text in the TOC (without permalink symbols)

## ExternalLinksExtension

Adds `target="_blank"` and `rel="noopener noreferrer"` to external links (http/https URLs).

```php
use Djot\Extension\ExternalLinksExtension;

// Default: all external links open in new tab
$converter->addExtension(new ExternalLinksExtension());

// Exclude your own domains
$converter->addExtension(new ExternalLinksExtension(
    internalHosts: ['example.com', 'www.example.com'],
));

// Custom attributes
$converter->addExtension(new ExternalLinksExtension(
    target: '_blank',
    rel: 'noopener',
    nofollow: true, // Adds 'nofollow' to rel
));
```

**Input:**
```djot
Visit [Example](https://example.com) or [Home](/home).
```

**Output:**
```html
<p>Visit <a href="https://example.com" target="_blank" rel="noopener noreferrer">Example</a> or <a href="/home">Home</a>.</p>
```

## HeadingPermalinksExtension

Adds clickable permalink anchors to headings, useful for documentation sites.

```php
use Djot\Extension\HeadingPermalinksExtension;

// Default: pilcrow symbol after heading
$converter->addExtension(new HeadingPermalinksExtension());

// Custom configuration
$converter->addExtension(new HeadingPermalinksExtension(
    symbol: '#',           // Link text
    position: 'before',    // 'before' or 'after'
    cssClass: 'anchor',    // CSS class for the link
    ariaLabel: 'Link to section',
    levels: [2, 3],        // Only h2 and h3
));
```

**Input:**
```djot
## Getting Started
```

**Output:**
```html
<section id="Getting-Started">
<h2>Getting Started <span class="permalink-wrapper"><a href="#Getting-Started" class="permalink" aria-label="Permalink">¶</a></span></h2>
</section>
```

## MentionsExtension

Converts `@username` patterns into user profile links.

```php
use Djot\Extension\MentionsExtension;

// Default: /users/view/{username}
$converter->addExtension(new MentionsExtension());

// Custom URL template
$converter->addExtension(new MentionsExtension(
    urlTemplate: '/profile/{username}',
    cssClass: 'user-mention',
));
```

**Input:**
```djot
Thanks @johndoe for the help!
```

**Output:**
```html
<p>Thanks <a href="/users/view/johndoe" data-username="johndoe" class="mention">@johndoe</a> for the help!</p>
```

## SemanticSpanExtension

Converts spans with semantic attributes (`kbd`, `dfn`, `abbr`) into proper HTML5 semantic elements. This is useful for marking up keyboard shortcuts, definitions, and abbreviations.

```php
use Djot\Extension\SemanticSpanExtension;

$converter->addExtension(new SemanticSpanExtension());
```

**Supported attributes:**

| Attribute | HTML Element | Usage |
|-----------|--------------|-------|
| `kbd` | `<kbd>` | Keyboard input/shortcuts |
| `dfn` | `<dfn>` | Definition of a term |
| `abbr` | `<abbr>` | Abbreviation with title |

**Keyboard shortcuts:**

```djot
Press [Ctrl+C]{kbd} to copy and [Ctrl+V]{kbd} to paste.
```

```html
<p>Press <kbd>Ctrl+C</kbd> to copy and <kbd>Ctrl+V</kbd> to paste.</p>
```

**Definitions:**

```djot
A [variable]{dfn} is a named storage location.

The [API]{dfn="Application Programming Interface"} provides access to the system.
```

```html
<p>A <dfn>variable</dfn> is a named storage location.</p>
<p>The <dfn title="Application Programming Interface">API</dfn> provides access to the system.</p>
```

**Abbreviations:**

```djot
The [HTML]{abbr="HyperText Markup Language"} standard defines web content structure.
```

```html
<p>The <abbr title="HyperText Markup Language">HTML</abbr> standard defines web content structure.</p>
```

**Combining attributes:**

Attributes can be combined. The nesting order is: `dfn` wraps `kbd` wraps `abbr`.

```djot
[CSS]{dfn abbr="Cascading Style Sheets"}
```

```html
<dfn><abbr title="Cascading Style Sheets">CSS</abbr></dfn>
```

**Preserving other attributes:**

Other attributes (classes, IDs) are preserved in an outer span:

```djot
[Ctrl+S]{kbd .shortcut #save-shortcut}
```

```html
<span class="shortcut" id="save-shortcut"><kbd>Ctrl+S</kbd></span>
```

**Note:** This extension provides manual control via attributes. For automatic abbreviation expansion (define once, apply everywhere), use the built-in abbreviation definition syntax instead:

```djot
*[HTML]: HyperText Markup Language

The HTML specification defines...
```

## SmartQuotesExtension

Configures locale-specific smart quote characters. By default, the parser produces English-style typographic quotes (`"…"` `'…'`). This extension lets you change them per locale while keeping apostrophes as `'` (U+2019) regardless of locale.

```php
use Djot\Extension\SmartQuotesExtension;

// German: „…" ‚…'
$converter->addExtension(new SmartQuotesExtension(locale: 'de'));

// French: «…» ‹…›
$converter->addExtension(new SmartQuotesExtension(locale: 'fr'));

// Swiss German: «…» ‹…›
$converter->addExtension(new SmartQuotesExtension(locale: 'de-CH'));

// Explicit characters (override any locale)
$converter->addExtension(new SmartQuotesExtension(
    openDoubleQuote: "\u{00AB}",
    closeDoubleQuote: "\u{00BB}",
    openSingleQuote: "\u{2039}",
    closeSingleQuote: "\u{203A}",
));

// Mix: locale with partial overrides
$converter->addExtension(new SmartQuotesExtension(
    locale: 'de',
    openDoubleQuote: "\u{00AB}",  // Override just double quotes
    closeDoubleQuote: "\u{00BB}",
));
```

**Input (with `locale: 'de'`):**
```djot
"Hallo," sagte sie. 'Es ist ein schöner Tag.'

Er antwortete: "Ich glaub's nicht."
```

**Output:**
```html
<p>„Hallo," sagte sie. ‚Es ist ein schöner Tag.'</p>
<p>Er antwortete: „Ich glaub's nicht."</p>
```

Note that the apostrophe in `glaub's` stays as `'` (U+2019) — apostrophes are language-independent.

**Supported locales:** `en`, `de`, `de-CH`, `fr`, `pl`, `ru`, `ja`, `zh`, `sv`, `da`, `fi`, `cs`, `hu`, `it`, `es`, `pt`, `nl`, `nb`, `nn`, `uk`

**Locale resolution:** exact match → language-only fallback (e.g., `de-AT` → `de`) → English defaults. Underscore format is also accepted (e.g., `fr_FR` → `fr`).

**Static helpers:**

```php
SmartQuotesExtension::getSupportedLocales();    // ['en', 'de', 'de-CH', ...]
SmartQuotesExtension::isLocaleSupported('de');   // true
SmartQuotesExtension::isLocaleSupported('de-AT'); // true (falls back to 'de')
SmartQuotesExtension::isLocaleSupported('xx');    // false
```

## TableOfContentsExtension

Extracts headings and generates a table of contents. The TOC is available after `convert()` is called.

```php
use Djot\Extension\TableOfContentsExtension;

$tocExtension = new TableOfContentsExtension();
$converter->addExtension($tocExtension);

$html = $converter->convert($djot);

// Get TOC as HTML
$tocHtml = $tocExtension->getTocHtml();

// Or get raw data for custom rendering
$tocData = $tocExtension->getToc();
// Returns: [['level' => 1, 'text' => 'Intro', 'id' => 'Intro'], ...]
```

**Configuration:**

```php
$tocExtension = new TableOfContentsExtension(
    minLevel: 2,       // Start from h2
    maxLevel: 4,       // Up to h4
    listType: 'ol',    // 'ul' or 'ol'
    cssClass: 'toc',   // CSS class for nav element
    position: 'top',   // 'top', 'bottom', or null for manual placement
    separator: '<hr>', // Optional HTML between TOC and content
);
```

**Auto-insertion:**

```php
// TOC automatically inserted at top of output
$converter->addExtension(new TableOfContentsExtension(position: 'top'));
$html = $converter->convert($djot); // TOC is included in $html

// Or at the bottom
$converter->addExtension(new TableOfContentsExtension(position: 'bottom'));

// With separator
$converter->addExtension(new TableOfContentsExtension(
    position: 'top',
    separator: '<hr>',
));

// Default: manual placement (position: null)
$tocExtension = new TableOfContentsExtension();
$converter->addExtension($tocExtension);
$html = $converter->convert($djot);
$toc = $tocExtension->getTocHtml(); // Place wherever you want
```

**Example TOC output:**

```html
<nav class="toc">
<ul>
<li><a href="#Introduction">Introduction</a></li>
<li><a href="#Getting-Started">Getting Started</a>
<ul>
<li><a href="#Installation">Installation</a></li>
<li><a href="#Configuration">Configuration</a></li>
</ul>
</li>
</ul>
</nav>
```

**Helper methods:**

```php
$tocExtension->hasToc();   // bool - true if any headings found
$tocExtension->clear();    // Reset for reuse with another document
```

## AutolinkExtension

Automatically converts bare URLs and email addresses into clickable links.

```php
use Djot\Extension\AutolinkExtension;

// Default: http, https, and mailto
$converter->addExtension(new AutolinkExtension());

// Only https
$converter->addExtension(new AutolinkExtension(
    allowedSchemes: ['https'],
));

// Disable email auto-linking
$converter->addExtension(new AutolinkExtension(
    allowedSchemes: ['https', 'http'],
));
```

**Input:**
```djot
Visit https://example.com or email user@example.com for help.
```

**Output:**
```html
<p>Visit <a href="https://example.com">https://example.com</a> or email <a href="mailto:user@example.com">user@example.com</a> for help.</p>
```

## DefaultAttributesExtension

Adds default attributes to elements by type. Useful for adding CSS classes, lazy loading, or other common attributes.

```php
use Djot\Extension\DefaultAttributesExtension;

$converter->addExtension(new DefaultAttributesExtension([
    'image' => ['loading' => 'lazy', 'decoding' => 'async'],
    'table' => ['class' => 'table table-striped'],
    'link' => ['class' => 'link'],
    'code_block' => ['class' => 'highlight'],
]));
```

**Behavior:**
- Default attributes are only applied if the element doesn't already have that attribute
- Classes are merged (both default and existing classes are kept)

**Supported element types (use snake_case):**

| Block Elements | Inline Elements |
|----------------|-----------------|
| paragraph | link |
| heading | image |
| code_block | emphasis |
| block_quote | strong |
| list | code |
| list_item | span |
| table | subscript |
| table_cell | superscript |
| div | footnote |
| thematic_break | footnote_ref |

**Common use cases:**

```php
// Lazy loading images
$converter->addExtension(new DefaultAttributesExtension([
    'image' => ['loading' => 'lazy'],
]));

// Bootstrap tables
$converter->addExtension(new DefaultAttributesExtension([
    'table' => ['class' => 'table table-bordered'],
]));

// Tailwind prose styling
$converter->addExtension(new DefaultAttributesExtension([
    'paragraph' => ['class' => 'mb-4'],
    'heading' => ['class' => 'font-bold'],
    'block_quote' => ['class' => 'border-l-4 pl-4 italic'],
]));
```

## Creating Custom Extensions

Implement `ExtensionInterface` to create your own extensions:

```php
use Djot\DjotConverter;
use Djot\Extension\ExtensionInterface;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Text;

class WikiLinkExtension implements ExtensionInterface
{
    public function __construct(
        protected string $baseUrl = '/wiki/',
    ) {
    }

    public function register(DjotConverter $converter): void
    {
        // Add inline pattern for [[Page Name]] syntax
        $converter->getParser()->getInlineParser()->addInlinePattern(
            '/\[\[([^\]]+)\]\]/',
            function (string $match, array $groups): Link {
                $pageName = $groups[1];
                $url = $this->baseUrl . rawurlencode($pageName);

                $link = new Link($url);
                $link->addClass('wiki-link');
                $link->appendChild(new Text($pageName));

                return $link;
            },
        );
    }
}

// Usage
$converter->addExtension(new WikiLinkExtension(baseUrl: '/docs/'));
```

## Using Multiple Extensions Together

Here's a complete example using all extensions:

```php
use Djot\DjotConverter;
use Djot\Extension\AutolinkExtension;
use Djot\Extension\ExternalLinksExtension;
use Djot\Extension\HeadingPermalinksExtension;
use Djot\Extension\MentionsExtension;
use Djot\Extension\TableOfContentsExtension;

$converter = new DjotConverter();
$tocExtension = new TableOfContentsExtension(minLevel: 2);

// Register extensions (order matters for some combinations)
$converter
    ->addExtension(new AutolinkExtension())           // First: create links from URLs
    ->addExtension(new ExternalLinksExtension())      // Then: add attributes to external links
    ->addExtension(new MentionsExtension())
    ->addExtension($tocExtension)                     // TOC before permalinks for clean text
    ->addExtension(new HeadingPermalinksExtension());

$djot = <<<'DJOT'
# Welcome

Thanks @admin for setting this up!

## Getting Started

Visit https://example.com for documentation.

## Configuration

Contact support@example.com for help.
DJOT;

$html = $converter->convert($djot);
$toc = $tocExtension->getTocHtml();

echo $toc;
echo $html;
```
