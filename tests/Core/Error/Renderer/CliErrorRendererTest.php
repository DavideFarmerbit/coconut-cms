<?php

namespace XyloIsCoding\CoconutCms\Tests\Core\Error\Renderer;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use XyloIsCoding\CoconutCms\Core\Error\ErrorContext;
use XyloIsCoding\CoconutCms\Core\Error\Renderer\CliErrorRenderer;

final class CliErrorRendererTest extends TestCase
{
    private function context(): ErrorContext
    {
        return new ErrorContext(referenceId: 'abcd1234', requestMethod: 'GET', requestUri: '/tags/abc');
    }

    public function testDebugModeShowsFullDetailsAsPlainText(): void
    {
        $output = (new CliErrorRenderer())->render(
            new RuntimeException('secret internal detail'),
            $this->context(),
            debug: true,
        );

        self::assertStringContainsString('RuntimeException', $output);
        self::assertStringContainsString('secret internal detail', $output);
        self::assertStringContainsString('abcd1234', $output);
        self::assertStringNotContainsString('<', $output);
    }

    public function testProductionModeHidesDetailsButShowsReference(): void
    {
        $output = (new CliErrorRenderer())->render(
            new RuntimeException('secret internal detail'),
            $this->context(),
            debug: false,
        );

        self::assertStringNotContainsString('secret internal detail', $output);
        self::assertStringContainsString('abcd1234', $output);
        self::assertStringContainsString('Something went wrong', $output);
        self::assertStringNotContainsString('<', $output);
    }
}
