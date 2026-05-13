<?php

declare(strict_types=1);

namespace Djot\Test\Watch;

use Djot\Watch\FileWatcher;
use PHPUnit\Framework\TestCase;

class FileWatcherTest extends TestCase
{
    public function testFirstPollReportsNoChange(): void
    {
        $tmp = $this->makeFile("initial\n");
        $watcher = new FileWatcher([$tmp]);
        $this->assertFalse($watcher->poll());
        @unlink($tmp);
    }

    public function testDetectsMtimeChange(): void
    {
        $tmp = $this->makeFile("initial\n");
        $watcher = new FileWatcher([$tmp]);

        // Force mtime forward past second-resolution boundary.
        touch($tmp, time() + 2);
        clearstatcache(true, $tmp);

        $this->assertTrue($watcher->poll(), 'change detected after touch');
        $this->assertFalse($watcher->poll(), 'no change on subsequent poll');

        @unlink($tmp);
    }

    public function testDetectsChangeOnAnyTrackedFile(): void
    {
        $a = $this->makeFile("A\n");
        $b = $this->makeFile("B\n");
        $watcher = new FileWatcher([$a, $b]);

        touch($b, time() + 2);
        clearstatcache(true, $b);
        $this->assertTrue($watcher->poll());

        @unlink($a);
        @unlink($b);
    }

    public function testDetectsSameSecondReplaceViaSizeChange(): void
    {
        $tmp = $this->makeFile("first\n");
        $watcher = new FileWatcher([$tmp]);
        // Write different-length content without advancing mtime past one-second resolution.
        file_put_contents($tmp, "longer second content\n");
        clearstatcache(true, $tmp);
        $this->assertTrue($watcher->poll());
        @unlink($tmp);
    }

    private function makeFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fw_test_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return $path;
    }
}
