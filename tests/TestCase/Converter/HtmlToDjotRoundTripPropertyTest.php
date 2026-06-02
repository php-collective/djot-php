<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Converter;

use Djot\Converter\HtmlToDjot;
use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Property net for the HTML -> Djot -> HTML round-trip of externally-authored
 * HTML (WYSIWYG editors, CMS exports, pasted content).
 *
 * For a paragraph of literal text, converting to Djot and back must not change
 * the block structure (no list, heading, blockquote, div, definition list, rule
 * or table injected) and must not lose the text. This sweep over Djot-significant
 * leading tokens and inline markers is what catches "a literal marker silently
 * became a block" regressions across positions, instead of one example at a time.
 */
class HtmlToDjotRoundTripPropertyTest extends TestCase
{
    protected HtmlToDjot $converter;

    protected DjotConverter $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new HtmlToDjot();
        $this->renderer = new DjotConverter();
    }

    /**
     * Tokens that, at the start of a line, can open a Djot block; plus inline
     * markers embedded in the body that can open inline constructs.
     *
     * @return array<string, array{string}>
     */
    public static function literalParagraphProvider(): array
    {
        $leadingTokens = [
            '-', '+', '*', '#', '##', '###', '>', '>>',
            ':', '::', ':::', '1.', '1)', '2.', '---', '***', '___',
            '~~~', '```', '|', '=',
        ];
        $bodies = [
            'plain words here',
            'with *stars* and _unders_ inline',
            'a [link](http://x.test) and `code`',
        ];
        $wrappers = [
            'bare' => static fn (string $inner): string => '<p>' . $inner . '</p>',
            'span' => static fn (string $inner): string => '<p><span>' . $inner . '</span></p>',
        ];

        $cases = [];
        foreach ($leadingTokens as $token) {
            foreach ($bodies as $bi => $body) {
                foreach ($wrappers as $wname => $wrap) {
                    $text = $token . ' ' . $body;
                    $inner = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5);
                    $cases["{$token} / b{$bi} / {$wname}"] = [$wrap($inner), $text];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('literalParagraphProvider')]
    public function testLiteralParagraphRoundTripIsStable(string $html, string $text): void
    {
        $djot = $this->converter->convert($html);
        $out = $this->renderer->convert($djot);

        $forbidden = [
            '<ul', '<ol', '<hr', '<h1', '<h2', '<h3', '<h4', '<h5', '<h6',
            '<blockquote', '<div', '<dl', '<table', '<section', '<pre',
        ];
        foreach ($forbidden as $tag) {
            $this->assertStringNotContainsString(
                $tag,
                $out,
                "Literal paragraph gained block `{$tag}`.\nInput: {$html}\nDjot: {$djot}\nOut: {$out}",
            );
        }

        $this->assertStringContainsString('<p', $out, "Lost the paragraph.\nDjot: {$djot}\nOut: {$out}");

        // Text must survive (alphanumerics only, to ignore smart punctuation and
        // entity escaping). Catches data loss such as text captured into an
        // attribute (`::: x` -> `<div class="x">`).
        $outText = html_entity_decode(strip_tags($out), ENT_QUOTES | ENT_HTML5);
        $this->assertSame(
            $this->alnum($text),
            $this->alnum($outText),
            "Literal text was lost or altered.\nDjot: {$djot}\nOut: {$out}",
        );
    }

    public function testDefinitionDescriptionLeadingColonStaysLiteral(): void
    {
        $html = '<dl><dt>term</dt><dd>: value</dd></dl>';
        $out = $this->renderer->convert($this->converter->convert($html));

        // Exactly one definition list: the leading colon must not open a nested one.
        $this->assertSame(1, substr_count($out, '<dl'));
        $this->assertStringContainsString('value', strip_tags($out));
    }

    public function testListItemLeadingColonStaysLiteral(): void
    {
        $html = '<ul><li>: not a definition</li></ul>';
        $out = $this->renderer->convert($this->converter->convert($html));

        $this->assertStringNotContainsString('<dl', $out);
        $this->assertSame(1, substr_count($out, '<ul'));
    }

    /**
     * Two adjacent inline elements that share a Djot delimiter must not merge
     * into one malformed token on the round-trip. `<em>a</em><em>b</em>` has to
     * stay two emphasis runs, not collapse to `_a__b_` -> `<em>a_</em>b_`.
     *
     * @return array<string, array{string}>
     */
    public static function adjacentInlineProvider(): array
    {
        $tags = ['em', 'strong', 'sub', 'sup', 'code', 'del', 'mark', 'ins'];

        $cases = [];
        foreach ($tags as $tag) {
            $cases["two {$tag}"] = ["<p><{$tag}>a</{$tag}><{$tag}>b</{$tag}></p>"];
            $cases["three {$tag}"] = ["<p><{$tag}>a</{$tag}><{$tag}>b</{$tag}><{$tag}>c</{$tag}></p>"];
        }
        // Mixed delimiters already serialize unambiguously; guard against regressions.
        $cases['em then strong'] = ['<p><em>a</em><strong>b</strong></p>'];
        $cases['em space em'] = ['<p><em>a</em> <em>b</em></p>'];

        return $cases;
    }

    #[DataProvider('adjacentInlineProvider')]
    public function testAdjacentInlineElementsRoundTrip(string $html): void
    {
        $djot = $this->converter->convert($html);
        $out = trim($this->renderer->convert($djot));

        $this->assertSame($html, $out, 'Djot intermediate: ' . $djot);
    }

    protected function alnum(string $value): string
    {
        return strtolower((string)preg_replace('/[^a-z0-9]/i', '', $value));
    }
}
