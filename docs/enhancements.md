# Enhancements Beyond Spec

This document tracks djot-php enhancements that go beyond the current [djot specification](https://htmlpreview.github.io/?https://github.com/jgm/djot/blob/master/doc/syntax.html) but align with its direction.

These are fixes or improvements for edge cases not explicitly covered by the spec.
They are either on the way to get incorporated upstream - or may be incorporated into future spec versions.

## Table of Contents

- [Tab Indentation Support](#tab-indentation-support)
- [Multiple Footnote References](#multiple-footnote-references)
- [Section ID Excludes Footnote Markers](#section-id-excludes-footnote-markers)
- [Symbol Parsing in Time Formats](#symbol-parsing-in-time-formats)
- [Em/En Dash with Unmatched Braces](#emen-dash-with-unmatched-braces)
- [Optional Modes](#optional-modes)
  - [Significant Newlines Mode](#significant-newlines-mode)
- [Language Features Beyond Spec](#language-features-beyond-spec)
  - [List Item Attributes](#list-item-attributes)
  - [Table Row and Cell Attributes](#table-row-and-cell-attributes)
  - [Boolean Attribute Shorthand](#boolean-attribute-shorthand)
  - [Fenced Comment Blocks](#fenced-comment-blocks)
  - [Multiple Definition Terms](#multiple-definition-terms)
  - [Multiple Definition Definitions](#multiple-definition-definitions---continuation)
  - [Definition List Element Attributes](#definition-list-element-attributes)
  - [Table Multi-line Cells, Rowspan, and Colspan](#table-multi-line-cells-rowspan-and-colspan)
  - [Captions for Images, Tables, and Block Quotes](#captions-for-images-tables-and-block-quotes)
- [Abbreviations (PHP Markdown Extra Style)](#abbreviations-php-markdown-extra-style)
- [Testing](#testing)
- [Upstream Tracking](#upstream-tracking)
- [Reporting Issues](#reporting-issues)

---

## Tab Indentation Support

**Related:** [jgm/djot#255](https://github.com/jgm/djot/issues/255)

**Status:** Implemented in djot-php

The djot spec doesn't explicitly define tab handling. We implemented consistent tab support:

### Indentation (Leading Whitespace)

Tabs at the start of lines count as 2 spaces (one indentation level):

```djot
- Level 1

	- Level 2 (tab-indented)

		- Level 3 (two tabs)
```

This applies to:
- Nested lists
- List item continuation
- Footnote continuation
- Definition list content

### Syntax Delimiters (Space After Markers)

The space after block markers (`#`, `-`, `>`, `:`, etc.) must be a space, not a tab:

```djot
# Heading       ✓ (space after #)
#	Heading      ✗ (tab after # - not a heading)

- List item     ✓ (space after -)
-	Item         ✗ (tab after - - not a list)

> Quote         ✓ (space after >)
>	Quote        ✗ (tab after > - not a blockquote)
```

**Rationale:** The space after markers is a syntax delimiter (alignment), not indentation. Tabs are only meaningful for nesting depth at line start.

---

## Multiple Footnote References

**Related:** [jgm/djot#348](https://github.com/jgm/djot/issues/348)

**Status:** Implemented in djot-php

When the same footnote is referenced multiple times, each reference gets a unique ID with multiple backlinks:

```djot
First reference[^note] and second reference[^note] and third[^note].

[^note]: This footnote is referenced three times.
```

**Output:**
```html
<p>First reference<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a>
and second reference<a id="fnref1-2" href="#fn1" role="doc-noteref"><sup>1</sup></a>
and third<a id="fnref1-3" href="#fn1" role="doc-noteref"><sup>1</sup></a>.</p>

<section role="doc-endnotes">
<ol>
<li id="fn1">
<p>This footnote is referenced three times.
<a href="#fnref1" role="doc-backlink">↩︎</a>
<a href="#fnref1-2" role="doc-backlink">↩︎</a>
<a href="#fnref1-3" role="doc-backlink">↩︎</a></p>
</li>
</ol>
</section>
```

**Features:**
- Unique IDs: `fnref1`, `fnref1-2`, `fnref1-3`
- Multiple backlinks in footnote content
- Proper ARIA roles for accessibility

---

## Section ID Excludes Footnote Markers

**Related:** [jgm/djot#349](https://github.com/jgm/djot/issues/349)

**Status:** Implemented in djot-php

Auto-generated section IDs correctly exclude footnote reference markers:

```djot
# Introduction[^1]

[^1]: A footnote in the heading.
```

**Output:**
```html
<section id="Introduction">
<h1>Introduction<a href="#fn1"><sup>1</sup></a></h1>
</section>
```

The ID is `Introduction`, not `Introduction1` or `Introduction[^1]`.

---

## Symbol Parsing in Time Formats

**Related:** [jgm/djot#350](https://github.com/jgm/djot/issues/350)

**Status:** Implemented in djot-php

Colons in time formats are not parsed as symbol delimiters:

```djot
The meeting is at 10:30:00.
```

**Output:**
```html
<p>The meeting is at 10:30:00.</p>
```

Not incorrectly parsed as symbols like `:30:`.

---

## Em/En Dash with Unmatched Braces

**Related:** [jgm/djot#125](https://github.com/jgm/djot/issues/125)

**Status:** Implemented in djot-php

Unmatched `{-` does not prevent em/en-dash conversion:

```djot
{--- produces em-dash
{-- produces en-dash
```

**Output:**
```html
<p>{— produces em-dash
{– produces en-dash</p>
```

---

## Optional Modes

These are optional parser modes that deviate from spec behavior for specific use cases.

### Significant Newlines Mode

**Related:** [jgm/djot#161](https://github.com/jgm/djot/issues/161)

**Status:** Implemented in djot-php (opt-in)

An optional mode for chat messages, comments, and quick notes where markdown-like behavior is more intuitive.

**Enable via:**
```php
// Factory method
$converter = DjotConverter::withSignificantNewlines();

// Constructor parameter
$converter = new DjotConverter(significantNewlines: true);

// Parser directly
$parser = new BlockParser(significantNewlines: true);
```

**Changes from spec:**

| Behavior | Standard Mode | Significant Newlines Mode |
|----------|---------------|---------------------------|
| Block elements interrupt paragraphs | No (blank line required) | Yes |
| Nested lists need blank lines | Yes | No |
| Soft breaks render as | `\n` or space | `<br>` |

**Example:**
```djot
Here is a list:
- item one
- item two
```

**Standard mode output:**
```html
<p>Here is a list:
- item one
- item two</p>
```

**Significant newlines mode output:**
```html
<p>Here is a list:</p>
<ul>
<li>item one</li>
<li>item two</li>
</ul>
```

**Escaping:** In this mode, escape block markers to keep them literal:
```djot
They said:
\> This is not a blockquote
```

---

## Language Features Beyond Spec

These are djot syntax features we've implemented that aren't yet in the upstream spec.

### List Item Attributes

**Related:** [jgm/djot#262](https://github.com/jgm/djot/pull/262)

**Status:** Implemented in djot-php (PR #5)

Attributes can be added to list items on the following indented line:

```djot
- item 1
  {.highlight #id1}
- item 2
  {data-value="test"}
- item 3
```

**Output:**
```html
<ul>
<li class="highlight" id="id1">item 1</li>
<li data-value="test">item 2</li>
<li>item 3</li>
</ul>
```

Works with all list types:

```djot
1. First item
   {.important}
2. Second item

- [ ] Unchecked task
  {.pending}
- [x] Completed task
  {.done}
```

**Rules:**
- Attributes on next line at content indentation level
- Uses standard `{.class #id key=value}` syntax
- Works with unordered, ordered, and task lists

---

### Table Row and Cell Attributes

**Related:** [jgm/djot#250](https://github.com/jgm/djot/issues/250)

**Status:** Implemented in djot-php (issue #18)

Attributes can be added to table rows and cells:

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

**Output:**
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

**Rules:**
- Row attributes: `| cell | cell |{.class}` (after final pipe)
- Cell attributes: `|{.class} content |` (after opening pipe)
- Separator row attributes are ignored: `|---|---|{.ignored}`
- Attributes preserved when rows are converted to headers
- Works with alignment specifiers

---

### Boolean Attribute Shorthand

**Related:** [jgm/djot#257](https://github.com/jgm/djot/issues/257)

**Status:** Implemented in djot-php

Boolean/flag attributes can be specified without a value for cleaner syntax:

```djot
{reversed}
1. Third
2. Second
3. First

::: details
{open}
This is expanded by default.
:::

[Download](file.zip){download .btn}
```

**Output:**
```html
<ol reversed="">
<li>Third</li>
<li>Second</li>
<li>First</li>
</ol>

<details open="">
<p>This is expanded by default.</p>
</details>

<p><a href="file.zip" class="btn" download="">Download</a></p>
```

**Supported syntax:**
- `{reversed}` - bare attribute name (no `=` required)
- `{hidden .class}` - combinable with classes
- `{#id open disabled}` - multiple boolean attributes with ID
- `{.alert hidden data-value="x"}` - mixed with key=value attributes
- `[text](url){download}` - works on inline links too

**Common use cases:**
- `{reversed}` - reversed ordered lists
- `{open}` - expanded `<details>` elements
- `{hidden}` - hidden elements
- `{download}` - downloadable links

---

### Fenced Comment Blocks

**Related:** [jgm/djot#67](https://github.com/jgm/djot/issues/67)

**Status:** Implemented in djot-php

Standard `{% %}` comments cannot contain blank lines (they act as paragraph separators).
Fenced comment blocks using `%%%` solve this:

```djot
%%%
This comment can contain

blank lines

and multiple paragraphs.
%%%
```

**Output:**
```html
<!-- nothing rendered -->
```

**Features:**
- Uses `%%%` (3+ percent signs) as delimiters
- Closing fence must have at least as many `%` as opening
- Blank lines inside are preserved in the Comment node
- Like code fences, use more `%` to include `%%%` inside

```djot
%%%%
%%% this is not the end
still inside
%%%%
```

**Rationale:** The `%` character is already associated with comments in Djot (`{% %}`).
This fenced syntax is consistent with code fences (`` ``` ``) and div fences (`:::`).

---

### Multiple Definition Terms

**Status:** Implemented in djot-php

Multiple terms can share definitions in definition lists:

```djot
: CLI
: Command Line Interface

  A text-based interface for interacting with computers.

: color
: colour

  The visual property of objects.
```

**Output:**
```html
<dl>
<dt>CLI</dt>
<dt>Command Line Interface</dt>
<dd>
<p>A text-based interface for interacting with computers.</p>
</dd>
<dt>color</dt>
<dt>colour</dt>
<dd>
<p>The visual property of objects.</p>
</dd>
</dl>
```

**Multiple definitions:** When multiple terms share definitions, each indented paragraph block (separated by blank lines) becomes a separate `<dd>`:

```djot
: color
: colour

  The visual property of objects.

  Used in art and design.
```

**Output:**
```html
<dl>
<dt>color</dt>
<dt>colour</dt>
<dd>
<p>The visual property of objects.</p>
</dd>
<dd>
<p>Used in art and design.</p>
</dd>
</dl>
```

**Rules:**
- Consecutive `: term` lines are grouped as multiple terms
- Blank lines between terms are allowed
- Definition follows after blank line with indentation
- Each paragraph block becomes a separate `<dd>` element
- Common in dictionaries for synonyms, abbreviations, and alternate spellings

---

### Multiple Definition Definitions (`: +` Continuation)

**Related:** [php-collective/djot-php#49](https://github.com/php-collective/djot-php/pull/49)

**Status:** Implemented in djot-php

HTML definition lists support multiple `<dd>` elements per term. While blank lines within definition content create paragraphs in the same `<dd>`, the `: +` continuation marker explicitly creates additional `<dd>` elements:

```djot
: term

  First definition.

: +

  Second definition (separate dd element).

: +

  Third definition.
```

**Output:**
```html
<dl>
<dt>term</dt>
<dd>
<p>First definition.</p>
</dd>
<dd>
<p>Second definition (separate dd element).</p>
</dd>
<dd>
<p>Third definition.</p>
</dd>
</dl>
```

**Comparison with blank lines:**

```djot
: term

  First paragraph.

  Second paragraph (same dd).
```

Produces a single `<dd>` with two paragraphs, while `: +` creates distinct `<dd>` elements.

**Features:**
- Uses `: +` marker to start a new definition for the same term
- Full roundtrip support in HtmlToDjot converter
- Works with definition list attributes
- Maintains compatibility with existing blank-line paragraph behavior

---

### Definition List Element Attributes

**Related:** [jgm/djot#323](https://github.com/jgm/djot/issues/323)

**Status:** Implemented in djot-php

Attributes can be attached to individual `<dl>`, `<dt>`, and `<dd>` elements:

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

**Output:**
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

**Syntax:**
- `{...}` before first term → applies to `<dl>`
- `{...}` on line after term → applies to that `<dt>`
- `{...}` as last line in definition block → applies to that `<dd>` (consistent with list items)

---

### Table Multi-line Cells, Rowspan, and Colspan

**Related:** [jgm/djot#368](https://github.com/jgm/djot/issues/368)

**Status:** Implemented in djot-php (PR #67)

Enhanced table features for complex data presentation:

**1. Multi-line Cell Content (continuation rows)**

Uses `+` prefix instead of `|` to signal content continuation:

```djot
| Name | Description      |
|------|------------------|
| Item | Long description |
+      | continued here   |
```

**Output:**
```html
<table>
<tr><th>Name</th><th>Description</th></tr>
<tr><td>Item</td><td>Long description continued here</td></tr>
</table>
```

Content from continuation rows is merged with space (like soft breaks).

**2. Rowspan Support**

The `^` marker indicates a cell is spanned from above (marker points UP):

```djot
| Category | Item   |
|----------|--------|
| Fruits   | Apple  |
| ^        | Banana |
| ^        | Orange |
```

**Output:**
```html
<table>
<tr><th>Category</th><th>Item</th></tr>
<tr><td rowspan="3">Fruits</td><td>Apple</td></tr>
<tr><td>Banana</td></tr>
<tr><td>Orange</td></tr>
</table>
```

Use `\^` for literal `^` content.

**3. Colspan Support**

The `<` marker indicates a cell is spanned from left (marker points LEFT):

```djot
| Name  | Contact Info | <     |
|-------|--------------|-------|
| Alice | alice@ex.com | x5234 |
```

**Output:**
```html
<table>
<tr><th>Name</th><th colspan="2">Contact Info</th></tr>
<tr><td>Alice</td><td>alice@ex.com</td><td>x5234</td></tr>
</table>
```

Use `\<` for literal `<` content. Content like `a < b` is NOT treated as a colspan marker.

**4. Combined Rowspan + Colspan (2x2 blocks)**

When a cell has both rowspan and colspan, it creates a rectangular block:

```djot
|     | H1  | H2  |
|-----|-----|-----|
| L1  | A   | <   |
| L2  | ^   | ^   |
```

This creates a 2x2 block where cell A has `colspan="2" rowspan="2"`.

**5. Code Spans Across Continuation Lines**

Code spans can span across continuation rows:

```djot
| aaa | `this is a really long |
+     | code span`             |
```

Renders the second cell as: `<code>this is a really long code span</code>`

**Edge Cases:**
- Span markers in continuation rows are merged as content (not treated as spans)
- Multiple `^` under a colspan only extend rowspan once per row
- If intersection cells contain content instead of markers, that content is dropped

---

### Creole-style Header Cells (`|=`)

**Related:** [php-collective/djot-php#8](https://github.com/php-collective/djot-php/pull/8)

**Status:** Implemented in djot-php

Mark individual cells as headers using the `|=` prefix (Creole/MediaWiki-style):

```djot
|= Name |= Age |
| Alice | 28   |
| Bob   | 34   |
```

**Output:**
```html
<table>
<tr><th>Name</th><th>Age</th></tr>
<tr><td>Alice</td><td>28</td></tr>
<tr><td>Bob</td><td>34</td></tr>
</table>
```

**Key Differences from Separator Row Headers:**
- No separator row (`|---|`) needed
- Individual cells can be headers (mix header and data cells in same row)
- Enables row headers on the left side of tables

**Row Headers Example:**

```djot
|= Category | Value |
|= Apples   | 10    |
|= Oranges  | 20    |
```

Each row has a header cell on the left and a data cell on the right.

**Inline Alignment:**

Alignment markers attach directly to `|=`:

| Syntax | Alignment |
|--------|-----------|
| `\|=< text` | Left |
| `\|=> text` | Right |
| `\|=~ text` | Center |

```djot
|=< Left |=> Right |=~ Center |
| A      | B       | C        |
```

Header alignment propagates to data cells below when no separator row is present.

**Compatibility:**

- Markers must be directly attached to pipe: `|= Header` (header), `| = text` (literal)
- Can coexist with separator rows (separator row alignment takes precedence)
- Works with colspan (`<`), rowspan (`^`), and multi-line cells (`+`)

---

### Captions for Images, Tables, and Block Quotes

**Related:** [php-collective/djot-php#37](https://github.com/php-collective/djot-php/issues/37)

**Status:** Implemented in djot-php

The `^ caption text` syntax adds captions to images, tables, and block quotes:

**Image captions** (wrapped in `<figure>` with `<figcaption>`):

```djot
![Sunset over the ocean](sunset.jpg)
^ A beautiful sunset captured at the beach
```

**Output:**
```html
<figure>
<img alt="Sunset over the ocean" src="sunset.jpg"><figcaption>A beautiful sunset captured at the beach</figcaption>
</figure>
```

**Table captions** (adds `<caption>` element):

```djot
| Product | Price |
|---------|-------|
| Widget  | $10   |
^ Product pricing as of 2024
```

**Output:**
```html
<table>
<caption>Product pricing as of 2024</caption>
<tr><th>Product</th><th>Price</th></tr>
<tr><td>Widget</td><td>$10</td></tr>
</table>
```

**Block quote captions** (wrapped in `<figure>` with `<figcaption>`, useful for attributions):

```djot
> To be or not to be, that is the question.
^ William Shakespeare, Hamlet
```

**Output:**
```html
<figure>
<blockquote>
<p>To be or not to be, that is the question.</p>
</blockquote>
<figcaption>William Shakespeare, Hamlet</figcaption>
</figure>
```

**Features:**
- `^ ` marker at start of line triggers caption parsing
- Can interrupt paragraphs (no blank line required before caption)
- Blank line between element and caption is allowed for readability
- Multi-line captions supported (continues until blank line or new block)
- Full roundtrip support in HtmlToDjot converter

**Multi-line caption example:**

```djot
![Historic photo](apollo.jpg)
^ This photograph was taken in 1969
during the Apollo 11 mission.
Credit: NASA
```

---

## Testing

All enhancements have dedicated test coverage:

```bash
# Tab indentation tests
vendor/bin/phpunit tests/TestCase/TabIndentationTest.php

# Run full test suite (800+ tests)
vendor/bin/phpunit
```

---

## Upstream Tracking

### Edge Case Fixes

| Enhancement            | Upstream Issue                                      | Status          |
|------------------------|-----------------------------------------------------|-----------------|
| Tab indentation        | [#255](https://github.com/jgm/djot/issues/255)      | Open discussion |
| Multiple footnote refs | [#348](https://github.com/jgm/djot/issues/348)      | Open            |
| Section ID footnotes   | [#349](https://github.com/jgm/djot/issues/349)      | Open            |
| Symbol time formats    | [#350](https://github.com/jgm/djot/issues/350)      | Open            |
| Em-dash with braces    | [#125](https://github.com/jgm/djot/issues/125)      | Open            |

### Language Features

| Feature                           | Upstream PR/Issue                                                   | Status     |
|-----------------------------------|---------------------------------------------------------------------|------------|
| List item attributes              | [djot:262](https://github.com/jgm/djot/pull/262)                    | Open PR    |
| Table row/cell attributes         | [djot:250](https://github.com/jgm/djot/issues/250)                  | Open       |
| Boolean attribute shorthand       | [djot:257](https://github.com/jgm/djot/issues/257)                  | Open       |
| Multiple definition terms         | [djot:128](https://github.com/jgm/djot/issues/128)                  | djot-php   |
| Multiple definition definitions   | [#49](https://github.com/php-collective/djot-php/pull/49)           | djot-php   |
| Definition list attributes        | [djot:323](https://github.com/jgm/djot/issues/323)                  | Open       |
| Fenced comment blocks             | [djot:67](https://github.com/jgm/djot/issues/67)                    | Open       |
| Captions (image/table/blockquote) | [#37](https://github.com/php-collective/djot-php/issues/37) | djot-php |
| Table multi-line/rowspan/colspan  | [djot:368](https://github.com/jgm/djot/issues/368)                  | Open       |
| Creole-style header cells (`\|=`) | [#8](https://github.com/php-collective/djot-php/pull/8)             | djot-php   |
| Abbreviations (block, not inline) | [djot:51](https://github.com/jgm/djot/issues/51)                    | djot-php   |

### Optional Modes

| Mode                  | Upstream Issue                              | Status            |
|-----------------------|---------------------------------------------|-------------------|
| Significant newlines  | [#161](https://github.com/jgm/djot/issues/161) | djot-php (opt-in) |

These enhancements may be adopted into the official spec. We track upstream discussions and adjust our implementation accordingly.

---

## Abbreviations (PHP Markdown Extra Style)

**Status:** djot-php extension

Abbreviation definitions using PHP Markdown Extra syntax for automatic `<abbr>` tag wrapping:

```djot
The HTML specification is maintained by the W3C.

*[HTML]: Hyper Text Markup Language
*[W3C]: World Wide Web Consortium
```

**Output:**
```html
<p>The <abbr title="Hyper Text Markup Language">HTML</abbr> specification
is maintained by the <abbr title="World Wide Web Consortium">W3C</abbr>.</p>
```

**Features:**
- Definitions can appear anywhere in the document
- Case-sensitive matching (HTML ≠ html)
- Word-boundary aware (HTML won't match HTMLElement or XHTML)
- Multi-line definitions supported with indentation
- Works alongside the inline span approach (`[HTML]{abbr="..."}`) from the [cookbook](cookbook.md#abbreviations)

**Multi-line definition example:**
```djot
*[HTML]: Hyper Text Markup Language,
  the standard markup language for documents
  designed to be displayed in a web browser
```

This is an extension feature not part of the djot spec yet.

---

## Reporting Issues

If you find edge cases or inconsistencies:

1. Check if it's covered by the [djot spec](https://htmlpreview.github.io/?https://github.com/jgm/djot/blob/master/doc/syntax.html)
2. Check [upstream issues](https://github.com/jgm/djot/issues) for existing discussions
3. Report to [djot-php issues](https://github.com/php-collective/djot-php/issues)
