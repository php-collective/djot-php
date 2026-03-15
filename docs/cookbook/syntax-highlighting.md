# Syntax Highlighting

This guide covers syntax highlighting for code blocks in Djot documents, including integration with popular highlighting libraries and the custom Djot language grammars provided by this project.

## Overview

Djot code blocks include an optional language identifier:

````djot
```php
echo "Hello World";
```
````

By default, djot-php renders this as:

```html
<pre><code class="language-php">echo "Hello World";
</code></pre>
```

The actual syntax highlighting is typically handled by a JavaScript library on the client side, or by a server-side highlighter. This page covers both approaches and the libraries you can use.

## Shiki

[Shiki](https://shiki.style/) is a modern syntax highlighter that uses TextMate grammars (the same as VS Code). It's the default highlighter for VitePress and Astro.

### Advantages

- **Accurate highlighting** — Uses VS Code's syntax engine
- **SSR-friendly** — Generates static HTML with inline styles, no client-side JS required
- **Theme support** — Works with any VS Code theme
- **Wide language support** — All languages VS Code supports

### VitePress Integration

VitePress uses Shiki by default. This documentation site uses a custom Djot grammar for highlighting Djot source code.

In `.vitepress/config.ts`:

```ts
import { defineConfig } from 'vitepress'
import { readFileSync } from 'fs'
import { resolve } from 'path'

const djotGrammar = JSON.parse(
  readFileSync(resolve(__dirname, 'grammars/djot.tmLanguage.json'), 'utf-8')
)

export default defineConfig({
  markdown: {
    languages: [
      {
        ...djotGrammar,
        name: 'djot',
        aliases: ['dj'],
      },
    ],
  },
})
```

### Standalone Shiki

For server-side highlighting in PHP applications, you can call Shiki via Node.js or use a PHP wrapper:

```php
// Using symfony/process to call Shiki CLI
use Symfony\Component\Process\Process;

function highlightWithShiki(string $code, string $lang): string
{
    $process = new Process([
        'npx', 'shiki', '--lang', $lang, '--theme', 'github-dark',
    ]);
    $process->setInput($code);
    $process->run();

    return $process->getOutput();
}
```

Or integrate via the event system to call an external highlighter:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\CodeBlock;

$converter = new DjotConverter();

$converter->on('render.code_block', function (RenderEvent $event) {
    $block = $event->getNode();
    if (!$block instanceof CodeBlock) {
        return;
    }

    $lang = $block->getLanguage();
    $code = $block->getContent();

    if ($lang) {
        // Call your highlighting service
        $highlighted = highlightWithShiki($code, $lang);
        $event->setHtml('<pre>' . $highlighted . '</pre>' . "\n");
    }
});
```

## highlight.js

[highlight.js](https://highlightjs.org/) is a popular client-side syntax highlighter with automatic language detection and a wide range of themes.

### Basic Setup

```html
<!-- Load highlight.js -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>

<!-- Load additional languages as needed -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/php.min.js"></script>

<!-- Initialize -->
<script>hljs.highlightAll();</script>
```

### PHP Integration

Add highlight.js-compatible classes to code blocks:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\CodeBlock;

$converter = new DjotConverter();

$converter->on('render.code_block', function (RenderEvent $event): void {
    $block = $event->getNode();
    if (!$block instanceof CodeBlock) {
        return;
    }

    $lang = $block->getLanguage();
    $code = htmlspecialchars($block->getContent(), ENT_QUOTES, 'UTF-8');

    if ($lang) {
        $html = '<pre><code class="language-' . htmlspecialchars($lang) . '">' . $code . '</code></pre>' . "\n";
    } else {
        $html = '<pre><code>' . $code . '</code></pre>' . "\n";
    }

    $event->setHtml($html);
});
```

### Dynamic Highlighting

For single-page applications or dynamic content, call highlight.js after rendering:

```js
// After inserting Djot-converted HTML into the DOM
document.querySelectorAll('pre code').forEach(el => {
    hljs.highlightElement(el);
});
```

### Supported Languages

highlight.js supports [190+ languages](https://highlightjs.org/supported-languages) out of the box.

## Prism.js

[Prism.js](https://prismjs.com/) is another popular client-side highlighter known for its plugin ecosystem.

### Basic Setup

```html
<!-- Load Prism -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>

<!-- Load languages -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js"></script>
```

### PHP Integration

Prism uses the same `language-*` class convention:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\CodeBlock;

$converter = new DjotConverter();

$converter->on('render.code_block', function (RenderEvent $event): void {
    $block = $event->getNode();
    if (!$block instanceof CodeBlock) {
        return;
    }

    $lang = $block->getLanguage();
    $code = htmlspecialchars($block->getContent(), ENT_QUOTES, 'UTF-8');

    if ($lang) {
        // Prism expects both pre and code to have the language class
        $langClass = 'language-' . htmlspecialchars($lang);
        $html = '<pre class="' . $langClass . '"><code class="' . $langClass . '">' . $code . '</code></pre>' . "\n";
    } else {
        $html = '<pre><code>' . $code . '</code></pre>' . "\n";
    }

    $event->setHtml($html);
});
```

### Plugins

Prism offers useful plugins:

- **Line Numbers** — Show line numbers
- **Line Highlight** — Highlight specific lines
- **Copy to Clipboard** — Add copy button
- **Diff Highlight** — Show code diffs

```html
<!-- Line numbers plugin -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.js"></script>
```

```php
// Add line-numbers class for the plugin
$converter->on('render.code_block', function (RenderEvent $event): void {
    $block = $event->getNode();
    if (!$block instanceof CodeBlock) {
        return;
    }

    $lang = $block->getLanguage();
    $code = htmlspecialchars($block->getContent(), ENT_QUOTES, 'UTF-8');

    $langClass = $lang ? 'language-' . htmlspecialchars($lang) : '';
    $html = '<pre class="line-numbers ' . $langClass . '"><code class="' . $langClass . '">' . $code . '</code></pre>' . "\n";

    $event->setHtml($html);
});
```

## Djot Language Grammars

This project provides syntax highlighting grammars for Djot source code itself, useful for documentation and editors.

### TextMate Grammar (Shiki/VS Code/Phiki)

The `djot.tmLanguage.json` grammar works with:
- **Shiki** — VitePress, Astro, and standalone
- **Phiki** — PHP syntax highlighter (Torchlight Engine)
- **VS Code** — Editor syntax highlighting
- **TextMate** — And compatible editors

The canonical source is included in this package at:

```
vendor/php-collective/djot/resources/grammars/djot.tmLanguage.json
```

This grammar is also used by:
- **IntelliJ/PhpStorm** — Via the [Djot plugin](https://plugins.jetbrains.com/plugin/18828-djot)
- **VS Code** — Via TextMate grammar support
- **VitePress/Shiki** — Fetched dynamically during `npm install`

#### Using with Phiki

[Phiki](https://github.com/phikiphp/phiki) is a PHP syntax highlighter that uses TextMate grammars, also used by [Torchlight Engine](https://github.com/torchlight-api/engine). To add Djot highlighting:

```php
use Phiki\Phiki;
use Phiki\Environment\Environment;

// Get the path to the Djot grammar included in this package
$grammarPath = dirname((new \ReflectionClass(\Djot\DjotConverter::class))->getFileName())
    . '/../resources/grammars/djot.tmLanguage.json';

// Create Phiki with the Djot grammar registered
$environment = Environment::default();
$environment->getGrammarRepository()->register('djot', $grammarPath);

$phiki = new Phiki($environment);
$html = $phiki->codeToHtml($djotCode, 'djot', 'github-light');
```

Or with Torchlight Engine, you can extend the Engine class to register the grammar.

### highlight.js Grammar

The `hljs-djot.js` grammar provides Djot highlighting for highlight.js.

The canonical source is in this repository:

<https://github.com/php-collective/djot-php/blob/master/docs/public/assets/hljs-djot.js>

#### Usage

```html
<!-- Load highlight.js -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>

<!-- Load the Djot language definition -->
<script src="path/to/hljs-djot.js"></script>

<!-- Initialize -->
<script>hljs.highlightAll();</script>
```

#### Supported Syntax

The highlight.js grammar supports the full Djot specification plus djot-php extensions:

**Block Elements:**
- Headings (`# Title`)
- Code fences (`` ``` `` with language)
- Div blocks (`:::`)
- Blockquotes (`>`)
- Lists (bullets and numbered)
- Task lists (`- [ ]`, `- [x]`)
- Definition lists (`: term`)
- Tables
- Line blocks (`|`)
- Horizontal rules
- Block attributes

**Inline Elements:**
- Strong (`*bold*`)
- Emphasis (`_italic_`)
- Highlight (`{=text=}`)
- Insert/Delete (`{+text+}`, `{-text-}`)
- Super/Subscript (`^text^`, `~text~`)
- Inline code
- Links and images
- Footnotes
- Math (`$\`code\`$`)
- Symbols (`:name:`)
- Spans with attributes

**djot-php Extensions:**
- Captions (`^ caption`)
- Fenced comments (`%%%`)
- Table row/cell attributes

#### Known Limitations

The highlight.js grammar covers core Djot syntax but has minor gaps compared to the TextMate grammar:

- **Frontmatter** — YAML metadata blocks not highlighted
- **Abbreviation definitions** — `*[ABBR]: text` not highlighted
- **Smart punctuation** — Em/en dashes, ellipsis not specially marked
- **Braced super/subscript** — Only `^text^` supported, not `{^text^}`

These are cosmetic limitations that don't affect the primary use case of highlighting Djot documents.

#### CSS Classes

The highlighter uses standard highlight.js classes:

| Element | CSS Class |
|---------|-----------|
| Headings | `hljs-section` |
| Strong | `hljs-strong` |
| Emphasis | `hljs-emphasis` |
| Highlight/Insert | `hljs-addition` |
| Delete | `hljs-deletion` |
| Code | `hljs-code` |
| Links/Images | `hljs-link` |
| URLs | `hljs-string` |
| Footnotes | `hljs-symbol` |
| Lists | `hljs-bullet` |
| Blockquotes | `hljs-quote` |
| Attributes | `hljs-attr` |
| Comments | `hljs-comment` |
| Math | `hljs-formula` |

## Comparison

| Feature | Shiki | Phiki | highlight.js | Prism.js |
|---------|-------|-------|--------------|----------|
| Rendering | Server-side | Server-side (PHP) | Client-side | Client-side |
| JS required | No | No | Yes | Yes |
| Languages | 200+ | 200+ | 190+ | 290+ |
| Themes | VS Code themes | VS Code themes | 90+ themes | 8 themes + community |
| Bundle size | N/A (SSR) | N/A (SSR) | ~40KB core | ~20KB core |
| Auto-detection | No | No | Yes | No |
| Plugins | Limited | Limited | Some | Extensive |
| Djot grammar | ✓ TextMate | ✓ TextMate | ✓ Custom | ✗ |

### Recommendations

- **VitePress/Astro sites** — Use Shiki (built-in)
- **PHP applications** — Use Phiki with the TextMate grammar from this package
- **Dynamic web apps** — Use highlight.js for auto-detection
- **Static sites with plugins** — Use Prism.js for line numbers, copy button, etc.
