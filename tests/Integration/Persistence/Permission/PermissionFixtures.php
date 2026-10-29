<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Permission;

use XyloIsCoding\CoconutCms\Admin\AdminCode;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityView;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Module;
use XyloIsCoding\CoconutCms\Persistence\Permission\Actor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\PrototypeValidator;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\TypePermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Id;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;

/** An actor holding the roles it's given. */
final readonly class PermActor implements Actor
{
    /** @param list<string> $roles */
    public function __construct(private array $roles = []) {
    }

    public function hasRole(string $role): bool {
        return in_array($role, $this->roles, true);
    }
}

/** Reads a field only admins may read: a car named "Secret" needs its internal note. */
final readonly class PermSecretNeedsInternal implements PrototypeValidatorRule
{
    public function checkUsage(FieldDescriptorCollection $fields): void {
    }

    public function validate(EntityView $entity): ValidationResult {
        return $entity->get('name') === 'Secret' && $entity->get('internal') === '' ? ValidationResult::invalid('a secret car needs an internal note') : ValidationResult::valid();
    }

    public function toStorage(): array {
        return [];
    }

    public static function fromStorage(array $stored): static {
        return new static();
    }
}

/** Guarded on its own; owned by a car or a document, it follows them. */
#[Entity(identifier: 'perm:engine'), TypePermission(read: 'engine-room', create: 'engine-room', update: 'engine-room', delete: 'engine-room')]
final class PermEngine
{
    #[Id]
    public ?string $id = null;

    #[Scalar(ScalarType::Int)]
    public int $horsepower = 100;
}

/** An Embed with a field only mechanics may read. */
#[Entity(identifier: 'perm:chassis')]
final class PermChassis
{
    #[Scalar(ScalarType::Int)]
    public int $weight = 1000;

    #[Scalar(ScalarType::String), FieldPermission(read: 'mechanic')]
    public string $serial = '';
}

/** An Embed item with a field only admins may read. */
#[Entity(identifier: 'perm:label')]
final class PermLabel
{
    #[Scalar(ScalarType::String)]
    public string $text = '';

    #[Scalar(ScalarType::String), FieldPermission(read: 'admin')]
    public string $note = '';
}

/** Open to everyone. */
#[Entity(identifier: 'perm:maker')]
class PermMaker
{
    #[Id]
    public ?string $id = null;

    #[Scalar(ScalarType::String)]
    public string $name = '';
}

/** A subtype only admins read. */
#[Entity(identifier: 'perm:secret_maker'), TypePermission(read: 'admin')]
class PermSecretMaker extends PermMaker
{
}

/** Declares its own role too: both must hold, so it can't open up its parent. */
#[Entity(identifier: 'perm:partner_maker'), TypePermission(read: 'partner')]
final class PermPartnerMaker extends PermSecretMaker
{
}

/** A role for each action. */
#[Entity(identifier: 'perm:document'), TypePermission(read: 'reader', create: 'creator', update: 'editor', delete: 'remover')]
final class PermDocument
{
    #[Id]
    public ?string $id = null;

    #[Scalar(ScalarType::String)]
    public string $title = '';

    #[OwningReference]
    public ?PermEngine $engine = null;
}

#[Entity(identifier: 'perm:car'), PrototypeValidator(new PermSecretNeedsInternal())]
final class PermCar
{
    #[Id]
    public ?string $id = null;

    #[Scalar(ScalarType::String)]
    public string $name = '';

    /** Everyone reads it; only admins change it. */
    #[Scalar(ScalarType::Int), FieldPermission(write: 'admin')]
    public int $price = 0;

    #[Scalar(ScalarType::String), FieldPermission(read: 'admin', write: 'admin')]
    public string $internal = '';

    #[OwningReference, FieldPermission(read: 'admin')]
    public ?PermEngine $engine = null;

    #[Embed]
    public PermChassis $chassis;

    #[Collection(itemKind: FieldKind::Embed, itemTarget: PermLabel::class)]
    public array $labels = [];

    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String, itemNullability: Nullability::NotNullable), FieldPermission(read: 'admin')]
    public array $codes = [];

    #[Reference]
    public ?PermMaker $maker = null;

    #[Reference]
    public ?PermDocument $document = null;

    public function __construct() {
        $this->chassis = new PermChassis();
    }
}

/** Not in the module: a readonly property can't be hidden. */
#[Entity(identifier: 'perm:readonly')]
final class PermReadonlySecret
{
    #[Scalar(ScalarType::String), FieldPermission(read: 'admin')]
    public readonly string $secret;
}

/** The permission tests' types. */
final class PermissionFixtures
{
    public const CLASSES = [PermEngine::class, PermChassis::class, PermLabel::class, PermMaker::class, PermSecretMaker::class, PermPartnerMaker::class, PermDocument::class, PermCar::class];
}

final class PermissionModule implements Module
{
    public function name(): string {
        return 'perm';
    }

    public function classes(): array {
        return PermissionFixtures::CLASSES;
    }

    public function steps(): MigrationSteps {
        return MigrationSteps::none();
    }

    public function operations(): array {
        return [];
    }

    public function adminCode(): ?AdminCode {
        return null;
    }
}
