<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Text;

/**
 * Auto-links bare URLs in text
 *
 * Converts plain URLs (http://, https://, mailto:) into clickable links
 * without requiring explicit link syntax.
 *
 * Example:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new AutolinkExtension());
 *
 * $html = $converter->convert('Visit https://example.com for more info.');
 * // Output: <p>Visit <a href="https://example.com">https://example.com</a> for more info.</p>
 * ```
 *
 * Configuration:
 * ```php
 * $autolink = new AutolinkExtension(
 *     allowedSchemes: ['https', 'http'], // Only http/https (default includes mailto)
 * );
 * ```
 */
class AutolinkExtension implements ExtensionInterface
{
    /**
     * @param array<string> $allowedSchemes URL schemes to auto-link
     */
    public function __construct(
        protected array $allowedSchemes = ['https', 'http', 'mailto'],
    ) {
    }

    /**
     * @return void
     */
    public function register(DjotConverter $converter): void
    {
        $inlineParser = $converter->getParser()->getInlineParser();

        // Build pattern for allowed schemes
        $schemes = implode('|', array_map('preg_quote', $this->allowedSchemes));

        // Pattern for URLs - matches scheme:// followed by non-whitespace, non-special chars
        // Handles trailing punctuation gracefully
        $pattern = '/(' . $schemes . '):\/\/[^\s<>\[\]()]*[^\s<>\[\]().,;:!?\'"]/';

        $inlineParser->addInlinePattern(
            $pattern,
            function (string $match, array $groups): Link {
                $url = $match;

                $link = new Link($url);
                $link->appendChild(new Text($url));

                return $link;
            },
        );

        // Also handle mailto: links (without the //)
        if (in_array('mailto', $this->allowedSchemes, true)) {
            // Simple email pattern
            $emailPattern = '/mailto:[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';

            $inlineParser->addInlinePattern(
                $emailPattern,
                function (string $match, array $groups): Link {
                    $link = new Link($match);
                    // Display without mailto: prefix
                    $display = substr($match, 7);
                    $link->appendChild(new Text($display));

                    return $link;
                },
            );

            // Also match bare email addresses
            $bareEmailPattern = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';

            $inlineParser->addInlinePattern(
                $bareEmailPattern,
                function (string $match, array $groups): Link {
                    $link = new Link('mailto:' . $match);
                    $link->appendChild(new Text($match));

                    return $link;
                },
            );
        }
    }
}
