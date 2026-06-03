<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Converter;

use Djot\Converter\HtmlToDjot;
use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip stability for the DEFAULT DjotConverter output (no round-trip
 * annotations), i.e. the path a WYSIWYG/HTML-serializing tool exercises:
 *
 *   Djot -> HTML (pass 1) -> Djot -> HTML (pass 2)
 *
 * HTML stability (pass 1 === pass 2) is the meaningful fidelity signal: the
 * intermediate Djot text is allowed to be normalized as long as it renders to
 * the same HTML.
 */
class HtmlToDjotDefaultRoundTripTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function stableCases(): array
    {
        return [
            'image in link' => ['[![alt](/i.png)](https://x.com)'],
            'literal bracket in link label' => ['[a]b](https://x.com)'],
            'footnote' => ["Text[^1]\n\n[^1]: note"],
            'footnote in list' => ["- item[^1]\n\n[^1]: note"],
            'loose unordered list' => ["- one\n\n- two"],
            'loose ordered list' => ["1. one\n\n2. two"],
            'tight list' => ["- one\n- two"],
            'explicit heading id' => ["{#myid}\n# Heading"],
            'explicit id on punctuation heading' => ["{#custom}\n# !!!"],
            'auto heading id' => ['# Heading'],
            'transliterated heading id' => ['# Über café'],
        ];
    }

    #[DataProvider('stableCases')]
    public function testDefaultRoundTripIsHtmlStable(string $djot): void
    {
        $toHtml = new DjotConverter(xhtml: true);
        $toDjot = new HtmlToDjot();

        $html1 = $toHtml->convert($djot);
        $djot2 = $toDjot->convert($html1);
        $html2 = (new DjotConverter(xhtml: true))->convert($djot2);

        $this->assertSame($html1, $html2, 'Round-trip changed the rendered HTML. Intermediate Djot: ' . $djot2);
    }
}
