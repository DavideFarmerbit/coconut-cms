<?php

namespace XyloIsCoding\CoconutCms\Core\Error;

use ErrorException;
use Throwable;

/**
 * Registers global handlers so nothing reaches the client as a raw PHP error page:
 * uncaught throwables and shutdown-only fatals (memory exhausted, timeouts) always
 * get logged and render either full details or a generic page depending on $debug;
 * ordinary warnings/notices/deprecations are logged and otherwise left alone.
 */
final class ErrorHandler
{
    private const array FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    public function __construct(
        private readonly bool $debug,
        private readonly ErrorLogger $logger = new DefaultErrorLogger(),
    ) {
    }

    /*================================================================================================================*/
    // Interface

    public function register(): void
    {
        ini_set('display_errors', '0');

        set_exception_handler($this->onException(...));
        set_error_handler($this->onError(...));
        register_shutdown_function($this->onShutdown(...));
    }

    // ~Interface
    /*================================================================================================================*/

    private function onException(Throwable $error): void
    {
        $this->logger->log($error);
        $this->respond($error);
    }

    private function onError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $this->logger->log(new ErrorException($message, 0, $severity, $file, $line));

        return true;
    }

    private function onShutdown(): void
    {
        $error = error_get_last();

        if ($error === null || !in_array($error['type'], self::FATAL_TYPES, true)) {
            return;
        }

        $exception = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
        $this->logger->log($exception);
        $this->respond($exception);
    }

    private function respond(Throwable $error): void
    {
        if (!headers_sent()) {
            http_response_code(500);
        }

        if ($this->debug) {
            printf(
                '<h1>%s</h1><p>%s in %s:%d</p><pre>%s</pre>',
                htmlspecialchars($error::class),
                htmlspecialchars($error->getMessage()),
                htmlspecialchars($error->getFile()),
                $error->getLine(),
                htmlspecialchars($error->getTraceAsString()),
            );

            return;
        }

        echo '<h1>Something went wrong</h1><p>Please try again later.</p>';
    }
}
