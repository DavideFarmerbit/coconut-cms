<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Rows;

use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

#[Entity]
final class RowsMaker
{
    #[Scalar(ScalarType::String)]
    public string $name = '';
}

#[Entity]
final class RowsTurbo
{
    #[Scalar(ScalarType::Int)]
    public int $boost = 2;
}

#[Entity(table: 'rows_engine')]
final class RowsEngine
{
    #[Scalar(ScalarType::Int)]
    public int $horsepower = 300;

    #[OwningReference]
    public ?RowsTurbo $turbo = null;
}

#[Entity(table: 'rows_wheel')]
final class RowsWheel
{
    #[Scalar(ScalarType::Int)]
    public int $size = 17;

    #[Reference]
    public ?RowsMaker $maker = null;
}

#[Entity(table: 'rows_chassis')]
final class RowsChassis
{
    #[Scalar(ScalarType::Int)]
    public int $weight = 1000;
}

#[Entity(table: 'rows_vehicle')]
class RowsVehicle
{
    #[Scalar(ScalarType::String)]
    public string $name = 'vehicle';
}

#[Entity(table: 'rows_car')]
final class RowsCar extends RowsVehicle
{
    #[Embed]
    public RowsChassis $chassis;

    #[OwningReference]
    public ?RowsEngine $engine;

    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: RowsEngine::class)]
    public array $spares = [];

    #[Collection(itemKind: FieldKind::Embed, itemTarget: RowsWheel::class)]
    public array $wheels = [];

    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String)]
    public array $tags = [];

    #[Reference]
    public ?RowsMaker $maker = null;

    public function __construct() {
        $this->chassis = new RowsChassis();
        $this->engine = new RowsEngine();
    }
}

/** A car aggregate holding every field kind, shared by the row tests. */
final class RowsFixtures
{
    /** Every fixture class, in an order compile() accepts. */
    public const CLASSES = [RowsMaker::class, RowsTurbo::class, RowsEngine::class, RowsWheel::class, RowsChassis::class, RowsVehicle::class, RowsCar::class];
}
