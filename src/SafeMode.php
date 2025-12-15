<?php

declare(strict_types=1);

namespace Djot;

/**
 * Configuration for safe mode rendering
 *
 * Safe mode helps prevent XSS attacks by sanitizing URLs,
 * filtering dangerous attributes, and controlling raw HTML output.
 */
class SafeMode
{
    /**
     * Strip raw HTML completely
     *
     * @var string
     */
    public const RAW_HTML_STRIP = 'strip';

    /**
     * Escape raw HTML as text
     *
     * @var string
     */
    public const RAW_HTML_ESCAPE = 'escape';

    /**
     * Allow raw HTML (not recommended in safe mode)
     *
     * @var string
     */
    public const RAW_HTML_ALLOW = 'allow';

    /**
     * Dangerous URL schemes that should be blocked
     *
     * @var array<string>
     */
    protected array $dangerousSchemes = [
        'javascript',
        'vbscript',
        'data',
        'file',
    ];

    /**
     * Allowed URL schemes (if set, only these are allowed)
     *
     * @var array<string>|null
     */
    protected ?array $allowedSchemes = null;

    /**
     * Attribute prefixes to block (e.g., 'on' blocks onclick, onload, etc.)
     *
     * @var array<string>
     */
    protected array $blockedAttributePrefixes = ['on'];

    /**
     * Specific attributes to block
     *
     * @var array<string>
     */
    protected array $blockedAttributes = ['srcdoc', 'formaction'];

    /**
     * How to handle raw HTML blocks and inline
     */
    protected string $rawHtmlMode = self::RAW_HTML_ESCAPE;

    /**
     * Create a safe mode configuration with sensible defaults
     */
    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Create a strict safe mode (strips raw HTML completely)
     */
    public static function strict(): self
    {
        return (new self())->setRawHtmlMode(self::RAW_HTML_STRIP);
    }

    /**
     * Get dangerous URL schemes
     *
     * @return array<string>
     */
    public function getDangerousSchemes(): array
    {
        return $this->dangerousSchemes;
    }

    /**
     * Set dangerous URL schemes to block
     *
     * @param array<string> $schemes
     */
    public function setDangerousSchemes(array $schemes): self
    {
        $this->dangerousSchemes = $schemes;

        return $this;
    }

    /**
     * Add a dangerous URL scheme
     */
    public function addDangerousScheme(string $scheme): self
    {
        $this->dangerousSchemes[] = strtolower($scheme);

        return $this;
    }

    /**
     * Get allowed URL schemes (null means all non-dangerous schemes allowed)
     *
     * @return array<string>|null
     */
    public function getAllowedSchemes(): ?array
    {
        return $this->allowedSchemes;
    }

    /**
     * Set allowed URL schemes (whitelist approach)
     *
     * @param array<string>|null $schemes Null to allow all non-dangerous schemes
     */
    public function setAllowedSchemes(?array $schemes): self
    {
        $this->allowedSchemes = $schemes;

        return $this;
    }

    /**
     * Get blocked attribute prefixes
     *
     * @return array<string>
     */
    public function getBlockedAttributePrefixes(): array
    {
        return $this->blockedAttributePrefixes;
    }

    /**
     * Set blocked attribute prefixes
     *
     * @param array<string> $prefixes
     */
    public function setBlockedAttributePrefixes(array $prefixes): self
    {
        $this->blockedAttributePrefixes = $prefixes;

        return $this;
    }

    /**
     * Get blocked attributes
     *
     * @return array<string>
     */
    public function getBlockedAttributes(): array
    {
        return $this->blockedAttributes;
    }

    /**
     * Set blocked attributes
     *
     * @param array<string> $attributes
     */
    public function setBlockedAttributes(array $attributes): self
    {
        $this->blockedAttributes = $attributes;

        return $this;
    }

    /**
     * Get raw HTML handling mode
     */
    public function getRawHtmlMode(): string
    {
        return $this->rawHtmlMode;
    }

    /**
     * Set raw HTML handling mode
     *
     * @param string $mode One of RAW_HTML_STRIP, RAW_HTML_ESCAPE, RAW_HTML_ALLOW
     */
    public function setRawHtmlMode(string $mode): self
    {
        $this->rawHtmlMode = $mode;

        return $this;
    }

    /**
     * Check if a URL is safe
     */
    public function isUrlSafe(string $url): bool
    {
        $url = trim($url);

        // Empty URLs are safe
        if ($url === '') {
            return true;
        }

        // Check for dangerous schemes
        $colonPos = strpos($url, ':');
        if ($colonPos !== false) {
            $scheme = strtolower(substr($url, 0, $colonPos));

            // Check against dangerous schemes
            if (in_array($scheme, $this->dangerousSchemes, true)) {
                return false;
            }

            // If we have an allowlist, check against it
            if ($this->allowedSchemes !== null) {
                return in_array($scheme, $this->allowedSchemes, true);
            }
        }

        return true;
    }

    /**
     * Sanitize a URL, returning empty string if unsafe
     */
    public function sanitizeUrl(string $url): string
    {
        return $this->isUrlSafe($url) ? $url : '';
    }

    /**
     * Check if an attribute name is safe
     */
    public function isAttributeSafe(string $name): bool
    {
        $nameLower = strtolower($name);

        // Check blocked attributes
        if (in_array($nameLower, $this->blockedAttributes, true)) {
            return false;
        }

        // Check blocked prefixes
        foreach ($this->blockedAttributePrefixes as $prefix) {
            if (str_starts_with($nameLower, strtolower($prefix))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Filter attributes, removing unsafe ones
     *
     * @param array<string, string> $attributes
     *
     * @return array<string, string>
     */
    public function filterAttributes(array $attributes): array
    {
        return array_filter(
            $attributes,
            fn (string $key) => $this->isAttributeSafe($key),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
