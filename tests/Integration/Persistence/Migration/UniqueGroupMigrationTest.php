<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Admin\AdminCode;
use XyloIsCoding\CoconutCms\Persistence\Migration\ContentRefused;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Module;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\PersistenceStatus;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Unique;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/** A product with a sku, not unique yet. */
#[Entity(identifier: 'uq:product')]
final class UqProductV1
{
    #[Scalar(ScalarType::String)]
    public string $sku = '';
}

/** The same product, its sku unique now. */
#[Entity(identifier: 'uq:product')]
#[Unique('sku')]
final class UqProductV2
{
    #[Scalar(ScalarType::String)]
    public string $sku = '';
}

/** The product's code, at version $version. */
final readonly class UqModule implements Module
{
    public function __construct(private int $version) {
    }

    public function name(): string {
        return 'uq';
    }

    public function classes(): array {
        return [$this->version === 1 ? UqProductV1::class : UqProductV2::class];
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

/**
 * A unique group added in code over content: refused before anything
 * changes when the content breaks it, the database deciding what's equal.
 */
final class UniqueGroupMigrationTest extends TestCase
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

    /**
     * Saves a product per sku in $skus, with the code at version 1.
     * @return list<string> Their ids.
     */
    private function products(string ...$skus): array {
        $v1 = Persistence::boot($this->connection, [new UqModule(1)]);
        $v1->migrate();
        $ids = [];
        foreach ($skus as $sku) {
            $product = new UqProductV1();
            $product->sku = $sku;
            $ids[] = $v1->as(new SystemActor())->save($product);
        }

        return $ids;
    }

    public function testDuplicatesRefuseTheMigrationBeforeAnythingChanges(): void {
        [$first, $second] = $this->products('A-1', 'A-1', 'B-2');

        try {
            Persistence::boot($this->connection, [new UqModule(2)])->migrate();
            self::fail('Two products share "A-1".');
        } catch (ContentRefused $refused) {
            self::assertCount(1, $refused->duplicates);
            self::assertSame('uq:product', $refused->duplicates[0]->type);
            self::assertSame(['sku' => 'A-1'], $refused->duplicates[0]->values);
            self::assertEqualsCanonicalizing([$first, $second], $refused->duplicates[0]->entities);
        }

        self::assertSame(PersistenceStatus::Ready, Persistence::boot($this->connection, [new UqModule(1)])->status(), 'The site stays as it was.');
    }

    public function testDistinctValuesTakeTheGroup(): void {
        $this->products('A-1', 'B-2');

        Persistence::boot($this->connection, [new UqModule(2)])->migrate();

        self::assertSame(PersistenceStatus::Ready, Persistence::boot($this->connection, [new UqModule(2)])->status());
    }

    public function testTheDatabaseDecidesWhatsEqual(): void {
        $this->products('a-1', 'A-1');
        $caseInsensitive = !str_contains(strtolower($this->connection->getDatabasePlatform()::class), 'sqlite');

        try {
            Persistence::boot($this->connection, [new UqModule(2)])->migrate();
            self::assertFalse($caseInsensitive, 'MySQL compares "a-1" and "A-1" as one value.');
        } catch (ContentRefused $refused) {
            self::assertTrue($caseInsensitive, 'SQLite compares them as two.');
            self::assertCount(1, $refused->duplicates);
        }
    }
}
