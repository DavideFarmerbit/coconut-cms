<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\EntityRegistrar;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Polymorphism\Crate;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Polymorphism\Fruit;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Polymorphism\GroceryItem;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Polymorphism\Vegetable;

/**
 * Phase 8 Step C: Owned relationships move onto entities.owner/owner_field/position,
 * no more dedicated per-relationship child table.
 */
final class OwnedRelationshipsTest extends TestCase
{
    /** @var array<class-string, string> */
    private const array TABLES = [
        GroceryItem::class => 'groceryitem',
        Vegetable::class => 'vegetable',
        Fruit::class => 'fruit',
        Crate::class => 'crate',
    ];

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('PRAGMA foreign_keys = ON');

        return $connection;
    }

    public function testASingularOwnedReferenceAndAnOwnedCollectionOfTheSameTypeRoundTripDisambiguatedByOwnerField(): void
    {
        $connection = $this->connection();
        $entityManager = EntityRegistrar::register($connection, [Vegetable::class, Fruit::class, Crate::class]);

        $id = $entityManager->repository(Crate::class)->insert(new Crate(
            'C1',
            new Vegetable('Carrot', 'orange'),
            [new Fruit('Apple', false), new Vegetable('Beet', 'red')],
        ));

        // No dedicated child table was created for either Owned field.
        $tableNames = $connection->createSchemaManager()->listTableNames();
        self::assertNotContains('crate_featured', $tableNames);
        self::assertNotContains('crate_items', $tableNames);

        $freshManager = new EntityManager($connection, self::TABLES);
        $found = $freshManager->repository(Crate::class)->find($id);

        self::assertInstanceOf(Vegetable::class, $found->featured);
        self::assertSame('Carrot', $found->featured->name);
        self::assertSame('orange', $found->featured->color);

        self::assertCount(2, $found->items);
        self::assertInstanceOf(Fruit::class, $found->items[0]);
        self::assertSame('Apple', $found->items[0]->name);
        self::assertFalse($found->items[0]->seedless);
        self::assertInstanceOf(Vegetable::class, $found->items[1]);
        self::assertSame('Beet', $found->items[1]->name);
        self::assertSame('red', $found->items[1]->color);
    }

    public function testOwnedItemsAreLazyUntilTouched(): void
    {
        $connection = $this->connection();
        $entityManager = EntityRegistrar::register($connection, [Vegetable::class, Fruit::class, Crate::class]);

        $id = $entityManager->repository(Crate::class)->insert(new Crate(
            'C2',
            new Vegetable('Carrot', 'orange'),
            [new Fruit('Apple', false)],
        ));

        $freshManager = new EntityManager($connection, self::TABLES);
        $found = $freshManager->repository(Crate::class)->find($id);

        self::assertTrue((new ReflectionClass(Vegetable::class))->isUninitializedLazyObject($found->featured));
        self::assertTrue((new ReflectionClass(Fruit::class))->isUninitializedLazyObject($found->items[0]));

        self::assertSame('orange', $found->featured->color);

        self::assertFalse((new ReflectionClass(Vegetable::class))->isUninitializedLazyObject($found->featured));
    }

    public function testDeletingTheOwnerCascadesBothTheSingularOwnedReferenceAndTheOwnedCollection(): void
    {
        $connection = $this->connection();
        $entityManager = EntityRegistrar::register($connection, [Vegetable::class, Fruit::class, Crate::class]);

        $id = $entityManager->repository(Crate::class)->insert(new Crate(
            'C3',
            new Vegetable('Carrot', 'orange'),
            [new Fruit('Apple', false), new Vegetable('Beet', 'red')],
        ));

        self::assertSame(4, (int) $connection->fetchOne('SELECT COUNT(*) FROM entities'), 'crate + featured + 2 items');

        $entityManager->repository(Crate::class)->delete($id);

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM entities'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM vegetable'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM fruit'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM groceryitem'));
    }

    public function testUpdatingTheOwnedSingularReferenceReplacesTheOldOneEntirely(): void
    {
        $connection = $this->connection();
        $entityManager = EntityRegistrar::register($connection, [Vegetable::class, Fruit::class, Crate::class]);
        $repository = $entityManager->repository(Crate::class);

        $id = $repository->insert(new Crate('C4', new Vegetable('Carrot', 'orange'), []));
        $oldFeaturedId = (string) $connection->fetchOne("SELECT id FROM entities WHERE owner = ? AND owner_field = 'featured'", [$id]);

        $updated = $repository->update($id, ['featured' => new Fruit('Apple', true)]);

        self::assertInstanceOf(Fruit::class, $updated->featured);
        self::assertSame('Apple', $updated->featured->name);
        self::assertSame(
            0,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM entities WHERE id = ?', [$oldFeaturedId]),
            'the old owned item is gone entirely, not just unlinked',
        );
    }

    public function testQueryCannotFilterOrSortByAnOwnedReferenceField(): void
    {
        $connection = $this->connection();
        $entityManager = EntityRegistrar::register($connection, [Vegetable::class, Fruit::class, Crate::class]);

        $this->expectException(LogicException::class);

        $entityManager->query(Crate::class)->where('featured', '=', '1')->get();
    }
}
