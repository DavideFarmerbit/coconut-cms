<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Query;

use XyloIsCoding\CoconutCms\Admin\AdminCode;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Module;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

enum QueryFuel: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Electric = 'electric';
}

#[Entity(identifier: 'qt:country')]
final class QueryCountry
{
    #[Scalar(ScalarType::String)]
    public string $name = '';
}

#[Entity(identifier: 'qt:maker')]
final class QueryMaker
{
    #[Scalar(ScalarType::String)]
    public string $name = '';

    #[Reference]
    public ?QueryCountry $country = null;
}

#[Entity(identifier: 'qt:chassis')]
final class QueryChassis
{
    #[Scalar(ScalarType::Int)]
    public int $weight = 1000;
}

/** Owned by an engine. */
#[Entity(identifier: 'qt:turbo')]
final class QueryTurbo
{
    #[Scalar(ScalarType::Int)]
    public int $boost = 2;
}

/** Only ever owned: a query never lists it. */
#[Entity(identifier: 'qt:engine')]
final class QueryEngine
{
    #[Scalar(ScalarType::Int)]
    public int $horsepower = 100;

    #[OwningReference]
    public ?QueryTurbo $turbo = null;

    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String, itemNullability: Nullability::NotNullable)]
    public array $parts = [];
}

#[Entity(identifier: 'qt:wheel')]
final class QueryWheel
{
    #[Scalar(ScalarType::Int)]
    public int $size = 17;
}

#[Entity(identifier: 'qt:vehicle')]
class QueryVehicle
{
    #[Scalar(ScalarType::String)]
    public string $name = '';

    #[Scalar(ScalarType::Int)]
    public int $price = 0;

    #[Scalar(ScalarType::String)]
    public ?string $nickname = null;

    #[Scalar(ScalarType::Bool)]
    public bool $sold = false;

    #[Scalar(ScalarType::Enum)]
    public QueryFuel $fuel = QueryFuel::Petrol;

    #[Embed]
    public QueryChassis $chassis;

    #[OwningReference]
    public ?QueryEngine $engine = null;

    #[Reference]
    public ?QueryMaker $maker = null;

    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: QueryEngine::class, itemNullability: Nullability::Nullable)]
    public array $spares = [];

    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String, itemNullability: Nullability::NotNullable)]
    public array $tags = [];

    #[Collection(itemKind: FieldKind::Embed, itemTarget: QueryWheel::class)]
    public array $wheels = [];

    #[Collection(itemKind: FieldKind::Reference, itemTarget: QueryMaker::class)]
    public array $previousMakers = [];

    public function __construct() {
        $this->chassis = new QueryChassis();
    }
}

#[Entity(identifier: 'qt:car')]
final class QueryCar extends QueryVehicle
{
    #[Scalar(ScalarType::Int)]
    public int $doors = 4;
}

#[Entity(identifier: 'qt:truck')]
final class QueryTruck extends QueryVehicle
{
    #[Scalar(ScalarType::Int)]
    public int $load = 1000;
}

/** The query tests' types: a vehicle and its subtypes, a maker, a chassis, an engine only ever owned. */
final class QueryFixtures
{
    public const CLASSES = [QueryCountry::class, QueryMaker::class, QueryChassis::class, QueryTurbo::class, QueryEngine::class, QueryWheel::class, QueryVehicle::class, QueryCar::class, QueryTruck::class];
}

final class QueryModule implements Module
{
    public function name(): string {
        return 'qt';
    }

    public function classes(): array {
        return QueryFixtures::CLASSES;
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
