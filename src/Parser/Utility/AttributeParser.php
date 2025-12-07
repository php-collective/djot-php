<?php

declare(strict_types=1);

namespace Djot\Parser\Utility;

use Djot\Node\Node;

/**
 * Shared utility for parsing djot attribute strings.
 *
 * Handles parsing of attribute syntax: {.class #id key="value" boolean}
 */
class AttributeParser
{
    /**
     * Parse attribute string and return as array
     *
     * Supports:
     * - .class (class shorthand)
     * - #id (id shorthand)
     * - key="double quoted value" (with escape support)
     * - key='single quoted value' (with escape support)
     * - key=unquoted
     * - bareword (boolean attribute)
     *
     * @param string $attrStr The attribute string (contents inside {})
     *
     * @return array<string, string> Parsed attributes
     */
    public static function parse(string $attrStr): array
    {
        $attributes = [];

        // Parse .class
        if (preg_match_all('/\.([^\s.#=}]+)/', $attrStr, $classMatches)) {
            $attributes['class'] = implode(' ', $classMatches[1]);
        }

        // Parse #id
        if (preg_match('/#([^\s.#=}]+)/', $attrStr, $idMatch)) {
            $attributes['id'] = $idMatch[1];
        }

        // Parse key="double quoted value", key='single quoted value', or key=unquoted
        // The regex uses ([^"\\]|\\.)* to match content with escaped characters
        $kvPattern = '/([a-zA-Z_][a-zA-Z0-9_-]*)="((?:[^"\\\\]|\\\\.)*)"|'
            . '([a-zA-Z_][a-zA-Z0-9_-]*)=\'((?:[^\'\\\\]|\\\\.)*)\''
            . '|([a-zA-Z_][a-zA-Z0-9_-]*)=([^\s}"\']+)/';

        if (preg_match_all($kvPattern, $attrStr, $kvMatches, PREG_SET_ORDER)) {
            foreach ($kvMatches as $match) {
                if (($match[1] ?? '') !== '') {
                    // key="double quoted value"
                    $attributes[$match[1]] = self::processEscapes($match[2] ?? '');
                } elseif (($match[3] ?? '') !== '') {
                    // key='single quoted value'
                    $attributes[$match[3]] = self::processEscapes($match[4] ?? '');
                } elseif (($match[5] ?? '') !== '') {
                    // key=unquoted
                    $attributes[$match[5]] = $match[6] ?? '';
                }
            }
        }

        // Parse boolean attributes (bare words like "reversed", "hidden")
        // First, strip out quoted values and key=value pairs to avoid matching words inside them
        $strippedAttr = preg_replace('/[a-zA-Z_][a-zA-Z0-9_-]*="(?:[^"\\\\]|\\\\.)*"/', '', $attrStr) ?? $attrStr;
        $strippedAttr = preg_replace("/[a-zA-Z_][a-zA-Z0-9_-]*='(?:[^'\\\\]|\\\\.)*'/", '', $strippedAttr) ?? $strippedAttr;
        $strippedAttr = preg_replace('/[a-zA-Z_][a-zA-Z0-9_-]*=[^\s}"\']+/', '', $strippedAttr) ?? $strippedAttr;

        // Now match bare words (must not start with . or #)
        if (preg_match_all('/(?:^|\s)([a-zA-Z][a-zA-Z0-9_-]*)(?=\s|$)/', $strippedAttr, $boolMatches)) {
            foreach ($boolMatches[1] as $boolAttr) {
                $attributes[$boolAttr] = '';
            }
        }

        return $attributes;
    }

    /**
     * Parse attribute string and merge with existing attributes
     *
     * @param array<string, string> $existing Existing attributes to merge with
     * @param string $attrStr The attribute string to parse
     *
     * @return array<string, string> Merged attributes
     */
    public static function parseAndMerge(array $existing, string $attrStr): array
    {
        $parsed = self::parse($attrStr);

        // Special handling for class: merge rather than replace
        if (isset($parsed['class']) && isset($existing['class'])) {
            $parsed['class'] = trim($existing['class'] . ' ' . $parsed['class']);
        }

        return array_merge($existing, $parsed);
    }

    /**
     * Apply attributes from a string directly to a node
     *
     * @param \Djot\Node\Node $node The node to apply attributes to
     * @param string $attrStr The attribute string to parse
     */
    public static function applyToNode(Node $node, string $attrStr): void
    {
        // Parse key=value, .class, #id patterns
        $pattern = '/\.([^\s.#=}]+)|#([^\s.#=}]+)'
            . '|([^\s.#=}]+)="((?:[^"\\\\]|\\\\.)*)"|([^\s.#=}]+)=\'((?:[^\'\\\\]|\\\\.)*)\''
            . '|([^\s.#=}]+)=([^\s}"\']+)/';
        preg_match_all($pattern, $attrStr, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (!empty($match[1])) {
                // Class attribute
                $node->addClass($match[1]);
            } elseif (!empty($match[2])) {
                // ID attribute
                $node->setAttribute('id', $match[2]);
            } elseif (($match[3] ?? '') !== '') {
                // key="double quoted value"
                $node->setAttribute($match[3], self::processEscapes($match[4] ?? ''));
            } elseif (($match[5] ?? '') !== '') {
                // key='single quoted value'
                $node->setAttribute($match[5], self::processEscapes($match[6] ?? ''));
            } elseif (($match[7] ?? '') !== '') {
                // key=unquoted
                $node->setAttribute($match[7], $match[8] ?? '');
            }
        }

        // Parse boolean attributes
        $strippedAttr = preg_replace('/[a-zA-Z_][a-zA-Z0-9_-]*="(?:[^"\\\\]|\\\\.)*"/', '', $attrStr) ?? $attrStr;
        $strippedAttr = preg_replace("/[a-zA-Z_][a-zA-Z0-9_-]*='(?:[^'\\\\]|\\\\.)*'/", '', $strippedAttr) ?? $strippedAttr;
        $strippedAttr = preg_replace('/[a-zA-Z_][a-zA-Z0-9_-]*=[^\s}"\']+/', '', $strippedAttr) ?? $strippedAttr;

        if (preg_match_all('/(?:^|\s)([a-zA-Z][a-zA-Z0-9_-]*)(?=\s|$)/', $strippedAttr, $boolMatches)) {
            foreach ($boolMatches[1] as $boolAttr) {
                $node->setAttribute($boolAttr, '');
            }
        }
    }

    /**
     * Process escape sequences in attribute values
     *
     * Handles \\ -> \ and \" -> " (and other escaped characters)
     */
    public static function processEscapes(string $value): string
    {
        // Replace escape sequences: \X -> X for any character X
        return preg_replace('/\\\\(.)/', '$1', $value) ?? $value;
    }
}
