<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Edit;

use DateTimeImmutable;
use XyloIsCoding\CoconutCms\Admin\AdminCode;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\AfterEdit;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\EditRefused;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Module;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Id;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Setter;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

enum EditFuel: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
}

/** Refuses a negative horsepower through its setter. */
#[Entity(identifier: 'edit:engine')]
class EditEngine
{
    #[Id]
    public ?string $id = null;

    #[Scalar(ScalarType::Int), Setter('setHorsepower')]
    public int $horsepower = 100;

    public function setHorsepower(int $horsepower): void {
        if ($horsepower < 0) {
            throw new EditRefused('Horsepower can\'t be negative.');
        }

        $this->horsepower = $horsepower;
    }
}

/** Overrides its parent's setter, and notes what its own edits changed. */
#[Entity(identifier: 'edit:turbo_engine')]
class EditTurboEngine extends EditEngine implements AfterEdit
{
    #[Scalar(ScalarType::Int)]
    public int $boost = 1;

    #[Scalar(ScalarType::String)]
    public string $edited = '';

    public function setHorsepower(int $horsepower): void {
        parent::setHorsepower($horsepower);
        $this->boost = intdiv($horsepower, 100);
    }

    public function afterEdit(array $paths): void {
        $this->edited = implode(',', $paths);
    }
}

#[Entity(identifier: 'edit:chassis')]
final class EditChassis
{
    #[Scalar(ScalarType::Int)]
    public int $weight = 1000;
}

#[Entity(identifier: 'edit:wheel')]
final class EditWheel
{
    #[Scalar(ScalarType::Int)]
    public int $size = 17;
}

#[Entity(identifier: 'edit:maker')]
final class EditMaker
{
    #[Id]
    public ?string $id = null;

    #[Scalar(ScalarType::String)]
    public string $name = '';
}

/** Every kind of field, a private setter, and a rule spanning fields. */
#[Entity(identifier: 'edit:car')]
class EditCar implements AfterEdit
{
    #[Id]
    public ?string $id = null;

    #[Scalar(ScalarType::String), Setter('rename')]
    public string $name = '';

    #[Scalar(ScalarType::String)]
    public string $slug = '';

    #[Scalar(ScalarType::Enum)]
    public EditFuel $fuel = EditFuel::Petrol;

    #[Scalar(ScalarType::String)]
    public string $country = '';

    #[Scalar(ScalarType::String)]
    public string $region = '';

    #[Scalar(ScalarType::Date)]
    public ?DateTimeImmutable $builtOn = null;

    #[Embed]
    public EditChassis $chassis;

    #[OwningReference]
    public ?EditEngine $engine = null;

    #[Collection(itemKind: FieldKind::Embed, itemTarget: EditWheel::class)]
    public array $wheels = [];

    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: EditEngine::class, itemNullability: Nullability::Nullable)]
    public array $spares = [];

    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String, itemNullability: Nullability::NotNullable)]
    public array $tags = [];

    #[Reference]
    public ?EditMaker $maker = null;

    #[Scalar(ScalarType::String), FieldPermission(read: 'admin')]
    public string $secret = '';

    #[Scalar(ScalarType::Int), FieldPermission(write: 'admin')]
    public int $price = 0;

    /** What the last afterEdit() was told. */
    #[Scalar(ScalarType::String)]
    public string $lastEdit = '';

    public function __construct() {
        $this->chassis = new EditChassis();
    }

    public function afterEdit(array $paths): void {
        if (in_array('country', $paths, true)) {
            $this->region = '';
        }

        if ($this->name === 'Forbidden') {
            throw new EditRefused('That name is taken.');
        }

        $this->lastEdit = implode(',', $paths);
    }

    private function rename(string $name): void {
        if ($name === '') {
            throw new EditRefused('A car needs a name.');
        }

        $this->name = $name;
        $this->slug = strtolower(str_replace(' ', '-', $name));
    }
}

/** Its own private rename() overrides nothing: EditCar's runs. */
#[Entity(identifier: 'edit:sports_car')]
final class EditSportsCar extends EditCar
{
    private function rename(string $name): void {
        $this->name = 'never';
    }
}

/** Not in the module: names a setter it lacks. */
#[Entity(identifier: 'edit:missing_setter')]
final class EditMissingSetter
{
    #[Scalar(ScalarType::Int), Setter('setCount')]
    public int $count = 0;
}

/** Not in the module: its setter takes a string for an int. */
#[Entity(identifier: 'edit:wrong_setter')]
final class EditWrongSetter
{
    #[Scalar(ScalarType::Int), Setter('setCount')]
    public int $count = 0;

    public function setCount(string $count): void {
    }
}

/** Not in the module: its setter takes two required values. */
#[Entity(identifier: 'edit:greedy_setter')]
final class EditGreedySetter
{
    #[Scalar(ScalarType::Int), Setter('setCount')]
    public int $count = 0;

    public function setCount(int $count, int $other): void {
    }
}

/** The edit tests' types. */
final class EditFixtures
{
    public const CLASSES = [EditEngine::class, EditTurboEngine::class, EditChassis::class, EditWheel::class, EditMaker::class, EditCar::class, EditSportsCar::class];
}

final class EditModule implements Module
{
    public function name(): string {
        return 'edit';
    }

    public function classes(): array {
        return EditFixtures::CLASSES;
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
