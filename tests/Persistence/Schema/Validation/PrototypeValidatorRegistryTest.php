<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Validation;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;

final class PrototypeValidatorRegistryTestRule implements PrototypeValidatorRule
{
    public function checkUsage(array $fields): void {
    }

    public function validate(object $entity): ValidationResult {
        return ValidationResult::valid();
    }
}

final class PrototypeValidatorRegistryTestNotARule
{
}

final class PrototypeValidatorRegistryTest extends TestCase
{
    public function testResolveReturnsTheRegisteredClass(): void {
        $registry = new PrototypeValidatorRegistry();
        $id = Identifier::of('app', 'endDateAfterStartDate');

        $registry->register($id, PrototypeValidatorRegistryTestRule::class);

        self::assertSame(PrototypeValidatorRegistryTestRule::class, $registry->resolve($id));
    }

    public function testRegisteringTheSameIdentifierTwiceThrows(): void {
        $registry = new PrototypeValidatorRegistry();
        $id = Identifier::of('app', 'endDateAfterStartDate');

        $registry->register($id, PrototypeValidatorRegistryTestRule::class);

        $this->expectException(RuntimeException::class);
        $registry->register($id, PrototypeValidatorRegistryTestRule::class);
    }

    public function testRegisteringAClassThatDoesNotImplementTheInterfaceThrows(): void {
        $registry = new PrototypeValidatorRegistry();

        $this->expectException(RuntimeException::class);
        $registry->register(Identifier::of('app', 'broken'), PrototypeValidatorRegistryTestNotARule::class);
    }

    public function testResolvingAnUnregisteredIdentifierThrows(): void {
        $registry = new PrototypeValidatorRegistry();

        $this->expectException(RuntimeException::class);
        $registry->resolve(Identifier::of('app', 'never-registered'));
    }
}
