# Symfony Integration

This guide covers Symfony integration options for djot-php.

## Recommended: Symfony Djot Bundle

For most projects, use the official **[symfony-djot](https://github.com/php-collective/symfony-djot)** bundle which provides:

- Twig filters (`|djot`, `|djot_text`) and function (`djot()`)
- Service injection via `DjotConverterInterface`
- Multiple converter profiles with different configurations
- Form type (`DjotType`) for Djot-enabled form fields
- Validation constraint (`ValidDjot`)
- Output caching
- All djot-php extensions configurable via YAML

```bash
composer require php-collective/symfony-djot
```

```twig
{# Use immediately in templates #}
{{ article.body|djot }}
```

```yaml
# config/packages/symfony_djot.yaml
symfony_djot:
    converters:
        default:
            safe_mode: false
            extensions:
                - type: autolink
                - type: external_links
        user_content:
            safe_mode: true
```

See the [symfony-djot documentation](https://github.com/php-collective/symfony-djot) for full configuration options.

---

## Manual Integration

For advanced use cases or full control over the integration, you can set up djot-php manually.

### Installation

```bash
composer require php-collective/djot
```

### Basic Setup

### 1. Create the Converter Factory

The factory creates configured `DjotConverter` instances and allows extensions to be injected via Symfony's service container:

```php
// src/Djot/DjotConverterFactory.php
<?php

declare(strict_types=1);

namespace App\Djot;

use Djot\DjotConverter;
use Djot\Extension\ExtensionInterface;

final class DjotConverterFactory
{
    /**
     * @param iterable<ExtensionInterface> $extensions
     */
    public function __construct(
        private iterable $extensions = [],
        private bool $safeMode = true,
    ) {
    }

    public function __invoke(): DjotConverter
    {
        $converter = new DjotConverter(safeMode: $this->safeMode);

        foreach ($this->extensions as $extension) {
            $converter->addExtension($extension);
        }

        return $converter;
    }
}
```

### 2. Create the Twig Extension

```php
// src/Twig/DjotExtension.php
<?php

declare(strict_types=1);

namespace App\Twig;

use App\Djot\DjotConverterFactory;
use Djot\DjotConverter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class DjotExtension extends AbstractExtension
{
    private ?DjotConverter $converter = null;

    public function __construct(
        private DjotConverterFactory $converterFactory,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('djot_to_html', $this->convert(...), ['is_safe' => ['html']]),
        ];
    }

    public function convert(string $content): string
    {
        $this->converter ??= ($this->converterFactory)();

        return $this->converter->convert($content);
    }
}
```

### 3. Configure Services

```yaml
# config/services.yaml
services:
    # Auto-tag any Djot extension classes
    _instanceof:
        Djot\Extension\ExtensionInterface:
            tags: ['app.djot_extension']

    App\Djot\DjotConverterFactory:
        arguments:
            $extensions: !tagged app.djot_extension
            $safeMode: true

    App\Twig\DjotExtension:
        tags: ['twig.extension']
```

### 4. Use in Templates

```twig
{{ article.body|djot_to_html }}

{# Or with a variable #}
{% set content %}
# Hello World

This is *bold* and _italic_ text.
{% endset %}
{{ content|djot_to_html }}
```

## Adding Extensions

Djot-php includes several built-in extensions. To use them, register them as services:

```yaml
# config/services.yaml
services:
    Djot\Extension\ExternalLinksExtension:
        arguments:
            $openInNewTab: true
            $addNofollow: true
        tags: ['app.djot_extension']

    Djot\Extension\TableOfContentsExtension:
        tags: ['app.djot_extension']

    Djot\Extension\HeadingPermalinksExtension:
        tags: ['app.djot_extension']
```

Extensions are automatically injected into the factory via the `!tagged app.djot_extension` argument.

## Configuration Options

The `DjotConverter` constructor accepts many options. Some are simple scalars (configurable directly in YAML), while others are objects that must be injected as services.

| Option | Type | YAML scalar? |
|--------|------|--------------|
| `xhtml` | `bool` | ✓ |
| `warnings` | `bool` | ✓ |
| `strict` | `bool` | ✓ |
| `safeMode` | `bool` | ✓ |
| `safeMode` | `SafeMode` | ✗ (service) |
| `significantNewlines` | `bool` | ✓ |
| `roundTripMode` | `bool` | ✓ |
| `profile` | `Profile` | ✗ (service) |
| `softBreakMode` | `SoftBreakMode` | ✗ (service) |
| `parser` | `BlockParser` | ✗ (service) |
| `renderer` | `RendererInterface` | ✗ (service) |

### Simple Scalar Options

For boolean options, extend the factory constructor:

```php
// src/Djot/DjotConverterFactory.php
public function __construct(
    private iterable $extensions = [],
    private bool $safeMode = true,
    private bool $xhtml = false,
    private bool $warnings = false,
    private bool $strict = false,
    private bool $significantNewlines = false,
    private bool $roundTripMode = false,
) {
}

public function __invoke(): DjotConverter
{
    $converter = new DjotConverter(
        xhtml: $this->xhtml,
        warnings: $this->warnings,
        strict: $this->strict,
        safeMode: $this->safeMode,
        significantNewlines: $this->significantNewlines,
        roundTripMode: $this->roundTripMode,
    );

    foreach ($this->extensions as $extension) {
        $converter->addExtension($extension);
    }

    return $converter;
}
```

```yaml
# config/services.yaml
App\Djot\DjotConverterFactory:
    arguments:
        $extensions: !tagged app.djot_extension
        $safeMode: true
        $xhtml: false
        $warnings: false
        $strict: false
        $significantNewlines: false
        $roundTripMode: false
```

::: warning
Only disable safe mode when rendering content from trusted sources (e.g., admin users, your own database). Never disable it for user-submitted content.
:::

## Advanced Configuration

For object-typed options, define them as services and inject them into the factory.

### Full-Featured Factory

Here's a factory that supports all configuration options:

```php
// src/Djot/DjotConverterFactory.php
<?php

declare(strict_types=1);

namespace App\Djot;

use Djot\DjotConverter;
use Djot\Extension\ExtensionInterface;
use Djot\Parser\BlockParser;
use Djot\Profile;
use Djot\Renderer\RendererInterface;
use Djot\Renderer\SoftBreakMode;
use Djot\SafeMode;

final class DjotConverterFactory
{
    /**
     * @param iterable<ExtensionInterface> $extensions
     */
    public function __construct(
        private iterable $extensions = [],
        // Scalar options
        private bool $xhtml = false,
        private bool $warnings = false,
        private bool $strict = false,
        private bool $safeMode = true,
        private bool $significantNewlines = false,
        private bool $roundTripMode = false,
        // Object options (injected as services)
        private ?SafeMode $safeModeConfig = null,
        private ?Profile $profile = null,
        private ?SoftBreakMode $softBreakMode = null,
        private ?BlockParser $parser = null,
        private ?RendererInterface $renderer = null,
    ) {
    }

    public function __invoke(): DjotConverter
    {
        // Use SafeMode object if provided, otherwise use bool
        $safeMode = $this->safeModeConfig ?? $this->safeMode;

        $converter = new DjotConverter(
            xhtml: $this->xhtml,
            warnings: $this->warnings,
            strict: $this->strict,
            safeMode: $safeMode,
            profile: $this->profile,
            significantNewlines: $this->significantNewlines,
            softBreakMode: $this->softBreakMode,
            roundTripMode: $this->roundTripMode,
            parser: $this->parser,
            renderer: $this->renderer,
        );

        foreach ($this->extensions as $extension) {
            $converter->addExtension($extension);
        }

        return $converter;
    }
}
```

### Custom SafeMode

Configure SafeMode with custom settings:

```yaml
# config/services.yaml
services:
    # Use default safe mode settings
    app.djot.safe_mode.default:
        class: Djot\SafeMode
        factory: ['Djot\SafeMode', 'defaults']

    # Or create custom safe mode
    app.djot.safe_mode.custom:
        class: Djot\SafeMode
        factory: ['Djot\SafeMode', 'create']
        arguments:
            $blockedSchemes: ['javascript', 'vbscript', 'data']
            $blockedAttributes: ['onclick', 'onerror', 'onload']

    App\Djot\DjotConverterFactory:
        arguments:
            $extensions: !tagged app.djot_extension
            $safeModeConfig: '@app.djot.safe_mode.default'
```

### Profiles

Restrict available Djot features using profiles:

```yaml
# config/services.yaml
services:
    # Basic profile - minimal features
    app.djot.profile.basic:
        class: Djot\Profile
        factory: ['Djot\Profile', 'basic']

    # Standard profile - common features
    app.djot.profile.standard:
        class: Djot\Profile
        factory: ['Djot\Profile', 'standard']

    # Comments profile - for comment sections
    app.djot.profile.comments:
        class: Djot\Profile
        factory: ['Djot\Profile', 'comments']

    App\Djot\DjotConverterFactory:
        arguments:
            $extensions: !tagged app.djot_extension
            $safeMode: true
            $profile: '@app.djot.profile.comments'
```

### SoftBreakMode

Control how soft line breaks are rendered:

```yaml
# config/services.yaml
services:
    # Ignore soft breaks (default)
    app.djot.soft_break.ignore:
        class: Djot\Renderer\SoftBreakMode
        factory: ['Djot\Renderer\SoftBreakMode', 'from']
        arguments: ['ignore']

    # Render as <br>
    app.djot.soft_break.br:
        class: Djot\Renderer\SoftBreakMode
        factory: ['Djot\Renderer\SoftBreakMode', 'from']
        arguments: ['br']

    # Preserve as newlines
    app.djot.soft_break.preserve:
        class: Djot\Renderer\SoftBreakMode
        factory: ['Djot\Renderer\SoftBreakMode', 'from']
        arguments: ['preserve']

    App\Djot\DjotConverterFactory:
        arguments:
            $extensions: !tagged app.djot_extension
            $safeMode: true
            $softBreakMode: '@app.djot.soft_break.br'
```

### Custom Parser

Inject a pre-configured parser:

```yaml
# config/services.yaml
services:
    app.djot.parser:
        class: Djot\Parser\BlockParser
        arguments:
            $collectWarnings: true
            $strictMode: false
            $significantNewlines: true

    App\Djot\DjotConverterFactory:
        arguments:
            $extensions: !tagged app.djot_extension
            $safeMode: true
            $parser: '@app.djot.parser'
```

::: tip
When providing a custom parser, the `warnings`, `strict`, and `significantNewlines` scalar options are ignored—the parser's own configuration takes precedence.
:::

### Custom Renderer

Use a different renderer (e.g., Markdown, PlainText, ANSI):

```yaml
# config/services.yaml
services:
    # HTML renderer with XHTML output
    app.djot.renderer.html:
        class: Djot\Renderer\HtmlRenderer
        arguments:
            $xhtml: true

    # Markdown renderer
    app.djot.renderer.markdown:
        class: Djot\Renderer\MarkdownRenderer

    # Plain text renderer
    app.djot.renderer.plaintext:
        class: Djot\Renderer\PlainTextRenderer

    # ANSI renderer (for terminal output)
    app.djot.renderer.ansi:
        class: Djot\Renderer\AnsiRenderer

    App\Djot\DjotConverterFactory:
        arguments:
            $extensions: !tagged app.djot_extension
            $safeMode: true
            $renderer: '@app.djot.renderer.html'
```

::: tip
When providing a custom renderer, the `xhtml`, `safeMode`, `softBreakMode`, and `roundTripMode` scalar options are ignored—configure them on the renderer directly.
:::

## Custom Extensions with Services

If you create a custom extension that requires injected services, the factory pattern handles this cleanly:

```php
// src/Djot/Extension/MyEmbedExtension.php
<?php

namespace App\Djot\Extension;

use App\Service\EmbedService;
use Djot\DjotConverter;
use Djot\Extension\ExtensionInterface;

final class MyEmbedExtension implements ExtensionInterface
{
    public function __construct(
        private EmbedService $embedService,
    ) {
    }

    public function register(DjotConverter $converter): void
    {
        $converter->on('render.link', function ($event) {
            // Use $this->embedService to process embeds
        });
    }
}
```

```yaml
# config/services.yaml
services:
    App\Djot\Extension\MyEmbedExtension:
        arguments:
            $embedService: '@App\Service\EmbedService'
        tags: ['app.djot_extension']
```

The extension is autowired with its dependencies and automatically added to the converter.

## Multiple Converters

For different rendering contexts (e.g., full HTML vs. excerpt), create multiple factories:

```yaml
# config/services.yaml
services:
    App\Djot\DjotConverterFactory:
        arguments:
            $extensions: !tagged app.djot_extension
            $safeMode: true

    app.djot.excerpt_factory:
        class: App\Djot\DjotConverterFactory
        arguments:
            $extensions: !tagged app.djot_extension.excerpt
            $safeMode: true
```

Then create separate Twig filters or use different factories in your services.
