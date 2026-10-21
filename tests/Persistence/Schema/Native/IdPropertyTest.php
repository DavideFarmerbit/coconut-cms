<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Native;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Id;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\IdProperty;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;

#[Entity]
class IdPropertyArticle
{
    #[Id]
    private ?string $id = null;

    public function id(): ?string {
        return $this->id;
    }
}

#[Entity]
final class IdPropertyNews extends IdPropertyArticle
{
}

#[Entity]
final class IdPropertyNone
{
}

#[Entity]
final class IdPropertyTwice extends IdPropertyArticle
{
    #[Id]
    private ?string $otherId = null;
}

#[Entity]
final class IdPropertyNotNullable
{
    #[Id]
    private string $id = '';
}

#[Entity]
final class IdPropertyNotAString
{
    #[Id]
    private ?int $id = null;
}

#[Entity]
final class IdPropertyNotDefaultingToNull
{
    #[Id]
    private ?string $id = 'fixed';
}

#[Entity]
final class IdPropertyWithoutDefault
{
    #[Id]
    private ?string $id;

    public function __construct() {
        $this->id = null;
    }
}

#[Entity]
final class IdPropertyStatic
{
    #[Id]
    private static ?string $id = null;
}

#[Entity]
final class IdPropertyAlsoAField
{
    #[Id]
    #[Scalar(ScalarType::String)]
    private ?string $id = null;
}

final class IdPropertyTest extends TestCase
{
    public function testAClassWithoutAnIdPropertyHasNone(): void {
        self::assertNull(IdProperty::of(IdPropertyNone::class));
    }

    public function testTheIdPropertyIsFound(): void {
        self::assertSame('id', IdProperty::of(IdPropertyArticle::class)?->getName());
    }

    public function testAnInheritedIdPropertyIsFoundToo(): void {
        self::assertSame(IdPropertyArticle::class, IdProperty::of(IdPropertyNews::class)?->getDeclaringClass()->getName());
    }

    public function testAssignFillsTheIdProperty(): void {
        $news = new IdPropertyNews();

        IdProperty::assign($news, 'some-id');

        self::assertSame('some-id', $news->id());
    }

    public function testAssignOnAClassWithoutAnIdPropertyDoesNothing(): void {
        $none = new IdPropertyNone();

        IdProperty::assign($none, 'some-id');

        self::assertEquals(new IdPropertyNone(), $none);
    }

    public function testTwoIdPropertiesInOneChainAreRejected(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has two #[Id] properties');

        IdProperty::of(IdPropertyTwice::class);
    }

    /** @return array<string, array{class-string, string}> */
    public static function wronglyShapedIds(): array {
        return [
            'not nullable' => [IdPropertyNotNullable::class, 'is not typed ?string'],
            'not a string' => [IdPropertyNotAString::class, 'is not typed ?string'],
            'defaulting to a value' => [IdPropertyNotDefaultingToNull::class, 'does not default to null'],
            'without a default' => [IdPropertyWithoutDefault::class, 'does not default to null'],
            'static' => [IdPropertyStatic::class, 'is static'],
            'also a field' => [IdPropertyAlsoAField::class, 'is also a field'],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('wronglyShapedIds')]
    public function testAWronglyShapedIdPropertyIsRejected(string $class, string $problem): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($problem);

        IdProperty::of($class);
    }

    public function testTheReaderRejectsAWronglyShapedIdPropertyAtCompileTime(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not typed ?string');

        NativeEntityReader::read(IdPropertyNotNullable::class);
    }

    public function testTheIdPropertyIsNeverAField(): void {
        self::assertCount(0, NativeEntityReader::read(IdPropertyArticle::class)->fields);
    }
}
