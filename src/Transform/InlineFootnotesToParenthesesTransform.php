<?php

declare(strict_types=1);

namespace Djot\Transform;

use Djot\Node\Document;
use Djot\Node\Inline\Span;
use Djot\Node\Inline\Text;
use Djot\Node\Node;

/**
 * Rewrites inline footnote spans into explicit parenthetical inline content.
 */
class InlineFootnotesToParenthesesTransform implements TransformerInterface
{
    public function __construct(protected string $cssClass = 'fn')
    {
    }

    public function transform(Document $document): Document
    {
        $transformed = clone $document;
        $this->walk($transformed);

        return $transformed;
    }

    protected function walk(Node $node): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Span && $child->hasClass($this->cssClass)) {
                $replacementChildren = array_values([new Text(' ('), ...$child->getChildren(), new Text(')')]);
                $node->replaceChildWithMany($child, $replacementChildren);

                continue;
            }

            $this->walk($child);
        }
    }
}
