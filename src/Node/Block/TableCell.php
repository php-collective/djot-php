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

    public function getType(): string
    {
        return 'table_cell';
    }
}
