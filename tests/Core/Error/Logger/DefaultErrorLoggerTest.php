<?php

namespace XyloIsCoding\CoconutCms\Tests\Core\Error\Logger;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use XyloIsCoding\CoconutCms\Core\Error\ErrorContext;
use XyloIsCoding\CoconutCms\Core\Error\Logger\DefaultErrorLogger;

final class DefaultErrorLoggerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/coconut-cms-logger-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function context(): ErrorContext
    {
        return new ErrorContext(referenceId: 'abcd1234', requestMethod: 'GET', requestUri: '/tags/abc');
    }

    /**
     * PHPUnit points error_log at its own per-test temp file right before invoking the test
     * method (for its own risky-test detection), overriding anything set in setUp(). So the
     * redirect has to happen inside the test body itself to actually take effect, and must be
     * restored before the method returns.
     */
    private function withErrorLogRedirectedTo(string $path, callable $callback): void
    {
        $original = ini_set('error_log', $path);

        try {
            $callback();
        } finally {
            ini_set('error_log', $original);
        }
    }

    public function testWritesToTodaysDatedFileWithFullContext(): void
    {
        $logger = new DefaultErrorLogger($this->dir);

        $logger->log(new RuntimeException('boom'), $this->context());

        $expectedFile = $this->dir . '/error-' . date('Y-m-d') . '.log';
        self::assertFileExists($expectedFile);

        $contents = file_get_contents($expectedFile);
        self::assertStringContainsString('[ref=abcd1234]', $contents);
        self::assertStringContainsString('[GET /tags/abc]', $contents);
        self::assertStringContainsString('[RuntimeException]', $contents);
        self::assertStringContainsString('boom', $contents);
    }

    public function testAppendsMultipleEntriesToTheSameDailyFile(): void
    {
        $logger = new DefaultErrorLogger($this->dir);

        $logger->log(new RuntimeException('first'), $this->context());
        $logger->log(new RuntimeException('second'), $this->context());

        $contents = file_get_contents($this->dir . '/error-' . date('Y-m-d') . '.log');
        self::assertStringContainsString('first', $contents);
        self::assertStringContainsString('second', $contents);
    }

    public function testPrunesFilesOlderThanRetentionButKeepsRecentOnes(): void
    {
        $old = $this->dir . '/error-2020-01-01.log';
        $recent = $this->dir . '/error-2020-06-01.log';
        file_put_contents($old, 'ancient');
        file_put_contents($recent, 'recent');
        touch($old, time() - (30 * 86400));
        touch($recent, time() - (2 * 86400));

        (new DefaultErrorLogger($this->dir, retainDays: 14))->log(new RuntimeException('trigger'), $this->context());

        self::assertFileDoesNotExist($old);
        self::assertFileExists($recent);
    }

    public function testFallsBackToErrorLogWhenNoDirectoryGiven(): void
    {
        $fallbackLog = $this->dir . '.php-error-log';

        $this->withErrorLogRedirectedTo($fallbackLog, function () {
            (new DefaultErrorLogger())->log(new RuntimeException('no directory'), $this->context());
        });

        self::assertFileExists($fallbackLog);
        self::assertStringContainsString('no directory', file_get_contents($fallbackLog));
        self::assertSame([], glob($this->dir . '/*'));

        unlink($fallbackLog);
    }

    public function testFallsBackToErrorLogWhenDirectoryIsNotWritable(): void
    {
        $fallbackLog = $this->dir . '.php-error-log';

        $this->withErrorLogRedirectedTo($fallbackLog, function () {
            (new DefaultErrorLogger('/no/such/directory/at/all'))->log(
                new RuntimeException('should not throw'),
                $this->context(),
            );
        });

        self::assertFileExists($fallbackLog);
        self::assertStringContainsString('should not throw', file_get_contents($fallbackLog));

        unlink($fallbackLog);
    }
}
