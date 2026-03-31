<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\CodeBlock;
use Djot\Node\Block\DefinitionDescription;
use Djot\Node\Block\DefinitionList;
use Djot\Node\Block\DefinitionTerm;
use Djot\Node\Block\Div;
use Djot\Node\Block\Heading;
use Djot\Node\Block\ListBlock;
use Djot\Node\Block\ListItem;
use Djot\Node\Block\Paragraph;
use Djot\Node\Block\Table;
use Djot\Node\Block\TableCell;
use Djot\Node\Block\TableRow;
use Djot\Node\Inline\Code;
use Djot\Node\Inline\Text;
use Djot\Node\Node;
use Djot\Renderer\HtmlRenderer;
use Djot\Util\StringUtil;

/**
 * Transforms nested divs into tabbed content interfaces
 *
 * This extension converts a wrapper div with class `tabs` containing child divs
 * with class `tab` into an accessible tabbed interface.
 *
 * Example:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new TabsExtension());
 *
 * // Or with ARIA mode (requires JavaScript):
 * $converter->addExtension(new TabsExtension(mode: 'aria'));
 * ```
 *
 * Input djot (note: outer container uses `::::` to allow nested `:::` divs):
 * ```
 * :::: tabs
 *
 * ::: tab
 * ### First Tab
 *
 * Content for the first tab.
 * :::
 *
 * ::: tab
 * ### Second Tab
 *
 * Content for the second tab.
 * :::
 *
 * ::::
 * ```
 *
 * Or with attributes:
 * ```
 * :::: tabs
 *
 * {label="First Tab"}
 * ::: tab
 * Content here.
 * :::
 *
 * {label="Second Tab" selected}
 * ::: tab
 * This tab is selected by default.
 * :::
 *
 * ::::
 * ```
 *
 * ## Output Modes
 *
 * ### CSS-only mode (default)
 *
 * Uses radio inputs and CSS sibling selectors. No JavaScript required.
 *
 * ```html
 * <div class="tabs">
 *   <input type="radio" name="tabset-1" id="tabset-1-tab-1" checked class="tabs-radio">
 *   <label for="tabset-1-tab-1" class="tabs-label">First Tab</label>
 *   <input type="radio" name="tabset-1" id="tabset-1-tab-2" class="tabs-radio">
 *   <label for="tabset-1-tab-2" class="tabs-label">Second Tab</label>
 *   <div class="tabs-panel">Content for the first tab.</div>
 *   <div class="tabs-panel">Content for the second tab.</div>
 * </div>
 * ```
 *
 * Required CSS:
 * ```css
 * .tabs { display: flex; flex-wrap: wrap; }
 * .tabs-radio { display: none; }
 * .tabs-label {
 *   padding: 0.5rem 1rem;
 *   cursor: pointer;
 *   border-bottom: 2px solid transparent;
 * }
 * .tabs-radio:checked + .tabs-label {
 *   border-bottom-color: currentColor;
 *   font-weight: bold;
 * }
 * .tabs-panel {
 *   display: none;
 *   width: 100%;
 *   order: 1;
 *   padding: 1rem 0;
 * }
 * .tabs-radio:nth-of-type(1):checked ~ .tabs-panel:nth-of-type(1),
 * .tabs-radio:nth-of-type(2):checked ~ .tabs-panel:nth-of-type(2),
 * .tabs-radio:nth-of-type(3):checked ~ .tabs-panel:nth-of-type(3),
 * .tabs-radio:nth-of-type(4):checked ~ .tabs-panel:nth-of-type(4),
 * .tabs-radio:nth-of-type(5):checked ~ .tabs-panel:nth-of-type(5) {
 *   display: block;
 * }
 * ```
 *
 * ### ARIA mode
 *
 * Uses semantic ARIA roles with button/tabpanel structure. Requires JavaScript.
 *
 * ```html
 * <div class="tabs" role="tablist">
 *   <button role="tab" id="tabset-1-tab-1" aria-selected="true"
 *           aria-controls="tabset-1-panel-1" class="tabs-tab">First Tab</button>
 *   <button role="tab" id="tabset-1-tab-2" aria-selected="false"
 *           aria-controls="tabset-1-panel-2" class="tabs-tab" tabindex="-1">Second Tab</button>
 *   <div role="tabpanel" id="tabset-1-panel-1" aria-labelledby="tabset-1-tab-1"
 *        class="tabs-panel">Content for the first tab.</div>
 *   <div role="tabpanel" id="tabset-1-panel-2" aria-labelledby="tabset-1-tab-2"
 *        class="tabs-panel" hidden>Content for the second tab.</div>
 * </div>
 * ```
 *
 * Required JavaScript:
 * ```javascript
 * document.addEventListener('click', function(e) {
 *   const tab = e.target.closest('[role="tab"]');
 *   if (!tab) return;
 *
 *   const tablist = tab.closest('[role="tablist"]');
 *   const tabs = tablist.querySelectorAll('[role="tab"]');
 *   const panels = tablist.querySelectorAll('[role="tabpanel"]');
 *
 *   tabs.forEach(t => {
 *     t.setAttribute('aria-selected', 'false');
 *     t.setAttribute('tabindex', '-1');
 *   });
 *   panels.forEach(p => p.hidden = true);
 *
 *   tab.setAttribute('aria-selected', 'true');
 *   tab.removeAttribute('tabindex');
 *   const panel = tablist.querySelector('#' + tab.getAttribute('aria-controls'));
 *   if (panel) panel.hidden = false;
 * });
 *
 * // Keyboard navigation
 * document.addEventListener('keydown', function(e) {
 *   const tab = e.target.closest('[role="tab"]');
 *   if (!tab) return;
 *
 *   const tablist = tab.closest('[role="tablist"]');
 *   const tabs = Array.from(tablist.querySelectorAll('[role="tab"]'));
 *   const index = tabs.indexOf(tab);
 *
 *   let newIndex;
 *   if (e.key === 'ArrowRight') newIndex = (index + 1) % tabs.length;
 *   else if (e.key === 'ArrowLeft') newIndex = (index - 1 + tabs.length) % tabs.length;
 *   else if (e.key === 'Home') newIndex = 0;
 *   else if (e.key === 'End') newIndex = tabs.length - 1;
 *   else return;
 *
 *   e.preventDefault();
 *   tabs[newIndex].click();
 *   tabs[newIndex].focus();
 * });
 * ```
 */
class TabsExtension implements ResettableExtensionInterface
{
    /**
     * Output mode: 'css' for CSS-only, 'aria' for ARIA with JS
     *
     * @var string
     */
    public const MODE_CSS = 'css';

    /**
     * @var string
     */
    public const MODE_ARIA = 'aria';

    /**
     * Counter for generating unique tab set IDs
     */
    protected int $tabSetCounter = 0;

    /**
     * Counter for generating fallback tab labels
     */
    protected int $labelCounter = 0;

    /**
     * @param string $mode Output mode: 'css' (default) or 'aria'
     * @param string $wrapperClass CSS class for the tabs container
     * @param string $tabClass CSS class for individual tab panels
     * @param string $labelClass CSS class for tab labels/buttons
     * @param string $radioClass CSS class for radio inputs (CSS mode only)
     * @param string $idPrefix Prefix for generated IDs
     */
    public function __construct(
        protected string $mode = self::MODE_CSS,
        protected string $wrapperClass = 'tabs',
        protected string $tabClass = 'tabs-panel',
        protected string $labelClass = 'tabs-label',
        protected string $radioClass = 'tabs-radio',
        protected string $idPrefix = 'tabset',
    ) {
    }

    public function register(DjotConverter $converter): void
    {
        // Only applies to HTML output - other renderers render tab divs normally
        $renderer = $converter->getRenderer();
        if (!$renderer instanceof HtmlRenderer) {
            return;
        }

        // Use the renderer directly for nested content to avoid calling
        // $converter->render() which clears all extensions including TOC.
        // See: https://github.com/php-collective/djot-php/issues/139
        $converter->on('render.div', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof Div) {
                return;
            }

            if (!$node->hasClass('tabs')) {
                return;
            }

            $tabs = $this->collectTabs($node, $renderer);
            if ($tabs === []) {
                return;
            }

            $html = $this->mode === self::MODE_ARIA
                ? $this->renderAriaTabs($node, $tabs, $renderer)
                : $this->renderCssTabs($node, $tabs, $renderer);

            $event->setHtml($html);
        });
    }

    public function clear(): void
    {
        $this->tabSetCounter = 0;
        $this->labelCounter = 0;
    }

    /**
     * Collect tab data from child divs
     *
     * @return array<array{label: string, content: string, selected: bool, id: string|null, node: \Djot\Node\Block\Div}>
     */
    protected function collectTabs(Div $wrapper, HtmlRenderer $renderer): array
    {
        $tabs = [];

        foreach ($wrapper->getChildren() as $child) {
            if (!$child instanceof Div || !$child->hasClass('tab')) {
                continue;
            }

            $label = $this->extractLabel($child);
            $selected = $child->hasAttribute('selected');
            $id = $child->hasAttribute('id') ? (string)$child->getAttribute('id') : null;

            // Render the tab content using the renderer directly
            $content = $this->renderTabContent($child, $renderer);

            $tabs[] = [
                'label' => $label,
                'content' => $content,
                'selected' => $selected,
                'id' => $id,
                'node' => $child, // Store original node for round-trip reconstruction
            ];
        }

        // If no tab is explicitly selected, select the first one
        if ($tabs !== [] && !in_array(true, array_column($tabs, 'selected'), true)) {
            $tabs[0]['selected'] = true;
        }

        return $tabs;
    }

    /**
     * Extract tab label from heading or attribute
     */
    protected function extractLabel(Div $tab): string
    {
        // Priority 1: label attribute
        if ($tab->hasAttribute('label')) {
            return (string)$tab->getAttribute('label');
        }

        // Priority 2: First heading
        foreach ($tab->getChildren() as $child) {
            if ($child instanceof Heading) {
                return $this->getTextContent($child);
            }
        }

        // Fallback: generic label
        return 'Tab ' . ++$this->labelCounter;
    }

    /**
     * Render tab content, removing the label heading if present
     *
     * Uses the renderer directly instead of $converter->render() to avoid
     * clearing extension state (which would break TOC, etc.)
     */
    protected function renderTabContent(Div $tab, HtmlRenderer $renderer): string
    {
        $skipFirstHeading = !$tab->hasAttribute('label');
        $skippedHeading = false;
        $html = '';

        foreach ($tab->getChildren() as $child) {
            // Skip the first heading if we're using it as the label
            if ($skipFirstHeading && !$skippedHeading && $child instanceof Heading) {
                $skippedHeading = true;

                continue;
            }

            $html .= $renderer->renderNodeFragment($child);
        }

        return $html;
    }

    /**
     * Get plain text content from a node
     */
    protected function getTextContent(Node $node): string
    {
        $text = '';

        foreach ($node->getChildren() as $child) {
            if ($child instanceof Text) {
                $text .= $child->getContent();
            } else {
                $text .= $this->getTextContent($child);
            }
        }

        return $text;
    }

    /**
     * Render tabs using CSS-only approach with radio inputs
     *
     * @param \Djot\Node\Block\Div $wrapper
     * @param array<array{label: string, content: string, selected: bool, id: string|null, node: \Djot\Node\Block\Div}> $tabs
     * @param \Djot\Renderer\HtmlRenderer $renderer
     */
    protected function renderCssTabs(Div $wrapper, array $tabs, HtmlRenderer $renderer): string
    {
        $this->tabSetCounter++;
        $setId = $this->idPrefix . '-' . $this->tabSetCounter;

        // Build wrapper attributes
        $attrs = $this->buildWrapperAttributes($wrapper);

        // Add data-djot-src for round-trip support
        if ($renderer->isRoundTripMode()) {
            $djotSrc = $this->reconstructDjotSource($wrapper, $tabs);
            $attrs .= ' data-djot-src="' . StringUtil::escapeHtml($djotSrc) . '"';
        }

        $html = '<div' . $attrs . ">\n";

        // Render all radio inputs and labels first
        foreach ($tabs as $index => $tab) {
            $tabNum = $index + 1;
            $inputId = $tab['id'] ?? ($setId . '-tab-' . $tabNum);
            $checked = $tab['selected'] ? ' checked' : '';

            $html .= '<input type="radio" name="' . StringUtil::escapeHtml($setId) . '" ';
            $html .= 'id="' . StringUtil::escapeHtml($inputId) . '"';
            $html .= ' class="' . StringUtil::escapeHtml($this->radioClass) . '"' . $checked . ">\n";

            $html .= '<label for="' . StringUtil::escapeHtml($inputId) . '" ';
            $html .= 'class="' . StringUtil::escapeHtml($this->labelClass) . '">';
            $html .= StringUtil::escapeHtml($tab['label']);
            $html .= "</label>\n";
        }

        // Render all tab panels
        foreach ($tabs as $tab) {
            $html .= '<div class="' . StringUtil::escapeHtml($this->tabClass) . '">';
            $html .= "\n" . $tab['content'];
            $html .= "</div>\n";
        }

        $html .= "</div>\n";

        return $html;
    }

    /**
     * Render tabs using ARIA roles (requires JavaScript)
     *
     * @param \Djot\Node\Block\Div $wrapper
     * @param array<array{label: string, content: string, selected: bool, id: string|null, node: \Djot\Node\Block\Div}> $tabs
     * @param \Djot\Renderer\HtmlRenderer $renderer
     */
    protected function renderAriaTabs(Div $wrapper, array $tabs, HtmlRenderer $renderer): string
    {
        $this->tabSetCounter++;
        $setId = $this->idPrefix . '-' . $this->tabSetCounter;

        // Build wrapper attributes with tablist role
        $attrs = $this->buildWrapperAttributes($wrapper, 'tablist');

        // Add data-djot-src for round-trip support
        if ($renderer->isRoundTripMode()) {
            $djotSrc = $this->reconstructDjotSource($wrapper, $tabs);
            $attrs .= ' data-djot-src="' . StringUtil::escapeHtml($djotSrc) . '"';
        }

        $html = '<div' . $attrs . ">\n";

        // Render all tab buttons first
        foreach ($tabs as $index => $tab) {
            $tabNum = $index + 1;
            $tabId = $tab['id'] ? StringUtil::escapeHtml($tab['id']) . '-tab' : $setId . '-tab-' . $tabNum;
            $panelId = $tab['id'] ? StringUtil::escapeHtml($tab['id']) . '-panel' : $setId . '-panel-' . $tabNum;
            $selected = $tab['selected'] ? 'true' : 'false';
            $tabindex = $tab['selected'] ? '' : ' tabindex="-1"';

            $html .= '<button role="tab" id="' . $tabId . '" ';
            $html .= 'aria-selected="' . $selected . '" ';
            $html .= 'aria-controls="' . $panelId . '" ';
            $html .= 'class="' . StringUtil::escapeHtml($this->labelClass) . '"' . $tabindex . '>';
            $html .= StringUtil::escapeHtml($tab['label']);
            $html .= "</button>\n";
        }

        // Render all tab panels
        foreach ($tabs as $index => $tab) {
            $tabNum = $index + 1;
            $tabId = $tab['id'] ? StringUtil::escapeHtml($tab['id']) . '-tab' : $setId . '-tab-' . $tabNum;
            $panelId = $tab['id'] ? StringUtil::escapeHtml($tab['id']) . '-panel' : $setId . '-panel-' . $tabNum;
            $hidden = $tab['selected'] ? '' : ' hidden';

            $html .= '<div role="tabpanel" id="' . $panelId . '" ';
            $html .= 'aria-labelledby="' . $tabId . '" ';
            $html .= 'class="' . StringUtil::escapeHtml($this->tabClass) . '"' . $hidden . '>';
            $html .= "\n" . $tab['content'];
            $html .= "</div>\n";
        }

        $html .= "</div>\n";

        return $html;
    }

    /**
     * Build wrapper div attributes
     */
    protected function buildWrapperAttributes(Div $wrapper, ?string $role = null): string
    {
        $classes = [$this->wrapperClass];

        // Add any additional classes from the original div (except 'tabs')
        foreach ($wrapper->getClassList() as $class) {
            if ($class !== 'tabs' && !in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        $attrs = ' class="' . StringUtil::escapeHtml(implode(' ', $classes)) . '"';

        if ($role !== null) {
            $attrs .= ' role="' . $role . '"';
        }

        // Copy other attributes (except class)
        foreach ($wrapper->getAttributes() as $name => $value) {
            if ($name === 'class') {
                continue;
            }
            $attrs .= ' ' . StringUtil::escapeHtml($name) . '="' . StringUtil::escapeHtml((string)$value) . '"';
        }

        return $attrs;
    }

    /**
     * Reconstruct the original Djot source for round-trip support
     *
     * @param \Djot\Node\Block\Div $wrapper
     * @param array<array{label: string, content: string, selected: bool, id: string|null, node: \Djot\Node\Block\Div}> $tabs
     */
    protected function reconstructDjotSource(Div $wrapper, array $tabs): string
    {
        $djot = ":::: tabs\n\n";

        foreach ($tabs as $tab) {
            $djot .= "::: tab\n";
            $djot .= '### ' . $tab['label'] . "\n\n";

            // Render tab content to Djot-like format
            $djot .= $this->renderNodeToDjot($tab['node'], true);

            $djot .= ":::\n\n";
        }

        // Remove trailing blank line
        $djot = rtrim($djot) . "\n";
        $djot .= "::::\n";

        return $djot;
    }

    /**
     * Render a node to Djot-like markup (simplified serialization)
     *
     * @param \Djot\Node\Node $node
     * @param bool $skipFirstHeading Whether to skip the first heading (used as tab label)
     */
    protected function renderNodeToDjot(Node $node, bool $skipFirstHeading = false): string
    {
        $djot = '';
        $skippedHeading = false;

        foreach ($node->getChildren() as $child) {
            // Skip the first heading if it's used as the tab label
            if ($skipFirstHeading && !$skippedHeading && $child instanceof Heading) {
                $skippedHeading = true;

                continue;
            }

            if ($child instanceof Paragraph) {
                $djot .= $this->renderInlineToDjot($child) . "\n\n";
            } elseif ($child instanceof Heading) {
                $djot .= str_repeat('#', $child->getLevel()) . ' ' . $this->renderInlineToDjot($child) . "\n\n";
            } elseif ($child instanceof ListBlock) {
                $djot .= $this->renderListToDjot($child) . "\n";
            } elseif ($child instanceof Table) {
                $djot .= $this->renderTableToDjot($child) . "\n";
            } elseif ($child instanceof CodeBlock) {
                $lang = $child->getLanguage() ?? '';
                $content = $child->getContent();

                // Choose a fence that does not conflict with the content
                $fence = StringUtil::findSafeCodeFence($content, 3);

                // Ensure the content ends with a newline so the closing fence is on its own line
                if ($content !== '' && !str_ends_with($content, "\n")) {
                    $content .= "\n";
                }

                $djot .= $fence . ($lang ? ' ' . $lang : '') . "\n";
                $djot .= $content;
                $djot .= $fence . "\n\n";
            } elseif ($child instanceof DefinitionList) {
                $djot .= $this->renderDefinitionListToDjot($child) . "\n";
            }
        }

        return $djot;
    }

    /**
     * Render inline content to Djot markup
     */
    protected function renderInlineToDjot(Node $node): string
    {
        $text = '';

        foreach ($node->getChildren() as $child) {
            if ($child instanceof Text) {
                $text .= $child->getContent();
            } elseif ($child instanceof Code) {
                $text .= '`' . $child->getContent() . '`';
            } else {
                $text .= $this->renderInlineToDjot($child);
            }
        }

        return $text;
    }

    /**
     * Render a list to Djot
     */
    protected function renderListToDjot(ListBlock $list, string $indent = ''): string
    {
        $djot = '';
        $num = 1;
        $isOrdered = $list->getListType() === ListBlock::TYPE_ORDERED;
        $marker = $isOrdered ? '%d. ' : '- ';

        foreach ($list->getChildren() as $item) {
            if (!$item instanceof ListItem) {
                continue;
            }

            $prefix = $isOrdered ? sprintf($marker, $num++) : $marker;
            $djot .= $indent . $prefix;

            $firstChild = true;
            foreach ($item->getChildren() as $child) {
                if ($child instanceof Paragraph) {
                    $djot .= $this->renderInlineToDjot($child) . "\n";
                    $firstChild = false;
                } elseif ($child instanceof ListBlock) {
                    // Nested list needs proper indentation
                    $nestedIndent = $indent . '  ';
                    $djot .= $this->renderListToDjot($child, $nestedIndent);
                }
            }
        }

        return $djot;
    }

    /**
     * Render a definition list to Djot
     */
    protected function renderDefinitionListToDjot(DefinitionList $list): string
    {
        $djot = '';

        foreach ($list->getChildren() as $child) {
            if ($child instanceof DefinitionTerm) {
                $djot .= $this->renderInlineToDjot($child) . "\n";
            } elseif ($child instanceof DefinitionDescription) {
                $djot .= ': ' . $this->renderInlineToDjot($child) . "\n";
            }
        }

        return $djot;
    }

    /**
     * Render a table to Djot
     */
    protected function renderTableToDjot(Table $table): string
    {
        $djot = '';
        $rows = [];

        // First pass: collect all cell texts
        foreach ($table->getChildren() as $rowOrSection) {
            $rowNodes = [];
            if ($rowOrSection instanceof TableRow) {
                $rowNodes = [$rowOrSection];
            } else {
                // It's a thead/tbody - get its row children
                $rowNodes = iterator_to_array($rowOrSection->getChildren());
            }

            foreach ($rowNodes as $row) {
                if (!$row instanceof TableRow) {
                    continue;
                }

                $rowTexts = [];
                foreach ($row->getChildren() as $cell) {
                    if (!$cell instanceof TableCell) {
                        continue;
                    }

                    $cellText = $this->renderInlineToDjot($cell);
                    $rowTexts[] = $cellText;
                }
                $rows[] = $rowTexts;
            }
        }

        // Get original separator widths if available
        $originalWidths = $table->getSeparatorWidths();

        // Second pass: output rows with separator after header
        foreach ($rows as $i => $row) {
            $djot .= '| ' . implode(' | ', $row) . " |\n";

            // Add separator after first row (header)
            if ($i === 0) {
                if ($originalWidths !== null) {
                    // Use original separator widths for exact round-trip
                    $separators = array_map(fn ($w) => str_repeat('-', $w), $originalWidths);
                } else {
                    // Fall back to minimal separators
                    $separators = array_fill(0, count($row), '---');
                }
                $djot .= '|' . implode('|', $separators) . "|\n";
            }
        }

        return $djot;
    }
}
