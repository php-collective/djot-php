<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Hyperlink
 */
class Link extends InlineNode
{
    /**
     * The reference label if this link was created from a reference link
     * like [text][ref] or [text][]. Null for inline links.
     */
    protected ?string $referenceLabel = null;

    /**
     * Whether this link was created from an autolink like <url> or <email>
     */
    protected bool $isAutolink = false;

    public function __construct(
        protected ?string $destination = null,
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

    public function isAutolink(): bool
    {
        return $this->isAutolink;
    }

    public function setAutolink(bool $isAutolink): void
    {
        $this->isAutolink = $isAutolink;
    }

    public function getDestination(): ?string
    {
        return $this->destination;
    }

    public function setDestination(string $destination): void
    {
        $this->destination = $destination;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getType(): string
    {
        return 'link';
    }
}
