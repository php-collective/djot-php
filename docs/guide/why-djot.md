# Why Djot? Upgrading from Markdown

Djot was created by John MacFarlane (creator of Pandoc and CommonMark) to address Markdown's accumulated quirks and limitations. This document explains why you might want to upgrade.

## The Problem with Markdown

Markdown was designed in 2004 as a simple format for writing blog posts. Twenty years later, it's used everywhere - but with significant problems:

1. **No single specification** - CommonMark, GFM, MultiMarkdown, PHP Markdown Extra, and dozens more
2. **Ambiguous syntax** - `_` and `*` behave differently based on context
3. **Limited features** - no highlighting, attributes, or consistent extensions
4. **HTML dependency** - raw HTML is the escape hatch for everything
5. **Complex parsing** - requires backtracking and lookahead

## Parser Design: No Backtracking

One of Djot's most significant technical advantages is its parser design.

### The Markdown Parsing Problem

Markdown requires **backtracking** - the parser must sometimes go back and reinterpret what it already parsed. Consider:

```markdown
*foo *bar* baz*
```

A Markdown parser sees `*foo ` and thinks "this might be emphasis." It continues, finds `*bar*` (definitely emphasis), then hits `baz*`. Now it must decide: is the outer `*...*` emphasis too?

The parser has to backtrack and try different interpretations. This leads to:

- **Unpredictable results** - different parsers make different choices
- **Performance costs** - worst-case exponential time complexity
- **Edge case bugs** - complex nesting creates surprising output

### Djot's Solution

Djot was designed from the ground up to parse **without backtracking**:

- `*` always means strong, `_` always means emphasis
- Delimiters must be "matched" - `*foo*` works, `*foo _bar* baz_` doesn't cross
- The parser never needs to reconsider previous decisions

**Benefits:**

| Aspect | Markdown | Djot |
|--------|----------|------|
| Parse complexity | Can require backtracking | Linear, single-pass |
| Predictability | Context-dependent | Always consistent |
| Edge cases | Many surprising results | Minimal surprises |
| Error recovery | Varies by parser | Predictable fallback |

### Real-World Example

```markdown
_(_foo_)_
```

Different Markdown parsers produce different output for this. Some treat it as emphasis around `(_foo_)`, others as `_(_foo` followed by `)_`.

In Djot, the rules are clear: underscores must balance, and the result is always predictable.

## Side-by-Side Comparison

### Emphasis and Strong

| Feature | Markdown | Djot |
|---------|----------|------|
| Emphasis | `*text*` or `_text_` | `_text_` |
| Strong | `**text**` or `__text__` | `*text*` |

Djot uses single characters consistently: `_` for emphasis, `*` for strong. No ambiguity.

### Text Formatting

| Feature | Markdown | Djot |
|---------|----------|------|
| Highlight | Not supported | `{=highlighted=}` |
| Insert | Not standard | `{+inserted+}` |
| Delete | `~~text~~` (GFM only) | `{-deleted-}` |
| Superscript | Not standard | `{^superscript^}` |
| Subscript | Not standard | `{~subscript~}` |

### Attributes

Markdown has no standard way to add classes, IDs, or attributes.

**Markdown (with non-standard extensions):**
```markdown
# Heading {#my-id .my-class}
<!-- Depends on parser, often not supported -->
```

**Djot:**
```djot
# Heading {#my-id .my-class}

A paragraph with [styled text]{.highlight} inline.

![Image](photo.jpg){width=300}
```

Djot attributes work on any element - headings, paragraphs, spans, images, code blocks, and more.

### Code Blocks

**Markdown:**
````markdown
```javascript
const x = 1;
```
````

**Djot:**
````djot
``` javascript
const x = 1;
```
{#example-code .highlighted linenos=true}
````

Djot allows attributes on code blocks for syntax highlighting configuration, line numbers, etc.

### Links and Images

| Feature | Markdown | Djot |
|---------|----------|------|
| Basic link | `[text](url)` | `[text](url)` |
| Basic image | `![alt](url)` | `![alt](url)` |
| Link with title | `[text](url "title")` | `[text](url "title")` |
| Link with attributes | Not supported | `[text](url){target=_blank}` |
| Image with size | Not standard | `![alt](url){width=300}` |

### Footnotes

**Markdown (extension, varies by parser):**
```markdown
Here's a footnote[^1].

[^1]: The footnote content.
```

**Djot (built-in, consistent):**
```djot
Here's a footnote[^1].

[^1]: The footnote content.
```

Same syntax, but Djot guarantees it works everywhere.

### Math

**Markdown:** Depends entirely on the parser. Some use `$...$`, others `\(...\)`, many don't support it.

**Djot:**
```djot
Inline math: $E = mc^2$

Display math:
$$
\int_0^\infty e^{-x^2} dx = \frac{\sqrt{\pi}}{2}
$$
```

### Raw Content

**Markdown:** Only raw HTML is supported.

**Djot:**
```djot
`<b>raw html</b>`{=html}

`\textbf{raw latex}`{=latex}

``` =html
<div class="custom">
  Raw HTML block
</div>
```
```

Output to any format, not just HTML.

### Divs and Spans

**Markdown:** Not supported. Must use raw HTML.

**Djot:**
```djot
::: warning
This is a warning div.
:::

This has a [styled span]{.highlight} inline.
```

### Symbols

**Markdown:** Not supported.

**Djot:**
```djot
I :heart: Djot! The weather is :sunny: today.
```

Symbols can be rendered as emoji, HTML entities, or custom output.

### Definition Lists

**Markdown:** Non-standard extension with varying syntax.

**Djot:**
```djot
: Term
  Definition of the term.

: Another term
  Another definition.
```

### Tables

Both support tables, but Djot tables are more consistent:

**Djot:**
```djot
| Header 1 | Header 2 |
|----------|----------|
| Cell 1   | Cell 2   |
{.striped .bordered}
```

Attributes on tables!

## Migration Path

This library provides tools for gradual migration:

### 1. Convert Existing Content

```php
use Djot\Converter\MarkdownToDjot;

$converter = new MarkdownToDjot();
$djot = $converter->convert($markdownContent);
```

### 2. Output to Markdown if Needed

```php
use Djot\DjotConverter;
use Djot\Renderer\MarkdownRenderer;

$converter = new DjotConverter();
$doc = $converter->parse($djotContent);

$renderer = new MarkdownRenderer();
$markdown = $renderer->render($doc);
```

### 3. Accept Both Formats

```php
// Detect and convert Markdown on input
if (containsMarkdownPatterns($input)) {
    $input = (new MarkdownToDjot())->convert($input);
}

$html = (new DjotConverter())->convert($input);
```

## When to Stick with Markdown

Markdown may still be the right choice when:

- Your content is very simple (just paragraphs and basic formatting)
- You're locked into a Markdown-only ecosystem (GitHub issues, etc.)
- Your team is familiar with Markdown and change is costly
- You don't need any of Djot's additional features

## When to Choose Djot

Djot is the better choice when:

- You need consistent, predictable parsing
- You want attributes on elements (classes, IDs, custom data)
- You need features like highlighting, super/subscript, or symbols
- You're building a new system without legacy constraints
- You want a single specification, not "which Markdown flavor?"
- You need to output to formats other than HTML

## Learn More

- [Official Djot Syntax Reference](https://djot.net/)
- [Djot Playground](https://djot.net/playground/)
- [Syntax Guide](syntax.md) - This library's syntax documentation
- [Converters](converters.md) - Migration tools
