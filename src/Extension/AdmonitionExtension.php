<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Div;

/**
 * Transforms divs with admonition type classes into semantic admonition markup
 *
 * This extension converts standard djot divs (`::: note`, `::: warning`, etc.) into
 * semantic admonition HTML with proper accessibility attributes.
 *
 * Example:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new AdmonitionExtension());
 *
 * // Or with custom settings:
 * $converter->addExtension(new AdmonitionExtension(
 *     types: ['note', 'tip', 'warning', 'danger', 'info', 'success', 'caution'],
 *     defaultTitle: true,
 *     titleTag: 'p',
 *     titleClass: 'admonition-title',
 * ));
 * ```
 *
 * Input djot:
 * ```
 * ::: note
 * This is a note.
 * :::
 *
 * {title="Watch Out!"}
 * ::: warning
 * Be careful here.
 * :::
 *
 * {collapsible}
 * ::: tip
 * Click to expand this tip.
 * :::
 *
 * {collapsible=open}
 * ::: danger
 * This is expanded by default.
 * :::
 * ```
 *
 * Output HTML:
 * ```html
 * <div class="admonition note" role="note">
 *   <p class="admonition-title">Note</p>
 *   <p>This is a note.</p>
 * </div>
 *
 * <div class="admonition warning" role="alert">
 *   <p class="admonition-title">Watch Out!</p>
 *   <p>Be careful here.</p>
 * </div>
 *
 * <details class="admonition tip">
 *   <summary>Tip</summary>
 *   <p>Click to expand this tip.</p>
 * </details>
 *
 * <details class="admonition danger" open>
 *   <summary>Danger</summary>
 *   <p>This is expanded by default.</p>
 * </details>
 * ```
 */
class AdmonitionExtension implements ExtensionInterface
{
    /**
     * Default admonition types
     *
     * @var array<string>
     */
    public const DEFAULT_TYPES = ['note', 'tip', 'warning', 'danger', 'info', 'success', 'caution'];

    /**
     * Types that should use role="alert" for screen readers
     *
     * @var array<string>
     */
    protected const ALERT_TYPES = ['warning', 'danger', 'caution'];

    /**
     * @param array<string> $types Admonition types to recognize
     * @param bool $defaultTitle Whether to auto-generate title from type when not specified
     * @param string $titleTag HTML tag for the title element
     * @param string $titleClass CSS class for the title element
     * @param string $containerClass Base CSS class for the container
     */
    public function __construct(
        protected array $types = self::DEFAULT_TYPES,
        protected bool $defaultTitle = true,
        protected string $titleTag = 'p',
        protected string $titleClass = 'admonition-title',
        protected string $containerClass = 'admonition',
    ) {
    }

    public function register(DjotConverter $converter): void
    {
        $converter->on('render.div', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof Div) {
                return;
            }

            $admonitionType = $this->getAdmonitionType($node);
            if ($admonitionType === null) {
                return;
            }

            $html = $this->renderAdmonition($node, $admonitionType, $event->getChildrenHtml());
            $event->setHtml($html);
        });
    }

    /**
     * Check if a div has an admonition type class
     */
    protected function getAdmonitionType(Div $node): ?string
    {
        $classAttr = (string)$node->getAttribute('class');
        $classes = preg_split('/\s+/', trim($classAttr));

        if (!is_array($classes)) {
            return null;
        }

        foreach ($classes as $class) {
            if (in_array($class, $this->types, true)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Render the admonition HTML
     */
    protected function renderAdmonition(Div $node, string $type, string $childrenHtml): string
    {
        $isCollapsible = $node->hasAttribute('collapsible');
        $isOpen = $node->getAttribute('collapsible') === 'open';
        $customTitle = $node->getAttribute('title');
        $title = $customTitle !== null ? (string)$customTitle : ($this->defaultTitle ? ucfirst($type) : null);

        // Build class list
        $classes = [$this->containerClass, $type];
        $existingClasses = (string)$node->getAttribute('class');
        foreach (preg_split('/\s+/', $existingClasses) ?: [] as $class) {
            $class = trim($class);
            if ($class !== '' && !in_array($class, $classes, true) && !in_array($class, $this->types, true)) {
                $classes[] = $class;
            }
        }
        $classAttr = implode(' ', $classes);

        // Build additional attributes (excluding class, title, collapsible)
        $extraAttrs = $this->buildExtraAttributes($node);

        if ($isCollapsible) {
            return $this->renderCollapsible($classAttr, $extraAttrs, $title, $childrenHtml, $isOpen);
        }

        return $this->renderStatic($type, $classAttr, $extraAttrs, $title, $childrenHtml);
    }

    /**
     * Render a static (non-collapsible) admonition
     */
    protected function renderStatic(string $type, string $classAttr, string $extraAttrs, ?string $title, string $childrenHtml): string
    {
        $role = in_array($type, self::ALERT_TYPES, true) ? 'alert' : 'note';
        $html = '<div class="' . $this->escape($classAttr) . '" role="' . $role . '"' . $extraAttrs . ">\n";

        if ($title !== null) {
            $html .= '<' . $this->titleTag . ' class="' . $this->escape($this->titleClass) . '">';
            $html .= $this->escape($title);
            $html .= '</' . $this->titleTag . ">\n";
        }

        $html .= $childrenHtml;
        $html .= "</div>\n";

        return $html;
    }

    /**
     * Render a collapsible admonition using details/summary
     */
    protected function renderCollapsible(string $classAttr, string $extraAttrs, ?string $title, string $childrenHtml, bool $isOpen): string
    {
        $openAttr = $isOpen ? ' open' : '';
        $html = '<details class="' . $this->escape($classAttr) . '"' . $openAttr . $extraAttrs . ">\n";

        if ($title !== null) {
            $html .= '<summary>' . $this->escape($title) . "</summary>\n";
        }

        $html .= $childrenHtml;
        $html .= "</details>\n";

        return $html;
    }

    /**
     * Build extra attributes string, excluding processed ones
     */
    protected function buildExtraAttributes(Div $node): string
    {
        $excluded = ['class', 'title', 'collapsible'];
        $attrs = '';

        foreach ($node->getAttributes() as $name => $value) {
            if (in_array($name, $excluded, true)) {
                continue;
            }
            $attrs .= ' ' . $this->escape($name) . '="' . $this->escape((string)$value) . '"';
        }

        return $attrs;
    }

    /**
     * Escape HTML special characters
     */
    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
