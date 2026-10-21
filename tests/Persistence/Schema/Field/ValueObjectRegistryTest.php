<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Field;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValue;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;

final class ValueObjectRegistryTestEmail implements StorableValue
{
    public function __construct(
        private readonly string $address,
    ) {
    }

    public function toStorage(): string {
        return $this->address;
    }

    public static function indexable(): bool {
        return true;
    }

    public static function fromStorage(string $stored): static {
        return new static($stored);
    }
}

final class ValueObjectRegistryTestNotStorable
{
}

final class ValueObjectRegistryTest extends TestCase
{
    public function testIdentifierOfReturnsWhatTheClassWasRegisteredUnder(): void {
        $registry = new ValueObjectRegistry();
        $id = Identifier::of('app', 'email');
        $registry->register($id, ValueObjectRegistryTestEmail::class);

        self::assertEquals($id, $registry->identifierOf(ValueObjectRegistryTestEmail::class));
    }

    public function testIdentifierOfAClassNeverRegisteredThrows(): void {
        $this->expectException(RuntimeException::class);

        (new ValueObjectRegistry())->identifierOf(ValueObjectRegistryTestEmail::class);
    }

    public function testResolveReturnsTheRegisteredClass(): void {
        $registry = new ValueObjectRegistry();
        $id = Identifier::of('app', 'email');
        $registry->register($id, ValueObjectRegistryTestEmail::class);

        self::assertSame(ValueObjectRegistryTestEmail::class, $registry->resolve($id));
    }

    public function testRegisteringTheSameIdentifierTwiceThrows(): void {
        $registry = new ValueObjectRegistry();
        $id = Identifier::of('app', 'email');
        $registry->register($id, ValueObjectRegistryTestEmail::class);

        $this->expectException(RuntimeException::class);

        $registry->register($id, ValueObjectRegistryTestEmail::class);
    }

    public function testRegisteringAClassNotImplementingStorableValueThrows(): void {
        $this->expectException(RuntimeException::class);

        (new ValueObjectRegistry())->register(Identifier::of('app', 'broken'), ValueObjectRegistryTestNotStorable::class);
    }

    public function testResolvingAnUnregisteredIdentifierThrows(): void {
        $this->expectException(RuntimeException::class);

        (new ValueObjectRegistry())->resolve(Identifier::of('app', 'missing'));
    }
}
