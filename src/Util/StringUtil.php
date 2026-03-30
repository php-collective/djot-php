<?php

declare(strict_types=1);

namespace Djot\Util;

/**
 * String utility functions
 */
final class StringUtil
{
    /**
     * Find a safe code fence marker that doesn't conflict with content
     *
     * Returns backticks (` or ```) that don't appear in the content,
     * extending the marker length as needed.
     *
     * @param string $content The content that will be fenced
     * @param int $minTicks Minimum number of backticks (1 for inline, 3 for blocks)
     *
     * @return string Safe fence marker
     */
    public static function findSafeCodeFence(string $content, int $minTicks = 1): string
    {
        $backticks = str_repeat('`', $minTicks);

        while (str_contains($content, $backticks)) {
            $backticks .= '`';
        }

        return $backticks;
    }
}
