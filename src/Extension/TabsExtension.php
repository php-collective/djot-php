<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Div;
use Djot\Node\Block\Heading;
use Djot\Node\Document;
use Djot\Node\Inline\Text;
use Djot\Node\Node;

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
class TabsExtension implements ExtensionInterface
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
        $converter->on('render.div', function (RenderEvent $event) use ($converter): void {
            $node = $event->getNode();
            if (!$node instanceof Div) {
                return;
            }

            if (!$this->hasClass($node, 'tabs')) {
                return;
            }

            $tabs = $this->collectTabs($node, $converter);
            if ($tabs === []) {
                return;
            }

            $html = $this->mode === self::MODE_ARIA
                ? $this->renderAriaTabs($node, $tabs)
                : $this->renderCssTabs($node, $tabs);

            $event->setHtml($html);
        });
    }

    /**
     * Collect tab data from child divs
     *
     * @return array<array{label: string, content: string, selected: bool, id: string|null}>
     */
    protected function collectTabs(Div $wrapper, DjotConverter $converter): array
    {
        $tabs = [];

        foreach ($wrapper->getChildren() as $child) {
            if (!$child instanceof Div || !$this->hasClass($child, 'tab')) {
                continue;
            }

            $label = $this->extractLabel($child);
            $selected = $child->hasAttribute('selected');
            $id = $child->hasAttribute('id') ? (string)$child->getAttribute('id') : null;

            // Render the tab content manually to bypass normal div rendering
            $content = $this->renderTabContent($child, $converter);

            $tabs[] = [
                'label' => $label,
                'content' => $content,
                'selected' => $selected,
                'id' => $id,
            ];
        }

        // If no tab is explicitly selected, select the first one
        if ($tabs !== [] && !array_filter($tabs, fn ($t) => $t['selected'])) {
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
        /** @var int $counter */
        static $counter = 0;

        return 'Tab ' . ++$counter;
    }

    /**
     * Render tab content, removing the label heading if present
     */
    protected function renderTabContent(Div $tab, DjotConverter $converter): string
    {
        $skipFirstHeading = !$tab->hasAttribute('label');
        $skippedHeading = false;

        // Create a temporary document with only the children we want to render
        $tempDoc = new Document();

        foreach ($tab->getChildren() as $child) {
            // Skip the first heading if we're using it as the label
            if ($skipFirstHeading && !$skippedHeading && $child instanceof Heading) {
                $skippedHeading = true;

                continue;
            }

            $tempDoc->appendChild($child);
        }

        return $converter->render($tempDoc);
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
     * @param array<array{label: string, content: string, selected: bool, id: string|null}> $tabs
     */
    protected function renderCssTabs(Div $wrapper, array $tabs): string
    {
        $this->tabSetCounter++;
        $setId = $this->idPrefix . '-' . $this->tabSetCounter;

        // Build wrapper attributes
        $attrs = $this->buildWrapperAttributes($wrapper);

        $html = '<div' . $attrs . ">\n";

        // Render all radio inputs and labels first
        foreach ($tabs as $index => $tab) {
            $tabNum = $index + 1;
            $inputId = $tab['id'] ?? ($setId . '-tab-' . $tabNum);
            $checked = $tab['selected'] ? ' checked' : '';

            $html .= '<input type="radio" name="' . $this->escape($setId) . '" ';
            $html .= 'id="' . $this->escape($inputId) . '"';
            $html .= ' class="' . $this->escape($this->radioClass) . '"' . $checked . ">\n";

            $html .= '<label for="' . $this->escape($inputId) . '" ';
            $html .= 'class="' . $this->escape($this->labelClass) . '">';
            $html .= $this->escape($tab['label']);
            $html .= "</label>\n";
        }

        // Render all tab panels
        foreach ($tabs as $tab) {
            $html .= '<div class="' . $this->escape($this->tabClass) . '">';
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
     * @param array<array{label: string, content: string, selected: bool, id: string|null}> $tabs
     */
    protected function renderAriaTabs(Div $wrapper, array $tabs): string
    {
        $this->tabSetCounter++;
        $setId = $this->idPrefix . '-' . $this->tabSetCounter;

        // Build wrapper attributes with tablist role
        $attrs = $this->buildWrapperAttributes($wrapper, 'tablist');

        $html = '<div' . $attrs . ">\n";

        // Render all tab buttons first
        foreach ($tabs as $index => $tab) {
            $tabNum = $index + 1;
            $tabId = $tab['id'] ? $this->escape($tab['id']) . '-tab' : $setId . '-tab-' . $tabNum;
            $panelId = $tab['id'] ? $this->escape($tab['id']) . '-panel' : $setId . '-panel-' . $tabNum;
            $selected = $tab['selected'] ? 'true' : 'false';
            $tabindex = $tab['selected'] ? '' : ' tabindex="-1"';

            $html .= '<button role="tab" id="' . $tabId . '" ';
            $html .= 'aria-selected="' . $selected . '" ';
            $html .= 'aria-controls="' . $panelId . '" ';
            $html .= 'class="' . $this->escape($this->labelClass) . '"' . $tabindex . '>';
            $html .= $this->escape($tab['label']);
            $html .= "</button>\n";
        }

        // Render all tab panels
        foreach ($tabs as $index => $tab) {
            $tabNum = $index + 1;
            $tabId = $tab['id'] ? $this->escape($tab['id']) . '-tab' : $setId . '-tab-' . $tabNum;
            $panelId = $tab['id'] ? $this->escape($tab['id']) . '-panel' : $setId . '-panel-' . $tabNum;
            $hidden = $tab['selected'] ? '' : ' hidden';

            $html .= '<div role="tabpanel" id="' . $panelId . '" ';
            $html .= 'aria-labelledby="' . $tabId . '" ';
            $html .= 'class="' . $this->escape($this->tabClass) . '"' . $hidden . '>';
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
        $existingClasses = (string)$wrapper->getAttribute('class');
        foreach (preg_split('/\s+/', $existingClasses) ?: [] as $class) {
            $class = trim($class);
            if ($class !== '' && $class !== 'tabs' && !in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        $attrs = ' class="' . $this->escape(implode(' ', $classes)) . '"';

        if ($role !== null) {
            $attrs .= ' role="' . $role . '"';
        }

        // Copy other attributes (except class)
        foreach ($wrapper->getAttributes() as $name => $value) {
            if ($name === 'class') {
                continue;
            }
            $attrs .= ' ' . $this->escape($name) . '="' . $this->escape((string)$value) . '"';
        }

        return $attrs;
    }

    /**
     * Check if a node has a specific class
     */
    protected function hasClass(Node $node, string $className): bool
    {
        $classAttr = (string)$node->getAttribute('class');
        $classes = preg_split('/\s+/', trim($classAttr));

        return is_array($classes) && in_array($className, $classes, true);
    }

    /**
     * Escape HTML special characters
     */
    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
