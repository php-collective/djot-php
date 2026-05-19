<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Image
 */
class Image extends InlineNode
{
    /**
     * The reference label if this image was created from a reference image
     * like ![alt][ref] or ![alt][]. Null for inline images.
     */
    protected ?string $referenceLabel = null;

    public function __construct(
        protected string $source = '',
        protected string $alt = '',
        protected ?string $title = null,
    ) {
    }

    public function getReferenceLabel(): ?string
    {
        return $this->referenceLabel;
    }

    public function setReferenceLabel(string $label): void
    {
        $this->referenceLabel = $label;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function getAlt(): string
    {
        return $this->alt;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getType(): string
    {
        return 'image';
    }
}
