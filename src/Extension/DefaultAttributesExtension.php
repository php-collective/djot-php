<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Node;

/**
 * Adds default attributes to elements by type
 *
 * This extension allows you to configure default attributes for any element type.
 * Useful for adding CSS classes, lazy loading, or other common attributes.
 *
 * Example:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new DefaultAttributesExtension([
 *     'image' => ['loading' => 'lazy', 'decoding' => 'async'],
 *     'table' => ['class' => 'table table-striped'],
 *     'link' => ['class' => 'link'],
 *     'code_block' => ['class' => 'highlight'],
 * ]));
 * ```
 *
 * Supported element types (use snake_case):
 * - Block: paragraph, heading, code_block, block_quote, list, list_item, table, table_cell, div, thematic_break
 * - Inline: link, image, emphasis, strong, code, span, subscript, superscript, footnote, footnote_ref
 *
 * Note: Default attributes are only applied if the element doesn't already have that attribute set.
 * Classes are merged (both default and existing classes are kept).
 */
class DefaultAttributesExtension implements ExtensionInterface
{
    /**
     * @param array<string, array<string, string>> $defaults Map of element type to attributes
     */
    public function __construct(protected array $defaults = [])
    {
    }

    public function register(DjotConverter $converter): void
    {
        if ($this->defaults === []) {
            return;
        }

        // Register a wildcard listener to catch all node types
        $converter->on('render.*', function (RenderEvent $event): void {
            $node = $event->getNode();
            $type = $node->getType();

            if (!isset($this->defaults[$type])) {
                return;
            }

            $this->applyDefaults($node, $this->defaults[$type]);
        });
    }

    /**
     * Apply default attributes to a node
     *
     * @param \Djot\Node\Node $node
     * @param array<string, string> $defaults
     */
    protected function applyDefaults(Node $node, array $defaults): void
    {
        foreach ($defaults as $name => $value) {
            if ($name === 'class') {
                // Merge classes instead of overwriting
                $this->mergeClass($node, $value);
            } elseif (!$node->hasAttribute($name)) {
                // Only set if not already present
                $node->setAttribute($name, $value);
            }
        }
    }

    /**
     * Merge class attribute, preserving existing classes
     */
    protected function mergeClass(Node $node, string $classes): void
    {
        foreach (explode(' ', $classes) as $class) {
            $class = trim($class);
            if ($class !== '') {
                $node->addClass($class);
            }
        }
    }
}
