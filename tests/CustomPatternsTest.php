<?php

declare(strict_types=1);

namespace Djot\Test;

use Djot\DjotConverter;
use Djot\Node\Block\Div;
use Djot\Node\Block\Paragraph;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Text;
use PHPUnit\Framework\TestCase;

class CustomPatternsTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    // ==================== Inline Patterns ====================

    public function testInlinePatternMention(): void
    {
        $parser = $this->converter->getParser()->getInlineParser();

        // Register @mention pattern
        $parser->addInlinePattern('/@([a-zA-Z0-9_]+)/', function ($match, $groups, $p) {
            $link = new Link('https://example.com/users/' . $groups[1]);
            $link->appendChild(new Text('@' . $groups[1]));

            return $link;
        });

        $result = $this->converter->convert('Hello @john_doe, how are you?');

        $this->assertStringContainsString('href="https://example.com/users/john_doe"', $result);
        $this->assertStringContainsString('@john_doe</a>', $result);
    }

    public function testInlinePatternWikiLink(): void
    {
        $parser = $this->converter->getParser()->getInlineParser();

        // Register [[wiki-link]] pattern
        $parser->addInlinePattern('/\[\[([^\]]+)\]\]/', function ($match, $groups, $p) {
            $link = new Link('/wiki/' . rawurlencode($groups[1]));
            $link->appendChild(new Text($groups[1]));

            return $link;
        });

        $result = $this->converter->convert('See [[Home Page]] for more info.');

        $this->assertStringContainsString('href="/wiki/Home%20Page"', $result);
        $this->assertStringContainsString('Home Page</a>', $result);
    }

    public function testInlinePatternHashtag(): void
    {
        $parser = $this->converter->getParser()->getInlineParser();

        // Register #hashtag pattern
        $parser->addInlinePattern('/#([a-zA-Z][a-zA-Z0-9_]*)/', function ($match, $groups, $p) {
            $link = new Link('/tags/' . strtolower($groups[1]));
            $link->appendChild(new Text('#' . $groups[1]));
            $link->setAttribute('class', 'hashtag');

            return $link;
        });

        $result = $this->converter->convert('Check out #CoolProject today!');

        $this->assertStringContainsString('href="/tags/coolproject"', $result);
        $this->assertStringContainsString('class="hashtag"', $result);
        $this->assertStringContainsString('#CoolProject</a>', $result);
    }

    public function testInlinePatternEmoji(): void
    {
        $parser = $this->converter->getParser()->getInlineParser();

        // Register :emoji: pattern that converts to actual emoji
        $emojis = [
            'smile' => '😊',
            'heart' => '❤️',
            'thumbsup' => '👍',
        ];

        $parser->addInlinePattern('/:([a-z]+):/', function ($match, $groups, $p) use ($emojis) {
            $name = $groups[1];
            if (isset($emojis[$name])) {
                return new Text($emojis[$name]);
            }

            return null; // Let default :symbol: handling take over
        });

        $result = $this->converter->convert('I :heart: this! :smile:');

        $this->assertStringContainsString('❤️', $result);
        $this->assertStringContainsString('😊', $result);
    }

    public function testInlinePatternReturnNull(): void
    {
        $parser = $this->converter->getParser()->getInlineParser();

        // Register pattern that sometimes returns null (fallback to default)
        $parser->addInlinePattern('/@([a-zA-Z0-9_]+)/', function ($match, $groups, $p) {
            // Only handle @admin specially
            if ($groups[1] === 'admin') {
                $link = new Link('/admin');
                $link->appendChild(new Text('Administrator'));

                return $link;
            }

            return null; // Not handled, will be parsed as text
        });

        $adminResult = $this->converter->convert('Contact @admin for help.');
        $this->assertStringContainsString('Administrator</a>', $adminResult);

        // Re-create converter to reset patterns
        $this->converter = new DjotConverter();
        $parser = $this->converter->getParser()->getInlineParser();
        $parser->addInlinePattern('/@([a-zA-Z0-9_]+)/', fn ($m, $g, $p) => null);

        $userResult = $this->converter->convert('Hello @user!');
        $this->assertStringContainsString('@user', $userResult);
        $this->assertStringNotContainsString('href=', $userResult);
    }

    public function testInlinePatternRemove(): void
    {
        $parser = $this->converter->getParser()->getInlineParser();

        $pattern = '/@([a-zA-Z0-9_]+)/';
        $parser->addInlinePattern($pattern, fn ($m, $g, $p) => new Text('REPLACED'));

        $this->assertCount(1, $parser->getInlinePatterns());

        $parser->removeInlinePattern($pattern);

        $this->assertCount(0, $parser->getInlinePatterns());
    }

    public function testInlinePatternPriority(): void
    {
        $parser = $this->converter->getParser()->getInlineParser();

        // Custom patterns are checked before built-in syntax
        // So we can override how certain things are parsed
        $parser->addInlinePattern('/\*\*([^*]+)\*\*/', function ($match, $groups, $p) {
            $text = new Text('【' . $groups[1] . '】');

            return $text;
        });

        $result = $this->converter->convert('This is **important** text.');

        $this->assertStringContainsString('【important】', $result);
        $this->assertStringNotContainsString('<strong>', $result);
    }

    // ==================== Block Patterns ====================

    public function testBlockPatternAdmonition(): void
    {
        $parser = $this->converter->getParser();

        // Register !!! admonition pattern
        $parser->addBlockPattern('/^!!!\s*(note|warning|danger)\s*$/', function ($lines, $start, $parent, $p) {
            preg_match('/^!!!\s*(note|warning|danger)\s*$/', $lines[$start], $m);
            $type = $m[1];

            // Collect indented content
            $content = [];
            $i = $start + 1;
            $count = count($lines);
            while ($i < $count && preg_match('/^\s+(.*)$/', $lines[$i], $contentMatch)) {
                $content[] = $contentMatch[1];
                $i++;
            }

            $div = new Div();
            $div->setAttribute('class', 'admonition ' . $type);
            $p->parseBlockContent($div, $content);
            $parent->appendChild($div);

            return $i - $start;
        });

        $djot = "!!! warning\n    Be careful with this feature.\n    It may cause issues.\n\nRegular text.";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="admonition warning"', $result);
        $this->assertStringContainsString('Be careful with this feature.', $result);
        $this->assertStringContainsString('It may cause issues.', $result);
        $this->assertStringContainsString('<p>Regular text.</p>', $result);
    }

    public function testBlockPatternSpoiler(): void
    {
        $parser = $this->converter->getParser();

        // Register :::spoiler ... ::: pattern
        $parser->addBlockPattern('/^:::spoiler\s*$/', function ($lines, $start, $parent, $p) {
            $content = [];
            $i = $start + 1;
            $count = count($lines);

            while ($i < $count && !preg_match('/^:::\s*$/', $lines[$i])) {
                $content[] = $lines[$i];
                $i++;
            }

            $div = new Div();
            $div->setAttribute('class', 'spoiler');
            $p->parseBlockContent($div, $content);
            $parent->appendChild($div);

            // +1 for closing :::
            return ($i < $count) ? $i - $start + 1 : $i - $start;
        });

        $djot = ":::spoiler\nThis is hidden content.\n\nWith multiple paragraphs.\n:::\n\nVisible text.";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="spoiler"', $result);
        $this->assertStringContainsString('This is hidden content.', $result);
        $this->assertStringContainsString('With multiple paragraphs.', $result);
        $this->assertStringContainsString('<p>Visible text.</p>', $result);
    }

    public function testBlockPatternTabs(): void
    {
        $parser = $this->converter->getParser();

        // Register === Tab Title pattern
        $parser->addBlockPattern('/^===\s+(.+)$/', function ($lines, $start, $parent, $p) {
            preg_match('/^===\s+(.+)$/', $lines[$start], $m);
            $title = trim($m[1]);

            // Collect content until next === or end
            $content = [];
            $i = $start + 1;
            $count = count($lines);

            while ($i < $count && !preg_match('/^===\s+/', $lines[$i])) {
                $content[] = $lines[$i];
                $i++;
            }

            $div = new Div();
            $div->setAttribute('class', 'tab');
            $div->setAttribute('data-title', $title);
            $p->parseBlockContent($div, $content);
            $parent->appendChild($div);

            return $i - $start;
        });

        $djot = "=== First Tab\nContent of first tab.\n\n=== Second Tab\nContent of second tab.";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="tab"', $result);
        $this->assertStringContainsString('data-title="First Tab"', $result);
        $this->assertStringContainsString('data-title="Second Tab"', $result);
        $this->assertStringContainsString('Content of first tab.', $result);
        $this->assertStringContainsString('Content of second tab.', $result);
    }

    public function testBlockPatternRemove(): void
    {
        $parser = $this->converter->getParser();

        $pattern = '/^!!!\s*$/';
        $parser->addBlockPattern($pattern, fn ($l, $s, $p, $bp) => 1);

        $this->assertCount(1, $parser->getBlockPatterns());

        $parser->removeBlockPattern($pattern);

        $this->assertCount(0, $parser->getBlockPatterns());
    }

    public function testBlockPatternReturnNull(): void
    {
        $parser = $this->converter->getParser();

        // Pattern that matches but returns null (fallback to default)
        $parser->addBlockPattern('/^#/', function ($lines, $start, $parent, $p) {
            // Only handle special case
            if (str_starts_with($lines[$start], '# SPECIAL:')) {
                $div = new Div();
                $div->setAttribute('class', 'special');

                $para = new Paragraph();
                $para->appendChild(new Text(substr($lines[$start], 10)));
                $div->appendChild($para);

                $parent->appendChild($div);

                return 1;
            }

            return null; // Let default heading parsing handle it
        });

        // Special heading handled by custom pattern
        $specialResult = $this->converter->convert('# SPECIAL: Custom content');
        $this->assertStringContainsString('class="special"', $specialResult);

        // Regular heading falls through to default
        $normalResult = $this->converter->convert('# Regular Heading');
        $this->assertStringContainsString('<h1>Regular Heading</h1>', $normalResult);
    }

    // ==================== Combined Patterns ====================

    public function testCombinedPatterns(): void
    {
        $parser = $this->converter->getParser();
        $inlineParser = $parser->getInlineParser();

        // Add both inline and block patterns
        $inlineParser->addInlinePattern('/@(\w+)/', function ($m, $g, $p) {
            $link = new Link('/u/' . $g[1]);
            $link->appendChild(new Text('@' . $g[1]));

            return $link;
        });

        $parser->addBlockPattern('/^NOTE:\s*$/', function ($lines, $start, $parent, $p) {
            $content = [];
            $i = $start + 1;
            $count = count($lines);
            while ($i < $count && $lines[$i] !== '' && !preg_match('/^[A-Z]+:\s*$/', $lines[$i])) {
                $content[] = $lines[$i];
                $i++;
            }

            $div = new Div();
            $div->setAttribute('class', 'note');
            $p->parseBlockContent($div, $content);
            $parent->appendChild($div);

            return $i - $start;
        });

        $djot = "NOTE:\nRemember to contact @support for help.\n\nRegular paragraph with @mention.";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="note"', $result);
        $this->assertStringContainsString('href="/u/support"', $result);
        $this->assertStringContainsString('href="/u/mention"', $result);
    }
}
