<?php

declare(strict_types=1);

namespace Djot\Watch;

use Djot\DjotConverter;

class Renderer
{
    private DjotConverter $converter;

    public function __construct(?DjotConverter $converter = null)
    {
        $this->converter = $converter ?? new DjotConverter();
    }

    public function render(string $djot): string
    {
        return $this->converter->convert($djot);
    }

    public function renderDocument(string $djot, ?string $cssPath): string
    {
        $body = $this->render($djot);

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Djot Preview</title>
<link rel="stylesheet" href="/__assets/style.css">
</head>
<body>
{$body}
<script src="/__assets/livereload.js"></script>
</body>
</html>
HTML;
    }
}
