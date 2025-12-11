<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\AbbreviationExtension;
use Djot\Extension\ExternalLinksExtension;
use Djot\Extension\ExtensionInterface;
use Djot\Extension\HeadingPermalinksExtension;
use Djot\Extension\MentionsExtension;
use PHPUnit\Framework\TestCase;

class ExtensionTest extends TestCase
{
    public function testAddExtension(): void
    {
        $converter = new DjotConverter();
        $extension = new ExternalLinksExtension();

        $result = $converter->addExtension($extension);

        $this->assertSame($converter, $result);
        $this->assertCount(1, $converter->getExtensions());
        $this->assertSame($extension, $converter->getExtensions()[0]);
    }

    public function testMultipleExtensions(): void
    {
        $converter = new DjotConverter();

        $converter
            ->addExtension(new ExternalLinksExtension())
            ->addExtension(new MentionsExtension());

        $this->assertCount(2, $converter->getExtensions());
    }
}
