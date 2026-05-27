<?php

declare(strict_types=1);

namespace Djot\Extension;

use Closure;
use Djot\DjotConverter;
use Djot\Node\Document;
use Djot\Node\Inline\Span;
use Djot\Node\Inline\Text;
use Djot\Node\Node;
use Djot\Parser\InlineParser;
use JsonException;

/**
 * Pandoc/Citum-style citations for Djot.
 *
 * Supported forms:
 * - [@key]
 * - [+@key]
 * - [-@key]
 * - [@a; @b]
 * - [@key, p. 10]
 *
 * This extension only parses and semantically marks citation groups. Proper
 * bibliography processing remains the responsibility of an external engine.
 */
class CitationsExtension implements ExtensionInterface, BeforeRenderExtensionInterface
{
    protected ?InlineParser $inlineParser = null;

    protected int $nextCitationId = 1;

    /**
     * @var \Closure(list<\Djot\Extension\CitationGroup>, \Djot\Node\Document): array<string, string>|null
     */
    protected ?Closure $resolver;

    /**
     * @param callable(list<\Djot\Extension\CitationGroup>, \Djot\Node\Document): array<string, string>|null $resolver
     * @param string $cssClass CSS classes added to parsed citation spans
     */
    public function __construct(
        Closure|callable|null $resolver = null,
        protected string $cssClass = 'citation',
    ) {
        $this->resolver = $resolver !== null ? Closure::fromCallable($resolver) : null;
    }

    public function register(DjotConverter $converter): void
    {
        $this->inlineParser = $converter->getParser()->getInlineParser();

        $this->inlineParser->addInlinePattern(
            '/\[((?:\s*[-+]?\@)[^\]]*)\](?![\(\[])/',
            function (string $match, array $_groups, InlineParser $_parser): ?Span {
                return $this->createCitationSpan($match);
            },
        );
    }

    public function beforeRender(Document $document): Document
    {
        if ($this->resolver === null) {
            return $document;
        }
        $resolver = $this->resolver;

        $spans = [];
        $groups = [];
        $this->collectCitationSpans($document, $spans, $groups);
        if ($groups === []) {
            return $document;
        }

        $resolvedById = $resolver(array_values($groups), $document);
        foreach ($spans as $span) {
            $id = $span->getAttribute('data-citation-id');
            if ($id === null || !isset($resolvedById[$id])) {
                continue;
            }

            $this->replaceSpanContents($span, $resolvedById[$id]);
            $span->setAttribute('data-citation-rendered', 'resolved');
        }

        return $document;
    }

    protected function createCitationSpan(string $source): ?Span
    {
        $group = $this->parseCitationGroup($source);
        if ($group === null) {
            return null;
        }

        $span = new Span();
        foreach (preg_split('/\s+/', trim($this->cssClass)) ?: [] as $class) {
            if ($class !== '') {
                $span->addClass($class);
            }
        }
        $span->addClass(count($group->references) > 1 ? 'citation-multiple' : 'citation-single');
        $span->setAttribute('data-citation-id', $group->id);
        $span->setAttribute('data-citation-source', $group->source);
        $span->setAttribute('data-citation-keys', implode(';', array_map(
            static fn (CitationReference $reference): string => $reference->key,
            $group->references,
        )));
        $span->setAttribute('data-citation-items', $this->encodeCitationGroup($group));
        $span->appendChild(new Text($group->source));

        return $span;
    }

    protected function parseCitationGroup(string $source): ?CitationGroup
    {
        if (!preg_match('/^\[(.*)\]$/s', $source, $matches)) {
            return null;
        }

        $inner = trim($matches[1]);
        if ($inner === '') {
            return null;
        }

        $parts = preg_split('/\s*;\s*/', $inner) ?: [];
        $references = [];

        foreach ($parts as $part) {
            if ($part === '') {
                return null;
            }

            if (!preg_match('/^([+-]?)[ \t]*@([^,\s;\]]+)(?:\s*,\s*(.+))?$/s', $part, $itemMatch)) {
                return null;
            }

            $mode = match ($itemMatch[1]) {
                '+' => CitationReference::MODE_INTEGRAL,
                '-' => CitationReference::MODE_SUPPRESS_AUTHOR,
                default => CitationReference::MODE_PARENTHESES,
            };

            $references[] = new CitationReference(
                $itemMatch[2],
                $mode,
                isset($itemMatch[3]) ? trim($itemMatch[3]) : null,
            );
        }

        if ($references === []) {
            return null;
        }

        return new CitationGroup(
            'citation-' . $this->nextCitationId++,
            $source,
            $references,
        );
    }

    /**
     * @param \Djot\Node\Node $node
     * @param array<int, \Djot\Node\Inline\Span> $spans
     * @param array<string, \Djot\Extension\CitationGroup> $groups
     */
    protected function collectCitationSpans(Node $node, array &$spans, array &$groups): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Span && $child->hasAttribute('data-citation-items')) {
                $spans[] = $child;
                $group = $this->decodeCitationGroup($child->getAttribute('data-citation-items'));
                if ($group !== null) {
                    $groups[$group->id] = $group;
                }
            }

            $this->collectCitationSpans($child, $spans, $groups);
        }
    }

    protected function replaceSpanContents(Span $span, string $rendered): void
    {
        while ($span->hasChildren()) {
            $span->removeChildAt(0);
        }

        if ($this->inlineParser === null) {
            $span->appendChild(new Text($rendered));

            return;
        }

        $this->inlineParser->parse($span, $rendered);
    }

    protected function encodeCitationGroup(CitationGroup $group): string
    {
        try {
            return json_encode($group->toArray(), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '';
        }
    }

    protected function decodeCitationGroup(?string $json): ?CitationGroup
    {
        if ($json === null || $json === '') {
            return null;
        }

        try {
            /** @var array{id: string, source: string, references: list<array{key: string, mode?: string, suffix?: string|null}>} $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return CitationGroup::fromArray($data);
        } catch (JsonException) {
            return null;
        }
    }
}
