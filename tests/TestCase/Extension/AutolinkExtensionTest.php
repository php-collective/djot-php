<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\AutolinkExtension;
use PHPUnit\Framework\TestCase;

class AutolinkExtensionTest extends TestCase
{
    public function testHttpsUrl(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AutolinkExtension());

        $html = $converter->convert('Visit https://example.com for more info.');

        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('>https://example.com</a>', $html);
    }

    public function testHttpUrl(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AutolinkExtension());

        $html = $converter->convert('Old site at http://example.com still works.');

        $this->assertStringContainsString('href="http://example.com"', $html);
    }

    public function testUrlWithPath(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AutolinkExtension());

        $html = $converter->convert('Check https://example.com/path/to/page for details.');

        $this->assertStringContainsString('href="https://example.com/path/to/page"', $html);
    }

    public function testUrlWithQueryString(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AutolinkExtension());

        $html = $converter->convert('Search at https://example.com/search?q=test&page=1 now.');

        $this->assertStringContainsString('href="https://example.com/search?q=test&amp;page=1"', $html);
    }

    public function testTrailingPunctuation(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AutolinkExtension());

        // Period should not be part of URL
        $html = $converter->convert('Visit https://example.com.');

        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('>https://example.com</a>.', $html);
    }

    public function testMultipleUrls(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AutolinkExtension());

        $html = $converter->convert('See https://example.com and https://other.com for info.');

        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('href="https://other.com"', $html);
    }

    public function testBareEmail(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AutolinkExtension());

        $html = $converter->convert('Contact user@example.com for help.');

        $this->assertStringContainsString('href="mailto:user@example.com"', $html);
        $this->assertStringContainsString('>user@example.com</a>', $html);
    }

    public function testMailtoLink(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AutolinkExtension());

        $html = $converter->convert('Email mailto:user@example.com directly.');

        $this->assertStringContainsString('href="mailto:user@example.com"', $html);
        // Display should not have mailto: prefix
        $this->assertStringContainsString('>user@example.com</a>', $html);
    }

    public function testDisallowedScheme(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AutolinkExtension(
            allowedSchemes: ['https'],
        ));

        $html = $converter->convert('See http://insecure.com for old stuff.');

        // http should not be linked
        $this->assertStringNotContainsString('href="http://insecure.com"', $html);
        $this->assertStringContainsString('http://insecure.com', $html);
    }

    public function testNoMailtoWhenDisabled(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AutolinkExtension(
            allowedSchemes: ['https', 'http'],
        ));

        $html = $converter->convert('Email user@example.com for help.');

        // Email should not be linked when mailto not in allowed schemes
        $this->assertStringNotContainsString('href="mailto:', $html);
        $this->assertStringContainsString('user@example.com', $html);
    }

    public function testDoesNotBreakExistingLinks(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AutolinkExtension());

        // Explicit link syntax should still work
        $html = $converter->convert('[Click here](https://example.com)');

        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('>Click here</a>', $html);
    }

    public function testUrlAtStartOfLine(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AutolinkExtension());

        $html = $converter->convert('https://example.com is a great site.');

        $this->assertStringContainsString('href="https://example.com"', $html);
    }

    public function testUrlAtEndOfLine(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new AutolinkExtension());

        $html = $converter->convert('Visit https://example.com');

        $this->assertStringContainsString('href="https://example.com"', $html);
    }
}
