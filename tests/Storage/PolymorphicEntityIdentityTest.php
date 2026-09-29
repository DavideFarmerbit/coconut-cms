<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\EntityRegistrar;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Polymorphism\Basket;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Polymorphism\Fruit;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Polymorphism\GroceryItem;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Polymorphism\Vegetable;

/**
 * Phase 8 Step B: with entities as every chain's shared root, a concrete subtype
 * assigned anywhere its base type is declared reads back as that subtype, resolved
 * from entities.concrete_type instead of a per-chain discriminator.
 */
final class PolymorphicEntityIdentityTest extends TestCase
{
    /** @var array<class-string, string> */
    private const array TABLES = [
        GroceryItem::class => 'groceryitem',
        Vegetable::class => 'vegetable',
        Fruit::class => 'fruit',
        Basket::class => 'basket',
    ];

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('PRAGMA foreign_keys = ON');

        return $connection;
    }

    public function testFindOnTheBaseIdentifierStillResolvesTheConcreteSubtype(): void
    {
        $connection = $this->connection();
        $entityManager = EntityRegistrar::register($connection, [Vegetable::class, Basket::class]);

        $vegetableId = $entityManager->repository(Vegetable::class)->insert(new Vegetable('Carrot', 'orange'));

        $foundAsBase = $entityManager->repository(GroceryItem::class)->find($vegetableId);

        self::assertInstanceOf(Vegetable::class, $foundAsBase);
        self::assertSame('orange', $foundAsBase->color);
        self::assertSame('Carrot', $foundAsBase->name);
    }

    public function testAConcreteSubtypeReachedThroughAReferenceFieldIsLazilyHydratedAsThatSubtype(): void
    {
        $connection = $this->connection();
        $entityManager = EntityRegistrar::register($connection, [Vegetable::class, Basket::class]);

        $vegetableId = $entityManager->repository(Vegetable::class)->insert(new Vegetable('Carrot', 'orange'));
        $vegetable = $entityManager->repository(Vegetable::class)->find($vegetableId);
        $basketId = $entityManager->repository(Basket::class)->insert(new Basket('B1', $vegetable));

        // A fresh EntityManager/IdentityMap, same already-synced schema, so the reference is genuinely reloaded, not reused.
        $freshManager = new EntityManager($connection, self::TABLES);
        $basket = $freshManager->repository(Basket::class)->find($basketId);

        self::assertInstanceOf(Vegetable::class, $basket->item, 'the declared type is GroceryItem, the concrete row is a Vegetable');
        self::assertSame('orange', $basket->item->color);
        self::assertSame('Carrot', $basket->item->name);
    }

    public function testAConcreteSubtypeReachedThroughAQueryResultRowIsLazilyHydratedAsThatSubtype(): void
    {
        $connection = $this->connection();
        $entityManager = EntityRegistrar::register($connection, [Vegetable::class, Basket::class]);

        $vegetableId = $entityManager->repository(Vegetable::class)->insert(new Vegetable('Carrot', 'orange'));
        $vegetable = $entityManager->repository(Vegetable::class)->find($vegetableId);
        $entityManager->repository(Basket::class)->insert(new Basket('B1', $vegetable));

        $freshManager = new EntityManager($connection, self::TABLES);
        $page = $freshManager->query(Basket::class)->where('label', '=', 'B1')->get();

        self::assertCount(1, $page->items);
        $basket = $page->items[0];

        self::assertTrue((new ReflectionClass(Vegetable::class))->isUninitializedLazyObject($basket->item), 'must still be lazy, a Query row must not eagerly resolve it');
        self::assertInstanceOf(Vegetable::class, $basket->item);
        self::assertSame('orange', $basket->item->color);
    }

    public function testQueryWithoutHydrateConcreteTypesReturnsOnlyBaseTypedObjectsEvenForForeignRows(): void
    {
        $connection = $this->connection();
        $entityManager = EntityRegistrar::register($connection, [Vegetable::class, Fruit::class]);

        $entityManager->repository(GroceryItem::class)->insert(new GroceryItem('Salt'));
        $entityManager->repository(Vegetable::class)->insert(new Vegetable('Carrot', 'orange'));
        $entityManager->repository(Fruit::class)->insert(new Fruit('Apple', false));

        $freshManager = new EntityManager($connection, self::TABLES);
        $page = $freshManager->query(GroceryItem::class)->orderBy('name')->get();

        self::assertCount(3, $page->items);
        foreach ($page->items as $item) {
            self::assertSame(GroceryItem::class, $item::class, 'plain Query stays base-typed, never resolves the concrete subtype on its own');
        }
    }

    public function testQueryWithHydrateConcreteTypesResolvesEveryRowsRealConcreteSubtypeGroupedByDistinctType(): void
    {
        $connection = $this->connection();
        $entityManager = EntityRegistrar::register($connection, [Vegetable::class, Fruit::class]);

        $entityManager->repository(GroceryItem::class)->insert(new GroceryItem('Salt'));
        $entityManager->repository(Vegetable::class)->insert(new Vegetable('Carrot', 'orange'));
        $entityManager->repository(Vegetable::class)->insert(new Vegetable('Beet', 'red'));
        $entityManager->repository(Fruit::class)->insert(new Fruit('Apple', false));
        $entityManager->repository(Fruit::class)->insert(new Fruit('Grape', true));

        $freshManager = new EntityManager($connection, self::TABLES);
        $page = $freshManager->query(GroceryItem::class)->orderBy('name')->hydrateConcreteTypes()->get();

        self::assertCount(5, $page->items);

        // Sort order (by name) is preserved across a mix of base-typed and foreign-typed rows.
        $names = array_map(static fn (GroceryItem $item): string => $item->name, $page->items);
        self::assertSame(['Apple', 'Beet', 'Carrot', 'Grape', 'Salt'], $names);

        $byName = [];
        foreach ($page->items as $item) {
            $byName[$item->name] = $item;
        }

        self::assertSame(GroceryItem::class, $byName['Salt']::class);
        self::assertInstanceOf(Vegetable::class, $byName['Carrot']);
        self::assertSame('orange', $byName['Carrot']->color);
        self::assertInstanceOf(Vegetable::class, $byName['Beet']);
        self::assertSame('red', $byName['Beet']->color);
        self::assertInstanceOf(Fruit::class, $byName['Apple']);
        self::assertFalse($byName['Apple']->seedless);
        self::assertInstanceOf(Fruit::class, $byName['Grape']);
        self::assertTrue($byName['Grape']->seedless);
    }
}
