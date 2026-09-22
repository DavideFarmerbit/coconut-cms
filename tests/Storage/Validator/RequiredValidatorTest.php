<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Validator;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\Validator\RequiredValidator;

final class RequiredValidatorTest extends TestCase
{
    public function testRejectsNullAndEmptyString(): void
    {
        $validator = new RequiredValidator();

        self::assertFalse($validator->validate(null));
        self::assertFalse($validator->validate(''));
    }

    public function testAcceptsAnyOtherValue(): void
    {
        $validator = new RequiredValidator();

        self::assertTrue($validator->validate('x'));
        self::assertTrue($validator->validate(0));
        self::assertTrue($validator->validate(false));
    }
}
