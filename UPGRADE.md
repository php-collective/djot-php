# Upgrade Guide

## Upgrading to 0.1.29

### Behavior change: tabs in code are preserved by default

Tabs in code blocks and inline code are now **preserved literally** instead of being converted to 4 spaces. This is spec-conformant (djot keeps tabs).

If you relied on the previous 4-space conversion, restore it explicitly:

```php
// Per renderer
$converter->getHtmlRenderer()->setCodeBlockTabWidth(4);

// Or via the opt-in extension
use Djot\Extension\TabNormalizationExtension;
$converter->addExtension(new TabNormalizationExtension()); // 4 spaces (configurable)
```

Note: a literal tab in `<pre>` renders at the browser's default tab width (usually 8), so enabling one of the above is recommended if you want a fixed width.

## Upgrading to 0.1.23

### Breaking Change: `significantNewlines` No Longer Sets `SoftBreakMode::Break`

Previously, enabling `significantNewlines` (either via constructor or `DjotConverter::withSignificantNewlines()`) would automatically set the soft break mode to `SoftBreakMode::Break`, rendering soft breaks as `<br>` tags.

These two features are now independent:

- **`significantNewlines`** (parser option): Controls whether block elements can interrupt paragraphs without blank lines (markdown-like behavior)
- **`SoftBreakMode`** (renderer option): Controls how soft breaks are rendered (`\n`, space, or `<br>`)

#### Migration

If your code relies on `withSignificantNewlines()` rendering soft breaks as `<br>`, you need to explicitly set the soft break mode:

**Before:**
```php
// This used to render soft breaks as <br>
$converter = DjotConverter::withSignificantNewlines();
```

**After:**
```php
use Djot\DjotConverter;
use Djot\Renderer\SoftBreakMode;

// Explicitly request <br> for soft breaks
$converter = DjotConverter::withSignificantNewlines(
    softBreakMode: SoftBreakMode::Break,
);

// Or using the constructor:
$converter = new DjotConverter(
    significantNewlines: true,
    softBreakMode: SoftBreakMode::Break,
);
```

#### Rationale

The two features serve different purposes:

1. **Significant newlines mode** is for markdown compatibility - allowing lists, blockquotes, and headings to interrupt paragraphs without requiring blank lines.

2. **Soft break mode** is for controlling line break visibility - useful for poetry, chat messages, or anywhere users expect pressing Enter to create a visible line break.

Bundling them together was confusing because:
- The factory method name `withSignificantNewlines()` didn't suggest it also changed soft break rendering
- Users wanting markdown-style block interruption didn't necessarily want visible soft breaks (or vice versa)
