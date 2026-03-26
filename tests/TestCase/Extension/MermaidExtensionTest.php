<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Extension;

use Djot\DjotConverter;
use Djot\Extension\MermaidExtension;
use PHPUnit\Framework\TestCase;

class MermaidExtensionTest extends TestCase
{
    public function testBasicFlowchart(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension());

        $djot = <<<'DJOT'
``` mermaid
graph TD;
    A-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertStringContainsString('graph TD;', $html);
        $this->assertStringContainsString('A--&gt;B;', $html);
        $this->assertStringContainsString('</pre>', $html);
    }

    public function testSequenceDiagram(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension());

        $djot = <<<'DJOT'
``` mermaid
sequenceDiagram
    Alice->>Bob: Hello Bob
    Bob-->>Alice: Hi Alice
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertStringContainsString('sequenceDiagram', $html);
        $this->assertStringContainsString('Alice', $html);
    }

    public function testNonMermaidCodeBlockUnaffected(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension());

        $djot = <<<'DJOT'
``` php
echo "Hello World";
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('class="language-php"', $html);
        $this->assertStringNotContainsString('class="mermaid"', $html);
    }

    public function testCodeBlockWithoutLanguageUnaffected(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension());

        $djot = <<<'DJOT'
```
plain code
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringNotContainsString('class="mermaid"', $html);
        $this->assertStringContainsString('plain code', $html);
    }

    public function testCustomTag(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension(tag: 'div'));

        $djot = <<<'DJOT'
``` mermaid
graph LR;
    A-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<div class="mermaid">', $html);
        $this->assertStringContainsString('</div>', $html);
        $this->assertStringNotContainsString('<pre', $html);
    }

    public function testCustomCssClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension(cssClass: 'diagram'));

        $djot = <<<'DJOT'
``` mermaid
graph TD;
    A-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('class="diagram"', $html);
        $this->assertStringNotContainsString('class="mermaid"', $html);
    }

    public function testWrapInFigure(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension(wrapInFigure: true));

        $djot = <<<'DJOT'
``` mermaid
pie title Pets
    "Dogs" : 45
    "Cats" : 30
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<figure class="mermaid-figure">', $html);
        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertStringContainsString('</figure>', $html);
    }

    public function testCustomFigureClass(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension(
            wrapInFigure: true,
            figureClass: 'diagram-wrapper',
        ));

        $djot = <<<'DJOT'
``` mermaid
graph TD;
    A-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<figure class="diagram-wrapper">', $html);
    }

    public function testPreservesAdditionalClasses(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension());

        $djot = <<<'DJOT'
{.custom-diagram .large}
``` mermaid
graph TD;
    A-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('class="mermaid custom-diagram large"', $html);
    }

    public function testPreservesCustomAttributes(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension());

        $djot = <<<'DJOT'
{#my-diagram data-theme="dark"}
``` mermaid
graph TD;
    A-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('id="my-diagram"', $html);
        $this->assertStringContainsString('data-theme="dark"', $html);
    }

    public function testEscapesHtmlInDiagram(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension());

        $djot = <<<'DJOT'
``` mermaid
graph TD;
    A["<script>alert('xss')</script>"]-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testClassDiagram(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension());

        $djot = <<<'DJOT'
``` mermaid
classDiagram
    Animal <|-- Duck
    Animal <|-- Fish
    Animal : +int age
    Animal : +String gender
    Animal: +isMammal()
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertStringContainsString('classDiagram', $html);
        $this->assertStringContainsString('Animal', $html);
    }

    public function testGanttChart(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension());

        $djot = <<<'DJOT'
``` mermaid
gantt
    title A Gantt Diagram
    dateFormat YYYY-MM-DD
    section Section
        A task          :a1, 2024-01-01, 30d
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertStringContainsString('gantt', $html);
    }

    public function testErDiagram(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension());

        $djot = <<<'DJOT'
``` mermaid
erDiagram
    CUSTOMER ||--o{ ORDER : places
    ORDER ||--|{ LINE-ITEM : contains
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertStringContainsString('erDiagram', $html);
        $this->assertStringContainsString('CUSTOMER', $html);
    }

    public function testGitGraph(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension());

        $djot = <<<'DJOT'
``` mermaid
gitGraph
    commit
    commit
    branch develop
    checkout develop
    commit
    commit
    checkout main
    merge develop
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertStringContainsString('gitGraph', $html);
    }

    public function testStateDiagram(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension());

        $djot = <<<'DJOT'
``` mermaid
stateDiagram-v2
    [*] --> Still
    Still --> [*]
    Still --> Moving
    Moving --> Still
    Moving --> Crash
    Crash --> [*]
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertStringContainsString('stateDiagram-v2', $html);
    }

    public function testMultipleMermaidBlocks(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension());

        $djot = <<<'DJOT'
First diagram:

``` mermaid
graph TD;
    A-->B;
```

Second diagram:

``` mermaid
graph LR;
    C-->D;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertSame(2, substr_count($html, '<pre class="mermaid">'));
        $this->assertStringContainsString('A--&gt;B;', $html);
        $this->assertStringContainsString('C--&gt;D;', $html);
    }

    public function testDivTagWithFigure(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new MermaidExtension(
            tag: 'div',
            wrapInFigure: true,
        ));

        $djot = <<<'DJOT'
``` mermaid
graph TD;
    A-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<figure class="mermaid-figure">', $html);
        $this->assertStringContainsString('<div class="mermaid">', $html);
        $this->assertStringContainsString('</div>', $html);
        $this->assertStringContainsString('</figure>', $html);
    }
}
