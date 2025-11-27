<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Definition term (dt)
 */
class DefinitionTerm extends BlockNode
{
    public function getType(): string
    {
        return 'definition_term';
    }
}
