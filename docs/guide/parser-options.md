# Parser Options

Configure parser behavior for different use cases.

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
