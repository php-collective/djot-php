<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\LinkPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LinkPolicy URL restrictions
 */
class LinkPolicyTest extends TestCase
{
    // ==================== Factory Method Tests ====================

    public function testUnrestrictedAllowsAllUrls(): void
    {
        $policy = LinkPolicy::unrestricted();

        $this->assertTrue($policy->isUrlAllowed('https://example.com'));
        $this->assertTrue($policy->isUrlAllowed('http://example.com'));
        $this->assertTrue($policy->isUrlAllowed('/path'));
        $this->assertTrue($policy->isUrlAllowed('#anchor'));
        $this->assertTrue($policy->isUrlAllowed('./relative'));
        $this->assertTrue($policy->isUrlAllowed('../parent'));
    }

    public function testUnrestrictedBlocksDangerousSchemes(): void
    {
        $policy = LinkPolicy::unrestricted();

        $this->assertFalse($policy->isUrlAllowed('javascript:alert(1)'));
        $this->assertFalse($policy->isUrlAllowed('vbscript:msgbox(1)'));
        $this->assertFalse($policy->isUrlAllowed('data:text/html,<script>'));
        $this->assertFalse($policy->isUrlAllowed('file:///etc/passwd'));
    }

    public function testInternalOnlyAllowsRelativeUrls(): void
    {
        $policy = LinkPolicy::internalOnly();

        $this->assertTrue($policy->isUrlAllowed('#anchor'));
        $this->assertTrue($policy->isUrlAllowed('/page'));
        $this->assertTrue($policy->isUrlAllowed('./relative'));
        $this->assertTrue($policy->isUrlAllowed('../parent'));
    }

    public function testInternalOnlyBlocksExternalUrls(): void
    {
        $policy = LinkPolicy::internalOnly();

        $this->assertFalse($policy->isUrlAllowed('https://example.com'));
        $this->assertFalse($policy->isUrlAllowed('http://external.com/path'));
        $this->assertFalse($policy->isUrlAllowed('//external.com/path'));
    }

    public function testInternalOnlyAllowsMailtoAndTel(): void
    {
        $policy = LinkPolicy::internalOnly();

        $this->assertTrue($policy->isUrlAllowed('mailto:test@example.com'));
        $this->assertTrue($policy->isUrlAllowed('tel:+1234567890'));
    }

    public function testAllowlistOnlyAllowsListedDomains(): void
    {
        $policy = LinkPolicy::allowlist(['github.com', 'example.com']);

        $this->assertTrue($policy->isUrlAllowed('https://github.com/user'));
        $this->assertTrue($policy->isUrlAllowed('https://example.com/page'));
        $this->assertTrue($policy->isUrlAllowed('https://www.github.com/repo'));
        $this->assertFalse($policy->isUrlAllowed('https://malware.com'));
        $this->assertFalse($policy->isUrlAllowed('https://evil.example.org'));
    }

    // ==================== Scheme Tests ====================

    public function testCustomDeniedSchemes(): void
    {
        $policy = (new LinkPolicy())->setDeniedSchemes(['ftp', 'ssh']);

        $this->assertTrue($policy->isUrlAllowed('https://example.com'));
        $this->assertTrue($policy->isUrlAllowed('javascript:void(0)')); // Not in custom list
        $this->assertFalse($policy->isUrlAllowed('ftp://server.com'));
        $this->assertFalse($policy->isUrlAllowed('ssh://server.com'));
    }

    public function testCustomAllowedSchemes(): void
    {
        $policy = (new LinkPolicy())->setAllowedSchemes(['https', 'mailto']);

        $this->assertTrue($policy->isUrlAllowed('https://example.com'));
        $this->assertTrue($policy->isUrlAllowed('mailto:test@example.com'));
        $this->assertFalse($policy->isUrlAllowed('http://example.com'));
        $this->assertFalse($policy->isUrlAllowed('ftp://files.com'));
    }

    // ==================== Domain Tests ====================

    public function testDeniedDomains(): void
    {
        $policy = (new LinkPolicy())->setDeniedDomains(['evil.com', 'spam.org']);

        $this->assertTrue($policy->isUrlAllowed('https://example.com'));
        $this->assertFalse($policy->isUrlAllowed('https://evil.com'));
        $this->assertFalse($policy->isUrlAllowed('https://subdomain.evil.com'));
        $this->assertFalse($policy->isUrlAllowed('https://spam.org/page'));
    }

    public function testAllowedDomainsWithSubdomains(): void
    {
        $policy = (new LinkPolicy())->setAllowedDomains(['example.com']);

        $this->assertTrue($policy->isUrlAllowed('https://example.com'));
        $this->assertTrue($policy->isUrlAllowed('https://www.example.com'));
        $this->assertTrue($policy->isUrlAllowed('https://api.example.com'));
        $this->assertFalse($policy->isUrlAllowed('https://other.com'));
    }

    // ==================== Rel Attribute Tests ====================

    public function testAddRelAttribute(): void
    {
        $policy = (new LinkPolicy())
            ->addRelAttribute('nofollow')
            ->addRelAttribute('noopener');

        $attrs = $policy->getRelAttributes();
        $this->assertContains('nofollow', $attrs);
        $this->assertContains('noopener', $attrs);
    }

    public function testAddRelAttributeNoDuplicates(): void
    {
        $policy = (new LinkPolicy())
            ->addRelAttribute('nofollow')
            ->addRelAttribute('nofollow');

        $attrs = $policy->getRelAttributes();
        $this->assertCount(1, $attrs);
    }

    public function testSetRelAttributes(): void
    {
        $policy = (new LinkPolicy())
            ->setRelAttributes(['nofollow', 'ugc', 'noopener']);

        $attrs = $policy->getRelAttributes();
        $this->assertCount(3, $attrs);
        $this->assertContains('ugc', $attrs);
    }

    // ==================== Empty URL Tests ====================

    public function testEmptyUrlIsAllowed(): void
    {
        $policy = LinkPolicy::unrestricted();
        $this->assertTrue($policy->isUrlAllowed(''));

        $policy = LinkPolicy::internalOnly();
        $this->assertTrue($policy->isUrlAllowed(''));
    }

    // ==================== Base Host Tests ====================

    public function testExternalDetectionWithBaseHost(): void
    {
        $policy = (new LinkPolicy())->setAllowExternal(false);

        // With a base host set, same-host URLs are allowed
        $this->assertTrue($policy->isUrlAllowed('https://example.com/page', 'example.com'));
        $this->assertFalse($policy->isUrlAllowed('https://other.com/page', 'example.com'));
        $this->assertTrue($policy->isUrlAllowed('//example.com/page', 'example.com'));
        $this->assertFalse($policy->isUrlAllowed('//other.com/page', 'example.com'));
    }

    public function testProtocolRelativeUrlRespectsAllowedSchemes(): void
    {
        $policy = (new LinkPolicy())->setAllowedSchemes(['mailto']);

        $this->assertFalse($policy->isUrlAllowed('//example.com/path'));
    }

    // ==================== Getter Tests ====================

    public function testGetters(): void
    {
        $policy = (new LinkPolicy())
            ->setAllowedSchemes(['https'])
            ->setDeniedSchemes(['ftp'])
            ->setAllowedDomains(['example.com'])
            ->setDeniedDomains(['evil.com'])
            ->setAllowExternal(false)
            ->setAllowInternal(true);

        $this->assertEquals(['https'], $policy->getAllowedSchemes());
        $this->assertEquals(['ftp'], $policy->getDeniedSchemes());
        $this->assertEquals(['example.com'], $policy->getAllowedDomains());
        $this->assertEquals(['evil.com'], $policy->getDeniedDomains());
        $this->assertFalse($policy->getAllowExternal());
        $this->assertTrue($policy->getAllowInternal());
    }
}
