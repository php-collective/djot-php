<?php

declare(strict_types=1);

namespace Djot\Node\Block;

use Djot\Node\Node;

/**
 * Table container
 */
class Table extends BlockNode
{
    protected ?string $caption = null;

    /**
     * @var array<\Djot\Node\Node>
     */
    protected array $captionChildren = [];

    public function getType(): string
    {
        return 'table';
    }

    public function setCaption(string $caption): void
    {
        $this->caption = $caption;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function addCaptionChild(Node $node): void
    {
        $this->captionChildren[] = $node;
    }

    /**
     * @return array<\Djot\Node\Node>
     */
    public function getCaptionChildren(): array
    {
        return $this->captionChildren;
    }

    public function hasCaptionChildren(): bool
    {
        return !empty($this->captionChildren);
    }
}
