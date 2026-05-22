<?php

declare(strict_types=1);

namespace Djot\Extension;

/**
 * Parsed experimental citation group, e.g. [@a; -@b, p. 10].
 */
final readonly class CitationGroup
{
    public function __construct(
        public string $id,
        public string $source,
        /**
         * @var list<\Djot\Extension\CitationReference>
         */
        public array $references,
    ) {
    }

    /**
     * @return array{id: string, source: string, references: list<array{key: string, mode: string, suffix?: string}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'references' => array_map(
                static fn (CitationReference $reference): array => $reference->toArray(),
                $this->references,
            ),
        ];
    }

    /**
     * @param array{id: string, source: string, references: list<array{key: string, mode?: string, suffix?: string|null}>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['source'],
            array_map(
                static fn (array $reference): CitationReference => CitationReference::fromArray($reference),
                $data['references'],
            ),
        );
    }
}
