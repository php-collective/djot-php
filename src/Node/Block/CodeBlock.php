<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Fenced code block
 */
class CodeBlock extends BlockNode
{
    /**
     * @param string $content The code content
     * @param string|null $language The language identifier (e.g., 'php', 'js')
     * @param bool $showLineNumbers Whether to display line numbers
     * @param int $lineNumberStart Starting line number (default 1)
     * @param array<int> $highlightLines Line numbers to highlight
     */
    public function __construct(
        protected string $content = '',
        protected ?string $language = null,
        protected bool $showLineNumbers = false,
        protected int $lineNumberStart = 1,
        protected array $highlightLines = [],
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

    public function appendContent(string $content): void
    {
        $this->content .= $content;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function showLineNumbers(): bool
    {
        return $this->showLineNumbers;
    }

    public function setShowLineNumbers(bool $show): void
    {
        $this->showLineNumbers = $show;
    }

    public function getLineNumberStart(): int
    {
        return $this->lineNumberStart;
    }

    public function setLineNumberStart(int $start): void
    {
        $this->lineNumberStart = $start;
    }

    /**
     * @return array<int>
     */
    public function getHighlightLines(): array
    {
        return $this->highlightLines;
    }

    /**
     * @param array<int> $lines
     */
    public function setHighlightLines(array $lines): void
    {
        $this->highlightLines = $lines;
    }

    public function getType(): string
    {
        return 'code_block';
    }
}
