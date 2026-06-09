<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use PHPUnit\Framework\TestCase;

/**
 * Functional tests for the bin/djot CLI.
 *
 * These shell out to the actual script so the documented command-line surface
 * (convert subcommand, formats, safe modes, exit codes) stays in sync with
 * docs/reference/cli.md.
 */
class CliTest extends TestCase
{
    /**
     * Run bin/djot with the given arguments and stdin, capturing output.
     *
     * @param array<int, string> $args
     * @param string $stdin
     *
     * @return array{stdout: string, stderr: string, exit: int}
     */
    protected function runCli(array $args, string $stdin = ''): array
    {
        $bin = dirname(__DIR__, 2) . '/bin/djot';
        $command = array_merge([PHP_BINARY, $bin], $args);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        $this->assertIsResource($process);

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit];
    }

    public function testConvertStdinToHtml(): void
    {
        $result = $this->runCli(['convert', '-', '--format=html'], '*hi*');
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('<strong>hi</strong>', $result['stdout']);
    }

    public function testConvertDefaultFormatIsHtml(): void
    {
        $result = $this->runCli(['convert', '-'], '*hi*');
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('<strong>hi</strong>', $result['stdout']);
    }

    public function testFormatText(): void
    {
        $result = $this->runCli(['convert', '-', '--format=text'], '*hi*');
        $this->assertSame(0, $result['exit']);
        $this->assertStringNotContainsString('<strong>', $result['stdout']);
        $this->assertStringContainsString('hi', $result['stdout']);
    }

    public function testFormatMarkdown(): void
    {
        $result = $this->runCli(['convert', '-', '--format=markdown'], '*hi*');
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('**hi**', $result['stdout']);
    }

    public function testSafeModeStripsDangerousUrl(): void
    {
        $result = $this->runCli(['convert', '-', '--safe'], '[a](javascript:alert(1))');
        $this->assertSame(0, $result['exit']);
        $this->assertStringNotContainsString('javascript:', $result['stdout']);
    }

    public function testSafeStrictAlsoStripsStyle(): void
    {
        $result = $this->runCli(['convert', '-', '--safe=strict'], '[a](https://x){style="color:red"}');
        $this->assertSame(0, $result['exit']);
        $this->assertStringNotContainsString('style=', $result['stdout']);
    }

    public function testSafeDefaultKeepsStyle(): void
    {
        $result = $this->runCli(['convert', '-', '--safe'], '[a](https://x){style="color:red"}');
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('style=', $result['stdout']);
    }

    public function testInvalidFormatExitsWithCodeThree(): void
    {
        $result = $this->runCli(['convert', '-', '--format=xml'], '*hi*');
        $this->assertSame(3, $result['exit']);
        $this->assertStringContainsString('Invalid format', $result['stderr']);
    }

    public function testMissingFileExitsWithCodeTwo(): void
    {
        $result = $this->runCli(['convert', '/no/such/file.djot']);
        $this->assertSame(2, $result['exit']);
        $this->assertStringContainsString('File not found', $result['stderr']);
    }

    public function testUnknownOptionExitsWithCodeOne(): void
    {
        $result = $this->runCli(['convert', '-', '--bogus'], '*hi*');
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('Unknown option', $result['stderr']);
    }

    public function testUnknownSafeModeExitsWithCodeOne(): void
    {
        $result = $this->runCli(['convert', '-', '--safe=loose'], '*hi*');
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('Unknown safe mode', $result['stderr']);
    }

    public function testEmptyOutputValueExitsWithCodeOne(): void
    {
        $result = $this->runCli(['convert', '-', '--output='], '*hi*');
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('--output requires a filename', $result['stderr']);
    }

    public function testStdinPlusFileExitsWithCodeOne(): void
    {
        $result = $this->runCli(['convert', '-', '/some/file.djot'], '*hi*');
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('Multiple input files specified', $result['stderr']);
    }

    public function testFilePlusStdinExitsWithCodeOne(): void
    {
        $result = $this->runCli(['convert', '/some/file.djot', '-'], '*hi*');
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('Multiple input files specified', $result['stderr']);
    }

    public function testLegacyFlatStdinStillConverts(): void
    {
        $result = $this->runCli([], '*hi*');
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('<strong>hi</strong>', $result['stdout']);
    }

    public function testVersionSubcommand(): void
    {
        $result = $this->runCli(['version']);
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('djot-php version', $result['stdout']);
    }

    public function testHelpDocumentsConvert(): void
    {
        $result = $this->runCli(['--help']);
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('djot convert', $result['stdout']);
    }
}
