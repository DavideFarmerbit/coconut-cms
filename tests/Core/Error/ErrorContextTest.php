<?php

namespace XyloIsCoding\CoconutCms\Tests\Core\Error;

use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Core\Error\ErrorContext;

#[BackupGlobals(true)]
final class ErrorContextTest extends TestCase
{
    public function testCaptureGeneratesAnEightCharacterHexReferenceId(): void
    {
        $context = ErrorContext::capture();

        self::assertMatchesRegularExpression('/^[a-f0-9]{8}$/', $context->referenceId);
    }

    public function testCaptureGeneratesADifferentReferenceIdEachTime(): void
    {
        self::assertNotSame(ErrorContext::capture()->referenceId, ErrorContext::capture()->referenceId);
    }

    public function testCaptureReadsRequestMethodAndUriWhenPresent(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/tags/php';

        $context = ErrorContext::capture();

        self::assertSame('POST', $context->requestMethod);
        self::assertSame('/tags/php', $context->requestUri);
    }

    public function testCaptureFallsBackToNullWhenNoRequestInfoAvailable(): void
    {
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

        $context = ErrorContext::capture();

        self::assertNull($context->requestMethod);
        self::assertNull($context->requestUri);
    }
}
