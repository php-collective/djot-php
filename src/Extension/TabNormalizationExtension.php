<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Renderer\HtmlRenderer;
use InvalidArgumentException;

/**
 * Normalizes tabs in code output to a fixed number of spaces.
 *
 * djot preserves literal tabs (the spec-conformant default). A literal tab in
 * `<pre>` renders at the browser's default tab width (usually 8), which authors
 * rarely want. Enable this extension to convert tabs in code blocks and inline
 * code to a fixed number of spaces (default 4) for consistent display, without
 * relying on CSS `tab-size`.
 *
 * Only HTML output is affected; with other renderers the extension is a no-op.
 *
 * Example:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new TabNormalizationExtension()); // tabs -> 4 spaces
 * $converter->addExtension(new TabNormalizationExtension(2)); // tabs -> 2 spaces
 * ```
 */
class TabNormalizationExtension implements ExtensionInterface
{
    /**
     * @param int $width Spaces per tab in code output (must be >= 1)
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(protected int $width = 4)
    {
        if ($this->width < 1) {
            throw new InvalidArgumentException('Tab width must be >= 1, got ' . $this->width);
        }
    }

    public function register(DjotConverter $converter): void
    {
        $renderer = $converter->getRenderer();
        if ($renderer instanceof HtmlRenderer) {
            $renderer->setCodeBlockTabWidth($this->width);
        }
    }
}
