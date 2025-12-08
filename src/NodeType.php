<?php

declare(strict_types=1);

namespace Djot;

/**
 * Node type constants for use with Profile-based filtering
 */
final class NodeType
{
    // Block types
    /**
     * @var string
     */
    public const PARAGRAPH = 'paragraph';

    /**
     * @var string
     */
    public const HEADING = 'heading';

    /**
     * @var string
     */
    public const CODE_BLOCK = 'code_block';

    /**
     * @var string
     */
    public const BLOCKQUOTE = 'block_quote';

    /**
     * @var string
     */
    public const LIST_BLOCK = 'list';

    /**
     * @var string
     */
    public const LIST_ITEM = 'list_item';

    /**
     * @var string
     */
    public const TABLE = 'table';

    /**
     * @var string
     */
    public const TABLE_ROW = 'table_row';

    /**
     * @var string
     */
    public const TABLE_CELL = 'table_cell';

    /**
     * @var string
     */
    public const THEMATIC_BREAK = 'thematic_break';

    /**
     * @var string
     */
    public const DIV = 'div';

    /**
     * @var string
     */
    public const RAW_BLOCK = 'raw_block';

    /**
     * @var string
     */
    public const FOOTNOTE = 'footnote';

    /**
     * @var string
     */
    public const DEFINITION_LIST = 'definition_list';

    /**
     * @var string
     */
    public const DEFINITION_TERM = 'definition_term';

    /**
     * @var string
     */
    public const DEFINITION_DESCRIPTION = 'definition_description';

    /**
     * @var string
     */
    public const SECTION = 'section';

    /**
     * @var string
     */
    public const LINE_BLOCK = 'line_block';

    /**
     * @var string
     */
    public const COMMENT = 'comment';

    /**
     * @var string
     */
    public const FIGURE = 'figure';

    /**
     * @var string
     */
    public const CAPTION = 'caption';

    // Inline types
    /**
     * @var string
     */
    public const TEXT = 'text';

    /**
     * @var string
     */
    public const EMPHASIS = 'emphasis';

    /**
     * @var string
     */
    public const STRONG = 'strong';

    /**
     * @var string
     */
    public const CODE = 'code';

    /**
     * @var string
     */
    public const LINK = 'link';

    /**
     * @var string
     */
    public const IMAGE = 'image';

    /**
     * @var string
     */
    public const SOFT_BREAK = 'soft_break';

    /**
     * @var string
     */
    public const HARD_BREAK = 'hard_break';

    /**
     * @var string
     */
    public const RAW_INLINE = 'raw_inline';

    /**
     * @var string
     */
    public const FOOTNOTE_REF = 'footnote_ref';

    /**
     * @var string
     */
    public const SPAN = 'span';

    /**
     * @var string
     */
    public const SUPERSCRIPT = 'superscript';

    /**
     * @var string
     */
    public const SUBSCRIPT = 'subscript';

    /**
     * @var string
     */
    public const HIGHLIGHT = 'highlight';

    /**
     * @var string
     */
    public const INSERT = 'insert';

    /**
     * @var string
     */
    public const DELETE = 'delete';

    /**
     * @var string
     */
    public const SYMBOL = 'symbol';

    /**
     * @var string
     */
    public const MATH = 'math';

    /**
     * @return list<string>
     */
    public static function allBlockTypes(): array
    {
        return [
            self::PARAGRAPH,
            self::HEADING,
            self::CODE_BLOCK,
            self::BLOCKQUOTE,
            self::LIST_BLOCK,
            self::LIST_ITEM,
            self::TABLE,
            self::TABLE_ROW,
            self::TABLE_CELL,
            self::THEMATIC_BREAK,
            self::DIV,
            self::RAW_BLOCK,
            self::FOOTNOTE,
            self::DEFINITION_LIST,
            self::DEFINITION_TERM,
            self::DEFINITION_DESCRIPTION,
            self::SECTION,
            self::LINE_BLOCK,
            self::COMMENT,
            self::FIGURE,
            self::CAPTION,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allInlineTypes(): array
    {
        return [
            self::TEXT,
            self::EMPHASIS,
            self::STRONG,
            self::CODE,
            self::LINK,
            self::IMAGE,
            self::SOFT_BREAK,
            self::HARD_BREAK,
            self::RAW_INLINE,
            self::FOOTNOTE_REF,
            self::SPAN,
            self::SUPERSCRIPT,
            self::SUBSCRIPT,
            self::HIGHLIGHT,
            self::INSERT,
            self::DELETE,
            self::SYMBOL,
            self::MATH,
        ];
    }
}
