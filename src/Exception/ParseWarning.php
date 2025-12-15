<?php

declare(strict_types=1);

namespace Djot\Exception;

/**
 * Represents a non-fatal parsing warning
 */
class ParseWarning
{
    public function __construct(
        protected string $message,
        protected int $line,
        protected int $column = 1,
        protected ?string $category = null,
        protected ?string $suggestion = null,
    ) {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    public function getColumn(): int
    {
        return $this->column;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }

    /**
     * @return array{message: string, line: int, column: int, category: string|null, suggestion: string|null}
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'line' => $this->line,
            'column' => $this->column,
            'category' => $this->category,
            'suggestion' => $this->suggestion,
        ];
    }

    public function __toString(): string
    {
        $str = sprintf('%s at line %d, column %d', $this->message, $this->line, $this->column);
        if ($this->category !== null) {
            $str = "[{$this->category}] " . $str;
        }

        return $str;
    }
}
