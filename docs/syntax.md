# Djot Syntax Reference

This document covers all Djot syntax supported by this library.

## Block Elements

### Headings

```djot
# Heading 1
## Heading 2
### Heading 3
#### Heading 4
##### Heading 5
###### Heading 6
```

### Paragraphs

Paragraphs are separated by blank lines:

```djot
First paragraph.

Second paragraph.
```

### Code Blocks

Fenced code blocks with optional language:

````djot
```php
echo "Hello World";
```
````

### Block Quotes

```djot
> This is a quote
> spanning multiple lines
```

### Lists

Bullet lists:

```djot
- Item 1
- Item 2
- Item 3
```

Ordered lists:

```djot
1. First
2. Second
3. Third
```

Task lists:

```djot
- [ ] Unchecked
- [x] Checked
```

### Definition Lists

```djot
Term
: Definition of the term

Another term
: Its definition
```

### Tables

```djot
| Header 1 | Header 2 |
|----------|----------|
| Cell 1   | Cell 2   |
```

With alignment:

```djot
| Left | Center | Right |
|:-----|:------:|------:|
| L    | C      | R     |
```

### Thematic Breaks

```djot
***
```

or

```djot
---
```

### Divs

```djot
::: warning
This is a warning div.
:::
```

### Comments

Comments are stripped from output:

```djot
{% This is a comment %}

{% Multi-line
   comment %}
```

### Line Blocks

Preserve line breaks (useful for poetry, addresses):

```djot
| Line one
| Line two
| Line three
```

### Block Attributes

Apply attributes to the following block:

```djot
{.highlight #intro}
# Introduction
```

## Inline Elements

### Emphasis and Strong

```djot
_emphasized text_
*strong text*
```

### Code Spans

```djot
Use the `print()` function
```

### Links

Inline links:

```djot
[Link text](https://example.com)
```

Reference links:

```djot
[Link text][ref]

[ref]: https://example.com
```

Autolinks:

```djot
<https://example.com>
<user@example.com>
```

### Images

```djot
![Alt text](image.png)
```

### Superscript and Subscript

```djot
E=mc^2^
H~2~O
```

### Highlight, Insert, Delete

```djot
{=highlighted=}
{+inserted+}
{-deleted-}
```

### Spans with Attributes

```djot
[text]{.class}
[text]{#id}
[text]{key=value}
```

### Math

Inline math:

```djot
$`E = mc^2`$
```

Display math:

```djot
$$`\sum_{i=0}^n i`$$
```

### Symbols

```djot
I :heart: Djot
```

### Footnotes

```djot
Here is a reference[^1].

[^1]: This is the footnote content.
```

### Raw HTML

Inline:

```djot
`<span>raw html</span>`{=html}
```

Block:

````djot
``` =html
<div class="custom">Raw HTML</div>
```
````

## Smart Typography

The parser automatically converts:

- `"quotes"` to curly quotes
- `--` to en-dash (–)
- `---` to em-dash (—)
- `...` to ellipsis (…)

## Hard Breaks

End a line with backslash for a hard break:

```djot
Line one\
Line two
```
