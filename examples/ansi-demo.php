#!/usr/bin/env php
<?php

/**
 * ANSI Renderer Demo
 *
 * Run this script in a terminal to see colorized Djot output:
 *   php examples/ansi-demo.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Djot\DjotConverter;
use Djot\Renderer\AnsiRenderer;

$djot = <<<'DJOT'
# Welcome to Djot

Djot is a lightweight markup language with _emphasis_, *strong*, and `inline code`.

## Features

- {=Highlighted text=} for important notes
- {+Inserted+} and {-deleted-} text
- Superscript: E=mc{^2^}
- Subscript: H{~2~}O
- Symbols: I :heart: Djot!

## Code Example

```php
$converter = new DjotConverter();
echo $converter->convert($djot);
```

## Links and Images

Visit [the Djot website](https://djot.net) for more info.

![A sample image](photo.jpg)

## Blockquotes

> Djot is designed to be parsed without backtracking,
> making it fast and predictable.
>
> — John MacFarlane

## Tables

| Feature      | Markdown | Djot |
|--------------|----------|------|
| Highlighting | No       | Yes  |
| Attributes   | No       | Yes  |
| Consistent   | No       | Yes  |

## Definition List

: Djot
  A modern markup language designed for clarity.

: Markdown
  The predecessor with many flavors.

## Task List

- [ ] Learn Djot syntax
- [x] Install djot-php
- [ ] Build something awesome

---

## Footnotes

Here's a statement with a footnote[^1].

[^1]: This is the footnote content.
DJOT;

$converter = new DjotConverter();
$renderer = new AnsiRenderer();

// Parse and render
$document = $converter->parse($djot);
$output = $renderer->render($document);

echo $output;
