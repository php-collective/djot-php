<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Exception\ProfileViolationException;
use Djot\LinkPolicy;
use Djot\NodeType;
use Djot\Profile;
use Djot\ProfileViolation;
use LengthException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Profile-based feature restriction
 */
class ProfileTest extends TestCase
{
    // ==================== Comment Profile Tests ====================

    public function testCommentProfileAllowsBasicFormatting(): void
    {
        $converter = new DjotConverter(profile: Profile::comment());
        $html = $converter->convert('This is *bold* and _italic_.');

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
    }

    public function testCommentProfileAllowsDelete(): void
    {
        $converter = new DjotConverter(profile: Profile::comment());
        $html = $converter->convert('This is {-deleted-} text.');

        $this->assertStringContainsString('<del>deleted</del>', $html);
    }

    public function testCommentProfileAllowsInlineCode(): void
    {
        $converter = new DjotConverter(profile: Profile::comment());
        $html = $converter->convert('Use `code` here.');

        $this->assertStringContainsString('<code>code</code>', $html);
    }

    public function testCommentProfileAllowsLinks(): void
    {
        $converter = new DjotConverter(profile: Profile::comment());
        $html = $converter->convert('[link](https://example.com)');

        $this->assertStringContainsString('href="https://example.com"', $html);
    }

    public function testCommentProfileAddsNofollowToLinks(): void
    {
        $converter = new DjotConverter(profile: Profile::comment());
        $html = $converter->convert('[link](https://example.com)');

        $this->assertStringContainsString('rel="nofollow ugc"', $html);
    }

    public function testCommentProfileAllowsLists(): void
    {
        $converter = new DjotConverter(profile: Profile::comment());
        $html = $converter->convert("- Item 1\n- Item 2");

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>', $html);
    }

    public function testCommentProfileAllowsBlockquotes(): void
    {
        $converter = new DjotConverter(profile: Profile::comment());
        $html = $converter->convert('> Quote here');

        $this->assertStringContainsString('<blockquote>', $html);
    }

    public function testCommentProfileAllowsCodeBlocks(): void
    {
        $converter = new DjotConverter(profile: Profile::comment());
        $html = $converter->convert("```\ncode block\n```");

        $this->assertStringContainsString('<pre>', $html);
        $this->assertStringContainsString('<code>', $html);
    }

    public function testCommentProfileStripsHeadings(): void
    {
        $converter = new DjotConverter(profile: Profile::comment());
        $html = $converter->convert("# Heading\n\nParagraph text");

        $this->assertStringNotContainsString('<h1>', $html);
        $this->assertStringContainsString('Heading', $html);
        $this->assertStringContainsString('Paragraph text', $html);
    }

    public function testCommentProfileStripsImages(): void
    {
        $converter = new DjotConverter(profile: Profile::comment());
        $html = $converter->convert('![alt text](image.jpg)');

        $this->assertStringNotContainsString('<img', $html);
        // Alt text should be preserved as text
        $this->assertStringContainsString('alt text', $html);
    }

    public function testCommentProfileStripsTables(): void
    {
        $converter = new DjotConverter(profile: Profile::comment());
        $html = $converter->convert("| A | B |\n|---|---|\n| 1 | 2 |");

        $this->assertStringNotContainsString('<table>', $html);
    }

    public function testCommentProfileConvertsRawHtmlToText(): void
    {
        $converter = new DjotConverter(profile: Profile::comment());
        $html = $converter->convert('`<script>alert(1)</script>`{=html}');

        // Raw HTML tag should not appear (security)
        $this->assertStringNotContainsString('<script>', $html);
        // Content is preserved as escaped text (ACTION_TO_TEXT behavior)
        $this->assertStringContainsString('&lt;script', $html);
    }

    public function testRawHtmlStrippedWithActionStrip(): void
    {
        $profile = Profile::comment()->onDisallowed(Profile::ACTION_STRIP);
        $converter = new DjotConverter(profile: $profile);
        $html = $converter->convert('`<script>alert(1)</script>`{=html}');

        // With ACTION_STRIP, content is completely removed
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('&lt;script', $html);
        $this->assertStringNotContainsString('alert', $html);
    }

    public function testCommentProfileReportsViolations(): void
    {
        $converter = new DjotConverter(profile: Profile::comment());
        $converter->convert("# Heading\n\n![image](test.jpg)");

        $this->assertTrue($converter->hasProfileViolations());
        $violations = $converter->getProfileViolations();
        $this->assertNotEmpty($violations);

        // Check that we have violations for heading and image
        $types = array_map(fn ($v) => $v->nodeType, $violations);
        $this->assertContains('heading', $types);
        $this->assertContains('image', $types);
    }

    // ==================== Minimal Profile Tests ====================

    public function testMinimalProfileAllowsNonDestructiveInline(): void
    {
        $converter = new DjotConverter(profile: Profile::minimal());
        $html = $converter->convert('*bold* and _italic_ and `code` and [link](url)');

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
        // Links are still disabled (potentially destructive)
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testMinimalProfileAllowsLists(): void
    {
        $converter = new DjotConverter(profile: Profile::minimal());
        $html = $converter->convert("- Item 1\n- Item 2");

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>', $html);
        $this->assertStringContainsString('Item 1', $html);
        $this->assertStringContainsString('Item 2', $html);
    }

    // ==================== Article Profile Tests ====================

    public function testArticleProfileAllowsHeadings(): void
    {
        $converter = new DjotConverter(profile: Profile::article());
        $html = $converter->convert('# Heading');

        $this->assertStringContainsString('<h1>', $html);
    }

    public function testArticleProfileAllowsImages(): void
    {
        $converter = new DjotConverter(profile: Profile::article());
        $html = $converter->convert('![alt](image.jpg)');

        $this->assertStringContainsString('<img', $html);
    }

    public function testArticleProfileAllowsTables(): void
    {
        $converter = new DjotConverter(profile: Profile::article());
        $html = $converter->convert("| A | B |\n|---|---|\n| 1 | 2 |");

        $this->assertStringContainsString('<table>', $html);
    }

    public function testArticleProfileStripsRawHtml(): void
    {
        $converter = new DjotConverter(profile: Profile::article());
        $html = $converter->convert('`<script>alert(1)</script>`{=html}');

        $this->assertStringNotContainsString('<script>', $html);
    }

    // ==================== Full Profile Tests ====================

    public function testFullProfileAllowsEverything(): void
    {
        $converter = new DjotConverter(profile: Profile::full());
        $djot = <<<'DJOT'
# Heading

Paragraph with *bold* and _italic_.

![image](test.jpg)

| A | B |
|---|---|
| 1 | 2 |

```
code block
```

> quote

- list item

`<b>raw html</b>`{=html}
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<h1>', $html);
        $this->assertStringContainsString('<strong>', $html);
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<pre>', $html);
        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('<ul>', $html);

        $this->assertFalse($converter->hasProfileViolations());
    }

    // ==================== Custom Profile Tests ====================

    public function testCustomProfileWithAllowlist(): void
    {
        $profile = (new Profile())
            ->allowInline([NodeType::TEXT, NodeType::STRONG])
            ->allowBlock([NodeType::PARAGRAPH]);

        $converter = new DjotConverter(profile: $profile);
        $html = $converter->convert('*bold* and _italic_');

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringNotContainsString('<em>', $html);
    }

    public function testCustomProfileWithDenylist(): void
    {
        $profile = (new Profile())
            ->denyInline([NodeType::LINK, NodeType::IMAGE])
            ->denyBlock([NodeType::HEADING]);

        $converter = new DjotConverter(profile: $profile);
        $html = $converter->convert("# Title\n\n[link](url)");

        $this->assertStringNotContainsString('<h1>', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    // ==================== Max Length Tests ====================

    public function testMaxLengthThrowsException(): void
    {
        $profile = (new Profile())->setMaxLength(10);
        $converter = new DjotConverter(profile: $profile);

        $this->expectException(LengthException::class);
        $this->expectExceptionMessage('exceeds maximum');
        $converter->convert('This is a very long string that exceeds the limit');
    }

    public function testMaxLengthAllowsShortInput(): void
    {
        $profile = (new Profile())->setMaxLength(100);
        $converter = new DjotConverter(profile: $profile);
        $html = $converter->convert('Short text');

        $this->assertStringContainsString('Short text', $html);
    }

    // ==================== Error Action Tests ====================

    public function testActionErrorThrowsException(): void
    {
        $profile = Profile::comment()->onDisallowed(Profile::ACTION_ERROR);
        $converter = new DjotConverter(profile: $profile);

        $this->expectException(ProfileViolationException::class);
        $converter->convert('# Heading not allowed');
    }

    public function testActionStripRemovesContent(): void
    {
        $profile = Profile::comment()->onDisallowed(Profile::ACTION_STRIP);
        $converter = new DjotConverter(profile: $profile);
        $html = $converter->convert("# Heading\n\nParagraph");

        $this->assertStringNotContainsString('Heading', $html);
        $this->assertStringContainsString('Paragraph', $html);
    }

    public function testActionToTextPreservesContent(): void
    {
        $profile = Profile::comment()->onDisallowed(Profile::ACTION_TO_TEXT);
        $converter = new DjotConverter(profile: $profile);
        $html = $converter->convert("# Heading\n\nParagraph");

        // Heading content should be preserved as text
        $this->assertStringContainsString('Heading', $html);
        $this->assertStringNotContainsString('<h1>', $html);
    }

    // ==================== Profile Info Tests ====================

    public function testProfileName(): void
    {
        $this->assertEquals('full', Profile::full()->getName());
        $this->assertEquals('article', Profile::article()->getName());
        $this->assertEquals('comment', Profile::comment()->getName());
        $this->assertEquals('minimal', Profile::minimal()->getName());
    }

    public function testProfileDescription(): void
    {
        $this->assertNotEmpty(Profile::full()->getDescription());
        $this->assertNotEmpty(Profile::article()->getDescription());
        $this->assertNotEmpty(Profile::comment()->getDescription());
        $this->assertNotEmpty(Profile::minimal()->getDescription());
    }

    public function testReasonDisallowed(): void
    {
        $profile = Profile::comment();

        // Should have reasons for disallowed types
        $reason = $profile->getReasonDisallowed(NodeType::HEADING);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('heading', strtolower($reason));

        $reason = $profile->getReasonDisallowed(NodeType::IMAGE);
        $this->assertNotNull($reason);

        // Should return null for allowed types
        $this->assertNull($profile->getReasonDisallowed(NodeType::PARAGRAPH));
        $this->assertNull($profile->getReasonDisallowed(NodeType::STRONG));
    }

    public function testProfileSummary(): void
    {
        $profile = Profile::comment();
        $summary = $profile->getSummary();

        $this->assertArrayHasKey('name', $summary);
        $this->assertArrayHasKey('description', $summary);
        $this->assertArrayHasKey('allowed_block', $summary);
        $this->assertArrayHasKey('allowed_inline', $summary);
        $this->assertEquals('comment', $summary['name']);
    }

    // ==================== Profile Setter Tests ====================

    public function testSetProfileAfterConstruction(): void
    {
        $converter = new DjotConverter();
        $converter->setProfile(Profile::comment());

        $html = $converter->convert('# Heading');
        $this->assertStringNotContainsString('<h1>', $html);
    }

    public function testGetProfile(): void
    {
        $profile = Profile::comment();
        $converter = new DjotConverter(profile: $profile);

        $this->assertSame($profile, $converter->getProfile());
    }

    public function testDisableProfile(): void
    {
        $converter = new DjotConverter(profile: Profile::comment());
        $converter->setProfile(null);

        $html = $converter->convert('# Heading');
        $this->assertStringContainsString('<h1>', $html);
    }

    // ==================== Combined with SafeMode Tests ====================

    public function testProfileAndSafeModeWorkTogether(): void
    {
        $converter = new DjotConverter(
            safeMode: true,
            profile: Profile::comment(),
        );

        $djot = <<<'DJOT'
# Heading should be stripped

[Safe link](https://example.com)

[Evil link](javascript:alert(1))

Paragraph with *bold*.
DJOT;

        $html = $converter->convert($djot);

        // Profile strips heading
        $this->assertStringNotContainsString('<h1>', $html);

        // SafeMode sanitizes javascript URL
        $this->assertStringNotContainsString('javascript:', $html);

        // Safe link works with nofollow from profile
        $this->assertStringContainsString('https://example.com', $html);
        $this->assertStringContainsString('nofollow', $html);

        // Bold works
        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    // ==================== Max Nesting Tests ====================

    public function testMaxNestingExceeded(): void
    {
        $profile = (new Profile())
            ->allowBlock([NodeType::PARAGRAPH, NodeType::LIST_BLOCK, NodeType::LIST_ITEM])
            ->allowInline([NodeType::TEXT])
            ->setMaxNesting(2);

        $converter = new DjotConverter(profile: $profile);
        // Create deeply nested list
        $html = $converter->convert("- Level 1\n  - Level 2\n    - Level 3\n      - Level 4");

        // Deeper levels should be filtered
        $this->assertTrue($converter->hasProfileViolations());
    }

    // ==================== Link Policy Integration Tests ====================

    public function testLinkPolicyBlocksDisallowedDomain(): void
    {
        $profile = (new Profile())
            ->allowInline([NodeType::TEXT, NodeType::LINK])
            ->allowBlock([NodeType::PARAGRAPH])
            ->setLinkPolicy(LinkPolicy::allowlist(['trusted.com']));

        $converter = new DjotConverter(profile: $profile);
        $html = $converter->convert('[link](https://evil.com)');

        $this->assertStringNotContainsString('evil.com', $html);
        $this->assertTrue($converter->hasProfileViolations());
    }

    public function testLinkPolicyAllowsWhitelistedDomain(): void
    {
        $profile = (new Profile())
            ->allowInline([NodeType::TEXT, NodeType::LINK])
            ->allowBlock([NodeType::PARAGRAPH])
            ->setLinkPolicy(LinkPolicy::allowlist(['trusted.com']));

        $converter = new DjotConverter(profile: $profile);
        $html = $converter->convert('[link](https://trusted.com/page)');

        $this->assertStringContainsString('trusted.com', $html);
        $this->assertFalse($converter->hasProfileViolations());
    }

    public function testLinkPolicyInternalOnlyBlocksExternal(): void
    {
        $profile = (new Profile())
            ->allowInline([NodeType::TEXT, NodeType::LINK])
            ->allowBlock([NodeType::PARAGRAPH])
            ->setLinkPolicy(LinkPolicy::internalOnly());

        $converter = new DjotConverter(profile: $profile);
        $html = $converter->convert('[external](https://example.com)');

        $this->assertStringNotContainsString('example.com', $html);
        $this->assertTrue($converter->hasProfileViolations());
    }

    public function testLinkPolicyInternalOnlyAllowsRelative(): void
    {
        $profile = (new Profile())
            ->allowInline([NodeType::TEXT, NodeType::LINK])
            ->allowBlock([NodeType::PARAGRAPH])
            ->setLinkPolicy(LinkPolicy::internalOnly());

        $converter = new DjotConverter(profile: $profile);
        $html = $converter->convert('[internal](/about)');

        $this->assertStringContainsString('/about', $html);
        $this->assertFalse($converter->hasProfileViolations());
    }

    public function testImageWithDisallowedUrl(): void
    {
        $profile = (new Profile())
            ->allowInline([NodeType::TEXT, NodeType::IMAGE])
            ->allowBlock([NodeType::PARAGRAPH])
            ->setLinkPolicy(LinkPolicy::allowlist(['trusted.com']));

        $converter = new DjotConverter(profile: $profile);
        $html = $converter->convert('![alt](https://evil.com/image.jpg)');

        $this->assertStringNotContainsString('evil.com', $html);
        $this->assertTrue($converter->hasProfileViolations());
    }

    public function testLinkWithExistingRelAttribute(): void
    {
        $profile = (new Profile())
            ->allowInline([NodeType::TEXT, NodeType::LINK])
            ->allowBlock([NodeType::PARAGRAPH])
            ->setLinkPolicy(
                LinkPolicy::unrestricted()->addRelAttribute('nofollow'),
            );

        $converter = new DjotConverter(profile: $profile);
        // Link with existing rel attribute
        $html = $converter->convert('[link](https://example.com){rel="author"}');

        // Should have both nofollow and author
        $this->assertStringContainsString('nofollow', $html);
        $this->assertStringContainsString('author', $html);
    }

    // ==================== Profile Getter Tests ====================

    public function testGetFeatureReasons(): void
    {
        $profile = Profile::comment();
        $reasons = $profile->getFeatureReasons();

        $this->assertIsArray($reasons);
        $this->assertArrayHasKey(NodeType::HEADING, $reasons);
        $this->assertArrayHasKey(NodeType::IMAGE, $reasons);
    }

    public function testSetFeatureReason(): void
    {
        $profile = (new Profile())
            ->denyBlock([NodeType::HEADING])
            ->setFeatureReason(NodeType::HEADING, 'Custom reason for heading');

        $reason = $profile->getReasonDisallowed(NodeType::HEADING);
        $this->assertEquals('Custom reason for heading', $reason);
    }

    public function testGetAllowedInline(): void
    {
        $profile = (new Profile())->allowInline([NodeType::TEXT, NodeType::STRONG]);
        $allowed = $profile->getAllowedInline();

        $this->assertContains(NodeType::TEXT, $allowed);
        $this->assertContains(NodeType::STRONG, $allowed);
    }

    public function testGetAllowedBlock(): void
    {
        $profile = (new Profile())->allowBlock([NodeType::PARAGRAPH]);
        $allowed = $profile->getAllowedBlock();

        $this->assertContains(NodeType::PARAGRAPH, $allowed);
    }

    public function testGetDeniedInline(): void
    {
        $profile = (new Profile())->denyInline([NodeType::LINK]);
        $denied = $profile->getDeniedInline();

        $this->assertContains(NodeType::LINK, $denied);
    }

    public function testGetDeniedBlock(): void
    {
        $profile = (new Profile())->denyBlock([NodeType::HEADING]);
        $denied = $profile->getDeniedBlock();

        $this->assertContains(NodeType::HEADING, $denied);
    }

    public function testIsTypeAllowedWithUnknownType(): void
    {
        $profile = Profile::full();

        // Unknown types should be denied
        $this->assertFalse($profile->isTypeAllowed('unknown_type'));
    }

    public function testIsTypeAllowedWithDocumentType(): void
    {
        $profile = Profile::minimal();

        // Document type should always be allowed
        $this->assertTrue($profile->isTypeAllowed('document'));
    }

    public function testGetLinkPolicy(): void
    {
        $policy = LinkPolicy::internalOnly();
        $profile = (new Profile())->setLinkPolicy($policy);

        $this->assertSame($policy, $profile->getLinkPolicy());
    }

    public function testGetDisallowedAction(): void
    {
        $profile = (new Profile())->onDisallowed(Profile::ACTION_STRIP);
        $this->assertEquals(Profile::ACTION_STRIP, $profile->getDisallowedAction());
    }

    // ==================== ProfileViolation Tests ====================

    public function testProfileViolationMessage(): void
    {
        $violation = new ProfileViolation('heading', 'element_not_allowed', 'Headings are disabled');

        $message = $violation->getMessage();
        $this->assertStringContainsString('heading', $message);
        $this->assertStringContainsString('element_not_allowed', $message);
        $this->assertStringContainsString('Headings are disabled', $message);
    }

    public function testProfileViolationMessageWithoutDescription(): void
    {
        $violation = new ProfileViolation('heading', 'element_not_allowed');

        $message = $violation->getMessage();
        $this->assertStringContainsString('heading', $message);
        $this->assertStringNotContainsString('()', $message);
    }

    // ==================== ProfileViolationException Tests ====================

    public function testProfileViolationExceptionContainsViolations(): void
    {
        $profile = Profile::comment()->onDisallowed(Profile::ACTION_ERROR);
        $converter = new DjotConverter(profile: $profile);

        try {
            $converter->convert("# Heading\n\n![image](test.jpg)");
            $this->fail('Expected ProfileViolationException');
        } catch (ProfileViolationException $e) {
            $this->assertNotEmpty($e->violations);
            $this->assertStringContainsString('heading', $e->getMessage());
        }
    }

    // ==================== Edge Cases ====================

    public function testEmptyInputWithProfile(): void
    {
        $converter = new DjotConverter(profile: Profile::comment());
        $html = $converter->convert('');

        $this->assertEquals('', trim($html));
        $this->assertFalse($converter->hasProfileViolations());
    }

    public function testNestedFormattingConvertedToText(): void
    {
        $profile = Profile::minimal();
        $converter = new DjotConverter(profile: $profile);
        // Link with nested formatting - minimal doesn't allow links
        $html = $converter->convert('[*bold link*](url)');

        // Link should be converted to text, but bold inside should remain
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('bold link', $html);
    }

    public function testMinimalProfileDefaultReason(): void
    {
        $profile = Profile::minimal();
        // Links are disallowed in minimal profile
        $reason = $profile->getReasonDisallowed(NodeType::LINK);

        // Should have a specific reason for links
        $this->assertNotNull($reason);
        $this->assertStringContainsString('Links', $reason);
    }

    public function testBlockNodeWithNoTextContent(): void
    {
        $profile = Profile::comment();
        $converter = new DjotConverter(profile: $profile);
        // Thematic break has no text content
        $html = $converter->convert("Paragraph\n\n---\n\nAnother");

        // Thematic break should just be removed
        $this->assertStringNotContainsString('<hr', $html);
        $this->assertStringContainsString('Paragraph', $html);
        $this->assertStringContainsString('Another', $html);
    }

    // ==================== Empty Container Cleanup Tests ====================

    public function testEmptyListItemsAreRemoved(): void
    {
        $profile = Profile::comment();
        $converter = new DjotConverter(profile: $profile);

        // List with images without alt text (not allowed in comment profile)
        $djot = <<<'DJOT'
- ![](img1.jpg)
- ![](img2.jpg)
- ![](img3.jpg)
DJOT;

        $html = $converter->convert($djot);

        // Images should be filtered, and empty list items should be removed
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<li>', $html);
        $this->assertStringNotContainsString('<ul>', $html);
    }

    public function testMixedListItemsPreserveNonEmpty(): void
    {
        $profile = Profile::comment();
        $converter = new DjotConverter(profile: $profile);

        $djot = <<<'DJOT'
- Text item
- ![](img.jpg)
- Another text
DJOT;

        $html = $converter->convert($djot);

        // Image should be filtered, but text items preserved
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Text item', $html);
        $this->assertStringContainsString('Another text', $html);
        // Should have list with 2 items
        $this->assertEquals(2, substr_count($html, '<li>'));
    }

    public function testEmptyParagraphsAreRemoved(): void
    {
        $profile = Profile::minimal();
        $converter = new DjotConverter(profile: $profile);

        // Image without alt text alone in a paragraph (not allowed in minimal)
        $html = $converter->convert("Text before\n\n![](img.jpg)\n\nText after");

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Text before', $html);
        $this->assertStringContainsString('Text after', $html);
        // Should have 2 paragraphs, not 3 with an empty one
        $this->assertEquals(2, substr_count($html, '<p>'));
    }

    public function testImageAltTextPreserved(): void
    {
        $profile = Profile::comment();
        $converter = new DjotConverter(profile: $profile);

        $html = $converter->convert('![descriptive alt text](img.jpg)');

        // Image converted to text preserving alt text
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('descriptive alt text', $html);
    }

    public function testCodeBlockContentPreservedAsText(): void
    {
        $profile = Profile::minimal();
        $converter = new DjotConverter(profile: $profile);

        $html = $converter->convert("```\ncode block content\n```");

        // Code block converted to text preserving content
        $this->assertStringNotContainsString('<pre>', $html);
        $this->assertStringNotContainsString('<code>', $html);
        $this->assertStringContainsString('code block content', $html);
    }

    // ==================== Whitespace Preservation Tests ====================

    public function testFilteredBlocksDoNotRunTogether(): void
    {
        $profile = Profile::comment();
        $converter = new DjotConverter(profile: $profile);

        $djot = <<<'DJOT'
# First Heading

# Second Heading

Regular paragraph
DJOT;

        $html = $converter->convert($djot);

        // Headings should be converted to text in separate paragraphs
        $this->assertStringNotContainsString('<h1>', $html);
        $this->assertStringContainsString('First Heading', $html);
        $this->assertStringContainsString('Second Heading', $html);
        // Text should not run together
        $this->assertStringNotContainsString('First HeadingSecond Heading', $html);
    }

    public function testFilteredInlineElementsPreserveText(): void
    {
        $profile = Profile::minimal();
        $converter = new DjotConverter(profile: $profile);

        // Link with text (links not allowed in minimal)
        $html = $converter->convert('Visit [our website](https://example.com) today!');

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('our website', $html);
        $this->assertStringContainsString('Visit', $html);
        $this->assertStringContainsString('today!', $html);
    }

    public function testNestedBlockContentPreservesWhitespace(): void
    {
        $profile = Profile::minimal();
        $converter = new DjotConverter(profile: $profile);

        // Blockquote with multiple paragraphs (not allowed in minimal)
        $djot = <<<'DJOT'
> First line
> Second line
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringNotContainsString('<blockquote>', $html);
        $this->assertStringContainsString('First line', $html);
        $this->assertStringContainsString('Second line', $html);
        // Should not run together
        $this->assertStringNotContainsString('First lineSecond line', $html);
    }
}
