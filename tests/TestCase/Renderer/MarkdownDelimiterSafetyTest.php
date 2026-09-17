<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\Node\Block\Paragraph;
use Djot\Node\Document;
use Djot\Node\Inline\Delete;
use Djot\Node\Inline\Emphasis;
use Djot\Node\Inline\Span;
use Djot\Node\Inline\Strong;
use Djot\Node\Inline\Text;
use Djot\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownDelimiterSafetyTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<\Djot\Node\Node>, string, string, string, string}>
     */
    public static function delimiterCases(): iterable
    {
        yield 'leading padding moves outside strong' => [Strong::class, 'a', ' b', 'c', 'a **b**c'];
        yield 'punctuation-bound emphasis uses HTML' => [Emphasis::class, 'a', '!x', 'b', 'a<em>!x</em>b'];
        yield 'punctuation-bound strong uses HTML' => [Strong::class, 'a ', 'x!', 'b', 'a <strong>x!</strong>b'];
        yield 'literal tilde beside deletion is escaped' => [Delete::class, 'a ', 'x', '~b', 'a ~~x~~\\~b'];
        yield 'ordinary emphasis keeps Markdown' => [Emphasis::class, '', 'x', '', '*x*'];
    }

    public function testTransparentSpanStillUsesTheOuterSeam(): void
    {
        $document = new Document();
        $paragraph = new Paragraph();
        $document->appendChild($paragraph);
        $paragraph->appendChild(new Text('a'));
        $span = new Span();
        $emphasis = new Emphasis();
        $emphasis->appendChild(new Text('!x'));
        $span->appendChild($emphasis);
        $paragraph->appendChild($span);
        $paragraph->appendChild(new Text('b'));

        self::assertSame("a<em>!x</em>b\n", (new MarkdownRenderer())->render($document));
    }

    public function testNestedRunsPreserveTheirOrder(): void
    {
        $document = new Document();
        $paragraph = new Paragraph();
        $document->appendChild($paragraph);
        $strong = new Strong();
        $emphasis = new Emphasis();
        $emphasis->appendChild(new Text('x'));
        $strong->appendChild($emphasis);
        $paragraph->appendChild($strong);

        self::assertSame("**<em>x</em>**\n", (new MarkdownRenderer())->render($document));
    }

    /**
     * @param class-string<\Djot\Node\Node> $nodeClass
     * @param string $before
     * @param string $inside
     * @param string $after
     * @param string $expected
     */
    #[DataProvider('delimiterCases')]
    public function testDelimiterRunsKeepTheirTree(
        string $nodeClass,
        string $before,
        string $inside,
        string $after,
        string $expected,
    ): void {
        $document = new Document();
        $paragraph = new Paragraph();
        $document->appendChild($paragraph);
        $paragraph->appendChild(new Text($before));
        $span = new $nodeClass();
        $span->appendChild(new Text($inside));
        $paragraph->appendChild($span);
        $paragraph->appendChild(new Text($after));

        self::assertSame($expected . "\n", (new MarkdownRenderer())->render($document));
    }
}
