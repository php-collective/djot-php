# Symfony Integration

This guide shows how to integrate djot-php with Symfony and Twig, providing a `djot_to_html` filter for your templates.

## Installation

```bash
composer require php-collective/djot
```

## Basic Setup

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

### Disabling Safe Mode

Safe mode is enabled by default to protect against XSS when rendering untrusted content. For trusted content only:

```yaml
App\Djot\DjotConverterFactory:
    arguments:
        $extensions: !tagged app.djot_extension
        $safeMode: false
```

::: warning
Only disable safe mode when rendering content from trusted sources (e.g., admin users, your own database). Never disable it for user-submitted content.
:::

### XHTML Output

For XHTML-compatible output:

```php
// src/Djot/DjotConverterFactory.php
public function __construct(
    private iterable $extensions = [],
    private bool $safeMode = true,
    private bool $xhtml = false,
) {
}

public function __invoke(): DjotConverter
{
    $converter = new DjotConverter(
        safeMode: $this->safeMode,
        xhtml: $this->xhtml,
    );
    // ...
}
```

```yaml
App\Djot\DjotConverterFactory:
    arguments:
        $extensions: !tagged app.djot_extension
        $safeMode: true
        $xhtml: true
```

## Advanced: Custom Extensions with Services

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
