<?php

declare(strict_types=1);

namespace Djot\Parser\Utility;

/**
 * Helper class for handling indentation in djot documents.
 *
 * In djot, tabs are treated as equivalent to 2 spaces for indentation purposes.
 * This class provides utilities for counting and stripping indentation.
 */
class IndentationHelper
{
    /**
     * Spaces equivalent to one tab
     *
     * @var int
     */
    public const TAB_WIDTH = 2;

    /**
     * Count the number of leading spaces in a line.
     *
     * Tabs count as TAB_WIDTH spaces (2 spaces, one indentation level).
     *
     * @param string $line The line to examine
     *
     * @return int The space-equivalent count of leading whitespace
     */
    public static function getLeadingSpaces(string $line): int
    {
        $count = 0;
        $len = strlen($line);

        for ($i = 0; $i < $len; $i++) {
            if ($line[$i] === ' ') {
                $count++;
            } elseif ($line[$i] === "\t") {
                $count += self::TAB_WIDTH;
            } else {
                break;
            }
        }

        return $count;
    }

    /**
     * Strip leading whitespace from a line, up to the specified space-equivalent count.
     *
     * Tabs count as TAB_WIDTH spaces. This correctly handles mixed spaces and tabs.
     *
     * @param string $line The line to strip
     * @param int $amount Maximum space-equivalent amount to strip
     *
     * @return string The line with leading whitespace stripped
     */
    public static function stripLeadingIndent(string $line, int $amount): string
    {
        $stripped = 0;
        $len = strlen($line);
        $i = 0;

        while ($i < $len && $stripped < $amount) {
            if ($line[$i] === ' ') {
                $stripped++;
                $i++;
            } elseif ($line[$i] === "\t") {
                $stripped += self::TAB_WIDTH;
                $i++;
            } else {
                break;
            }
        }

        return substr($line, $i);
    }

    /**
     * Check if a line is blank (empty or whitespace only)
     *
     * @param string $line The line to check
     *
     * @return bool True if the line is blank
     */
    public static function isBlankLine(string $line): bool
    {
        return trim($line) === '';
    }

    /**
     * Check if a line has at least the specified indentation level
     *
     * @param string $line The line to check
     * @param int $minIndent Minimum space-equivalent indentation
     *
     * @return bool True if the line has at least the specified indentation
     */
    public static function hasMinIndent(string $line, int $minIndent): bool
    {
        return self::getLeadingSpaces($line) >= $minIndent;
    }

    /**
     * Create an indentation string of the specified space-equivalent width
     *
     * @param int $spaces Number of spaces
     * @param bool $useTabs Whether to use tabs (default: false, use spaces)
     *
     * @return string The indentation string
     */
    public static function createIndent(int $spaces, bool $useTabs = false): string
    {
        if ($useTabs) {
            $tabs = intdiv($spaces, self::TAB_WIDTH);
            $remainder = $spaces % self::TAB_WIDTH;

            return str_repeat("\t", $tabs) . str_repeat(' ', $remainder);
        }

        return str_repeat(' ', $spaces);
    }
}
