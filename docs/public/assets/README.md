# Djot Syntax Highlighting

This folder contains demo files for Djot syntax highlighting.

## Grammars

Djot syntax highlighting grammars are maintained in the [djot-grammars](https://github.com/php-collective/djot-grammars) repository, which provides:

- **TextMate grammar** — For Shiki, VS Code, and TextMate-compatible editors
- **highlight.js grammar** — For highlight.js integration
- **Prism.js grammar** — For Prism.js integration

### Installation

```bash
npm install djot-grammars
```

### highlight.js Usage

```html
<!-- Load highlight.js -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>

<!-- Load the Djot language definition -->
<script src="node_modules/djot-grammars/highlightjs/djot.js"></script>

<!-- Initialize highlighting -->
<script>hljs.highlightAll();</script>
```

## Demo

Open `hljs-demo.html` in a browser to see the highlight.js grammar in action.
