<?php

namespace XyloIsCoding\CoconutCms\Core\Error\Logger;

use Throwable;
use XyloIsCoding\CoconutCms\Core\Error\ErrorContext;
use XyloIsCoding\CoconutCms\Core\Error\ErrorLogger;

class DefaultErrorLogger implements ErrorLogger
{
    private const string FILE_PREFIX = 'error-';
    private const string FILE_SUFFIX = '.log';

    /**
     * @param string|null $directory Directory to write daily-rotated log files into (one file
     *        per calendar day, named error-YYYY-MM-DD.log). Null falls back to PHP's own
     *        error_log() instead of file rotation.
     * @param int $retainDays How many days of rotated files to keep; older ones are pruned
     *        whenever a new entry is successfully written.
     */
    public function __construct(
        private readonly ?string $directory = null,
        private readonly int $retainDays = 14,
    ) {
    }

    public function log(Throwable $error, ErrorContext $context): void
    {
        $message = $this->format($error, $context);

        if ($this->directory === null || !$this->writeToDailyFile($message)) {
            error_log($message);
            return;
        }

        $this->pruneOldFiles();
    }

    private function writeToDailyFile(string $message): bool
    {
        $path = sprintf('%s/%s%s%s', $this->directory, self::FILE_PREFIX, date('Y-m-d'), self::FILE_SUFFIX);

        return @file_put_contents($path, $message, FILE_APPEND | LOCK_EX) !== false;
    }

    private function pruneOldFiles(): void
    {
        $cutoff = time() - ($this->retainDays * 86400);
        $pattern = sprintf('%s/%s*%s', $this->directory, self::FILE_PREFIX, self::FILE_SUFFIX);

        foreach (glob($pattern) ?: [] as $file) {
            $modifiedAt = @filemtime($file);

            if ($modifiedAt !== false && $modifiedAt < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function format(Throwable $error, ErrorContext $context): string
    {
        return sprintf(
            '[%s] [ref=%s] [%s %s] [%s] %s in %s:%d%s%s%s',
            date('Y-m-d H:i:s'),
            $context->referenceId,
            $context->requestMethod ?? 'CLI',
            $context->requestUri ?? '-',
            $error::class,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
            PHP_EOL,
            $error->getTraceAsString(),
            PHP_EOL,
        );
    }
}
