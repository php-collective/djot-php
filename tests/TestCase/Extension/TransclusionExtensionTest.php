<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\TransclusionExtension;
use Djot\Node\ContentNodeInterface;
use Djot\Node\Inline\Span;
use Djot\Node\Inline\Text;
use Djot\Node\Inline\TransclusionArgument;
use Djot\Node\Node;
use PHPUnit\Framework\TestCase;

class TransclusionExtensionTest extends TestCase
{
    public function testPositionalArgs(): void
    {
        $seenArgs = [];

        $converter = new DjotConverter();
        $converter->addExtension(new TransclusionExtension(
            /** @param array<int, \Djot\Node\Inline\TransclusionArgument> $args */
            resolver: function (string $name, array $args) use (&$seenArgs): Node {
                $seenArgs = $args;

                $span = new Span();
                $span->appendChild(new Text($this->plainText($args[0]) . ', '));
                $span->appendChild(new Text($this->plainText($args[1])));

                return $span;
            },
        ));

        $html = $converter->convert('{{infobox|Berlin|Germany}}');

        $this->assertStringContainsString('Berlin', $html);
        $this->assertStringContainsString('Germany', $html);
        $this->assertCount(2, $seenArgs);
        $this->assertFalse($seenArgs[0]->isNamed());
        $this->assertFalse($seenArgs[1]->isNamed());
        $this->assertSame(0, $seenArgs[0]->getIndex());
        $this->assertSame(1, $seenArgs[1]->getIndex());
    }

    public function testNamedArgs(): void
    {
        $seenArgs = [];

        $converter = new DjotConverter();
        $converter->addExtension(new TransclusionExtension(
            /** @param array<int, \Djot\Node\Inline\TransclusionArgument> $args */
            resolver: function (string $name, array $args) use (&$seenArgs): Node {
                $seenArgs = $args;

                $span = new Span();
                $span->appendChild(new Text($this->plainText($args[0]) . ' '));
                $span->appendChild(new Text($this->plainText($args[1])));

                return $span;
            },
        ));

        $html = $converter->convert('{{cite|author=Doe|year=1990}}');

        $this->assertSame('author', $seenArgs[0]->getKey());
        $this->assertSame('year', $seenArgs[1]->getKey());
        $this->assertStringContainsString('Doe', $html);
        $this->assertStringContainsString('1990', $html);
    }

    public function testMarkupBearingArgumentIsParsed(): void
    {
        $titleArg = null;

        $converter = new DjotConverter();
        $converter->addExtension(new TransclusionExtension(
            /** @param array<int, \Djot\Node\Inline\TransclusionArgument> $args */
            resolver: static function (string $name, array $args) use (&$titleArg): Node {
                $titleArg = $args[0];

                $span = new Span();
                foreach ($args[0]->getChildren() as $child) {
                    $span->appendChild($child);
                }

                return $span;
            },
        ));

        $html = $converter->convert('{{cite|title=_Important_ book}}');

        $this->assertStringContainsString('<em>Important</em>', $html);
        $this->assertInstanceOf(TransclusionArgument::class, $titleArg);
        $this->assertTrue($this->hasChildType($titleArg, 'emphasis'));
    }

    public function testUnresolvedFallbackWithoutResolver(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TransclusionExtension());

        $html = $converter->convert('See {{Tigers}} here');

        $this->assertStringContainsString('class="transclusion-unresolved"', $html);
        $this->assertStringContainsString('{{Tigers}}', $html);
    }

    public function testResolverReturnsNullUsesFallback(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new TransclusionExtension(
            /** @param array<int, \Djot\Node\Inline\TransclusionArgument> $args */
            resolver: static fn (string $name, array $args): ?Node => null,
        ));

        $html = $converter->convert('See {{Tigers}} here');

        $this->assertStringContainsString('class="transclusion-unresolved"', $html);
        $this->assertStringContainsString('{{Tigers}}', $html);
    }

    protected function hasChildType(TransclusionArgument $arg, string $type): bool
    {
        foreach ($arg->getChildren() as $child) {
            if ($child->getType() === $type) {
                return true;
            }
        }

        return false;
    }

    protected function plainText(TransclusionArgument $arg): string
    {
        $text = '';
        foreach ($arg->getChildren() as $child) {
            if ($child instanceof ContentNodeInterface) {
                $text .= $child->getContent();
            }
        }

        return $text;
    }
}
