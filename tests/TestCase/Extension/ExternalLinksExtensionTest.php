<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\ExternalLinksExtension;
use PHPUnit\Framework\TestCase;

class ExternalLinksExtensionTest extends TestCase
{
    public function testExternalLinkGetsTargetAndRel(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new ExternalLinksExtension());

        $html = $converter->convert('[Example](https://example.com)');

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function testInternalLinkUnchanged(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new ExternalLinksExtension());

        $html = $converter->convert('[Home](/home)');

        $this->assertStringNotContainsString('target=', $html);
        $this->assertStringNotContainsString('rel=', $html);
    }

    public function testInternalHostsExcluded(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new ExternalLinksExtension(
            internalHosts: ['example.com', 'www.example.com'],
        ));

        $html = $converter->convert('[Example](https://example.com/page)');

        $this->assertStringNotContainsString('target=', $html);
        $this->assertStringNotContainsString('rel=', $html);
    }

    public function testCustomTargetAndRel(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new ExternalLinksExtension(
            target: '_self',
            rel: 'external',
        ));

        $html = $converter->convert('[Example](https://example.com)');

        $this->assertStringContainsString('target="_self"', $html);
        $this->assertStringContainsString('rel="external"', $html);
    }

    public function testNofollow(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new ExternalLinksExtension(
            nofollow: true,
        ));

        $html = $converter->convert('[Example](https://example.com)');

        $this->assertStringContainsString('nofollow', $html);
    }

    public function testHttpAndHttpsLinks(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new ExternalLinksExtension());

        $httpHtml = $converter->convert('[HTTP](http://example.com)');
        $httpsHtml = $converter->convert('[HTTPS](https://example.com)');

        $this->assertStringContainsString('target="_blank"', $httpHtml);
        $this->assertStringContainsString('target="_blank"', $httpsHtml);
    }

    public function testMailtoLinkUnchanged(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new ExternalLinksExtension());

        $html = $converter->convert('[Email](mailto:test@example.com)');

        $this->assertStringNotContainsString('target=', $html);
    }
}
