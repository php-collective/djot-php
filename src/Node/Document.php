<?php

declare(strict_types=1);

namespace Djot\Node;

/**
 * Root document node
 */
class Document extends Node
{
    /**
     * Abbreviation definitions for round-trip support
     *
     * @var array<string, string>
     */
    protected array $abbreviations = [];

    /**
     * Byte length of the original source the document was parsed from.
     *
     * Used to scale render-time DoS budgets (e.g. abbreviation expansion).
     * Defaults to 0 for documents built directly rather than parsed.
     */
    protected int $sourceLength = 0;

    public function getType(): string
    {
        return 'document';
    }

    /**
     * Get the byte length of the original parsed source.
     */
    public function getSourceLength(): int
    {
        return $this->sourceLength;
    }

    /**
     * Set the byte length of the original parsed source.
     */
    public function setSourceLength(int $sourceLength): void
    {
        $this->sourceLength = $sourceLength;
    }

    /**
     * Get abbreviation definitions
     *
     * @return array<string, string>
     */
    public function getAbbreviations(): array
    {
        return $this->abbreviations;
    }

    /**
     * Set abbreviation definitions
     *
     * @param array<string, string> $abbreviations
     */
    public function setAbbreviations(array $abbreviations): void
    {
        $this->abbreviations = $abbreviations;
    }
}
