<?php

namespace XyloIsCoding\CoconutCms\Tests\Core\Error\Renderer;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use XyloIsCoding\CoconutCms\Core\Error\ErrorContext;
use XyloIsCoding\CoconutCms\Core\Error\Renderer\HtmlErrorRenderer;

final class HtmlErrorRendererTest extends TestCase
{
    private function context(): ErrorContext
    {
        return new ErrorContext(referenceId: 'abcd1234', requestMethod: 'GET', requestUri: '/tags/abc');
    }

    public function testDebugModeShowsFullDetails(): void
    {
        $output = (new HtmlErrorRenderer())->render(
            new RuntimeException('secret internal detail'),
            $this->context(),
            debug: true,
        );

        self::assertStringContainsString('RuntimeException', $output);
        self::assertStringContainsString('secret internal detail', $output);
        self::assertStringContainsString('abcd1234', $output);
        self::assertStringContainsString('<h1>', $output);
    }

    public function testProductionModeHidesDetailsButShowsReference(): void
    {
        $output = (new HtmlErrorRenderer())->render(
            new RuntimeException('secret internal detail'),
            $this->context(),
            debug: false,
        );

        self::assertStringNotContainsString('secret internal detail', $output);
        self::assertStringNotContainsString('RuntimeException', $output);
        self::assertStringContainsString('abcd1234', $output);
        self::assertStringContainsString('Something went wrong', $output);
    }

    public function testMessageIsHtmlEscaped(): void
    {
        $output = (new HtmlErrorRenderer())->render(
            new RuntimeException('<script>alert(1)</script>'),
            $this->context(),
            debug: true,
        );

        self::assertStringNotContainsString('<script>alert(1)</script>', $output);
        self::assertStringContainsString('&lt;script&gt;', $output);
    }
}
