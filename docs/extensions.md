# Extensions

Extensions provide a clean way to bundle related customizations together. Each extension can register inline patterns, block patterns, and render event listeners.

## Available Extensions

| Extension | Description |
|-----------|-------------|
| [ExternalLinksExtension](#externallinksextension) | Adds `target="_blank"` and `rel` attributes to external links |
| [HeadingPermalinksExtension](#headingpermalinksextension) | Adds clickable anchor links to headings |
| [MentionsExtension](#mentionsextension) | Converts `@username` patterns to profile links |
| [TableOfContentsExtension](#tableofcontentsextension) | Generates a table of contents from headings |
| [AutolinkExtension](#autolinkextension) | Auto-links bare URLs and email addresses |

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
);
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
