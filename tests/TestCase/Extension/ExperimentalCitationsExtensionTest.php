<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\CitationGroup;
use Djot\Extension\ExperimentalCitationsExtension;
use Djot\Node\Document;
use PHPUnit\Framework\TestCase;

class ExperimentalCitationsExtensionTest extends TestCase
{
    public function testParentheticalCitation(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new ExperimentalCitationsExtension());

        $html = $converter->convert('Parenthetical: [@kuhn1962].');

        $this->assertStringContainsString('class="citation experimental-citation citation-single"', $html);
        $this->assertStringContainsString('data-citation-source="[@kuhn1962]"', $html);
        $this->assertStringContainsString('data-citation-keys="kuhn1962"', $html);
        $this->assertStringContainsString('&quot;mode&quot;:&quot;parenthetical&quot;', $html);
    }

    public function testIntegralCitation(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new ExperimentalCitationsExtension());

        $html = $converter->convert('[+@smith2010, p. 10] argues the point.');

        $this->assertStringContainsString('data-citation-source="[+@smith2010, p. 10]"', $html);
        $this->assertStringContainsString('&quot;mode&quot;:&quot;integral&quot;', $html);
        $this->assertStringContainsString('&quot;suffix&quot;:&quot;p. 10&quot;', $html);
    }

    public function testSuppressAuthorCitation(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new ExperimentalCitationsExtension());

        $html = $converter->convert('[-@watson1953, p. 737].');

        $this->assertStringContainsString('&quot;mode&quot;:&quot;suppress-author&quot;', $html);
    }

    public function testMultiCitation(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new ExperimentalCitationsExtension());

        $html = $converter->convert('[@kuhn1962; @watson1953, ch. 2]');

        $this->assertStringContainsString('citation-multiple', $html);
        $this->assertStringContainsString('data-citation-keys="kuhn1962;watson1953"', $html);
        $this->assertStringContainsString('&quot;key&quot;:&quot;kuhn1962&quot;', $html);
        $this->assertStringContainsString('&quot;key&quot;:&quot;watson1953&quot;', $html);
        $this->assertStringContainsString('&quot;suffix&quot;:&quot;ch. 2&quot;', $html);
    }

    public function testCitationResolverCanReplaceRenderedText(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new ExperimentalCitationsExtension(
            resolver: static function (array $groups, Document $document): array {
                $resolved = [];
                foreach ($groups as $group) {
                    $keys = array_map(
                        static fn ($reference): string => strtoupper($reference->key),
                        $group->references,
                    );
                    $resolved[$group->id] = '(' . implode('; ', $keys) . ')';
                }

                return $resolved;
            },
        ));

        $html = $converter->convert('See [@kuhn1962; @watson1953].');

        $this->assertStringContainsString('(KUHN1962; WATSON1953)', $html);
        $this->assertStringContainsString('data-citation-rendered="resolved"', $html);
    }

    public function testResolverReceivesDistinctIdsForRepeatedCitationGroups(): void
    {
        $seenIds = [];

        $converter = new DjotConverter();
        $converter->addExtension(new ExperimentalCitationsExtension(
            resolver: static function (array $groups, Document $document) use (&$seenIds): array {
                $resolved = [];
                foreach ($groups as $group) {
                    $seenIds[] = $group->id;
                    $resolved[$group->id] = $group->source;
                }

                return $resolved;
            },
        ));

        $converter->convert('[@kuhn1962] and again [@kuhn1962].');

        $this->assertCount(2, $seenIds);
        $this->assertNotSame($seenIds[0], $seenIds[1]);
    }

    public function testCitationLikeBracketFollowedByLinkDestinationStillParsesAsLink(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new ExperimentalCitationsExtension());

        $html = $converter->convert('Read [+@kuhn1962](https://example.com).');

        $this->assertStringContainsString('<a href="https://example.com">+@kuhn1962</a>', $html);
        $this->assertStringNotContainsString('data-citation-source', $html);
    }

    public function testNonCitationBracketStaysPlainText(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new ExperimentalCitationsExtension());

        $html = $converter->convert('[not a citation]');

        $this->assertStringContainsString('<p>[not a citation]</p>', $html);
    }

    public function testResolverCanInspectStructuredGroups(): void
    {
        $captured = [];

        $converter = new DjotConverter();
        $converter->addExtension(new ExperimentalCitationsExtension(
            resolver: static function (array $groups, Document $document) use (&$captured): array {
                $captured = $groups;

                return array_reduce(
                    $groups,
                    static function (array $carry, CitationGroup $group): array {
                        $carry[$group->id] = $group->source;

                        return $carry;
                    },
                    [],
                );
            },
        ));

        $converter->convert('[@kuhn1962; -@watson1953, ch. 2]');

        $this->assertCount(1, $captured);
        $this->assertSame('kuhn1962', $captured[0]->references[0]->key);
        $this->assertSame('suppress-author', $captured[0]->references[1]->mode);
        $this->assertSame('ch. 2', $captured[0]->references[1]->suffix);
    }

    public function testResolverReceivesDocumentContext(): void
    {
        $seenDocument = null;

        $converter = new DjotConverter();
        $converter->addExtension(new ExperimentalCitationsExtension(
            resolver: static function (array $groups, Document $document) use (&$seenDocument): array {
                $seenDocument = $document;

                return [$groups[0]->id => $groups[0]->source];
            },
        ));

        $converter->convert('# Heading' . "\n\n" . '[@kuhn1962]');

        $this->assertInstanceOf(Document::class, $seenDocument);
        $this->assertCount(2, $seenDocument->getChildren());
    }

    public function testResolverMayReturnEmptyMap(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new ExperimentalCitationsExtension(
            resolver: static fn (array $groups, Document $document): array => [],
        ));

        $html = $converter->convert('[@kuhn1962]');

        $this->assertStringContainsString('[@kuhn1962]', $html);
        $this->assertStringNotContainsString('data-citation-rendered="resolved"', $html);
    }
}
