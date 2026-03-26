# Extensions

Extensions provide a clean way to bundle related customizations together. Each extension can register inline patterns, block patterns, and render event listeners.

## Available Extensions

| Extension | Description |
|-----------|-------------|
| [AdmonitionExtension](#admonitionextension) | Transforms divs into semantic admonition markup with accessibility support |
| [AutolinkExtension](#autolinkextension) | Auto-links bare URLs and email addresses |
| [DefaultAttributesExtension](#defaultattributesextension) | Adds default attributes to elements by type |
| [ExternalLinksExtension](#externallinksextension) | Adds `target="_blank"` and `rel` attributes to external links |
| [FrontmatterExtension](#frontmatterextension) | Parses YAML/NEON/TOML/JSON frontmatter at document start |
| [HeadingPermalinksExtension](#headingpermalinksextension) | Adds clickable anchor links to headings |
| [MentionsExtension](#mentionsextension) | Converts `@username` patterns to profile links |
| [MermaidExtension](#mermaidextension) | Transforms mermaid code blocks into diagrams |
| [SemanticSpanExtension](#semanticspanextension) | Converts span attributes to semantic HTML elements (`<kbd>`, `<dfn>`, `<abbr>`) |
| [SmartQuotesExtension](#smartquotesextension) | Configures locale-specific smart quote characters |
| [TableOfContentsExtension](#tableofcontentsextension) | Generates a table of contents from headings |
| [TabsExtension](#tabsextension) | Transforms divs into accessible tabbed content interfaces |
| [WikilinksExtension](#wikilinksextension) | Converts `[[Page Name]]` patterns to wiki-style links |

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

Extensions are reset per render, so reusing the same `DjotConverter` across multiple `convert()` calls will not carry per-document extension state such as collected TOC entries into the next output.

## AdmonitionExtension

Transforms fenced divs with admonition type classes into semantic HTML with proper accessibility attributes. Inspired by Python-Markdown/Material for MkDocs admonition syntax, using djot's native fenced div syntax.

```php
use Djot\Extension\AdmonitionExtension;

// Default configuration
$converter->addExtension(new AdmonitionExtension());

// Custom configuration
$converter->addExtension(new AdmonitionExtension(
    types: ['note', 'tip', 'warning', 'danger', 'info', 'success'],
    defaultTitle: true,
    titleTag: 'p',
    titleClass: 'admonition-title',
    containerClass: 'admonition',
));
```

**Basic admonition:**

```djot
::: note
This is a note.
:::
```

```html
<div class="admonition note" role="note">
  <p class="admonition-title">Note</p>
  <p>This is a note.</p>
</div>
```

**With custom title:**

```djot
{title="Watch Out!"}
::: warning
Be careful here.
:::
```

```html
<div class="admonition warning" role="alert">
  <p class="admonition-title">Watch Out!</p>
  <p>Be careful here.</p>
</div>
```

**Collapsible admonitions:**

Use `{collapsible}` for a collapsed state or `{collapsible=open}` for expanded by default:

```djot
{collapsible}
::: tip
Click to expand this tip.
:::

{collapsible=open}
::: danger
This is expanded by default.
:::
```

```html
<details class="admonition tip">
  <summary>Tip</summary>
  <p>Click to expand this tip.</p>
</details>

<details class="admonition danger" open>
  <summary>Danger</summary>
  <p>This is expanded by default.</p>
</details>
```

**ARIA roles:**

The extension automatically adds appropriate ARIA roles:
- `role="note"` for informational types: `note`, `tip`, `info`, `success`
- `role="alert"` for warning types: `warning`, `danger`

**Configuration options:**

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `types` | `array` | `['note', 'tip', 'warning', 'danger', 'info', 'success']` | Admonition types to recognize |
| `defaultTitle` | `bool` | `true` | Auto-generate title from type name |
| `titleTag` | `string` | `'p'` | HTML tag for the title element |
| `titleClass` | `string` | `'admonition-title'` | CSS class for the title element |
| `containerClass` | `string` | `'admonition'` | Base CSS class for the container |
| `icons` | `bool\|array` | `false` | Enable icons: `true` for defaults, or array for custom |
| `iconClass` | `string` | `'admonition-icon'` | CSS class for the icon wrapper span |

**Icons:**

Add emoji icons to admonition titles using the `icons` parameter:

```php
// Enable default icons
$converter->addExtension(new AdmonitionExtension(icons: true));

// Custom icons
$converter->addExtension(new AdmonitionExtension(
    icons: [
        'note' => '🗒️',
        'tip' => '🌟',
        'warning' => '🔶',
        'danger' => '☠️',
    ],
));
```

**Default icons** (when `icons: true`):

| Type | Icon |
|------|------|
| `note` | 📝 |
| `tip` | 💡 |
| `warning` | ⚠️ |
| `danger` | 🚨 |
| `info` | ℹ️ |
| `success` | ✅ |

**Output with icons:**

```html
<div class="admonition note" role="note">
  <p class="admonition-title"><span class="admonition-icon">ℹ️</span> Note</p>
  <p>This is a note.</p>
</div>
```

**Styling icons:**

```css
.admonition-icon {
  margin-right: 0.25em;
}
```

**Custom types:**

```php
// Only recognize custom types
$converter->addExtension(new AdmonitionExtension(
    types: ['important', 'example', 'exercise'],
));
```

**Styling for MkDocs/Material compatibility:**

```php
// Use div with class for title (common in documentation systems)
$converter->addExtension(new AdmonitionExtension(
    titleTag: 'div',
    titleClass: 'admonition-title',
));
```

**Disable auto-generated titles:**

```php
// Only show titles when explicitly set via {title="..."}
$converter->addExtension(new AdmonitionExtension(
    defaultTitle: false,
));
```

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

## FrontmatterExtension

Parses frontmatter blocks at the start of documents. Supports YAML, NEON, TOML, JSON, or any other format. The extension parses the frontmatter syntax but does not interpret the content — applications should use their preferred library (symfony/yaml, etc.) to parse the raw content.

> **Note:** A format identifier (`---yaml`, `---toml`, `---json`, ...) distinguishes frontmatter from a bare thematic break (`---`). When no identifier is present, the extension falls back to a configurable default format (`yaml` by default). This follows the approach used by the [tree-sitter-djot](https://github.com/treeman/tree-sitter-djot) grammar.

**Syntax:**

```djot
---yaml
title: My Document
author: John Doe
tags:
  - php
  - djot
---

# Document content starts here
```

A bare `---` opening is also accepted and uses the configured default format:

```djot
---
title: My Document
---

# Document content starts here
```

**Basic usage:**

```php
use Djot\DjotConverter;
use Djot\Extension\FrontmatterExtension;

$ext = new FrontmatterExtension();
$converter = new DjotConverter();
$converter->addExtension($ext);

$html = $converter->convert($djot);

// Access the frontmatter after parsing
if ($ext->hasFrontmatter()) {
    echo $ext->getFormat();  // 'yaml'
    echo $ext->getContent(); // Raw YAML string
}
```

**Parsing frontmatter content:**

Use `getParsedContent()` with your preferred parser library:

```php
use Symfony\Component\Yaml\Yaml;

$metadata = $ext->getParsedContent(function (string $content, string $format) {
    return match ($format) {
        'yaml' => Yaml::parse($content),
        'neon' => \Nette\Neon\Neon::decode($content),
        'toml' => \Yosymfony\Toml\Toml::parse($content),
        'json' => json_decode($content, true),
        default => null,
    };
});

echo $metadata['title'];  // 'My Document'
echo $metadata['author']; // 'John Doe'
```

**Default format:**

When a frontmatter block opens with a bare `---` (no format identifier), the `defaultFormat` parameter controls which format is assumed:

```php
// Falls back to 'yaml' (the built-in default)
$ext = new FrontmatterExtension();

// Use 'toml' as the default for bare --- blocks
$ext = new FrontmatterExtension(defaultFormat: 'toml');
```

Blocks that include an explicit identifier always take precedence over `defaultFormat`:

```djot
---json
{"title": "always json, regardless of defaultFormat"}
---
```

**Rendering options:**

By default, frontmatter produces no HTML output. You can change this:

```php
use Djot\Extension\Frontmatter;
use Djot\Extension\FrontmatterExtension;

// Render as HTML comment (useful for debugging)
$ext = new FrontmatterExtension(renderAsComment: true);

// Custom render callback
$ext = new FrontmatterExtension(
    renderCallback: fn (Frontmatter $fm) =>
        '<script type="application/ld+json">' .
        htmlspecialchars($fm->getContent()) .
        '</script>',
);
```

**Output with `renderAsComment: true`:**

```html
<!-- frontmatter (yaml)
title: My Document
author: John Doe
-->
```

**Reusing for multiple documents:**

```php
$ext->reset();  // Clear frontmatter state
$converter->convert($anotherDocument);
```

**Attributes:**

Block attributes are placed on the preceding line (standard djot style):

```djot
{.meta #frontmatter}
---yaml
title: Document with meta class
---

{kernel="myproject" #cell-1}
---python
import flight
---
```

Access attributes via the Frontmatter node:

```php
$frontmatter = $ext->getFrontmatter();
$class = $frontmatter->getAttribute('class');   // 'meta'
$kernel = $frontmatter->getAttribute('kernel'); // 'myproject'
$id = $frontmatter->getAttribute('id');         // 'cell-1'
```

**Supported formats:**

Any word can be used as the format identifier. Common ones:

| Format | Example | Notes |
|--------|---------|-------|
| `yaml` | `---yaml` | Built-in default for bare `---` |
| `toml` | `---toml` | |
| `json` | `---json` | |
| `neon` | `---neon` | |
| `lua`  | `---lua`  | |
| `python` | `---python` | |

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

## MermaidExtension

Transforms code blocks with language `mermaid` into Mermaid.js-compatible markup for rendering diagrams. Mermaid supports flowcharts, sequence diagrams, class diagrams, state diagrams, ER diagrams, Gantt charts, pie charts, git graphs, and more.

```php
use Djot\Extension\MermaidExtension;

// Default configuration
$converter->addExtension(new MermaidExtension());

// Custom configuration
$converter->addExtension(new MermaidExtension(
    tag: 'pre',           // or 'div'
    cssClass: 'mermaid',
    wrapInFigure: false,
    figureClass: 'mermaid-figure',
));
```

**Basic flowchart:**

````djot
``` mermaid
graph TD;
    A-->B;
    A-->C;
    B-->D;
    C-->D;
```
````

**Output:**

```html
<pre class="mermaid">graph TD;
    A-->B;
    A-->C;
    B-->D;
    C-->D;
</pre>
```

**Sequence diagram:**

````djot
``` mermaid
sequenceDiagram
    Alice->>Bob: Hello Bob
    Bob-->>Alice: Hi Alice
```
````

**Class diagram:**

````djot
``` mermaid
classDiagram
    Animal <|-- Duck
    Animal <|-- Fish
    Animal : +int age
    Animal: +isMammal()
```
````

**Configuration options:**

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `tag` | `string` | `'pre'` | HTML tag to use (`'pre'` or `'div'`) |
| `cssClass` | `string` | `'mermaid'` | CSS class for Mermaid.js detection |
| `wrapInFigure` | `bool` | `false` | Wrap in a `<figure>` element |
| `figureClass` | `string` | `'mermaid-figure'` | CSS class for the figure wrapper |

**With figure wrapper:**

```php
$converter->addExtension(new MermaidExtension(wrapInFigure: true));
```

```html
<figure class="mermaid-figure">
  <pre class="mermaid">graph TD;
      A-->B;
  </pre>
</figure>
```

**Block attributes:**

Custom attributes are preserved on the output element:

````djot
{#my-diagram .custom-diagram data-theme="dark"}
``` mermaid
graph LR;
    A-->B;
```
````

```html
<pre class="mermaid custom-diagram" id="my-diagram" data-theme="dark">graph LR;
    A-->B;
</pre>
```

### Required JavaScript

Include Mermaid.js in your page to render the diagrams:

**Via CDN (ES module):**

```html
<script type="module">
  import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';
  mermaid.initialize({ startOnLoad: true });
</script>
```

**Via npm:**

```javascript
import mermaid from 'mermaid';
mermaid.initialize({ startOnLoad: true });
```

**For dynamic content:**

If diagrams are loaded after page load (AJAX, SPA), call Mermaid manually:

```javascript
import mermaid from 'mermaid';

// After inserting new mermaid blocks into the DOM
await mermaid.run({
  querySelector: '.mermaid',
});
```

### Supported Diagram Types

Mermaid supports many diagram types:

| Type | Syntax Start |
|------|--------------|
| Flowchart | `graph TD` or `graph LR` |
| Sequence | `sequenceDiagram` |
| Class | `classDiagram` |
| State | `stateDiagram-v2` |
| ER | `erDiagram` |
| Gantt | `gantt` |
| Pie | `pie` |
| Git | `gitGraph` |
| Mindmap | `mindmap` |
| Timeline | `timeline` |

See <https://mermaid.js.org/> for full documentation and syntax.

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

## TabsExtension

Transforms nested divs into accessible tabbed content interfaces. Supports two output modes: CSS-only (no JavaScript required) and ARIA mode (with JavaScript for full accessibility).

```php
use Djot\Extension\TabsExtension;

// Default: CSS-only mode
$converter->addExtension(new TabsExtension());

// ARIA mode (requires JavaScript)
$converter->addExtension(new TabsExtension(mode: 'aria'));

// Custom configuration
$converter->addExtension(new TabsExtension(
    mode: 'css',
    wrapperClass: 'tabs',
    tabClass: 'tabs-panel',
    labelClass: 'tabs-label',
    idPrefix: 'tabset',
));
```

**Basic syntax using headings:**

```djot
:::: tabs
::: tab
### First Tab

Content for the first tab.
:::

::: tab
### Second Tab

Content for the second tab.
:::
::::
```

**Using label attributes:**

```djot
:::: tabs
{label="First Tab"}
::: tab
Content here.
:::

{label="Second Tab" selected}
::: tab
This tab is selected by default.
:::
::::
```

Note: Use 4 colons (`::::`) for the outer `tabs` wrapper and 3 colons (`:::`) for inner `tab` divs. This is required for djot to parse nested fenced divs correctly.

**Configuration options:**

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `mode` | `string` | `'css'` | Output mode: `'css'` or `'aria'` |
| `wrapperClass` | `string` | `'tabs'` | CSS class for the tabs container |
| `tabClass` | `string` | `'tabs-panel'` | CSS class for individual tab panels |
| `labelClass` | `string` | `'tabs-label'` | CSS class for tab labels/buttons |
| `radioClass` | `string` | `'tabs-radio'` | CSS class for radio inputs (CSS mode only) |
| `idPrefix` | `string` | `'tabset'` | Prefix for generated IDs |

### CSS-Only Mode

Uses radio inputs and CSS sibling selectors. No JavaScript required.

**Output:**

```html
<div class="tabs">
  <input type="radio" name="tabset-1" id="tabset-1-tab-1" checked class="tabs-radio">
  <label for="tabset-1-tab-1" class="tabs-label">First Tab</label>
  <input type="radio" name="tabset-1" id="tabset-1-tab-2" class="tabs-radio">
  <label for="tabset-1-tab-2" class="tabs-label">Second Tab</label>
  <div class="tabs-panel">Content for the first tab.</div>
  <div class="tabs-panel">Content for the second tab.</div>
</div>
```

**Required CSS:**

```css
.tabs { display: flex; flex-wrap: wrap; }
.tabs-radio { display: none; }
.tabs-label {
  padding: 0.5rem 1rem;
  cursor: pointer;
  border-bottom: 2px solid transparent;
}
.tabs-radio:checked + .tabs-label {
  border-bottom-color: currentColor;
  font-weight: bold;
}
.tabs-panel {
  display: none;
  width: 100%;
  order: 1;
  padding: 1rem 0;
}
/* Show active panel based on checked radio */
.tabs-radio:nth-of-type(1):checked ~ .tabs-panel:nth-of-type(1),
.tabs-radio:nth-of-type(2):checked ~ .tabs-panel:nth-of-type(2),
.tabs-radio:nth-of-type(3):checked ~ .tabs-panel:nth-of-type(3),
.tabs-radio:nth-of-type(4):checked ~ .tabs-panel:nth-of-type(4),
.tabs-radio:nth-of-type(5):checked ~ .tabs-panel:nth-of-type(5) {
  display: block;
}
```

### ARIA Mode

Uses semantic ARIA roles with button/tabpanel structure. Requires JavaScript for tab switching.

**Output:**

```html
<div class="tabs" role="tablist">
  <button role="tab" id="tabset-1-tab-1" aria-selected="true"
          aria-controls="tabset-1-panel-1" class="tabs-label">First Tab</button>
  <button role="tab" id="tabset-1-tab-2" aria-selected="false"
          aria-controls="tabset-1-panel-2" class="tabs-label" tabindex="-1">Second Tab</button>
  <div role="tabpanel" id="tabset-1-panel-1" aria-labelledby="tabset-1-tab-1"
       class="tabs-panel">Content for the first tab.</div>
  <div role="tabpanel" id="tabset-1-panel-2" aria-labelledby="tabset-1-tab-2"
       class="tabs-panel" hidden>Content for the second tab.</div>
</div>
```

**Required JavaScript:**

```javascript
// Tab click handler
document.addEventListener('click', function(e) {
  const tab = e.target.closest('[role="tab"]');
  if (!tab) return;

  const tablist = tab.closest('[role="tablist"]');
  const tabs = tablist.querySelectorAll('[role="tab"]');
  const panels = tablist.querySelectorAll('[role="tabpanel"]');

  tabs.forEach(t => {
    t.setAttribute('aria-selected', 'false');
    t.setAttribute('tabindex', '-1');
  });
  panels.forEach(p => p.hidden = true);

  tab.setAttribute('aria-selected', 'true');
  tab.removeAttribute('tabindex');
  const panel = tablist.querySelector('#' + tab.getAttribute('aria-controls'));
  if (panel) panel.hidden = false;
});

// Keyboard navigation (arrow keys, Home, End)
document.addEventListener('keydown', function(e) {
  const tab = e.target.closest('[role="tab"]');
  if (!tab) return;

  const tablist = tab.closest('[role="tablist"]');
  const tabs = Array.from(tablist.querySelectorAll('[role="tab"]'));
  const index = tabs.indexOf(tab);

  let newIndex;
  if (e.key === 'ArrowRight') newIndex = (index + 1) % tabs.length;
  else if (e.key === 'ArrowLeft') newIndex = (index - 1 + tabs.length) % tabs.length;
  else if (e.key === 'Home') newIndex = 0;
  else if (e.key === 'End') newIndex = tabs.length - 1;
  else return;

  e.preventDefault();
  tabs[newIndex].click();
  tabs[newIndex].focus();
});
```

### Choosing a Mode

| Feature | CSS Mode | ARIA Mode |
|---------|----------|-----------|
| JavaScript required | No | Yes |
| Keyboard navigation | Tab only | Arrow keys, Home, End |
| Screen reader support | Basic | Full ARIA |
| Browser support | All modern | All modern |
| Best for | Simple sites | Accessible apps |

## WikilinksExtension

Converts `[[Page Name]]` patterns into wiki-style links, commonly used in wiki systems and note-taking apps like Obsidian, Notion, and MediaWiki.

> **Note:** This syntax is not yet part of the official djot spec. See [jgm/djot#26](https://github.com/jgm/djot/issues/26) for the upstream discussion.

```php
use Djot\Extension\WikilinksExtension;

// Default: creates URL-safe slugs
$converter->addExtension(new WikilinksExtension());

// Custom URL generator
$converter->addExtension(new WikilinksExtension(
    urlGenerator: fn (string $page) => '/wiki/' . strtolower(str_replace(' ', '_', $page)) . '.html',
));

// Open in new window
$converter->addExtension(new WikilinksExtension(
    newWindow: true,
));

// Custom CSS class
$converter->addExtension(new WikilinksExtension(
    cssClass: 'wiki-link internal',
));
```

**Supported syntax:**

| Syntax | Description | Output |
|--------|-------------|--------|
| `[[Page]]` | Basic link | `<a href="page">Page</a>` |
| `[[Page Name]]` | Spaces in name | `<a href="page-name">Page Name</a>` |
| `[[page\|Display Text]]` | Custom display text | `<a href="page">Display Text</a>` |
| `[[page#section]]` | Link with anchor | `<a href="page#section">page</a>` |
| `[[page#section\|Link]]` | Anchor with display text | `<a href="page#section">Link</a>` |
| `[[folder/page]]` | Path support | `<a href="folder/page">folder/page</a>` |

**Input:**
```djot
See [[Tigers]] for more info, or check [[Big Cats|the cats page]].

Jump to [[Getting Started#installation]] for setup instructions.
```

**Output:**
```html
<p>See <a href="tigers" class="wikilink" data-wikilink="Tigers">Tigers</a> for more info,
or check <a href="big-cats" class="wikilink" data-wikilink="Big Cats">the cats page</a>.</p>
<p>Jump to <a href="getting-started#installation" class="wikilink" data-wikilink="Getting Started">installation</a> for setup instructions.</p>
```

**Configuration options:**

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `urlGenerator` | `Closure` | Slugify | Function that converts page name to URL |
| `cssClass` | `string` | `'wikilink'` | CSS class(es) for the link |
| `newWindow` | `bool` | `false` | Open links in new tab |

**Common configurations:**

```php
// Obsidian-style (preserve paths, encode for URLs)
$converter->addExtension(new WikilinksExtension(
    urlGenerator: fn (string $page) => '/notes/' . rawurlencode($page) . '.md',
));

// MediaWiki-style (underscores instead of hyphens)
$converter->addExtension(new WikilinksExtension(
    urlGenerator: fn (string $page) => '/wiki/' . str_replace(' ', '_', $page),
));

// Static site generator (lowercase with .html extension)
$converter->addExtension(new WikilinksExtension(
    urlGenerator: fn (string $page) => '/' . strtolower(str_replace(' ', '-', $page)) . '.html',
));
```

**JavaScript integration:**

Each wikilink includes a `data-wikilink` attribute with the original page name, useful for client-side handling:

```javascript
document.querySelectorAll('a[data-wikilink]').forEach(link => {
    const pageName = link.dataset.wikilink;
    // Check if page exists, add special styling, etc.
});
```

## Creating Custom Extensions

Implement `ExtensionInterface` to create your own extensions:

```php
use Djot\DjotConverter;
use Djot\Extension\ExtensionInterface;
use Djot\Event\RenderEvent;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Text;

class HashtagExtension implements ExtensionInterface
{
    public function __construct(
        protected string $baseUrl = '/tags/',
    ) {
    }

    public function register(DjotConverter $converter): void
    {
        // Add inline pattern for #hashtag syntax
        $converter->getParser()->getInlineParser()->addInlinePattern(
            '/#([a-zA-Z][a-zA-Z0-9_]*)/',
            function (string $match, array $groups): Link {
                $tag = $groups[1];
                $url = $this->baseUrl . rawurlencode(strtolower($tag));

                $link = new Link($url);
                $link->addClass('hashtag');
                $link->appendChild(new Text('#' . $tag));

                return $link;
            },
        );
    }
}

// Usage
$converter->addExtension(new HashtagExtension(baseUrl: '/tags/'));
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
use Djot\Extension\WikilinksExtension;

$converter = new DjotConverter();
$tocExtension = new TableOfContentsExtension(minLevel: 2);

// Register extensions (order matters for some combinations)
$converter
    ->addExtension(new AutolinkExtension())           // First: create links from URLs
    ->addExtension(new ExternalLinksExtension())      // Then: add attributes to external links
    ->addExtension(new MentionsExtension())
    ->addExtension(new WikilinksExtension())          // Wiki-style links
    ->addExtension($tocExtension)                     // TOC before permalinks for clean text
    ->addExtension(new HeadingPermalinksExtension());

$djot = <<<'DJOT'
# Welcome

Thanks @admin for setting this up! See [[Getting Started]] below.

## Getting Started

Visit https://example.com for documentation.

## Configuration

Contact support@example.com for help. Also check [[Advanced Config|advanced settings]].
DJOT;

$html = $converter->convert($djot);
$toc = $tocExtension->getTocHtml();

echo $toc;
echo $html;
```
