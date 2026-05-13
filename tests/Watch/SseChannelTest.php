<?php

declare(strict_types=1);

namespace Djot\Test\Watch;

use Djot\Watch\SseChannel;
use PHPUnit\Framework\TestCase;

class SseChannelTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sse_test_');
        self::assertNotFalse($tmp);
        $this->path = $tmp;
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testInitialSequenceIsZero(): void
    {
        $channel = new SseChannel($this->path);
        $this->assertSame(0, $channel->current());
    }

    public function testBumpIncrementsSequence(): void
    {
        $channel = new SseChannel($this->path);
        $channel->bump();
        $this->assertSame(1, $channel->current());
        $channel->bump();
        $this->assertSame(2, $channel->current());
    }

    public function testCurrentIsReadableFromAnotherInstance(): void
    {
        $producer = new SseChannel($this->path);
        $producer->bump();
        $producer->bump();

        $consumer = new SseChannel($this->path);
        $this->assertSame(2, $consumer->current());
    }
}
