<?php

declare(strict_types=1);

namespace Djot\Renderer;

/**
 * Defines how soft breaks (single newlines in source) are rendered
 */
enum SoftBreakMode: string
{
    /**
     * Render as newline character in HTML source (default for HtmlRenderer)
     * Not visible in browser since HTML collapses whitespace
     */
    case Newline = 'newline';

    /**
     * Render as a space character
     * Not visible in browser (same as newline due to whitespace collapsing)
     */
    case Space = 'space';

    /**
     * Render as <br> tag (visible line break in browser)
     * Useful for poetry, addresses, or when preserving line breaks matters
     */
    case Break = 'br';
}
