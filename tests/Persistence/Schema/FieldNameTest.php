<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

final class FieldNameTest extends TestCase
{
    public function testAcceptsAnOrdinaryName(): void {
        self::assertSame('piston_diameter', (string) FieldName::of('piston_diameter'));
    }

    /** @return array<string, array{string}> */
    public static function invalidNames(): array {
        return [
            'empty' => [''],
            'contains the embed separator' => ['piston:diameter'],
            'contains the traverse separator' => ['engine.horsepower'],
            'all digits, read as a collection index' => ['12'],
        ];
    }

    #[DataProvider('invalidNames')]
    public function testRejects(string $name): void {
        $this->expectException(InvalidArgumentException::class);

        FieldName::of($name);
    }
}
