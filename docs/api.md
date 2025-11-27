# API Reference

## DjotConverter

The main entry point for converting Djot to HTML.

```php
use Djot\DjotConverter;

$converter = new DjotConverter(xhtml: false);
$html = $converter->convert($djotString);
```

### Constructor

```php
public function __construct(bool $xhtml = false)
```

- `$xhtml`: When `true`, produces XHTML-compatible output (self-closing tags like `<br />`).

### Methods

#### convert

```php
public function convert(string $input): string
```

Converts Djot markup to HTML.

#### on

```php
public function on(string $event, callable $listener): self
```

Register a listener for render events. See [Event System](#event-system) below.

#### off

```php
public function off(?string $event = null): self
```

Remove listeners. Pass event name to remove specific listeners, or `null` to remove all.

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

$parser = new BlockParser();
$document = $parser->parse($djotString);
```

### HtmlRenderer

Renders an AST Document to HTML.

```php
use Djot\Renderer\HtmlRenderer;

$renderer = new HtmlRenderer(xhtml: false);
$html = $renderer->render($document);
```

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
