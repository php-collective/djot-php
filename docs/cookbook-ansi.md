# AnsiRenderer Cookbook

Common recipes and customizations for the AnsiRenderer.

## Table of Contents

- [Basic Usage](#basic-usage)
- [Configuration Options](#configuration-options)
- [CLI Documentation Viewer](#cli-documentation-viewer)
- [Plain Text Fallback](#plain-text-fallback)
- [Custom Color Schemes](#custom-color-schemes)
- [Terminal Width Detection](#terminal-width-detection)
- [Pager Integration](#pager-integration)
- [Help Text Generation](#help-text-generation)
- [ASCII-Only Mode](#ascii-only-mode)

## Basic Usage

The AnsiRenderer converts Djot AST to ANSI-formatted text for terminal display:

```php
use Djot\DjotConverter;
use Djot\Renderer\AnsiRenderer;

$converter = new DjotConverter();
$renderer = new AnsiRenderer();

$djot = '# Welcome

This is *bold* and _italic_ text with `inline code`.

- Item one
- Item two';

$document = $converter->parse($djot);
echo $renderer->render($document);
```

This produces colorized output with:
- Magenta bold heading with underline
- Bold text for strong emphasis
- Italic text for emphasis
- Yellow highlighted inline code
- Cyan bullet points

## Configuration Options

The AnsiRenderer supports three configuration options:

```php
use Djot\Renderer\AnsiRenderer;

// Constructor parameters
$renderer = new AnsiRenderer(
    terminalWidth: 120,  // For line wrapping (default: 80)
    useColors: true,     // ANSI color codes (default: true)
    useUnicode: true,    // Unicode bullets/boxes (default: true)
);

// Or fluent setters
$renderer = new AnsiRenderer();
$renderer
    ->setTerminalWidth(100)
    ->setUseColors(true)
    ->setUseUnicode(true);
```

### Terminal Width

Controls the width used for thematic breaks:

```php
$renderer = new AnsiRenderer(terminalWidth: 60);
```

### Use Colors

When disabled, outputs plain text without ANSI escape codes:

```php
$renderer = new AnsiRenderer(useColors: false);
```

### Use Unicode

When disabled, uses ASCII alternatives for bullets, checkboxes, and table borders:

```php
$renderer = new AnsiRenderer(useUnicode: false);
```

Unicode enabled:
```
• Item with bullet
☑ Completed task
☐ Pending task
┌───────┬───────┐
│ Col 1 │ Col 2 │
└───────┴───────┘
```

Unicode disabled:
```
* Item with bullet
[x] Completed task
[ ] Pending task
+---------------+
| Col 1 | Col 2 |
+---------------+
```

## CLI Documentation Viewer

Build a documentation viewer for the terminal:

```php
use Djot\DjotConverter;
use Djot\Renderer\AnsiRenderer;

function showDocs(string $docPath): void
{
    $converter = new DjotConverter();
    $renderer = new AnsiRenderer(
        terminalWidth: (int) exec('tput cols') ?: 80,
    );

    $djot = file_get_contents($docPath);
    $document = $converter->parse($djot);
    echo $renderer->render($document);
}

// Usage
showDocs('docs/README.djot');
```

## Plain Text Fallback

For environments without ANSI support, disable colors and Unicode:

```php
use Djot\DjotConverter;
use Djot\Renderer\AnsiRenderer;

function renderForTerminal(string $djot): string
{
    $converter = new DjotConverter();

    // Detect if terminal supports colors
    $supportsColors = getenv('TERM') !== 'dumb'
        && stream_isatty(STDOUT);

    $renderer = new AnsiRenderer(
        useColors: $supportsColors,
        useUnicode: $supportsColors,
    );

    $document = $converter->parse($djot);
    return $renderer->render($document);
}
```

## Custom Color Schemes

Create different visual themes by extending AnsiRenderer:

```php
use Djot\Node\Block\Heading;
use Djot\Renderer\AnsiRenderer;

class DarkThemeRenderer extends AnsiRenderer
{
    protected function renderHeading(Heading $node): string
    {
        $level = $node->getLevel();
        $content = $this->renderChildren($node);

        // Custom colors for dark terminals
        $color = match ($level) {
            1 => self::FG_BRIGHT_GREEN,
            2 => self::FG_BRIGHT_YELLOW,
            3 => self::FG_BRIGHT_BLUE,
            default => self::FG_WHITE,
        };

        $styled = $this->style($content, self::BOLD . $color);

        if ($level <= 2) {
            $underlineChar = $this->useUnicode ? '━' : '=';
            $underline = str_repeat($underlineChar, mb_strlen($content));
            $styled .= "\n" . $this->style($underline, $color);
        }

        return $styled . "\n\n";
    }
}

$renderer = new DarkThemeRenderer();
```

### Minimal Theme

A subdued color scheme:

```php
use Djot\Node\Inline\Code;
use Djot\Renderer\AnsiRenderer;

class MinimalRenderer extends AnsiRenderer
{
    protected function renderCode(Code $node): string
    {
        // Use dim instead of bright yellow
        return $this->style($node->getContent(), self::DIM);
    }
}
```

## Terminal Width Detection

Automatically detect terminal width:

```php
use Djot\DjotConverter;
use Djot\Renderer\AnsiRenderer;

function getTerminalWidth(): int
{
    // Try tput first
    $width = (int) @exec('tput cols 2>/dev/null');
    if ($width > 0) {
        return $width;
    }

    // Try stty
    $output = @exec('stty size 2>/dev/null');
    if ($output) {
        $parts = explode(' ', $output);
        if (isset($parts[1])) {
            return (int) $parts[1];
        }
    }

    // Check environment variable
    if (isset($_SERVER['COLUMNS'])) {
        return (int) $_SERVER['COLUMNS'];
    }

    // Default
    return 80;
}

$renderer = new AnsiRenderer(terminalWidth: getTerminalWidth());
```

## Pager Integration

Pipe output to `less` for long documents:

```php
use Djot\DjotConverter;
use Djot\Renderer\AnsiRenderer;

function viewWithPager(string $djot): void
{
    $converter = new DjotConverter();
    $renderer = new AnsiRenderer();

    $document = $converter->parse($djot);
    $output = $renderer->render($document);

    // Open pipe to less with ANSI support
    $pager = popen('less -R', 'w');
    if ($pager) {
        fwrite($pager, $output);
        pclose($pager);
    } else {
        // Fallback to direct output
        echo $output;
    }
}

// Usage
$longDoc = file_get_contents('very-long-document.djot');
viewWithPager($longDoc);
```

## Help Text Generation

Generate CLI help text from Djot:

```php
use Djot\DjotConverter;
use Djot\Renderer\AnsiRenderer;

function showHelp(): void
{
    $converter = new DjotConverter();
    $renderer = new AnsiRenderer();

    $help = <<<'DJOT'
# myapp - A sample application

## Usage

`myapp [options] <command>`

## Commands

: init
  Initialize a new project

: build
  Build the project

: deploy
  Deploy to production

## Options

| Option | Description |
|--------|-------------|
| `-h, --help` | Show help |
| `-v, --verbose` | Verbose output |
| `-q, --quiet` | Suppress output |

## Examples

```bash
myapp init my-project
myapp build --verbose
myapp deploy --env production
```
DJOT;

    $document = $converter->parse($help);
    echo $renderer->render($document);
}
```

## ASCII-Only Mode

For legacy terminals or logging:

```php
use Djot\DjotConverter;
use Djot\Renderer\AnsiRenderer;

function renderAsciiOnly(string $djot): string
{
    $converter = new DjotConverter();
    $renderer = new AnsiRenderer(
        useColors: false,
        useUnicode: false,
    );

    $document = $converter->parse($djot);
    return $renderer->render($document);
}

$djot = <<<'DJOT'
# Task List

- [x] Done
- [ ] Todo

## Table

| A | B |
|---|---|
| 1 | 2 |
DJOT;

echo renderAsciiOnly($djot);
```

Output:
```
Task List
=========

[x] Done
[ ] Todo

Table
-----

+-------+
| A | B |
+-------+
| 1 | 2 |
+-------+
```

## Supported Features

The AnsiRenderer supports all Djot elements:

| Element | Rendering |
|---------|-----------|
| Headings | Colored + underline (h1/h2) |
| Bold/Strong | ANSI bold |
| Italic/Emphasis | ANSI italic |
| Inline code | Yellow text |
| Code blocks | Indented with language header |
| Links | Blue underlined + URL in parentheses |
| Images | `[img: alt text]` |
| Lists | Cyan bullets (•) or yellow numbers |
| Task lists | Checkboxes (☑/☐) |
| Tables | Unicode box drawing |
| Blockquotes | Cyan vertical bars (│) |
| Highlights | Reverse video yellow |
| Insert | Green underlined |
| Delete | Red strikethrough |
| Superscript | Unicode (²) |
| Subscript | Unicode (₂) |
| Symbols | Emoji conversion |
| Thematic breaks | Horizontal line |
| Footnotes | Cyan markers |

## ANSI Code Reference

Available constants in AnsiRenderer:

```php
// Styles
AnsiRenderer::RESET       // Reset all styles
AnsiRenderer::BOLD        // Bold text
AnsiRenderer::DIM         // Dimmed text
AnsiRenderer::ITALIC      // Italic text
AnsiRenderer::UNDERLINE   // Underlined text
AnsiRenderer::STRIKETHROUGH // Strikethrough
AnsiRenderer::REVERSE     // Reverse video

// Foreground colors
AnsiRenderer::FG_BLACK, FG_RED, FG_GREEN, FG_YELLOW
AnsiRenderer::FG_BLUE, FG_MAGENTA, FG_CYAN, FG_WHITE
AnsiRenderer::FG_BRIGHT_BLACK, FG_BRIGHT_RED, etc.

// Background colors
AnsiRenderer::BG_BLACK, BG_RED, BG_GREEN, etc.

// Box drawing (Unicode)
AnsiRenderer::BOX_HORIZONTAL  // ─
AnsiRenderer::BOX_VERTICAL    // │
AnsiRenderer::BOX_TOP_LEFT    // ┌
AnsiRenderer::BOX_CROSS       // ┼
// etc.

// Markers
AnsiRenderer::BULLET           // •
AnsiRenderer::CHECKBOX_CHECKED // ☑
AnsiRenderer::CHECKBOX_UNCHECKED // ☐
```
