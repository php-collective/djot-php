# Djot Syntax Highlighting

This folder contains syntax highlighting support for Djot markup.

## highlight.js

The `hljs-djot.js` file provides a language definition for [highlight.js](https://highlightjs.org/).

### Usage

```html
<!-- Load highlight.js -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>

<!-- Load the Djot language definition -->
<script src="path/to/hljs-djot.js"></script>

<!-- Mark your code blocks -->
<pre><code class="language-djot">
# Hello World

This is *strong* and _emphasized_ text.
</code></pre>

<!-- Initialize highlighting -->
<script>
document.querySelectorAll('pre code.language-djot').forEach(el => {
    hljs.highlightElement(el);
});
</script>
```

### Supported Syntax

The language definition supports the full Djot specification plus djot-php enhancements:

#### Block Elements
- **Headings** (`# Title` through `###### Title`)
- **Code fences** (`` ``` `` with optional language)
- **Div blocks** (`:::` with optional class)
- **Blockquotes** (`> text`)
- **Lists** (bullets `-`, `*`, `+` and numbered `1.`, `1)`)
- **Task lists** (`- [ ]`, `- [x]`)
- **Definition lists** (`: term`)
- **Tables** (`| cell | cell |` with separator rows)
- **Line blocks** (`| text` for poetry/addresses)
- **Horizontal rules** (`---`, `***`, `___`)
- **Block attributes** (`{.class #id key=value}`)

#### Inline Elements
- **Strong** (`*bold*`)
- **Emphasis** (`_italic_`)
- **Highlight** (`{=text=}`)
- **Insert** (`{+text+}`)
- **Delete** (`{-text-}`)
- **Superscript** (`^text^`)
- **Subscript** (`~text~`)
- **Inline code** (`` `code` ``)
- **Links** (`[text](url)`)
- **Images** (`![alt](url)`)
- **Reference links** (`[text][ref]`)
- **Autolinks** (`<https://...>`, `<user@example.com>`)
- **Footnotes** (`[^note]` and `[^note]: definition`)
- **Math** (`$`code`$` and `$$`code`$$`)
- **Symbols** (`:name:`)
- **Spans with attributes** (`[text]{.class}`)
- **Raw format markers** (`` `code`{=html} ``)
- **Escape sequences** (`\*`, `\[`, etc.)
- **Hard line breaks** (`\` at end of line)

#### djot-php Extensions
- **Captions** (`^ caption text` for images, tables, blockquotes)
- **Fenced comments** (`%%%` blocks that can contain blank lines)
- **Inline comments** (`{% comment %}`)
- **Table row/cell attributes** (`| cell |{.class}`)

### CSS Classes Used

The highlighter uses standard highlight.js CSS classes:

| Element | CSS Class |
|---------|-----------|
| Headings | `hljs-section` |
| Strong | `hljs-strong` |
| Emphasis | `hljs-emphasis` |
| Highlight/Insert | `hljs-addition` |
| Delete | `hljs-deletion` |
| Code | `hljs-code` |
| Links/Images | `hljs-link` |
| URLs | `hljs-string` |
| Footnotes/References | `hljs-symbol` |
| Lists | `hljs-bullet` |
| Blockquotes | `hljs-quote` |
| Attributes | `hljs-attr` |
| Comments | `hljs-comment` |
| Math | `hljs-formula` |
| Meta (rules, breaks) | `hljs-meta` |
| Titles (captions, terms) | `hljs-title` |
| Keywords (languages) | `hljs-keyword` |
| Sub/Superscript | `hljs-built_in` |
