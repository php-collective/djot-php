<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Transform;

use Djot\DjotConverter;
use Djot\Extension\InlineFootnotesExtension;
use Djot\Transform\InlineFootnotesToParenthesesTransform;
use PHPUnit\Framework\TestCase;

class InlineFootnotesToParenthesesTransformTest extends TestCase
{
    public function testTransformSupportsMarkdownFallbackWithoutMutatingOriginalDocument(): void
    {
        $input = 'Text[A footnote]{.fn} continues.';

        $markdown = DjotConverter::markdown();
        $document = $markdown->parse($input);
        $transformed = $markdown->transform($document, new InlineFootnotesToParenthesesTransform());

        $this->assertStringContainsString('TextA footnote continues.', $markdown->render($document));
        $this->assertStringContainsString('Text (A footnote) continues.', $markdown->render($transformed));
    }

    public function testTransformSupportsPlainTextFallback(): void
    {
        $input = 'Text[A footnote]{.fn} continues.';

        $converter = DjotConverter::plainText();
        $document = $converter->parse($input);
        $document = $converter->transform($document, new InlineFootnotesToParenthesesTransform());

        $this->assertStringContainsString('Text (A footnote) continues.', $converter->render($document));
    }

    public function testOriginalDocumentStillRendersAsHtmlInlineFootnoteAfterNonHtmlTransform(): void
    {
        $input = 'Text[Footnote]{.fn} after.';

        $plain = DjotConverter::plainText();
        $document = $plain->parse($input);
        $plainDocument = $plain->transform($document, new InlineFootnotesToParenthesesTransform());

        $this->assertStringContainsString('Text (Footnote) after.', $plain->render($plainDocument));

        $html = new DjotConverter();
        $html->addExtension(new InlineFootnotesExtension());
        $htmlOutput = $html->render($document);

        $this->assertStringContainsString('role="doc-noteref"', $htmlOutput);
        $this->assertStringContainsString('role="doc-endnotes"', $htmlOutput);
    }
}
