<?php

declare(strict_types=1);

namespace Djot\Extension;

use Closure;
use Djot\DjotConverter;
use Djot\Node\Document;
use Djot\Node\Inline\Span;
use Djot\Node\Inline\Text;
use Djot\Node\Inline\Transclusion;
use Djot\Node\Inline\TransclusionArgument;
use Djot\Node\Node;
use Djot\Parser\InlineParser;

/**
 * Experimental transclusion / fragment invocation support for Djot.
 *
 * Parses `{{name|positional|key=value}}` calls into a transclusion node whose
 * arguments contain parsed inline child nodes. Before rendering, a host
 * resolver may expand the invocation into normal Djot AST nodes:
 *
 * ```php
 * $converter->addExtension(new TransclusionExtension(
 *     resolver: function (string $name, array $args): ?\Djot\Node\Node {
 *         return $name === 'example' ? new \Djot\Node\Inline\Span() : null;
 *     },
 * ));
 * ```
 *
 * This is an opt-in proof of concept, not official Djot syntax. It exists to
 * explore template-like fragment calls for https://github.com/jgm/djot/discussions/366.
 *
 * Spike limitation: nested `{{ }}` and literal braces inside arguments are not
 * supported. Raw `|` characters always split arguments.
 */
class TransclusionExtension implements BeforeRenderExtensionInterface
{
    /**
     * @param \Closure(string, array<int, \Djot\Node\Inline\TransclusionArgument>): \Djot\Node\Node|null $resolver
     *     Resolver called with the transclusion name and parsed argument nodes.
     *     Return a replacement AST node, or null to use the unresolved fallback.
     * @param string $unresolvedCssClass CSS class for unresolved fallback spans.
     */
    public function __construct(
        protected ?Closure $resolver = null,
        protected string $unresolvedCssClass = 'transclusion-unresolved',
    ) {
    }

    public function register(DjotConverter $converter): void
    {
        $inlineParser = $converter->getParser()->getInlineParser();

        $inlineParser->addInlinePattern(
            '/\{\{([^{}]+)\}\}/',
            function (string $match, array $groups, InlineParser $parser): Transclusion {
                $segments = explode('|', $groups[1]);
                $name = trim($segments[0]);
                $node = new Transclusion($name);

                foreach (array_slice($segments, 1) as $index => $segment) {
                    $key = null;
                    $value = $segment;

                    if (preg_match('/^\s*([A-Za-z0-9_-]+)\s*=(.*)$/s', $segment, $matches)) {
                        $key = $matches[1];
                        $value = $matches[2];
                    }

                    $arg = new TransclusionArgument($key, $index);
                    $parser->parse($arg, trim($value, " \t"));
                    $node->appendChild($arg);
                }

                return $node;
            },
        );
    }

    public function beforeRender(Document $document): Document
    {
        $this->replaceTransclusions($document);

        return $document;
    }

    protected function replaceTransclusions(Node $node): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Transclusion) {
                $node->replaceChildNode($child, $this->resolveTransclusion($child));

                continue;
            }

            $this->replaceTransclusions($child);
        }
    }

    protected function resolveTransclusion(Transclusion $node): Node
    {
        $args = $this->collectArguments($node);
        $resolved = $this->resolver !== null ? ($this->resolver)($node->getName(), $args) : null;

        if ($resolved instanceof Node) {
            return $resolved;
        }

        return $this->createUnresolvedFallback($node);
    }

    /**
     * @return array<int, \Djot\Node\Inline\TransclusionArgument>
     */
    protected function collectArguments(Transclusion $node): array
    {
        $args = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof TransclusionArgument) {
                $args[] = $child;
            }
        }

        return $args;
    }

    protected function createUnresolvedFallback(Transclusion $node): Span
    {
        $span = new Span();
        foreach (preg_split('/\s+/', trim($this->unresolvedCssClass)) ?: [] as $class) {
            if ($class !== '') {
                $span->addClass($class);
            }
        }
        $span->appendChild(new Text('{{' . $node->getName() . '}}'));

        return $span;
    }
}
