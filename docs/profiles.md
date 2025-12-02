# Profiles

Profiles provide feature restriction for different rendering contexts. They complement SafeMode (XSS prevention) by controlling which markup features are available.

## Quick Start

```php
use Djot\DjotConverter;
use Djot\Profile;

// User comments - basic formatting, nofollow links
$converter = new DjotConverter(profile: Profile::comment());

// Blog posts - all formatting, no raw HTML
$converter = new DjotConverter(profile: Profile::article());

// Chat messages - inline formatting and lists only
$converter = new DjotConverter(profile: Profile::minimal());

// Trusted content - everything allowed
$converter = new DjotConverter(profile: Profile::full());
```

## Built-in Profiles

### `Profile::full()`

All features enabled. Use only for trusted content like admin interfaces or backend documentation.

```php
$converter = new DjotConverter(profile: Profile::full());
```

### `Profile::article()`

For blog posts and articles. All formatting features except raw HTML.

**Allowed:** Headings, paragraphs, lists, tables, code blocks, images, links, footnotes, all inline formatting

**Denied:** Raw HTML blocks and inline (XSS prevention)

```php
$converter = new DjotConverter(profile: Profile::article());
```

### `Profile::comment()`

For user-generated content like comments. Basic formatting with nofollow links.

**Allowed:**
- Inline: text, emphasis, strong, code, strikethrough, line breaks
- Block: paragraphs, lists, blockquotes, code blocks

**Denied:** Headings, images, tables, footnotes, raw HTML, divs, definition lists

**Link policy:** Adds `rel="nofollow ugc"` to all links

```php
$converter = new DjotConverter(profile: Profile::comment());
```

### `Profile::minimal()`

For chat messages and micro-posts. All trivial inline formatting plus lists.

**Allowed:**
- Inline: text, emphasis, strong, code, delete, insert, highlight, superscript, subscript, line breaks
- Block: paragraphs, lists

**Denied:** Links, images, headings, tables, code blocks, raw HTML, footnotes

**Max nesting:** 2 levels

```php
$converter = new DjotConverter(profile: Profile::minimal());
```

## Custom Profiles

Create custom profiles for specific needs:

```php
use Djot\Profile;
use Djot\NodeType;
use Djot\LinkPolicy;

$profile = new Profile();
$profile
    ->allowInline([
        NodeType::TEXT,
        NodeType::EMPHASIS,
        NodeType::STRONG,
        NodeType::LINK,
    ])
    ->allowBlock([
        NodeType::PARAGRAPH,
        NodeType::LIST_BLOCK,
        NodeType::LIST_ITEM,
    ])
    ->setLinkPolicy(
        LinkPolicy::unrestricted()
            ->addRelAttribute('nofollow')
    )
    ->setMaxNesting(3)
    ->setMaxLength(10000);

$converter = new DjotConverter(profile: $profile);
```

## Deny Lists

Instead of allowlists, use deny lists to block specific features:

```php
$profile = new Profile();
$profile
    ->denyInline([NodeType::RAW_INLINE])
    ->denyBlock([NodeType::RAW_BLOCK, NodeType::TABLE]);
```

## Handling Disallowed Elements

Three options for what happens when disallowed markup is encountered:

```php
// Convert to plain text (default - safest for UX)
$profile->onDisallowed(Profile::ACTION_TO_TEXT);

// Strip completely from output
$profile->onDisallowed(Profile::ACTION_STRIP);

// Throw exception
$profile->onDisallowed(Profile::ACTION_ERROR);
```

Example with `ACTION_TO_TEXT`:
```php
$converter = new DjotConverter(profile: Profile::minimal());
$html = $converter->convert('Check [this link](https://example.com)!');
// Output: <p>Check this link!</p>
// Link converted to text, URL removed
```

## Link Policies

Control how links are rendered:

```php
use Djot\LinkPolicy;

// Add nofollow to all links
$policy = LinkPolicy::unrestricted()
    ->addRelAttribute('nofollow')
    ->addRelAttribute('ugc');

// Only allow specific domains
$policy = LinkPolicy::create()
    ->allowDomains(['example.com', 'docs.example.com']);

// Block specific domains
$policy = LinkPolicy::create()
    ->denyDomains(['spam.com', 'malware.site']);

// Block dangerous schemes
$policy = LinkPolicy::create()
    ->denySchemes(['javascript', 'data', 'file']);

$profile = new Profile();
$profile->setLinkPolicy($policy);
```

## Feature Reasons

Provide user-friendly explanations for why features are disabled:

```php
$profile = Profile::comment();

// Get reason for specific feature
$reason = $profile->getReasonDisallowed(NodeType::IMAGE);
// "Images are disabled to prevent spam, inappropriate content, and bandwidth abuse."

// Set custom reason
$profile->setFeatureReason(
    NodeType::TABLE,
    'Tables are not supported in comments. Please use lists instead.'
);
```

## Combining with SafeMode

Profile (feature restriction) and SafeMode (XSS prevention) work together:

```php
$converter = new DjotConverter(
    profile: Profile::comment(),  // Feature restriction
    safeMode: true,               // XSS prevention
);
```

- **Profile:** "What features are allowed?"
- **SafeMode:** "How do we prevent XSS in allowed features?"

## Node Types Reference

### Inline Types

| Type | Description |
|------|-------------|
| `NodeType::TEXT` | Plain text |
| `NodeType::EMPHASIS` | `_italic_` |
| `NodeType::STRONG` | `*bold*` |
| `NodeType::CODE` | `` `code` `` |
| `NodeType::LINK` | `[text](url)` |
| `NodeType::IMAGE` | `![alt](url)` |
| `NodeType::DELETE` | `{-deleted-}` |
| `NodeType::INSERT` | `{+inserted+}` |
| `NodeType::HIGHLIGHT` | `{=marked=}` |
| `NodeType::SUPERSCRIPT` | `{^super^}` |
| `NodeType::SUBSCRIPT` | `{~sub~}` |
| `NodeType::SPAN` | `[text]{.class}` |
| `NodeType::SYMBOL` | `:symbol:` |
| `NodeType::MATH` | `$math$` |
| `NodeType::RAW_INLINE` | `` `<b>`{=html} `` |
| `NodeType::FOOTNOTE_REF` | `[^note]` |
| `NodeType::SOFT_BREAK` | Line break (soft) |
| `NodeType::HARD_BREAK` | Line break (hard) |

### Block Types

| Type | Description |
|------|-------------|
| `NodeType::PARAGRAPH` | Paragraph |
| `NodeType::HEADING` | `# Heading` |
| `NodeType::CODE_BLOCK` | Fenced code block |
| `NodeType::BLOCKQUOTE` | `> quote` |
| `NodeType::LIST_BLOCK` | List container |
| `NodeType::LIST_ITEM` | List item |
| `NodeType::TABLE` | Table |
| `NodeType::DIV` | `::: div` |
| `NodeType::SECTION` | Section wrapper |
| `NodeType::THEMATIC_BREAK` | `---` |
| `NodeType::RAW_BLOCK` | Raw HTML block |
| `NodeType::FOOTNOTE` | Footnote definition |
| `NodeType::DEFINITION_LIST` | Definition list |
| `NodeType::DEFINITION_TERM` | Definition term |
| `NodeType::DEFINITION_DESCRIPTION` | Definition description |
| `NodeType::LINE_BLOCK` | Line block |
