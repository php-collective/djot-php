<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

final class Mention extends Link
{
    /**
     * @var string
     */
    public const KIND_MENTION = 'mention';

    /**
     * @var string
     */
    public const KIND_TAG = 'tag';

    public function __construct(
        protected string $name,
        string $destination = '',
        protected string $kind = self::KIND_MENTION,
    ) {
        parent::__construct($destination);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getKind(): string
    {
        return $this->kind;
    }
}
