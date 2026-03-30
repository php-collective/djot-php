<?php

declare(strict_types=1);

namespace Djot\Transform;

use Djot\Node\Block\Heading;
use Djot\Node\Document;
use Djot\Node\Node;

/**
 * Returns a transformed copy of a document with shifted heading levels.
 */
class HeadingLevelShiftTransform implements TransformerInterface
{
    public function __construct(protected int $shift = 1)
    {
        $this->shift = max(0, min($shift, 5));
    }

    public function transform(Document $document): Document
    {
        $transformed = clone $document;
        if ($this->shift === 0) {
            return $transformed;
        }

        $this->walkAndShift($transformed);

        return $transformed;
    }

    protected function walkAndShift(Node $node): void
    {
        if ($node instanceof Heading) {
            $node->setLevel($node->getLevel() + $this->shift);
        }

        foreach ($node->getChildren() as $child) {
            $this->walkAndShift($child);
        }
    }
}
