<?php

declare(strict_types=1);

namespace Djot;

use Djot\Node\Node;

/**
 * Profile-based feature restriction for different rendering contexts
 *
 * Profiles complement SafeMode (XSS prevention) by controlling which
 * markup features are available. Use this to create different rendering
 * contexts like full documents, blog posts, user comments, or chat messages.
 */
class Profile
{
    /**
     * Strip disallowed elements from output
     *
     * @var string
     */
    public const ACTION_STRIP = 'strip';

    /**
     * Convert disallowed elements to plain text (default, safest for UX)
     *
     * @var string
     */
    public const ACTION_TO_TEXT = 'to_text';

    /**
     * Throw exception on disallowed elements
     *
     * @var string
     */
    public const ACTION_ERROR = 'error';

    protected string $name = 'custom';

    protected string $description = '';

    /**
     * @var array<string, string>
     */
    protected array $featureReasons = [];

    /**
     * @var list<string>|null
     */
    protected ?array $allowedInline = null;

    /**
     * @var list<string>|null
     */
    protected ?array $allowedBlock = null;

    /**
     * @var list<string>
     */
    protected array $deniedInline = [];

    /**
     * @var list<string>
     */
    protected array $deniedBlock = [];

    protected ?LinkPolicy $linkPolicy = null;

    protected int $maxNesting = 0;

    protected int $maxLength = 0;

    protected string $disallowedAction = self::ACTION_TO_TEXT;

    /**
     * Create a full profile with all features enabled
     *
     * Use for trusted content like backend documentation or admin interfaces.
     */
    public static function full(): self
    {
        $profile = new self();
        $profile->name = 'full';
        $profile->description = 'All features enabled. Use only for trusted content.';

        return $profile;
    }

    /**
     * Create an article profile suitable for blog posts and articles
     *
     * Disables raw HTML to prevent XSS while allowing all formatting features.
     * Authors can use all djot features except embedding raw HTML/JS.
     */
    public static function article(): self
    {
        $profile = new self();
        $profile->name = 'article';
        $profile->description = 'Blog posts and articles. All formatting, no raw HTML.';
        $profile
            ->denyBlock([NodeType::RAW_BLOCK])
            ->denyInline([NodeType::RAW_INLINE]);

        $profile->featureReasons = [
            NodeType::RAW_BLOCK => 'Raw HTML blocks are disabled to prevent XSS attacks. Use djot markup instead.',
            NodeType::RAW_INLINE => 'Raw HTML is disabled to prevent XSS attacks. Use djot markup instead.',
        ];

        return $profile;
    }

    /**
     * Create a comment profile suitable for user-generated content
     *
     * Allowed formatting:
     * - Inline: bold, italic, strikethrough, insert, highlight, superscript, subscript, code, links
     * - Block: paragraphs, lists, blockquotes, code blocks
     *
     * This prevents:
     * - Headings: Users shouldn't structure page hierarchy
     * - Images: Prevents spam, inappropriate content, bandwidth abuse
     * - Tables: Too complex for comments, often misused for layout
     * - Footnotes: Overkill for comments
     * - Raw HTML: XSS prevention
     * - Divs/Sections: Layout control not needed
     *
     * Links have nofollow/ugc attributes to prevent SEO spam.
     */
    public static function comment(): self
    {
        $profile = new self();
        $profile->name = 'comment';
        $profile->description = 'User comments. Basic formatting only, nofollow links.';
        $profile
            ->allowInline([
                NodeType::TEXT,
                NodeType::EMPHASIS,
                NodeType::STRONG,
                NodeType::CODE,
                NodeType::LINK,
                NodeType::SOFT_BREAK,
                NodeType::HARD_BREAK,
                NodeType::DELETE,
                NodeType::INSERT,
                NodeType::HIGHLIGHT,
                NodeType::SUPERSCRIPT,
                NodeType::SUBSCRIPT,
            ])
            ->allowBlock([
                NodeType::PARAGRAPH,
                NodeType::LIST_BLOCK,
                NodeType::LIST_ITEM,
                NodeType::BLOCKQUOTE,
                NodeType::CODE_BLOCK,
            ])
            ->setLinkPolicy(
                LinkPolicy::unrestricted()
                    ->addRelAttribute('nofollow')
                    ->addRelAttribute('ugc'),
            )
            ->setMaxNesting(4);

        $profile->featureReasons = [
            NodeType::HEADING => 'Headings are disabled in comments to prevent disrupting page structure.',
            NodeType::IMAGE => 'Images are disabled to prevent spam, inappropriate content, and bandwidth abuse.',
            NodeType::TABLE => 'Tables are disabled as they are too complex for comment formatting.',
            NodeType::FOOTNOTE => 'Footnotes are disabled as they are unnecessary for comments.',
            NodeType::FOOTNOTE_REF => 'Footnotes are disabled as they are unnecessary for comments.',
            NodeType::RAW_BLOCK => 'Raw HTML is disabled for security reasons.',
            NodeType::RAW_INLINE => 'Raw HTML is disabled for security reasons.',
            NodeType::DIV => 'Custom containers are disabled in comments.',
            NodeType::SECTION => 'Sections are disabled in comments.',
            NodeType::DEFINITION_LIST => 'Definition lists are disabled in comments.',
            NodeType::DEFINITION_TERM => 'Definition lists are disabled in comments.',
            NodeType::DEFINITION_DESCRIPTION => 'Definition lists are disabled in comments.',
            NodeType::THEMATIC_BREAK => 'Horizontal rules are disabled in comments.',
            NodeType::LINE_BLOCK => 'Line blocks are disabled in comments.',
            NodeType::SPAN => 'Custom spans are disabled in comments.',
            NodeType::SYMBOL => 'Symbol markup is disabled in comments.',
            NodeType::MATH => 'Math markup is disabled in comments.',
        ];

        return $profile;
    }

    /**
     * Create a minimal profile suitable for chat or short-form input
     *
     * Allows all trivial inline formatting:
     * - Basic: text, bold, italic, strikethrough, code
     * - Advanced: superscript, subscript, insert, delete
     * - Breaks: soft/hard line breaks
     *
     * Blocks limited to paragraphs and lists. Suitable for:
     * - Chat messages
     * - Micro-posts
     * - Short form content
     */
    public static function minimal(): self
    {
        $profile = new self();
        $profile->name = 'minimal';
        $profile->description = 'Chat/micro-posts. Non-destructive inline formatting, paragraphs and lists.';
        $profile
            ->allowInline([
                NodeType::TEXT,
                NodeType::EMPHASIS,
                NodeType::STRONG,
                NodeType::CODE,
                NodeType::DELETE,
                NodeType::INSERT,
                NodeType::SUPERSCRIPT,
                NodeType::SUBSCRIPT,
                NodeType::SOFT_BREAK,
                NodeType::HARD_BREAK,
            ])
            ->allowBlock([
                NodeType::PARAGRAPH,
                NodeType::LIST_BLOCK,
                NodeType::LIST_ITEM,
            ])
            ->setMaxNesting(2);

        $profile->featureReasons = [
            NodeType::LINK => 'Links are disabled in this minimal context.',
            NodeType::HIGHLIGHT => 'Highlighting is disabled in this minimal context.',
            NodeType::IMAGE => 'Images are disabled in this minimal context.',
            NodeType::RAW_INLINE => 'Raw HTML is disabled for security reasons.',
            NodeType::FOOTNOTE_REF => 'Footnotes are disabled in this minimal context.',
            NodeType::SPAN => 'Custom spans are disabled in this minimal context.',
            NodeType::SYMBOL => 'Symbols are disabled in this minimal context.',
            NodeType::MATH => 'Math is disabled in this minimal context.',
            'default' => 'Only basic text formatting and lists are allowed in this context.',
        ];

        return $profile;
    }

    /**
     * Get the profile name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the profile description
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Get the reason why a specific node type is disallowed
     *
     * Returns null if the node type is allowed or no specific reason is set.
     */
    public function getReasonDisallowed(string $nodeType): ?string
    {
        if ($this->isTypeAllowed($nodeType)) {
            return null;
        }

        return $this->featureReasons[$nodeType] ?? $this->featureReasons['default'] ?? null;
    }

    /**
     * Get all feature restriction reasons
     *
     * @return array<string, string>
     */
    public function getFeatureReasons(): array
    {
        return $this->featureReasons;
    }

    /**
     * Set a reason for why a feature is disallowed
     */
    public function setFeatureReason(string $nodeType, string $reason): self
    {
        $this->featureReasons[$nodeType] = $reason;

        return $this;
    }

    /**
     * Set allowed inline types (null means all allowed)
     *
     * @param list<string>|null $types
     */
    public function allowInline(?array $types): self
    {
        $this->allowedInline = $types;

        return $this;
    }

    /**
     * Set allowed block types (null means all allowed)
     *
     * @param list<string>|null $types
     */
    public function allowBlock(?array $types): self
    {
        $this->allowedBlock = $types;

        return $this;
    }

    /**
     * Add types to the inline deny list
     *
     * @param list<string> $types
     */
    public function denyInline(array $types): self
    {
        $this->deniedInline = array_merge($this->deniedInline, $types);

        return $this;
    }

    /**
     * Add types to the block deny list
     *
     * @param list<string> $types
     */
    public function denyBlock(array $types): self
    {
        $this->deniedBlock = array_merge($this->deniedBlock, $types);

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getAllowedInline(): ?array
    {
        return $this->allowedInline;
    }

    /**
     * @return list<string>|null
     */
    public function getAllowedBlock(): ?array
    {
        return $this->allowedBlock;
    }

    /**
     * @return list<string>
     */
    public function getDeniedInline(): array
    {
        return $this->deniedInline;
    }

    /**
     * @return list<string>
     */
    public function getDeniedBlock(): array
    {
        return $this->deniedBlock;
    }

    public function getLinkPolicy(): ?LinkPolicy
    {
        return $this->linkPolicy;
    }

    public function setLinkPolicy(?LinkPolicy $policy): self
    {
        $this->linkPolicy = $policy;

        return $this;
    }

    public function getMaxNesting(): int
    {
        return $this->maxNesting;
    }

    /**
     * Set maximum nesting depth (0 = unlimited)
     */
    public function setMaxNesting(int $max): self
    {
        $this->maxNesting = $max;

        return $this;
    }

    public function getMaxLength(): int
    {
        return $this->maxLength;
    }

    /**
     * Set maximum input length in bytes (0 = unlimited)
     */
    public function setMaxLength(int $max): self
    {
        $this->maxLength = $max;

        return $this;
    }

    public function getDisallowedAction(): string
    {
        return $this->disallowedAction;
    }

    /**
     * Set action for disallowed elements
     *
     * @param string $action One of ACTION_STRIP, ACTION_TO_TEXT, ACTION_ERROR
     */
    public function onDisallowed(string $action): self
    {
        $this->disallowedAction = $action;

        return $this;
    }

    /**
     * Check if a node is allowed by this profile
     */
    public function isNodeAllowed(Node $node): bool
    {
        return $this->isTypeAllowed($node->getType());
    }

    /**
     * Check if a type string is allowed by this profile
     */
    public function isTypeAllowed(string $type): bool
    {
        // Check inline types
        if (in_array($type, NodeType::allInlineTypes(), true)) {
            return $this->isInlineAllowed($type);
        }

        // Check block types
        if (in_array($type, NodeType::allBlockTypes(), true)) {
            return $this->isBlockAllowed($type);
        }

        // Document type is always allowed
        if ($type === 'document') {
            return true;
        }

        // Unknown types are denied by default
        return false;
    }

    /**
     * Check if an inline type is allowed
     */
    public function isInlineAllowed(string $type): bool
    {
        // Check deny list first
        if (in_array($type, $this->deniedInline, true)) {
            return false;
        }

        // If allowlist is set, check against it
        if ($this->allowedInline !== null) {
            return in_array($type, $this->allowedInline, true);
        }

        // Otherwise allowed
        return true;
    }

    /**
     * Check if a block type is allowed
     */
    public function isBlockAllowed(string $type): bool
    {
        // Check deny list first
        if (in_array($type, $this->deniedBlock, true)) {
            return false;
        }

        // If allowlist is set, check against it
        if ($this->allowedBlock !== null) {
            return in_array($type, $this->allowedBlock, true);
        }

        // Otherwise allowed
        return true;
    }

    /**
     * Get a summary of what this profile allows/denies
     *
     * @return array{name: string, description: string, allowed_block: list<string>|string, allowed_inline: list<string>|string, denied_block: list<string>, denied_inline: list<string>}
     */
    public function getSummary(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'allowed_block' => $this->allowedBlock ?? 'all',
            'allowed_inline' => $this->allowedInline ?? 'all',
            'denied_block' => $this->deniedBlock,
            'denied_inline' => $this->deniedInline,
        ];
    }
}
