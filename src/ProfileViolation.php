<?php

declare(strict_types=1);

namespace Djot;

/**
 * Represents a profile violation during filtering
 */
class ProfileViolation
{
    public function __construct(
        public readonly string $nodeType,
        public readonly string $reason,
        public readonly ?string $reasonDescription = null,
    ) {
    }

    public function getMessage(): string
    {
        $msg = "'{$this->nodeType}' is not allowed: {$this->reason}";
        if ($this->reasonDescription !== null) {
            $msg .= " ({$this->reasonDescription})";
        }

        return $msg;
    }
}
