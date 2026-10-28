<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Values;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

#[Entity(identifier: 'scope:engine')]
class ScopeEngine
{
    #[Scalar(ScalarType::Int)]
    public int $horsepower = 100;

    #[Scalar(ScalarType::Int)]
    public int $torque = 0;
}

#[Entity(identifier: 'scope:turbo')]
final class ScopeTurbo extends ScopeEngine
{
    #[Scalar(ScalarType::Int)]
    public int $boost = 5;
}

#[Entity(identifier: 'scope:chassis')]
final class ScopeChassis
{
    #[Scalar(ScalarType::Int)]
    public int $weight = 1000;

    #[Scalar(ScalarType::Int)]
    public int $grade = 0;
}

/** Holds an Embed and an owned engine, of any engine type. */
#[Entity(identifier: 'scope:car')]
final class ScopeCar
{
    #[Scalar(ScalarType::String)]
    public string $name = '';

    #[Scalar(ScalarType::String)]
    public string $nickname = '';

    #[Embed]
    public ScopeChassis $chassis;

    #[OwningReference]
    public ?ScopeEngine $engine = null;

    public function __construct() {
        $this->chassis = new ScopeChassis();
    }
}

/** Unrelated to everything else. */
#[Entity(identifier: 'scope:maker')]
final class ScopeMaker
{
    #[Scalar(ScalarType::String)]
    public string $name = '';
}

/** The maker, with one field more. */
#[Entity(identifier: 'scope:maker')]
final class ScopeMakerWithCountry
{
    #[Scalar(ScalarType::String)]
    public string $name = '';

    #[Scalar(ScalarType::String)]
    public string $country = 'IT';
}

/** The turbo, with one field more. */
#[Entity(identifier: 'scope:turbo')]
final class ScopeTurboWithWastegate extends ScopeEngine
{
    #[Scalar(ScalarType::Int)]
    public int $boost = 5;

    #[Scalar(ScalarType::Int)]
    public int $wastegate = 1;
}

/** The chassis, with another default. */
#[Entity(identifier: 'scope:chassis')]
final class ScopeHeavierChassis
{
    #[Scalar(ScalarType::Int)]
    public int $weight = 1100;

    #[Scalar(ScalarType::Int)]
    public int $grade = 0;
}

/** The engine, with another default its turbo inherits. */
#[Entity(identifier: 'scope:engine')]
class ScopeStrongerEngine
{
    #[Scalar(ScalarType::Int)]
    public int $horsepower = 150;

    #[Scalar(ScalarType::Int)]
    public int $torque = 0;
}

#[Entity(identifier: 'scope:turbo')]
final class ScopeTurboOfStrongerEngine extends ScopeStrongerEngine
{
    #[Scalar(ScalarType::Int)]
    public int $boost = 5;
}

/** The engine with the turbo's boost moved up to it. */
#[Entity(identifier: 'scope:engine')]
class ScopeEngineWithBoost
{
    #[Scalar(ScalarType::Int)]
    public int $horsepower = 100;

    #[Scalar(ScalarType::Int)]
    public int $torque = 0;

    #[Scalar(ScalarType::Int)]
    public int $boost = 0;
}

/** The turbo, its boost now inherited. */
#[Entity(identifier: 'scope:turbo')]
final class ScopeTurboOfEngineWithBoost extends ScopeEngineWithBoost
{
}

/** A plain engine gaining a boost gets one: what MoveField's initializer decides. */
final class ScopeBoostForPlainEngines implements FieldInitializer
{
    public function initialize(Values $values): Conversion {
        return Conversion::of(1);
    }
}

/** Types several aggregates hold, and versions of them each changing one thing. */
final class MigratorScope
{
    public const V1 = [Cms::class, ScopeEngine::class, ScopeTurbo::class, ScopeChassis::class, ScopeCar::class, ScopeMaker::class];

    /**
     * V1 with the classes $old swapped for $new: the same identifiers, another version.
     * @param list<class-string> $old
     * @param list<class-string> $new
     * @return list<class-string>
     */
    public static function swapping(array $old, array $new): array {
        return [...array_values(array_diff(self::V1, $old)), ...$new];
    }
}
