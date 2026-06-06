<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Renderer\AsciiTransliterator;
use Djot\Renderer\HtmlRenderer;

/**
 * Folds auto-generated heading ids to ASCII (Über -> Uber, café -> cafe, Привет ->
 * Privet) for maximum URL/CSS-fragment portability.
 *
 * By default djot-php generates spec-faithful ids (jgm/djot#393) that preserve
 * non-ASCII characters. Adding this extension layers an ASCII transliteration on top
 * of that, as a pluggable id transform - it does not fork the core slugger.
 *
 * The transform is wired to BOTH the renderer's HeadingIdTracker and the parser's
 * heading-reference resolution pass, so `<section id>` values and implicit
 * `[Heading][]` link targets stay in parity.
 */
class AsciiHeadingIdsExtension implements ExtensionInterface
{
    /**
     * @param bool|null $useIntl Force the transliteration engine; null auto-detects
     *     ext-intl (ICU) and otherwise uses the built-in baked map.
     */
    public function __construct(protected ?bool $useIntl = null)
    {
    }

    public function register(DjotConverter $converter): void
    {
        $transliterator = new AsciiTransliterator($this->useIntl);
        $transform = static fn (string $id): string => $transliterator->transliterate($id);

        // Renderer side (section ids). getHeadingIdTracker() only exists for HTML.
        if ($converter->getRenderer() instanceof HtmlRenderer) {
            $converter->getHeadingIdTracker()->setIdTransformer($transform);
        }

        // Parser side (implicit [Heading][] reference resolution) - keeps the link
        // targets identical to the rendered section ids.
        $converter->getParser()->setHeadingIdTransformer($transform);
    }
}
