<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Node\Block\Caption;
use Djot\Node\Block\Div;
use Djot\Node\Block\Figure;
use Djot\Node\Block\Paragraph;
use Djot\Node\Block\Table;
use Djot\Node\Document;
use Djot\Node\Inline\Text;
use Djot\Node\Node;

/**
 * Transforms figure divs into composite figures with ordered panels
 *
 * This opt-in extension converts a div with class `figure` into one figure.
 * Captioned figures become panels, while tables are wrapped as panels and all
 * other content remains in source order.
 *
 * Example:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new FigureGroupExtension());
 *
 * // With custom classes:
 * $converter->addExtension(new FigureGroupExtension(
 *     triggerClass: 'composite',
 *     groupClass: 'composite-figure',
 *     panelsClass: 'composite-panels',
 *     panelClass: 'composite-panel',
 * ));
 * ```
 *
 * Input djot:
 * ```
 * ::: figure
 * ![First](first.png)
 * ^ First panel
 *
 * ![Second](second.png)
 * ^ Second panel
 * :::
 * ^ Group caption
 * ```
 */
class FigureGroupExtension implements BeforeRenderExtensionInterface
{
    /**
     * @param string $triggerClass CSS class identifying figure-group divs
     * @param string $groupClass CSS class for the composite figure
     * @param string $panelsClass CSS class for the panels container
     * @param string $panelClass CSS class for each promoted panel
     */
    public function __construct(
        protected string $triggerClass = 'figure',
        protected string $groupClass = 'figure-group',
        protected string $panelsClass = 'figure-panels',
        protected string $panelClass = 'figure-panel',
    ) {
    }

    public function register(DjotConverter $converter): void
    {
        // Work happens in beforeRender, which applies to every renderer.
    }

    public function beforeRender(Document $document): Document
    {
        $this->transformChildren($document);

        return $document;
    }

    protected function transformChildren(Node $container): void
    {
        $childCount = count($container->getChildren());
        for ($index = 0; $index < $childCount; $index++) {
            $children = $container->getChildren();
            $child = $children[$index];

            if ($child instanceof Div && $child->hasClass($this->triggerClass)) {
                $group = $this->createGroup($child);
                $next = $children[$index + 1] ?? null;

                if ($next instanceof Paragraph && $this->isGroupCaption($next)) {
                    $group->appendChild($this->createCaption($next));
                    $container->removeChildAt($index + 1);
                    $childCount--;
                }

                $container->replaceChild($index, $group);

                continue;
            }

            if ($child->hasChildren()) {
                $this->transformChildren($child);
            }
        }
    }

    protected function createGroup(Div $div): Figure
    {
        $group = new Figure();
        $group->setAttributes($div->getAttributes());

        $classes = [$this->groupClass];
        foreach ($div->getClassList() as $class) {
            if ($class !== $this->triggerClass && !in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }
        $group->setAttribute('class', implode(' ', $classes));

        $panels = new Div();
        $panels->setAttribute('class', $this->panelsClass);

        foreach ($div->getChildren() as $child) {
            if ($child instanceof Figure) {
                $child->addClass($this->panelClass);
                $panels->appendChild($child);

                continue;
            }

            if ($child instanceof Table) {
                $panel = new Figure();
                $panel->setAttribute('class', $this->panelClass);
                $panel->appendChild($child);
                $panels->appendChild($panel);

                continue;
            }

            $panels->appendChild($child);
        }

        $group->appendChild($panels);

        return $group;
    }

    protected function isGroupCaption(Paragraph $paragraph): bool
    {
        $first = $paragraph->getChildren()[0] ?? null;
        if (!$first instanceof Text) {
            return false;
        }

        $content = $first->getContent();

        return $content === '^' || str_starts_with($content, '^ ');
    }

    protected function createCaption(Paragraph $paragraph): Caption
    {
        $children = $paragraph->getChildren();
        /** @var \Djot\Node\Inline\Text $first */
        $first = $children[0];
        $content = $first->getContent();
        $first->setContent($content === '^' ? '' : substr($content, 2));

        $caption = new Caption();
        foreach ($children as $index => $child) {
            if ($index === 0 && $first->getContent() === '') {
                continue;
            }
            $caption->appendChild($child);
        }

        return $caption;
    }
}
