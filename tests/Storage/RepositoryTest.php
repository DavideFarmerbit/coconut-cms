<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\Repository;
use XyloIsCoding\CoconutCms\Storage\RowMapper;
use XyloIsCoding\CoconutCms\Storage\SchemaBuilder;
use XyloIsCoding\CoconutCms\Storage\SchemaSynchronizer;
use XyloIsCoding\CoconutCms\Storage\ValidationException;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Address;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Product;

final class RepositoryTest extends TestCase
{
    private Connection $connection;
    private Repository $repository;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $fields = PrototypeShape::ofClass(Product::class);
        (new SchemaSynchronizer($this->connection))->sync(SchemaBuilder::tableFor('products', $fields));

        $this->repository = (new EntityManager($this->connection, [Product::class => 'products']))->repository(Product::class);
    }

    private function product(string $sku = 'ABC-1'): Product
    {
        return new Product($sku, 'Widget', 'A fine widget', new Address('Rome', 'Via Roma 1'), true);
    }

    public function testInsertThenFindReturnsAMatchingEntity(): void
    {
        $id = $this->repository->insert($this->product());

        $found = $this->repository->find($id);

        self::assertSame('ABC-1', $found->sku);
        self::assertSame('Rome', $found->address->city);
    }

    public function testFindTwiceReturnsTheSameInstanceThroughTheIdentityMap(): void
    {
        $id = $this->repository->insert($this->product());

        self::assertSame($this->repository->find($id), $this->repository->find($id));
    }

    public function testFindReturnsNullForAnUnknownId(): void
    {
        self::assertNull($this->repository->find('999'));
    }

    public function testUpdateChangesOnlyTheGivenFieldsAndPersistsThem(): void
    {
        $id = $this->repository->insert($this->product());

        $this->repository->update($id, ['name' => 'Widget Pro']);

        $found = $this->repository->find($id);
        self::assertSame('Widget Pro', $found->name);
        self::assertSame('A fine widget', $found->description, 'unrelated fields must be untouched');
    }

    public function testDeleteRemovesTheRowAndForgetsIt(): void
    {
        $id = $this->repository->insert($this->product());

        $this->repository->delete($id);

        self::assertNull($this->repository->find($id));
    }

    public function testInsertRejectsAValueThatFailsItsValidator(): void
    {
        $this->expectException(ValidationException::class);

        $this->repository->insert(new Product('', 'Widget', '', new Address('Rome', ''), true));
    }

    public function testInsertRejectsADuplicateUniqueValueWithAFriendlyMessageBeforeHittingTheDatabase(): void
    {
        $this->repository->insert($this->product('ABC-1'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('"ABC-1" is already taken for field "sku"');

        $this->repository->insert($this->product('ABC-1'));
    }

    public function testUpdateAllowsKeepingAnEntitysOwnUniqueValue(): void
    {
        $id = $this->repository->insert($this->product('ABC-1'));

        $updated = $this->repository->update($id, ['sku' => 'ABC-1']);

        self::assertSame('ABC-1', $updated->sku);
    }

    public function testTheUniqueConstraintIsReallyEnforcedAtTheDatabaseLevelToo(): void
    {
        $fields = PrototypeShape::ofClass(Product::class);
        $this->connection->insert('products', RowMapper::toRow($fields, [
            'sku' => 'ABC-1',
            'name' => 'Widget',
            'description' => '',
            'address' => new Address('Rome', ''),
            'active' => true,
        ]));

        $this->expectException(UniqueConstraintViolationException::class);

        // bypasses the repository's own friendly pre-check to prove the real constraint holds independently
        $this->connection->insert('products', RowMapper::toRow($fields, [
            'sku' => 'ABC-1',
            'name' => 'Widget 2',
            'description' => '',
            'address' => new Address('Milan', ''),
            'active' => true,
        ]));
    }
}
