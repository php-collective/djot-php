<?php

declare(strict_types=1);

namespace Djot\Util;

/**
 * Always-on URL scheme hardening shared by every renderer.
 *
 * A link destination or image source whose (normalized) scheme is on the
 * dangerous denylist (`javascript`, `vbscript`, `data`, `file`) is neutralized
 * regardless of safe mode or output format - there is no legitimate reason to
 * emit such a URL from untrusted markup, and a non-HTML export (Markdown, plain
 * text) carries it to wherever it is rendered next. Scheme detection strips C0
 * controls + spaces first to defeat `java\tscript:` style evasion.
 */
final class UrlSafety
{
    /**
     * @var array<string>
     */
    public const DANGEROUS_SCHEMES = ['javascript', 'vbscript', 'data', 'file'];

    /**
     * True when the URL carries a dangerous denylist scheme. Scheme-less and
     * relative URLs, and every non-denylist scheme, return false.
     */
    public static function hasDangerousScheme(string $url): bool
    {
        $probe = (string)preg_replace('/[\x00-\x20]+/', '', $url);
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $probe, $m) === 1) {
            return in_array(strtolower($m[1]), self::DANGEROUS_SCHEMES, true);
        }

        return false;
    }

    /**
     * Blank a URL that carries a dangerous scheme; otherwise return it unchanged.
     */
    public static function sanitize(string $url): string
    {
        return self::hasDangerousScheme($url) ? '' : $url;
    }
}
