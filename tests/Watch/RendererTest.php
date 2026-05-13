<?php

declare(strict_types=1);

namespace Djot\Test\Watch;

use Djot\Watch\Renderer;
use PHPUnit\Framework\TestCase;

class RendererTest extends TestCase
{
    public function testRenderConvertsDjotToHtmlFragment(): void
    {
        $renderer = new Renderer();
        $html = $renderer->render('# Hello');
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('Hello', $html);
    }

    public function testRenderDocumentWrapsFragmentWithLayout(): void
    {
        $renderer = new Renderer();
        $html = $renderer->renderDocument('# Hello', cssPath: null);
        $this->assertStringContainsString('<!doctype html>', strtolower($html));
        $this->assertStringContainsString('/__assets/livereload.js', $html);
        $this->assertStringContainsString('/__assets/style.css', $html);
        $this->assertStringContainsString('<h1', $html);
    }

    public function testRenderDocumentEscapesTitleSource(): void
    {
        $renderer = new Renderer();
        $html = $renderer->renderDocument('paragraph', cssPath: null);
        $this->assertStringContainsString('<title>Djot Preview</title>', $html);
    }
}
