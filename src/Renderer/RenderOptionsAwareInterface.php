<?php

declare(strict_types=1);

namespace Djot\Renderer;

interface RenderOptionsAwareInterface
{
    public function setRenderOptions(RenderOptions $renderOptions): self;
}
