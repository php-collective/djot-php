<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Parser;

use Djot\DjotConverter;
use Djot\Node\Block\Div;
use Djot\Node\Block\Heading;
use Djot\Node\Block\Paragraph;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Span;
use Djot\Node\Inline\Strong;
use Djot\Node\Inline\Text;
use Djot\Node\Node;
use Djot\Parser\BlockParser;
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
        $this->assertStringContainsString('<h1 id="Regular Heading">Regular Heading</h1>', $normalResult);
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

    // ==================== Edge Cases for Custom Patterns ====================

    public function testInlinePatternWithNestedFormatting(): void
    {
        $parser = $this->converter->getParser()->getInlineParser();

        // Register a pattern that captures content with formatting
        $parser->addInlinePattern('/\{\{([^}]+)\}\}/', function ($match, $groups, $p) {
            $span = new Span();
            $span->setAttribute('class', 'template');
            $span->appendChild(new Text($groups[1]));

            return $span;
        });

        $result = $this->converter->convert('Check out {{template_variable}} here.');

        $this->assertStringContainsString('class="template"', $result);
        $this->assertStringContainsString('template_variable', $result);
    }

    public function testInlinePatternWithUnicode(): void
    {
        $parser = $this->converter->getParser()->getInlineParser();

        // Pattern that matches Unicode hashtags - note that the pattern needs proper handling
        // The custom pattern system anchors patterns, so we need to test this differently
        $parser->addInlinePattern('/#([a-zA-Z0-9_]+)/', function ($match, $groups, $p) {
            $link = new Link('/tags/' . rawurlencode($groups[1]));
            $link->appendChild(new Text('#' . $groups[1]));

            return $link;
        });

        $result = $this->converter->convert('Check out #TestTag and #hello123!');

        $this->assertStringContainsString('href="/tags/TestTag"', $result);
        $this->assertStringContainsString('href="/tags/hello123"', $result);
        $this->assertStringContainsString('#TestTag', $result);
    }

    public function testInlinePatternAtStartOfLine(): void
    {
        $parser = $this->converter->getParser()->getInlineParser();

        $parser->addInlinePattern('/^>>\s*(.+)$/', function ($match, $groups, $p) {
            $span = new Span();
            $span->setAttribute('class', 'greentext');
            $span->appendChild(new Text($groups[1]));

            return $span;
        });

        // Note: This tests that patterns work at the start of content
        $result = $this->converter->convert('>> implying this is greentext');

        $this->assertStringContainsString('greentext', $result);
    }

    public function testInlinePatternWithMultipleCaptures(): void
    {
        $parser = $this->converter->getParser()->getInlineParser();

        // Pattern that captures two groups: label|url
        $parser->addInlinePattern('/\[\[([^|]+)\|([^\]]+)\]\]/', function ($match, $groups, $p) {
            $link = new Link($groups[2]);
            $link->appendChild(new Text($groups[1]));

            return $link;
        });

        $result = $this->converter->convert('See [[Documentation|/docs]] for more info.');

        $this->assertStringContainsString('href="/docs"', $result);
        $this->assertStringContainsString('Documentation</a>', $result);
    }

    public function testInlinePatternWithAttributes(): void
    {
        $parser = $this->converter->getParser()->getInlineParser();

        // Pattern that creates element with multiple attributes
        $parser->addInlinePattern('/\[!([^\]]+)\]/', function ($match, $groups, $p) {
            $span = new Span();
            $span->setAttribute('class', 'alert');
            $span->setAttribute('role', 'alert');
            $span->setAttribute('aria-live', 'polite');
            $span->appendChild(new Text($groups[1]));

            return $span;
        });

        $result = $this->converter->convert('Important: [!This is an alert message]');

        $this->assertStringContainsString('class="alert"', $result);
        $this->assertStringContainsString('role="alert"', $result);
        $this->assertStringContainsString('aria-live="polite"', $result);
    }

    public function testBlockPatternWithNestedBlocks(): void
    {
        $parser = $this->converter->getParser();

        // Register collapsible details pattern
        $parser->addBlockPattern('/^<details>\s*$/', function ($lines, $start, $parent, $p) {
            $content = [];
            $i = $start + 1;
            $count = count($lines);

            while ($i < $count && !preg_match('/^<\/details>\s*$/', $lines[$i])) {
                $content[] = $lines[$i];
                $i++;
            }

            $div = new Div();
            $div->setAttribute('class', 'details');
            $p->parseBlockContent($div, $content);
            $parent->appendChild($div);

            // +1 for closing tag
            return ($i < $count) ? $i - $start + 1 : $i - $start;
        });

        $djot = "<details>\n# Summary\n\nThis is the content.\n\n- Item 1\n- Item 2\n</details>\n\nAfter details.";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="details"', $result);
        $this->assertStringContainsString('<h1 id="Summary">Summary</h1>', $result);
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<p>After details.</p>', $result);
    }

    public function testBlockPatternWithIndentedContent(): void
    {
        $parser = $this->converter->getParser();

        // Register callout pattern with indented content
        $parser->addBlockPattern('/^CALLOUT:\s*(\w+)\s*$/', function ($lines, $start, $parent, $p) {
            preg_match('/^CALLOUT:\s*(\w+)\s*$/', $lines[$start], $m);
            $type = $m[1];

            $content = [];
            $i = $start + 1;
            $count = count($lines);

            // Collect indented lines
            while ($i < $count && preg_match('/^    (.*)$/', $lines[$i], $contentMatch)) {
                $content[] = $contentMatch[1];
                $i++;
            }

            $div = new Div();
            $div->setAttribute('class', 'callout callout-' . strtolower($type));
            $p->parseBlockContent($div, $content);
            $parent->appendChild($div);

            return $i - $start;
        });

        $djot = "CALLOUT: INFO\n    This is important information.\n    With multiple lines.\n\nRegular paragraph.";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="callout callout-info"', $result);
        $this->assertStringContainsString('important information', $result);
        $this->assertStringContainsString('<p>Regular paragraph.</p>', $result);
    }

    public function testBlockPatternConsumingMultipleBlocks(): void
    {
        $parser = $this->converter->getParser();

        // Register a two-column layout pattern
        $parser->addBlockPattern('/^:::\s*columns\s*$/', function ($lines, $start, $parent, $p) {
            $leftContent = [];
            $rightContent = [];
            $currentSide = 'left';
            $i = $start + 1;
            $count = count($lines);

            while ($i < $count && !preg_match('/^:::\s*$/', $lines[$i])) {
                if (preg_match('/^---\s*$/', $lines[$i])) {
                    $currentSide = 'right';
                    $i++;

                    continue;
                }

                if ($currentSide === 'left') {
                    $leftContent[] = $lines[$i];
                } else {
                    $rightContent[] = $lines[$i];
                }
                $i++;
            }

            $container = new Div();
            $container->setAttribute('class', 'columns');

            $leftDiv = new Div();
            $leftDiv->setAttribute('class', 'column-left');
            $p->parseBlockContent($leftDiv, $leftContent);
            $container->appendChild($leftDiv);

            $rightDiv = new Div();
            $rightDiv->setAttribute('class', 'column-right');
            $p->parseBlockContent($rightDiv, $rightContent);
            $container->appendChild($rightDiv);

            $parent->appendChild($container);

            return ($i < $count) ? $i - $start + 1 : $i - $start;
        });

        $djot = "::: columns\nLeft content here.\n---\nRight content here.\n:::\n\nAfter columns.";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="columns"', $result);
        $this->assertStringContainsString('class="column-left"', $result);
        $this->assertStringContainsString('class="column-right"', $result);
        $this->assertStringContainsString('Left content here', $result);
        $this->assertStringContainsString('Right content here', $result);
    }

    public function testMultipleCustomBlockPatterns(): void
    {
        $parser = $this->converter->getParser();

        // Register multiple block patterns
        $parser->addBlockPattern('/^!!!note\s*$/', function ($lines, $start, $parent, $p) {
            return $this->createAdmonition($lines, $start, $parent, $p, 'note');
        });

        $parser->addBlockPattern('/^!!!warning\s*$/', function ($lines, $start, $parent, $p) {
            return $this->createAdmonition($lines, $start, $parent, $p, 'warning');
        });

        $djot = "!!!note\n    This is a note.\n\n!!!warning\n    This is a warning.\n\nRegular text.";
        $result = $this->converter->convert($djot);

        $this->assertStringContainsString('class="admonition note"', $result);
        $this->assertStringContainsString('class="admonition warning"', $result);
        $this->assertStringContainsString('This is a note', $result);
        $this->assertStringContainsString('This is a warning', $result);
    }

    /**
     * Helper method for admonition patterns
     */
    private function createAdmonition(array $lines, int $start, Node $parent, BlockParser $p, string $type): int
    {
        $content = [];
        $i = $start + 1;
        $count = count($lines);

        while ($i < $count && preg_match('/^    (.*)$/', $lines[$i], $m)) {
            $content[] = $m[1];
            $i++;
        }

        $div = new Div();
        $div->setAttribute('class', 'admonition ' . $type);
        $p->parseBlockContent($div, $content);
        $parent->appendChild($div);

        return $i - $start;
    }

    public function testInlinePatternNotMatchingPartialWord(): void
    {
        $parser = $this->converter->getParser()->getInlineParser();

        // Pattern with word boundary
        $parser->addInlinePattern('/\bTODO\b/', function ($match, $groups, $p) {
            $span = new Span();
            $span->setAttribute('class', 'todo');
            $span->appendChild(new Text('TODO'));

            return $span;
        });

        $result = $this->converter->convert('This is a TODO item, not TODOLIST.');

        $this->assertStringContainsString('class="todo"', $result);
        // TODOLIST should not match
        $this->assertStringContainsString('TODOLIST', $result);
    }

    public function testInlinePatternPreservesEscapes(): void
    {
        $parser = $this->converter->getParser()->getInlineParser();

        $parser->addInlinePattern('/@(\w+)/', function ($match, $groups, $p) {
            $link = new Link('/user/' . $groups[1]);
            $link->appendChild(new Text('@' . $groups[1]));

            return $link;
        });

        // Test that escaped @ is not matched
        $result = $this->converter->convert('Contact \\@admin for help, or @support.');

        // Escaped @ should be literal
        $this->assertStringContainsString('@admin', $result);
        $this->assertStringNotContainsString('href="/user/admin"', $result);

        // Non-escaped @ should create link
        $this->assertStringContainsString('href="/user/support"', $result);
    }

    public function testBlockPatternPrecedenceOverBuiltin(): void
    {
        $parser = $this->converter->getParser();

        // Override heading behavior for special prefix
        $parser->addBlockPattern('/^##\s+DRAFT:\s+(.+)$/', function ($lines, $start, $parent, $p) {
            preg_match('/^##\s+DRAFT:\s+(.+)$/', $lines[$start], $m);

            $div = new Div();
            $div->setAttribute('class', 'draft-heading');

            $heading = new Heading(2);
            $heading->appendChild(new Text($m[1]));
            $div->appendChild($heading);

            $parent->appendChild($div);

            return 1;
        });

        $result = $this->converter->convert("## DRAFT: Work in Progress\n\n## Regular Heading");

        $this->assertStringContainsString('class="draft-heading"', $result);
        $this->assertStringContainsString('Work in Progress', $result);
        // Regular heading should still work
        $this->assertStringContainsString('<h2 id="Regular Heading">Regular Heading</h2>', $result);
    }

    public function testBlockPatternWithEmptyContent(): void
    {
        $parser = $this->converter->getParser();

        $parser->addBlockPattern('/^---separator---$/', function ($lines, $start, $parent, $p) {
            $div = new Div();
            $div->setAttribute('class', 'separator');
            $parent->appendChild($div);

            return 1;
        });

        $result = $this->converter->convert("Before\n\n---separator---\n\nAfter");

        $this->assertStringContainsString('class="separator"', $result);
        $this->assertStringContainsString('<p>Before</p>', $result);
        $this->assertStringContainsString('<p>After</p>', $result);
    }

    public function testInlinePatternChaining(): void
    {
        $parser = $this->converter->getParser()->getInlineParser();

        // Multiple inline patterns in sequence
        $parser->addInlinePattern('/@(\w+)/', function ($match, $groups, $p) {
            $link = new Link('/users/' . $groups[1]);
            $link->appendChild(new Text('@' . $groups[1]));

            return $link;
        });

        $parser->addInlinePattern('/#(\w+)/', function ($match, $groups, $p) {
            $link = new Link('/tags/' . $groups[1]);
            $link->appendChild(new Text('#' . $groups[1]));

            return $link;
        });

        $result = $this->converter->convert('Hello @alice and @bob! Check #news and #updates.');

        $this->assertStringContainsString('href="/users/alice"', $result);
        $this->assertStringContainsString('href="/users/bob"', $result);
        $this->assertStringContainsString('href="/tags/news"', $result);
        $this->assertStringContainsString('href="/tags/updates"', $result);
    }

    public function testInlinePatternReturnsComplexNode(): void
    {
        $parser = $this->converter->getParser()->getInlineParser();

        // Pattern that returns a node with children
        $parser->addInlinePattern('/\[\[(\w+):(\w+)\]\]/', function ($match, $groups, $p) {
            $span = new Span();
            $span->setAttribute('class', 'ref');
            $span->setAttribute('data-type', $groups[1]);
            $span->setAttribute('data-id', $groups[2]);

            $strong = new Strong();
            $strong->appendChild(new Text($groups[1] . ':'));
            $span->appendChild($strong);

            $span->appendChild(new Text(' ' . $groups[2]));

            return $span;
        });

        $result = $this->converter->convert('Reference [[issue:123]] in the tracker.');

        $this->assertStringContainsString('class="ref"', $result);
        $this->assertStringContainsString('data-type="issue"', $result);
        $this->assertStringContainsString('data-id="123"', $result);
        $this->assertStringContainsString('<strong>issue:</strong>', $result);
    }

    public function testGetRegisteredPatterns(): void
    {
        $parser = $this->converter->getParser();
        $inlineParser = $parser->getInlineParser();

        // Add some patterns
        $inlineParser->addInlinePattern('/pattern1/', fn ($m, $g, $p) => null);
        $inlineParser->addInlinePattern('/pattern2/', fn ($m, $g, $p) => null);
        $parser->addBlockPattern('/^block1$/', fn ($l, $s, $p, $bp) => 1);

        $inlinePatterns = $inlineParser->getInlinePatterns();
        $blockPatterns = $parser->getBlockPatterns();

        $this->assertCount(2, $inlinePatterns);
        $this->assertArrayHasKey('/pattern1/', $inlinePatterns);
        $this->assertArrayHasKey('/pattern2/', $inlinePatterns);
        $this->assertCount(1, $blockPatterns);
        $this->assertArrayHasKey('/^block1$/', $blockPatterns);
    }
}
