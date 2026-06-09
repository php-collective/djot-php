<?php

declare(strict_types=1);

namespace Djot\Filter;

use Djot\Exception\ProfileViolationException;
use Djot\LinkPolicy;
use Djot\Node\Block\BlockNode;
use Djot\Node\Block\BlockQuote;
use Djot\Node\Block\CodeBlock;
use Djot\Node\Block\Comment;
use Djot\Node\Block\DefinitionDescription;
use Djot\Node\Block\DefinitionList;
use Djot\Node\Block\DefinitionTerm;
use Djot\Node\Block\Footnote;
use Djot\Node\Block\Heading;
use Djot\Node\Block\ListBlock;
use Djot\Node\Block\ListItem;
use Djot\Node\Block\Paragraph;
use Djot\Node\Block\Table;
use Djot\Node\Block\TableCell;
use Djot\Node\Block\TableRow;
use Djot\Node\Block\ThematicBreak;
use Djot\Node\ContentNodeInterface;
use Djot\Node\Document;
use Djot\Node\Inline\FootnoteRef;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Symbol;
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
        $this->cleanupEmptyContainers($doc);

        return $doc;
    }

    /**
     * @return list<\Djot\ProfileViolation>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    public function clearViolations(): void
    {
        $this->violations = [];
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
        // A comment is never visible content. Converting it to text would leak
        // its body into the output (and, being a block node, wrap it in a stray
        // paragraph), so always drop it instead.
        if ($node instanceof Comment) {
            $parent->removeChild($node);

            return;
        }

        $textContent = $this->extractTextContent($node);

        if ($textContent === '') {
            // If no text content, just remove the node
            $parent->removeChild($node);

            return;
        }

        // For block nodes, wrap text in a paragraph to maintain block structure
        // For inline nodes, just replace with text
        if ($node instanceof BlockNode) {
            $paragraph = new Paragraph();
            $this->appendTextWithBreaks($paragraph, $textContent);
            $parent->replaceChildNode($node, $paragraph);
        } else {
            // Inline node - replace with text
            $textNode = new Text($textContent);
            $parent->replaceChildNode($node, $textNode);
        }
    }

    /**
     * Append text content to a node, converting newlines to HardBreak nodes
     */
    protected function appendTextWithBreaks(Node $parent, string $content): void
    {
        $lines = explode("\n", $content);
        $lastIndex = count($lines) - 1;

        foreach ($lines as $index => $line) {
            if ($line !== '') {
                $parent->appendChild(new Text($line));
            }
            // Add line break between lines (not after the last line)
            if ($index < $lastIndex) {
                $parent->appendChild(new HardBreak());
            }
        }
    }

    /**
     * Remove empty container nodes (list items, paragraphs with no content, empty lists)
     */
    protected function cleanupEmptyContainers(Node $parent): void
    {
        $children = $parent->getChildren();

        foreach ($children as $child) {
            // Recursively clean up children first
            $this->cleanupEmptyContainers($child);

            // Check if this node is now empty and should be removed
            if ($this->isEmptyContainer($child)) {
                $parent->removeChild($child);
            }
        }
    }

    /**
     * Check if a node is an empty container that should be removed
     */
    protected function isEmptyContainer(Node $node): bool
    {
        // Text nodes are empty if they have no content
        if ($node instanceof Text) {
            return $node->getContent() === '';
        }

        // Nodes that store raw content directly are not empty if they have content.
        if ($node instanceof ContentNodeInterface) {
            $content = $node->getContent();
            if ($content !== '') {
                return false;
            }
        }

        // Check if all children are empty
        $children = $node->getChildren();
        if ($children === []) {
            // Structural elements that must be preserved even when empty:
            // - ThematicBreak: valid self-closing element (renders as <hr>)
            // - TableCell: maintains table column structure
            if ($node instanceof ThematicBreak || $node instanceof TableCell) {
                return false;
            }

            return $node instanceof BlockNode;
        }

        // If all children are empty, this container is empty
        foreach ($children as $child) {
            if (!$this->isEmptyContainer($child)) {
                return false;
            }
        }

        return true;
    }

    protected function extractTextContent(Node $node): string
    {
        // Special handling for images - show as [img: alt] or [img]
        if ($node instanceof Image) {
            $alt = $node->getAlt();

            return $alt !== '' ? '[img: ' . $alt . ']' : '[img]';
        }

        // Special handling for headings - preserve level with # prefix
        if ($node instanceof Heading) {
            $prefix = str_repeat('#', $node->getLevel()) . ' ';
            $text = '';
            foreach ($node->getChildren() as $child) {
                $text .= $this->extractTextContent($child);
            }

            return $prefix . $text;
        }

        // Special handling for code blocks - wrap in backticks
        if ($node instanceof CodeBlock) {
            $content = $node->getContent();
            // Use single backticks for single-line, triple for multi-line
            if (str_contains($content, "\n")) {
                return "```\n" . $content . "\n```";
            }

            return '`' . $content . '`';
        }

        // Special handling for links - get child text content
        if ($node instanceof Link) {
            $text = '';
            foreach ($node->getChildren() as $child) {
                $text .= $this->extractTextContent($child);
            }

            return $text;
        }

        // Special handling for tables - preserve row structure
        if ($node instanceof Table) {
            $rows = [];
            foreach ($node->getChildren() as $row) {
                if ($row instanceof TableRow) {
                    $cells = [];
                    foreach ($row->getChildren() as $cell) {
                        $cells[] = $this->extractTextContent($cell);
                    }
                    $rows[] = implode(' | ', $cells);
                }
            }

            return implode("\n", $rows);
        }

        // Special handling for blockquotes - add > prefix
        if ($node instanceof BlockQuote) {
            $paragraphs = [];
            foreach ($node->getChildren() as $child) {
                $text = $this->extractTextContent($child);
                if ($text !== '') {
                    $paragraphs[] = '> ' . $text;
                }
            }

            return implode("\n", $paragraphs);
        }

        // Special handling for definition lists - preserve term/description structure
        if ($node instanceof DefinitionList) {
            $parts = [];
            foreach ($node->getChildren() as $child) {
                $text = $this->extractTextContent($child);
                if ($text !== '') {
                    // Add prefix for terms to distinguish from descriptions
                    if ($child instanceof DefinitionTerm) {
                        $parts[] = $text;
                    } elseif ($child instanceof DefinitionDescription) {
                        // Prefix descriptions with dash for visibility in HTML
                        $parts[] = '- ' . $text;
                    } else {
                        $parts[] = $text;
                    }
                }
            }

            return implode("\n", $parts);
        }

        // Special handling for lists - preserve item structure with markers
        if ($node instanceof ListBlock) {
            $items = [];
            $index = $node->getStart();
            foreach ($node->getChildren() as $child) {
                if ($child instanceof ListItem) {
                    $text = $this->extractTextContent($child);
                    if ($text !== '') {
                        // Use appropriate marker based on list type
                        $marker = match ($node->getListType()) {
                            ListBlock::TYPE_ORDERED => $index . '. ',
                            default => '- ',
                        };
                        $items[] = $marker . $text;
                        $index++;
                    }
                }
            }

            return implode("\n", $items);
        }

        // Special handling for symbols - use the symbol name
        if ($node instanceof Symbol) {
            return ':' . $node->getName() . ':';
        }

        // Special handling for footnote references - use the label
        if ($node instanceof FootnoteRef) {
            return '[^' . $node->getLabel() . ']';
        }

        // Special handling for footnote definitions - preserve label with content
        if ($node instanceof Footnote) {
            $content = [];
            foreach ($node->getChildren() as $child) {
                $text = $this->extractTextContent($child);
                if ($text !== '') {
                    $content[] = $text;
                }
            }

            return '[^' . $node->getLabel() . ']: ' . implode(' ', $content);
        }

        // Special handling for thematic breaks - show original marker
        if ($node instanceof ThematicBreak) {
            return '---';
        }

        if ($node instanceof Text) {
            return $node->getContent();
        }

        // Handle nodes that store content directly (CodeBlock, RawBlock, etc.)
        if ($node instanceof ContentNodeInterface) {
            $content = $node->getContent();
            if ($content !== '') {
                return $content;
            }
        }

        $parts = [];
        foreach ($node->getChildren() as $child) {
            $childText = $this->extractTextContent($child);
            if ($childText !== '') {
                $parts[] = $childText;
            }
        }

        // Join with space for block elements to prevent text from running together
        return implode(' ', $parts);
    }
}
