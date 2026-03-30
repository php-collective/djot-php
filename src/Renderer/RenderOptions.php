<?php

declare(strict_types=1);

namespace Djot\Renderer;

/**
 * Shared per-converter render options used by renderers.
 */
class RenderOptions
{
    /**
     * Shift rendered heading levels without mutating the AST.
     */
    public int $headingLevelShift = 0;
}
