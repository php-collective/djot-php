<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

final class Mention extends Link
{
    public function __construct(protected string $username, string $destination = '')
    {
        parent::__construct($destination);
    }

    public function getUsername(): string
    {
        return $this->username;
    }
}
