# Interactive Playground

Try djot-php directly in your browser. This playground uses the live [sandbox API](https://sandbox.dereuromark.de/sandbox/djot) to convert Djot markup using the actual PHP library.

<DjotPlayground />

## How It Works

The playground sends your Djot input to the sandbox API, which processes it using djot-php and returns the HTML output. This means you're testing with the real library, not a JavaScript approximation.

## Features to Try

### Basic Formatting

```djot
_emphasis_ and *strong* and _*both*_

H~2~O for subscript, 2^10^ for superscript

{=highlighted=} and {+inserted+} and {-deleted-}
```

### Links and Images

```djot
[Link text](https://example.com)

![Alt text](https://via.placeholder.com/150)
```

### Code Blocks

````djot
``` php
$converter = new DjotConverter();
echo $converter->convert($djot);
```
````

### Tables

```djot
| Name    | Role       |
|---------|------------|
| Alice   | Developer  |
| Bob     | Designer   |
```

### Extensions

Enable extensions above to try features like:

- **Autolink**: Automatically link URLs like https://example.com
- **Smart Quotes**: Convert "quotes" to "curly quotes"
- **Table of Contents**: Generate a TOC from headings
- **Mentions**: Convert @username to profile links

## More Resources

- [Full Syntax Reference](/guide/syntax)
- [Extensions Documentation](/extensions/)
- [Full Sandbox](https://sandbox.dereuromark.de/sandbox/djot) with more options
