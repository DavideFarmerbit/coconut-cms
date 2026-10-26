<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Module;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

enum RoundTripFuel: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
}

/** One field of every scalar type, each with a value no default gives. */
#[Entity(identifier: 'roundtrip:sample')]
final class RoundTripSample
{
    #[Scalar(ScalarType::String)]
    public string $text = '';

    #[Scalar(ScalarType::Int)]
    public int $count = 0;

    #[Scalar(ScalarType::Float)]
    public float $ratio = 0.0;

    #[Scalar(ScalarType::Bool)]
    public bool $enabled = false;

    #[Scalar(ScalarType::Decimal)]
    public string $price = '0';

    #[Scalar(ScalarType::Date)]
    public ?DateTimeImmutable $day = null;

    #[Scalar(ScalarType::DateTime)]
    public ?DateTimeImmutable $at = null;

    #[Scalar(ScalarType::Enum)]
    public RoundTripFuel $fuel = RoundTripFuel::Petrol;
}

final class RoundTripModule implements Module
{
    public function name(): string {
        return 'roundtrip';
    }

    public function classes(): array {
        return [RoundTripSample::class];
    }

    public function steps(): MigrationSteps {
        return MigrationSteps::none();
    }
}

/** Every scalar type stored and read back the same, whichever database runs it. */
final class ScalarRoundTripTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
    }

    protected function tearDown(): void {
        TestDatabase::dropEverything($this->connection);
        $this->connection->close();
    }

    public function testEveryScalarTypeComesBackAsItWasSaved(): void {
        Persistence::boot($this->connection, [new RoundTripModule()])->migrate();
        $sample = new RoundTripSample();
        $sample->text = 'Family car';
        $sample->count = 9_000_000_000;
        $sample->ratio = 0.25;
        $sample->enabled = true;
        $sample->price = '12.5';
        $sample->day = new DateTimeImmutable('2024-02-29');
        $sample->at = new DateTimeImmutable('2025-01-01 10:30:00', new DateTimeZone('UTC'));
        $sample->fuel = RoundTripFuel::Diesel;
        $id = Persistence::boot($this->connection, [new RoundTripModule()])->as(new SystemActor())->save($sample);

        $loaded = Persistence::boot($this->connection, [new RoundTripModule()])->as(new SystemActor())->load($id);

        self::assertSame('Family car', $loaded->text);
        self::assertSame(9_000_000_000, $loaded->count);
        self::assertSame(0.25, $loaded->ratio);
        self::assertTrue($loaded->enabled);
        self::assertSame('12.500000', $loaded->price);
        self::assertSame('2024-02-29', $loaded->day->format('Y-m-d'));
        self::assertSame('2025-01-01 10:30:00', $loaded->at->format('Y-m-d H:i:s'));
        self::assertSame(RoundTripFuel::Diesel, $loaded->fuel);
    }
}
