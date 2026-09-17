<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Converter;

use Djot\Converter\HtmlToDjot;
use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlToDjotFidelityBackportTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function htmlCases(): iterable
    {
        yield 'formatting edge space' => ['<p><strong>x </strong>y</p>', '<p><strong>x </strong>y</p>'];

        yield 'link edge space' => ['<p><a href="u">x </a>y</p>', '<p><a href="u">x </a>y</p>'];

        yield 'whitespace-only formatting' => ['<p>a<strong> </strong>b</p>', '<p>a<strong> </strong>b</p>'];

        yield 'quote inline grouping' => [
            '<blockquote>one<em>two</em>three</blockquote>',
            "<blockquote>\n<p>one<em>two</em>three</p>\n</blockquote>",
        ];

        yield 'quote inline whitespace' => [
            '<blockquote><strong>a</strong> <em>b</em></blockquote>',
            "<blockquote>\n<p><strong>a</strong> <em>b</em></p>\n</blockquote>",
        ];

        yield 'nested quote marks' => [
            '<p><q>outer <q>inner</q></q></p>',
            '<p>“outer ‘inner’”</p>',
        ];

        yield 'nested quote keeps authored inner quotes' => [
            '<p><q>outer <q>He said "hi"</q></q></p>',
            '<p>“outer ‘He said "hi"’”</p>',
        ];

        yield 'literal smart-punctuation runs' => [
            '<p>a -- b --- c ... d</p>',
            '<p>a -- b --- c ... d</p>',
        ];
    }

    #[DataProvider('htmlCases')]
    public function testHtmlMeaningSurvivesTheDjotSource(string $html, string $expected): void
    {
        $djot = (new HtmlToDjot())->convert($html);

        self::assertSame($expected . "\n", (new DjotConverter())->convert($djot));
    }
}
