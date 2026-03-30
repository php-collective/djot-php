# Converters

Converters transform other markup formats into Djot, useful for content migration.

## MarkdownToDjot

Converts Markdown syntax to Djot syntax. This is a source-to-source transformation that handles common Markdown patterns.

```php
use Djot\Converter\MarkdownToDjot;

$converter = new MarkdownToDjot();
$djot = $converter->convert($markdownText);
```

**Conversion Table:**

| Markdown | Djot Output |
|----------|-------------|
| `**bold**` | `*bold*` |
| `__bold__` | `*bold*` |
| `*italic*` | `_italic_` |
| `***bold italic***` | `*_bold italic_*` |
| `~~strikethrough~~` | `{-strikethrough-}` |
| `==highlight==` | `{=highlight=}` |
| `^superscript^` | `{^superscript^}` |
| `~subscript~` | `{~subscript~}` |

**File Operations:**

```php
// Convert file and get result
$djot = $converter->convertFile('/path/to/file.md');

// Convert file and save (replaces .md with .djot)
$converter->convertFileAndSave('/path/to/file.md');

// Convert file and save to specific path
$converter->convertFileAndSave('/path/to/input.md', '/path/to/output.djot');
```

**Behavior:**
- Preserves code blocks and inline code (no conversion inside them)
- Handles nested formatting (bold inside italic, etc.)
- Safe to run on mixed Markdown/Djot content
- Block-level syntax (headings, lists, etc.) passes through unchanged

**Use Cases:**
- Migrating Markdown documentation to Djot
- Converting existing content libraries
- Batch processing Markdown files

## BbcodeToDjot

Converts BBCode markup to Djot. Useful for migrating forum content.

```php
use Djot\Converter\BbcodeToDjot;

$converter = new BbcodeToDjot();
$djot = $converter->convert($bbcodeText);
```

**Conversion Table:**

| BBCode | Djot Output |
|--------|-------------|
| `[b]bold[/b]` | `*bold*` |
| `[i]italic[/i]` | `_italic_` |
| `[u]underline[/u]` | `{+underline+}` |
| `[s]strike[/s]` | `{-strike-}` |
| `[sup]super[/sup]` | `^super^` |
| `[sub]sub[/sub]` | `~sub~` |
| `[url=http://...]text[/url]` | `[text](url)` |
| `[url]http://...[/url]` | `<url>` |
| `[img]url[/img]` | `![](url)` |
| `[code]...[/code]` | ` ```...``` ` |
| `[code=php]...[/code]` | ` ```php...``` ` |
| `[quote]...[/quote]` | `> ...` |
| `[quote=Author]...[/quote]` | `> *Author wrote:*` + quoted |
| `[list][*]...[/list]` | `- ...` |
| `[list=1][*]...[/list]` | `1. ...` |
| `[hr]` | `---` |
| `[spoiler]...[/spoiler]` | `::: spoiler` |
| `[spoiler=Title]...[/spoiler]` | `{title="Title"}\n::: spoiler` |
| `[table]...[/table]` | Djot table syntax |
| `[youtube]ID[/youtube]` | `![YouTube](url)` |

**Stripped Tags:**
- `[size=X]` - no Djot equivalent
- `[color=X]` - no Djot equivalent
- `[font=X]` - no Djot equivalent
- `[center]`, `[left]`, `[right]` - alignment not supported

**Example:**

```php
$bbcode = <<<'BBCODE'
[b]Welcome![/b]

Check out [url=https://example.com]our site[/url].

[quote=Admin]Please read the rules.[/quote]

[list]
[*]Rule 1
[*]Rule 2
[/list]
BBCODE;

$djot = $converter->convert($bbcode);
```

Output:
```djot
*Welcome!*

Check out [our site](https://example.com).

> *Admin wrote:*
> Please read the rules.

- Rule 1
- Rule 2
```

**Use Cases:**
- Migrating forum content to modern platforms
- Converting archived discussions
- Importing user-generated content from legacy systems

## HtmlToDjot

Converts HTML to Djot markup. Useful for importing content from CMS systems, WYSIWYG editors, or web scraping.

```php
use Djot\Converter\HtmlToDjot;

$converter = new HtmlToDjot();
$djot = $converter->convert($html);
```

**Conversion Table:**

| HTML | Djot Output |
|------|-------------|
| `<strong>`, `<b>` | `*bold*` |
| `<em>`, `<i>` | `_italic_` |
| `<u>`, `<ins>` | `{+underline+}` |
| `<s>`, `<del>`, `<strike>` | `{-deleted-}` |
| `<mark>` | `{=highlighted=}` |
| `<sup>` | `^superscript^` |
| `<sub>` | `~subscript~` |
| `<code>` | `` `code` `` |
| `<pre><code>` | ` ```code block``` ` |
| `<a href="...">` | `[text](url)` |
| `<img src="..." alt="...">` | `![alt](src)` |
| `<h1>` - `<h6>` | `#` - `######` |
| `<p>` | Paragraph |
| `<blockquote>` | `> quote` |
| `<ul>`, `<ol>` | `- item` / `1. item` |
| `<hr>` | `---` |
| `<br>` | `\` (hard break) |
| `<table>` | Djot table syntax |
| `<table>` + `<caption>` | Table with `^ caption` |
| `<dl>`, `<dt>`, `<dd>` | Definition list |
| `<span class="x">` | `[text]{.x}` |
| `<figure>` + `<img>` + `<figcaption>` | Image with `^ caption` |
| `<figure>` + `<blockquote>` + `<figcaption>` | Block quote with `^ caption` |

**File Operations:**

```php
// Convert file and get result
$djot = $converter->convertFile('/path/to/file.html');
```

**Example:**

```php
$html = <<<'HTML'
<article>
  <h1>Welcome</h1>
  <p>This is <strong>important</strong> and <em>emphasized</em>.</p>
  <ul>
    <li>First item</li>
    <li>Second item</li>
  </ul>
  <blockquote>A famous quote</blockquote>
  <pre><code class="language-php">echo "Hello";</code></pre>
</article>
HTML;

$djot = $converter->convert($html);
```

Output:
```djot
# Welcome

This is *important* and _emphasized_.

- First item
- Second item

> A famous quote

```php
echo "Hello";
```
```

**Behavior:**
- Strips `<script>`, `<style>`, and `<noscript>` tags
- Normalizes whitespace (multiple spaces/newlines become single space)
- Preserves whitespace inside `<pre>` blocks
- Detects code language from `class="language-xxx"` attribute
- Converts `<span>` with class/id to Djot span syntax
- Handles nested lists
- Handles tables with headers

### Round-Trip Mode

For perfect Djot→HTML→Djot round-trips, enable round-trip mode. This adds data attributes to preserve Djot-specific syntax that would otherwise be lost:

```php
use Djot\DjotConverter;
use Djot\Converter\HtmlToDjot;

// Via constructor
$djotConverter = new DjotConverter(roundTripMode: true);

// Or via renderer
// $djotConverter = new DjotConverter();
// $djotConverter->getHtmlRenderer()->setRoundTripMode(true);

$djot = '***';  // Asterisk thematic break
$html = $djotConverter->convert($djot);
// Output: <hr data-char="*">

$htmlToDjot = new HtmlToDjot();
$back = $htmlToDjot->convert($html);
// Output: *** (preserved!)
```

**What round-trip mode preserves:**

| Element | Data Attribute | Purpose |
|---------|----------------|---------|
| Thematic breaks | `data-char` | Preserves `*` vs `-` character |
| Unordered lists | `data-marker` | Preserves `*`, `+`, or `-` marker |
| Ordered lists | `data-marker` | Preserves `)` vs `.` delimiter |

Without round-trip mode, these elements use defaults when converting back:
- Thematic breaks → `---`
- Unordered lists → `-` marker
- Ordered lists → `.` delimiter

**Use Cases:**
- Importing content from WordPress or other CMS
- Converting WYSIWYG editor output
- Web scraping and content extraction
- Migrating HTML documentation to Djot
