<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;

/**
 * Interface for Djot extensions
 *
 * Extensions provide a way to bundle related customizations together:
 * - Custom inline patterns (e.g., @mentions, [[wiki-links]])
 * - Custom block patterns (e.g., admonitions, spoilers)
 * - Render event listeners (e.g., external link handling, heading permalinks)
 * - Optional `beforeRender()` hooks for AST preprocessing
 *
 * If an extension mutates the AST in `beforeRender()`, it should also implement
 * `MutatesDocumentBeforeRenderInterface`. This tells `DjotConverter` to clone the
 * caller-provided `Document` before running preprocessing hooks, keeping repeated
 * `render()` calls on the same AST stable.
 *
 * Example:
 * ```php
 * class MentionsExtension implements ExtensionInterface
 * {
 *     public function register(DjotConverter $converter): void
 *     {
 *         // Add inline pattern for @username
 *         $converter->getParser()->getInlineParser()->addInlinePattern(
 *             '/@([a-zA-Z0-9_]+)/',
 *             fn($match, $groups) => new Link('/users/' . $groups[1])
 *         );
 *
 *         // Add render listener for styling
 *         $converter->on('render.link', function($event) {
 *             // ...
 *         });
 *     }
 * }
 *
 * $converter = new DjotConverter();
 * $converter->addExtension(new MentionsExtension());
 * ```
 */
interface ExtensionInterface
{
    /**
     * Register extension components with the converter
     *
     * Called when the extension is added to the converter. Use this method to:
     * - Register inline patterns via $converter->getParser()->getInlineParser()
     * - Register block patterns via $converter->getParser()
     * - Register render event listeners via $converter->on()
     */
    public function register(DjotConverter $converter): void;
}
