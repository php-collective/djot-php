<?php

declare(strict_types=1);

namespace Djot\Renderer;

use Closure;
use Djot\Event\RenderEvent;
use Djot\Node\Block\BlockQuote;
use Djot\Node\Block\Caption;
use Djot\Node\Block\CodeBlock;
use Djot\Node\Block\Comment;
use Djot\Node\Block\DefinitionDescription;
use Djot\Node\Block\DefinitionList;
use Djot\Node\Block\DefinitionTerm;
use Djot\Node\Block\Div;
use Djot\Node\Block\Figure;
use Djot\Node\Block\Footnote;
use Djot\Node\Block\Heading;
use Djot\Node\Block\LineBlock;
use Djot\Node\Block\ListBlock;
use Djot\Node\Block\ListItem;
use Djot\Node\Block\Paragraph;
use Djot\Node\Block\RawBlock;
use Djot\Node\Block\Table;
use Djot\Node\Block\TableCell;
use Djot\Node\Block\TableRow;
use Djot\Node\Block\ThematicBreak;
use Djot\Node\Document;
use Djot\Node\Inline\Abbreviation;
use Djot\Node\Inline\Code;
use Djot\Node\Inline\Delete;
use Djot\Node\Inline\Emphasis;
use Djot\Node\Inline\FootnoteRef;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Highlight;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\Insert;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Math;
use Djot\Node\Inline\RawInline;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Span;
use Djot\Node\Inline\Strong;
use Djot\Node\Inline\Subscript;
use Djot\Node\Inline\Superscript;
use Djot\Node\Inline\Symbol;
use Djot\Node\Inline\Text;
use Djot\Node\Node;
use Djot\Renderer\Utility\EventDispatcherTrait;
use Djot\SafeMode;

/**
 * Renders AST to HTML
 */
class HtmlRenderer implements RendererInterface, RenderOptionsAwareInterface
{
    use EventDispatcherTrait;

    protected SoftBreakMode $softBreakMode = SoftBreakMode::Newline;

    /**
     * Safe mode configuration (null = disabled)
     */
    protected ?SafeMode $safeMode = null;

    /**
     * Tab width for code blocks (null = preserve tabs, integer = convert to spaces)
     */
    protected ?int $codeBlockTabWidth = 4;

    /**
     * Round-trip mode adds data attributes to preserve Djot-specific information
     * for perfect HTML→Djot conversion (e.g., list markers, thematic break characters)
     */
    protected bool $roundTripMode = false;

    protected RenderContext $sharedRenderContext;

    protected ?RenderContext $activeRenderContext = null;

    protected ?RenderOptions $renderOptions = null;

    /**
     * Dispatch table mapping node class names to render method names
     *
     * @var array<class-string<\Djot\Node\Node>, string>
     */
    protected array $nodeRenderers = [];

    public function __construct(protected bool $xhtml = false)
    {
        $this->sharedRenderContext = new RenderContext();
        $this->initNodeRenderers();
    }

    public function setRenderOptions(RenderOptions $renderOptions): self
    {
        $this->renderOptions = $renderOptions;

        return $this;
    }

    /**
     * Get the heading ID tracker
     */
    public function getHeadingIdTracker(): HeadingIdTracker
    {
        return $this->getRenderContext()->headingIdTracker;
    }

    /**
     * Initialize the node renderer dispatch table
     *
     * Maps node class names to render method names for O(1) lookup.
     */
    protected function initNodeRenderers(): void
    {
        $this->nodeRenderers = [
            Document::class => 'renderChildren',
            Paragraph::class => 'renderParagraph',
            Heading::class => 'renderHeading',
            CodeBlock::class => 'renderCodeBlock',
            Comment::class => '',
            RawBlock::class => 'renderRawBlock',
            BlockQuote::class => 'renderBlockQuote',
            DefinitionList::class => 'renderDefinitionList',
            DefinitionTerm::class => 'renderDefinitionTerm',
            DefinitionDescription::class => 'renderDefinitionDescription',
            ListBlock::class => 'renderList',
            ListItem::class => 'renderListItem',
            ThematicBreak::class => 'renderThematicBreak',
            Div::class => 'renderDiv',
            Figure::class => 'renderFigure',
            Caption::class => 'renderCaption',
            Table::class => 'renderTable',
            TableRow::class => 'renderTableRow',
            TableCell::class => 'renderTableCell',
            LineBlock::class => 'renderLineBlock',
            Footnote::class => 'renderFootnote',
            Text::class => 'renderText',
            Emphasis::class => 'renderEmphasis',
            Strong::class => 'renderStrong',
            Link::class => 'renderLink',
            Image::class => 'renderImage',
            Code::class => 'renderCode',
            RawInline::class => 'renderRawInline',
            Math::class => 'renderMath',
            Symbol::class => 'renderSymbol',
            FootnoteRef::class => 'renderFootnoteRef',
            SoftBreak::class => 'renderSoftBreak',
            HardBreak::class => 'renderHardBreak',
            Span::class => 'renderSpan',
            Highlight::class => 'renderHighlight',
            Superscript::class => 'renderSuperscript',
            Subscript::class => 'renderSubscript',
            Insert::class => 'renderInsert',
            Delete::class => 'renderDelete',
            Abbreviation::class => 'renderAbbreviation',
        ];
    }

    /**
     * Enable safe mode with the given configuration
     */
    public function setSafeMode(?SafeMode $safeMode): self
    {
        $this->safeMode = $safeMode;

        return $this;
    }

    /**
     * Get the current safe mode configuration
     */
    public function getSafeMode(): ?SafeMode
    {
        return $this->safeMode;
    }

    /**
     * Check if safe mode is enabled
     */
    public function isSafeModeEnabled(): bool
    {
        return $this->safeMode !== null;
    }

    /**
     * Set how soft breaks are rendered
     *
     * @param \Djot\Renderer\SoftBreakMode $mode How to render soft breaks:
     *   - Newline: renders as "\n" (default, not visible in browser)
     *   - Space: renders as " " (not visible in browser)
     *   - Break: renders as "<br>" (visible line break)
     */
    public function setSoftBreakMode(SoftBreakMode $mode): self
    {
        $this->softBreakMode = $mode;

        return $this;
    }

    /**
     * Get the current soft break mode
     */
    public function getSoftBreakMode(): SoftBreakMode
    {
        return $this->softBreakMode;
    }

    /**
     * Set tab width for code blocks
     *
     * When set, tabs in code blocks and inline code are converted to spaces.
     * This ensures consistent display across all browsers and contexts
     * (email clients, RSS readers, etc.) without relying on CSS tab-size.
     *
     * @param int|null $width Number of spaces per tab (null to preserve tabs)
     */
    public function setCodeBlockTabWidth(?int $width): self
    {
        $this->codeBlockTabWidth = $width;

        return $this;
    }

    /**
     * Get the current code block tab width
     */
    public function getCodeBlockTabWidth(): ?int
    {
        return $this->codeBlockTabWidth;
    }

    /**
     * Enable round-trip mode to preserve Djot-specific information in HTML output
     *
     * When enabled, adds data attributes for:
     * - List markers (data-marker for non-default markers like *, +, or ))
     * - Thematic break characters (data-char for non-default like * or _)
     *
     * This allows HtmlToDjot to reconstruct the original Djot syntax perfectly.
     */
    public function setRoundTripMode(bool $enabled): self
    {
        $this->roundTripMode = $enabled;

        return $this;
    }

    /**
     * Check if round-trip mode is enabled
     */
    public function isRoundTripMode(): bool
    {
        return $this->roundTripMode;
    }

    /**
     * Register an inline footnote and return its number
     *
     * Used by extensions like InlineFootnotesExtension to add footnotes
     * without requiring a separate footnote definition block.
     *
     * The content renderer callback is invoked lazily during renderFootnotesSection(),
     * ensuring the inline footnote's number is reserved before any nested footnotes
     * in its content are rendered.
     *
     * @param \Closure(): string $contentRenderer Callback that returns the footnote HTML content
     *
     * @return int The assigned footnote number
     */
    public function registerInlineFootnote(Closure $contentRenderer): int
    {
        $context = $this->getRenderContext();
        $context->footnoteCounter++;
        $number = $context->footnoteCounter;

        // Use a synthetic label that cannot collide with user-supplied labels.
        // Djot footnote labels cannot contain ']', so including it here ensures uniqueness.
        $label = '_inline_]' . $number;
        $context->footnoteNumbers[$label] = $number;
        $context->footnoteRefCounts[$label] = 1;

        // Store deferred content renderer
        $context->inlineFootnoteRenderers[$number] = $contentRenderer;

        return $number;
    }

    public function render(Document $document): string
    {
        return $this->withRenderContext(
            $this->sharedRenderContext,
            function () use ($document): string {
                $this->sharedRenderContext->reset();

                $html = $this->renderDocumentWithSections($document);

                if (
                    $this->sharedRenderContext->collectedFootnotes !== []
                    || $this->sharedRenderContext->footnoteNumbers !== []
                ) {
                    $html .= $this->renderFootnotesSection();
                }

                return $html;
            },
        );
    }

    /**
     * Render a single node fragment using the current renderer configuration.
     *
     * This is intended for extensions that need core rendering behavior for an
     * isolated node without re-rendering a full document.
     */
    public function renderNodeFragment(Node $node): string
    {
        return $this->withFragmentContext(fn (): string => $this->renderNode($node));
    }

    /**
     * Render a document fragment without resetting active render state.
     *
     * This is intended for extensions that need block-level rendering for a
     * temporary document while participating in the current render.
     */
    public function renderDocumentFragment(Document $document): string
    {
        return $this->withFragmentContext(fn (): string => $this->renderDocumentWithSections($document));
    }

    /**
     * Render document with section wrapping around headings
     *
     * @phpstan-impure Populates collectedFootnotes and footnoteNumbers during rendering
     */
    protected function renderDocumentWithSections(Document $document): string
    {
        $children = $document->getChildren();
        $html = '';
        /** @var array<int, int> $openSections Level => count of open sections at that level */
        $openSections = [];

        $childCount = count($children);
        for ($i = 0; $i < $childCount; $i++) {
            $child = $children[$i];

            if ($child instanceof Heading) {
                $level = $child->getLevel();
                $customHtml = null;

                // Dispatch render event for heading - allows custom rendering
                if ($this->hasAnyListeners()) {
                    $eventName = 'render.' . $child->getType();
                    $event = new RenderEvent($child);
                    $event->setChildrenRenderer(fn (): string => $this->renderChildren($child));
                    $this->dispatchEvent($eventName, $event);
                    $this->dispatchEvent('render.*', $event);

                    if ($event->isDefaultPrevented()) {
                        $customHtml = $event->getHtml();
                    }
                }

                // Close any sections at same or deeper level
                for ($l = 6; $l >= $level; $l--) {
                    while (!empty($openSections[$l]) && $openSections[$l] > 0) {
                        $html .= "</section>\n";
                        $openSections[$l]--;
                    }
                }

                // If event provided custom HTML, use it (without section wrapper)
                if ($customHtml !== null) {
                    $html .= $customHtml;

                    continue;
                }

                // Get the section ID
                $sectionId = $this->getSectionId($child);

                // Open new section
                $html .= '<section id="' . $this->escapeAttribute($sectionId) . '">' . "\n";
                if (!isset($openSections[$level])) {
                    $openSections[$level] = 0;
                }
                $openSections[$level]++;

                // Render heading without section wrapper
                $html .= $this->renderHeadingContent($child);
            } else {
                // Track IDs from non-heading elements for deduplication
                $this->trackIdFromNode($child);
                $html .= $this->renderNode($child);
            }
        }

        // Close all open sections (deepest first)
        for ($l = 6; $l >= 1; $l--) {
            while (!empty($openSections[$l]) && $openSections[$l] > 0) {
                $html .= "</section>\n";
                $openSections[$l]--;
            }
        }

        return $html;
    }

    /**
     * Generate section ID from heading
     */
    protected function getSectionId(Heading $node): string
    {
        return $this->getRenderContext()->headingIdTracker->getIdForHeading($node);
    }

    /**
     * Track ID usage from non-heading elements (like paragraphs with explicit IDs)
     */
    protected function trackIdFromNode(Node $node): void
    {
        if ($node->hasAttribute('id')) {
            $idAttr = $node->getAttribute('id');
            $id = is_string($idAttr) ? $idAttr : '';
            $this->getRenderContext()->headingIdTracker->trackId($id);
        }
    }

    /**
     * Render just the heading tag content (without section wrapper)
     */
    protected function renderHeadingContent(Heading $node): string
    {
        $level = $this->getRenderedHeadingLevel($node);

        // Don't render id on heading since it's on section
        $attrs = $this->renderAttributesExcluding($node, ['id']);

        return '<h' . $level . $attrs . '>' . $this->renderChildren($node) . '</h' . $level . ">\n";
    }

    /**
     * Render node attributes as HTML string, excluding specified attributes
     *
     * Respects safe mode filtering when enabled.
     *
     * @param \Djot\Node\Node $node
     * @param array<string> $exclude Attribute names to exclude
     */
    public function renderAttributesExcluding(Node $node, array $exclude): string
    {
        return $this->renderAttributeArray($this->getRenderableAttributes($node, $exclude));
    }

    protected function renderNode(Node $node): string
    {
        // Only dispatch events if listeners are registered (avoid object allocation)
        if ($this->hasAnyListeners()) {
            $eventName = 'render.' . $node->getType();
            $event = new RenderEvent($node);

            // Provide lazy children renderer for extensions that need to wrap children
            $event->setChildrenRenderer(fn (): string => $this->renderChildren($node));

            // Call specific listeners
            $this->dispatchEvent($eventName, $event);

            // Call wildcard listeners
            $this->dispatchEvent('render.*', $event);

            // If listener provided custom HTML, use it
            if ($event->isDefaultPrevented()) {
                return $event->getHtml() ?? '';
            }
        }

        // Use dispatch table for O(1) lookup instead of instanceof chain
        $class = $node::class;
        if (isset($this->nodeRenderers[$class])) {
            $method = $this->nodeRenderers[$class];
            if ($method === '') {
                return ''; // Comment nodes
            }

            /** @var string */
            return $this->$method($node);
        }

        return $this->renderChildren($node);
    }

    protected function renderChildren(Node $node): string
    {
        $html = '';
        foreach ($node->getChildren() as $child) {
            $html .= $this->renderNode($child);
        }

        return $html;
    }

    protected function renderParagraph(Paragraph $node): string
    {
        $attrs = $this->renderAttributes($node);
        $content = rtrim($this->renderChildren($node), " \t");

        return '<p' . $attrs . '>' . $content . "</p>\n";
    }

    protected function renderHeading(Heading $node): string
    {
        // This is called when a heading is rendered inside other blocks (blockquote, div, etc.)
        // Section wrapping is ONLY applied at document level by renderDocumentWithSections
        // Inside nested blocks, headings just get id attribute directly
        $level = $this->getRenderedHeadingLevel($node);
        $sectionId = $this->getSectionId($node);
        $attrs = $this->renderAttributesExcluding($node, ['id']);

        return '<h' . $level . ' id="' . $this->escapeAttribute($sectionId) . '"' . $attrs . '>'
            . $this->renderChildren($node) . '</h' . $level . ">\n";
    }

    /**
     * Get plain text content of a node (for generating heading IDs)
     */
    protected function getPlainText(Node $node): string
    {
        return $this->getRenderContext()->headingIdTracker->getPlainText($node);
    }

    protected function getRenderedHeadingLevel(Heading $node): int
    {
        return min($node->getLevel() + $this->getRenderOptions()->headingLevelShift, 6);
    }

    protected function getRenderOptions(): RenderOptions
    {
        return $this->renderOptions ??= new RenderOptions();
    }

    protected function renderCodeBlock(CodeBlock $node): string
    {
        $language = $node->getLanguage();
        $attrs = $this->renderAttributes($node);

        $code = $this->escape($node->getContent());

        // Convert tabs to spaces if configured
        if ($this->codeBlockTabWidth !== null) {
            $code = str_replace("\t", str_repeat(' ', $this->codeBlockTabWidth), $code);
        }

        // Add trailing newline inside code block (official djot behavior)
        if ($code !== '' && !str_ends_with($code, "\n")) {
            $code .= "\n";
        }

        if ($language !== null) {
            $langClass = 'class="language-' . $this->escapeAttribute($language) . '"';

            return '<pre' . $attrs . '><code ' . $langClass . '>' . $code . "</code></pre>\n";
        }

        return '<pre' . $attrs . '><code>' . $code . "</code></pre>\n";
    }

    protected function renderBlockQuote(BlockQuote $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<blockquote' . $attrs . ">\n" . $this->renderChildren($node) . "</blockquote>\n";
    }

    protected function renderList(ListBlock $node): string
    {
        $attrs = $this->getRenderableAttributes($node);
        $tight = $node->isTight();

        // Render children with tight parameter
        $html = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof ListItem) {
                $html .= $this->renderListItem($child, $tight);
            } else {
                $html .= $this->renderNode($child);
            }
        }

        if ($node->getListType() === ListBlock::TYPE_ORDERED) {
            $olAttrs = '';
            $start = $node->getStart();
            $style = $node->getStyle();
            $marker = $node->getMarker();

            // Order: start, then type, then other attrs
            if ($start !== 1) {
                $olAttrs .= ' start="' . $start . '"';
            }
            if ($style !== null) {
                $olAttrs .= ' type="' . $style . '"';
            }
            // Preserve marker for round-trip (only if non-default and round-trip mode enabled)
            if ($this->roundTripMode && $marker !== null && $marker !== '.') {
                $olAttrs .= ' data-marker="' . htmlspecialchars($marker, ENT_QUOTES) . '"';
            }

            return '<ol' . $olAttrs . $this->renderAttributeArray($attrs) . ">\n" . $html . "</ol>\n";
        }

        if ($node->getListType() === ListBlock::TYPE_TASK) {
            $attrs = $this->mergeAttribute($attrs, 'class', 'task-list');
        }

        // Preserve marker for round-trip (only if non-default and round-trip mode enabled)
        $marker = $node->getMarker();
        $markerAttr = '';
        if ($this->roundTripMode && $marker !== null && $marker !== '-') {
            $markerAttr = ' data-marker="' . htmlspecialchars($marker, ENT_QUOTES) . '"';
        }

        return '<ul' . $markerAttr . $this->renderAttributeArray($attrs) . ">\n" . $html . "</ul>\n";
    }

    protected function renderListItem(ListItem $node, bool $tight = true): string
    {
        $attrs = $this->renderAttributes($node);
        $content = $this->renderChildren($node);

        if ($tight) {
            // For tight lists, strip paragraph wrapper from content
            // This handles both:
            // 1. Single paragraph: <p>text</p> -> text
            // 2. Paragraph followed by nested list: <p>text</p>\n<ul>... -> text\n<ul>...
            $content = preg_replace('/^<p>(.+?)<\/p>(\n)?/s', '$1$2', $content) ?? $content;
        }

        // Handle task list items
        if ($node->isTask()) {
            $checked = $node->getChecked() ? ' checked=""' : '';
            // Always use xhtml-style format for task list checkboxes
            $checkbox = '<input disabled="" type="checkbox"' . $checked . "/>\n";
            $content = $checkbox . rtrim($content);
        } else {
            $content = rtrim($content);
        }

        // In djot, list item content is always on its own line
        return '<li' . $attrs . ">\n" . $content . "\n</li>\n";
    }

    protected function renderThematicBreak(ThematicBreak $node): string
    {
        $attrs = $this->renderAttributes($node);
        // Preserve character for round-trip (only if non-default and round-trip mode enabled)
        if ($this->roundTripMode && $node->char !== '-') {
            $attrs .= ' data-char="' . htmlspecialchars($node->char, ENT_QUOTES) . '"';
        }

        return $this->xhtml ? '<hr' . $attrs . " />\n" : '<hr' . $attrs . ">\n";
    }

    protected function renderDiv(Div $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<div' . $attrs . ">\n" . $this->renderChildren($node) . "</div>\n";
    }

    protected function renderLineBlock(LineBlock $node): string
    {
        $attrs = $this->getRenderableAttributes($node);
        $attrs = $this->mergeAttribute($attrs, 'class', 'line-block');

        return '<div' . $this->renderAttributeArray($attrs) . ">\n" . $this->renderChildren($node) . "</div>\n";
    }

    protected function renderFigure(Figure $node): string
    {
        $attrs = $this->renderAttributes($node);
        $html = '<figure' . $attrs . ">\n";

        foreach ($node->getChildren() as $child) {
            if ($child instanceof Caption) {
                // Caption becomes figcaption
                $html .= '<figcaption>' . rtrim($this->renderChildren($child)) . "</figcaption>\n";
            } elseif ($child instanceof Image) {
                // Image rendered directly (not wrapped in p)
                $html .= $this->renderImage($child);
            } else {
                // Other content (blockquote, etc.)
                $html .= $this->renderNode($child);
            }
        }

        return $html . "</figure>\n";
    }

    protected function renderCaption(Caption $node): string
    {
        // Caption is usually rendered as part of figure or table
        // This is a fallback if caption appears standalone
        return '<figcaption>' . rtrim($this->renderChildren($node)) . "</figcaption>\n";
    }

    protected function renderTable(Table $node): string
    {
        $attrs = $this->renderAttributes($node);
        $html = '<table' . $attrs . ">\n";

        // Render caption if present
        if ($node->hasCaption()) {
            /** @var \Djot\Node\Block\Caption $caption */
            $caption = $node->getCaption();
            $captionHtml = $this->renderChildren($caption);
            $html .= '<caption>' . rtrim($captionHtml) . "</caption>\n";
        }

        // djot tables don't use thead/tbody - just rows with th or td cells
        foreach ($node->getChildren() as $row) {
            if ($row instanceof TableRow) {
                $html .= $this->renderTableRow($row);
            }
        }

        return $html . "</table>\n";
    }

    protected function renderTableRow(TableRow $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<tr' . $attrs . ">\n" . $this->renderChildren($node) . "</tr>\n";
    }

    protected function renderTableCell(TableCell $node): string
    {
        $tag = $node->isHeader() ? 'th' : 'td';
        $attrs = $this->getRenderableAttributes($node);

        $rowspan = $node->getRowspan();
        if ($rowspan > 1) {
            $attrs['rowspan'] = (string)$rowspan;
        }

        $colspan = $node->getColspan();
        if ($colspan > 1) {
            $attrs['colspan'] = (string)$colspan;
        }

        $alignment = $node->getAlignment();
        if ($alignment !== TableCell::ALIGN_DEFAULT) {
            $attrs = $this->mergeAttribute($attrs, 'style', 'text-align: ' . $alignment . ';');
        }

        return '<' . $tag . $this->renderAttributeArray($attrs) . '>' . $this->renderChildren($node) . '</' . $tag . ">\n";
    }

    protected function renderText(Text $node): string
    {
        return $this->escape($node->getContent());
    }

    protected function renderEmphasis(Emphasis $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<em' . $attrs . '>' . $this->renderChildren($node) . '</em>';
    }

    protected function renderStrong(Strong $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<strong' . $attrs . '>' . $this->renderChildren($node) . '</strong>';
    }

    protected function renderLink(Link $node): string
    {
        $attrs = $this->renderAttributes($node);
        $href = $node->getDestination();
        $title = $node->getTitle();

        // Sanitize URL in safe mode
        if ($this->safeMode !== null && $href !== null) {
            $href = $this->safeMode->sanitizeUrl($href);
        }

        $html = '<a';
        // Only output href if destination is set (even if empty)
        if ($href !== null) {
            $html .= ' href="' . $this->escapeAttribute($href) . '"';
        }
        if ($title !== null) {
            $html .= ' title="' . $this->escapeAttribute($title) . '"';
        }
        $html .= $attrs . '>' . $this->renderChildren($node) . '</a>';

        return $html;
    }

    protected function renderImage(Image $node): string
    {
        $attrs = $this->renderAttributes($node);
        $alt = $this->escapeAttribute($node->getAlt());
        $src = $node->getSource();
        $title = $node->getTitle();

        // Sanitize URL in safe mode
        if ($this->safeMode !== null) {
            $src = $this->safeMode->sanitizeUrl($src);
        }

        $html = '<img alt="' . $alt . '" src="' . $this->escapeAttribute($src) . '"';
        if ($title !== null) {
            $html .= ' title="' . $this->escapeAttribute($title) . '"';
        }
        $html .= $attrs;

        return $this->xhtml ? $html . ' />' : $html . '>';
    }

    protected function renderCode(Code $node): string
    {
        $attrs = $this->renderAttributes($node);
        $content = $this->escape($node->getContent());

        // Convert tabs to spaces if configured
        if ($this->codeBlockTabWidth !== null) {
            $content = str_replace("\t", str_repeat(' ', $this->codeBlockTabWidth), $content);
        }

        return '<code' . $attrs . '>' . $content . '</code>';
    }

    protected function renderSoftBreak(): string
    {
        return match ($this->softBreakMode) {
            SoftBreakMode::Newline => "\n",
            SoftBreakMode::Space => ' ',
            SoftBreakMode::Break => $this->xhtml ? "<br />\n" : "<br>\n",
        };
    }

    protected function renderHardBreak(): string
    {
        return $this->xhtml ? "<br />\n" : "<br>\n";
    }

    protected function renderSpan(Span $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<span' . $attrs . '>' . $this->renderChildren($node) . '</span>';
    }

    protected function renderHighlight(Highlight $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<mark' . $attrs . '>' . $this->renderChildren($node) . '</mark>';
    }

    protected function renderSuperscript(Superscript $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<sup' . $attrs . '>' . $this->renderChildren($node) . '</sup>';
    }

    protected function renderSubscript(Subscript $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<sub' . $attrs . '>' . $this->renderChildren($node) . '</sub>';
    }

    protected function renderInsert(Insert $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<ins' . $attrs . '>' . $this->renderChildren($node) . '</ins>';
    }

    protected function renderDelete(Delete $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<del' . $attrs . '>' . $this->renderChildren($node) . '</del>';
    }

    protected function renderAbbreviation(Abbreviation $node): string
    {
        $attrs = $this->renderAttributes($node);
        $title = $node->getTitle();

        return '<abbr title="' . $this->escapeAttribute($title) . '"' . $attrs . '>'
            . $this->renderChildren($node) . '</abbr>';
    }

    protected function renderAttributes(Node $node): string
    {
        return $this->renderAttributeArray($this->getRenderableAttributes($node));
    }

    /**
     * @param \Djot\Node\Node $node
     * @param array<string> $exclude
     *
     * @return array<string, string>
     */
    protected function getRenderableAttributes(Node $node, array $exclude = []): array
    {
        $attrs = $node->getAttributes();
        if (!$attrs) {
            return [];
        }

        if ($exclude !== []) {
            $attrs = array_diff_key($attrs, array_flip($exclude));
        }

        // Filter dangerous attributes in safe mode
        if ($this->safeMode !== null) {
            $attrs = $this->safeMode->filterAttributes($attrs);
        }

        return $attrs;
    }

    /**
     * @param array<string, string> $attrs
     */
    protected function renderAttributeArray(array $attrs): string
    {
        if ($attrs === []) {
            return '';
        }

        // Preserve source order of attributes (matching JS reference implementation)
        $html = '';
        foreach ($attrs as $key => $value) {
            $html .= ' ' . $this->escape($key) . '="' . $this->escapeAttribute($value) . '"';
        }

        return $html;
    }

    /**
     * @param array<string, string> $attrs
     * @param string $value
     * @param string $key
     *
     * @return array<string, string>
     */
    protected function mergeAttribute(array $attrs, string $key, string $value): array
    {
        if ($value === '') {
            return $attrs;
        }

        if (!isset($attrs[$key]) || $attrs[$key] === '') {
            $attrs[$key] = $value;

            return $attrs;
        }

        if ($key === 'class') {
            $attrs[$key] .= ' ' . $value;

            return $attrs;
        }

        if ($key === 'style') {
            $existing = rtrim($attrs[$key]);
            if ($existing !== '' && !str_ends_with($existing, ';')) {
                $existing .= ';';
            }
            $attrs[$key] = trim($existing . ' ' . $value);

            return $attrs;
        }

        $attrs[$key] = $value;

        return $attrs;
    }

    protected function escape(string $text): string
    {
        // ENT_NOQUOTES: Don't convert quotes - official djot keeps them literal
        // Only escape <, >, and & for HTML safety
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

        // Convert escaped space placeholder (U+E000) to &nbsp; entity
        // Literal NBSP characters in source are preserved as-is
        return str_replace("\u{E000}", '&nbsp;', $escaped);
    }

    /**
     * Escape text for use in HTML attribute values
     *
     * Unlike escape(), this DOES escape quotes since they're in attribute context
     */
    public function escapeAttribute(string $text): string
    {
        // ENT_QUOTES: Escape both single and double quotes for attribute values
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Convert escaped space placeholder (U+E000) to &nbsp; entity
        return str_replace("\u{E000}", '&nbsp;', $escaped);
    }

    protected function renderRawBlock(RawBlock $node): string
    {
        // Only output if format is HTML
        if ($node->getFormat() !== 'html') {
            return '';
        }

        $content = $node->getContent();

        // Handle raw HTML according to safe mode
        if ($this->safeMode !== null) {
            $mode = $this->safeMode->getRawHtmlMode();
            if ($mode === SafeMode::RAW_HTML_STRIP) {
                return '';
            }
            if ($mode === SafeMode::RAW_HTML_ESCAPE) {
                return $this->escape($content) . "\n";
            }
        }

        return $content . "\n";
    }

    protected function renderRawInline(RawInline $node): string
    {
        // Only output if format is HTML
        if ($node->getFormat() !== 'html') {
            return '';
        }

        $content = $node->getContent();

        // Handle raw HTML according to safe mode
        if ($this->safeMode !== null) {
            $mode = $this->safeMode->getRawHtmlMode();
            if ($mode === SafeMode::RAW_HTML_STRIP) {
                return '';
            }
            if ($mode === SafeMode::RAW_HTML_ESCAPE) {
                return $this->escape($content);
            }
        }

        return $content;
    }

    protected function renderDefinitionList(DefinitionList $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<dl' . $attrs . ">\n" . $this->renderChildren($node) . "</dl>\n";
    }

    protected function renderDefinitionTerm(DefinitionTerm $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<dt' . $attrs . '>' . $this->renderChildren($node) . "</dt>\n";
    }

    protected function renderDefinitionDescription(DefinitionDescription $node): string
    {
        $attrs = $this->renderAttributes($node);
        $content = $this->renderChildren($node);

        // Content goes on separate lines
        $content = rtrim($content);
        if ($content === '') {
            return '<dd' . $attrs . ">\n</dd>\n";
        }

        return '<dd' . $attrs . ">\n" . $content . "\n</dd>\n";
    }

    protected function renderFootnote(Footnote $node): string
    {
        // Collect footnote for rendering at document end, don't output here
        $label = $node->getLabel();
        $this->getRenderContext()->collectedFootnotes[$label] = $node;

        return '';
    }

    /**
     * Render all collected footnotes as end section
     */
    protected function renderFootnotesSection(): string
    {
        $context = $this->getRenderContext();

        // Pre-render all footnote contents to discover any nested footnote references
        // Keep iterating until no new footnotes are discovered
        $renderedContents = [];
        $processedNumbers = [];

        do {
            $newFootnotes = false;
            foreach ($context->footnoteNumbers as $label => $number) {
                if (isset($processedNumbers[$number])) {
                    continue;
                }
                $processedNumbers[$number] = true;

                if (isset($context->inlineFootnoteRenderers[$number])) {
                    // Inline footnote - invoke deferred renderer
                    $renderedContents[$number] = trim(($context->inlineFootnoteRenderers[$number])());
                } elseif (isset($context->collectedFootnotes[$label])) {
                    // Regular footnote - rendering may discover new footnote references
                    $renderedContents[$number] = trim($this->renderChildren($context->collectedFootnotes[$label]));
                } else {
                    $renderedContents[$number] = '';
                }

                // Check if new footnotes were discovered during rendering
                if (count($context->footnoteNumbers) > count($processedNumbers)) {
                    $newFootnotes = true;
                }
            }
        } while ($newFootnotes);

        // Sort footnotes by their reference number order
        ksort($renderedContents);

        $html = '<section role="doc-endnotes">' . "\n";
        $html .= '<hr>' . "\n";
        $html .= '<ol>' . "\n";

        foreach ($renderedContents as $number => $content) {
            $html .= '<li id="fn' . $number . '">' . "\n";

            // Find the label for this footnote number to get ref count
            $label = array_search($number, $context->footnoteNumbers, true);
            $refCount = $label !== false ? ($context->footnoteRefCounts[$label] ?? 1) : 1;

            // Generate backlinks - multiple if footnote referenced multiple times
            $backlinks = $this->generateBacklinks($number, $refCount);

            // Add backlink - if content ends with </p>, insert before it
            // Otherwise add as separate paragraph
            if ($content !== '' && preg_match('/^(.*)(<\/p>\n?)$/s', $content, $matches)) {
                $content = $matches[1] . $backlinks . '</p>';
                $html .= $content . "\n";
            } else {
                // Content doesn't end with paragraph (e.g., code block or empty)
                if ($content !== '') {
                    $html .= $content . "\n";
                }
                $html .= '<p>' . $backlinks . '</p>' . "\n";
            }

            $html .= '</li>' . "\n";
        }

        $html .= '</ol>' . "\n";
        $html .= '</section>' . "\n";

        return $html;
    }

    /**
     * Generate backlink(s) for a footnote
     *
     * @param int $number Footnote number
     * @param int $refCount Number of times footnote was referenced
     */
    protected function generateBacklinks(int $number, int $refCount): string
    {
        if ($refCount <= 1) {
            // Single reference - simple backlink
            return '<a href="#fnref' . $number . '" role="doc-backlink">↩︎</a>';
        }

        // Multiple references - generate numbered backlinks
        $links = [];
        for ($i = 1; $i <= $refCount; $i++) {
            $refId = 'fnref' . $number;
            if ($i > 1) {
                $refId .= '-' . $i;
            }
            $links[] = '<a href="#' . $refId . '" role="doc-backlink">↩︎<sup>' . $i . '</sup></a>';
        }

        return implode(' ', $links);
    }

    protected function renderFootnoteRef(FootnoteRef $node): string
    {
        $context = $this->getRenderContext();
        $label = $node->getLabel();

        // Assign number to footnote on first reference
        if (!isset($context->footnoteNumbers[$label])) {
            $context->footnoteCounter++;
            $context->footnoteNumbers[$label] = $context->footnoteCounter;
        }
        $number = $context->footnoteNumbers[$label];

        // Track reference count for this footnote to generate unique IDs
        if (!isset($context->footnoteRefCounts[$label])) {
            $context->footnoteRefCounts[$label] = 0;
        }
        $context->footnoteRefCounts[$label]++;
        $refCount = $context->footnoteRefCounts[$label];

        // Generate unique ID: fnref1 for first, fnref1-2 for second, etc.
        $refId = 'fnref' . $number;
        if ($refCount > 1) {
            $refId .= '-' . $refCount;
        }

        // Format: <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a>
        return '<a id="' . $refId . '" href="#fn' . $number . '" role="doc-noteref"><sup>' . $number . '</sup></a>';
    }

    protected function renderMath(Math $node): string
    {
        $content = $this->escape($node->getContent());

        if ($node->isDisplay()) {
            return '<span class="math display">\\[' . $content . '\\]</span>';
        }

        return '<span class="math inline">\\(' . $content . '\\)</span>';
    }

    protected function renderSymbol(Symbol $node): string
    {
        // By default, symbols are rendered as their name
        // Could be extended to support emoji mappings
        return ':' . $this->escape($node->getName()) . ':';
    }

    protected function getRenderContext(): RenderContext
    {
        return $this->activeRenderContext ?? $this->sharedRenderContext;
    }

    /**
     * @param \Closure(): string $callback
     */
    protected function withFragmentContext(Closure $callback): string
    {
        $context = $this->activeRenderContext ?? new RenderContext();

        return $this->withRenderContext($context, $callback);
    }

    /**
     * @param \Djot\Renderer\RenderContext $context
     * @param \Closure(): string $callback
     */
    protected function withRenderContext(RenderContext $context, Closure $callback): string
    {
        $previousContext = $this->activeRenderContext;
        $this->activeRenderContext = $context;

        try {
            return $callback();
        } finally {
            $this->activeRenderContext = $previousContext;
        }
    }
}
