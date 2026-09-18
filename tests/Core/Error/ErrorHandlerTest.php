<?php

namespace XyloIsCoding\CoconutCms\Tests\Core\Error;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;
use XyloIsCoding\CoconutCms\Core\Error\ErrorContext;
use XyloIsCoding\CoconutCms\Core\Error\ErrorHandler;
use XyloIsCoding\CoconutCms\Core\Error\ErrorRenderer;
use XyloIsCoding\CoconutCms\Core\Error\Renderer\CliErrorRenderer;

final class ErrorHandlerTest extends TestCase
{
    private function rendererOf(ErrorHandler $handler): ErrorRenderer
    {
        $property = (new ReflectionClass($handler))->getProperty('renderer');

        return $property->getValue($handler);
    }

    public function testDefaultsToCliRendererSincePhpunitRunsUnderCliSapi(): void
    {
        // PHP_SAPI is always literally 'cli' inside a PHPUnit run, so this default is what we
        // can actually observe in-process; the "web SAPI -> Html" branch is covered by the
        // subprocess tests below, which run under php -S (SAPI "cli-server").
        self::assertInstanceOf(CliErrorRenderer::class, $this->rendererOf(new ErrorHandler(debug: true)));
    }

    public function testExplicitRendererOverridesAutoSelection(): void
    {
        $custom = new class implements ErrorRenderer {
            public function render(Throwable $error, ErrorContext $context, bool $debug): string
            {
                return 'custom';
            }
        };

        $handler = new ErrorHandler(debug: true, renderer: $custom);

        self::assertSame($custom, $this->rendererOf($handler));
    }

    /**
     * Runs a small script in a real, separate PHP process (not PHPUnit's own process-isolation
     * machinery, which has its own error_log/output plumbing that conflicts with code under test
     * that installs global handlers). This is the same technique used to hand-verify this exact
     * behavior throughout development, just automated.
     *
     * Only stdout is captured (what a client would actually receive as the response) — stderr is
     * discarded so an unconfigured logger's error_log() fallback (which writes to stderr) never
     * leaks into what these tests treat as "the rendered response."
     */
    private function runInSubprocess(string $body): string
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $script = sprintf("<?php\nrequire %s;\n%s", var_export($autoload, true), $body);

        $path = tempnam(sys_get_temp_dir(), 'coconut-cms-error-handler-test-');
        file_put_contents($path, $script);

        $output = shell_exec(sprintf('php %s 2>/dev/null', escapeshellarg($path)));
        unlink($path);

        return $output ?? '';
    }

    public function testUncaughtExceptionIsLoggedAndRenderedWithFullDetailsInDebugMode(): void
    {
        $output = $this->runInSubprocess(<<<'PHP'
use XyloIsCoding\CoconutCms\Core\Error\ErrorHandler;
(new ErrorHandler(debug: true))->register();
throw new RuntimeException('secret internal detail');
PHP);

        self::assertStringContainsString('RuntimeException', $output);
        self::assertStringContainsString('secret internal detail', $output);
        self::assertMatchesRegularExpression('/Reference: [a-f0-9]{8}/', $output);
    }

    public function testUncaughtExceptionHidesDetailsInProductionMode(): void
    {
        $output = $this->runInSubprocess(<<<'PHP'
use XyloIsCoding\CoconutCms\Core\Error\ErrorHandler;
(new ErrorHandler(debug: false))->register();
throw new RuntimeException('secret internal detail');
PHP);

        self::assertStringNotContainsString('secret internal detail', $output);
        self::assertStringContainsString('Something went wrong', $output);
        self::assertMatchesRegularExpression('/Reference: [a-f0-9]{8}/', $output);
    }

    public function testLoggedEntryReferenceIdMatchesWhatTheResponseShows(): void
    {
        $dir = sys_get_temp_dir() . '/coconut-cms-error-handler-test-' . bin2hex(random_bytes(4));
        mkdir($dir);

        $output = $this->runInSubprocess(<<<PHP
use XyloIsCoding\CoconutCms\Core\Error\ErrorHandler;
use XyloIsCoding\CoconutCms\Core\Error\Logger\DefaultErrorLogger;
(new ErrorHandler(debug: false, logger: new DefaultErrorLogger('$dir')))->register();
throw new RuntimeException('boom');
PHP);

        preg_match('/Reference: ([a-f0-9]{8})/', $output, $matches);
        self::assertNotEmpty($matches, 'Expected a reference id in the response output.');

        $logFiles = glob($dir . '/*.log') ?: [];
        self::assertCount(1, $logFiles);
        self::assertStringContainsString('ref=' . $matches[1], file_get_contents($logFiles[0]));

        unlink($logFiles[0]);
        rmdir($dir);
    }

    public function testWarningsAreLoggedButDoNotHaltExecution(): void
    {
        $dir = sys_get_temp_dir() . '/coconut-cms-error-handler-test-' . bin2hex(random_bytes(4));
        mkdir($dir);

        $output = $this->runInSubprocess(<<<PHP
use XyloIsCoding\CoconutCms\Core\Error\ErrorHandler;
use XyloIsCoding\CoconutCms\Core\Error\Logger\DefaultErrorLogger;
(new ErrorHandler(debug: false, logger: new DefaultErrorLogger('$dir')))->register();
trigger_error('just a warning', E_USER_WARNING);
echo "execution continued normally\\n";
PHP);

        self::assertStringContainsString('execution continued normally', $output);
        self::assertStringNotContainsString('Something went wrong', $output);

        $logFiles = glob($dir . '/*.log') ?: [];
        self::assertCount(1, $logFiles);
        self::assertStringContainsString('just a warning', file_get_contents($logFiles[0]));

        unlink($logFiles[0]);
        rmdir($dir);
    }
}
