# Djot Syntax Reference

This document covers all Djot syntax supported by this library with input and output examples.

## Block Elements

### Headings

Headings use `#` characters. The number of `#` determines the level (1-6).

**Input:**
```djot
# Heading 1
## Heading 2
### Heading 3
#### Heading 4
##### Heading 5
###### Heading 6
```

<OutputTabs>
<template #output>

```html
<h1>Heading 1</h1>
<h2>Heading 2</h2>
<h3>Heading 3</h3>
<h4>Heading 4</h4>
<h5>Heading 5</h5>
<h6>Heading 6</h6>
```

</template>
<template #result>
<h1>Heading 1</h1>
<h2>Heading 2</h2>
<h3>Heading 3</h3>
<h4>Heading 4</h4>
<h5>Heading 5</h5>
<h6>Heading 6</h6>
</template>
</OutputTabs>

### Paragraphs

Paragraphs are separated by blank lines. Text within a paragraph wraps normally.

**Input:**
```djot
First paragraph with
multiple lines.

Second paragraph.
```

<OutputTabs>
<template #output>

```html
<p>First paragraph with
multiple lines.</p>
<p>Second paragraph.</p>
```

</template>
<template #result>
<p>First paragraph with
multiple lines.</p>
<p>Second paragraph.</p>
</template>
</OutputTabs>

### Code Blocks

Fenced code blocks use triple backticks with an optional language identifier.

**Input:**
````djot
```php
function hello(): void {
    echo "Hello World";
}
```
````

<OutputTabs>
<template #output>

```html
<pre><code class="language-php">function hello(): void {
    echo "Hello World";
}
</code></pre>
```

</template>
<template #result>
<pre><code class="language-php">function hello(): void {
    echo "Hello World";
}
</code></pre>
</template>
</OutputTabs>

Without a language:

**Input:**
````djot
```
Plain code block
```
````

<OutputTabs>
<template #output>

```html
<pre><code>Plain code block
</code></pre>
```

</template>
<template #result>
<pre><code>Plain code block
</code></pre>
</template>
</OutputTabs>

::: info Closing fence is optional
The closing fence may be omitted. An unterminated code block is implicitly
closed at the end of its enclosing block (a list item, a block quote) or the
end of the document - the same rule the official djot reference uses:

````djot
> ```
> code in a block quote
````

renders the fenced block closed at the end of the quote. A bare `` ``` `` with
nothing after it is therefore a valid (empty) code block, not an inline code
span.
:::

::: info Language specifier must be a single token
After the opening fence, only a single-token language specifier (optionally
surrounded by whitespace) is allowed - "nothing else". A would-be info string
with internal whitespace is **not** a code block opener: the backtick form
falls through to an inline verbatim span, and the tilde form to a plain
paragraph.

| Input | Result |
|-------|--------|
| `` ``` `` | empty code block |
| `` ``` php `` | code block, `language-php` |
| `` ``` not a code block `` | inline `<code>` span (not a code block) |

The enhanced [code group](/extensions/) syntax (`lang [Label]`) is the one
exception, and only applies to fences that have content lines.
:::

### Block Quotes

Block quotes use `>` at the start of each line.

**Input:**
```djot
> This is a quote
> spanning multiple lines.
>
> With multiple paragraphs.
```

<OutputTabs>
<template #output>

```html
<blockquote>
<p>This is a quote
spanning multiple lines.</p>
<p>With multiple paragraphs.</p>
</blockquote>
```

</template>
<template #result>
<blockquote>
<p>This is a quote
spanning multiple lines.</p>
<p>With multiple paragraphs.</p>
</blockquote>
</template>
</OutputTabs>

Nested block quotes:

**Input:**
```djot
> Outer quote
>
> > Nested quote
```

<OutputTabs>
<template #output>

```html
<blockquote>
<p>Outer quote</p>
<blockquote>
<p>Nested quote</p>
</blockquote>
</blockquote>
```

</template>
<template #result>
<blockquote>
<p>Outer quote</p>
<blockquote>
<p>Nested quote</p>
</blockquote>
</blockquote>
</template>
</OutputTabs>

#### Block Quote Captions

Use `^` after a block quote to add an attribution/caption. The block quote will be wrapped in a `<figure>` element with a `<figcaption>`.

**Input:**
```djot
> To be or not to be, that is the question.
^ William Shakespeare
```

<OutputTabs>
<template #output>

```html
<figure>
<blockquote>
<p>To be or not to be, that is the question.</p>
</blockquote>
<figcaption>William Shakespeare</figcaption>
</figure>
```

</template>
<template #result>
<figure>
<blockquote>
<p>To be or not to be, that is the question.</p>
</blockquote>
<figcaption>William Shakespeare</figcaption>
</figure>
</template>
</OutputTabs>

Prefer not to prefix every line? The [`BlockQuoteDivExtension`](/extensions/#blockquotedivextension) adds a fenced form, `::: >`, that produces the same `<blockquote>` without the per-line `>` - useful when the quote contains lists, fences, or tables.

### Lists

#### Bullet Lists

Use `-`, `*`, or `+` for bullet lists.

**Input:**
```djot
- Item 1
- Item 2
- Item 3
```

<OutputTabs>
<template #output>

```html
<ul>
<li>Item 1</li>
<li>Item 2</li>
<li>Item 3</li>
</ul>
```

</template>
<template #result>
<ul>
<li>Item 1</li>
<li>Item 2</li>
<li>Item 3</li>
</ul>
</template>
</OutputTabs>

#### Ordered Lists

Use numbers followed by `.` or `)`.

**Input:**
```djot
1. First
2. Second
3. Third
```

<OutputTabs>
<template #output>

```html
<ol>
<li>First</li>
<li>Second</li>
<li>Third</li>
</ol>
```

</template>
<template #result>
<ol>
<li>First</li>
<li>Second</li>
<li>Third</li>
</ol>
</template>
</OutputTabs>

Lists can start at any number:

**Input:**
```djot
5. Fifth item
6. Sixth item
```

<OutputTabs>
<template #output>

```html
<ol start="5">
<li>Fifth item</li>
<li>Sixth item</li>
</ol>
```

</template>
<template #result>
<ol start="5">
<li>Fifth item</li>
<li>Sixth item</li>
</ol>
</template>
</OutputTabs>

#### Empty List Items

A list marker may be followed by a space *or by the end of the line*, so a bare
marker on its own line is a valid empty item. This works for every marker type
(`-`, `*`, `+`, `1.`, `(1)`, `a.`, `i.`).

**Input:**
```djot
- One
-
- Three
```

<OutputTabs>
<template #output>

```html
<ul>
<li>One</li>
<li></li>
<li>Three</li>
</ul>
```

</template>
<template #result>
<ul>
<li>One</li>
<li></li>
<li>Three</li>
</ul>
</template>
</OutputTabs>

#### Nested Lists

Indent with 2+ spaces for nested lists.

**Input:**
```djot
- Item 1
  - Nested A
  - Nested B
- Item 2
```

<OutputTabs>
<template #output>

```html
<ul>
<li>Item 1
<ul>
<li>Nested A</li>
<li>Nested B</li>
</ul>
</li>
<li>Item 2</li>
</ul>
```

</template>
<template #result>
<ul>
<li>Item 1
<ul>
<li>Nested A</li>
<li>Nested B</li>
</ul>
</li>
<li>Item 2</li>
</ul>
</template>
</OutputTabs>

#### Task Lists

Use `[ ]` or `[_]` for unchecked and `[x]` or `[X]` for checked items.

The underscore notation `[_]` is useful on mobile devices or in editors without monospaced fonts, where the space in `[ ]` can be hard to type or see.

**Input:**
```djot
- [ ] Todo item (space)
- [_] Todo item (underscore)
- [x] Done item
```

<OutputTabs>
<template #output>

```html
<ul class="task-list">
<li><input type="checkbox" disabled> Todo item (space)</li>
<li><input type="checkbox" disabled> Todo item (underscore)</li>
<li><input type="checkbox" disabled checked> Done item</li>
</ul>
```

</template>
<template #result>
<ul class="task-list">
<li><input type="checkbox" disabled> Todo item (space)</li>
<li><input type="checkbox" disabled> Todo item (underscore)</li>
<li><input type="checkbox" disabled checked> Done item</li>
</ul>
</template>
</OutputTabs>

#### List Item Attributes (Extension)

Attributes can be attached to a list item by placing them in curly braces
**immediately after the marker**, with no space before the brace (per the djot
proposal [jgm/djot#262](https://github.com/jgm/djot/pull/262)):

**Input:**
```djot
+{.blue} A blue list item.
+{#id1 .highlight} Item with id and class.
1.{data-value="test"} Numbered item with a data attribute.
```

<OutputTabs>
<template #output>

```html
<ul>
<li class="blue">A blue list item.</li>
<li id="id1" class="highlight">Item with id and class.</li>
</ul>
<ol>
<li data-value="test">Numbered item with a data attribute.</li>
</ol>
```

</template>
<template #result>
<ul>
<li class="blue">A blue list item.</li>
<li id="id1" class="highlight">Item with id and class.</li>
</ul>
<ol>
<li data-value="test">Numbered item with a data attribute.</li>
</ol>
</template>
</OutputTabs>

Works with every marker type (bullet, ordered, parenthesized, roman, alpha, and
task lists). A **space** between the marker and the brace changes the meaning:
`+ {.blue}` makes the `{.blue}` ordinary item content (a block attribute for the
following block inside the item), not an attribute on the `<li>`.

::: tip Soft-deprecated alternative
Attributes can also be added on the following indented line. This older form
still attaches to the `<li>`, but the marker-adjacent form above is now the
preferred syntax.
:::

**Input:**
```djot
- item 1
  {.highlight #id1}
- item 2
  {data-value="test"}
- item 3
```

<OutputTabs>
<template #output>

```html
<ul>
<li class="highlight" id="id1">item 1</li>
<li data-value="test">item 2</li>
<li>item 3</li>
</ul>
```

</template>
<template #result>
<ul>
<li class="highlight" id="id1">item 1</li>
<li data-value="test">item 2</li>
<li>item 3</li>
</ul>
</template>
</OutputTabs>

Works with all list types (unordered, ordered, and task lists). The attribute line must be indented to the content indentation level.

The `{...}` line attaches to the `<li>` **only when it is the last
content line of the item**. If another block follows it within the
same item, the `{...}` behaves as a standard djot block attribute for
that following block, and the list / item are not terminated:

```djot
- item
  {.note}
  > a quoted aside inside the item
```

renders as:

```html
<ul>
<li>
item
<blockquote class="note">
<p>a quoted aside inside the item</p>
</blockquote>
</li>
</ul>
```

#### Tight vs Loose Lists

Lists in Djot can be *tight* or *loose*, which affects how list items are rendered.

**Tight lists** have no blank lines between items. List item content is rendered directly without `<p>` tags:

**Input:**
```djot
- Item one
- Item two
- Item three
```

<OutputTabs>
<template #output>

```html
<ul>
<li>Item one</li>
<li>Item two</li>
<li>Item three</li>
</ul>
```

</template>
<template #result>
<ul>
<li>Item one</li>
<li>Item two</li>
<li>Item three</li>
</ul>
</template>
</OutputTabs>

**Loose lists** have blank lines between items. Each item's content is wrapped in `<p>` tags:

**Input:**
```djot
- Item one

- Item two

- Item three
```

<OutputTabs>
<template #output>

```html
<ul>
<li><p>Item one</p></li>
<li><p>Item two</p></li>
<li><p>Item three</p></li>
</ul>
```

</template>
<template #result>
<ul>
<li><p>Item one</p></li>
<li><p>Item two</p></li>
<li><p>Item three</p></li>
</ul>
</template>
</OutputTabs>

The tight/loose distinction affects visual spacing. Loose lists typically have more vertical space between items due to paragraph margins in CSS.

::: tip Mixed Lists
A list is loose if *any* item is separated by a blank line. One blank line makes the entire list loose.
:::

**Nested lists** require a blank line before the nested content in standard Djot:

```djot
- Parent item

  - Nested item A
  - Nested item B
```

With `nestedListsWithoutBlankLine` mode enabled, nested lists can appear immediately without a blank line:

```djot
- Parent item
  - Nested item A
  - Nested item B
```

See the [Parser Options guide](/guide/parser-options#nested-lists-without-blank-line-mode) for more on `nestedListsWithoutBlankLine` mode.

### Definition Lists

Terms are prefixed with `: ` and definitions are indented below.

::: code-group
```djot [Basic]
: Term 1

  Definition of term 1

: Term 2

  Definition of term 2
```

```djot [Multiple Terms]
: color
: colour

  The visual property of objects.
```

```djot [Multiple Definitions]
: word

  First meaning.

: +

  Second meaning (separate dd).
```
:::

<OutputTabs>
<template #output>

```html
<dl>
<dt>Term 1</dt>
<dd><p>Definition of term 1</p></dd>
<dt>Term 2</dt>
<dd><p>Definition of term 2</p></dd>
</dl>
```

</template>
<template #result>
<dl>
<dt>Term 1</dt>
<dd><p>Definition of term 1</p></dd>
<dt>Term 2</dt>
<dd><p>Definition of term 2</p></dd>
</dl>
</template>
</OutputTabs>

::: tip Enhancements
- **Multiple terms**: Consecutive `: term` lines share a definition
- **Multiple definitions**: Use `: +` continuation for separate `<dd>` elements
- **Attributes**: Add `{.class}` to `<dl>`, `<dt>`, or `<dd>` elements

See [definition list enhancements](/reference/enhancements#multiple-definition-terms) for full details.
:::

#### Definition List Attributes (Extension)

Attributes can be attached to individual definition list elements:

**Input:**
```djot
{.vocabulary}
: color
{.american}
: colour
{.british}

  The visual property of objects.
  {.primary}
```

<OutputTabs>
<template #output>

```html
<dl class="vocabulary">
<dt class="american">color</dt>
<dt class="british">colour</dt>
<dd class="primary">
<p>The visual property of objects.</p>
</dd>
</dl>
```

</template>
<template #result>
<dl class="vocabulary">
<dt class="american">color</dt>
<dt class="british">colour</dt>
<dd class="primary">
<p>The visual property of objects.</p>
</dd>
</dl>
</template>
</OutputTabs>

- `{...}` before first term → applies to `<dl>`
- `{...}` on line after term → applies to that `<dt>`
- `{...}` as last line in definition block → applies to that `<dd>`

### Tables

Tables use `|` to separate columns.

**Input:**
```djot
| Header 1 | Header 2 |
|----------|----------|
| Cell 1   | Cell 2   |
| Cell 3   | Cell 4   |
```

<OutputTabs>
<template #output>

```html
<table>
<thead>
<tr><th>Header 1</th><th>Header 2</th></tr>
</thead>
<tbody>
<tr><td>Cell 1</td><td>Cell 2</td></tr>
<tr><td>Cell 3</td><td>Cell 4</td></tr>
</tbody>
</table>
```

</template>
<template #result>
<table>
<thead>
<tr><th>Header 1</th><th>Header 2</th></tr>
</thead>
<tbody>
<tr><td>Cell 1</td><td>Cell 2</td></tr>
<tr><td>Cell 3</td><td>Cell 4</td></tr>
</tbody>
</table>
</template>
</OutputTabs>

#### Column Alignment

Use `:` in the separator row for alignment.

**Input:**
```djot
| Left | Center | Right |
|:-----|:------:|------:|
| L    | C      | R     |
```

<OutputTabs>
<template #output>

```html
<table>
<thead>
<tr><th style="text-align: left">Left</th><th style="text-align: center">Center</th><th style="text-align: right">Right</th></tr>
</thead>
<tbody>
<tr><td style="text-align: left">L</td><td style="text-align: center">C</td><td style="text-align: right">R</td></tr>
</tbody>
</table>
```

</template>
<template #result>
<table>
<thead>
<tr><th style="text-align: left">Left</th><th style="text-align: center">Center</th><th style="text-align: right">Right</th></tr>
</thead>
<tbody>
<tr><td style="text-align: left">L</td><td style="text-align: center">C</td><td style="text-align: right">R</td></tr>
</tbody>
</table>
</template>
</OutputTabs>

#### Table Captions

Use `^` after the table for a caption.

**Input:**
```djot
| A | B |
|---|---|
| 1 | 2 |
^ This is the caption
```

<OutputTabs>
<template #output>

```html
<table>
<caption>This is the caption</caption>
<thead>
<tr><th>A</th><th>B</th></tr>
</thead>
<tbody>
<tr><td>1</td><td>2</td></tr>
</tbody>
</table>
```

</template>
<template #result>
<table>
<caption>This is the caption</caption>
<thead>
<tr><th>A</th><th>B</th></tr>
</thead>
<tbody>
<tr><td>1</td><td>2</td></tr>
</tbody>
</table>
</template>
</OutputTabs>

#### Table Row and Cell Attributes (Extension)

Attributes can be added to table rows and individual cells:

**Row attributes** (after final pipe):
```djot
| Name | Age |{.header-row}
|------|-----|
| John | 30  |{.highlight}
```

**Cell attributes** (after opening pipe):
```djot
|{.name} Name |{.age} Age |
|-------------|-----------|
|{.emphasis} John | 30 |
```

<OutputTabs>
<template #output>

```html
<table>
<tr class="header-row">
<th class="name">Name</th>
<th class="age">Age</th>
</tr>
<tr class="highlight">
<td class="emphasis">John</td>
<td>30</td>
</tr>
</table>
```

</template>
<template #result>
<table>
<tr class="header-row">
<th class="name">Name</th>
<th class="age">Age</th>
</tr>
<tr class="highlight">
<td class="emphasis">John</td>
<td>30</td>
</tr>
</table>
</template>
</OutputTabs>

#### Table Multi-line Cells, Rowspan, and Colspan (Extension)

**Multi-line cell content** uses `+` prefix for continuation rows:

```djot
| Name | Description      |
|------|------------------|
| Item | Long description |
+      | continued here   |
```

**Rowspan** uses `^` marker (points UP to cell above):

```djot
| Category | Item   |
|----------|--------|
| Fruits   | Apple  |
| ^        | Banana |
| ^        | Orange |
```

<OutputTabs>
<template #output>

```html
<table>
<tr><th>Category</th><th>Item</th></tr>
<tr><td rowspan="3">Fruits</td><td>Apple</td></tr>
<tr><td>Banana</td></tr>
<tr><td>Orange</td></tr>
</table>
```

</template>
<template #result>
<table>
<tr><th>Category</th><th>Item</th></tr>
<tr><td rowspan="3">Fruits</td><td>Apple</td></tr>
<tr><td>Banana</td></tr>
<tr><td>Orange</td></tr>
</table>
</template>
</OutputTabs>

**Colspan** uses `<` marker (points LEFT to cell before):

```djot
| Name  | Contact Info | <     |
|-------|--------------|-------|
| Alice | alice@ex.com | x5234 |
```

<OutputTabs>
<template #output>

```html
<table>
<tr><th>Name</th><th colspan="2">Contact Info</th></tr>
<tr><td>Alice</td><td>alice@ex.com</td><td>x5234</td></tr>
</table>
```

</template>
<template #result>
<table>
<tr><th>Name</th><th colspan="2">Contact Info</th></tr>
<tr><td>Alice</td><td>alice@ex.com</td><td>x5234</td></tr>
</table>
</template>
</OutputTabs>

Use `\^` or `\<` for literal characters. Content like `a < b` is NOT treated as colspan.

### Thematic Breaks

Use `***`, `---`, or `___` (3+ characters) on a line by itself.

**Input:**
```djot
Above

***

Below
```

<OutputTabs>
<template #output>

```html
<p>Above</p>
<hr>
<p>Below</p>
```

</template>
<template #result>
<p>Above</p>
<hr>
<p>Below</p>
</template>
</OutputTabs>

### Divs

Fenced divs use `:::` with an optional class name.

**Input:**
```djot
::: warning
This is a warning message.
:::
```

<OutputTabs>
<template #output>

```html
<div class="warning">
<p>This is a warning message.</p>
</div>
```

</template>
<template #result>
<div class="warning">
<p>This is a warning message.</p>
</div>
</template>
</OutputTabs>

Nested divs:

**Input:**
```djot
::: outer
Outer content

::: inner
Inner content
:::

More outer
:::
```

<OutputTabs>
<template #output>

```html
<div class="outer">
<p>Outer content</p>
<div class="inner">
<p>Inner content</p>
</div>
<p>More outer</p>
</div>
```

</template>
<template #result>
<div class="outer">
<p>Outer content</p>
<div class="inner">
<p>Inner content</p>
</div>
<p>More outer</p>
</div>
</template>
</OutputTabs>

::: info Closing fence is optional
Like code blocks, the closing `:::` may be omitted - an unterminated div is
implicitly closed at the end of its enclosing block or the document.
:::

### Comments

Comments are not rendered in output.

**Input:**
```djot
Visible text

{% This is a comment %}

More visible text

{% Multi-line
   comment here %}
```

<OutputTabs>
<template #output>

```html
<p>Visible text</p>
<p>More visible text</p>
```

</template>
<template #result>
<p>Visible text</p>
<p>More visible text</p>
</template>
</OutputTabs>

### Fenced Comments (Extension)

Standard `{% %}` comments cannot contain blank lines. For longer comments that need blank lines,
use fenced comments with `%%%`:

**Input:**
```djot
Visible text

%%%
This is a fenced comment block.

It can contain blank lines.

Multiple paragraphs of notes, TODOs,
or documentation that won't render.
%%%

More visible text
```

<OutputTabs>
<template #output>

```html
<p>Visible text</p>
<p>More visible text</p>
```

</template>
<template #result>
<p>Visible text</p>
<p>More visible text</p>
</template>
</OutputTabs>

Like code fences, you can use more than three `%` characters. The closing fence must have
at least as many `%` as the opening fence:

**Input:**
```djot
Some text

%%%%
%%% This is not the end
Still inside the comment
%%%%

More text
```

<OutputTabs>
<template #output>

```html
<p>Some text</p>
<p>More text</p>
```

</template>
<template #result>
<p>Some text</p>
<p>More text</p>
</template>
</OutputTabs>

> **Note:** This is a djot-php extension, not part of the official Djot specification.
> See [discussion](https://github.com/jgm/djot/issues/67) for background.

Fenced comment blocks are block-level elements that break paragraph continuity.
Unlike other block elements, fenced comments can interrupt paragraphs without
requiring a preceding blank line - making them truly "invisible" from a formatting
perspective:

**Input:**
```djot
Lorem ipsum
%%%
comment
%%%
dolor sit amet
```

<OutputTabs>
<template #output>

```html
<p>Lorem ipsum</p>
<p>dolor sit amet</p>
```

</template>
<template #result>
<p>Lorem ipsum</p>
<p>dolor sit amet</p>
</template>
</OutputTabs>

This produces two separate paragraphs. For comments that should not interrupt
paragraph flow (keeping text in the same paragraph), use inline comments (`{% ... %}`).

### Line Blocks

Preserve line breaks using `|` at the start of each line. Useful for poetry or addresses.
This is a djot-php addition beyond the core spec; see [Enhancements](/reference/enhancements#line-blocks).

**Input:**
```djot
| Roses are red,
| Violets are blue,
| Sugar is sweet,
| And so are you.
```

<OutputTabs>
<template #output>

```html
<div class="line-block">
<p>Roses are red,<br>
Violets are blue,<br>
Sugar is sweet,<br>
And so are you.</p>
</div>
```

</template>
<template #result>
<div class="line-block">
<p>Roses are red,<br>
Violets are blue,<br>
Sugar is sweet,<br>
And so are you.</p>
</div>
</template>
</OutputTabs>

::: tip Fenced alternative
Prefer not to prefix every line? The [`LineBlockDivExtension`](/extensions/#lineblockdivextension) adds a fenced form, `::: |`, that produces the same `line-block` div without the per-line `|`. Leading indentation and medial alignment gaps are preserved, and a blank line separates stanzas.
:::

### Block Attributes

For an overview of where attributes attach for each block construct
(lists, definition lists, tables, etc.), see
[Attachment Model](/reference/enhancements#attachment-model) in the
reference.

Apply attributes to the following block using `{...}` syntax.

**Input:**
```djot
{.highlight #intro}
# Introduction

{.note data-version="2.0"}
This is a note.
```

<OutputTabs>
<template #output>

```html
<h1 class="highlight" id="intro">Introduction</h1>
<p class="note" data-version="2.0">This is a note.</p>
```

</template>
<template #result>
<h1 class="highlight" id="intro">Introduction</h1>
<p class="note" data-version="2.0">This is a note.</p>
</template>
</OutputTabs>

#### Boolean Attribute Shorthand (Extension)

Boolean/flag attributes can be specified without a value:

**Input:**
```djot
{reversed}
1. Third
2. Second
3. First

[Download](file.zip){download .btn}
```

<OutputTabs>
<template #output>

```html
<ol reversed="">
<li>Third</li>
<li>Second</li>
<li>First</li>
</ol>
<p><a href="file.zip" class="btn" download="">Download</a></p>
```

</template>
<template #result>
<ol reversed="">
<li>Third</li>
<li>Second</li>
<li>First</li>
</ol>
<p><a href="file.zip" class="btn" download="">Download</a></p>
</template>
</OutputTabs>

Common boolean attributes: `{reversed}` (lists), `{open}` (details), `{hidden}`, `{download}` (links).

## Inline Elements

### Emphasis and Strong

Use `_underscores_` for emphasis and `*asterisks*` for strong.

**Input:**
```djot
This is _emphasized_ and *strong* text.

You can _nest *strong* inside_ emphasis.
```

<OutputTabs>
<template #output>

```html
<p>This is <em>emphasized</em> and <strong>strong</strong> text.</p>
<p>You can <em>nest <strong>strong</strong> inside</em> emphasis.</p>
```

</template>
<template #result>
<p>This is <em>emphasized</em> and <strong>strong</strong> text.</p>
<p>You can <em>nest <strong>strong</strong> inside</em> emphasis.</p>
</template>
</OutputTabs>

### Code Spans

Use backticks for inline code.

**Input:**
```djot
Use the `print()` function.

For literal backticks: `` `code` ``
```

<OutputTabs>
<template #output>

```html
<p>Use the <code>print()</code> function.</p>
<p>For literal backticks: <code>`code`</code></p>
```

</template>
<template #result>
<p>Use the <code>print()</code> function.</p>
<p>For literal backticks: <code>`code`</code></p>
</template>
</OutputTabs>

### Links

#### Inline Links

**Input:**
```djot
[Link text](https://example.com)

[Link with title](https://example.com "Title")
```

<OutputTabs>
<template #output>

```html
<p><a href="https://example.com">Link text</a></p>
<p><a href="https://example.com" title="Title">Link with title</a></p>
```

</template>
<template #result>
<p><a href="https://example.com">Link text</a></p>
<p><a href="https://example.com" title="Title">Link with title</a></p>
</template>
</OutputTabs>

#### Reference Links

**Input:**
```djot
[Link text][ref]

[Another link][ref]

[ref]: https://example.com
```

<OutputTabs>
<template #output>

```html
<p><a href="https://example.com">Link text</a></p>
<p><a href="https://example.com">Another link</a></p>
```

</template>
<template #result>
<p><a href="https://example.com">Link text</a></p>
<p><a href="https://example.com">Another link</a></p>
</template>
</OutputTabs>

#### Reference Links with Attributes

Attributes can be added to reference definitions and will be applied to all links using that reference.

**Input:**
```djot
[Click here][example]

{.external title="Example Site"}
[example]: https://example.com
```

<OutputTabs>
<template #output>

```html
<p><a href="https://example.com" class="external" title="Example Site">Click here</a></p>
```

</template>
<template #result>
<p><a href="https://example.com" class="external" title="Example Site">Click here</a></p>
</template>
</OutputTabs>

Link-level attributes can override or extend definition attributes:

**Input:**
```djot
[Click here][example]{.button}

{.external}
[example]: https://example.com
```

<OutputTabs>
<template #output>

```html
<p><a href="https://example.com" class="external button">Click here</a></p>
```

</template>
<template #result>
<p><a href="https://example.com" class="external button">Click here</a></p>
</template>
</OutputTabs>

#### Autolinks

**Input:**
```djot
<https://example.com>

<user@example.com>
```

<OutputTabs>
<template #output>

```html
<p><a href="https://example.com">https://example.com</a></p>
<p><a href="mailto:user@example.com">user@example.com</a></p>
```

</template>
<template #result>
<p><a href="https://example.com">https://example.com</a></p>
<p><a href="mailto:user@example.com">user@example.com</a></p>
</template>
</OutputTabs>

### Images

**Input:**
```djot
![Demo landscape](/demo.svg)

![Demo landscape](/demo.svg "A simple landscape")
```

<OutputTabs>
<template #output>

```html
<p><img src="/demo.svg" alt="Demo landscape"></p>
<p><img src="/demo.svg" alt="Demo landscape" title="A simple landscape"></p>
```

</template>
<template #result>
<p><img src="/demo.svg" alt="Demo landscape"></p>
<p><img src="/demo.svg" alt="Demo landscape" title="A simple landscape"></p>
</template>
</OutputTabs>

#### Image Captions

Use `^` after an image to add a caption. The image will be wrapped in a `<figure>` element with a `<figcaption>`.

**Input:**
```djot
![Demo landscape](/demo.svg)
^ A simple landscape with sun, hills, and sky
```

<OutputTabs>
<template #output>

```html
<figure>
<img src="/demo.svg" alt="Demo landscape"><figcaption>A simple landscape with sun, hills, and sky</figcaption>
</figure>
```

</template>
<template #result>
<figure>
<img src="/demo.svg" alt="Demo landscape"><figcaption>A simple landscape with sun, hills, and sky</figcaption>
</figure>
</template>
</OutputTabs>

### Superscript and Subscript

Use `^` for superscript and `~` for subscript.

**Input:**
```djot
E=mc^2^

H~2~O
```

<OutputTabs>
<template #output>

```html
<p>E=mc<sup>2</sup></p>
<p>H<sub>2</sub>O</p>
```

</template>
<template #result>
<p>E=mc<sup>2</sup></p>
<p>H<sub>2</sub>O</p>
</template>
</OutputTabs>

### Highlight, Insert, Delete

**Input:**
```djot
{=highlighted text=}

{+inserted text+}

{-deleted text-}
```

<OutputTabs>
<template #output>

```html
<p><mark>highlighted text</mark></p>
<p><ins>inserted text</ins></p>
<p><del>deleted text</del></p>
```

</template>
<template #result>
<p><mark>highlighted text</mark></p>
<p><ins>inserted text</ins></p>
<p><del>deleted text</del></p>
</template>
</OutputTabs>

### Spans with Attributes

Apply attributes to inline text.

**Input:**
```djot
[styled text]{.highlight}

[more text]{#unique-id}

[data text]{data-value="42"}
```

<OutputTabs>
<template #output>

```html
<p><span class="highlight">styled text</span></p>
<p><span id="unique-id">more text</span></p>
<p><span data-value="42">data text</span></p>
```

</template>
<template #result>
<p><span class="highlight">styled text</span></p>
<p><span id="unique-id">more text</span></p>
<p><span data-value="42">data text</span></p>
</template>
</OutputTabs>

### Math

#### Inline Math

**Input:**
```djot
The equation $`E = mc^2`$ is famous.
```

<OutputTabs>
<template #output>

```html
<p>The equation <span class="math inline">\(E = mc^2\)</span> is famous.</p>
```

</template>
<template #result>
<p>The equation <span class="math inline">\(E = mc^2\)</span> is famous.</p>
</template>
</OutputTabs>

#### Display Math

**Input:**
```djot
$$`\sum_{i=0}^{n} i = \frac{n(n+1)}{2}`$$
```

<OutputTabs>
<template #output>

```html
<span class="math display">\[\sum_{i=0}^{n} i = \frac{n(n+1)}{2}\]</span>
```

</template>
<template #result>
<span class="math display">\[\sum_{i=0}^{n} i = \frac{n(n+1)}{2}\]</span>
</template>
</OutputTabs>

### Symbols

Use `:name:` for symbols.

**Input:**
```djot
I :heart: Djot
```

<OutputTabs>
<template #output>

```html
<p>I <span class="symbol">heart</span> Djot</p>
```

</template>
<template #result>
<p>I <span class="symbol">heart</span> Djot</p>
</template>
</OutputTabs>

Note: Symbol rendering can be customized via events. See the [Cookbook](/cookbook/) for examples.

### Footnotes

**Input:**
```djot
Here is a statement[^1] with a footnote.

Another reference[^note].

[^1]: This is the first footnote.

[^note]: This is a named footnote.
```

<OutputTabs>
<template #output>

```html
<p>Here is a statement<sup id="fnref-1-1"><a href="#fn-1">1</a></sup> with a footnote.</p>
<p>Another reference<sup id="fnref-note-1"><a href="#fn-note">note</a></sup>.</p>
<div class="footnote" id="fn-1">
<p><sup>1</sup> This is the first footnote. <a href="#fnref-1-1">↩</a></p>
</div>
<div class="footnote" id="fn-note">
<p><sup>note</sup> This is a named footnote. <a href="#fnref-note-1">↩</a></p>
</div>
```

</template>
<template #result>
<p>Here is a statement<sup id="fnref-1-1"><a href="#fn-1">1</a></sup> with a footnote.</p>
<p>Another reference<sup id="fnref-note-1"><a href="#fn-note">note</a></sup>.</p>
<div class="footnote" id="fn-1">
<p><sup>1</sup> This is the first footnote. <a href="#fnref-1-1">↩</a></p>
</div>
<div class="footnote" id="fn-note">
<p><sup>note</sup> This is a named footnote. <a href="#fnref-note-1">↩</a></p>
</div>
</template>
</OutputTabs>

### Raw HTML

#### Inline Raw HTML

**Input:**
```djot
Text `<span class="special">raw html</span>`{=html} more text
```

<OutputTabs>
<template #output>

```html
<p>Text <span class="special">raw html</span> more text</p>
```

</template>
<template #result>
<p>Text <span class="special">raw html</span> more text</p>
</template>
</OutputTabs>

#### Block Raw HTML

**Input:**
````djot
``` =html
<div class="custom">
  <p>Raw HTML block</p>
</div>
```
````

<OutputTabs>
<template #output>

```html
<div class="custom">
  <p>Raw HTML block</p>
</div>
```

</template>
<template #result>
<div class="custom">
  <p>Raw HTML block</p>
</div>
</template>
</OutputTabs>

## Smart Typography

The parser automatically converts certain character sequences.

### Quotes

**Input:**
```djot
"Double quotes" and 'single quotes'

"Nested 'quotes' work" too
```

<OutputTabs>
<template #output>

```html
<p>"Double quotes" and 'single quotes'</p>
<p>"Nested 'quotes' work" too</p>
```

</template>
<template #result>
<p>"Double quotes" and 'single quotes'</p>
<p>"Nested 'quotes' work" too</p>
</template>
</OutputTabs>

### Dashes

**Input:**
```djot
En-dash: 1--10

Em-dash: wait---what?
```

<OutputTabs>
<template #output>

```html
<p>En-dash: 1–10</p>
<p>Em-dash: wait—what?</p>
```

</template>
<template #result>
<p>En-dash: 1–10</p>
<p>Em-dash: wait—what?</p>
</template>
</OutputTabs>

### Ellipsis

**Input:**
```djot
Wait for it...
```

<OutputTabs>
<template #output>

```html
<p>Wait for it…</p>
```

</template>
<template #result>
<p>Wait for it…</p>
</template>
</OutputTabs>

## Hard Line Breaks

End a line with a backslash for a hard break.

**Input:**
```djot
Line one\
Line two\
Line three
```

<OutputTabs>
<template #output>

```html
<p>Line one<br>
Line two<br>
Line three</p>
```

</template>
<template #result>
<p>Line one<br>
Line two<br>
Line three</p>
</template>
</OutputTabs>

## Abbreviations (Extension)

Abbreviations allow you to define terms that will automatically be wrapped in `<abbr>` tags with their definitions. This is an extension feature inspired by PHP Markdown Extra.

**Input:**
```djot
The HTML specification is maintained by the W3C.

*[HTML]: Hyper Text Markup Language
*[W3C]: World Wide Web Consortium
```

<OutputTabs>
<template #output>

```html
<p>The <abbr title="Hyper Text Markup Language">HTML</abbr> specification is maintained by the <abbr title="World Wide Web Consortium">W3C</abbr>.</p>
```

</template>
<template #result>
<p>The <abbr title="Hyper Text Markup Language">HTML</abbr> specification is maintained by the <abbr title="World Wide Web Consortium">W3C</abbr>.</p>
</template>
</OutputTabs>

Abbreviation definitions can appear anywhere in the document and will be applied to all matching text. Matching is:
- Case-sensitive (HTML ≠ html)
- Word-boundary aware (HTML won't match HTMLElement)

Definitions can span multiple lines if continuation lines are indented:

```djot
*[HTML]: Hyper Text Markup Language,
  the standard markup language for documents
  designed to be displayed in a web browser
```

### Inline Abbreviations

For one-off abbreviations or to override a definition, use the inline span syntax:

**Input:**
```djot
The [HTML]{abbr="Hyper Text Markup Language"} specification.
```

<OutputTabs>
<template #output>

```html
<p>The <abbr title="Hyper Text Markup Language">HTML</abbr> specification.</p>
```

</template>
<template #result>
<p>The <abbr title="Hyper Text Markup Language">HTML</abbr> specification.</p>
</template>
</OutputTabs>

The definition-based approach (`*[ABBR]: ...`) automatically applies to all matching text, while the inline `[ABBR]{abbr="..."}` approach allows marking specific occurrences.

## Escaping

Use backslash to escape special characters.

**Input:**
```djot
\*not strong\*

\# not a heading

\[not a link\]
```

<OutputTabs>
<template #output>

```html
<p>*not strong*</p>
<p># not a heading</p>
<p>[not a link]</p>
```

</template>
<template #result>
<p>*not strong*</p>
<p># not a heading</p>
<p>[not a link]</p>
</template>
</OutputTabs>

### Non-Breaking Space

An escaped space (`\ `) produces a non-breaking space.

**Input:**
```djot
100\ km

Dr.\ Smith
```

<OutputTabs>
<template #output>

```html
<p>100&nbsp;km</p>
<p>Dr.&nbsp;Smith</p>
```

</template>
<template #result>
<p>100&nbsp;km</p>
<p>Dr.&nbsp;Smith</p>
</template>
</OutputTabs>

This is useful for keeping values and units together, titles and names, or any text that shouldn't be split across lines.
