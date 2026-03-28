<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\DefaultAttributesExtension;
use PHPUnit\Framework\TestCase;

class DefaultAttributesExtensionTest extends TestCase
{
    public function testImageLazyLoading(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new DefaultAttributesExtension([
            'image' => ['loading' => 'lazy', 'decoding' => 'async'],
        ]));

        $html = $converter->convert('![Alt text](image.jpg)');

        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('decoding="async"', $html);
    }

    public function testTableClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new DefaultAttributesExtension([
            'table' => ['class' => 'table table-striped'],
        ]));

        $html = $converter->convert("| A | B |\n|---|---|\n| 1 | 2 |");

        $this->assertStringContainsString('class="table table-striped"', $html);
    }

    public function testLinkClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new DefaultAttributesExtension([
            'link' => ['class' => 'link'],
        ]));

        $html = $converter->convert('[Example](https://example.com)');

        $this->assertStringContainsString('class="link"', $html);
    }

    public function testCodeBlockClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new DefaultAttributesExtension([
            'code_block' => ['class' => 'highlight'],
        ]));

        $html = $converter->convert("```php\necho 'hello';\n```");

        $this->assertStringContainsString('class="highlight', $html);
    }

    public function testExistingAttributeNotOverwritten(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new DefaultAttributesExtension([
            'image' => ['loading' => 'lazy'],
        ]));

        // Image with explicit loading attribute
        $html = $converter->convert('![Alt](image.jpg){loading=eager}');

        $this->assertStringContainsString('loading="eager"', $html);
        $this->assertStringNotContainsString('loading="lazy"', $html);
    }

    public function testClassesMerged(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new DefaultAttributesExtension([
            'paragraph' => ['class' => 'default-class'],
        ]));

        $html = $converter->convert('{.custom-class}' . "\n" . 'Some text');

        $this->assertStringContainsString('custom-class', $html);
        $this->assertStringContainsString('default-class', $html);
    }

    public function testMultipleElementTypes(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new DefaultAttributesExtension([
            'image' => ['loading' => 'lazy'],
            'link' => ['class' => 'link'],
            'table' => ['class' => 'table'],
        ]));

        $html = $converter->convert("![img](a.jpg)\n\n[link](b.com)\n\n| A |\n|---|\n| 1 |");

        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('class="link"', $html);
        $this->assertStringContainsString('class="table"', $html);
    }

    public function testEmptyDefaultsNoOp(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new DefaultAttributesExtension([]));

        $html = $converter->convert('![Alt](image.jpg)');

        $this->assertStringNotContainsString('loading=', $html);
    }

    public function testParagraphClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new DefaultAttributesExtension([
            'paragraph' => ['class' => 'prose'],
        ]));

        $html = $converter->convert('Hello world');

        $this->assertStringContainsString('<p class="prose">', $html);
    }

    public function testBlockQuoteClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new DefaultAttributesExtension([
            'block_quote' => ['class' => 'quote'],
        ]));

        $html = $converter->convert('> A quote');

        $this->assertStringContainsString('class="quote"', $html);
    }

    public function testDivClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new DefaultAttributesExtension([
            'div' => ['class' => 'container'],
        ]));

        $html = $converter->convert("::: note\nContent\n:::");

        $this->assertStringContainsString('note', $html);
        $this->assertStringContainsString('container', $html);
    }

    public function testHeadingDataAttribute(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new DefaultAttributesExtension([
            'heading' => ['data-toc' => 'true'],
        ]));

        $html = $converter->convert('## Heading');

        $this->assertStringContainsString('data-toc="true"', $html);
    }

    public function testSpanClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new DefaultAttributesExtension([
            'span' => ['class' => 'inline'],
        ]));

        $html = $converter->convert('[some text]{}');

        $this->assertStringContainsString('class="inline"', $html);
    }

    public function testRepeatedRenderDoesNotDuplicateDefaultClasses(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new DefaultAttributesExtension([
            'paragraph' => ['class' => 'prose'],
        ]));

        $document = $converter->parse('Hello world');

        $first = $converter->render($document);
        $second = $converter->render($document);

        $this->assertStringContainsString('<p class="prose">', $first);
        $this->assertStringContainsString('<p class="prose">', $second);
        $this->assertStringNotContainsString('prose prose', $second);
    }
}
