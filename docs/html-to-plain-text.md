# HTML to Plain Text: A Better Approach

When you need to convert HTML to plain text, `strip_tags()` often produces poor results. This document shows how using `HtmlToDjot` combined with Profile filtering produces much more readable output.

## The Problem with strip_tags()

```php
$html = '<table><tr><th>Name</th><th>Type</th></tr><tr><td>Djot</td><td>Markup</td></tr></table>';
echo strip_tags($html);
// Output: "NameTypeDjotMarkup" - unreadable!
```

Common issues:
- Table cells run together with no separation
- List items lose their structure
- Headings blend into body text
- Images disappear entirely (including alt text)
- Blockquotes lose their visual distinction

## Possible Solution: HtmlToDjot + Profile Filtering

```php
use Djot\Converter\HtmlToDjot;
use Djot\DjotConverter;
use Djot\NodeType;
use Djot\Profile;

function htmlToPlainText(string $html): string
{
    // Step 1: Convert HTML to Djot AST
    $htmlConverter = new HtmlToDjot();
    $djot = $htmlConverter->convert($html);

    // Step 2: Create a plain-text profile (only text and paragraphs allowed)
    $plainTextProfile = (new Profile())
        ->allowInline([NodeType::TEXT, NodeType::SOFT_BREAK, NodeType::HARD_BREAK])
        ->allowBlock([NodeType::PARAGRAPH]);

    // Step 3: Render with plain-text profile (converts everything to text)
    $djotConverter = new DjotConverter(profile: $plainTextProfile);
    $plainHtml = $djotConverter->convert($djot);

    // Step 4: Strip remaining HTML tags and decode entities
    $text = strip_tags(str_replace(['<br>', '</p><p>'], ["\n", "\n\n"], $plainHtml));

    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
```

This custom profile is more restrictive than `Profile::minimal()` - it only allows plain text and paragraphs, converting all other elements (headings, lists, tables, etc.) to their semantic text representation.

## Side-by-Side Comparison

Each example shows the actual output from both approaches.

### Table

**Input:**
```html
<table><tr><th>Name</th><th>Type</th></tr><tr><td>Djot</td><td>Markup</td></tr></table>
```

| Method | Output |
|--------|--------|
| `strip_tags()` | `NameTypeDjotMarkup` |
| HtmlToDjot+Profile | `Name \| Type`<br>`Djot \| Markup` |

### Heading

**Input:**
```html
<h2>Welcome to Our Site</h2>
```

| Method | Output |
|--------|--------|
| `strip_tags()` | `Welcome to Our Site` |
| HtmlToDjot+Profile | `## Welcome to Our Site` |

### Image

**Input:**
```html
<img src="photo.jpg" alt="A beautiful photo">
```

| Method | Output |
|--------|--------|
| `strip_tags()` | *(empty string)* |
| HtmlToDjot+Profile | `[img: A beautiful photo]` |

### Blockquote

**Input:**
```html
<blockquote><p>A wise quote.</p></blockquote>
```

| Method | Output |
|--------|--------|
| `strip_tags()` | `A wise quote.` |
| HtmlToDjot+Profile | `> A wise quote.` |

### Code Block

**Input:**
```html
<pre><code>echo "Hello";</code></pre>
```

| Method | Output |
|--------|--------|
| `strip_tags()` | `echo "Hello";` |
| HtmlToDjot+Profile | `` `echo "Hello";` `` |

### Thematic Break

**Input:**
```html
<hr>
```

| Method | Output |
|--------|--------|
| `strip_tags()` | *(empty string)* |
| HtmlToDjot+Profile | `---` |

### Definition List

**Input:**
```html
<dl><dt>Term</dt><dd>Definition here</dd></dl>
```

| Method | Output |
|--------|--------|
| `strip_tags()` | `TermDefinition here` |
| HtmlToDjot+Profile | `Term`<br>`- Definition here` |

### Unordered List

**Input:**
```html
<ul><li>First</li><li>Second</li></ul>
```

| Method | Output |
|--------|--------|
| `strip_tags()` | `FirstSecond` |
| HtmlToDjot+Profile | `- First`<br>`- Second` |

### Ordered List

**Input:**
```html
<ol><li>First</li><li>Second</li><li>Third</li></ol>
```

| Method | Output |
|--------|--------|
| `strip_tags()` | `FirstSecondThird` |
| HtmlToDjot+Profile | `1. First`<br>`2. Second`<br>`3. Third` |

## Use Cases

### Email Plain-Text Version

```php
// Generate plain-text alternative for HTML emails
$plainText = htmlToPlainText($htmlEmail);
```

### Search Indexing

```php
// Extract readable text for search engines
$searchableText = htmlToPlainText($articleHtml);
```

### Content Preview

```php
// Generate preview snippets
$preview = mb_substr(htmlToPlainText($content), 0, 200) . '...';
```

### Accessibility / Screen Readers

```php
// Generate screen-reader friendly text summary
$accessibleText = htmlToPlainText($richContent);
```

## Performance Considerations

The HtmlToDjot approach involves parsing, which is slower than `strip_tags()`. For high-volume processing, consider caching:

```php
$cacheKey = 'plain_text_' . md5($html);
$plainText = $cache->get($cacheKey, fn() => htmlToPlainText($html));
```

## Summary

| Feature | `strip_tags()` | HtmlToDjot + Profile |
|---------|----------------|---------------------|
| Table cells | ❌ `NameType` (merged) | ✅ `Name \| Type` |
| Image alt text | ❌ *(lost)* | ✅ `[img: alt]` |
| Heading level | ❌ Plain text | ✅ `## Heading` |
| Blockquotes | ❌ No indicator | ✅ `> quote` |
| Code blocks | ⚠️ Plain text | ✅ `` `code` `` |
| Unordered lists | ❌ `FirstSecond` | ✅ `- First`, `- Second` |
| Ordered lists | ❌ `FirstSecond` | ✅ `1. First`, `2. Second` |
| Definition lists | ❌ `TermDef` | ✅ `Term` + `- Def` |
| Thematic breaks | ❌ *(lost)* | ✅ `---` |

For readable plain text output from HTML, the HtmlToDjot + Profile approach is significantly better.
