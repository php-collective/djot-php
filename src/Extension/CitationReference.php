<?php

declare(strict_types=1);

namespace Djot\Extension;

/**
 * Parsed experimental citation item.
 */
final readonly class CitationReference
{
    public const MODE_PARENTHESES = 'parenthetical';
    public const MODE_INTEGRAL = 'integral';
    public const MODE_SUPPRESS_AUTHOR = 'suppress-author';

    public function __construct(
        public string $key,
        public string $mode = self::MODE_PARENTHESES,
        public ?string $suffix = null,
    ) {
    }

    /**
     * @return array{key: string, mode: string, suffix?: string}
     */
    public function toArray(): array
    {
        $data = [
            'key' => $this->key,
            'mode' => $this->mode,
        ];

        if ($this->suffix !== null) {
            $data['suffix'] = $this->suffix;
        }

        return $data;
    }

    /**
     * @param array{key: string, mode?: string, suffix?: string|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['key'],
            $data['mode'] ?? self::MODE_PARENTHESES,
            $data['suffix'] ?? null,
        );
    }
}
