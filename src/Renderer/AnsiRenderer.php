<?php

declare(strict_types=1);

namespace Djot\Renderer;

use Djot\Node\Block\BlockQuote;
use Djot\Node\Block\Caption;
use Djot\Node\Block\CodeBlock;
use Djot\Node\Block\Comment;
use Djot\Node\Block\DefinitionDescription;
use Djot\Node\Block\DefinitionList;
use Djot\Node\Block\DefinitionTerm;
use Djot\Node\Block\Div;
use Djot\Node\Block\Figure;
use Djot\Node\Block\Footnote;
use Djot\Node\Block\Heading;
use Djot\Node\Block\LineBlock;
use Djot\Node\Block\ListBlock;
use Djot\Node\Block\ListItem;
use Djot\Node\Block\Paragraph;
use Djot\Node\Block\RawBlock;
use Djot\Node\Block\Section;
use Djot\Node\Block\Table;
use Djot\Node\Block\TableCell;
use Djot\Node\Block\TableRow;
use Djot\Node\Block\ThematicBreak;
use Djot\Node\Document;
use Djot\Node\Inline\Abbreviation;
use Djot\Node\Inline\Code;
use Djot\Node\Inline\Delete;
use Djot\Node\Inline\Emphasis;
use Djot\Node\Inline\FootnoteRef;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Highlight;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\Insert;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Math;
use Djot\Node\Inline\RawInline;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Span;
use Djot\Node\Inline\Strong;
use Djot\Node\Inline\Subscript;
use Djot\Node\Inline\Superscript;
use Djot\Node\Inline\Symbol;
use Djot\Node\Inline\Text;
use Djot\Node\Node;

/**
 * Renders AST to ANSI-formatted terminal output
 *
 * Produces colored, styled text suitable for display in terminals
 * that support ANSI escape codes.
 */
class AnsiRenderer implements RendererInterface
{
    // ANSI escape codes
    /**
     * @var string
     */
    public const RESET = "\033[0m";

    /**
     * @var string
     */
    public const BOLD = "\033[1m";

    /**
     * @var string
     */
    public const DIM = "\033[2m";

    /**
     * @var string
     */
    public const ITALIC = "\033[3m";

    /**
     * @var string
     */
    public const UNDERLINE = "\033[4m";

    /**
     * @var string
     */
    public const STRIKETHROUGH = "\033[9m";

    /**
     * @var string
     */
    public const REVERSE = "\033[7m";

    // Foreground colors
    /**
     * @var string
     */
    public const FG_BLACK = "\033[30m";

    /**
     * @var string
     */
    public const FG_RED = "\033[31m";

    /**
     * @var string
     */
    public const FG_GREEN = "\033[32m";

    /**
     * @var string
     */
    public const FG_YELLOW = "\033[33m";

    /**
     * @var string
     */
    public const FG_BLUE = "\033[34m";

    /**
     * @var string
     */
    public const FG_MAGENTA = "\033[35m";

    /**
     * @var string
     */
    public const FG_CYAN = "\033[36m";

    /**
     * @var string
     */
    public const FG_WHITE = "\033[37m";

    /**
     * @var string
     */
    public const FG_BRIGHT_BLACK = "\033[90m";

    /**
     * @var string
     */
    public const FG_BRIGHT_RED = "\033[91m";

    /**
     * @var string
     */
    public const FG_BRIGHT_GREEN = "\033[92m";

    /**
     * @var string
     */
    public const FG_BRIGHT_YELLOW = "\033[93m";

    /**
     * @var string
     */
    public const FG_BRIGHT_BLUE = "\033[94m";

    /**
     * @var string
     */
    public const FG_BRIGHT_MAGENTA = "\033[95m";

    /**
     * @var string
     */
    public const FG_BRIGHT_CYAN = "\033[96m";

    /**
     * @var string
     */
    public const FG_BRIGHT_WHITE = "\033[97m";

    // Background colors
    /**
     * @var string
     */
    public const BG_BLACK = "\033[40m";

    /**
     * @var string
     */
    public const BG_RED = "\033[41m";

    /**
     * @var string
     */
    public const BG_GREEN = "\033[42m";

    /**
     * @var string
     */
    public const BG_YELLOW = "\033[43m";

    /**
     * @var string
     */
    public const BG_BLUE = "\033[44m";

    /**
     * @var string
     */
    public const BG_MAGENTA = "\033[45m";

    /**
     * @var string
     */
    public const BG_CYAN = "\033[46m";

    /**
     * @var string
     */
    public const BG_WHITE = "\033[47m";

    /**
     * @var string
     */
    public const BG_BRIGHT_BLACK = "\033[100m";

    // Unicode box drawing characters
    /**
     * @var string
     */
    public const BOX_HORIZONTAL = '─';

    /**
     * @var string
     */
    public const BOX_VERTICAL = '│';

    /**
     * @var string
     */
    public const BOX_TOP_LEFT = '┌';

    /**
     * @var string
     */
    public const BOX_TOP_RIGHT = '┐';

    /**
     * @var string
     */
    public const BOX_BOTTOM_LEFT = '└';

    /**
     * @var string
     */
    public const BOX_BOTTOM_RIGHT = '┘';

    /**
     * @var string
     */
    public const BOX_T_DOWN = '┬';

    /**
     * @var string
     */
    public const BOX_T_UP = '┴';

    /**
     * @var string
     */
    public const BOX_T_RIGHT = '├';

    /**
     * @var string
     */
    public const BOX_T_LEFT = '┤';

    /**
     * @var string
     */
    public const BOX_CROSS = '┼';

    // List markers
    /**
     * @var string
     */
    public const BULLET = '•';

    /**
     * @var string
     */
    public const CHECKBOX_UNCHECKED = '☐';

    /**
     * @var string
     */
    public const CHECKBOX_CHECKED = '☑';

    protected int $listDepth = 0;

    protected int $blockQuoteDepth = 0;

    protected int $terminalWidth = 80;

    protected bool $useColors = true;

    protected bool $useUnicode = true;

    /**
     * @var array<int, int>
     */
    protected array $orderedListCounters = [];

    public function __construct(int $terminalWidth = 80, bool $useColors = true, bool $useUnicode = true)
    {
        $this->terminalWidth = $terminalWidth;
        $this->useColors = $useColors;
        $this->useUnicode = $useUnicode;
    }

    /**
     * Set terminal width for wrapping
     */
    public function setTerminalWidth(int $width): self
    {
        $this->terminalWidth = $width;

        return $this;
    }

    /**
     * Enable or disable ANSI colors
     */
    public function setUseColors(bool $useColors): self
    {
        $this->useColors = $useColors;

        return $this;
    }

    /**
     * Enable or disable Unicode characters (bullets, box drawing)
     */
    public function setUseUnicode(bool $useUnicode): self
    {
        $this->useUnicode = $useUnicode;

        return $this;
    }

    public function render(Document $document): string
    {
        $output = $this->renderChildren($document);

        // Normalize multiple blank lines
        $output = preg_replace("/\n{3,}/", "\n\n", $output) ?? $output;

        return trim($output) . "\n";
    }

    protected function renderNode(Node $node): string
    {
        return match (true) {
            $node instanceof Document => $this->renderChildren($node),
            $node instanceof Paragraph => $this->renderParagraph($node),
            $node instanceof Heading => $this->renderHeading($node),
            $node instanceof CodeBlock => $this->renderCodeBlock($node),
            $node instanceof Caption => $this->renderCaption($node),
            $node instanceof Comment => '', // Skip comments
            $node instanceof Figure => $this->renderFigure($node),
            $node instanceof RawBlock => $this->renderRawBlock($node),
            $node instanceof Section => $this->renderChildren($node),
            $node instanceof BlockQuote => $this->renderBlockQuote($node),
            $node instanceof ListBlock => $this->renderList($node),
            $node instanceof ListItem => $this->renderListItem($node),
            $node instanceof DefinitionList => $this->renderDefinitionList($node),
            $node instanceof DefinitionTerm => $this->renderDefinitionTerm($node),
            $node instanceof DefinitionDescription => $this->renderDefinitionDescription($node),
            $node instanceof ThematicBreak => $this->renderThematicBreak(),
            $node instanceof Div => $this->renderDiv($node),
            $node instanceof Table => $this->renderTable($node),
            $node instanceof LineBlock => $this->renderLineBlock($node),
            $node instanceof Footnote => $this->renderFootnote($node),
            $node instanceof Text => $node->getContent(),
            $node instanceof Abbreviation => $this->renderAbbreviation($node),
            $node instanceof Emphasis => $this->renderEmphasis($node),
            $node instanceof Strong => $this->renderStrong($node),
            $node instanceof Code => $this->renderCode($node),
            $node instanceof Link => $this->renderLink($node),
            $node instanceof Image => $this->renderImage($node),
            $node instanceof HardBreak => "\n",
            $node instanceof SoftBreak => ' ',
            $node instanceof Superscript => $this->renderSuperscript($node),
            $node instanceof Subscript => $this->renderSubscript($node),
            $node instanceof Highlight => $this->renderHighlight($node),
            $node instanceof Insert => $this->renderInsert($node),
            $node instanceof Delete => $this->renderDelete($node),
            $node instanceof Span => $this->renderChildren($node),
            $node instanceof Math => $this->renderMath($node),
            $node instanceof Symbol => $this->renderSymbol($node),
            $node instanceof FootnoteRef => $this->renderFootnoteRef($node),
            $node instanceof RawInline => '', // Skip raw inline
            default => $this->renderChildren($node),
        };
    }

    protected function renderChildren(Node $node): string
    {
        $output = '';
        foreach ($node->getChildren() as $child) {
            $output .= $this->renderNode($child);
        }

        return $output;
    }

    protected function renderParagraph(Paragraph $node): string
    {
        $content = $this->renderChildren($node);
        $prefix = $this->getBlockQuotePrefix();

        if ($prefix !== '') {
            $content = $this->prefixLines($content, $prefix);
        }

        return $content . "\n\n";
    }

    protected function renderHeading(Heading $node): string
    {
        $level = $node->getLevel();
        $content = $this->renderChildren($node);

        // Color based on level
        $color = match ($level) {
            1 => self::FG_BRIGHT_MAGENTA,
            2 => self::FG_BRIGHT_CYAN,
            3 => self::FG_BRIGHT_BLUE,
            4 => self::FG_BRIGHT_GREEN,
            5 => self::FG_BRIGHT_YELLOW,
            default => self::FG_WHITE,
        };

        $styled = $this->style($content, self::BOLD . $color);

        // Add underline for h1 and h2
        if ($level <= 2) {
            $underlineChar = $level === 1 ? '═' : '─';
            if (!$this->useUnicode) {
                $underlineChar = $level === 1 ? '=' : '-';
            }
            $underline = str_repeat($underlineChar, mb_strlen($content));
            $styled .= "\n" . $this->style($underline, $color);
        }

        return $styled . "\n\n";
    }

    protected function renderCodeBlock(CodeBlock $node): string
    {
        $content = $node->getContent();
        $lang = $node->getLanguage();

        $lines = explode("\n", rtrim($content));
        $output = '';

        // Header with language
        if ($lang !== null && $lang !== '') {
            $header = $this->useUnicode
                ? self::BOX_TOP_LEFT . str_repeat(self::BOX_HORIZONTAL, 2) . ' ' . $lang . ' '
                : '--- ' . $lang . ' ';
            $output .= $this->style($header, self::DIM) . "\n";
        }

        // Code content with background
        foreach ($lines as $line) {
            $styledLine = $this->style('  ' . $line, self::FG_BRIGHT_WHITE);
            $output .= $styledLine . "\n";
        }

        return $output . "\n";
    }

    protected function renderBlockQuote(BlockQuote $node): string
    {
        $this->blockQuoteDepth++;
        $content = $this->renderChildren($node);
        $this->blockQuoteDepth--;

        return $content;
    }

    protected function getBlockQuotePrefix(): string
    {
        if ($this->blockQuoteDepth === 0) {
            return '';
        }

        $bar = $this->useUnicode ? '│' : '|';
        $prefix = str_repeat($this->style($bar, self::FG_CYAN . self::DIM) . ' ', $this->blockQuoteDepth);

        return $prefix;
    }

    protected function prefixLines(string $content, string $prefix): string
    {
        $lines = explode("\n", $content);
        $prefixed = [];
        foreach ($lines as $line) {
            $prefixed[] = $prefix . $line;
        }

        return implode("\n", $prefixed);
    }

    protected function renderList(ListBlock $node): string
    {
        $this->listDepth++;

        if ($node->getListType() === ListBlock::TYPE_ORDERED) {
            $this->orderedListCounters[$this->listDepth] = $node->getStart();
        }

        $output = $this->renderChildren($node);
        $this->listDepth--;

        if ($this->listDepth === 0) {
            $output .= "\n";
        }

        return $output;
    }

    protected function renderListItem(ListItem $node): string
    {
        $indent = str_repeat('  ', $this->listDepth - 1);
        $parent = $node->getParent();

        if ($parent instanceof ListBlock && $parent->getListType() === ListBlock::TYPE_ORDERED) {
            $num = $this->orderedListCounters[$this->listDepth] ?? 1;
            $marker = $this->style($num . '.', self::FG_YELLOW);
            $this->orderedListCounters[$this->listDepth] = $num + 1;
        } else {
            $bullet = $this->useUnicode ? self::BULLET : '*';
            $marker = $this->style($bullet, self::FG_CYAN);
        }

        $content = trim($this->renderChildren($node));

        // Handle task list items
        if ($node->isTask()) {
            $checkbox = $node->getChecked()
                ? $this->style($this->useUnicode ? self::CHECKBOX_CHECKED : '[x]', self::FG_GREEN)
                : $this->style($this->useUnicode ? self::CHECKBOX_UNCHECKED : '[ ]', self::FG_BRIGHT_BLACK);
            $marker = $checkbox;
        }

        return $indent . $marker . ' ' . $content . "\n";
    }

    protected function renderDefinitionList(DefinitionList $node): string
    {
        return $this->renderChildren($node) . "\n";
    }

    protected function renderDefinitionTerm(DefinitionTerm $node): string
    {
        $content = $this->renderChildren($node);

        return $this->style($content, self::BOLD . self::FG_YELLOW) . "\n";
    }

    protected function renderDefinitionDescription(DefinitionDescription $node): string
    {
        $content = trim($this->renderChildren($node));

        return '  ' . $content . "\n";
    }

    protected function renderThematicBreak(): string
    {
        $char = $this->useUnicode ? '─' : '-';
        $line = str_repeat($char, min(40, $this->terminalWidth - 4));

        return $this->style($line, self::DIM) . "\n\n";
    }

    protected function renderDiv(Div $node): string
    {
        return $this->renderChildren($node);
    }

    protected function renderTable(Table $node): string
    {
        // First pass: calculate column widths
        $colWidths = [];
        $rows = [];

        foreach ($node->getChildren() as $row) {
            if (!$row instanceof TableRow) {
                continue;
            }

            $cells = [];
            $colIndex = 0;
            foreach ($row->getChildren() as $cell) {
                if (!$cell instanceof TableCell) {
                    continue;
                }
                $content = trim($this->renderChildren($cell));
                // Strip ANSI codes for width calculation
                $plainContent = preg_replace('/\033\[[0-9;]*m/', '', $content) ?? $content;
                $width = mb_strlen($plainContent);
                $colWidths[$colIndex] = max($colWidths[$colIndex] ?? 0, $width);
                $cells[] = ['content' => $content, 'plain' => $plainContent, 'isHeader' => $row->isHeader()];
                $colIndex++;
            }
            $rows[] = $cells;
        }

        // Second pass: render table
        $output = '';
        $isFirstRow = true;
        $headerRendered = false;

        foreach ($rows as $rowIndex => $cells) {
            // Top border for first row
            if ($isFirstRow) {
                $output .= $this->renderTableBorder($colWidths, 'top');
                $isFirstRow = false;
            }

            // Render row
            $output .= $this->renderTableRow($cells, $colWidths);

            // Separator after header
            if (isset($cells[0]['isHeader']) && $cells[0]['isHeader'] && !$headerRendered) {
                $output .= $this->renderTableBorder($colWidths, 'middle');
                $headerRendered = true;
            }
        }

        // Bottom border
        $output .= $this->renderTableBorder($colWidths, 'bottom');

        // Table caption
        if ($node->hasCaption()) {
            /** @var \Djot\Node\Block\Caption $caption */
            $caption = $node->getCaption();
            $output .= $this->renderCaption($caption);
        }

        return $output . "\n";
    }

    /**
     * @param array<int, int> $colWidths
     * @param string $position
     */
    protected function renderTableBorder(array $colWidths, string $position): string
    {
        if (!$this->useUnicode) {
            $total = array_sum($colWidths) + (count($colWidths) * 3) + 1;

            return '+' . str_repeat('-', $total - 2) . "+\n";
        }

        $left = match ($position) {
            'top' => self::BOX_TOP_LEFT,
            'middle' => self::BOX_T_RIGHT,
            'bottom' => self::BOX_BOTTOM_LEFT,
            default => self::BOX_T_RIGHT,
        };

        $right = match ($position) {
            'top' => self::BOX_TOP_RIGHT,
            'middle' => self::BOX_T_LEFT,
            'bottom' => self::BOX_BOTTOM_RIGHT,
            default => self::BOX_T_LEFT,
        };

        $cross = match ($position) {
            'top' => self::BOX_T_DOWN,
            'middle' => self::BOX_CROSS,
            'bottom' => self::BOX_T_UP,
            default => self::BOX_CROSS,
        };

        $parts = [];
        foreach ($colWidths as $width) {
            $parts[] = str_repeat(self::BOX_HORIZONTAL, $width + 2);
        }

        return $this->style($left . implode($cross, $parts) . $right, self::DIM) . "\n";
    }

    /**
     * @param array<int, array{content: string, plain: string, isHeader: bool}> $cells
     * @param array<int, int> $colWidths
     */
    protected function renderTableRow(array $cells, array $colWidths): string
    {
        $separator = $this->useUnicode ? self::BOX_VERTICAL : '|';
        $styledSep = $this->style($separator, self::DIM);

        $parts = [];
        foreach ($cells as $index => $cell) {
            $width = $colWidths[$index] ?? 0;
            $padding = $width - mb_strlen($cell['plain']);
            $content = $cell['content'] . str_repeat(' ', $padding);

            if ($cell['isHeader']) {
                $content = $this->style($cell['plain'] . str_repeat(' ', $padding), self::BOLD);
            }

            $parts[] = ' ' . $content . ' ';
        }

        return $styledSep . implode($styledSep, $parts) . $styledSep . "\n";
    }

    protected function renderLineBlock(LineBlock $node): string
    {
        return $this->renderChildren($node) . "\n";
    }

    protected function renderFootnote(Footnote $node): string
    {
        $label = $node->getLabel();
        $content = trim($this->renderChildren($node));
        $marker = $this->style('[' . $label . ']', self::FG_CYAN . self::DIM);

        return $marker . ' ' . $content . "\n";
    }

    protected function renderEmphasis(Emphasis $node): string
    {
        return $this->style($this->renderChildren($node), self::ITALIC);
    }

    protected function renderStrong(Strong $node): string
    {
        return $this->style($this->renderChildren($node), self::BOLD);
    }

    protected function renderCode(Code $node): string
    {
        $content = $node->getContent();

        return $this->style($content, self::FG_BRIGHT_YELLOW);
    }

    protected function renderLink(Link $node): string
    {
        $text = $this->renderChildren($node);
        $url = $node->getDestination();

        // OSC 8 hyperlink support (for terminals that support it)
        // Format: \033]8;;URL\033\\TEXT\033]8;;\033\\
        $styled = $this->style($text, self::UNDERLINE . self::FG_BLUE);

        if ($url !== null && $url !== '' && $url !== $text) {
            $styled .= $this->style(' (' . $url . ')', self::DIM);
        }

        return $styled;
    }

    protected function renderImage(Image $node): string
    {
        $alt = $node->getAlt();
        $marker = $this->style('[img:', self::FG_MAGENTA);
        $altText = $alt !== '' ? ' ' . $alt : '';

        return $marker . $altText . $this->style(']', self::FG_MAGENTA);
    }

    protected function renderSuperscript(Superscript $node): string
    {
        $content = $this->renderChildren($node);
        // Use Unicode superscript characters if possible
        if ($this->useUnicode) {
            return $this->toSuperscript($content);
        }

        return '^' . $content;
    }

    protected function renderSubscript(Subscript $node): string
    {
        $content = $this->renderChildren($node);
        // Use Unicode subscript characters if possible
        if ($this->useUnicode) {
            return $this->toSubscript($content);
        }

        return '_' . $content;
    }

    protected function renderHighlight(Highlight $node): string
    {
        return $this->style($this->renderChildren($node), self::REVERSE . self::FG_YELLOW);
    }

    protected function renderInsert(Insert $node): string
    {
        return $this->style($this->renderChildren($node), self::FG_GREEN . self::UNDERLINE);
    }

    protected function renderDelete(Delete $node): string
    {
        return $this->style($this->renderChildren($node), self::STRIKETHROUGH . self::FG_RED);
    }

    protected function renderMath(Math $node): string
    {
        $content = $node->getContent();

        return $this->style($content, self::FG_BRIGHT_MAGENTA);
    }

    protected function renderSymbol(Symbol $node): string
    {
        $name = $node->getName();

        // Common emoji mappings
        $emoji = match ($name) {
            'heart' => '❤',
            'star' => '★',
            'check' => '✓',
            'x' => '✗',
            'warning' => '⚠',
            'info' => 'ℹ',
            'arrow_right' => '→',
            'arrow_left' => '←',
            'arrow_up' => '↑',
            'arrow_down' => '↓',
            'sunny' => '☀',
            'cloud' => '☁',
            'thumbsup' => '👍',
            'thumbsdown' => '👎',
            'smile' => '😊',
            'sad' => '😢',
            default => ':' . $name . ':',
        };

        return $this->useUnicode ? $emoji : ':' . $name . ':';
    }

    protected function renderFootnoteRef(FootnoteRef $node): string
    {
        $label = $node->getLabel();

        return $this->style('[' . $label . ']', self::FG_CYAN . self::BOLD);
    }

    protected function renderRawBlock(RawBlock $node): string
    {
        // Show raw blocks dimmed
        $format = $node->getFormat();
        $content = $node->getContent();

        return $this->style('[raw:' . $format . '] ' . $content, self::DIM) . "\n\n";
    }

    protected function renderFigure(Figure $node): string
    {
        $output = '';

        foreach ($node->getChildren() as $child) {
            if ($child instanceof Caption) {
                // Render caption after content, styled as italic
                $output .= $this->renderCaption($child);
            } else {
                $output .= $this->renderNode($child) . "\n";
            }
        }

        return $output;
    }

    protected function renderCaption(Caption $node): string
    {
        $content = trim($this->renderChildren($node));

        return $this->style($content, self::ITALIC . self::DIM) . "\n\n";
    }

    protected function renderAbbreviation(Abbreviation $node): string
    {
        $text = $this->renderChildren($node);
        $title = $node->getTitle();

        // Show abbreviation with definition in parentheses
        return $text . $this->style(' (' . $title . ')', self::DIM);
    }

    /**
     * Apply ANSI styling if colors are enabled
     */
    protected function style(string $text, string $codes): string
    {
        if (!$this->useColors) {
            return $text;
        }

        return $codes . $text . self::RESET;
    }

    /**
     * Convert text to Unicode superscript characters
     */
    protected function toSuperscript(string $text): string
    {
        $map = [
            '0' => '⁰',
            '1' => '¹',
            '2' => '²',
            '3' => '³',
            '4' => '⁴',
            '5' => '⁵',
            '6' => '⁶',
            '7' => '⁷',
            '8' => '⁸',
            '9' => '⁹',
            '+' => '⁺',
            '-' => '⁻',
            '=' => '⁼',
            '(' => '⁽',
            ')' => '⁾',
            'n' => 'ⁿ',
            'i' => 'ⁱ',
        ];

        return strtr($text, $map);
    }

    /**
     * Convert text to Unicode subscript characters
     */
    protected function toSubscript(string $text): string
    {
        $map = [
            '0' => '₀',
            '1' => '₁',
            '2' => '₂',
            '3' => '₃',
            '4' => '₄',
            '5' => '₅',
            '6' => '₆',
            '7' => '₇',
            '8' => '₈',
            '9' => '₉',
            '+' => '₊',
            '-' => '₋',
            '=' => '₌',
            '(' => '₍',
            ')' => '₎',
        ];

        return strtr($text, $map);
    }
}
