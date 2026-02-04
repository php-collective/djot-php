<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\Node\Block\Heading;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Strong;
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
}
