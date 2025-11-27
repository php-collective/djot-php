# API Reference

## DjotConverter

The main entry point for converting Djot to HTML.

```php
use Djot\DjotConverter;

$converter = new DjotConverter();
$html = $converter->convert($djotString);
```

### Constructor

```php
public function __construct(
    bool $xhtml = false,
    bool $warnings = false,
    bool $strict = false
)
```

- `$xhtml`: When `true`, produces XHTML-compatible output (self-closing tags like `<br />`).
- `$warnings`: When `true`, collects warnings during parsing (see [Error Handling](#error-handling)).
- `$strict`: When `true`, throws `ParseException` on parse errors (see [Error Handling](#error-handling)).

### Methods

#### convert

```php
public function convert(string $input): string
```

Converts Djot markup to HTML.

#### convertFile

```php
public function convertFile(string $path): string
```

Converts a Djot file to HTML. Throws `RuntimeException` if the file cannot be read.

```php
$html = $converter->convertFile('/path/to/document.djot');
```

#### parse

```php
public function parse(string $input): Document
```

Parses Djot markup into an AST Document without rendering.

#### parseFile

```php
public function parseFile(string $path): Document
```

Parses a Djot file into an AST Document. Throws `RuntimeException` if the file cannot be read.

```php
$document = $converter->parseFile('/path/to/document.djot');
// Manipulate the AST...
$html = $converter->render($document);
```

#### render

```php
public function render(Document $document): string
```

Renders an AST Document to HTML.

#### getParser

```php
public function getParser(): BlockParser
```

Returns the block parser for direct access (useful for custom pattern registration).

#### getRenderer

```php
public function getRenderer(): HtmlRenderer
```

Returns the HTML renderer for direct configuration.

#### on

```php
public function on(string $event, Closure $listener): self
```

Register a listener for render events. See [Event System](#event-system) below.

#### off

```php
public function off(?string $event = null): self
```

Remove listeners. Pass event name to remove specific listeners, or `null` to remove all.

#### getWarnings

```php
public function getWarnings(): array
```

Returns an array of `ParseWarning` objects from the last parse operation. Only populated when `warnings: true` is set.

#### hasWarnings

```php
public function hasWarnings(): bool
```

Returns `true` if there were any warnings during the last parse operation.

#### clearWarnings

```php
public function clearWarnings(): self
```

Clears any collected warnings.

## Error Handling

The parser can optionally report warnings and errors with line/column information.

### Warning Mode

Enable warning collection to detect issues without stopping parsing:

```php
$converter = new DjotConverter(warnings: true);
$html = $converter->convert($djot);

if ($converter->hasWarnings()) {
    foreach ($converter->getWarnings() as $warning) {
        echo $warning->getMessage();  // "Undefined reference 'foo'"
        echo $warning->getLine();     // 5
        echo $warning->getColumn();   // 3

        // Or as string
        echo $warning;  // "Undefined reference 'foo' at line 5, column 3"

        // Or as array
        $data = $warning->toArray();
        // ['message' => '...', 'line' => 5, 'column' => 3]
    }
}
```

**Warnings are reported for:**
- Undefined reference links (`[text][missing]`)
- Undefined footnotes (`[^missing]`)

### Strict Mode

Enable strict mode to throw exceptions on parse errors:

```php
use Djot\Exception\ParseException;

$converter = new DjotConverter(strict: true);

try {
    $html = $converter->convert($djot);
} catch (ParseException $e) {
    echo $e->getMessage();      // "Unclosed code fence at line 3, column 1"
    echo $e->getSourceLine();   // 3
    echo $e->getSourceColumn(); // 1
}
```

**Errors that throw in strict mode:**
- Unclosed code fences
- Unclosed divs (`:::`)
- Unclosed comments (`{% ... %}`)
- Unclosed raw blocks

### Both Modes

You can enable both modes together - warnings will be collected for non-fatal issues, while fatal errors will throw:

```php
$converter = new DjotConverter(warnings: true, strict: true);
```

### ParseWarning

```php
use Djot\Exception\ParseWarning;

$warning->getMessage(): string   // Warning message
$warning->getLine(): int         // 1-indexed line number
$warning->getColumn(): int       // 1-indexed column number
$warning->toArray(): array       // ['message' => ..., 'line' => ..., 'column' => ...]
(string)$warning                 // "Message at line X, column Y"
```

### ParseException

```php
use Djot\Exception\ParseException;

$e->getMessage(): string         // Full message with location
$e->getSourceLine(): int         // 1-indexed line number
$e->getSourceColumn(): int       // 1-indexed column number
```

## Event System

The event system allows you to customize rendering without subclassing.

### Event Names

Events are named `render.{node_type}`:
- `render.link`, `render.image`, `render.heading`, `render.paragraph`, etc.
- `render.*` - wildcard, fires for all nodes

### Modifying Nodes

Modify node attributes before rendering:

```php
$converter->on('render.link', function (RenderEvent $event): void {
    $link = $event->getNode();

    // Add target="_blank" to external links
    if (str_starts_with($link->getDestination(), 'http')) {
        $link->setAttribute('target', '_blank');
        $link->setAttribute('rel', 'noopener');
    }
});
```

### Replacing Output

Replace the rendered HTML entirely:

```php
$converter->on('render.symbol', function (RenderEvent $event): void {
    $symbol = $event->getNode();
    $event->setHtml(match($symbol->getName()) {
        'heart' => '❤️',
        'star' => '⭐',
        default => ':' . $symbol->getName() . ':',
    });
});
```

### Chaining

Methods return `$this` for chaining:

```php
$html = $converter
    ->on('render.link', $linkHandler)
    ->on('render.image', $imageHandler)
    ->convert($djot);
```

### RenderEvent

```php
use Djot\Event\RenderEvent;

$event->getNode(): Node       // Get the node being rendered
$event->setHtml(string $html) // Replace output HTML
$event->getHtml(): ?string    // Get custom HTML if set
$event->preventDefault()      // Skip default rendering
$event->isDefaultPrevented(): bool
```

## Parser Classes

For advanced use cases, you can work directly with the parser and renderer.

### BlockParser

Parses Djot input into an AST (Abstract Syntax Tree).

```php
use Djot\Parser\BlockParser;

$parser = new BlockParser(
    collectWarnings: false,
    strictMode: false
);
$document = $parser->parse($djotString);

// Get warnings (if collectWarnings: true)
// Get exception on errors (if strictMode: true)
$warnings = $parser->getWarnings();
```

#### Custom Block Patterns

Register custom block-level syntax patterns:

```php
$parser = $converter->getParser();

// Register a custom block pattern
$parser->addBlockPattern('/^!!!\\s*(note|warning|danger)\\s*$/', function ($lines, $start, $parent, $p) {
    preg_match('/^!!!\\s*(note|warning|danger)\\s*$/', $lines[$start], $m);
    $type = $m[1];

    // Collect indented content
    $content = [];
    $i = $start + 1;
    while ($i < count($lines) && preg_match('/^\\s+(.*)$/', $lines[$i], $contentMatch)) {
        $content[] = $contentMatch[1];
        $i++;
    }

    $div = new Div();
    $div->setAttribute('class', 'admonition ' . $type);
    $p->parseBlockContent($div, $content);  // Parse nested content
    $parent->appendChild($div);

    return $i - $start;  // Return number of lines consumed
});

// Remove a pattern
$parser->removeBlockPattern('/^!!!\\s*(note|warning|danger)\\s*$/');

// List registered patterns
$patterns = $parser->getBlockPatterns();
```

**Callback signature:**
```php
function(array $lines, int $startIndex, Node $parent, BlockParser $parser): ?int
```

- `$lines`: Array of all input lines
- `$startIndex`: Index of the line that matched the pattern
- `$parent`: Parent node to append children to
- `$parser`: The BlockParser instance (use `parseBlockContent()` for nested parsing)
- Returns: Number of lines consumed, or `null` to fall back to default parsing

#### Custom Inline Patterns

Register custom inline syntax patterns via the InlineParser:

```php
$inlineParser = $converter->getParser()->getInlineParser();

// Register @mention pattern
$inlineParser->addInlinePattern('/@([a-zA-Z0-9_]+)/', function ($match, $groups, $p) {
    $link = new Link('https://example.com/users/' . $groups[1]);
    $link->appendChild(new Text('@' . $groups[1]));
    return $link;
});

// Remove a pattern
$inlineParser->removeInlinePattern('/@([a-zA-Z0-9_]+)/');

// List registered patterns
$patterns = $inlineParser->getInlinePatterns();
```

**Callback signature:**
```php
function(string $match, array $groups, InlineParser $parser): ?Node
```

- `$match`: The full matched string
- `$groups`: Array of regex capture groups (index 0 is full match)
- `$parser`: The InlineParser instance
- Returns: A Node to insert, or `null` to fall back to default parsing

**Important notes:**
- Custom patterns are checked **before** built-in syntax
- Returning `null` allows the default parser to handle the text
- Patterns can override built-in syntax (e.g., override `**bold**`)

### HtmlRenderer

Renders an AST Document to HTML.

```php
use Djot\Renderer\HtmlRenderer;

$renderer = new HtmlRenderer(xhtml: false);
$html = $renderer->render($document);
```

### PlainTextRenderer

Renders an AST Document to plain text (useful for search indexing, SEO, email fallbacks).

```php
use Djot\Renderer\PlainTextRenderer;

$renderer = new PlainTextRenderer();
$text = $renderer->render($document);
```

**Configuration:**

```php
// Customize list item prefix (default: "- ")
$renderer->setListItemPrefix('* ');

// Customize table cell separator (default: "\t")
$renderer->setTableCellSeparator(' | ');
```

**Behavior:**
- Strips all formatting (emphasis, strong, etc.)
- Preserves text content from links and images (alt text)
- Renders lists with configurable prefixes
- Renders tables with configurable separators
- Strips raw HTML and comments
- Preserves footnote references as `[1]` etc.

### MarkdownRenderer

Renders an AST Document to CommonMark-compatible Markdown.

```php
use Djot\Renderer\MarkdownRenderer;

$renderer = new MarkdownRenderer();
$markdown = $renderer->render($document);
```

**Behavior:**
- Converts Djot to CommonMark Markdown
- Uses GFM extensions where available (strikethrough with `~~`)
- Falls back to HTML for features without Markdown equivalents:
  - Superscript: `<sup>text</sup>`
  - Subscript: `<sub>text</sub>`
  - Highlight: `<mark>text</mark>`
  - Insert: `<ins>text</ins>`
- Preserves table alignment
- Preserves footnotes

## AST Node Types

### Block Nodes

All block nodes extend `Djot\Node\Block\BlockNode`:

| Class | Description |
|-------|-------------|
| `Document` | Root document node |
| `Paragraph` | Text paragraph |
| `Heading` | Heading (levels 1-6) |
| `CodeBlock` | Fenced code block |
| `BlockQuote` | Block quote |
| `ListBlock` | Ordered, unordered, or task list |
| `ListItem` | List item |
| `Table` | Table |
| `TableRow` | Table row |
| `TableCell` | Table cell (th or td) |
| `Div` | Generic div container |
| `LineBlock` | Line block (preserves line breaks) |
| `ThematicBreak` | Horizontal rule |
| `DefinitionList` | Definition list |
| `DefinitionTerm` | Definition term |
| `DefinitionDescription` | Definition description |
| `Footnote` | Footnote definition |
| `RawBlock` | Raw HTML block |
| `Comment` | Comment (not rendered) |

### Inline Nodes

All inline nodes extend `Djot\Node\Inline\InlineNode`:

| Class | Description |
|-------|-------------|
| `Text` | Plain text |
| `Emphasis` | Emphasized text |
| `Strong` | Strong text |
| `Code` | Inline code |
| `Link` | Hyperlink |
| `Image` | Image |
| `HardBreak` | Hard line break |
| `SoftBreak` | Soft line break |
| `Span` | Span with attributes |
| `Superscript` | Superscript text |
| `Subscript` | Subscript text |
| `Highlight` | Highlighted text |
| `Insert` | Inserted text |
| `Delete` | Deleted text |
| `FootnoteRef` | Footnote reference |
| `Math` | Math expression |
| `Symbol` | Symbol (e.g., `:heart:`) |
| `RawInline` | Raw HTML inline |

## Working with the AST

```php
use Djot\Parser\BlockParser;
use Djot\Renderer\HtmlRenderer;

$parser = new BlockParser();
$renderer = new HtmlRenderer();

// Parse to AST
$document = $parser->parse('# Hello *world*');

// Manipulate AST
foreach ($document->getChildren() as $node) {
    echo $node->getType() . "\n"; // "heading"
}

// Render to HTML
$html = $renderer->render($document);
// <h1>Hello <strong>world</strong></h1>
```

### Modifying Nodes

```php
// Get/set attributes
$node->setAttribute('class', 'highlight');
$node->getAttribute('class'); // 'highlight'
$node->addClass('special');
```

### Node Methods

```php
// Get node type
$node->getType(): string

// Children
$node->getChildren(): array
$node->appendChild(Node $child): void
$node->prependChild(Node $child): void

// Attributes
$node->getAttribute(string $key): mixed
$node->setAttribute(string $key, mixed $value): void
$node->getAttributes(): array
$node->setAttributes(array $attrs): void
$node->addClass(string $class): void
```
