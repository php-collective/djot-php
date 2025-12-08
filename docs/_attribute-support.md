# Attribute Support in djot-php

This document details attribute support for Djot elements in djot-php.

**Status:** Block elements and sub-elements have full attribute support. Some inline elements (emphasis, strong, code spans, etc.) do not yet support trailing attributes.

---

## Attribute Syntax

Djot uses `{...}` for attributes with these formats:
- `.class` - CSS class
- `#id` - Element ID
- `key=value` - Custom attribute
- `key="value with spaces"` - Quoted values
- `key` - Boolean/flag attribute (value-less)

Multiple attributes: `{.class1 .class2 #myid data-foo="bar" hidden}`

---

## Block Elements

### Attribute Placement Rule: **Before the Block**

All block-level elements receive attributes from a standalone `{...}` on the preceding line.

| Element | Syntax | HTML Output |
|---------|--------|-------------|
| Paragraph | `{.intro}`<br>`This is text.` | `<p class="intro">` |
| Heading | `{#section1}`<br>`# Title` | `<h1 id="section1">` |
| Code Block | `{.highlight}`<br>`` ```python `` | `<pre class="highlight"><code>` |
| Blockquote | `{.callout}`<br>`> Quote` | `<blockquote class="callout">` |
| List (ul/ol) | `{.todo-list}`<br>`- Item` | `<ul class="todo-list">` |
| Table | `{.data-table}`<br>`\| A \| B \|` | `<table class="data-table">` |
| Div | `{.warning}`<br>`:::`<br>`Content`<br>`:::` | `<div class="warning">` |
| Thematic Break | `{.separator}`<br>`---` | `<hr class="separator">` |

---

## Inline Elements

### Attribute Placement Rule: **After the Element (Suffix)**

All inline elements receive attributes immediately following the closing delimiter.

| Element | Syntax | HTML Output | Status |
|---------|--------|-------------|--------|
| Link | `[text](url){.external}` | `<a href="url" class="external">` | ✓ |
| Image | `![alt](src){.photo}` | `<img src="src" class="photo">` | ✓ |
| Span | `[text]{.note}` | `<span class="note">` | ✓ |
| Emphasis | `_text_{.highlight}` | `<em class="highlight">` | Not yet |
| Strong | `*text*{.important}` | `<strong class="important">` | Not yet |
| Code Span | `` `code`{.lang-js} `` | `<code class="lang-js">` | Not yet |
| Superscript | `{^text^}{.ref}` | `<sup class="ref">` | Not yet |
| Subscript | `{~text~}{.chemical}` | `<sub class="chemical">` | Not yet |
| Insert | `{+text+}{.added}` | `<ins class="added">` | Not yet |
| Delete | `{-text-}{.removed}` | `<del class="removed">` | Not yet |
| Highlight | `{=text=}{.match}` | `<mark class="match">` | Not yet |
| Symbol | `:emoji:{.large}` | `<span class="symbol large">` | Not yet |

---

## Sub-Element Attributes

These are attributes on child elements within a parent structure.

### List Items

**Placement:** After content on next indented line (consistent with content-first principle)

```djot
- First item
  {.highlight}
- Second item
  {#item2 .important}
- Third item
```

```html
<ul>
<li class="highlight">First item</li>
<li id="item2" class="important">Second item</li>
<li>Third item</li>
</ul>
```

Works with all list types: unordered, ordered, and task lists.

---

### Table Rows and Cells

**Row attributes:** After final pipe
**Cell attributes:** After opening pipe

```djot
| Name | Age |{.header-row}
|------|-----|
|{.name} John |{.age} 30 |{.data-row}
```

```html
<table>
<tr class="header-row">
<th class="name">Name</th>
<th class="age">Age</th>
</tr>
<tr class="data-row">
<td class="name">John</td>
<td class="age">30</td>
</tr>
</table>
```

---

### Definition Lists

Definition lists have three levels of attributes:

| Target | Placement | Example |
|--------|-----------|---------|
| `<dl>` | Before first term | `{.glossary}`<br>`: term` |
| `<dt>` | After term on next line | `: term`<br>`{.keyword}` |
| `<dd>` | After content as last indented line | `  Content`<br>`  {.description}` |

**Example:**

```djot
{.vocabulary}
: color
{.american}
: colour
{.british}

  The visual property of objects.
  {.primary}

  Used in art and design.
  {.secondary}
```

```html
<dl class="vocabulary">
<dt class="american">color</dt>
<dt class="british">colour</dt>
<dd class="primary">
<p>The visual property of objects.</p>
</dd>
<dd class="secondary">
<p>Used in art and design.</p>
</dd>
</dl>
```

---

## Consistency Analysis

### Content-First Principle

For sub-elements (children within containers), we follow a **content-first, attribute-after** pattern:

| Element Type | Pattern | Consistent |
|--------------|---------|------------|
| List items | Content on marker line, attr below | Yes |
| Table rows | Content in cells, attr after final pipe | Yes |
| Table cells | Attr after pipe, then content | *See note* |
| DT (term) | Content on term line, attr below | Yes |
| DD (definition) | Content first, attr as last line | Yes |

**Note on table cells:** Cell attributes come before content (`|{.class} content |`) because:
1. There's no "next line" concept within a cell
2. The opening `|` is the natural anchor point
3. Consistent with other inline-like contexts

### Block-Before, Inline-After

The general rule follows Djot conventions:
- **Block elements:** Attributes on preceding line (before)
- **Inline elements:** Attributes immediately after (suffix)
- **Sub-elements:** Content first, attributes after (where possible)

---

## Attribute Support Matrix

| Node Type | Attr Support | Position | Notes |
|-----------|--------------|----------|-------|
| **Block Elements** ||||
| Document | Yes | Before first block | Root element |
| Paragraph | Yes | Preceding line | Standard block |
| Heading | Yes | Preceding line | All levels H1-H6 |
| CodeBlock | Yes | Preceding line | Fenced code |
| BlockQuote | Yes | Preceding line | Standard block |
| ListBlock | Yes | Preceding line | ul/ol containers |
| ListItem | Yes | Next indented line | After content |
| Table | Yes | Preceding line | Table container |
| TableRow | Yes | After final pipe | Row level |
| TableCell | Yes | After opening pipe | Before content |
| Div | Yes | In fence or preceding | `:::` fences |
| ThematicBreak | Yes | Preceding line | `---` or `***` |
| DefinitionList | Yes | Preceding line | dl container |
| DefinitionTerm | Yes | Next line | dt element |
| DefinitionDescription | Yes | Last indented line | dd element |
| Section | Yes | Inherited from heading | Auto-sections |
| Footnote | Yes | Preceding line | Footnote defs |
| **Inline Elements** ||||
| Link | Yes | Suffix | `[](url){}` |
| Image | Yes | Suffix | `![](url){}` |
| Span | Yes | Suffix | `[text]{}` |
| Emphasis | Not yet | Suffix | `_..._{}` |
| Strong | Not yet | Suffix | `*...*{}` |
| CodeSpan | Not yet | Suffix | `` `...`{} `` |
| Superscript | Not yet | Suffix | `{^...^}{}` |
| Subscript | Not yet | Suffix | `{~...~}{}` |
| Insert | Not yet | Suffix | `{+...+}{}` |
| Delete | Not yet | Suffix | `{-...-}{}` |
| Highlight | Not yet | Suffix | `{=...=}{}` |
| Symbol | Not yet | Suffix | `:name:{}` |
| FootnoteRef | Not yet | Suffix | `[^ref]{}` |
| **Special** ||||
| Comment | No | N/A | Not rendered |
| RawBlock | Yes | Preceding line | Format-specific |
| RawInline | Yes | Suffix | Format-specific |

---

## HTML to Djot Round-Trip

The `HtmlToDjot` converter preserves attributes during conversion:

```php
$converter = new HtmlToDjot();
$djot = $converter->convert('<p class="intro" id="p1">Hello</p>');
// Output: {#p1 .intro}\nHello\n
```

All standard HTML attributes are extracted and converted to Djot syntax:
- `class` → `.classname`
- `id` → `#idname`
- Other attributes → `key="value"`

---

## Testing

All attribute functionality is covered by tests:

```bash
# Run all tests (1050+ tests)
vendor/bin/phpunit

# Specific attribute tests
vendor/bin/phpunit --filter "Attribute"
vendor/bin/phpunit --filter "testDefinitionList"
vendor/bin/phpunit --filter "testTable"
```

---

## References

- [Djot Syntax Spec](https://htmlpreview.github.io/?https://github.com/jgm/djot/blob/master/doc/syntax.html)
- [List Item Attributes PR #262](https://github.com/jgm/djot/pull/262)
- [Table Attributes Issue #250](https://github.com/jgm/djot/issues/250)
- [Definition List Attributes Issue #323](https://github.com/jgm/djot/issues/323)
