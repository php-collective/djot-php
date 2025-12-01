<?php

declare(strict_types=1);

namespace Djot\Exception;

use Djot\ProfileViolation;
use RuntimeException;

/**
 * Exception thrown when profile violations occur in ACTION_ERROR mode
 */
class ProfileViolationException extends RuntimeException
{
    /**
     * @param list<\Djot\ProfileViolation> $violations
     */
    public function __construct(public readonly array $violations)
    {
        $messages = array_map(
            fn (ProfileViolation $v) => $v->getMessage(),
            $violations,
        );
        parent::__construct('Profile violations: ' . implode('; ', $messages));
    }
}
