<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\EntityRegistrar;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Polymorphism\Basket;
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
}
