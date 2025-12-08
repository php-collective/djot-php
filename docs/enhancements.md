# Enhancements Beyond Spec

This document tracks djot-php enhancements that go beyond the current [djot specification](https://htmlpreview.github.io/?https://github.com/jgm/djot/blob/master/doc/syntax.html) but align with its direction.

These are fixes or improvements for edge cases not explicitly covered by the spec.
They are either on the way to get incorporated upstream - or may be incorporated into future spec versions.

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

| Feature                   | Upstream PR/Issue                              | Status     |
|---------------------------|------------------------------------------------|------------|
| List item attributes      | [#262](https://github.com/jgm/djot/pull/262)   | Open PR    |
| Boolean attribute shorthand | [#257](https://github.com/jgm/djot/issues/257) | Open       |
| Multiple definition terms | -                                              | djot-php   |
| Fenced comment blocks     | [#67](https://github.com/jgm/djot/issues/67)   | Open       |

### Optional Modes

| Mode                  | Upstream Issue                              | Status            |
|-----------------------|---------------------------------------------|-------------------|
| Significant newlines  | [#161](https://github.com/jgm/djot/issues/161) | djot-php (opt-in) |

These enhancements may be adopted into the official spec. We track upstream discussions and adjust our implementation accordingly.

---

## Reporting Issues

If you find edge cases or inconsistencies:

1. Check if it's covered by the [djot spec](https://htmlpreview.github.io/?https://github.com/jgm/djot/blob/master/doc/syntax.html)
2. Check [upstream issues](https://github.com/jgm/djot/issues) for existing discussions
3. Report to [djot-php issues](https://github.com/php-collective/djot-php/issues)
