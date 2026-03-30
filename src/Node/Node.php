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
     * @var array<string, string>
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

    /**
     * Replace a child node with another node
     */
    public function replaceChildNode(Node $oldChild, Node $newChild): bool
    {
        $index = array_search($oldChild, $this->children, true);
        if ($index === false) {
            return false;
        }

        $newChild->parent = $this;
        $this->children[$index] = $newChild;
        $oldChild->parent = null;

        return true;
    }

    /**
     * Replace a child node with multiple nodes
     *
     * @param \Djot\Node\Node $oldChild
     * @param list<\Djot\Node\Node> $newChildren
     */
    public function replaceChildWithMany(Node $oldChild, array $newChildren): bool
    {
        $index = array_search($oldChild, $this->children, true);
        if ($index === false) {
            return false;
        }

        foreach ($newChildren as $child) {
            $child->parent = $this;
        }

        array_splice($this->children, (int)$index, 1, $newChildren);
        $oldChild->parent = null;

        return true;
    }

    /**
     * Remove a child node
     */
    public function removeChild(Node $child): bool
    {
        $index = array_search($child, $this->children, true);
        if ($index === false) {
            return false;
        }

        array_splice($this->children, (int)$index, 1);
        $child->parent = null;

        return true;
    }

    /**
     * Remove child at index
     */
    public function removeChildAt(int $index): ?Node
    {
        if (!isset($this->children[$index])) {
            return null;
        }

        $child = $this->children[$index];
        array_splice($this->children, $index, 1);
        $child->parent = null;

        return $child;
    }

    /**
     * @param string $key
     * @param string $value
     *
     * @return void
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * @return string|int|null
     */
    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        $this->attributes = array_merge($this->attributes, $attributes);
    }

    public function hasAttribute(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function removeAttribute(string $key): void
    {
        unset($this->attributes[$key]);
    }

    /**
     * Add a CSS class to the node
     */
    public function addClass(string $class): void
    {
        $class = trim($class);
        if ($class === '') {
            return;
        }

        $classes = (string)($this->getAttribute('class') ?? '');
        $classList = $classes !== '' ? (preg_split('/\s+/', trim($classes)) ?: []) : [];

        if (in_array($class, $classList, true)) {
            return;
        }

        $classList[] = $class;
        $this->setAttribute('class', implode(' ', $classList));
    }

    abstract public function getType(): string;
}
