<?php

declare(strict_types=1);

namespace Djot\Extension;

final readonly class SocialLinkResolverInput
{
    /**
     * @param string $kind
     * @param string $name
     * @param array<string, string> $attributes
     * @param mixed $context
     */
    public function __construct(
        public string $kind,
        public string $name,
        public array $attributes,
        public mixed $context = null,
    ) {
    }
}
