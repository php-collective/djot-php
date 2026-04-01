<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

use Djot\Node\ContentNodeInterface;

/**
 * Escaped text node for round-trip support
 *
 * Represents text that was escaped in the original Djot source (e.g., \* -> *)
 * This allows preserving the escape during round-trip conversion.
 */
class EscapedText extends InlineNode implements ContentNodeInterface
{
    public function __construct(
        protected string $content = '',
    ) {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function getType(): string
    {
        return 'escaped_text';
    }
}
