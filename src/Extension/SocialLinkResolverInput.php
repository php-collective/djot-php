<?php

declare(strict_types=1);

namespace Djot\Extension;

final readonly class SocialLinkResolverInput
{
    /**
     * @param string $username
     * @param array<string, string> $attributes
     * @param mixed $context
     */
    public function __construct(
        public string $username,
        public array $attributes,
        public mixed $context = null,
    ) {
    }
}
