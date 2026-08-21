<?php

declare(strict_types=1);

namespace Djot\Performance;

use Djot\Renderer\HeadingIdTracker;
use Djot\Util\StringUtil;

/**
 * Conservative, allocation-light HTML facade for an unambiguous core subset.
 *
 * It is deliberately whole-document: an unsupported boundary returns null
 * before any output is published, leaving the authoritative AST pipeline in
 * charge. Text is borrowed as string slices and no public node is constructed.
 */
final class BorrowedHtmlLayout
{
    private bool $observing = false;

    /**
     * @var array{
     *   headingNumbers: array{minLevel: int}|null,
     *   headingPermalinks: array{symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>, showOnHover: bool, copyToClipboard: bool}|null,
     *   externalLinks: array{internalHosts: array<string>, target: string, rel: string, nofollow: bool}|null,
     *   lowercaseIds: bool,
     *   mathBlockLanguage: string|null,
     *   collectHeadings: bool
     * }
     */
    private array $events = [
        'headingNumbers' => null,
        'headingPermalinks' => null,
        'externalLinks' => null,
        'lowercaseIds' => false,
        'mathBlockLanguage' => null,
        'collectHeadings' => false,
    ];

    /**
     * @var list<array{level: int, text: string, html: string, id: string}>
     */
    private array $headings = [];

    /**
     * @var list<int>
     */
    private array $numberLevels = [];

    /**
     * @var list<int>
     */
    private array $numbers = [];

    /**
     * @var int
     */
    private const MAX_SOURCE_BYTES = 65536;

    /**
     * @param string $source
     * @param bool $observe
     * @param array{
     *   headingNumbers?: array{minLevel: int}|null,
     *   headingPermalinks?: array{symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>, showOnHover: bool, copyToClipboard: bool}|null,
     *   externalLinks?: array{internalHosts: array<string>, target: string, rel: string, nofollow: bool}|null,
     *   lowercaseIds?: bool,
     *   mathBlockLanguage?: string|null,
     *   collectHeadings?: bool
     * } $events
     *
     * @return array{html: string, accepted: array<string, int>, headings: list<array{level: int, text: string, html: string, id: string}>}|null
     */
    public function render(string $source, bool $observe = false, array $events = []): ?array
    {
        $this->observing = $observe;
        $this->events = array_replace(
            [
                'headingNumbers' => null,
                'headingPermalinks' => null,
                'externalLinks' => null,
                'lowercaseIds' => false,
                'mathBlockLanguage' => null,
                'collectHeadings' => false,
            ],
            $events,
        );
        $this->headings = [];
        $this->numberLevels = [];
        $this->numbers = [];
        if (!$this->eligibleSource($source)) {
            return null;
        }

        $lines = explode("\n", $source);
        if (end($lines) === '') {
            array_pop($lines);
        }
        foreach ($lines as $line) {
            if ($line !== rtrim($line, ' ')) {
                return null;
            }
        }

        $stats = $this->emptyStats();
        $definitions = $this->collectDefinitions($lines, $stats);
        if ($definitions === null) {
            return null;
        }
        $rendered = $this->renderBlocks($lines, $definitions, $stats);
        if ($rendered === null) {
            return null;
        }
        $html = $rendered['html'];

        return [
            'html' => $html === '' ? '' : $html . ($rendered['endsWithoutNewline'] ? '' : "\n"),
            'accepted' => $observe ? $stats : [],
            'headings' => $this->headings,
        ];
    }

    private function eligibleSource(string $source): bool
    {
        return strlen($source) <= self::MAX_SOURCE_BYTES
            && preg_match('/[^\x00-\x7F]|[\x00\x09\x0B\x0C\x0D]/', $source) !== 1
            && !str_starts_with($source, '---')
            && !str_contains($source, '[^')
            && !str_contains($source, '^[')
            && !str_contains($source, '[@')
            && !str_contains($source, '</#')
            && !str_contains($source, '![')
            && !str_contains($source, '%%')
            && !str_contains($source, ':::')
            && preg_match('/(?:^|\n)( *)- [^\n]*\n\n(?:\n)*\1- /', $source) !== 1;
    }

    /**
     * @return array<string, int>
     */
    private function emptyStats(): array
    {
        return array_fill_keys([
            'headings', 'paragraphs', 'blockQuotes', 'codeFences',
            'thematicBreaks', 'unorderedListItems', 'orderedListItems',
            'tableRows', 'linkDefinitions', 'consumedLines', 'activeDefinitions',
        ], 0);
    }

    /**
     * @param array<string, int> $stats
     * @param bool $active
     * @param int $end
     * @param int $start
     * @param string $event
     */
    private function accept(array &$stats, string $event, int $start, int $end, bool $active = false): void
    {
        if (!$this->observing) {
            return;
        }
        $stats[$event]++;
        $stats['consumedLines'] += $end - $start;
        if ($active) {
            $stats['activeDefinitions']++;
        }
    }

    /**
     * @param list<string> $lines
     * @param array<string, int> $stats
     *
     * @return array<string, array{href: string, title: ?string}>|null
     */
    private function collectDefinitions(array $lines, array &$stats): ?array
    {
        $definitions = [];
        $fence = null;
        foreach ($lines as $index => $line) {
            if ($fence !== null) {
                if ($this->isFenceClose($line, $fence)) {
                    $fence = null;
                }

                continue;
            }
            $open = $this->fenceOpen($line);
            if ($open !== null) {
                $fence = $open;

                continue;
            }
            if (!str_contains($line, ']:')) {
                continue;
            }
            if (
                preg_match('/^\[([^\]]+)\]: +(\S+?)(?: +"([^"]*)")?$/', $line, $match) !== 1
                || str_starts_with($match[1], '@')
                || ($index > 0 && trim($lines[$index - 1]) !== '')
                || (isset($lines[$index + 1]) && trim($lines[$index + 1]) !== '')
            ) {
                return null;
            }
            if (isset($match[3])) {
                continue;
            }
            $definitions[$match[1]] = ['href' => $match[2], 'title' => null];
            $this->accept($stats, 'linkDefinitions', $index, $index + 1, true);
        }

        return $definitions;
    }

    /**
     * @param list<string> $lines
     * @param array<string, array{href: string, title: ?string}> $definitions
     * @param array<string, int> $stats
     *
     * @return array{html: string, endsWithoutNewline: bool}|null
     */
    private function renderBlocks(array $lines, array $definitions, array &$stats): ?array
    {
        $out = [];
        $sections = [];
        $ids = new HeadingIdTracker();
        if ($this->events['lowercaseIds']) {
            // Djot's default IDs preserve case; configured ID transforms use the authoritative path.
        }
        $i = 0;
        $wrote = false;
        $previousMath = false;
        $count = count($lines);
        while ($i < $count) {
            $line = $lines[$i];
            if (trim($line) === '' || $this->isActiveDefinition($line, $definitions)) {
                $i++;

                continue;
            }
            if (preg_match('/^(#{1,6}) +(.*)$/', $line, $heading) === 1) {
                if (isset($lines[$i + 1]) && trim($lines[$i + 1]) !== '') {
                    return null;
                }
                $level = strlen($heading[1]);
                $title = rtrim($heading[2]);
                if ($this->inlineComplex($title) || preg_match('/[*\/`[]/', $title) === 1) {
                    return null;
                }
                while ($sections !== [] && end($sections) >= $level) {
                    $out[] = "\n" . $this->indent(count($sections) - 1) . '</section>';
                    array_pop($sections);
                }
                if ($wrote && !$previousMath) {
                    $out[] = "\n";
                }
                $previousMath = false;
                $id = $ids->uniqueId($ids->normalizeId($title));
                $heading = $this->escape($title);
                if ($this->events['headingNumbers'] !== null) {
                    $number = $this->nextHeadingNumber($level, $this->events['headingNumbers']['minLevel']);
                    if ($number !== null) {
                        $heading = '<span class="section-number">' . $number . '</span> ' . $heading;
                    }
                }
                $permalink = $this->events['headingPermalinks'];
                if ($permalink !== null && in_array($level, $permalink['levels'], true)) {
                    $anchor = '<a href="#' . $this->escapeAttribute($id)
                        . '" class="' . $this->escapeAttribute($permalink['cssClass'])
                        . '" aria-label="' . $this->escapeAttribute($permalink['ariaLabel']) . '"'
                        . ($permalink['copyToClipboard'] ? ' data-permalink-copy=""' : '')
                        . '>' . $this->escape($permalink['symbol']) . '</a>';
                    if ($permalink['showOnHover']) {
                        $anchor = '<span class="permalink-wrapper permalink-hover">' . $anchor . '</span>';
                    }
                    $heading = $permalink['position'] === 'before'
                        ? $anchor . ' ' . $heading
                        : $heading . ' ' . $anchor;
                }
                if ($this->events['collectHeadings']) {
                    $this->headings[] = [
                        'level' => $level,
                        'text' => $title,
                        'html' => $this->escape($title),
                        'id' => $id,
                    ];
                }
                $out[] = $this->indent(count($sections)) . '<section id="' . $this->escapeAttribute($id) . '">' . "\n"
                    . $this->indent(count($sections) + 1) . '<h' . $level . '>' . $heading
                    . '</h' . $level . '>';
                $this->accept($stats, 'headings', $i, $i + 1);
                $sections[] = $level;
                $wrote = true;
                $i++;

                continue;
            }
            if ($wrote && !$previousMath) {
                $out[] = "\n";
            }
            $previousMath = false;
            $depth = count($sections);
            $fence = $this->fenceOpen($line);
            if ($fence !== null) {
                if ($fence['char'] !== '`' || str_starts_with($line, ' ') || str_starts_with($line, '>')) {
                    return null;
                }
                $close = $i + 1;
                while ($close < $count && !$this->isFenceClose($lines[$close], $fence)) {
                    $close++;
                }
                if ($close >= $count) {
                    return null;
                }
                $slot = substr($line, $fence['len']);
                $info = trim($slot);
                if (str_starts_with($slot, '  ') || ($info !== '' && preg_match('/^[A-Za-z0-9-]+$/', $info) !== 1)) {
                    return null;
                }
                $code = '';
                for ($j = $i + 1; $j < $close; $j++) {
                    $code .= $this->escape($lines[$j]) . "\n";
                }
                if ($info === $this->events['mathBlockLanguage']) {
                    $math = substr($code, 0, -1);
                    $out[] = $this->indent($depth) . '<div class="math display">\\[' . $math . '\\]</div>';
                    $this->accept($stats, 'codeFences', $i, $close + 1);
                    $i = $close + 1;
                    $wrote = true;
                    $previousMath = true;

                    continue;
                }
                $out[] = $this->indent($depth) . '<pre><code'
                    . ($info === '' ? '' : ' class="language-' . $info . '"') . '>' . $code . '</code></pre>';
                $this->accept($stats, 'codeFences', $i, $close + 1);
                $i = $close + 1;
                $wrote = true;

                continue;
            }
            if (str_starts_with($line, '- ')) {
                $rendered = $this->renderUnorderedList($lines, $i, $definitions, $stats);
                if ($rendered === null) {
                    return null;
                }
                $out[] = $rendered['html'];
                $i = $rendered['next'];
                $wrote = true;

                continue;
            }
            if ($this->thematicBreak($line)) {
                $out[] = $this->indent($depth) . '<hr>';
                $this->accept($stats, 'thematicBreaks', $i, $i + 1);
                $i++;
                $wrote = true;

                continue;
            }
            if ($this->decimalListItem($line) !== null) {
                return null;
            }
            if (str_starts_with($line, '> ')) {
                $rendered = $this->renderBlockQuote($lines, $i, $definitions, $stats);
                if ($rendered === null) {
                    return null;
                }
                $out[] = $rendered['html'];
                $i = $rendered['next'];
                $wrote = true;

                continue;
            }
            if (str_starts_with($line, '|')) {
                $rendered = $this->renderTable($lines, $i, $definitions, $stats);
                if ($rendered === null) {
                    return null;
                }
                $out[] = $rendered['html'];
                $i = $rendered['next'];
                $wrote = true;

                continue;
            }
            if ($this->blockish($line)) {
                return null;
            }
            $start = $i;
            $paragraph = [];
            while (isset($lines[$i]) && trim($lines[$i]) !== '') {
                if ($this->blockish($lines[$i])) {
                    return null;
                }
                $html = $this->renderInline($lines[$i], $definitions);
                if ($html === null) {
                    return null;
                }
                $paragraph[] = $html;
                $i++;
            }
            $out[] = $this->indent($depth) . '<p>' . implode("\n", $paragraph) . '</p>';
            $this->accept($stats, 'paragraphs', $start, $i);
            $wrote = true;
        }
        $hadOpenSections = $sections !== [];
        while ($sections !== []) {
            $out[] = "\n" . $this->indent(count($sections) - 1) . '</section>';
            array_pop($sections);
        }

        return [
            'html' => implode('', $out),
            'endsWithoutNewline' => $previousMath && !$hadOpenSections,
        ];
    }

    /**
     * @param string $text
     * @param array<string, array{href: string, title: ?string}> $definitions
     */
    private function renderInline(string $text, array $definitions): ?string
    {
        if (preg_match('/^\[[^\]]+\]: +\S+ +"[^"]*"$/', $text) === 1) {
            return $this->escapeText($text);
        }
        if (
            strpbrk($text, "*_`[{}^\\<>~!@$=#'\":") === false
            && !str_contains($text, '--')
            && !str_contains($text, '...')
            && !str_contains($text, '(c)')
            && !str_contains($text, '(r)')
            && !str_contains($text, '(tm)')
        ) {
            return $this->escape($text);
        }
        if ($this->inlineComplex($text)) {
            return null;
        }
        if (strpbrk($text, '*_`[') === false) {
            return $this->escapeText($text);
        }
        $flat = $this->renderFlatInline($text, $definitions);
        if ($flat !== false) {
            return $flat;
        }

        $out = '';
        $plain = 0;
        $length = strlen($text);
        for ($i = 0; $i < $length;) {
            $delimiter = $text[$i];
            if (!str_contains('*_`[', $delimiter)) {
                $i++;

                continue;
            }
            $out .= $this->escapeText(substr($text, $plain, $i - $plain));
            if ($delimiter === '*' || $delimiter === '_') {
                if ($delimiter === '*' && ($text[$i + 1] ?? '') === '*') {
                    $close = strpos($text, '**', $i + 2);
                    if ($close === false || $close === $i + 2) {
                        return null;
                    }
                    $inner = $this->renderInline('*' . substr($text, $i + 2, $close - $i - 2) . '*', $definitions);
                    if ($inner === null) {
                        return null;
                    }
                    $out .= '<strong>' . $inner . '</strong>';
                    $i = $close + 2;
                    $plain = $i;

                    continue;
                }
                $close = strpos($text, $delimiter, $i + 1);
                if (
                    $close === false || $close <= $i + 1 || ctype_space($text[$i + 1])
                    || ctype_space($text[$close - 1])
                    || ($i > 0 && ctype_alnum($text[$i - 1]))
                    || (isset($text[$close + 1]) && ctype_alnum($text[$close + 1]))
                ) {
                    return null;
                }
                $inner = $this->renderInline(substr($text, $i + 1, $close - $i - 1), $definitions);
                if ($inner === null) {
                    return null;
                }
                $tag = $delimiter === '*' ? 'strong' : 'em';
                $out .= '<' . $tag . '>' . $inner . '</' . $tag . '>';
                $i = $close + 1;
            } elseif ($delimiter === '`') {
                $close = strpos($text, '`', $i + 1);
                if ($close === false) {
                    return null;
                }
                $code = substr($text, $i + 1, $close - $i - 1);
                if ($code !== trim($code)) {
                    return null;
                }
                $out .= '<code>' . $this->escape($code) . '</code>';
                $i = $close + 1;
            } else {
                $labelEnd = strpos($text, ']', $i + 1);
                if ($labelEnd === false) {
                    return null;
                }
                $label = substr($text, $i + 1, $labelEnd - $i - 1);
                $title = null;
                if (($text[$labelEnd + 1] ?? '') === '(') {
                    $close = strpos($text, ')', $labelEnd + 2);
                    if ($close === false) {
                        return null;
                    }
                    $href = substr($text, $labelEnd + 2, $close - $labelEnd - 2);
                    if ($href === '' || preg_match('/[\s(]/', $href) === 1) {
                        return null;
                    }
                    $i = $close + 1;
                } elseif (($text[$labelEnd + 1] ?? '') === '[') {
                    $close = strpos($text, ']', $labelEnd + 2);
                    if ($close === false) {
                        return null;
                    }
                    $key = substr($text, $labelEnd + 2, $close - $labelEnd - 2);
                    if ($key === '') {
                        return null;
                    }
                    $href = $definitions[$key]['href'] ?? null;
                    $title = $definitions[$key]['title'] ?? null;
                    $i = $close + 1;
                } else {
                    return null;
                }
                if ($href !== null && !$this->safeUrl($href)) {
                    return null;
                }
                $inner = $this->renderInline($label, $definitions);
                if ($inner === null) {
                    return null;
                }
                $out .= '<a' . ($href === null ? '' : ' href="' . $this->escapeAttribute($href) . '"')
                    . ($title === null ? '' : ' title="' . $this->escapeAttribute($title) . '"')
                    . ($href === null ? '' : $this->externalLinkAttributes($href))
                    . '>' . $inner . '</a>';
            }
            $plain = $i;
        }

        return $out . $this->escapeText(substr($text, $plain));
    }

    /**
     * Common non-nested inline markup is tokenized in PCRE instead of walking
     * every byte in PHP. False asks the conservative generic path to decide.
     *
     * @param string $text
     * @param array<string, array{href: string, title: ?string}> $definitions
     */
    private function renderFlatInline(string $text, array $definitions): string|false
    {
        $matched = preg_match_all(
            '/\*\*([^*\n]+)\*\*|\*([^*\n]+)\*|_([^_\n]+)_|`([^`\n]*)`'
                . '|\[([^\]\n*\_`]+)\]\(([^()\s]+)\)|\[([^\]\n*\_`]+)\]\[([^\]\n]+)\]/',
            $text,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        if ($matched === false) {
            return false;
        }

        $out = '';
        $offset = 0;
        foreach ($matches as $match) {
            $token = $match[0][0];
            $start = $match[0][1];
            $plain = substr($text, $offset, $start - $offset);
            if (strpbrk($plain, '*_`[]') !== false) {
                return false;
            }
            $out .= $this->escapeText($plain);

            $delimiter = $token[0];
            if ($delimiter === '*' || $delimiter === '_') {
                $double = str_starts_with($token, '**');
                $inner = $double ? ($match[1][0] ?? '')
                    : ($delimiter === '*' ? ($match[2][0] ?? '') : ($match[3][0] ?? ''));
                $end = $start + strlen($token);
                if (
                    $inner === '' || strpbrk($inner, '*_`[]') !== false
                    || ctype_space($inner[0]) || ctype_space($inner[strlen($inner) - 1])
                    || ($start > 0 && ctype_alnum($text[$start - 1]))
                    || (isset($text[$end]) && ctype_alnum($text[$end]))
                ) {
                    return false;
                }
                $tag = $delimiter === '*' ? 'strong' : 'em';
                $rendered = '<' . $tag . '>' . $this->escapeText($inner) . '</' . $tag . '>';
                $out .= $double ? '<strong>' . $rendered . '</strong>' : $rendered;
            } elseif ($delimiter === '`') {
                $code = $match[4][0] ?? '';
                if ($code !== trim($code)) {
                    return false;
                }
                $out .= '<code>' . $this->escape($code) . '</code>';
            } else {
                $direct = ($match[6][0] ?? '') !== '';
                $label = $direct ? ($match[5][0] ?? '') : ($match[7][0] ?? '');
                $href = $direct ? ($match[6][0] ?? '') : ($definitions[$match[8][0] ?? '']['href'] ?? null);
                if ($href !== null && !$this->safeUrl($href)) {
                    return false;
                }
                $out .= '<a' . ($href === null ? '>' : ' href="' . $this->escapeAttribute($href) . '">')
                    . $this->escapeText($label) . '</a>';
            }
            $offset = $start + strlen($token);
        }

        $tail = substr($text, $offset);
        if (strpbrk($tail, '*_`[]') !== false) {
            return false;
        }

        return $out . $this->escapeText($tail);
    }

    /**
     * @param string $line
     * @param array<string, array{href: string, title: ?string}> $definitions
     */
    private function isActiveDefinition(string $line, array $definitions): bool
    {
        return preg_match('/^\[([^\]]+)\]:/', $line, $match) === 1 && isset($definitions[$match[1]]);
    }

    /**
     * @param list<string> $lines
     * @param int $start
     * @param array<string, array{href: string, title: ?string}> $definitions
     * @param array<string, int> $stats
     *
     * @return array{html: string, next: int}|null
     */
    private function renderUnorderedList(array $lines, int $start, array $definitions, array &$stats): ?array
    {
        $html = "<ul>\n";
        $i = $start;
        while (isset($lines[$i]) && str_starts_with($lines[$i], '- ')) {
            $itemText = substr($lines[$i], 2);
            if (str_starts_with($itemText, '- ')) {
                return null;
            }
            $inline = $this->renderInline($itemText, $definitions);
            if ($inline === null) {
                return null;
            }
            $this->accept($stats, 'unorderedListItems', $i, $i + 1);
            $html .= "<li>\n" . $inline;
            $i++;
            while (isset($lines[$i]) && str_starts_with($lines[$i], '  - ')) {
                $nested = $this->renderInline(substr($lines[$i], 4), $definitions);
                if ($nested === null) {
                    return null;
                }
                $html .= "\n- " . $nested;
                $this->accept($stats, 'unorderedListItems', $i, $i + 1);
                $i++;
            }
            if (isset($lines[$i]) && trim($lines[$i]) !== '' && !str_starts_with($lines[$i], '- ')) {
                return null;
            }
            $html .= "\n</li>\n";
        }

        return ['html' => $html . '</ul>', 'next' => $i];
    }

    /**
     * @param list<string> $lines
     * @param int $start
     * @param array<string, array{href: string, title: ?string}> $definitions
     * @param array<string, int> $stats
     *
     * @return array{html: string, next: int}|null
     */
    private function renderBlockQuote(array $lines, int $start, array $definitions, array &$stats): ?array
    {
        $parts = [];
        $i = $start;
        while (isset($lines[$i]) && str_starts_with($lines[$i], '> ')) {
            $quoteText = substr($lines[$i], 2);
            if ($this->blockish($quoteText)) {
                return null;
            }
            $inline = $this->renderInline($quoteText, $definitions);
            if ($inline === null) {
                return null;
            }
            $parts[] = $inline;
            $i++;
        }
        if (isset($lines[$i]) && trim($lines[$i]) !== '') {
            return null;
        }
        $this->accept($stats, 'blockQuotes', $start, $i);

        return ['html' => "<blockquote>\n<p>" . implode("\n", $parts) . "</p>\n</blockquote>", 'next' => $i];
    }

    /**
     * @param list<string> $lines
     * @param int $start
     * @param array<string, array{href: string, title: ?string}> $definitions
     * @param array<string, int> $stats
     *
     * @return array{html: string, next: int}|null
     */
    private function renderTable(array $lines, int $start, array $definitions, array &$stats): ?array
    {
        $html = "<table>\n";
        $i = $start;
        while (isset($lines[$i]) && str_starts_with($lines[$i], '|')) {
            $trimmed = trim($lines[$i]);
            if (!str_ends_with($trimmed, '|') || str_contains($trimmed, '\\|')) {
                return null;
            }
            if (str_starts_with($trimmed, '|-') || str_starts_with($trimmed, '|:')) {
                return null;
            }
            $cells = array_map('trim', explode('|', substr($trimmed, 1, -1)));
            $html .= "<tr>\n";
            foreach ($cells as $cell) {
                $inline = $this->renderInline($cell, $definitions);
                if ($inline === null) {
                    return null;
                }
                $html .= '<td>' . $inline . "</td>\n";
            }
            $html .= "</tr>\n";
            $this->accept($stats, 'tableRows', $i, $i + 1);
            $i++;
        }

        return ['html' => $html . '</table>', 'next' => $i];
    }

    /**
     * @return array{number: int, text: string}|null
     */
    private function decimalListItem(string $line): ?array
    {
        if (preg_match('/^(\d+)\. ([^ ].*)$/', $line, $match) !== 1) {
            return null;
        }
        $number = (int)$match[1];

        return $number > 0 ? ['number' => $number, 'text' => $match[2]] : null;
    }

    private function inlineComplex(string $text): bool
    {
        $withoutContractions = $text;
        if (str_contains($text, "'")) {
            $withoutContractions = preg_replace('/(?<=[A-Za-z0-9])\'(?=[A-Za-z0-9])/', '', $text);
            if ($withoutContractions === null) {
                return true;
            }
        }

        if (str_contains($withoutContractions, '"') && substr_count($withoutContractions, '"') % 2 !== 0) {
            return true;
        }

        return preg_match('/[{}^\\\\<>~!@$=#\']|\.\.\.|\/\*|\*\/|``|\+\-|\(c\)|\(r\)|\(tm\)/', $withoutContractions) === 1
            || substr_count($text, ':') >= 2;
    }

    private function blockish(string $text): bool
    {
        if (
            $text === ':' || $text === '-' || $text === '+'
            || preg_match('/^(?:\([A-Za-z0-9]+\)|\d+[.)]|[A-Za-z][.)]|[ivxlcdmIVXLCDM]+[.)])(?: |$)/', $text) === 1
        ) {
            return true;
        }

        return preg_match('/^(?:\s|#|\* |\+ |- |>|\||\{|:::|```|~~~|\.{1,9} |[A-Za-z0-9]+[.)] |---+|\*\*\*+)$/', $text) === 1
            || preg_match('/^(?:\s|#|\* |\+ |- |>|\||\{|:::|```|~~~|\.{1,9} |[A-Za-z0-9]+[.)] )/', $text) === 1;
    }

    private function thematicBreak(string $line): bool
    {
        return preg_match('/^\*{3,}$/', $line) === 1;
    }

    /**
     * @return array{char: string, len: int}|null
     */
    private function fenceOpen(string $line): ?array
    {
        if (preg_match('/^(`{3,}|~{3,})/', $line, $match) !== 1) {
            return null;
        }

        return ['char' => $match[1][0], 'len' => strlen($match[1])];
    }

    /**
     * @param string $line
@param array{char: string, len: int} $fence
     */
    private function isFenceClose(string $line, array $fence): bool
    {
        return preg_match('/^' . preg_quote($fence['char'], '/') . '{' . $fence['len'] . ',}\s*$/', $line) === 1;
    }

    private function safeUrl(string $url): bool
    {
        return preg_match('/^(?:https?:|mailto:|\/|#|\.\/|\.\.\/)/i', $url) === 1;
    }

    private function nextHeadingNumber(int $level, int $minLevel): ?string
    {
        if ($level < $minLevel) {
            return null;
        }
        $depth = count($this->numberLevels);
        while ($depth > 0 && $this->numberLevels[$depth - 1] > $level) {
            array_pop($this->numberLevels);
            array_pop($this->numbers);
            $depth--;
        }
        if ($depth > 0 && $this->numberLevels[$depth - 1] === $level) {
            $number = array_pop($this->numbers);
            $this->numbers[] = ($number ?? 0) + 1;
        } else {
            $this->numberLevels[] = $level;
            $this->numbers[] = 1;
        }

        return implode('.', $this->numbers);
    }

    private function externalLinkAttributes(string $href): string
    {
        $external = $this->events['externalLinks'];
        if ($external === null || preg_match('#^https?://#i', $href) !== 1) {
            return '';
        }
        $host = parse_url($href, PHP_URL_HOST);
        if (!is_string($host)) {
            return '';
        }
        foreach ($external['internalHosts'] as $internalHost) {
            if (strtolower($internalHost) === strtolower($host)) {
                return '';
            }
        }
        $rel = $external['rel'];
        if ($external['nofollow'] && !str_contains($rel, 'nofollow')) {
            $rel .= ' nofollow';
        }

        return ' target="' . $this->escapeAttribute($external['target'])
            . '" rel="' . $this->escapeAttribute(trim($rel)) . '"';
    }

    private function escape(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

        return str_replace(["\u{E000}", "\u{00A0}"], '&nbsp;', $escaped);
    }

    private function escapeText(string $text): string
    {
        if (str_contains($text, "'")) {
            $text = (string)preg_replace('/(?<=[A-Za-z0-9])\'(?=[A-Za-z0-9])/', '’', $text);
        }
        if (str_contains($text, '"')) {
            $text = (string)preg_replace('/"([^"]*)"/', '“$1”', $text);
        }
        if (str_contains($text, '--')) {
            $text = (string)preg_replace_callback('/-{2,}/', static function (array $match): string {
                $length = strlen($match[0]);
                if ($length % 2 === 0 && $length % 3 !== 0) {
                    return str_repeat('–', intdiv($length, 2));
                }
                $triples = intdiv($length, 3);
                $remainder = $length % 3;
                if ($remainder === 1) {
                    $triples--;
                    $remainder = 4;
                }

                return str_repeat('—', $triples) . str_repeat('–', intdiv($remainder, 2));
            }, $text);
        }

        return $this->escape($text);
    }

    private function escapeAttribute(string $text): string
    {
        return StringUtil::escapeHtml($text);
    }

    private function indent(int $depth): string
    {
        return '';
    }
}
