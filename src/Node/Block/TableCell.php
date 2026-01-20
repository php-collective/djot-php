<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Table cell
 */
class TableCell extends BlockNode
{
    /**
     * @var string
     */
    public const ALIGN_DEFAULT = 'default';

    /**
     * @var string
     */
    public const ALIGN_LEFT = 'left';

    /**
     * @var string
     */
    public const ALIGN_CENTER = 'center';

    /**
     * @var string
     */
    public const ALIGN_RIGHT = 'right';

    public function __construct(
        protected bool $isHeader = false,
        protected string $alignment = self::ALIGN_DEFAULT,
        protected int $rowspan = 1,
        protected int $colspan = 1,
    ) {
    }

    public function isHeader(): bool
    {
        return $this->isHeader;
    }

    public function getAlignment(): string
    {
        return $this->alignment;
    }

    public function getRowspan(): int
    {
        return $this->rowspan;
    }

    public function setRowspan(int $rowspan): void
    {
        $this->rowspan = $rowspan;
    }

    public function getColspan(): int
    {
        return $this->colspan;
    }

    public function setColspan(int $colspan): void
    {
        $this->colspan = $colspan;
    }

    public function getType(): string
    {
        return 'table_cell';
    }
}
