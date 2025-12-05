<?php

declare(strict_types=1);

namespace Djot\Renderer;

use Djot\Node\Document;

/**
 * Interface for renderers that convert a Document AST to string output
 */
interface RendererInterface
{
    public function render(Document $document): string;
}
