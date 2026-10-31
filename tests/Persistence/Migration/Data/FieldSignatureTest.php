<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Migration\InvalidFixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/** What a signature matches: a kind, narrowed or not, nullability never part of it. */
final class FieldSignatureTest extends TestCase
{
    public function testWhatsLeftOutMatchesAny(): void {
        $text = FieldDescriptor::scalar('name', ScalarType::String, Nullability::Nullable);
        $tags = FieldDescriptor::collection('tags', FieldDescriptor::scalar('tags', ScalarType::String, Nullability::NotNullable));
        $maker = FieldDescriptor::reference('maker', 'shop:maker');

        self::assertTrue(FieldSignature::any()->matches($maker));
        self::assertTrue(FieldSignature::scalar()->matches($text));
        self::assertTrue(FieldSignature::scalar(ScalarType::String)->matches($text));
        self::assertTrue(FieldSignature::text()->matches($text));
        self::assertFalse(FieldSignature::number()->matches($text));
        self::assertTrue(FieldSignature::oneOf(FieldSignature::reference(), FieldSignature::scalar(ScalarType::String))->matches($text));
        self::assertTrue(FieldSignature::collection(FieldSignature::oneOf(FieldSignature::reference(), FieldSignature::text()))->matches($tags), 'A union nests.');
        self::assertTrue(FieldSignature::collection()->matches($tags));
        self::assertTrue(FieldSignature::collection(FieldSignature::scalar(ScalarType::String))->matches($tags));
        self::assertFalse(FieldSignature::collection(FieldSignature::reference())->matches($tags));
        self::assertTrue(FieldSignature::reference()->matches($maker));
        self::assertFalse(FieldSignature::reference('shop:yard')->matches($maker));
        self::assertFalse(FieldSignature::owningReference()->matches($maker));
    }

    public function testARefusalNamesWhatWasExpected(): void {
        $this->expectException(InvalidFixup::class);
        $this->expectExceptionMessage('Shop\\Converter takes a Scalar of string or text, and field "power" of "shop:car" is not one.');

        FieldSignature::text()->assertMatches(FieldDescriptor::scalar('power', ScalarType::Int, Nullability::NotNullable), 'Shop\\Converter', 'takes', 'shop:car', 'power');
    }

    public function testItDescribesItself(): void {
        self::assertSame('any field', FieldSignature::any()->describe());
        self::assertSame('a Scalar of int, float or decimal', FieldSignature::number()->describe());
        self::assertSame('an Embed of shop:engine or an owned object of shop:engine', FieldSignature::oneOf(FieldSignature::embed('shop:engine'), FieldSignature::owningReference('shop:engine'))->describe());
        self::assertSame('a Collection of a Reference of shop:maker', FieldSignature::collection(FieldSignature::reference('shop:maker'))->describe());
        self::assertSame('an owned object of shop:motor', FieldSignature::owningReference('shop:motor')->describe());
    }
}
