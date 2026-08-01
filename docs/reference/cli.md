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

### Section Wrapping

Disable document heading section wrappers in HTML output:

```bash
./vendor/bin/djot convert document.djot --no-sections
```

This keeps heading ids inline and does not affect the footnote endnotes section.

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
  --no-sections         Do not wrap document headings in sections (HTML only)
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

## Live Preview: `djot-watch`

A companion long-running command that serves a live-reloading HTML preview of a `.djot` file in your browser. Useful while drafting in any editor (Vim, Helix, VS Code, Zed, Sublime — anything).

### Usage

```bash
./vendor/bin/djot-watch path/to/file.djot
```

This starts a local HTTP server on `http://127.0.0.1:8765/`, opens the URL in your default browser, and re-renders the file on every save. The browser tab refreshes automatically via Server-Sent Events.

Press `Ctrl+C` to stop.

### Flags

| Flag | Description |
|------|-------------|
| `-p`, `--port PORT` | HTTP port (default `8765`; auto-bumps up to `+10` if taken). |
| `--host HOST` | Bind host (default `127.0.0.1`). |
| `--no-open` | Do not launch the browser on startup. |
| `--css FILE` | Path to a custom CSS file served at `/__assets/style.css`. |
| `-v`, `--version` | Print version. |
| `-h`, `--help` | Print help text. |

### Custom Styling

The watcher ships a minimal default stylesheet (system fonts, sensible spacing, dark-mode aware). Override with `--css`:

```bash
./vendor/bin/djot-watch post.djot --css ./my-preview.css
```

### Editor Integration

The watcher is editor-agnostic — it just watches the file you give it. Bind a key or task in your editor to run `./vendor/bin/djot-watch ${FILE}` so you can fire up the preview without leaving the editor. For Zed, see the [zed-djot extension README](https://github.com/php-collective/zed-djot) for a `tasks.json` snippet.

### How It Works

`djot-watch` boots a long-lived PHP process that:

1. Renders your `.djot` file via the same `DjotConverter` used by `bin/djot`.
2. Spawns `php -S` on the chosen port with a small router script.
3. Polls the file for `(mtime, size)` changes every 250 ms; pushes a Server-Sent Events `reload` event when something changes.
4. Injects a tiny JS client into the served HTML that reloads on the SSE event.

No daemon, no config file, no global state. Just the binary and your file.
