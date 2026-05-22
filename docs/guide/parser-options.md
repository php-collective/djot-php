# Parser Options

Configure parser behavior for different use cases.

## Quick Reference

::: code-group

```php [HTML (default)]
use Djot\DjotConverter;

// Simple - all defaults
$converter = new DjotConverter();

// With options
$converter = new DjotConverter(
    xhtml: true,
    significantNewlines: true,
);
```

```php [Other Formats]
use Djot\DjotConverter;

// Named constructors for other formats
$converter = DjotConverter::markdown();
$converter = DjotConverter::plainText();
$converter = DjotConverter::ansi();
```

```php [Advanced]
use Djot\DjotConverter;
use Djot\Parser\BlockParser;
use Djot\Renderer\HtmlRenderer;

// Full control via create()
$converter = DjotConverter::create(
    new BlockParser(significantNewlines: true),
    new HtmlRenderer(xhtml: true),
);
```

:::

## Soft Break Modes

Control how soft breaks (single newlines in source) are rendered. This setting is available on all renderers.

### Available Modes

```php
use Djot\DjotConverter;
use Djot\Renderer\SoftBreakMode;

// Set via constructor
$converter = new DjotConverter(softBreakMode: SoftBreakMode::Space);
$converter = new DjotConverter(softBreakMode: SoftBreakMode::Newline);
$converter = new DjotConverter(softBreakMode: SoftBreakMode::Break);
```

| Mode                             | HTML   | Markdown            | Plain Text |
|----------------------------------|--------|---------------------|------------|
| `Space` (default for Plain/ANSI) | ` `    | ` `                 | ` `        |
| `Newline` (default for HTML)     | `\n`   | `\n`                | `\n`       |
| `Break`                          | `<br>` | `  \n` (two spaces) | `\n`       |

### Example: Poetry or Lyrics

For content where line breaks should be visible (poetry, lyrics, addresses):

```php
use Djot\DjotConverter;
use Djot\Renderer\SoftBreakMode;

$converter = new DjotConverter(softBreakMode: SoftBreakMode::Break);

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

The "significant newlines" mode provides markdown-like behavior where block elements can interrupt paragraphs without blank lines. This is useful when you want markdown-compatible syntax without requiring blank lines before block elements.

### Enabling Significant Newlines Mode

```php
use Djot\DjotConverter;
use Djot\Parser\BlockParser;

// Method 1: Factory method
$converter = DjotConverter::withSignificantNewlines();

// Method 2: Constructor parameter
$converter = new DjotConverter(significantNewlines: true);

// Method 3: With other output formats
$converter = DjotConverter::markdown(
    new BlockParser(significantNewlines: true),
);
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

Note: Soft break rendering is controlled separately via `SoftBreakMode` - see the [Soft Break Modes](#soft-break-modes) section above.

#### Lone marker lines are not blocks

Hard-wrapped prose frequently starts a line with `-`, `*`, `+`, `>` or `|` as an
arithmetic/comparison operator or pipe rather than a list/quote/table marker:

```text
Die Frage ist, wann ist x = 5
* 3 + 17 wahr.
```

To avoid turning these into spurious lists, a **single** marker line followed by
ordinary prose does *not* interrupt a paragraph. A bullet/blockquote/table
marker only interrupts when it forms a *real* block:

- two or more consecutive marker lines (`- a` / `- b`, or `> a` / `> b`), **or**
- a marker line with an indented continuation (`- item` / `  more`), **or**
- it is preceded by a blank line (then any single marker starts a block).

This mirrors the existing rule that only `1.` (not `5.` or `1985.`) interrupts
a paragraph as an ordered list. Headings (`#`) and code/comment/div fences are
unambiguous and still interrupt on a single line.

### Preventing Block Interruption with Escaping

In significant newlines mode, if you want to include literal block markers without triggering block parsing, escape the first character with a backslash:

```php
$converter = DjotConverter::withSignificantNewlines();

// Without escaping - two markers form a list
$result = $converter->convert("Price:
- 10 dollars
- 5 cents");
// Output: <p>Price:</p><ul><li>10 dollars</li><li>5 cents</li></ul>

// With escaping - literal text (first marker neutralized)
$result = $converter->convert("Price:
\\- 10 dollars
- 5 cents");
// Output: <p>Price:<br>- 10 dollars<br>- 5 cents</p>
```

Common escapes:
- `\-`, `\*`, `\+` - Prevent list interpretation
- `\>` - Prevent blockquote interpretation
- `\#` - Prevent heading interpretation
- `\|` - Prevent table interpretation
- `` \` `` - Prevent code fence interpretation

### Use Cases

**Markdown-compatible syntax:**
```php
$converter = DjotConverter::withSignificantNewlines();

$note = "TODO:
- Buy groceries
- Call mom
- Finish report";

echo $converter->convert($note);
```

**Chat/messaging with visible line breaks:**

For chat applications where users expect both markdown-style block elements AND visible line breaks when pressing Enter, combine both options:

```php
use Djot\DjotConverter;
use Djot\Renderer\SoftBreakMode;

$converter = DjotConverter::withSignificantNewlines(
    softBreakMode: SoftBreakMode::Break,
);

$message = "Hey!
Check this out:
- cool feature
- another one";

echo $converter->convert($message);
// Renders with <br> for line breaks AND proper list formatting
```

### Combining with Other Options

```php
use Djot\DjotConverter;
use Djot\SafeMode;
use Djot\Renderer\SoftBreakMode;

// Significant newlines with safe mode for user-generated content
$converter = new DjotConverter(
    safeMode: new SafeMode(),
    significantNewlines: true,
    softBreakMode: SoftBreakMode::Break, // Optional: visible line breaks
);
```

## Nested Blocks in Lists Mode

`nestedBlocksInLists` is a focused subset of [Significant Newlines](#significant-newlines-mode) mode. It lets indentation alone introduce nested blocks - sublists, blockquotes, and fenced code - inside an already-open list item, **without** enabling paragraph interruption anywhere else.

Use it when you want markdown-like nested lists but otherwise spec-compliant djot: top-level paragraphs still require a blank line before any block.

### Enabling Nested Blocks in Lists Mode

```php
use Djot\DjotConverter;
use Djot\Parser\BlockParser;

// Method 1: Factory method
$converter = DjotConverter::withNestedBlocksInLists();

// Method 2: Constructor parameter
$converter = new DjotConverter(nestedBlocksInLists: true);

// Method 3: Directly on the parser
$parser = new BlockParser(nestedBlocksInLists: true);
$parser->setNestedBlocksInLists(true);
```

### Behavior

Indented content nests inside the open list item, even without a blank line:

```php
$converter = DjotConverter::withNestedBlocksInLists();
$result = $converter->convert("- Item
    - Nested one
    - Nested two");
```

Output:
```html
<ul>
<li>
Item
<ul>
<li>
Nested one
</li>
<li>
Nested two
</li>
</ul>
</li>
</ul>
```

But a top-level block still does **not** interrupt a paragraph (this is what differs from significant newlines mode):

```php
$converter = DjotConverter::withNestedBlocksInLists();
$result = $converter->convert("Here is a list:
- one
- two");
```

Output:
```html
<p>Here is a list:
- one
- two</p>
```

### nestedBlocksInLists vs significantNewlines

| Behavior                                              | Default | `nestedBlocksInLists` | `significantNewlines` |
|------------------------------------------------------|---------|-----------------------|-----------------------|
| Nested blocks in list items without a blank line     | No      | **Yes**               | Yes                   |
| Lists/blockquotes/headings interrupt top-level paragraphs | No | No                    | Yes                   |

Note: `significantNewlines` implies `nestedBlocksInLists` - enabling the former turns on the latter automatically.
