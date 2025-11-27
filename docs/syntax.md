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

**Output:**
```html
<h1>Heading 1</h1>
<h2>Heading 2</h2>
<h3>Heading 3</h3>
<h4>Heading 4</h4>
<h5>Heading 5</h5>
<h6>Heading 6</h6>
```

### Paragraphs

Paragraphs are separated by blank lines. Text within a paragraph wraps normally.

**Input:**
```djot
First paragraph with
multiple lines.

Second paragraph.
```

**Output:**
```html
<p>First paragraph with
multiple lines.</p>
<p>Second paragraph.</p>
```

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

**Output:**
```html
<pre><code class="language-php">function hello(): void {
    echo "Hello World";
}
</code></pre>
```

Without a language:

**Input:**
````djot
```
Plain code block
```
````

**Output:**
```html
<pre><code>Plain code block
</code></pre>
```

### Block Quotes

Block quotes use `>` at the start of each line.

**Input:**
```djot
> This is a quote
> spanning multiple lines.
>
> With multiple paragraphs.
```

**Output:**
```html
<blockquote>
<p>This is a quote
spanning multiple lines.</p>
<p>With multiple paragraphs.</p>
</blockquote>
```

Nested block quotes:

**Input:**
```djot
> Outer quote
>
> > Nested quote
```

**Output:**
```html
<blockquote>
<p>Outer quote</p>
<blockquote>
<p>Nested quote</p>
</blockquote>
</blockquote>
```

### Lists

#### Bullet Lists

Use `-`, `*`, or `+` for bullet lists.

**Input:**
```djot
- Item 1
- Item 2
- Item 3
```

**Output:**
```html
<ul>
<li>Item 1</li>
<li>Item 2</li>
<li>Item 3</li>
</ul>
```

#### Ordered Lists

Use numbers followed by `.` or `)`.

**Input:**
```djot
1. First
2. Second
3. Third
```

**Output:**
```html
<ol>
<li>First</li>
<li>Second</li>
<li>Third</li>
</ol>
```

Lists can start at any number:

**Input:**
```djot
5. Fifth item
6. Sixth item
```

**Output:**
```html
<ol start="5">
<li>Fifth item</li>
<li>Sixth item</li>
</ol>
```

#### Nested Lists

Indent with 2+ spaces for nested lists.

**Input:**
```djot
- Item 1
  - Nested A
  - Nested B
- Item 2
```

**Output:**
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

#### Task Lists

Use `[ ]` for unchecked and `[x]` or `[X]` for checked items.

**Input:**
```djot
- [ ] Todo item
- [x] Done item
- [ ] Another todo
```

**Output:**
```html
<ul class="task-list">
<li><input type="checkbox" disabled> Todo item</li>
<li><input type="checkbox" disabled checked> Done item</li>
<li><input type="checkbox" disabled> Another todo</li>
</ul>
```

### Definition Lists

Terms followed by `: ` definitions.

**Input:**
```djot
Term 1
: Definition of term 1

Term 2
: Definition of term 2
: Alternative definition
```

**Output:**
```html
<dl>
<dt>Term 1</dt>
<dd>Definition of term 1</dd>
<dt>Term 2</dt>
<dd>Definition of term 2</dd>
<dd>Alternative definition</dd>
</dl>
```

### Tables

Tables use `|` to separate columns.

**Input:**
```djot
| Header 1 | Header 2 |
|----------|----------|
| Cell 1   | Cell 2   |
| Cell 3   | Cell 4   |
```

**Output:**
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

#### Column Alignment

Use `:` in the separator row for alignment.

**Input:**
```djot
| Left | Center | Right |
|:-----|:------:|------:|
| L    | C      | R     |
```

**Output:**
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

#### Table Captions

Use `^` after the table for a caption.

**Input:**
```djot
| A | B |
|---|---|
| 1 | 2 |
^ This is the caption
```

**Output:**
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

### Thematic Breaks

Use `***`, `---`, or `___` (3+ characters) on a line by itself.

**Input:**
```djot
Above

***

Below
```

**Output:**
```html
<p>Above</p>
<hr>
<p>Below</p>
```

### Divs

Fenced divs use `:::` with an optional class name.

**Input:**
```djot
::: warning
This is a warning message.
:::
```

**Output:**
```html
<div class="warning">
<p>This is a warning message.</p>
</div>
```

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

**Output:**
```html
<div class="outer">
<p>Outer content</p>
<div class="inner">
<p>Inner content</p>
</div>
<p>More outer</p>
</div>
```

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

**Output:**
```html
<p>Visible text</p>
<p>More visible text</p>
```

### Line Blocks

Preserve line breaks using `|` at the start of each line. Useful for poetry or addresses.

**Input:**
```djot
| Roses are red,
| Violets are blue,
| Sugar is sweet,
| And so are you.
```

**Output:**
```html
<div class="line-block">
<p>Roses are red,<br>
Violets are blue,<br>
Sugar is sweet,<br>
And so are you.</p>
</div>
```

### Block Attributes

Apply attributes to the following block using `{...}` syntax.

**Input:**
```djot
{.highlight #intro}
# Introduction

{.note data-version="2.0"}
This is a note.
```

**Output:**
```html
<h1 class="highlight" id="intro">Introduction</h1>
<p class="note" data-version="2.0">This is a note.</p>
```

## Inline Elements

### Emphasis and Strong

Use `_underscores_` for emphasis and `*asterisks*` for strong.

**Input:**
```djot
This is _emphasized_ and *strong* text.

You can _nest *strong* inside_ emphasis.
```

**Output:**
```html
<p>This is <em>emphasized</em> and <strong>strong</strong> text.</p>
<p>You can <em>nest <strong>strong</strong> inside</em> emphasis.</p>
```

### Code Spans

Use backticks for inline code.

**Input:**
```djot
Use the `print()` function.

For literal backticks: `` `code` ``
```

**Output:**
```html
<p>Use the <code>print()</code> function.</p>
<p>For literal backticks: <code>`code`</code></p>
```

### Links

#### Inline Links

**Input:**
```djot
[Link text](https://example.com)

[Link with title](https://example.com "Title")
```

**Output:**
```html
<p><a href="https://example.com">Link text</a></p>
<p><a href="https://example.com" title="Title">Link with title</a></p>
```

#### Reference Links

**Input:**
```djot
[Link text][ref]

[Another link][ref]

[ref]: https://example.com
```

**Output:**
```html
<p><a href="https://example.com">Link text</a></p>
<p><a href="https://example.com">Another link</a></p>
```

#### Reference Links with Attributes

Attributes can be added to reference definitions and will be applied to all links using that reference.

**Input:**
```djot
[Click here][example]

{.external title="Example Site"}
[example]: https://example.com
```

**Output:**
```html
<p><a href="https://example.com" class="external" title="Example Site">Click here</a></p>
```

Link-level attributes can override or extend definition attributes:

**Input:**
```djot
[Click here][example]{.button}

{.external}
[example]: https://example.com
```

**Output:**
```html
<p><a href="https://example.com" class="external button">Click here</a></p>
```

#### Autolinks

**Input:**
```djot
<https://example.com>

<user@example.com>
```

**Output:**
```html
<p><a href="https://example.com">https://example.com</a></p>
<p><a href="mailto:user@example.com">user@example.com</a></p>
```

### Images

**Input:**
```djot
![Alt text](image.png)

![Alt text](image.png "Title")
```

**Output:**
```html
<p><img src="image.png" alt="Alt text"></p>
<p><img src="image.png" alt="Alt text" title="Title"></p>
```

### Superscript and Subscript

Use `^` for superscript and `~` for subscript.

**Input:**
```djot
E=mc^2^

H~2~O
```

**Output:**
```html
<p>E=mc<sup>2</sup></p>
<p>H<sub>2</sub>O</p>
```

### Highlight, Insert, Delete

**Input:**
```djot
{=highlighted text=}

{+inserted text+}

{-deleted text-}
```

**Output:**
```html
<p><mark>highlighted text</mark></p>
<p><ins>inserted text</ins></p>
<p><del>deleted text</del></p>
```

### Spans with Attributes

Apply attributes to inline text.

**Input:**
```djot
[styled text]{.highlight}

[more text]{#unique-id}

[data text]{data-value="42"}
```

**Output:**
```html
<p><span class="highlight">styled text</span></p>
<p><span id="unique-id">more text</span></p>
<p><span data-value="42">data text</span></p>
```

### Math

#### Inline Math

**Input:**
```djot
The equation $`E = mc^2`$ is famous.
```

**Output:**
```html
<p>The equation <span class="math inline">\(E = mc^2\)</span> is famous.</p>
```

#### Display Math

**Input:**
```djot
$$`\sum_{i=0}^{n} i = \frac{n(n+1)}{2}`$$
```

**Output:**
```html
<span class="math display">\[\sum_{i=0}^{n} i = \frac{n(n+1)}{2}\]</span>
```

### Symbols

Use `:name:` for symbols.

**Input:**
```djot
I :heart: Djot
```

**Output:**
```html
<p>I <span class="symbol">heart</span> Djot</p>
```

Note: Symbol rendering can be customized via events. See the [Cookbook](cookbook.md) for examples.

### Footnotes

**Input:**
```djot
Here is a statement[^1] with a footnote.

Another reference[^note].

[^1]: This is the first footnote.

[^note]: This is a named footnote.
```

**Output:**
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

### Raw HTML

#### Inline Raw HTML

**Input:**
```djot
Text `<span class="special">raw html</span>`{=html} more text
```

**Output:**
```html
<p>Text <span class="special">raw html</span> more text</p>
```

#### Block Raw HTML

**Input:**
````djot
``` =html
<div class="custom">
  <p>Raw HTML block</p>
</div>
```
````

**Output:**
```html
<div class="custom">
  <p>Raw HTML block</p>
</div>
```

## Smart Typography

The parser automatically converts certain character sequences.

### Quotes

**Input:**
```djot
"Double quotes" and 'single quotes'

"Nested 'quotes' work" too
```

**Output:**
```html
<p>"Double quotes" and 'single quotes'</p>
<p>"Nested 'quotes' work" too</p>
```

### Dashes

**Input:**
```djot
En-dash: 1--10

Em-dash: wait---what?
```

**Output:**
```html
<p>En-dash: 1–10</p>
<p>Em-dash: wait—what?</p>
```

### Ellipsis

**Input:**
```djot
Wait for it...
```

**Output:**
```html
<p>Wait for it…</p>
```

## Hard Line Breaks

End a line with a backslash for a hard break.

**Input:**
```djot
Line one\
Line two\
Line three
```

**Output:**
```html
<p>Line one<br>
Line two<br>
Line three</p>
```

## Escaping

Use backslash to escape special characters.

**Input:**
```djot
\*not strong\*

\# not a heading

\[not a link\]
```

**Output:**
```html
<p>*not strong*</p>
<p># not a heading</p>
<p>[not a link]</p>
```
