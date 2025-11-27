<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Djot\DjotConverter;

$djot = <<<'DJOT'
# Djot PHP Parser Demo

This is a *proof of concept* implementation of a _Djot_ parser in PHP.

## Features Demonstrated

### Inline Formatting

- *Strong* text with asterisks
- _Emphasized_ text with underscores
- `Inline code` with backticks
- {=Highlighted=} text
- {+Inserted+} and {-deleted-} text
- Superscript: E=mc^2^
- Subscript: H~2~O

### Links and Images

Visit [Djot website](https://djot.net) for more information.

Or use an autolink: <https://github.com>

Email: <user@example.com>

![Example image](example.png)

### Code Blocks

```php
<?php
$converter = new DjotConverter();
$html = $converter->convert($djotText);
echo $html;
```

### Block Quotes

> "Djot is a light markup language"
> -- John MacFarlane

### Lists

Unordered:
- First item
- Second item
- Third item

Ordered:
1. Step one
2. Step two
3. Step three

Task list:
- [x] Create parser
- [x] Create renderer
- [ ] Add more features

### Tables

| Feature       | Status    | Notes           |
|:--------------|:---------:|----------------:|
| Paragraphs    | Done      | Basic support   |
| Headings      | Done      | Levels 1-6      |
| Code blocks   | Done      | With language   |
| Lists         | Done      | All types       |
| Tables        | Done      | With alignment  |

### Divs (Generic Containers)

::: warning
This is a warning message in a div with class "warning".
:::

### Smart Typography

- Smart quotes: "Hello," she said. 'Nice!'
- En-dash: 2020--2024
- Em-dash: wait---what?
- Ellipsis: and so on...

---

That's all folks!
DJOT;

$converter = new DjotConverter();
$html = $converter->convert($djot);

// Output as a complete HTML page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Djot PHP Parser Demo</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem;
            color: #333;
        }
        h1, h2, h3 { color: #2c3e50; }
        code {
            background: #f4f4f4;
            padding: 0.2em 0.4em;
            border-radius: 3px;
            font-family: 'Fira Code', monospace;
        }
        pre {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 1rem;
            border-radius: 5px;
            overflow-x: auto;
        }
        pre code {
            background: none;
            padding: 0;
            color: inherit;
        }
        blockquote {
            border-left: 4px solid #3498db;
            margin: 1rem 0;
            padding: 0.5rem 1rem;
            background: #ecf0f1;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            margin: 1rem 0;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 0.5rem;
        }
        th { background: #f4f4f4; }
        mark { background: #fff3cd; padding: 0.1em 0.2em; }
        ins { background: #d4edda; text-decoration: none; }
        del { background: #f8d7da; }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 1rem;
            border-radius: 5px;
            margin: 1rem 0;
        }
        .task-list { list-style: none; padding-left: 0; }
        .task-list li { display: flex; align-items: center; gap: 0.5rem; }
        hr { border: none; border-top: 2px solid #eee; margin: 2rem 0; }
        a { color: #3498db; }
    </style>
</head>
<body>
<?= $html ?>
</body>
</html>
