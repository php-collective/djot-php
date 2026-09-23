<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\Converter\HtmlToDjot;
use Djot\DjotConverter;
use Djot\Extension\MentionsExtension;
use Djot\Extension\SocialLinkResolverInput;
use Djot\Renderer\MarkdownRenderer;
use Djot\Renderer\PlainTextRenderer;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MentionsExtensionTest extends TestCase
{
    public function testResolverReceivesContextAndCanRefuseALink(): void
    {
        $context = (object)['tenant' => 42];
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(
            resolver: static function (SocialLinkResolverInput $input) use ($context): ?string {
                self::assertSame($context, $input->context);
                self::assertSame('mention', $input->kind);

                return $input->name === 'alice' ? '/people/42' : null;
            },
            resolverContext: $context,
        ));

        $html = $converter->convert('@alice @missing');
        self::assertStringContainsString('<a href="/people/42"', $html);
        self::assertStringContainsString('<span data-username="missing" class="mention">@missing</span>', $html);
    }

    public function testResolverRejectsADangerousScheme(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(
            resolver: static fn (): string => 'javascript:alert(1)',
        ));

        self::assertStringNotContainsString('<a', $converter->convert('@alice'));
    }

    public function testResolvedDestinationReachesMarkdown(): void
    {
        $converter = new DjotConverter(renderer: new MarkdownRenderer());
        $converter->addExtension(new MentionsExtension(
            resolver: static fn (): string => '/people/42',
        ));

        self::assertSame("[@alice](/people/42)\n", $converter->convert('@alice'));
    }

    public function testAuthoredDataAttributeCannotInvokeTheResolver(): void
    {
        $calls = 0;
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(
            resolver: static function () use (&$calls): string {
                $calls++;

                return '/people/42';
            },
        ));

        $html = $converter->convert('[ordinary](/kept){data-username=admin}');
        self::assertSame(0, $calls);
        self::assertStringContainsString('href="/kept"', $html);
    }

    public function testRefusedMentionKeepsItsPlainText(): void
    {
        $converter = new DjotConverter(renderer: new PlainTextRenderer());
        $converter->addExtension(new MentionsExtension(resolver: static fn (): null => null));

        self::assertSame("hello @alice world\n", $converter->convert('hello @alice world'));
    }

    public function testResolverRunsAgainForARepeatedRender(): void
    {
        $calls = 0;
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(
            resolver: static function () use (&$calls): string {
                $calls++;

                return '/people/' . $calls;
            },
        ));
        $document = $converter->parse('@alice');

        self::assertStringContainsString('href="/people/1"', $converter->render($document));
        self::assertStringContainsString('href="/people/2"', $converter->render($document));
    }

    public function testUserMention(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension());

        $html = $converter->convert('Hello @johndoe!');

        $this->assertStringContainsString('href="/users/view/johndoe"', $html);
        $this->assertStringContainsString('@johndoe', $html);
        $this->assertStringContainsString('class="mention"', $html);
    }

    public function testCustomUrlTemplate(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(
            urlTemplate: '/profile/{username}',
        ));

        $html = $converter->convert('Thanks @alice!');

        $this->assertStringContainsString('href="/profile/alice"', $html);
    }

    public function testFullUrlTemplate(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(
            urlTemplate: 'https://example.com/users/{username}',
        ));

        $html = $converter->convert('Contact @support for help.');

        $this->assertStringContainsString('href="https://example.com/users/support"', $html);
    }

    public function testCustomCssClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(
            cssClass: 'user-link highlighted',
        ));

        $html = $converter->convert('Hello @johndoe!');

        $this->assertStringContainsString('class="user-link highlighted"', $html);
    }

    public function testMultipleMentions(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension());

        $html = $converter->convert('@alice and @bob discussed the issue.');

        $this->assertStringContainsString('href="/users/view/alice"', $html);
        $this->assertStringContainsString('href="/users/view/bob"', $html);
    }

    public function testMentionWithHyphenAndUnderscore(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension());

        $html = $converter->convert('Thanks @john-doe and @jane_doe');

        $this->assertStringContainsString('href="/users/view/john-doe"', $html);
        $this->assertStringContainsString('href="/users/view/jane_doe"', $html);
    }

    public function testDataAttribute(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension());

        $html = $converter->convert('@johndoe');

        $this->assertStringContainsString('data-username="johndoe"', $html);
    }

    public function testMentionAtStartOfText(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension());

        $html = $converter->convert('@admin please help');

        $this->assertStringContainsString('href="/users/view/admin"', $html);
    }

    public function testMentionAtEndOfText(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension());

        $html = $converter->convert('Thanks @helper');

        $this->assertStringContainsString('href="/users/view/helper"', $html);
    }

    public function testEscapedMentionNotLinked(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension());

        $html = $converter->convert('Contact \\@support for help.');

        // Escaped @ should be literal, not a link
        $this->assertStringContainsString('@support', $html);
        $this->assertStringNotContainsString('href="/users/view/support"', $html);
    }

    public function testRepeatedRenderDoesNotDuplicateMentionClasses(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(cssClass: 'mention user-link'));

        $document = $converter->parse('Hello @johndoe!');

        $first = $converter->render($document);
        $second = $converter->render($document);

        $this->assertStringContainsString('class="mention user-link"', $first);
        $this->assertStringContainsString('class="mention user-link"', $second);
        $this->assertStringNotContainsString('mention user-link mention user-link', $second);
    }

    public function testTagsAreDisabledByDefault(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension());

        self::assertSame("<p>#php</p>\n", $converter->convert('#php'));
    }

    public function testTagUrlTemplateCreatesALink(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(
            tagUrlTemplate: '/tags/{tag}',
            tagCssClass: 'topic',
        ));

        self::assertSame(
            "<p><a href=\"/tags/php\" data-tag=\"php\" class=\"topic\">#php</a></p>\n",
            $converter->convert('#php'),
        );
    }

    public function testEmptyTagTemplateCreatesASpan(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(tagUrlTemplate: ''));

        self::assertSame(
            "<p><span data-tag=\"php\" class=\"tag\">#php</span></p>\n",
            $converter->convert('#php'),
        );
    }

    public function testTagResolverReceivesInputAndContext(): void
    {
        $context = (object)['tenant' => 42];
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(
            resolverContext: $context,
            tagResolver: static function (SocialLinkResolverInput $input) use ($context): string {
                self::assertSame('tag', $input->kind);
                self::assertSame('php', $input->name);
                self::assertSame('php', $input->attributes['data-tag']);
                self::assertSame($context, $input->context);

                return '/topics/php';
            },
        ));

        self::assertStringContainsString('href="/topics/php"', $converter->convert('#php'));
    }

    public function testTagResolverCanRefuseALink(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(tagResolver: static fn (): null => null));

        self::assertSame(
            "<p><span data-tag=\"php\" class=\"tag\">#php</span></p>\n",
            $converter->convert('#php'),
        );
    }

    public function testTagResolverExceptionCreatesASpan(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(
            tagResolver: static fn (): never => throw new Exception('unavailable'),
        ));

        self::assertSame(
            "<p><span data-tag=\"php\" class=\"tag\">#php</span></p>\n",
            $converter->convert('#php'),
        );
    }

    public function testTagResolverRejectsADangerousScheme(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(
            tagResolver: static fn (): string => 'javascript:alert(1)',
        ));

        self::assertSame(
            "<p><span data-tag=\"php\" class=\"tag\">#php</span></p>\n",
            $converter->convert('#php'),
        );
    }

    public function testTagPatternKeepsTrailingDotOutOfTheName(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(tagUrlTemplate: '/tags/{tag}'));

        self::assertSame(
            "<p><a href=\"/tags/php\" data-tag=\"php\" class=\"tag\">#php</a>.</p>\n",
            $converter->convert('#php.'),
        );
    }

    public function testTagPatternIncludesInteriorDots(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(tagUrlTemplate: '/tags/{tag}'));

        self::assertSame(
            "<p><a href=\"/tags/php.framework\" data-tag=\"php.framework\" class=\"tag\">#php.framework</a></p>\n",
            $converter->convert('#php.framework'),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function protectedInlineConstructProvider(): array
    {
        return [
            'attribute blocks are parsed before custom inline patterns' => ['text{#id .cls}'],
            'link destinations are parsed before custom inline patterns' => ['[x](#anchor)'],
            'autolinks are parsed before custom inline patterns' => ['<https://a.b/#frag>'],
            'code spans are parsed before custom inline patterns' => ['`#code`'],
            'raw inline is parsed before custom inline patterns' => ['`#raw`{=html}'],
            'HTML entities cannot satisfy the tag boundary' => ['&#123;'],
            'heading markers are parsed at block level' => ['# Heading'],
            'word-internal hashes cannot satisfy the tag boundary' => ['C# and abc#def'],
        ];
    }

    #[DataProvider('protectedInlineConstructProvider')]
    public function testTagPatternDoesNotMatchProtectedInlineConstructs(string $source): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(tagUrlTemplate: '/tags/{tag}'));

        self::assertStringNotContainsString('data-tag=', $converter->convert($source));
    }

    public function testRefusedTagKeepsItsMarkdownAndPlainText(): void
    {
        $markdownConverter = new DjotConverter(renderer: new MarkdownRenderer());
        $markdownConverter->addExtension(new MentionsExtension(tagResolver: static fn (): null => null));
        $plainTextConverter = new DjotConverter(renderer: new PlainTextRenderer());
        $plainTextConverter->addExtension(new MentionsExtension(tagResolver: static fn (): null => null));

        self::assertSame("#php\n", $markdownConverter->convert('#php'));
        self::assertSame("#php\n", $plainTextConverter->convert('#php'));
    }

    public function testHtmlToDjotImportsATagSpan(): void
    {
        $html = '<p><span data-tag="php" class="tag">#php</span></p>';

        self::assertSame("#php\n", (new HtmlToDjot())->convert($html));
    }

    public function testHtmlToDjotImportsATagLink(): void
    {
        $html = '<p><a href="/tags/php" data-tag="php">#php</a></p>';

        self::assertSame("#php\n", (new HtmlToDjot())->convert($html));
    }

    public function testMentionAndTagInsideALinkLabelStayText(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MentionsExtension(tagUrlTemplate: '/tags/{tag}'));

        self::assertSame(
            "<p><a href=\"/u\">see @bob and #php</a></p>\n",
            $converter->convert('[see @bob and #php](/u)'),
        );
    }
}
