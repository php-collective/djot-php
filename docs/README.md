# Djot PHP Documentation

This directory contains detailed documentation for djot-php.

## Contents

- [Syntax Reference](syntax.md) - Complete Djot syntax guide
- [API Reference](api.md) - Classes and methods
- [Cookbook](cookbook.md) - Common customizations and recipes
- [Architecture](architecture.md) - Internal design
- [Converters](converters.md) - Markdown/BBCode to Djot conversion

## Examples

### Text Formatting

```php
use Djot\DjotConverter;

$converter = new DjotConverter();

$djot = <<<'DJOT'
This is _emphasized_ and *strong* text.

You can also use {=highlighted=}, {+inserted+}, and {-deleted-} text.

Superscript: E=mc^2^ and subscript: H~2~O
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<p>This is <em>emphasized</em> and <strong>strong</strong> text.</p>
<p>You can also use <mark>highlighted</mark>, <ins>inserted</ins>, and <del>deleted</del> text.</p>
<p>Superscript: E=mc<sup>2</sup> and subscript: H<sub>2</sub>O</p>
```

### Headings

```php
$djot = <<<'DJOT'
# Heading 1
## Heading 2
### Heading 3
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<h1>Heading 1</h1>
<h2>Heading 2</h2>
<h3>Heading 3</h3>
```

### Links and Images

```php
$djot = <<<'DJOT'
[Visit Example](https://example.com)

![Alt text](image.png)

Reference style: [Example][ex]

[ex]: https://example.com
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<p><a href="https://example.com">Visit Example</a></p>
<p><img src="image.png" alt="Alt text"></p>
<p>Reference style: <a href="https://example.com">Example</a></p>
```

### Lists

```php
$djot = <<<'DJOT'
Bullet list:

- Item 1
- Item 2
  - Nested item
- Item 3

Ordered list:

1. First
2. Second
3. Third

Task list:

- [ ] Todo
- [x] Done
DJOT;

echo $converter->convert($djot);
```

### Code Blocks

````php
$djot = <<<'DJOT'
Inline `code` works too.

```php
function hello(): void {
    echo "Hello World";
}
```
DJOT;

echo $converter->convert($djot);
````

Output:
```html
<p>Inline <code>code</code> works too.</p>
<pre><code class="language-php">function hello(): void {
    echo "Hello World";
}
</code></pre>
```

### Tables

```php
$djot = <<<'DJOT'
| Name    | Age | City     |
|:--------|:---:|---------:|
| Alice   | 30  | New York |
| Bob     | 25  | London   |
^ Table caption
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<table>
<caption>Table caption</caption>
<thead>
<tr><th style="text-align: left">Name</th><th style="text-align: center">Age</th><th style="text-align: right">City</th></tr>
</thead>
<tbody>
<tr><td style="text-align: left">Alice</td><td style="text-align: center">30</td><td style="text-align: right">New York</td></tr>
<tr><td style="text-align: left">Bob</td><td style="text-align: center">25</td><td style="text-align: right">London</td></tr>
</tbody>
</table>
```

### Block Quotes

```php
$djot = <<<'DJOT'
> This is a quote.
> It can span multiple lines.
>
> And include multiple paragraphs.
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<blockquote>
<p>This is a quote.
It can span multiple lines.</p>
<p>And include multiple paragraphs.</p>
</blockquote>
```

### Divs with Attributes

```php
$djot = <<<'DJOT'
::: warning
This is a warning message.
:::

{.highlight #important}
This paragraph has a class and ID.
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<div class="warning">
<p>This is a warning message.</p>
</div>
<p class="highlight" id="important">This paragraph has a class and ID.</p>
```

### Footnotes

```php
$djot = <<<'DJOT'
Here is a statement[^1] with a footnote.

[^1]: This is the footnote content.
DJOT;

echo $converter->convert($djot);
```

### Math Expressions

```php
$djot = <<<'DJOT'
Inline math: $`E = mc^2`

Display math:

$$`\sum_{i=0}^{n} i = \frac{n(n+1)}{2}`$$
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<p>Inline math: <span class="math inline">\(E = mc^2\)</span></p>
<p>Display math:</p>
<span class="math display">\[\sum_{i=0}^{n} i = \frac{n(n+1)}{2}\]</span>
```

### Smart Typography

```php
$djot = <<<'DJOT'
"Quoted text" and 'single quotes'

Dashes: en--dash and em---dash

Ellipsis...
DJOT;

echo $converter->convert($djot);
```

Output:
```html
<p>"Quoted text" and 'single quotes'</p>
<p>Dashes: en–dash and em—dash</p>
<p>Ellipsis…</p>
```

## Event System

Customize rendering by listening to events:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;

$converter = new DjotConverter();

// Add target="_blank" to external links
$converter->on('render.link', function (RenderEvent $event): void {
    $link = $event->getNode();
    $href = $link->getDestination();

    if (str_starts_with($href, 'http')) {
        $link->setAttribute('target', '_blank');
        $link->setAttribute('rel', 'noopener noreferrer');
    }
});

// Custom emoji rendering
$converter->on('render.symbol', function (RenderEvent $event): void {
    $symbol = $event->getNode();
    $emoji = match ($symbol->getName()) {
        'heart' => '❤️',
        'star' => '⭐',
        'check' => '✅',
        default => ':' . $symbol->getName() . ':',
    };
    $event->setHtml($emoji);
});

echo $converter->convert('[Example](https://example.com) I :heart: this!');
// Output: <p><a href="https://example.com" target="_blank" rel="noopener noreferrer">Example</a> I ❤️ this!</p>
```

## XHTML Mode

For XHTML-compatible output:

```php
$converter = new DjotConverter(xhtml: true);
echo $converter->convert("Line one\\\nLine two");
// Output: <p>Line one<br />
// Line two</p>
```

## Alternative Renderers

### Plain Text

Extract plain text for search indexing or SEO:

```php
use Djot\Renderer\PlainTextRenderer;

$document = $converter->parse('Hello *world*!');
$renderer = new PlainTextRenderer();
echo $renderer->render($document);
// Output: Hello world!
```

### Markdown

Convert Djot to CommonMark Markdown:

```php
use Djot\Renderer\MarkdownRenderer;

$document = $converter->parse('Hello *world*!');
$renderer = new MarkdownRenderer();
echo $renderer->render($document);
// Output: Hello **world**!
```

## File Operations

```php
// Convert file directly
$html = $converter->convertFile('/path/to/document.djot');

// Or parse to AST first
$document = $converter->parseFile('/path/to/document.djot');
$html = $converter->render($document);
```

## Custom Syntax Patterns

Extend Djot with custom inline and block patterns:

```php
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Text;

// Add @mention support
$parser = $converter->getParser()->getInlineParser();
$parser->addInlinePattern('/@([a-zA-Z0-9_]+)/', function ($match, $groups, $p) {
    $link = new Link('/users/' . $groups[1]);
    $link->appendChild(new Text('@' . $groups[1]));
    return $link;
});

echo $converter->convert('Hello @john!');
// Output: <p>Hello <a href="/users/john">@john</a>!</p>
```

See the [Cookbook](cookbook.md) for more examples including wiki links, hashtags, admonitions, and custom block patterns.

## Security Considerations

**Warning:** When processing untrusted user input, the default output may contain XSS vulnerabilities. The following vectors are not sanitized by default:

- `javascript:` URLs in links and images
- Event handler attributes (e.g., `onclick`)
- Raw HTML blocks and inline HTML

### Recommended: Use Safe Mode

Enable the built-in safe mode for XSS protection:

```php
use Djot\DjotConverter;

// Enable with sensible defaults
$converter = new DjotConverter(safeMode: true);
$html = $converter->convert($userInput);
```

Safe mode automatically:
- Blocks dangerous URL schemes (`javascript:`, `vbscript:`, `data:`, `file:`)
- Strips event handler attributes (`onclick`, `onload`, etc.)
- Escapes raw HTML (or strips it in strict mode)

For stricter protection, use `SafeMode::strict()`:

```php
use Djot\DjotConverter;
use Djot\SafeMode;

$converter = new DjotConverter(safeMode: SafeMode::strict());
```

See the [API Reference](api.md#safe-mode) for full SafeMode configuration options.

### Alternative: Use HTMLPurifier

For maximum control over allowed HTML, you can also use [HTMLPurifier](http://htmlpurifier.org/):

```bash
composer require ezyang/htmlpurifier
```

```php
use Djot\DjotConverter;

function convertUserContent(string $djot): string
{
    $converter = new DjotConverter();
    $html = $converter->convert($djot);

    $config = HTMLPurifier_Config::createDefault();
    $config->set('Cache.DefinitionImpl', null);
    $config->set('HTML.DefinitionID', 'djot-purifier');
    $config->set('HTML.DefinitionRev', 1);
    $config->set('HTML.Allowed', 'p,br,strong,em,u,s,del,ins,mark,sub[id],sup[id],a[href|title|class|id],img[src|alt|title],ul,ol,li,dl,dt,dd,blockquote,pre,code[class],h1[id],h2[id],h3[id],h4[id],h5[id],h6[id],table[class|id],thead,tbody,tr,th[align],td[align],hr,div[class|id],span[class|id],section[id]');
    $config->set('Attr.EnableID', true);
    $config->set('HTML.TargetBlank', true);
    $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

    // Add HTML5 elements not in HTMLPurifier's default set
    $def = $config->maybeGetRawHTMLDefinition();
    if ($def !== null) {
        $def->addElement('mark', 'Inline', 'Inline', 'Common');
        $def->addElement('section', 'Block', 'Flow', 'Common');
    }

    $purifier = new HTMLPurifier($config);

    return $purifier->purify($html);
}

// Safe to use with untrusted input
echo convertUserContent($untrustedUserInput);
```

This ensures:
- Only safe HTML tags and attributes are allowed
- `javascript:` and other dangerous URL schemes are blocked
- Event handlers like `onclick` are stripped
- IDs are preserved for footnotes and heading anchors
- HTML5 elements (`mark`, `section`) are supported
