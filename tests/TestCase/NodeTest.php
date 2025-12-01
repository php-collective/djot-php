<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\Node\Block\Paragraph;
use Djot\Node\Inline\Text;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Node manipulation methods
 */
class NodeTest extends TestCase
{
    public function testRemoveChild(): void
    {
        $paragraph = new Paragraph();
        $text1 = new Text('First');
        $text2 = new Text('Second');
        $paragraph->appendChild($text1);
        $paragraph->appendChild($text2);

        $this->assertCount(2, $paragraph->getChildren());

        $result = $paragraph->removeChild($text1);

        $this->assertTrue($result);
        $this->assertCount(1, $paragraph->getChildren());
        $this->assertNull($text1->getParent());
        $this->assertSame($text2, $paragraph->getChildren()[0]);
    }

    public function testRemoveChildNotFound(): void
    {
        $paragraph = new Paragraph();
        $text1 = new Text('First');
        $text2 = new Text('Not a child');
        $paragraph->appendChild($text1);

        $result = $paragraph->removeChild($text2);

        $this->assertFalse($result);
        $this->assertCount(1, $paragraph->getChildren());
    }

    public function testRemoveChildAt(): void
    {
        $paragraph = new Paragraph();
        $text1 = new Text('First');
        $text2 = new Text('Second');
        $text3 = new Text('Third');
        $paragraph->appendChild($text1);
        $paragraph->appendChild($text2);
        $paragraph->appendChild($text3);

        $removed = $paragraph->removeChildAt(1);

        $this->assertSame($text2, $removed);
        $this->assertNull($text2->getParent());
        $this->assertCount(2, $paragraph->getChildren());
        $this->assertSame($text1, $paragraph->getChildren()[0]);
        $this->assertSame($text3, $paragraph->getChildren()[1]);
    }

    public function testRemoveChildAtInvalidIndex(): void
    {
        $paragraph = new Paragraph();
        $text1 = new Text('First');
        $paragraph->appendChild($text1);

        $removed = $paragraph->removeChildAt(5);

        $this->assertNull($removed);
        $this->assertCount(1, $paragraph->getChildren());
    }

    public function testReplaceChildNode(): void
    {
        $paragraph = new Paragraph();
        $text1 = new Text('First');
        $text2 = new Text('Second');
        $replacement = new Text('Replacement');
        $paragraph->appendChild($text1);
        $paragraph->appendChild($text2);

        $result = $paragraph->replaceChildNode($text1, $replacement);

        $this->assertTrue($result);
        $this->assertSame($paragraph, $replacement->getParent());
        $this->assertNull($text1->getParent());
        $this->assertSame($replacement, $paragraph->getChildren()[0]);
        $this->assertSame($text2, $paragraph->getChildren()[1]);
    }

    public function testReplaceChildNodeNotFound(): void
    {
        $paragraph = new Paragraph();
        $text1 = new Text('First');
        $notChild = new Text('Not a child');
        $replacement = new Text('Replacement');
        $paragraph->appendChild($text1);

        $result = $paragraph->replaceChildNode($notChild, $replacement);

        $this->assertFalse($result);
        $this->assertCount(1, $paragraph->getChildren());
        $this->assertSame($text1, $paragraph->getChildren()[0]);
    }

    public function testReplaceChildWithMany(): void
    {
        $paragraph = new Paragraph();
        $text1 = new Text('First');
        $text2 = new Text('Second');
        $paragraph->appendChild($text1);
        $paragraph->appendChild($text2);

        $replacements = [
            new Text('A'),
            new Text('B'),
            new Text('C'),
        ];

        $result = $paragraph->replaceChildWithMany($text1, $replacements);

        $this->assertTrue($result);
        $this->assertCount(4, $paragraph->getChildren());
        $this->assertNull($text1->getParent());

        $children = $paragraph->getChildren();
        $this->assertEquals('A', $children[0]->getContent());
        $this->assertEquals('B', $children[1]->getContent());
        $this->assertEquals('C', $children[2]->getContent());
        $this->assertSame($text2, $children[3]);

        // Check parents are set
        foreach ($replacements as $r) {
            $this->assertSame($paragraph, $r->getParent());
        }
    }

    public function testReplaceChildWithManyNotFound(): void
    {
        $paragraph = new Paragraph();
        $text1 = new Text('First');
        $notChild = new Text('Not a child');
        $paragraph->appendChild($text1);

        $result = $paragraph->replaceChildWithMany($notChild, [new Text('A')]);

        $this->assertFalse($result);
        $this->assertCount(1, $paragraph->getChildren());
    }
}
