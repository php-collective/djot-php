<?php

declare(strict_types=1);

namespace Djot\Renderer;

use Djot\Node\Block\Heading;
use Djot\Node\Inline\Code;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Math;
use Djot\Node\Inline\RawInline;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Text;
use Djot\Node\Node;

/**
 * Shared service for generating and deduplicating heading IDs
 *
 * Used by HtmlRenderer, TableOfContentsExtension, and HeadingPermalinksExtension
 * to ensure consistent heading IDs across all consumers.
 *
 * Uses spl_object_id caching so the same heading node always returns the same ID
 * regardless of how many times it's queried.
 */
class HeadingIdTracker
{
    /**
     * Tracks used IDs for deduplication
     *
     * @var array<string, int>
     */
    protected array $usedIds = [];

    /**
     * Counter for auto-generated section IDs (when heading has no text)
     */
    protected int $sectionCounter = 0;

    /**
     * Cache of resolved IDs per heading node (keyed by spl_object_id)
     *
     * @var array<int, string>
     */
    protected array $resolvedIds = [];

    /**
     * Cache of plain text per node (keyed by spl_object_id)
     *
     * Caching ensures the first caller captures the original text before
     * any extensions modify the node tree (e.g., HeadingPermalinksExtension
     * appending a permalink symbol).
     *
     * @var array<int, string>
     */
    protected array $resolvedTexts = [];

    /**
     * Get the unique ID for a heading node
     *
     * Returns a cached result if this heading has already been resolved.
     * Otherwise generates, deduplicates, and caches the ID.
     */
    public function getIdForHeading(Heading $node): string
    {
        $objectId = spl_object_id($node);
        if (isset($this->resolvedIds[$objectId])) {
            return $this->resolvedIds[$objectId];
        }

        $id = $this->generateId($node);
        $this->resolvedIds[$objectId] = $id;

        return $id;
    }

    /**
     * Track an explicit ID from a non-heading element
     *
     * This prevents auto-generated heading IDs from conflicting
     * with explicitly set IDs on other elements.
     */
    public function trackId(string $id): void
    {
        if ($id !== '' && !isset($this->usedIds[$id])) {
            $this->usedIds[$id] = 0;
        }
    }

    /**
     * Normalize text into a valid ID string
     *
     * 1. Strip # characters entirely
     * 2. Trim whitespace
     * 3. Replace whitespace sequences with single dashes
     */
    public function normalizeId(string $text): string
    {
        $id = str_replace('#', '', $text);
        $id = trim($id);

        return preg_replace('/[\s]+/', '-', $id) ?? $id;
    }

    /**
     * Get plain text content of a node
     *
     * For Heading nodes, the result is cached by spl_object_id so that
     * the original text is preserved even if extensions later modify
     * the heading's children (e.g., appending a permalink symbol).
     */
    public function getPlainText(Node $node): string
    {
        if ($node instanceof Heading) {
            $objectId = spl_object_id($node);
            if (isset($this->resolvedTexts[$objectId])) {
                return $this->resolvedTexts[$objectId];
            }

            $text = $this->extractPlainText($node);
            $this->resolvedTexts[$objectId] = $text;

            return $text;
        }

        return $this->extractPlainText($node);
    }

    /**
     * Recursively extract plain text from a node tree
     */
    protected function extractPlainText(Node $node): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Text) {
                $text .= $child->getContent();
            } elseif ($child instanceof SoftBreak || $child instanceof HardBreak) {
                $text .= ' ';
            } elseif ($child instanceof Code || $child instanceof Math || $child instanceof RawInline) {
                $text .= $child->getContent();
            } elseif ($child instanceof Node) {
                $text .= $this->extractPlainText($child);
            }
        }

        return $text;
    }

    /**
     * Reset all state (called per render)
     */
    public function reset(): void
    {
        $this->usedIds = [];
        $this->sectionCounter = 0;
        $this->resolvedIds = [];
        $this->resolvedTexts = [];
    }

    /**
     * Generate and deduplicate an ID for a heading
     */
    protected function generateId(Heading $node): string
    {
        // If heading has explicit id attribute, use it
        if ($node->hasAttribute('id')) {
            $idAttr = $node->getAttribute('id');
            $id = is_string($idAttr) ? $idAttr : '';
            // Track explicit IDs so auto-generated IDs don't conflict
            if (!isset($this->usedIds[$id])) {
                $this->usedIds[$id] = 0;
            }

            return $id;
        }

        // Generate from heading text
        $headingText = $this->getPlainText($node);

        if ($headingText === '') {
            // Generate fallback ID
            $this->sectionCounter++;

            return 's-' . $this->sectionCounter;
        }

        $baseId = $this->normalizeId($headingText);

        // Track and deduplicate
        if (!isset($this->usedIds[$baseId])) {
            $this->usedIds[$baseId] = 0;

            return $baseId;
        }

        // Already used, add suffix (first conflict is -1, second is -2, etc.)
        $this->usedIds[$baseId]++;

        return $baseId . '-' . $this->usedIds[$baseId];
    }
}
