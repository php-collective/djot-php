<?php

declare(strict_types=1);

namespace Djot;

/**
 * Link URL policy for Profile-based filtering
 *
 * Controls which URLs are allowed in links and images.
 */
class LinkPolicy
{
    /**
     * @var list<string>|null
     */
    protected ?array $allowedSchemes = null;

    /**
     * @var list<string>
     */
    protected array $deniedSchemes = ['javascript', 'vbscript', 'data', 'file'];

    /**
     * @var list<string>|null
     */
    protected ?array $allowedDomains = null;

    /**
     * @var list<string>
     */
    protected array $deniedDomains = [];

    protected bool $allowExternal = true;

    protected bool $allowInternal = true;

    /**
     * @var list<string>
     */
    protected array $relAttributes = [];

    /**
     * Create a policy that allows all URLs (except dangerous schemes)
     */
    public static function unrestricted(): self
    {
        return new self();
    }

    /**
     * Create a policy that only allows internal links (relative URLs, fragments)
     *
     * Use this for contexts where external links are not desired,
     * such as internal documentation or wikis.
     */
    public static function internalOnly(): self
    {
        return (new self())->setAllowExternal(false);
    }

    /**
     * Create a policy that only allows links to specific domains
     *
     * Use this to restrict links to trusted domains only.
     *
     * @param list<string> $domains Allowed domain names
     */
    public static function allowlist(array $domains): self
    {
        return (new self())->setAllowedDomains($domains);
    }

    /**
     * @return list<string>|null
     */
    public function getAllowedSchemes(): ?array
    {
        return $this->allowedSchemes;
    }

    /**
     * @param list<string>|null $schemes
     */
    public function setAllowedSchemes(?array $schemes): self
    {
        $this->allowedSchemes = $schemes;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getDeniedSchemes(): array
    {
        return $this->deniedSchemes;
    }

    /**
     * @param list<string> $schemes
     */
    public function setDeniedSchemes(array $schemes): self
    {
        $this->deniedSchemes = $schemes;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getAllowedDomains(): ?array
    {
        return $this->allowedDomains;
    }

    /**
     * @param list<string>|null $domains
     */
    public function setAllowedDomains(?array $domains): self
    {
        $this->allowedDomains = $domains;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getDeniedDomains(): array
    {
        return $this->deniedDomains;
    }

    /**
     * @param list<string> $domains
     */
    public function setDeniedDomains(array $domains): self
    {
        $this->deniedDomains = $domains;

        return $this;
    }

    public function getAllowExternal(): bool
    {
        return $this->allowExternal;
    }

    public function setAllowExternal(bool $allow): self
    {
        $this->allowExternal = $allow;

        return $this;
    }

    public function getAllowInternal(): bool
    {
        return $this->allowInternal;
    }

    public function setAllowInternal(bool $allow): self
    {
        $this->allowInternal = $allow;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getRelAttributes(): array
    {
        return $this->relAttributes;
    }

    /**
     * @param list<string> $attrs
     */
    public function setRelAttributes(array $attrs): self
    {
        $this->relAttributes = $attrs;

        return $this;
    }

    /**
     * Add a rel attribute to be applied to all links
     */
    public function addRelAttribute(string $attr): self
    {
        if (!in_array($attr, $this->relAttributes, true)) {
            $this->relAttributes[] = $attr;
        }

        return $this;
    }

    /**
     * Check if a URL is allowed by this policy
     *
     * @param string $url The URL to check
     * @param string|null $baseHost Current document's host (for external detection)
     */
    public function isUrlAllowed(string $url, ?string $baseHost = null): bool
    {
        $url = trim($url);

        if ($url === '') {
            return true;
        }

        // Fragment-only URLs are always internal
        if (str_starts_with($url, '#')) {
            return $this->allowInternal;
        }

        // Relative paths are internal
        if (str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../')) {
            return $this->allowInternal;
        }

        // Check for scheme
        $colonPos = strpos($url, ':');
        if ($colonPos !== false) {
            $scheme = strtolower(substr($url, 0, $colonPos));

            // Check denied schemes
            if (in_array($scheme, $this->deniedSchemes, true)) {
                return false;
            }

            // Check allowed schemes if set
            if ($this->allowedSchemes !== null && !in_array($scheme, $this->allowedSchemes, true)) {
                return false;
            }

            // mailto: and tel: are considered internal for simplicity
            if (in_array($scheme, ['mailto', 'tel'], true)) {
                return true;
            }

            // Parse host for http/https URLs
            if (in_array($scheme, ['http', 'https'], true)) {
                $parsed = parse_url($url);
                $host = $parsed['host'] ?? null;

                if ($host !== null) {
                    // Check denied domains
                    if ($this->isDomainDenied($host)) {
                        return false;
                    }

                    // Check allowed domains if set
                    if ($this->allowedDomains !== null && !$this->isDomainAllowed($host)) {
                        return false;
                    }

                    // Check external policy
                    if (!$this->allowExternal) {
                        // If we have a base host, compare
                        if ($baseHost !== null && !$this->isSameHost($host, $baseHost)) {
                            return false;
                        }
                        // If no base host, assume all absolute URLs are external
                        if ($baseHost === null) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    protected function isDomainDenied(string $host): bool
    {
        $host = strtolower($host);
        foreach ($this->deniedDomains as $denied) {
            if ($host === strtolower($denied) || str_ends_with($host, '.' . strtolower($denied))) {
                return true;
            }
        }

        return false;
    }

    protected function isDomainAllowed(string $host): bool
    {
        if ($this->allowedDomains === null) {
            return true;
        }

        $host = strtolower($host);
        foreach ($this->allowedDomains as $allowed) {
            if ($host === strtolower($allowed) || str_ends_with($host, '.' . strtolower($allowed))) {
                return true;
            }
        }

        return false;
    }

    protected function isSameHost(string $host1, string $host2): bool
    {
        return strtolower($host1) === strtolower($host2);
    }
}
