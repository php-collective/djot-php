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

    /**
     * @return array{message: string, line: int, column: int}
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'line' => $this->line,
            'column' => $this->column,
        ];
    }

    public function __toString(): string
    {
        return sprintf('%s at line %d, column %d', $this->message, $this->line, $this->column);
    }
}
