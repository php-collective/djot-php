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
