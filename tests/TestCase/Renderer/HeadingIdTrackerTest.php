<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\DjotConverter;
use Djot\Node\Block\Heading;
use Djot\Node\Inline\FootnoteRef;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Strong;
use Djot\Node\Inline\Symbol;
use Djot\Node\Inline\Text;
use Djot\Renderer\AsciiTransliterator;
use Djot\Renderer\HeadingIdTracker;
use PHPUnit\Framework\TestCase;

class HeadingIdTrackerTest extends TestCase
{
    protected HeadingIdTracker $tracker;

    protected function setUp(): void
    {
        $this->tracker = new HeadingIdTracker();
    }

    public function testBasicIdGeneration(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('Hello World'));

        $id = $this->tracker->getIdForHeading($heading);

        $this->assertSame('Hello-World', $id);
    }

    public function testDeduplicatesSameText(): void
    {
        $heading1 = new Heading(2);
        $heading1->appendChild(new Text('Final Thoughts'));

        $heading2 = new Heading(2);
        $heading2->appendChild(new Text('Final Thoughts'));

        $heading3 = new Heading(2);
        $heading3->appendChild(new Text('Final Thoughts'));

        $id1 = $this->tracker->getIdForHeading($heading1);
        $id2 = $this->tracker->getIdForHeading($heading2);
        $id3 = $this->tracker->getIdForHeading($heading3);

        $this->assertSame('Final-Thoughts', $id1);
        $this->assertSame('Final-Thoughts-1', $id2);
        $this->assertSame('Final-Thoughts-2', $id3);
    }

    public function testCachingReturnsSameId(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('Cached'));

        $id1 = $this->tracker->getIdForHeading($heading);
        $id2 = $this->tracker->getIdForHeading($heading);

        $this->assertSame($id1, $id2);
        $this->assertSame('Cached', $id1);
    }

    public function testCachingDoesNotIncrementCounter(): void
    {
        $heading1 = new Heading(2);
        $heading1->appendChild(new Text('Same'));

        $heading2 = new Heading(2);
        $heading2->appendChild(new Text('Same'));

        // First call caches
        $id1 = $this->tracker->getIdForHeading($heading1);
        // Second call for same object returns cached
        $id1Again = $this->tracker->getIdForHeading($heading1);
        // Third call for different object with same text gets -1
        $id2 = $this->tracker->getIdForHeading($heading2);

        $this->assertSame('Same', $id1);
        $this->assertSame('Same', $id1Again);
        $this->assertSame('Same-1', $id2);
    }

    public function testExplicitIdAttribute(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('Some Text'));
        $heading->setAttribute('id', 'custom-id');

        $id = $this->tracker->getIdForHeading($heading);

        $this->assertSame('custom-id', $id);
    }

    public function testEmptyHeadingGetsFallbackId(): void
    {
        $heading1 = new Heading(2);
        $heading2 = new Heading(3);

        $id1 = $this->tracker->getIdForHeading($heading1);
        $id2 = $this->tracker->getIdForHeading($heading2);

        $this->assertSame('s-1', $id1);
        $this->assertSame('s-2', $id2);
    }

    public function testResetClearsState(): void
    {
        $heading1 = new Heading(2);
        $heading1->appendChild(new Text('Title'));

        $id1 = $this->tracker->getIdForHeading($heading1);
        $this->assertSame('Title', $id1);

        $this->tracker->reset();

        // After reset, a new heading with same text should get the base ID again
        $heading2 = new Heading(2);
        $heading2->appendChild(new Text('Title'));

        $id2 = $this->tracker->getIdForHeading($heading2);
        $this->assertSame('Title', $id2);
    }

    public function testTrackIdPreventsConflict(): void
    {
        // Pre-track an ID that a heading would generate
        $this->tracker->trackId('My-Heading');

        $heading = new Heading(2);
        $heading->appendChild(new Text('My Heading'));

        $id = $this->tracker->getIdForHeading($heading);

        $this->assertSame('My-Heading-1', $id);
    }

    public function testTrackIdEmptyStringIgnored(): void
    {
        $this->tracker->trackId('');

        $heading = new Heading(2);
        $heading->appendChild(new Text('Test'));

        $id = $this->tracker->getIdForHeading($heading);

        $this->assertSame('Test', $id);
    }

    /**
     * The generated `s-N` fallback must dedupe against tracked ids: an
     * earlier explicit `{#s-1}` makes the next empty/all-punctuation
     * heading take `s-2`, not duplicate `s-1`. This is what makes the
     * upfront `reserveExplicitIds` pre-pass effective end-to-end.
     */
    public function testFallbackIdSkipsReservedSN(): void
    {
        $this->tracker->trackId('s-1');

        $heading = new Heading(2);

        $this->assertSame('s-2', $this->tracker->getIdForHeading($heading));
    }

    public function testNormalizeId(): void
    {
        $this->assertSame('Hello-World', $this->tracker->normalizeId('Hello World'));
        $this->assertSame('No-Hashes', $this->tracker->normalizeId('#No# #Hashes#'));
        $this->assertSame('Trim-Me', $this->tracker->normalizeId('  Trim Me  '));
        $this->assertSame('Multiple-Spaces', $this->tracker->normalizeId('Multiple   Spaces'));
        $this->assertSame('this-t-key-params-fallback', $this->tracker->normalizeId("\$this->t(\$key, \$params = [], \$fallback = '')"));
        $this->assertSame('My-title', $this->tracker->normalizeId('My --- title'));
        // jgm/djot#393: non-ASCII is preserved (case kept), not transliterated.
        $this->assertSame('Привет-мир', $this->tracker->normalizeId('Привет мир'));
        $this->assertSame('', $this->tracker->normalizeId('###'));
        $this->assertSame('s-123-Things', $this->tracker->normalizeId('123 Things'));
        $this->assertSame('s-1-Introduction', $this->tracker->normalizeId('1. Introduction'));
    }

    /**
     * Pins djot-php's heading-ID behaviour to jgm/djot#393: each maximal run of
     * non-alphanumeric ASCII is replaced with `-` and leading/trailing `-` are
     * trimmed. Case and genuine non-ASCII characters (Cyrillic, accented Latin)
     * are preserved; `_` is replaced (no longer an exception). The parser's smart
     * punctuation is reversed to its ASCII source first, so a smart apostrophe/
     * quote becomes a separator (matching the djot.js reference, which slugs the
     * source text) rather than a preserved U+2019 glyph. A leading-digit result
     * keeps the `s-` prefix for CSS-selector safety (orthogonal to #393).
     * ASCII-folding is opt-in via AsciiHeadingIdsExtension.
     */
    public function testNormalizeIdSpecAlignmentEdgeCases(): void
    {
        $this->assertSame('A-B-C', $this->tracker->normalizeId('A+B=C'));
        $this->assertSame('Emphasis-strong', $this->tracker->normalizeId('Emphasis/strong'));
        $this->assertSame('That-s-all', $this->tracker->normalizeId("That's all"));
        // Smart apostrophe (U+2019) is reversed to its ASCII source, so it slugs
        // like a straight apostrophe instead of leaking the glyph into the id.
        $this->assertSame('That-s-all', $this->tracker->normalizeId('That’s all'));
        $this->assertSame('foo-bar', $this->tracker->normalizeId('foo...bar'));
        $this->assertSame('Uber-uns', $this->tracker->normalizeId('Uber uns'));
        $this->assertSame('Über-uns', $this->tracker->normalizeId('Über uns'));
        $this->assertSame('café-résumé', $this->tracker->normalizeId('café résumé'));
        $this->assertSame('Straße', $this->tracker->normalizeId('Straße'));
        $this->assertSame('s-2024-recap', $this->tracker->normalizeId('2024 recap'));
        $this->assertSame('', $this->tracker->normalizeId('!!!'));
    }

    /**
     * The parser renders smart punctuation into the heading text (apostrophe as
     * U+2019, quotes/dashes/ellipsis as their typographic glyphs). Those glyphs
     * are reversed to their ASCII source before slugging so the id is derived
     * from the source text (as djot.js does), rather than leaking non-ASCII
     * typography into the id.
     */
    public function testNormalizeIdReversesSmartPunctuation(): void
    {
        $this->assertSame('Bob-s-Guide', $this->tracker->normalizeId("Bob\u{2019}s Guide"));
        $this->assertSame('left-single', $this->tracker->normalizeId("\u{2018}left single\u{2019}"));
        $this->assertSame('Say-Hello', $this->tracker->normalizeId("Say \u{201C}Hello\u{201D}"));
        $this->assertSame('a-b', $this->tracker->normalizeId("a \u{2013} b"));
        $this->assertSame('a-b', $this->tracker->normalizeId("a \u{2014} b"));
        $this->assertSame('foo-bar', $this->tracker->normalizeId("foo\u{2026}bar"));
    }

    /**
     * End-to-end: a heading run through the full converter (which applies smart
     * punctuation) must still produce a source-derived id, free of typographic
     * glyphs.
     */
    public function testSmartPunctuationIdEndToEnd(): void
    {
        $converter = new DjotConverter();

        $html = $converter->convert("# That's all");
        $this->assertStringContainsString('id="That-s-all"', $html);

        $html = $converter->convert('# a -- b');
        $this->assertStringContainsString('id="a-b"', $html);

        $html = $converter->convert('# Say "Hello"');
        $this->assertStringContainsString('id="Say-Hello"', $html);
    }

    /**
     * An id transform (e.g. the one set by AsciiHeadingIdsExtension) is applied to
     * the spec id; here it transliterates non-ASCII to ASCII for portability.
     */
    public function testAsciiHeadingIdsOptInTransliterates(): void
    {
        $transliterator = new AsciiTransliterator();
        $ascii = new HeadingIdTracker(static fn (string $id): string => $transliterator->transliterate($id));
        $this->assertSame('Privet-mir', $ascii->normalizeId('Привет мир'));
        $this->assertSame('Uber-uns', $ascii->normalizeId('Über uns'));
        $this->assertSame('cafe-resume', $ascii->normalizeId('café résumé'));
    }

    public function testGetPlainText(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('Hello '));
        $strong = new Strong();
        $strong->appendChild(new Text('World'));
        $heading->appendChild($strong);

        $text = $this->tracker->getPlainText($heading);

        $this->assertSame('Hello World', $text);
    }

    public function testGetPlainTextWithBreaks(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('Hello'));
        $heading->appendChild(new SoftBreak());
        $heading->appendChild(new Text('World'));

        $text = $this->tracker->getPlainText($heading);

        $this->assertSame('Hello World', $text);
    }

    public function testGetPlainTextWithHardBreak(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('Hello'));
        $heading->appendChild(new HardBreak());
        $heading->appendChild(new Text('World'));

        $text = $this->tracker->getPlainText($heading);

        $this->assertSame('Hello World', $text);
    }

    public function testHashInHeadingText(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('C# Programming'));

        $id = $this->tracker->getIdForHeading($heading);

        $this->assertSame('C-Programming', $id);
    }

    public function testGetPlainTextCachesForHeadings(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('Original'));

        // First call caches the text
        $text1 = $this->tracker->getPlainText($heading);
        $this->assertSame('Original', $text1);

        // Modify the heading (simulates HeadingPermalinksExtension appending symbol)
        $heading->appendChild(new Text(' extra'));

        // Second call returns cached text, not the modified tree
        $text2 = $this->tracker->getPlainText($heading);
        $this->assertSame('Original', $text2);
    }

    public function testGetPlainTextCacheResetsWithReset(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('Before'));

        $this->tracker->getPlainText($heading);
        $this->tracker->reset();

        // After reset, a new heading gets fresh extraction
        $heading2 = new Heading(2);
        $heading2->appendChild(new Text('After'));

        $this->assertSame('After', $this->tracker->getPlainText($heading2));
    }

    public function testGetIdForHeadingAlsoCachesPlainText(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('Title'));

        // getIdForHeading internally calls getPlainText, which caches
        $id = $this->tracker->getIdForHeading($heading);
        $this->assertSame('Title', $id);

        // Modify heading after ID generation
        $heading->appendChild(new Text(' modified'));

        // Plain text should still return the cached original
        $text = $this->tracker->getPlainText($heading);
        $this->assertSame('Title', $text);
    }

    /**
     * The djot spec (and jgm/djot#393) says auto-generated identifiers are formed
     * from the plain text content "excluding non-textual elements such as footnote
     * references and symbols". A symbol must not leak into the ID.
     */
    public function testSymbolsExcludedFromId(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('Introduction '));
        $heading->appendChild(new Symbol('smile'));

        $id = $this->tracker->getIdForHeading($heading);

        $this->assertSame('Introduction', $id);
    }

    public function testHeadingWithOnlySymbolGetsFallbackId(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Symbol('tada'));

        $id = $this->tracker->getIdForHeading($heading);

        $this->assertSame('s-1', $id);
    }

    public function testSymbolBetweenWordsDoesNotProduceStrayDashes(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('Build'));
        $heading->appendChild(new Symbol('rocket'));
        $heading->appendChild(new Text('Status'));

        $id = $this->tracker->getIdForHeading($heading);

        $this->assertSame('BuildStatus', $id);
    }

    /**
     * Footnote references are likewise excluded from the identifier:
     * `# Introduction[^1]` generates `Introduction`, not `Introduction1`.
     */
    public function testFootnoteReferenceExcludedFromId(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('Introduction'));
        $heading->appendChild(new FootnoteRef('1'));

        $id = $this->tracker->getIdForHeading($heading);

        $this->assertSame('Introduction', $id);
    }

    /**
     * Symbols are still part of the human-readable plain text (e.g. for TOC
     * labels); only the *identifier* excludes them. This pins that boundary.
     */
    public function testSymbolsRetainedInPlainText(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('Introduction '));
        $heading->appendChild(new Symbol('smile'));

        $this->assertSame('Introduction :smile:', $this->tracker->getPlainText($heading));
    }

    /**
     * jgm/djot#393 removes the per-character exceptions: `_` is non-alphanumeric
     * ASCII, so it is replaced with `-` like any other punctuation.
     */
    public function testUnderscoreReplacedInId(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('foo_bar baz'));

        $id = $this->tracker->getIdForHeading($heading);

        $this->assertSame('foo-bar-baz', $id);
    }

    /**
     * With an ASCII-folding id transform, when transliteration removes the entire
     * heading text (a script outside the baked map, no ext-intl), the heading must
     * fall back to a stable generated `s-N` id. (By default the non-ASCII text is
     * preserved instead, per #393, so no fallback occurs.)
     */
    public function testHeadingThatTransliteratesToNothingGetsFallbackId(): void
    {
        $transliterator = new AsciiTransliterator(useIntl: false);
        $tracker = new HeadingIdTracker(static fn (string $id): string => $transliterator->transliterate($id));

        $cjk = new Heading(2);
        $cjk->appendChild(new Text('日本語の見出し'));

        $next = new Heading(2);
        $next->appendChild(new Text('عنوان عربي'));

        $this->assertSame('s-1', $tracker->getIdForHeading($cjk));
        $this->assertSame('s-2', $tracker->getIdForHeading($next));
    }

    public function testAllPunctuationHeadingGetsFallbackId(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('!!!'));

        $this->assertSame('s-1', $this->tracker->getIdForHeading($heading));
    }

    /**
     * The `s-N` fallback dedupes against reserved IDs: an earlier explicit
     * `{#s-1}` forces the next all-punct/empty heading to take `s-2`,
     * skipping the taken slot. Parser/render parity is preserved by
     * BlockParser's post-parse rewrite (see #184), so the do-while here is
     * safe — both passes seed their tracker with `reserveExplicitIds`.
     */
    public function testFallbackIdSkipsReservedSNCollision(): void
    {
        $this->tracker->trackId('s-1');

        $heading = new Heading(2);
        $heading->appendChild(new Text('###'));

        $this->assertSame('s-2', $this->tracker->getIdForHeading($heading));
    }
}
