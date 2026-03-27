<?php

declare(strict_types=1);

namespace Djot\Extension;

use Closure;
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\CodeBlock;
use Djot\Node\Block\Div;
use Djot\Renderer\HtmlRenderer;

/**
 * Transforms code-group divs into tabbed code block interfaces
 *
 * This extension converts a div with class `code-group` containing multiple
 * code blocks into a tabbed interface, ideal for showing code examples in
 * multiple languages or variations.
 *
 * Labels are extracted from the language hint using `[Label]` suffix syntax,
 * falling back to the language name or "Code N".
 *
 * Example:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new CodeGroupExtension());
 *
 * // With custom syntax highlighter:
 * $converter->addExtension(new CodeGroupExtension(
 *     highlighter: fn(string $code, ?string $lang) => $highlighter->highlight($code, $lang),
 * ));
 * ```
 *
 * Input djot:
 * ```
 * ::: code-group
 * ``` php [Installation]
 * composer require php-collective/djot
 * ```
 *
 * ``` bash [NPM]
 * npm install @example/djot
 * ```
 * :::
 * ```
 *
 * Output HTML:
 * ```html
 * <div class="code-group">
 *   <input type="radio" name="codegroup-1" id="codegroup-1-tab-1" class="code-group-radio" checked>
 *   <label for="codegroup-1-tab-1" class="code-group-label">Installation</label>
 *   <input type="radio" name="codegroup-1" id="codegroup-1-tab-2" class="code-group-radio">
 *   <label for="codegroup-1-tab-2" class="code-group-label">NPM</label>
 *   <div class="code-group-panel">
 *     <pre><code class="language-php">composer require php-collective/djot</code></pre>
 *   </div>
 *   <div class="code-group-panel">
 *     <pre><code class="language-bash">npm install @example/djot</code></pre>
 *   </div>
 * </div>
 * ```
 *
 * ## Comparison with TabsExtension
 *
 * Use **CodeGroupExtension** when:
 * - You have multiple code blocks to display as tabs
 * - Labels come from language hints (`php [Label]`)
 * - You want syntax highlighting integration
 *
 * Use **TabsExtension** when:
 * - You have arbitrary content (not just code)
 * - Labels come from headings or attributes
 * - You need ARIA mode with full keyboard navigation
 */
class CodeGroupExtension implements ExtensionInterface
{
    /**
     * Counter for generating unique group IDs
     */
    protected int $groupCounter = 0;

    /**
     * @param string $wrapperClass CSS class for the code-group container
     * @param string $panelClass CSS class for individual code panels
     * @param string $labelClass CSS class for tab labels
     * @param string $radioClass CSS class for radio inputs
     * @param string $idPrefix Prefix for generated IDs
     * @param \Closure|null $highlighter Optional syntax highlighter callback: fn(string $code, ?string $lang): string
     */
    public function __construct(
        protected string $wrapperClass = 'code-group',
        protected string $panelClass = 'code-group-panel',
        protected string $labelClass = 'code-group-label',
        protected string $radioClass = 'code-group-radio',
        protected string $idPrefix = 'codegroup',
        protected ?Closure $highlighter = null,
    ) {
    }

    public function register(DjotConverter $converter): void
    {
        $converter->on('render.div', function (RenderEvent $event) use ($converter): void {
            $node = $event->getNode();
            if (!$node instanceof Div) {
                return;
            }

            if (!$this->hasClass($node, 'code-group')) {
                return;
            }

            $codeBlocks = $this->extractCodeBlocks($node);
            if ($codeBlocks === []) {
                return;
            }

            $html = $this->renderCodeGroup($node, $codeBlocks, $converter->getRenderer());
            $event->setHtml($html);
        });
    }

    /**
     * Extract code blocks from the div
     *
     * @return array<array{block: \Djot\Node\Block\CodeBlock, language: string|null, label: string, selected: bool}>
     */
    protected function extractCodeBlocks(Div $node): array
    {
        $blocks = [];
        $position = 0;

        foreach ($node->getChildren() as $child) {
            if (!$child instanceof CodeBlock) {
                continue;
            }

            $position++;
            $metadata = $this->parseLanguageMetadata($child->getLanguage(), $position);

            // Check for selected attribute on preceding paragraph (djot attribute syntax)
            $selected = $child->hasAttribute('selected');

            $blocks[] = [
                'block' => $child,
                'language' => $metadata['language'],
                'label' => $metadata['label'],
                'selected' => $selected,
            ];
        }

        // If no block is explicitly selected, select the first one
        if ($blocks !== [] && !array_filter($blocks, fn ($b) => $b['selected'])) {
            $blocks[0]['selected'] = true;
        }

        return $blocks;
    }

    /**
     * Parse language hint with optional [Label] suffix
     *
     * Supports formats:
     * - "php" -> language: php, label: php
     * - "php [Installation]" -> language: php, label: Installation
     * - "[Custom Label]" -> language: null, label: Custom Label
     * - "" -> language: null, label: Code N
     *
     * @return array{language: string|null, label: string}
     */
    protected function parseLanguageMetadata(?string $language, int $position): array
    {
        $raw = trim((string)$language);
        if ($raw === '') {
            return ['language' => null, 'label' => 'Code ' . $position];
        }

        // Match the full hint: optional language token, optional [label]
        if (preg_match('/^(?:(?<lang>[^\s\[]+)\s*)?(?:\[(?<label>[^\]]+)])?$/', $raw, $matches) !== 1) {
            return ['language' => $raw, 'label' => $raw];
        }

        $matchedLanguage = $matches['lang'] ?? null;
        $matchedLabel = $matches['label'] ?? null;

        $resolvedLanguage = $matchedLanguage !== null && $matchedLanguage !== '' ? $matchedLanguage : null;
        $resolvedLabel = $matchedLabel !== null && $matchedLabel !== '' ? trim($matchedLabel) : null;

        // Fallback label to language name or position
        if ($resolvedLabel === null) {
            $resolvedLabel = $resolvedLanguage ?? 'Code ' . $position;
        }

        return ['language' => $resolvedLanguage, 'label' => $resolvedLabel];
    }

    /**
     * Render the code group as tabbed interface
     *
     * @param \Djot\Node\Block\Div $wrapper
     * @param array<array{block: \Djot\Node\Block\CodeBlock, language: string|null, label: string, selected: bool}> $codeBlocks
     * @param \Djot\Renderer\HtmlRenderer $renderer
     */
    protected function renderCodeGroup(Div $wrapper, array $codeBlocks, HtmlRenderer $renderer): string
    {
        $this->groupCounter++;
        $groupId = $this->idPrefix . '-' . $this->groupCounter;

        // Build wrapper attributes
        $attrs = $this->buildWrapperAttributes($wrapper);

        $html = '<div' . $attrs . ">\n";

        // Render all radio inputs and labels first
        foreach ($codeBlocks as $index => $item) {
            $tabNum = $index + 1;
            $inputId = $groupId . '-tab-' . $tabNum;
            $checked = $item['selected'] ? ' checked' : '';

            $html .= '<input type="radio" name="' . $this->escape($groupId) . '" ';
            $html .= 'id="' . $this->escape($inputId) . '" ';
            $html .= 'class="' . $this->escape($this->radioClass) . '"' . $checked . ">\n";

            $html .= '<label for="' . $this->escape($inputId) . '" ';
            $html .= 'class="' . $this->escape($this->labelClass) . '">';
            $html .= $this->escape($item['label']);
            $html .= "</label>\n";
        }

        // Render all code panels
        foreach ($codeBlocks as $item) {
            $html .= '<div class="' . $this->escape($this->panelClass) . '">';
            $html .= $this->renderCodeBlock($item['block'], $item['language'], $renderer);
            $html .= "</div>\n";
        }

        $html .= "</div>\n";

        return $html;
    }

    /**
     * Render a single code block, using highlighter if available
     */
    protected function renderCodeBlock(CodeBlock $block, ?string $language, HtmlRenderer $renderer): string
    {
        $code = rtrim($block->getContent(), "\n");

        // Use custom highlighter if provided
        if ($this->highlighter !== null) {
            return ($this->highlighter)($code, $language);
        }

        $renderBlock = new CodeBlock($block->getContent(), $language);

        foreach ($block->getAttributes() as $name => $value) {
            if ($name === 'selected') {
                continue;
            }

            $renderBlock->setAttribute($name, $value);
        }

        return $renderer->renderNodeFragment($renderBlock);
    }

    /**
     * Build wrapper div attributes
     */
    protected function buildWrapperAttributes(Div $wrapper): string
    {
        $classes = [$this->wrapperClass];

        // Add any additional classes from the original div (except 'code-group')
        $existingClasses = (string)$wrapper->getAttribute('class');
        foreach (preg_split('/\s+/', $existingClasses) ?: [] as $class) {
            $class = trim($class);
            if ($class !== '' && $class !== 'code-group' && !in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        $attrs = ' class="' . $this->escape(implode(' ', $classes)) . '"';

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
    protected function hasClass(Div $node, string $className): bool
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
