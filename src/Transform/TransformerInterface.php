<?php

declare(strict_types=1);

namespace Djot\Transform;

use Djot\Node\Document;

interface TransformerInterface
{
    public function transform(Document $document): Document;
}
