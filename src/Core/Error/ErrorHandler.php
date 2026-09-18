<?php

namespace XyloIsCoding\CoconutCms\Core\Error;

use ErrorException;
use Throwable;
use XyloIsCoding\CoconutCms\Core\Error\Logger\DefaultErrorLogger;
use XyloIsCoding\CoconutCms\Core\Error\Renderer\CliErrorRenderer;
use XyloIsCoding\CoconutCms\Core\Error\Renderer\HtmlErrorRenderer;

/**
 * Registers global handlers so nothing reaches the client as a raw PHP error page:
 * uncaught throwables and shutdown-only fatals (memory exhausted, timeouts) always
 * get logged and render either full details or a generic page depending on $debug;
 * ordinary warnings/notices/deprecations are logged and otherwise left alone.
 */
final class ErrorHandler
{
    private const array FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    private readonly ErrorRenderer $renderer;

    public function __construct(
        private readonly bool $debug,
        private readonly ErrorLogger $logger = new DefaultErrorLogger(),
        ?ErrorRenderer $renderer = null,
    ) {
        $this->renderer = $renderer ?? (PHP_SAPI === 'cli' ? new CliErrorRenderer() : new HtmlErrorRenderer());
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
        $context = ErrorContext::capture();
        $this->logger->log($error, $context);
        $this->respond($error, $context);
    }

    private function onError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $this->logger->log(new ErrorException($message, 0, $severity, $file, $line), ErrorContext::capture());

        return true;
    }

    private function onShutdown(): void
    {
        $error = error_get_last();

        if ($error === null || !in_array($error['type'], self::FATAL_TYPES, true)) {
            return;
        }

        $exception = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
        $context = ErrorContext::capture();
        $this->logger->log($exception, $context);
        $this->respond($exception, $context);
    }

    private function respond(Throwable $error, ErrorContext $context): void
    {
        if (!headers_sent()) {
            http_response_code(500);
        }

        echo $this->renderer->render($error, $context, $this->debug);
    }
}
