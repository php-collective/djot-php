# Upgrade Guide

## Upgrading to 1.x (Unreleased)

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
