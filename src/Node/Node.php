<?php

declare(strict_types=1);

namespace Djot\Node;

use ReflectionMethod;
use WeakMap;

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

    /**
     * @var \WeakMap<\Djot\Node\Node, \Djot\Node\ClassMembership>|null
     */
    private static ?WeakMap $classMemberships = null;

    /**
     * @var array<class-string, bool>
     */
    private static array $nativeClassAccess = [];

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
        return $this->children !== [];
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

    public function setAttribute(string $key, string $value): void
    {
        $this->attributes[$key] = $value;
        if ($key === 'class') {
            if (self::$classMemberships !== null) {
                unset(self::$classMemberships[$this]);
            }
        }
    }

    public function getAttribute(string $key): ?string
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
        if (isset($attributes['class'])) {
            if (self::$classMemberships !== null) {
                unset(self::$classMemberships[$this]);
            }
        }
    }

    public function hasAttribute(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function removeAttribute(string $key): void
    {
        unset($this->attributes[$key]);
        if ($key === 'class') {
            if (self::$classMemberships !== null) {
                unset(self::$classMemberships[$this]);
            }
        }
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

        $native = self::$nativeClassAccess[$this::class] ??= str_starts_with($this::class, __NAMESPACE__ . '\\')
            && (new ReflectionMethod($this, 'getType'))->getDeclaringClass()->getName() === $this::class
            && (new ReflectionMethod($this, 'getAttribute'))->getDeclaringClass()->getName() === self::class
            && (new ReflectionMethod($this, 'setAttribute'))->getDeclaringClass()->getName() === self::class
            && (new ReflectionMethod($this, 'setAttributes'))->getDeclaringClass()->getName() === self::class
            && (new ReflectionMethod($this, 'removeAttribute'))->getDeclaringClass()->getName() === self::class;
        if (
            $native && preg_match('/\s/', $class) === 0
            && (strlen($this->attributes['class'] ?? '') >= 128
                || (self::$classMemberships !== null && isset(self::$classMemberships[$this])))
        ) {
            self::$classMemberships ??= new WeakMap();
            if (!isset(self::$classMemberships[$this])) {
                $value = $this->attributes['class'] ?? '';
                $list = $value !== '' ? (preg_split('/\s+/', trim($value)) ?: []) : [];
                self::$classMemberships[$this] = new ClassMembership(array_fill_keys($list, true));
                unset($value);
            }
            $membership = self::$classMemberships[$this];
            if (!isset($membership->members[$class])) {
                if ($membership->needsNormalization) {
                    $this->attributes['class'] = implode(' ', preg_split('/\s+/', trim($this->attributes['class'] ?? '')) ?: []);
                    $membership->needsNormalization = false;
                }
                $membership->members[$class] = true;
                if (isset($membership->members[''])) {
                    unset($membership->members['']);
                    $membership->needsNormalization = true;
                    $this->attributes['class'] .= ' ' . $class;

                    return;
                }
                $this->attributes['class'] .= $this->attributes['class'] !== '' ? ' ' . $class : $class;
            }

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

    /**
     * Check if the node has a specific CSS class
     */
    public function hasClass(string $class): bool
    {
        return in_array($class, $this->getClassList(), true);
    }

    /**
     * Get all CSS classes as an array
     *
     * @return list<string>
     */
    public function getClassList(): array
    {
        $classes = $this->getAttribute('class') ?? '';
        if ($classes === '') {
            return [];
        }

        $classList = preg_split('/\s+/', trim($classes)) ?: [];

        return array_values(array_filter($classList, fn ($c) => $c !== ''));
    }

    /**
     * Deep-clone child nodes and repair parent links.
     */
    public function __clone(): void
    {
        $this->parent = null;

        foreach ($this->children as $index => $child) {
            $clonedChild = clone $child;
            $clonedChild->parent = $this;
            $this->children[$index] = $clonedChild;
        }
    }

    abstract public function getType(): string;
}
