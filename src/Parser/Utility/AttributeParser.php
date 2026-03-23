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

        // Strip quoted values and unquoted key=value pairs before matching .class and #id
        // to avoid matching dots/hashes inside attribute values like key="file.txt"
        // or partial matches from invalid unquoted values like key=foo.bar
        $strippedForShorthand = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '', $attrStr) ?? $attrStr;
        $strippedForShorthand = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '', $strippedForShorthand) ?? $strippedForShorthand;
        // Strip unquoted key=value tokens entirely (up to whitespace) to prevent
        // invalid chars like dots from being misinterpreted as .class shorthand
        $strippedForShorthand = preg_replace('/[a-zA-Z_][a-zA-Z0-9_-]*=[^\s}]+/', '', $strippedForShorthand) ?? $strippedForShorthand;

        // Parse .class
        if (preg_match_all('/\.([^\s.#=}]+)/', $strippedForShorthand, $classMatches)) {
            $attributes['class'] = implode(' ', $classMatches[1]);
        }

        // Parse #id
        if (preg_match('/#([^\s.#=}]+)/', $strippedForShorthand, $idMatch)) {
            $attributes['id'] = $idMatch[1];
        }

        // Parse key="double quoted value", key='single quoted value', or key=unquoted
        // The regex uses ([^"\\]|\\.)* to match content with escaped characters
        // Per djot spec, unquoted values may only contain: alphanumerics, underscore, colon, hyphen
        // Unquoted values must be followed by whitespace or } to be valid (not invalid chars like dots)
        $kvPattern = '/([a-zA-Z_][a-zA-Z0-9_-]*)="((?:[^"\\\\]|\\\\.)*)"|'
            . '([a-zA-Z_][a-zA-Z0-9_-]*)=\'((?:[^\'\\\\]|\\\\.)*)\''
            . '|([a-zA-Z_][a-zA-Z0-9_-]*)=([a-zA-Z0-9_:-]+)(?=\s|}|$)/';

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
        $strippedAttr = preg_replace('/[a-zA-Z_][a-zA-Z0-9_-]*=[a-zA-Z0-9_:-]+/', '', $strippedAttr) ?? $strippedAttr;

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
        // Strip quoted values and unquoted key=value pairs before matching .class and #id
        // to avoid matching dots/hashes inside attribute values like key="file.txt"
        // or partial matches from invalid unquoted values like key=foo.bar
        $strippedForShorthand = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '', $attrStr) ?? $attrStr;
        $strippedForShorthand = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '', $strippedForShorthand) ?? $strippedForShorthand;
        // Strip unquoted key=value tokens entirely (up to whitespace) to prevent
        // invalid chars like dots from being misinterpreted as .class shorthand
        $strippedForShorthand = preg_replace('/[a-zA-Z_][a-zA-Z0-9_-]*=[^\s}]+/', '', $strippedForShorthand) ?? $strippedForShorthand;

        // Parse .class and #id on stripped string
        if (preg_match_all('/\.([^\s.#=}]+)/', $strippedForShorthand, $classMatches)) {
            foreach ($classMatches[1] as $class) {
                $node->addClass($class);
            }
        }

        if (preg_match('/#([^\s.#=}]+)/', $strippedForShorthand, $idMatch)) {
            $node->setAttribute('id', $idMatch[1]);
        }

        // Parse key=value on original string (needs quoted values intact)
        // Per djot spec, unquoted values may only contain: alphanumerics, underscore, colon, hyphen
        // Unquoted values must be followed by whitespace or } to be valid (not invalid chars like dots)
        $kvPattern = '/([^\s.#=}]+)="((?:[^"\\\\]|\\\\.)*)"|([^\s.#=}]+)=\'((?:[^\'\\\\]|\\\\.)*)\''
            . '|([^\s.#=}]+)=([a-zA-Z0-9_:-]+)(?=\s|}|$)/';
        preg_match_all($kvPattern, $attrStr, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (($match[1] ?? '') !== '') {
                // key="double quoted value"
                $node->setAttribute($match[1], self::processEscapes($match[2] ?? ''));
            } elseif (($match[3] ?? '') !== '') {
                // key='single quoted value'
                $node->setAttribute($match[3], self::processEscapes($match[4] ?? ''));
            } elseif (($match[5] ?? '') !== '') {
                // key=unquoted
                $node->setAttribute($match[5], $match[6] ?? '');
            }
        }

        // Parse boolean attributes
        $strippedAttr = preg_replace('/[a-zA-Z_][a-zA-Z0-9_-]*="(?:[^"\\\\]|\\\\.)*"/', '', $attrStr) ?? $attrStr;
        $strippedAttr = preg_replace("/[a-zA-Z_][a-zA-Z0-9_-]*='(?:[^'\\\\]|\\\\.)*'/", '', $strippedAttr) ?? $strippedAttr;
        $strippedAttr = preg_replace('/[a-zA-Z_][a-zA-Z0-9_-]*=[a-zA-Z0-9_:-]+/', '', $strippedAttr) ?? $strippedAttr;

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
