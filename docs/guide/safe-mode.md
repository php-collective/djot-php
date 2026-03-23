# Safe Mode

When processing untrusted user input, djot-php provides built-in XSS protection through Safe Mode.

## The Problem

By default, Djot allows several constructs that can be exploited for XSS attacks:

```djot
[Click me](javascript:alert('XSS'))

{onclick="alert('XSS')"}
This paragraph has an event handler.

```{=html}
<script>alert('XSS')</script>
```
```

## Enabling Safe Mode

Enable safe mode with sensible defaults:

```php
use Djot\DjotConverter;

$converter = new DjotConverter(safeMode: true);
$html = $converter->convert($userInput);
```

## What Safe Mode Blocks

| Threat | Default Mode | Safe Mode |
|--------|--------------|-----------|
| `javascript:` URLs | Allowed | Blocked |
| `vbscript:` URLs | Allowed | Blocked |
| `data:` URLs | Allowed | Blocked |
| `file:` URLs | Allowed | Blocked |
| Event handlers (`onclick`, etc.) | Allowed | Stripped |
| Raw HTML blocks | Allowed | Escaped |

Safe mode also normalizes URL schemes by stripping ASCII control characters and whitespace before comparison, preventing bypass attempts like `java\tscript:` or `java\x0bscript:`.

## Strict Mode

For maximum protection, use strict mode:

```php
use Djot\DjotConverter;
use Djot\SafeMode;

$converter = new DjotConverter(safeMode: SafeMode::strict());
```

Strict mode additionally:
- Strips raw HTML entirely (instead of escaping)
- Blocks all non-http(s) URL schemes
- Removes all custom attributes

## Custom Configuration

Configure Safe Mode for specific needs:

```php
use Djot\SafeMode;

$safeMode = new SafeMode(
    // URL schemes to block
    blockedSchemes: ['javascript', 'vbscript', 'data', 'file'],

    // Attributes to strip
    blockedAttributes: ['onclick', 'onload', 'onerror', 'onmouseover'],

    // How to handle raw HTML: 'escape', 'strip', or 'allow'
    rawHtmlHandling: 'escape',
);

$converter = new DjotConverter(safeMode: $safeMode);
```

## Alternative: HTMLPurifier

For maximum control, combine djot-php with [HTMLPurifier](http://htmlpurifier.org/):

```php
use Djot\DjotConverter;

$converter = new DjotConverter();
$html = $converter->convert($userInput);

$config = HTMLPurifier_Config::createDefault();
$config->set('HTML.Allowed', 'p,br,strong,em,a[href],ul,ol,li,code,pre');
$config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);

$purifier = new HTMLPurifier($config);
$safeHtml = $purifier->purify($html);
```

## Best Practices

1. **Always enable safe mode** for user-generated content
2. Use **strict mode** for comments and untrusted sources
3. Consider **HTMLPurifier** when you need fine-grained control
4. **Test your configuration** with known XSS payloads

## See Also

- [API Reference - SafeMode](/reference/api#safe-mode)
- [Profiles](./profiles) - Restrict features by context
