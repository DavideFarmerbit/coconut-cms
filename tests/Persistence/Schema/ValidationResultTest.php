<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use LogicException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\ValidationResult;

final class ValidationResultTest extends TestCase
{
    public function testValidIsValidAndHasNoError(): void {
        $result = ValidationResult::valid();

        self::assertTrue($result->isValid());
    }

    public function testInvalidStoresError(): void {
        $result = ValidationResult::invalid('too long');

        self::assertFalse($result->isValid());
        self::assertSame('too long', $result->error());
    }

    public function testErrorOnAValidResultThrows(): void {
        $this->expectException(LogicException::class);

        ValidationResult::valid()->error();
    }
}
