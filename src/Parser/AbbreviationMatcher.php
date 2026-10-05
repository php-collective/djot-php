<?php

declare(strict_types=1);

namespace Djot\Parser;

use InvalidArgumentException;
use LengthException;

/**
 * Match literal terms at PCRE word boundaries without a dictionary-sized regex.
 *
 * @internal
 */
final class AbbreviationMatcher
{
    /**
     * @var int
     */
    private const BOUNDARY = 256;

    /**
     * @var int
     */
    private const MAX_STATES = 131072;

    /**
     * @var array<int, int>
     */
    private array $edges = [];

    /**
     * @var array<int, int>
     */
    private array $firstChildren = [-1];

    /**
     * @var array<int, int>
     */
    private array $siblings = [-1];

    /**
     * @var array<int, int>
     */
    private array $symbols = [0];

    /**
     * @var array<int, int>
     */
    private array $failures = [0];

    /**
     * @var array<int, string>
     */
    private array $terms = [];

    /**
     * @param array<string> $keys
     *
     * @throws \InvalidArgumentException
     * @throws \LengthException
     */
    public function __construct(array $keys)
    {
        foreach ($keys as $key) {
            if (strlen($key) + 2 > self::MAX_STATES) {
                throw new LengthException('Abbreviation matcher state budget exceeded.');
            }
            $boundaries = self::boundaries($key);
            if ($boundaries === false) {
                throw new InvalidArgumentException('Abbreviation keys must be valid UTF-8.');
            }
            $state = $this->edge(0, self::BOUNDARY);
            for ($at = strlen($key) - 1; $at >= 0; $at--) {
                $state = $this->edge($state, ord($key[$at]));
                if ($at > 0 && $boundaries[$at] === "\1") {
                    $state = $this->edge($state, self::BOUNDARY);
                }
            }
            $state = $this->edge($state, self::BOUNDARY);
            $this->terms[$state] = $key;
        }

        $queue = [];
        for ($child = $this->firstChildren[0]; $child !== -1; $child = $this->siblings[$child]) {
            $queue[] = $child;
        }
        for ($cursor = 0; isset($queue[$cursor]); $cursor++) {
            $state = $queue[$cursor];
            for ($child = $this->firstChildren[$state]; $child !== -1; $child = $this->siblings[$child]) {
                $symbol = $this->symbols[$child];
                $failure = $this->failures[$state];
                while ($failure !== 0 && !isset($this->edges[$failure * 257 + $symbol])) {
                    $failure = $this->failures[$failure];
                }
                $failure = $this->edges[$failure * 257 + $symbol] ?? 0;
                $this->failures[$child] = $failure;
                if (isset($this->terms[$failure]) && strlen($this->terms[$failure]) > strlen($this->terms[$child] ?? '')) {
                    $this->terms[$child] = $this->terms[$failure];
                }
                $queue[] = $child;
            }
        }
        $this->firstChildren = [];
        $this->siblings = [];
        $this->symbols = [];
    }

    private function edge(int $state, int $symbol): int
    {
        $index = $state * 257 + $symbol;
        if (!isset($this->edges[$index])) {
            $child = count($this->failures);
            if ($child >= self::MAX_STATES) {
                throw new LengthException('Abbreviation matcher state budget exceeded.');
            }
            $this->edges[$index] = $child;
            $this->siblings[$child] = $this->firstChildren[$state];
            $this->firstChildren[$state] = $child;
            $this->firstChildren[$child] = -1;
            $this->symbols[$child] = $symbol;
            $this->failures[$child] = 0;
        }

        return $this->edges[$index];
    }

    /**
     * @return array<string>|false
     */
    public function split(string $text): array|false
    {
        $boundaries = self::boundaries($text);
        if ($boundaries === false) {
            return false;
        }
        $found = [];
        $state = 0;
        for ($at = strlen($text); $at >= 0; $at--) {
            if ($boundaries[$at] === "\1") {
                $state = $this->advance($state, self::BOUNDARY);
                if (isset($this->terms[$state])) {
                    $found[$at] = $this->terms[$state];
                }
            }
            if ($at > 0) {
                $state = $this->advance($state, ord($text[$at - 1]));
            }
        }
        $parts = [];
        $cursor = 0;
        for ($at = 0, $length = strlen($text); $at < $length; $at++) {
            if ($at < $cursor || !isset($found[$at])) {
                continue;
            }
            if ($at > $cursor) {
                $parts[] = substr($text, $cursor, $at - $cursor);
            }
            $parts[] = $found[$at];
            $cursor = $at + strlen($found[$at]);
        }
        if ($cursor < strlen($text)) {
            $parts[] = substr($text, $cursor);
        }

        return $parts;
    }

    /**
     * @return string|false
     */
    private static function boundaries(string $text): string|false
    {
        if (preg_match('//u', $text) !== 1) {
            return false;
        }
        $positions = str_repeat("\0", strlen($text) + 1);
        $previous = false;
        $length = strlen($text);
        for ($at = 0; $at < $length;) {
            $byte = ord($text[$at]);
            $width = $byte < 128 ? 1 : ($byte < 224 ? 2 : ($byte < 240 ? 3 : 4));
            $word = $byte < 128
                ? ($byte >= 48 && $byte <= 57) || ($byte >= 65 && $byte <= 90)
                    || ($byte >= 97 && $byte <= 122) || $byte === 95
                : preg_match('/\\w/u', substr($text, $at, $width)) === 1;
            if ($word !== $previous) {
                $positions[$at] = "\1";
            }
            $previous = $word;
            $at += $width;
        }
        if ($previous) {
            $positions[$length] = "\1";
        }

        return $positions;
    }

    private function advance(int $state, int $symbol): int
    {
        while ($state !== 0 && !isset($this->edges[$state * 257 + $symbol])) {
            $state = $this->failures[$state];
        }

        return $this->edges[$state * 257 + $symbol] ?? 0;
    }
}
