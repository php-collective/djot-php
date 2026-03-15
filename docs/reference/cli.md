# CLI Tool

djot-php includes a command-line tool for converting Djot files.

## Installation

The CLI is available after installing the package:

```bash
composer require php-collective/djot
```

## Usage

### Basic Conversion

Convert a Djot file to HTML:

```bash
./vendor/bin/djot convert document.djot
```

### Output Formats

```bash
# HTML (default)
./vendor/bin/djot convert document.djot --format=html

# Plain text
./vendor/bin/djot convert document.djot --format=text

# Markdown
./vendor/bin/djot convert document.djot --format=markdown

# ANSI (colorized terminal output)
./vendor/bin/djot convert document.djot --format=ansi
```

### Output to File

```bash
./vendor/bin/djot convert document.djot -o output.html
./vendor/bin/djot convert document.djot --output=output.html
```

### Safe Mode

Enable safe mode for untrusted content:

```bash
./vendor/bin/djot convert document.djot --safe
./vendor/bin/djot convert document.djot --safe=strict
```

### Reading from STDIN

```bash
echo "Hello *world*" | ./vendor/bin/djot convert -
cat document.djot | ./vendor/bin/djot convert -
```

## Commands

### `convert`

Convert Djot to another format.

```
Usage:
  djot convert [options] <file>

Arguments:
  file                  Input file (use - for stdin)

Options:
  -o, --output=FILE     Output file (default: stdout)
  -f, --format=FORMAT   Output format: html, text, markdown, ansi
  --safe[=MODE]         Enable safe mode (default, strict)
  -h, --help            Display help
```

### `version`

Show version information:

```bash
./vendor/bin/djot version
```

## Examples

### Batch Conversion

Convert all `.djot` files in a directory:

```bash
for file in docs/*.djot; do
  ./vendor/bin/djot convert "$file" -o "${file%.djot}.html"
done
```

### Pipeline Usage

```bash
# Convert and pipe to a pager
./vendor/bin/djot convert README.djot --format=ansi | less -R

# Convert and copy to clipboard (macOS)
./vendor/bin/djot convert document.djot | pbcopy

# Convert and serve with PHP built-in server
./vendor/bin/djot convert document.djot > public/index.html
php -S localhost:8000 -t public
```

## Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | General error |
| 2 | File not found |
| 3 | Invalid format |
