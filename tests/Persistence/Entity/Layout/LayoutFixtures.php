<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Layout;

use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LayoutBuilder;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Unique;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\ValueObject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValue;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Tests\Persistence\Schema\InMemorySchemaStores;

final class LayoutColor implements StorableValue
{
    public function __construct(
        private readonly string $hex,
    ) {
    }

    public function toStorage(): string {
        return $this->hex;
    }

    public static function indexable(): bool {
        return true;
    }

    public static function fromStorage(string $stored): static {
        return new static($stored);
    }
}

/** Free text: may grow past 255 characters, so it's never indexable. */
final class LayoutNotes implements StorableValue
{
    public function __construct(
        private readonly string $text,
    ) {
    }

    public function toStorage(): string {
        return $this->text;
    }

    public static function indexable(): bool {
        return false;
    }

    public static function fromStorage(string $stored): static {
        return new static($stored);
    }
}

#[Entity]
final class LayoutNoted
{
    #[ValueObject]
    private LayoutNotes $notes;

    public function __construct() {
        $this->notes = new LayoutNotes('');
    }
}

enum LayoutFuel: int
{
    case Petrol = 1;
    case Diesel = 2;
}

#[Entity]
final class LayoutMaker
{
    #[Scalar(ScalarType::String)]
    private string $name = '';
}

#[Entity]
final class LayoutPiston
{
    #[Scalar(ScalarType::Decimal)]
    private string $diameter = '10';
}

#[Entity]
#[Unique('serial')]
class LayoutEngine
{
    #[Scalar(ScalarType::String)]
    private string $serial = '';

    #[Embed]
    private LayoutPiston $piston;

    public function __construct() {
        $this->piston = new LayoutPiston();
    }
}

#[Entity]
#[Unique('size')]
final class LayoutWheel
{
    #[Scalar(ScalarType::Int)]
    private int $size = 17;

    #[Reference]
    private ?LayoutMaker $maker = null;
}

#[Entity]
#[Unique('maker')]
final class LayoutChassis
{
    #[Scalar(ScalarType::Int)]
    private int $weight = 1000;

    #[Reference]
    private ?LayoutMaker $maker = null;

    #[OwningReference]
    private ?LayoutEngine $spare = null;

    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String, itemNullability: Nullability::NotNullable)]
    private array $tags = [];
}

#[Entity]
class LayoutVehicle
{
    #[Scalar(ScalarType::String)]
    private string $name = '';
}

#[Entity(table: 'car')]
#[Unique('color', 'fuel')]
final class LayoutCar extends LayoutVehicle
{
    #[ValueObject]
    private LayoutColor $color;

    #[Scalar(ScalarType::Enum)]
    private LayoutFuel $fuel = LayoutFuel::Petrol;

    #[Embed]
    private LayoutChassis $chassis;

    #[OwningReference]
    private ?LayoutEngine $engine = null;

    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: LayoutEngine::class, itemNullability: Nullability::Nullable)]
    private array $engines = [];

    #[Collection(itemKind: FieldKind::Embed, itemTarget: LayoutWheel::class)]
    private array $wheels = [];

    #[Collection(itemKind: FieldKind::Reference, itemTarget: LayoutMaker::class)]
    private array $makers = [];

    public function __construct() {
        $this->color = new LayoutColor('#000000');
        $this->chassis = new LayoutChassis();
    }
}

/** Compiles every fixture class, so a test can lay any of them out. */
final class LayoutFixtures
{
    /** @param list<class-string> $classes Compiled together, in one batch. */
    public static function builderFor(array $classes): LayoutBuilder {
        $stores = new InMemorySchemaStores();
        $stores->valueObjects->register(Identifier::of('test', 'color'), LayoutColor::class);
        $stores->valueObjects->register(Identifier::of('test', 'notes'), LayoutNotes::class);
        $stores->enums->register(Identifier::of('test', 'fuel'), LayoutFuel::class);
        $stores->compiler()->compile(NativeEntityReader::readAll($classes));

        return new LayoutBuilder(new SchemaLookup($stores->registry));
    }

    /** A builder knowing every class a LayoutCar needs. */
    public static function carBuilder(): LayoutBuilder {
        return self::builderFor([
            LayoutMaker::class,
            LayoutPiston::class,
            LayoutEngine::class,
            LayoutWheel::class,
            LayoutChassis::class,
            LayoutVehicle::class,
            LayoutCar::class,
        ]);
    }
}
