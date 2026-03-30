<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Table container
 */
class Table extends BlockNode
{
    protected ?Caption $caption = null;

    /**
     * Original separator widths for round-trip preservation
     *
     * @var array<int>|null
     */
    protected ?array $separatorWidths = null;

    public function getType(): string
    {
        return 'table';
    }

    public function setCaption(Caption $caption): void
    {
        $this->caption = $caption;
    }

    public function getCaption(): ?Caption
    {
        return $this->caption;
    }

    public function hasCaption(): bool
    {
        return $this->caption !== null;
    }

    /**
     * Set the original separator widths from parsing
     *
     * @param array<int> $widths Array of separator widths per column
     */
    public function setSeparatorWidths(array $widths): void
    {
        $this->separatorWidths = $widths;
    }

    /**
     * Get the original separator widths
     *
     * @return array<int>|null Array of widths or null if not set
     */
    public function getSeparatorWidths(): ?array
    {
        return $this->separatorWidths;
    }
}
