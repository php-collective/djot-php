<?php

declare(strict_types=1);

namespace Djot\Filter;

use Djot\Exception\ProfileViolationException;
use Djot\LinkPolicy;
use Djot\Node\Block\BlockNode;
use Djot\Node\Document;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Text;
use Djot\Node\Node;
use Djot\Profile;
use Djot\ProfileViolation;

/**
 * Filters a document AST according to a Profile
 *
 * Walks the document tree and either strips, converts to text,
 * or throws exceptions for disallowed nodes.
 */
class ProfileFilter
{
    /**
     * @var list<\Djot\ProfileViolation>
     */
    protected array $violations = [];

    protected ?string $baseHost = null;

    /**
     * Set the base host for external link detection
     */
    public function setBaseHost(?string $host): void
    {
        $this->baseHost = $host;
    }

    /**
     * Filter a document according to the profile
     */
    public function filter(Document $doc, Profile $profile): Document
    {
        $this->violations = [];
        $this->filterChildren($doc, $profile, 0);

        return $doc;
    }

    /**
     * @return list<\Djot\ProfileViolation>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    protected function filterChildren(Node $parent, Profile $profile, int $depth): void
    {
        // Get a copy of children since we may modify during iteration
        $children = $parent->getChildren();

        foreach ($children as $child) {
            // Check nesting depth
            $maxNesting = $profile->getMaxNesting();
            if ($maxNesting > 0 && $depth > $maxNesting) {
                $this->handleViolation($child, $parent, $profile, 'max_nesting_exceeded');

                continue;
            }

            // Check if this node type is allowed
            if (!$profile->isNodeAllowed($child)) {
                $this->handleViolation($child, $parent, $profile, 'element_not_allowed');

                continue;
            }

            // Special handling for links
            if ($child instanceof Link && $profile->getLinkPolicy() !== null) {
                $this->filterLink($child, $parent, $profile);
            }

            // Special handling for images
            if ($child instanceof Image && $profile->getLinkPolicy() !== null) {
                $this->filterImage($child, $parent, $profile);
            }

            // Recursively filter children
            $this->filterChildren($child, $profile, $depth + 1);
        }
    }

    protected function filterLink(Link $node, Node $parent, Profile $profile): void
    {
        $policy = $profile->getLinkPolicy();
        if ($policy === null) {
            return;
        }

        $url = $node->getDestination() ?? '';

        if (!$policy->isUrlAllowed($url, $this->baseHost)) {
            $this->handleViolation($node, $parent, $profile, 'link_not_allowed');

            return;
        }

        // Add rel attributes if configured
        $this->applyRelAttributes($node, $policy);
    }

    protected function filterImage(Image $node, Node $parent, Profile $profile): void
    {
        $policy = $profile->getLinkPolicy();
        if ($policy === null) {
            return;
        }

        $url = $node->getSource();

        if (!$policy->isUrlAllowed($url, $this->baseHost)) {
            $this->handleViolation($node, $parent, $profile, 'image_not_allowed');
        }
    }

    protected function applyRelAttributes(Link $node, LinkPolicy $policy): void
    {
        $relAttrs = $policy->getRelAttributes();
        if ($relAttrs === []) {
            return;
        }

        $existing = $node->getAttribute('rel');
        $existingArray = $existing !== null ? explode(' ', (string)$existing) : [];

        foreach ($relAttrs as $rel) {
            if (!in_array($rel, $existingArray, true)) {
                $existingArray[] = $rel;
            }
        }

        $node->setAttribute('rel', implode(' ', $existingArray));
    }

    protected function handleViolation(Node $node, Node $parent, Profile $profile, string $reason): void
    {
        $reasonDescription = $profile->getReasonDisallowed($node->getType());

        $this->violations[] = new ProfileViolation(
            $node->getType(),
            $reason,
            $reasonDescription,
        );

        match ($profile->getDisallowedAction()) {
            Profile::ACTION_STRIP => $this->stripNode($node, $parent),
            Profile::ACTION_TO_TEXT => $this->convertToText($node, $parent),
            Profile::ACTION_ERROR => throw new ProfileViolationException($this->violations),
            default => $this->convertToText($node, $parent),
        };
    }

    protected function stripNode(Node $node, Node $parent): void
    {
        $parent->removeChild($node);
    }

    protected function convertToText(Node $node, Node $parent): void
    {
        $textContent = $this->extractTextContent($node);

        if ($textContent === '') {
            // If no text content, just remove the node
            $parent->removeChild($node);

            return;
        }

        // For block nodes, we need to convert to a paragraph with text
        // For inline nodes, we just convert to text
        if ($node instanceof BlockNode) {
            // Extract all text nodes from the block
            $textNodes = $this->extractTextNodes($node);
            if ($textNodes !== []) {
                $parent->replaceChildWithMany($node, $textNodes);
            } else {
                $parent->removeChild($node);
            }
        } else {
            // Inline node - replace with text
            $textNode = new Text($textContent);
            $parent->replaceChildNode($node, $textNode);
        }
    }

    protected function extractTextContent(Node $node): string
    {
        // Special handling for images - use alt text
        if ($node instanceof Image) {
            return $node->getAlt();
        }

        // Special handling for links - get child text content
        if ($node instanceof Link) {
            $text = '';
            foreach ($node->getChildren() as $child) {
                $text .= $this->extractTextContent($child);
            }

            return $text;
        }

        if ($node instanceof Text) {
            return $node->getContent();
        }

        $text = '';
        foreach ($node->getChildren() as $child) {
            $text .= $this->extractTextContent($child);
        }

        return $text;
    }

    /**
     * Extract text nodes from a node, preserving structure where possible
     *
     * @return list<\Djot\Node\Node>
     */
    protected function extractTextNodes(Node $node): array
    {
        $nodes = [];

        foreach ($node->getChildren() as $child) {
            if ($child instanceof Text) {
                $nodes[] = new Text($child->getContent());
            } else {
                // Recursively extract from child
                $childNodes = $this->extractTextNodes($child);
                foreach ($childNodes as $childNode) {
                    $nodes[] = $childNode;
                }
            }
        }

        return $nodes;
    }
}
