<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\CodeBlock;
use Djot\Renderer\HtmlRenderer;
use Djot\Util\StringUtil;

/**
 * Transforms code blocks with language "mermaid" into Mermaid.js-compatible markup
 *
 * This extension converts fenced code blocks with the `mermaid` language identifier
 * into HTML that Mermaid.js can render as diagrams.
 *
 * Example:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new MermaidExtension());
 *
 * // Or with custom settings:
 * $converter->addExtension(new MermaidExtension(
 *     tag: 'pre',
 *     cssClass: 'mermaid',
 *     wrapInFigure: false,
 * ));
 * ```
 *
 * Input djot:
 * ```
 * ``` mermaid
 * graph TD;
 *     A-->B;
 *     A-->C;
 *     B-->D;
 *     C-->D;
 * ```
 * ```
 *
 * Output HTML (default):
 * ```html
 * <pre class="mermaid">graph TD;
 *     A-->B;
 *     A-->C;
 *     B-->D;
 *     C-->D;
 * </pre>
 * ```
 *
 * Output HTML (with wrapInFigure: true):
 * ```html
 * <figure class="mermaid-figure">
 *   <pre class="mermaid">graph TD;
 *       A-->B;
 *   </pre>
 * </figure>
 * ```
 *
 * ## Required JavaScript
 *
 * Include Mermaid.js in your page:
 *
 * ```html
 * <script type="module">
 *   import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';
 *   mermaid.initialize({ startOnLoad: true });
 * </script>
 * ```
 *
 * Or via npm:
 *
 * ```javascript
 * import mermaid from 'mermaid';
 * mermaid.initialize({ startOnLoad: true });
 * ```
 *
 * ## Supported Diagram Types
 *
 * Mermaid supports many diagram types including:
 * - Flowcharts (`graph TD`, `graph LR`)
 * - Sequence diagrams (`sequenceDiagram`)
 * - Class diagrams (`classDiagram`)
 * - State diagrams (`stateDiagram-v2`)
 * - Entity Relationship diagrams (`erDiagram`)
 * - Gantt charts (`gantt`)
 * - Pie charts (`pie`)
 * - Git graphs (`gitGraph`)
 * - And more...
 *
 * See https://mermaid.js.org/ for full documentation.
 */
class MermaidExtension implements ExtensionInterface
{
    protected bool $roundTripMode = false;

    /**
     * @param string $tag HTML tag to use ('pre' or 'div')
     * @param string $cssClass CSS class for Mermaid.js to detect
     * @param bool $wrapInFigure Whether to wrap in a figure element
     * @param string $figureClass CSS class for the figure element
     */
    public function __construct(
        protected string $tag = 'pre',
        protected string $cssClass = 'mermaid',
        protected bool $wrapInFigure = false,
        protected string $figureClass = 'mermaid-figure',
    ) {
    }

    public function register(DjotConverter $converter): void
    {
        // Check for round-trip mode from HTML renderer
        $renderer = $converter->getRenderer();
        if ($renderer instanceof HtmlRenderer) {
            $this->roundTripMode = $renderer->isRoundTripMode();
        }

        $converter->on('render.code_block', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof CodeBlock) {
                return;
            }

            if ($node->getLanguage() !== 'mermaid') {
                return;
            }

            $html = $this->renderMermaid($node);
            $event->setHtml($html);
        });
    }

    /**
     * Render the mermaid diagram markup
     */
    protected function renderMermaid(CodeBlock $node): string
    {
        $content = $node->getContent();

        // Build CSS classes
        $classes = [$this->cssClass];
        foreach ($node->getClassList() as $class) {
            if (!in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }
        $classAttr = implode(' ', $classes);

        // Build additional attributes (excluding class and language-related)
        $extraAttrs = $this->buildExtraAttributes($node);

        // Add data-djot-src for round-trip support
        if ($this->roundTripMode) {
            $djotSrc = $this->reconstructCodeBlockSource($node);
            $extraAttrs .= ' data-djot-src="' . StringUtil::escapeHtml($djotSrc) . '"';
        }

        // Build the main element
        // Mermaid content needs special escaping:
        // - Escape < and & to prevent XSS (e.g., <script> becomes &lt;script>)
        // - Preserve > for Mermaid arrow syntax (e.g., -->)
        $escapedContent = str_replace(['&', '<'], ['&amp;', '&lt;'], $content);
        $element = '<' . $this->tag . ' class="' . StringUtil::escapeHtml($classAttr) . '"' . $extraAttrs . '>';
        $element .= $escapedContent;
        $element .= '</' . $this->tag . ">\n";

        if ($this->wrapInFigure) {
            $html = '<figure class="' . StringUtil::escapeHtml($this->figureClass) . "\">\n";
            $html .= $element;
            $html .= "</figure>\n";

            return $html;
        }

        return $element;
    }

    /**
     * Reconstruct the original Djot source for a mermaid code block
     */
    protected function reconstructCodeBlockSource(CodeBlock $node): string
    {
        $content = $node->getContent();

        // Choose a fence that does not conflict with the content
        $fence = StringUtil::findSafeCodeFence($content, 3);

        // Build the code fence
        $djot = $this->renderDjotAttributeBlock($node);
        $djot .= $fence . ' mermaid' . "\n";
        $djot .= $content;
        if (!str_ends_with($content, "\n")) {
            $djot .= "\n";
        }
        $djot .= $fence . "\n";

        return $djot;
    }

    /**
     * @param \Djot\Node\Block\CodeBlock $node
     * @param array<string> $skipAttrs
     * @param array<string> $skipClasses
     */
    protected function renderDjotAttributeBlock(CodeBlock $node, array $skipAttrs = [], array $skipClasses = []): string
    {
        $parts = [];

        $id = $node->getAttribute('id');
        if ($id !== null && $id !== '' && !in_array('id', $skipAttrs, true)) {
            $parts[] = '#' . $id;
        }

        if (!in_array('class', $skipAttrs, true)) {
            foreach ($node->getClassList() as $class) {
                if (!in_array($class, $skipClasses, true)) {
                    $parts[] = '.' . $class;
                }
            }
        }

        foreach ($node->getAttributes() as $name => $value) {
            if ($name === 'id' || $name === 'class' || in_array($name, $skipAttrs, true)) {
                continue;
            }

            $parts[] = $value === ''
                ? $name
                : $name . '=' . $this->quoteDjotAttributeValue($value);
        }

        if ($parts === []) {
            return '';
        }

        return '{' . implode(' ', $parts) . "}\n";
    }

    protected function quoteDjotAttributeValue(string $value): string
    {
        if ($value !== '' && preg_match('/^[A-Za-z0-9._:-]+$/', $value) === 1) {
            return $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * Build extra attributes string, excluding processed ones
     */
    protected function buildExtraAttributes(CodeBlock $node): string
    {
        $excluded = ['class'];
        $attrs = '';

        foreach ($node->getAttributes() as $name => $value) {
            if (in_array($name, $excluded, true)) {
                continue;
            }
            $attrs .= ' ' . StringUtil::escapeHtml($name) . '="' . StringUtil::escapeHtml((string)$value) . '"';
        }

        return $attrs;
    }
}
