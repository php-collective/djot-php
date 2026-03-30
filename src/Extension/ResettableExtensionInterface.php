<?php

declare(strict_types=1);

namespace Djot\Extension;

/**
 * Optional extension lifecycle hook for clearing per-render state.
 */
interface ResettableExtensionInterface extends ExtensionInterface
{
    public function clear(): void;
}
