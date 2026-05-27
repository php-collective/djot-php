<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Div;
use Djot\Renderer\HtmlRenderer;
use Djot\Util\StringUtil;

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
 * // With icons enabled (uses default emoji icons):
 * $converter->addExtension(new AdmonitionExtension(icons: true));
 *
 * // With custom icons:
 * $converter->addExtension(new AdmonitionExtension(
 *     icons: ['note' => '📝', 'tip' => '💡', 'warning' => '⚠️'],
 * ));
 *
 * // Or with custom settings:
 * $converter->addExtension(new AdmonitionExtension(
 *     types: ['note', 'tip', 'warning', 'danger', 'info', 'success'],
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
 * Output HTML (with icons: true):
 * ```html
 * <div class="admonition note" role="note">
 *   <p class="admonition-title"><span class="admonition-icon">ℹ️</span> Note</p>
 *   <p>This is a note.</p>
 * </div>
 *
 * <div class="admonition warning" role="alert">
 *   <p class="admonition-title"><span class="admonition-icon">⚠️</span> Watch Out!</p>
 *   <p>Be careful here.</p>
 * </div>
 *
 * <details class="admonition tip">
 *   <summary><span class="admonition-icon">💡</span> Tip</summary>
 *   <p>Click to expand this tip.</p>
 * </details>
 *
 * <details class="admonition danger" open>
 *   <summary><span class="admonition-icon">🚨</span> Danger</summary>
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
    public const DEFAULT_TYPES = ['note', 'tip', 'warning', 'danger', 'info', 'success'];

    /**
     * Default icons for each admonition type (used when icons: true)
     *
     * @var array<string, string>
     */
    public const DEFAULT_ICONS = [
        'note' => '📝',
        'tip' => '💡',
        'warning' => '⚠️',
        'danger' => '🚨',
        'info' => 'ℹ️',
        'success' => '✅',
    ];

    /**
     * Types that should use role="alert" for screen readers
     *
     * @var array<string>
     */
    protected const ALERT_TYPES = ['warning', 'danger'];

    /**
     * Resolved icons map (empty if icons disabled)
     *
     * @var array<string, string>
     */
    protected array $resolvedIcons = [];

    /**
     * @param array<string> $types Admonition types to recognize
     * @param bool $defaultTitle Whether to auto-generate title from type when not specified
     * @param string $titleTag HTML tag for the title element
     * @param string $titleClass CSS class for the title element
     * @param string $containerClass Base CSS class for the container
     * @param array<string, string>|bool $icons Enable icons (true = default icons, array = custom icons, false = disabled)
     * @param string $iconClass CSS class for the icon wrapper span
     */
    public function __construct(
        protected array $types = self::DEFAULT_TYPES,
        protected bool $defaultTitle = true,
        protected string $titleTag = 'p',
        protected string $titleClass = 'admonition-title',
        protected string $containerClass = 'admonition',
        array|bool $icons = false,
        protected string $iconClass = 'admonition-icon',
    ) {
        $this->resolvedIcons = $this->resolveIcons($icons);
    }

    /**
     * Resolve the icons configuration to a map
     *
     * @param array<string, string>|bool $icons
     *
     * @return array<string, string>
     */
    protected function resolveIcons(array|bool $icons): array
    {
        if ($icons === false) {
            return [];
        }

        if ($icons === true) {
            return self::DEFAULT_ICONS;
        }

        return $icons;
    }

    /**
     * Get icon for a specific admonition type
     */
    protected function getIcon(string $type): ?string
    {
        return $this->resolvedIcons[$type] ?? null;
    }

    public function register(DjotConverter $converter): void
    {
        $renderer = $converter->getRenderer();
        if (!$renderer instanceof HtmlRenderer) {
            return;
        }

        $converter->on('render.div', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof Div) {
                return;
            }

            $admonitionType = $this->getAdmonitionType($node);
            if ($admonitionType === null) {
                return;
            }

            $html = $this->renderAdmonition($node, $admonitionType, $event->getChildrenHtml(), $renderer);
            $event->setHtml($html);
        });
    }

    /**
     * Check if a div has an admonition type class
     */
    protected function getAdmonitionType(Div $node): ?string
    {
        foreach ($node->getClassList() as $class) {
            if (in_array($class, $this->types, true)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Render the admonition HTML
     */
    protected function renderAdmonition(Div $node, string $type, string $childrenHtml, HtmlRenderer $renderer): string
    {
        $isCollapsible = $node->hasAttribute('collapsible');
        $isOpen = $node->getAttribute('collapsible') === 'open';
        $customTitle = $node->getAttribute('title');
        $title = $customTitle !== null ? (string)$customTitle : ($this->defaultTitle ? ucfirst($type) : null);

        // Build class list
        $classes = [$this->containerClass, $type];
        foreach ($node->getClassList() as $class) {
            if (!in_array($class, $classes, true) && !in_array($class, $this->types, true)) {
                $classes[] = $class;
            }
        }
        $classAttr = implode(' ', $classes);

        // Build additional attributes (excluding class, title, collapsible)
        $extraAttrs = $this->buildExtraAttributes($node);

        // Add round-trip data attributes
        if ($renderer->isRoundTripMode()) {
            $extraAttrs .= ' data-djot-admonition-type="' . StringUtil::escapeHtml($type) . '"';
            // Store custom title if provided (null means auto-generated)
            if ($customTitle !== null) {
                $extraAttrs .= ' data-djot-admonition-title="' . StringUtil::escapeHtml($customTitle) . '"';
            }
        }

        $icon = $this->getIcon($type);

        if ($isCollapsible) {
            return $this->renderCollapsible($classAttr, $extraAttrs, $title, $childrenHtml, $isOpen, $icon);
        }

        return $this->renderStatic($type, $classAttr, $extraAttrs, $title, $childrenHtml, $icon);
    }

    /**
     * Render the title content with optional icon
     */
    protected function renderTitleContent(?string $title, ?string $icon): string
    {
        if ($title === null) {
            return '';
        }

        $content = '';
        if ($icon !== null) {
            $content .= '<span class="' . StringUtil::escapeHtml($this->iconClass) . '">' . $icon . '</span> ';
        }
        $content .= StringUtil::escapeHtml($title);

        return $content;
    }

    /**
     * Render a static (non-collapsible) admonition
     */
    protected function renderStatic(string $type, string $classAttr, string $extraAttrs, ?string $title, string $childrenHtml, ?string $icon): string
    {
        $role = in_array($type, self::ALERT_TYPES, true) ? 'alert' : 'note';
        $html = '<div class="' . StringUtil::escapeHtml($classAttr) . '" role="' . $role . '"' . $extraAttrs . ">\n";

        if ($title !== null) {
            $html .= '<' . $this->titleTag . ' class="' . StringUtil::escapeHtml($this->titleClass) . '">';
            $html .= $this->renderTitleContent($title, $icon);
            $html .= '</' . $this->titleTag . ">\n";
        }

        $html .= $childrenHtml;
        $html .= "</div>\n";

        return $html;
    }

    /**
     * Render a collapsible admonition using details/summary
     */
    protected function renderCollapsible(string $classAttr, string $extraAttrs, ?string $title, string $childrenHtml, bool $isOpen, ?string $icon): string
    {
        $openAttr = $isOpen ? ' open' : '';
        $html = '<details class="' . StringUtil::escapeHtml($classAttr) . '"' . $openAttr . $extraAttrs . ">\n";

        if ($title !== null) {
            $html .= '<summary>' . $this->renderTitleContent($title, $icon) . "</summary>\n";
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
            $attrs .= ' ' . StringUtil::escapeHtml($name) . '="' . StringUtil::escapeHtml((string)$value) . '"';
        }

        return $attrs;
    }
}
