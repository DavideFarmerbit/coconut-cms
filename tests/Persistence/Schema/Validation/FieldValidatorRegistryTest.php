<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Validation;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;

final class FieldValidatorRegistryTestRule implements FieldValidatorRule
{
    public function checkUsage(FieldDescriptor $field): void {
    }

    public function validate(mixed $value): ValidationResult {
        return ValidationResult::valid();
    }
}

final class FieldValidatorRegistryTestNotARule
{
}

final class FieldValidatorRegistryTest extends TestCase
{
    public function testResolveReturnsTheRegisteredClass(): void {
        $registry = new FieldValidatorRegistry();
        $id = Identifier::of('app', 'required');

        $registry->register($id, FieldValidatorRegistryTestRule::class);

        self::assertSame(FieldValidatorRegistryTestRule::class, $registry->resolve($id));
    }

    public function testRegisteringTheSameIdentifierTwiceThrows(): void {
        $registry = new FieldValidatorRegistry();
        $id = Identifier::of('app', 'required');

        $registry->register($id, FieldValidatorRegistryTestRule::class);

        $this->expectException(RuntimeException::class);
        $registry->register($id, FieldValidatorRegistryTestRule::class);
    }

    public function testRegisteringAClassThatDoesNotImplementTheInterfaceThrows(): void {
        $registry = new FieldValidatorRegistry();

        $this->expectException(RuntimeException::class);
        $registry->register(Identifier::of('app', 'broken'), FieldValidatorRegistryTestNotARule::class);
    }

    public function testResolvingAnUnregisteredIdentifierThrows(): void {
        $registry = new FieldValidatorRegistry();

        $this->expectException(RuntimeException::class);
        $registry->resolve(Identifier::of('app', 'never-registered'));
    }
}
