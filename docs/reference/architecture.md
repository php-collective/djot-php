# Architecture

This document describes the internal architecture of the Djot PHP parser.

## Overview

The library follows a classic parser/renderer architecture:

```
Input (Djot String)
        ↓
   BlockParser
        ↓
     AST (Document)
        ↓
   HtmlRenderer
        ↓
Output (HTML String)
```

## Components

### BlockParser

The `BlockParser` is responsible for:

1. Splitting input into lines
2. Extracting reference definitions (first pass)
3. Extracting footnote definitions (first pass)
4. Parsing block-level elements (second pass)
5. Delegating inline parsing to `InlineParser`

Key methods:
- `parse(string $input): Document` - Main entry point
- `tryParse*()` methods - Each returns consumed lines or null

### InlineParser

The `InlineParser` handles inline elements within block content:

- Emphasis, strong, code spans
- Links, images, autolinks
- Smart typography
- Special syntax (math, symbols, footnote refs)

It uses a delimiter stack approach for paired delimiters like `_emphasis_`.

### HtmlRenderer

The `HtmlRenderer` walks the AST and produces HTML:

- Uses PHP 8 `match` expression for node type dispatch
- Supports XHTML mode for self-closing tags
- Handles attribute rendering

## AST Structure

### Node Hierarchy

```
Node (abstract)
├── Block\BlockNode (abstract)
│   ├── Document
│   ├── Paragraph
│   ├── Heading
│   ├── CodeBlock
│   ├── BlockQuote
│   ├── ListBlock
│   ├── ListItem
│   ├── Table
│   ├── TableRow
│   ├── TableCell
│   ├── Div
│   ├── ThematicBreak
│   ├── DefinitionList
│   ├── DefinitionTerm
│   ├── DefinitionDescription
│   ├── Footnote
│   ├── RawBlock
│   └── Comment
│
└── Inline\InlineNode (abstract)
    ├── Text
    ├── Emphasis
    ├── Strong
    ├── Code
    ├── Link
    ├── Image
    ├── HardBreak
    ├── SoftBreak
    ├── Span
    ├── Superscript
    ├── Subscript
    ├── Highlight
    ├── Insert
    ├── Delete
    ├── FootnoteRef
    ├── Math
    ├── Symbol
    └── RawInline
```

### Node Properties

Each node can have:
- **Children**: Other nodes contained within
- **Attributes**: Key-value pairs (id, class, custom)
- **Type-specific data**: e.g., heading level, link URL

## Parsing Strategy

### Two-Pass Block Parsing

1. **First pass**: Extract reference definitions and footnotes
   - These can appear anywhere but are needed during inline parsing

2. **Second pass**: Parse blocks in order
   - Each block type has a `tryParse*()` method
   - Methods return consumed line count or null
   - First matching parser wins

### Block Precedence

Blocks are tried in this order:
1. Comments (`{% %}`)
2. Raw blocks (``` =html)
3. Code blocks (```)
4. Divs (:::)
5. Headings (#)
6. Thematic breaks (***)
7. Block quotes (>)
8. Definition lists
9. Lists (-, *, 1.)
10. Tables (|)
11. Footnote definitions
12. Reference definitions
13. Paragraphs (fallback)

### Block Attributes

Block attributes `{.class #id key=value}` are parsed separately and stored in `pendingAttributes`. They're applied to the next block element.

## Inline Parsing

The inline parser processes text character by character:

1. Handle escapes (`\*`)
2. Check for special syntax starts
3. Match delimiters for paired elements
4. Convert smart typography
5. Collect remaining text

### Smart Typography

Automatically converts:
- Straight quotes to curly quotes
- `--` to en-dash
- `---` to em-dash
- `...` to ellipsis

## Extension Points

### Event System (Recommended)

The preferred way to customize rendering is through the event system. This allows you to modify how nodes are rendered without subclassing:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;

$converter = new DjotConverter();

// Customize link rendering
$converter->on('render.link', function (RenderEvent $event): void {
    $link = $event->getNode();
    $href = $link->getDestination();

    // Add attributes
    if (str_starts_with($href, 'http')) {
        $link->setAttribute('target', '_blank');
    }

    // Or completely override the HTML output
    // $event->setHtml('<custom-link>...</custom-link>');
});
```

Events are fired for each node type using the pattern `render.{node_type}`:
- `render.paragraph`, `render.heading`, `render.code_block`, etc.
- `render.link`, `render.image`, `render.emphasis`, `render.symbol`, etc.

See the [Cookbook](/cookbook/) for common customization recipes.

For the longer-term direction on extension preprocessing and avoiding AST
mutation during `render()`, see
[Non-Mutating `beforeRender()` Plan](./non-mutating-before-render-plan.md).

### Adding New Syntax

For entirely new syntax elements, extend the parser:

1. Create a new Node class extending `BlockNode` or `InlineNode`
2. Add parsing logic in `BlockParser` or `InlineParser`
3. Add rendering in `HtmlRenderer`

Example for a new block type:
```php
// 1. Create node
class Alert extends BlockNode {
    public function getType(): string { return 'alert'; }
}

// 2. Add parser method
protected function tryParseAlert(Node $parent, array $lines, int $start): ?int {
    // Parse and return consumed lines
}

// 3. Add to parse order in parseBlocks()

// 4. Add renderer
$node instanceof Alert => $this->renderAlert($node),
```
