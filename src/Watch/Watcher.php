<?php

declare(strict_types=1);

namespace Djot\Watch;

class Watcher
{
    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        unset($argv);
        fwrite(STDERR, "djot-watch is not yet implemented in this build.\n");

        return 70;
    }
}
