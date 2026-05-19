# Enhancements Beyond Spec

This document tracks djot-php enhancements that go beyond the current [djot specification](https://htmlpreview.github.io/?https://github.com/jgm/djot/blob/master/doc/syntax.html) but align with its direction.

These are fixes or improvements for edge cases not explicitly covered by the spec.
They are either on the way to get incorporated upstream - or may be incorporated into future spec versions.

## Table of Contents

- [Tab Indentation Support](#tab-indentation-support)
- [Multiple Footnote References](#multiple-footnote-references)
- [Section ID Excludes Footnote Markers and Symbols](#section-id-excludes-footnote-markers-and-symbols)
- [CSS-Safe Heading IDs](#css-safe-heading-ids)
- [Symbol Parsing in Time Formats](#symbol-parsing-in-time-formats)
- [Em/En Dash with Unmatched Braces](#em-en-dash-with-unmatched-braces)
- [Optional Modes](#optional-modes)
  - [Significant Newlines Mode](#significant-newlines-mode)
- [Language Features Beyond Spec](#language-features-beyond-spec)
  - [Task List Underscore Notation](#task-list-underscore-notation)
  - [List Item Attributes](#list-item-attributes)
  - [Table Row and Cell Attributes](#table-row-and-cell-attributes)
  - [Boolean Attribute Shorthand](#boolean-attribute-shorthand)
  - [Fenced Comment Blocks](#fenced-comment-blocks)
  - [Multiple Definition Terms](#multiple-definition-terms)
  - [Multiple Definition Definitions](#multiple-definition-definitions-continuation)
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

## Section ID Excludes Footnote Markers and Symbols

**Related:** [jgm/djot#349](https://github.com/jgm/djot/issues/349), [jgm/djot#393](https://github.com/jgm/djot/pull/393)

**Status:** Implemented in djot-php

Per the djot spec, an auto-generated identifier is formed from the plain text
content of the heading *"excluding non-textual elements such as footnote
references and symbols"*. djot-php excludes both:

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

Symbols are likewise dropped from the identifier (but kept in the
human-readable plain text used for things like TOC labels):

```djot
# Release notes :tada:
```

The ID is `Release-notes`, not `Release-notes-tada`. A heading whose only
content is a symbol falls back to a generated `s-N` ID.

---

## CSS-Safe Heading IDs

**Related:** [php-collective/djot-php#92](https://github.com/php-collective/djot-php/pull/92), [jgm/djot#391](https://github.com/jgm/djot/issues/391)

**Status:** Implemented in djot-php

Auto-generated heading IDs are normalized to be valid CSS selectors **and ASCII-only**, so they work with `querySelector()` / HTMX scroll restoration *and* survive being copied around as URL fragments (see [Why ASCII](#why-ascii) below).

### Normalization Rules

1. **Transliterate to ASCII** — `Über`→`Uber`, `café`→`cafe`, `Привет`→`Privet`, smart quotes/dashes→`'"-` (then replaced)
2. **Strip `#` characters** — Prevents invalid selectors
3. **Trim whitespace**
4. **Whitespace to dashes** — Spaces become single `-`
5. **Invalid characters to dashes** — Anything other than letters, numbers, `-`, `_` becomes `-`
6. **Collapse consecutive dashes** — `foo--bar` becomes `foo-bar`
7. **Trim leading/trailing dashes**
8. **Prefix digits** — IDs starting with a digit get an `h-` prefix (CSS requirement)
9. **Fallback** — Empty results become `heading` (or a generated `s-N` for empty headings)

### Examples

| Heading | Generated ID |
|---------|--------------|
| `# Hello World` | `Hello-World` |
| `# Hello World!` | `Hello-World` |
| `# Über uns` | `Uber-uns` |
| `# café résumé` | `cafe-resume` |
| `# Привет мир` | `Privet-mir` |
| `# Bob's Guide` (smart quotes) | `Bob-s-Guide` |
| `# E=mc^2` | `E-mc-2` |
| `# 123 Numbers First` | `h-123-Numbers-First` |
| `# $this->method()` | `this-method` |
| `# ###` | `heading` |

### Why ASCII {#why-ascii}

Heading IDs end up as URL fragments (`…/page#Über-uns`) that get copied into chat, email and other documents, where **auto-linkers re-detect the URL heuristically**. Non-ASCII fragments are routinely:

- **truncated** — the link is cut at the first non-ASCII byte (`#Über` → `#`), producing a silent dead link;
- **percent-encoded inconsistently** — `’`→`%E2%80%99`, bloating and sometimes breaking the link;
- **re-normalized differently** by the receiving app (NFC/NFD), so the pasted fragment no longer matches the page's `id`.

Transliterating to ASCII keeps shared deep links robust. It's a deliberate deviation from both the djot.js reference and the [jgm/djot#393](https://github.com/jgm/djot/pull/393) spec prose (both preserve non-ASCII) — see [Spec Alignment](#spec-alignment).

### Transliteration engine & determinism

Two engines produce the ASCII form:

- **ICU `Transliterator`** (`Any-Latin; Latin-ASCII`) when `ext-intl` is installed — also romanizes scripts the map doesn't cover (Greek, CJK, Arabic, …);
- a **baked Unicode→ASCII map** (`src/Renderer/ascii_translit_map.php`) otherwise.

The baked map is generated *from the same ICU transform*, and the generator bakes a script **only if every code point in it transliterates context-free** (verified standalone, doubled, and between Latin letters). For those scripts — Latin (so all of German, French, Spanish, Polish, Czech, Turkish, Vietnamese, …), Cyrillic, punctuation, smart quotes, dashes, currency — the output is **byte-identical with or without `ext-intl`**, so shared anchors stay stable across environments.

Scripts whose ICU romanization is context-sensitive (e.g. Greek: `αυ`→`au` but `υ`→`y`) are excluded *wholesale* — baking only their context-free letters would produce IDs that disagree with ICU, which is worse than not covering them. Those scripts, plus non-Latin scripts the map never covers (CJK, Arabic, …), behave one way: **with `ext-intl` they are romanized; without it they are dropped and the heading falls back to a generated `s-N` id**. `ext-intl` is therefore *recommended* (a `composer suggest`) but not required; the determinism guarantee above never depends on it.

### Explicit IDs

You can always override with an explicit ID attribute:

```djot
# My Heading {#custom-id}
```

Explicit IDs are used as-is without normalization or transliteration.

### Spec Alignment {#spec-alignment}

The remove-vs-replace question raised in [jgm/djot#391](https://github.com/jgm/djot/issues/391) was settled by [jgm/djot#393](https://github.com/jgm/djot/pull/393), which reworded the spec to: *"replacing each maximal run of non-alphanumeric ASCII characters with `-`, removing any leading or trailing `-`"*. #393 changed only the spec **prose**; the djot.js reference implementation is unchanged.

djot-php replaces (does not remove) mid-word punctuation — the direction #393 settled on — additionally replaces `' " ; :` so IDs are valid CSS identifiers, and **transliterates non-ASCII to ASCII** so IDs stay link-safe when shared. The last point is a deliberate deviation from *both* djot.js and the #393 prose, justified by the [Why ASCII](#why-ascii) failure mode.

| Aspect | djot.js reference impl | #393 spec prose | djot-php |
|--------|------------------------|-----------------|----------|
| Mid-word punctuation (`A+B=C`) | `A-B-C` | `A-B-C` | `A-B-C` |
| Consecutive punctuation (`foo...bar`) | collapse → `foo-bar` | collapse → `foo-bar` | collapse → `foo-bar` |
| Underscore (`foo_bar`) | keep → `foo_bar` | strip → `foo-bar` | keep → `foo_bar` (CSS-valid, link-safe) |
| Apostrophe / `"` / `;` / `:` | preserve | replace | replace → `-` (CSS-safe) |
| Non-ASCII letters (`Über uns`) | preserve → `Über-uns` | preserve → `Über-uns` | **transliterate → `Uber-uns`** (link-safe) |
| Non-ASCII / smart quotes (`Bob’s`) | preserve → `Bob’s` | preserve → `Bob’s` | **transliterate → `Bob-s`** (link-safe) |
| Leading digit (`2024 recap`) | `2024-recap` | `2024-recap` | prefix → `h-2024-recap` (CSS requires non-digit start) |
| Empty result (`!!!`) | `s-N` family | unspecified | fallback → `heading` |
| Symbols / footnote refs | excluded | excluded | excluded |

The deviations are deliberate: `' " ; :` are not valid in unescaped CSS identifiers, and non-ASCII fragments break when shared (see [Why ASCII](#why-ascii)). The leading-digit and empty-result behaviors fill in gaps the spec and reference handle inconsistently. A note proposing the spec clarify the non-ASCII question is tracked against [jgm/djot#391](https://github.com/jgm/djot/issues/391).

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

Note: Soft break rendering is controlled separately via `SoftBreakMode` and is not affected by this setting.

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

### Task List Underscore Notation

**Related:** [jgm/djot#305](https://github.com/jgm/djot/issues/305)

**Status:** Implemented in djot-php

The underscore `[_]` can be used as an alternative to space `[ ]` for unchecked task list items:

```djot
- [_] unchecked with underscore
- [ ] unchecked with space
- [x] checked item
```

**Output:**
```html
<ul class="task-list">
<li><input type="checkbox" disabled> unchecked with underscore</li>
<li><input type="checkbox" disabled> unchecked with space</li>
<li><input type="checkbox" disabled checked> checked item</li>
</ul>
```

**Rationale:** The underscore notation is useful when:
- Typing on mobile devices where spaces inside brackets can be difficult
- Using editors without monospaced fonts where `[ ]` may look ambiguous
- The underscore visually resembles an empty checkbox in source

Both notations are fully equivalent and can be mixed within the same list.

---

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
- The `{...}` line attaches to the `<li>` **only when it is the last
  content line of the item**. If another block follows the `{...}`
  line within the same item, the `{...}` reverts to a standard djot
  block attribute for that following block; the list and item are
  not terminated.

**Stripped attribute names:** `start`, `type`, and `reversed` are HTML
attributes valid only on `<ol>`. When authored on a list item they are
silently dropped from the rendered `<li>` to keep output HTML valid.
To set `<ol start="5">`, use a standard block-attribute line **before**
the list: `{start=5}` followed by `1. ...`.

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

**Stripped attribute names:** the same `start`, `type`, `reversed`
strip applies to `<dd>` output.

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
| Task list underscore notation     | [djot:305](https://github.com/jgm/djot/issues/305)                  | Open       |
| List item attributes              | [djot:262](https://github.com/jgm/djot/pull/262)                    | Open PR    |
| Table row/cell attributes         | [djot:250](https://github.com/jgm/djot/issues/250)                  | Open       |
| Boolean attribute shorthand       | [djot:257](https://github.com/jgm/djot/issues/257)                  | Open       |
| Multiple definition terms         | [djot:128](https://github.com/jgm/djot/issues/128)                  | djot-php   |
| Multiple definition definitions   | [#49](https://github.com/php-collective/djot-php/pull/49)           | djot-php   |
| Definition list attributes        | [djot:323](https://github.com/jgm/djot/issues/323)                  | Open       |
| Fenced comment blocks             | [djot:67](https://github.com/jgm/djot/issues/67)                    | Open       |
| Captions (image/table/blockquote) | [#37](https://github.com/php-collective/djot-php/issues/37) | djot-php |
| Table multi-line/rowspan/colspan  | [djot:368](https://github.com/jgm/djot/issues/368)                  | Open       |
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
- Works alongside the inline span approach (`[HTML]{abbr="..."}`) from the [cookbook](/cookbook/#abbreviations)

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
