<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\Node\Block\Heading;
use Djot\Node\Inline\FootnoteRef;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Strong;
use Djot\Node\Inline\Symbol;
use Djot\Node\Inline\Text;
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

    public function testNormalizeId(): void
    {
        $this->assertSame('Hello-World', $this->tracker->normalizeId('Hello World'));
        $this->assertSame('No-Hashes', $this->tracker->normalizeId('#No# #Hashes#'));
        $this->assertSame('Trim-Me', $this->tracker->normalizeId('  Trim Me  '));
        $this->assertSame('Multiple-Spaces', $this->tracker->normalizeId('Multiple   Spaces'));
        $this->assertSame('this-t-key-params-fallback', $this->tracker->normalizeId("\$this->t(\$key, \$params = [], \$fallback = '')"));
        $this->assertSame('My-title', $this->tracker->normalizeId('My --- title'));
        $this->assertSame('日本語の見出し', $this->tracker->normalizeId('日本語の見出し'));
        $this->assertSame('', $this->tracker->normalizeId('###'));
        $this->assertSame('h-123-Things', $this->tracker->normalizeId('123 Things'));
        $this->assertSame('h-1-Introduction', $this->tracker->normalizeId('1. Introduction'));
    }

    /**
     * Pins the auto-ID rule settled in jgm/djot#393: each maximal run of
     * non-alphanumeric ASCII characters is replaced with `-`, leading/trailing
     * `-` are trimmed, and non-ASCII characters are preserved verbatim.
     *
     * djot-php follows this prose, including dropping the previous `_`
     * exception. The only deliberate deviations are the two CSS-validity
     * adjustments (leading-digit `h-` prefix, empty result → `s-N` fallback),
     * which the heading-level tests cover.
     */
    public function testNormalizeIdSpecAlignmentEdgeCases(): void
    {
        $this->assertSame('A-B-C', $this->tracker->normalizeId('A+B=C'));
        $this->assertSame('Emphasis-strong', $this->tracker->normalizeId('Emphasis/strong'));
        $this->assertSame('That-s-all', $this->tracker->normalizeId("That's all"));
        $this->assertSame('foo-bar', $this->tracker->normalizeId('foo...bar'));
        $this->assertSame('foo-bar-baz', $this->tracker->normalizeId('foo_bar baz'));
        $this->assertSame('Uber-uns', $this->tracker->normalizeId('Uber uns'));
        $this->assertSame('Über-uns', $this->tracker->normalizeId('Über uns'));
        // Non-ASCII punctuation/symbols are not "non-alphanumeric ASCII", so
        // they are preserved (and are valid CSS identifier code points).
        $this->assertSame('A–B', $this->tracker->normalizeId('A–B'));
        $this->assertSame('café—bar', $this->tracker->normalizeId('café—bar'));
        $this->assertSame('h-2024-recap', $this->tracker->normalizeId('2024 recap'));
        $this->assertSame('', $this->tracker->normalizeId('!!!'));
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
     * Per the jgm/djot#393 wording, `_` is a non-alphanumeric ASCII character
     * and is replaced with `-` like any other punctuation (the previous `_`
     * exception is gone).
     */
    public function testUnderscoreReplacedInId(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('foo_bar baz'));

        $id = $this->tracker->getIdForHeading($heading);

        $this->assertSame('foo-bar-baz', $id);
    }

    /**
     * A heading whose text normalizes to nothing (all ASCII punctuation)
     * falls back to a generated `s-N` identifier, matching djot.js — not the
     * literal `heading` sentinel djot-php used previously.
     */
    public function testAllPunctuationHeadingGetsFallbackId(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('!!!'));

        $id = $this->tracker->getIdForHeading($heading);

        $this->assertSame('s-1', $id);
    }

    /**
     * The generated `s-N` fallback must not collide with a real heading whose
     * text normalizes to the same value (e.g. `# s 1` → `s-1`).
     */
    public function testFallbackIdDoesNotCollideWithNormalHeading(): void
    {
        $punct = new Heading(2);
        $punct->appendChild(new Text('!!!'));

        $sOne = new Heading(2);
        $sOne->appendChild(new Text('s 1'));

        $firstId = $this->tracker->getIdForHeading($punct);
        $secondId = $this->tracker->getIdForHeading($sOne);

        $this->assertSame('s-1', $firstId);
        $this->assertNotSame($firstId, $secondId);
    }

    /**
     * The fallback must also avoid explicitly tracked IDs.
     */
    public function testFallbackIdAvoidsTrackedExplicitId(): void
    {
        $this->tracker->trackId('s-1');

        $heading = new Heading(2);
        $heading->appendChild(new Text('###'));

        $id = $this->tracker->getIdForHeading($heading);

        $this->assertSame('s-2', $id);
    }

    public function testNonAsciiPunctuationHeadingIsPreserved(): void
    {
        $heading = new Heading(2);
        $heading->appendChild(new Text('Spec — Notes'));

        $id = $this->tracker->getIdForHeading($heading);

        $this->assertSame('Spec-—-Notes', $id);
    }
}
