<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\MentionsExtension;
use Djot\Extension\SocialLinkResolverInput;
use Djot\Renderer\MarkdownRenderer;
use Djot\Renderer\PlainTextRenderer;
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

                return $input->username === 'alice' ? '/people/42' : null;
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
}
