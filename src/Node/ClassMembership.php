<?php

declare(strict_types=1);

namespace Djot\Node;

/**
 * @internal
 */
final class ClassMembership
{
    public bool $needsNormalization = true;

    /**
     * @param array<string, true> $members
     */
    public function __construct(public array $members)
    {
    }
}
