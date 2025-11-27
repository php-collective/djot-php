<?php

declare(strict_types=1);

namespace Djot\Node;

/**
 * Base class for all AST nodes
 */
abstract class Node
{
    protected ?Node $parent = null;

    /**
     * @var array<\Djot\Node\Node>
     */
    protected array $children = [];

    /**
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    public function appendChild(Node $child): void
    {
        $child->parent = $this;
        $this->children[] = $child;
    }

    public function prependChild(Node $child): void
    {
        $child->parent = $this;
        array_unshift($this->children, $child);
    }

    /**
     * @return array<\Djot\Node\Node>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function getParent(): ?Node
    {
        return $this->parent;
    }

    public function hasChildren(): bool
    {
        return count($this->children) > 0;
    }

    public function replaceChild(int $index, Node $child): void
    {
        $child->parent = $this;
        $this->children[$index] = $child;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function setAttributes(array $attributes): void
    {
        $this->attributes = array_merge($this->attributes, $attributes);
    }

    public function hasAttribute(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Add a CSS class to the node
     */
    public function addClass(string $class): void
    {
        $classes = $this->getAttribute('class') ?? '';
        if ($classes !== '') {
            $classes .= ' ';
        }
        $classes .= $class;
        $this->setAttribute('class', $classes);
    }

    abstract public function getType(): string;
}
