# Video Embeds

This guide shows how to embed videos from YouTube, Vimeo, and 50+ other providers using the [dereuromark/media-embed](https://github.com/dereuromark/media-embed) library.

## Installation

```bash
composer require dereuromark/media-embed
```

## Basic Integration

Register an event listener that transforms video divs:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Div;
use MediaEmbed\MediaEmbed;

$converter = new DjotConverter();
$mediaEmbed = new MediaEmbed();

$converter->on('render.div', function (RenderEvent $event) use ($mediaEmbed): void {
    $node = $event->getNode();
    if (!$node instanceof Div) {
        return;
    }

    // Check for 'video' class
    $classes = preg_split('/\s+/', trim((string)$node->getAttribute('class')));
    if (!in_array('video', $classes, true)) {
        return;
    }

    // Extract URL from div content
    $url = trim(strip_tags($event->getChildrenHtml()));
    $object = $mediaEmbed->parseUrl($url);

    if ($object === null) {
        return; // Not a recognized video URL
    }

    $html = '<figure class="video-embed">' . "\n";
    $html .= $object->getEmbedCode();
    $html .= "</figure>\n";

    $event->setHtml($html);
});
```

## Usage

```djot
::: video
https://www.youtube.com/watch?v=dQw4w9WgXcQ
:::
```

Output:

```html
<figure class="video-embed">
  <iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"
          allowfullscreen></iframe>
</figure>
```

## Supported Providers

media-embed supports 50+ providers including:

- YouTube, Vimeo, Dailymotion
- Twitch, Kick
- TikTok, Instagram
- SoundCloud, Spotify
- And many more...

See the [full provider list](https://github.com/dereuromark/media-embed#supported-providers).

## Customizing the Embed

### Set iframe dimensions

```php
$object = $mediaEmbed->parseUrl($url);
$object->setWidth(800);
$object->setHeight(450);
$html = $object->getEmbedCode();
```

### Add custom attributes

```php
$object = $mediaEmbed->parseUrl($url);
$object->setAttribute('loading', 'lazy');
$object->setAttribute('referrerpolicy', 'no-referrer');
$html = $object->getEmbedCode();
```

### Privacy-enhanced mode

For YouTube, use the privacy-enhanced domain:

```php
$html = $object->getEmbedCode();
$html = str_replace('youtube.com', 'youtube-nocookie.com', $html);
```

## Responsive Wrapper

Add CSS for responsive 16:9 video embeds:

```css
.video-embed {
  position: relative;
  padding-bottom: 56.25%; /* 16:9 */
  height: 0;
  overflow: hidden;
}

.video-embed iframe {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  border: 0;
}
```

## As a Reusable Extension

If you use this pattern frequently, wrap it in an extension class:

```php
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Extension\ExtensionInterface;
use Djot\Node\Block\Div;
use MediaEmbed\MediaEmbed;

class VideoExtension implements ExtensionInterface
{
    public function __construct(
        protected ?MediaEmbed $mediaEmbed = null,
        protected string $figureClass = 'video-embed',
        protected bool $lazy = true,
    ) {
        $this->mediaEmbed ??= new MediaEmbed();
    }

    public function register(DjotConverter $converter): void
    {
        $converter->on('render.div', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof Div) {
                return;
            }

            if (!$this->hasClass($node, 'video')) {
                return;
            }

            $url = trim(strip_tags($event->getChildrenHtml()));
            $object = $this->mediaEmbed->parseUrl($url);

            if ($object === null) {
                return;
            }

            if ($this->lazy) {
                $object->setAttribute('loading', 'lazy');
            }

            $html = '<figure class="' . $this->escape($this->figureClass) . "\">\n";
            $html .= $object->getEmbedCode();
            $html .= "</figure>\n";

            $event->setHtml($html);
        });
    }

    protected function hasClass(Div $node, string $className): bool
    {
        $classes = preg_split('/\s+/', trim((string)$node->getAttribute('class')));

        return is_array($classes) && in_array($className, $classes, true);
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
```

Usage:

```php
$converter = new DjotConverter();
$converter->addExtension(new VideoExtension());
```

## Audio Embeds

The same pattern works for audio providers (SoundCloud, Spotify):

```djot
::: audio
https://soundcloud.com/artist/track-name
:::
```

```php
$converter->on('render.div', function (RenderEvent $event) use ($mediaEmbed): void {
    // Same logic, check for 'audio' class instead
});
```
