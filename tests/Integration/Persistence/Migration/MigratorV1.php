<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

#[Entity(identifier: 'migtest:vehicle')]
class MigV1Vehicle
{
    #[Scalar(ScalarType::String)]
    public string $name = 'vehicle';
}

#[Entity(identifier: 'migtest:chassis')]
final class MigV1Chassis
{
    #[Scalar(ScalarType::Int)]
    public int $weight = 1000;
}

#[Entity(identifier: 'migtest:engine')]
class MigV1Engine
{
    #[Scalar(ScalarType::Int)]
    public int $horsepower = 300;
}

#[Entity(identifier: 'migtest:turbo_engine')]
final class MigV1TurboEngine extends MigV1Engine
{
    #[Scalar(ScalarType::Int)]
    public int $boost = 2;
}

#[Entity(identifier: 'migtest:car')]
final class MigV1Car extends MigV1Vehicle
{
    #[Scalar(ScalarType::Int)]
    public int $price = 100;

    #[Embed]
    public MigV1Chassis $chassis;

    #[OwningReference]
    public ?MigV1Engine $engine;

    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: MigV1Engine::class)]
    public array $spares = [];

    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String)]
    public array $tags = [];

    public function __construct() {
        $this->chassis = new MigV1Chassis();
        $this->engine = new MigV1Engine();
    }
}

/** The migration test's first version of the code: what's stored before it migrates. */
final class MigratorV1
{
    /** Every class, Cms included. */
    public const CLASSES = [Cms::class, MigV1Vehicle::class, MigV1Chassis::class, MigV1Engine::class, MigV1TurboEngine::class, MigV1Car::class];
}
