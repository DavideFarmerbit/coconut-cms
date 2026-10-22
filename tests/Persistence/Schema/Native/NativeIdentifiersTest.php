<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Native;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;

#[Entity]
final class IdentifiersBlogPost
{
}

#[Entity(identifier: 'shop:product')]
final class IdentifiersProduct
{
}

#[Entity(identifier: 'coconut-cms:page')]
final class IdentifiersPage
{
}

#[Entity(identifier: 'shop:product', table: 'own_products')]
final class IdentifiersOtherProduct
{
}

#[Entity(identifier: 'no-namespace')]
final class IdentifiersMalformed
{
}

final class IdentifiersNotAnEntity
{
}

final class NativeIdentifiersTest extends TestCase
{
    public function testAClassWithNoIdentifierGetsItsShortNameSnakeCasedInTheDefaultNamespace(): void {
        self::assertSame('default:identifiers_blog_post', NativeIdentifiers::of(IdentifiersBlogPost::class));
    }

    public function testAnIdentifierDeclaredOnTheClassIsUsedAsIs(): void {
        self::assertSame('shop:product', NativeIdentifiers::of(IdentifiersProduct::class));
    }

    public function testAMalformedIdentifierThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeIdentifiers::of(IdentifiersMalformed::class);
    }

    public function testAClassNotMarkedAsAnEntityThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeIdentifiers::of(IdentifiersNotAnEntity::class);
    }

    public function testATableInTheDefaultNamespaceIsTheKeyAlone(): void {
        self::assertSame('identifiers_blog_post', NativeEntityReader::read(IdentifiersBlogPost::class)->table);
    }

    public function testATableInAnyOtherNamespaceIsPrefixedWithIt(): void {
        self::assertSame('shop_product', NativeEntityReader::read(IdentifiersProduct::class)->table);
        self::assertSame('coconut_cms_page', NativeEntityReader::read(IdentifiersPage::class)->table);
    }

    public function testATableNamedOnTheClassWins(): void {
        self::assertSame('own_products', NativeEntityReader::read(IdentifiersOtherProduct::class)->table);
    }

    public function testTheRegistryGivesBackTheClassRegisteredUnderAnIdentifier(): void {
        $registry = new NativeEntityRegistry();

        self::assertSame('shop:product', $registry->register(IdentifiersProduct::class));
        self::assertSame(IdentifiersProduct::class, $registry->classOf('shop:product'));
    }

    public function testTwoClassesWithOneIdentifierAreRejected(): void {
        $registry = new NativeEntityRegistry();
        $registry->register(IdentifiersProduct::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('both have the identifier "shop:product"');

        $registry->register(IdentifiersOtherProduct::class);
    }

    public function testReadingTwoClassesWithOneIdentifierTogetherIsRejected(): void {
        $this->expectException(InvalidArgumentException::class);

        NativeEntityReader::readAll([IdentifiersProduct::class, IdentifiersOtherProduct::class]);
    }

    public function testAnIdentifierNeverRegisteredHasNoClass(): void {
        $this->expectException(InvalidArgumentException::class);

        (new NativeEntityRegistry())->classOf('shop:product');
    }
}
