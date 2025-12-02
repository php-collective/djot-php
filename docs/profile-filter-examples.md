# Profile Filter Examples

When using profiles with `ACTION_TO_TEXT` (the default), disallowed elements are converted to plain text rather than being stripped entirely. This preserves content while removing formatting that isn't appropriate for the context.

## How It Works

```php
use Djot\DjotConverter;
use Djot\Profile;

// Profile::minimal() only allows basic text formatting and lists
$converter = new DjotConverter(profile: Profile::minimal());
$html = $converter->convert($userInput);
```

## Element Conversion Examples

The following examples show how each element type is converted when filtered by `Profile::minimal()`.

### Heading

**Input:**
```djot
### This is a heading
```

**Output:**
```html
<p>### This is a heading</p>
```

**Renders as:** ### This is a heading

### Footnote Reference

**Input:**
```djot
Here is a footnote reference[^1].
```

**Output:**
```html
<p>Here is a footnote reference[^1].</p>
```

**Renders as:** Here is a footnote reference[^1].

### Footnote Definition

**Input:**
```djot
[^1]: This is the footnote content.
```

**Output:**
```html
<p>[^1]: This is the footnote content.</p>
```

**Renders as:** [^1]: This is the footnote content.

### Thematic Break

**Input:**
```djot
---
```

**Output:**
```html
<p>---</p>
```

**Renders as:** ---

### Definition List

**Input:**
```djot
: Djot

  A lightweight markup language.

: Markdown

  The predecessor.
```

**Output:**
```html
<p>Djot<br>
- A lightweight markup language.<br>
Markdown<br>
- The predecessor.</p>
```

**Renders as:**
```
Djot
- A lightweight markup language.
Markdown
- The predecessor.
```

### Table

**Input:**
```djot
| Name | Type |
|------|------|
| Djot | Markup |
| PHP  | Code |
```

**Output:**
```html
<p>Name | Type<br>
Djot | Markup<br>
PHP | Code</p>
```

**Renders as:**
```
Name | Type
Djot | Markup
PHP | Code
```

### Blockquote

**Input:**
```djot
> First paragraph.
>
> Second paragraph.
```

**Output:**
```html
<p>&gt; First paragraph.<br>
&gt; Second paragraph.</p>
```

**Renders as:**
```
> First paragraph.
> Second paragraph.
```

### Symbol

**Input:**
```djot
I :heart: this!
```

**Output:**
```html
<p>I :heart: this!</p>
```

**Renders as:** I :heart: this!

### Link

**Input:**
```djot
Check [this link](https://example.com)!
```

**Output:**
```html
<p>Check this link!</p>
```

**Renders as:** Check this link!

### Image

**Input:**
```djot
![Alt text](image.jpg)
```

**Output:**
```html
<p>[img: Alt text]</p>
```

**Renders as:** [img: Alt text]

### Image (no alt text)

**Input:**
```djot
![](image.jpg)
```

**Output:**
```html
<p>[img]</p>
```

**Renders as:** [img]

### Code Block (single line)

**Input:**
````djot
```php
echo "Hello";
```
````

**Output:**
```html
<p>`echo "Hello";`</p>
```

**Renders as:** `echo "Hello";`

### Code Block (multi-line)

**Input:**
````djot
```php
echo "Hello";
echo "World";
```
````

**Output:**
```html
<p>```<br>
echo "Hello";<br>
echo "World";<br>
```</p>
```

**Renders as:**
````
```
echo "Hello";
echo "World";
```
````

### Highlight

**Input:**
```djot
This is {=highlighted=} text.
```

**Output:**
```html
<p>This is highlighted text.</p>
```

**Renders as:** This is highlighted text.

### Raw HTML Inline

**Input:**
```djot
`<b>bold</b>`{=html}
```

**Output:**
```html
<p>&lt;b&gt;bold&lt;/b&gt;</p>
```

**Renders as:** &lt;b&gt;bold&lt;/b&gt; (escaped)

## Comparison: ACTION_TO_TEXT vs ACTION_STRIP

| Mode | Behavior | Use Case |
|------|----------|----------|
| `ACTION_TO_TEXT` | Converts to plain text, preserves content | User-facing (default) |
| `ACTION_STRIP` | Removes element entirely | When content must be removed |
| `ACTION_ERROR` | Throws exception | API validation |

```php
// Strip mode - content is lost
$profile = Profile::minimal()->onDisallowed(Profile::ACTION_STRIP);

// Error mode - throws ProfileViolationException
$profile = Profile::minimal()->onDisallowed(Profile::ACTION_ERROR);
```
