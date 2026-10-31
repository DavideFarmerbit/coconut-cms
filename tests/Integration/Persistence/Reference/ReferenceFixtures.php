<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Reference;

use XyloIsCoding\CoconutCms\Admin\AdminCode;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Module;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\TypePermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Id;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/** Only mechanics read an engine reached as itself; its boost only tuners. */
#[Entity(identifier: 'ref:engine'), TypePermission(read: 'mechanic')]
final class RefEngine
{
    #[Id]
    public ?string $id = null;

    #[Scalar(ScalarType::Int)]
    public int $horsepower = 100;

    #[Scalar(ScalarType::Int), FieldPermission(read: 'tuner')]
    public int $boost = 0;
}

/** Owns engines. Its Reference is declared before the Collection it may point into. */
#[Entity(identifier: 'ref:car')]
final class RefCar
{
    #[Id]
    public ?string $id = null;

    #[Reference]
    public ?RefEngine $favorite = null;

    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: RefEngine::class, itemNullability: Nullability::Nullable)]
    public array $spares = [];

    #[OwningReference, FieldPermission(read: 'admin')]
    public ?RefEngine $secretEngine = null;
}

/** Points at an engine some car owns. */
#[Entity(identifier: 'ref:garage')]
final class RefGarage
{
    #[Id]
    public ?string $id = null;

    #[Reference]
    public ?RefEngine $engine = null;
}

/** The reference tests' types. */
final class ReferenceFixtures
{
    public const CLASSES = [RefEngine::class, RefCar::class, RefGarage::class];
}

final class ReferenceModule implements Module
{
    public function name(): string {
        return 'ref';
    }

    public function classes(): array {
        return ReferenceFixtures::CLASSES;
    }

    public function steps(): MigrationSteps {
        return MigrationSteps::none();
    }

    public function operations(): array {
        return [];
    }

    public function converters(): array {
        return [];
    }

    public function adminCode(): ?AdminCode {
        return null;
    }
}
