<?php

declare(strict_types=1);

namespace Djot\Renderer;

use Closure;
use Djot\Node\Block\Heading;
use Djot\Node\Inline\Code;
use Djot\Node\Inline\FootnoteRef;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Math;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Symbol;
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
     * @param \Closure(string): string|null $idTransformer Optional transform applied
     *     to the spec-normalized id (e.g. ASCII transliteration for URL/CSS
     *     portability). Null (default) leaves the jgm/djot#393 unicode-preserving id
     *     unchanged. Set via an extension such as AsciiHeadingIdsExtension.
     */
    public function __construct(protected ?Closure $idTransformer = null)
    {
    }

    /**
     * Set the optional id transform (see the constructor). Used by extensions to
     * adjust generated ids without forking the core spec slugger.
     *
     * @param \Closure(string): string|null $idTransformer
     */
    public function setIdTransformer(?Closure $idTransformer): void
    {
        $this->idTransformer = $idTransformer;
    }

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
     * Reserve every explicit `id` attribute in the document subtree
     *
     * Walks the AST and `trackId`s any node with an explicit `id`, so
     * later heading auto-id generation dedupes against the entire
     * document's explicit ids regardless of their position. Run once
     * up-front by both the renderer and the implicit-reference pass so
     * the two compute the same heading ids (parser/render parity).
     */
    public function reserveExplicitIds(Node $node): void
    {
        if ($node->hasAttribute('id')) {
            $this->trackId((string)$node->getAttribute('id'));
        }

        foreach ($node->getChildren() as $child) {
            $this->reserveExplicitIds($child);
        }
    }

    /**
     * Normalize heading text into an identifier (jgm/djot#393)
     *
     * 1. Slug the text: replace each maximal run of non-alphanumeric ASCII with a
     *    single '-' and trim; non-ASCII characters and letter case are preserved.
     * 2. If an id transform is set (e.g. ASCII transliteration via
     *    AsciiHeadingIdsExtension), apply it to the slug and re-slug the result
     *    (the transform may reintroduce spaces/punctuation, e.g. romanization).
     * 3. Prefix with 's-' if the result starts with a digit, so the id is a valid
     *    bare CSS selector (querySelector('#9-x') would otherwise throw). This is
     *    orthogonal to #393, which governs punctuation only.
     *
     * Returns '' when nothing usable remains (all-punctuation text, or a transform
     * that reduces the text to nothing); the caller then falls back to a generated
     * `s-N` id.
     */
    public function normalizeId(string $text): string
    {
        $id = $this->slug($text);

        if ($this->idTransformer !== null) {
            $id = $this->slug(($this->idTransformer)($id));
        }

        if ($id !== '' && preg_match('/^\p{N}/u', $id)) {
            $id = 's-' . $id;
        }

        return $id;
    }

    /**
     * jgm/djot#393 slug step: replace each maximal run of non-alphanumeric ASCII
     * with a single '-' and trim. Non-ASCII characters and letter case are kept.
     */
    protected function slug(string $text): string
    {
        return trim(preg_replace('/[^0-9A-Za-z\x{0080}-\x{10FFFF}]+/u', '-', $text) ?? $text, '-');
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
     *
     * When $forId is true, non-textual elements that the djot spec excludes
     * from auto-generated heading identifiers are skipped: symbols (`:name:`)
     * and footnote references (`[^label]`). See jgm/djot#393. Otherwise the
     * full human-readable text is returned (e.g. for TOC labels), with
     * symbols rendered as `:name:`.
     */
    protected function extractPlainText(Node $node, bool $forId = false): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof FootnoteRef) {
                continue;
            }

            if ($child instanceof Text) {
                $text .= $child->getContent();
            } elseif ($child instanceof SoftBreak || $child instanceof HardBreak) {
                $text .= ' ';
            } elseif ($child instanceof Code || $child instanceof Math) {
                $text .= $child->getContent();
            } elseif ($child instanceof Symbol) {
                if (!$forId) {
                    $text .= ':' . $child->getName() . ':';
                }
            } elseif ($child instanceof Node) {
                $text .= $this->extractPlainText($child, $forId);
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
            $id = $idAttr ?? '';
            // Track explicit IDs so auto-generated IDs don't conflict
            if (!isset($this->usedIds[$id])) {
                $this->usedIds[$id] = 0;
            }

            return $id;
        }

        // Warm the plain-text cache so display consumers (TOC, permalinks)
        // still see the pre-mutation text including symbols.
        $this->getPlainText($node);

        // The identifier itself is formed from the plain text content
        // excluding non-textual elements such as symbols and footnote
        // references (jgm/djot#393).
        $idText = $this->extractPlainText($node, forId: true);
        $baseId = $this->normalizeId($idText);

        if ($baseId === '') {
            // No usable content: empty heading, all-punctuation text, or a
            // script the transliterator could not reduce to ASCII. Fall
            // back to a generated `s-N` id, skipping any `s-N` already
            // reserved (by `reserveExplicitIds`, an explicit `{#s-N}`, or
            // a prior heading) so the fallback never produces a duplicate.
            // Parser/render parity is preserved by `BlockParser`'s
            // post-parse rewrite (#184) which re-targets implicit refs to
            // the renderer-visible deduped id.
            do {
                $this->sectionCounter++;
                $fallback = 's-' . $this->sectionCounter;
            } while (isset($this->usedIds[$fallback]));

            $this->usedIds[$fallback] = 0;

            return $fallback;
        }

        // Track and deduplicate
        if (!isset($this->usedIds[$baseId])) {
            $this->usedIds[$baseId] = 0;

            return $baseId;
        }

        // Already used. Find the next suffix that isn't itself reserved —
        // an explicit `{#Foo-1}` must not be silently overridden by an
        // auto-id collision on `# Foo`.
        do {
            $this->usedIds[$baseId]++;
            $candidate = $baseId . '-' . $this->usedIds[$baseId];
        } while (isset($this->usedIds[$candidate]));

        $this->usedIds[$candidate] = 0;

        return $candidate;
    }
}
