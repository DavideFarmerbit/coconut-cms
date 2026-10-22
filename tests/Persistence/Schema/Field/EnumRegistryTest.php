<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Field;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;

enum EnumRegistryTestStatus: string
{
    case Draft = 'draft';
}

enum EnumRegistryTestPure
{
    case Only;
}

final class EnumRegistryTest extends TestCase
{
    public function testIdentifierOfReturnsWhatTheEnumWasRegisteredUnder(): void {
        $registry = new EnumRegistry();
        $id = Identifier::of('app', 'status');
        $registry->register($id, EnumRegistryTestStatus::class);

        self::assertEquals($id, $registry->identifierOf(EnumRegistryTestStatus::class));
    }

    public function testIdentifierOfAnEnumNeverRegisteredThrows(): void {
        $this->expectException(RuntimeException::class);

        (new EnumRegistry())->identifierOf(EnumRegistryTestStatus::class);
    }

    public function testResolveReturnsTheRegisteredEnum(): void {
        $registry = new EnumRegistry();
        $id = Identifier::of('app', 'status');
        $registry->register($id, EnumRegistryTestStatus::class);

        self::assertSame(EnumRegistryTestStatus::class, $registry->resolve($id));
    }

    public function testRegisteringTheSameIdentifierTwiceThrows(): void {
        $registry = new EnumRegistry();
        $id = Identifier::of('app', 'status');
        $registry->register($id, EnumRegistryTestStatus::class);

        $this->expectException(RuntimeException::class);

        $registry->register($id, EnumRegistryTestStatus::class);
    }

    public function testRegisteringAPureEnumThrows(): void {
        $this->expectException(RuntimeException::class);

        (new EnumRegistry())->register(Identifier::of('app', 'pure'), EnumRegistryTestPure::class);
    }

    public function testResolvingAnUnregisteredIdentifierThrows(): void {
        $this->expectException(RuntimeException::class);

        (new EnumRegistry())->resolve(Identifier::of('app', 'missing'));
    }
}
