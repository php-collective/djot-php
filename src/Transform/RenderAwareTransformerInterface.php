<?php

declare(strict_types=1);

namespace Djot\Transform;

use Djot\Node\Document;
use Djot\Renderer\RendererInterface;

/**
 * Optional transformer hook for render-specific derived trees.
 */
interface RenderAwareTransformerInterface extends TransformerInterface
{
    public function transformForRenderer(Document $document, RendererInterface $renderer): Document;
}
