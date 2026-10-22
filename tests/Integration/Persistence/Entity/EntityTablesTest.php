<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Entity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityTables;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelLayout;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;
use XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Layout\LayoutFixtures;

#[Entity]
final class EntityTablesNote
{
    #[Scalar(ScalarType::String)]
    private string $text = '';
}

final class EntityTablesTest extends TestCase
{
    private Connection $connection;
    /** @var list<LevelLayout> */
    private array $levels;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();

        $builder = LayoutFixtures::builderFor([Cms::class, EntityTablesNote::class]);
        $this->levels = [$builder->levelOf(Cms::IDENTIFIER), $builder->levelOf('default:entity_tables_note')];
        $this->dropTables();
    }

    protected function tearDown(): void {
        $this->dropTables();
        $this->connection->close();
    }

    private function dropTables(): void {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (DatabaseSchema::of($this->levels)->getTables() as $table) {
            $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table->getName()));
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** An entities row for a new entity, owned by $owner. */
    private function insertEntity(string $owner): string {
        $id = EntityIds::generate();
        $this->connection->insert(DatabaseSchema::ENTITIES, [
            'id' => $id,
            'owner' => $owner,
            'concrete_identifier' => 'default:entity_tables_note',
        ]);

        return $id;
    }

    public function testCreateSeedsTheCmsRowAsItsOwnOwner(): void {
        (new EntityTables($this->connection))->create($this->levels);

        self::assertSame(
            ['id' => EntityIds::NIL, 'owner' => EntityIds::NIL, 'owner_field' => '', 'position' => -1, 'concrete_identifier' => Cms::IDENTIFIER],
            $this->connection->fetchAssociative('SELECT * FROM entities'),
        );
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cms WHERE id = ?', [EntityIds::NIL]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cms__state WHERE id = ?', [EntityIds::NIL]));
    }

    public function testARowWithNoOwnerGivenBelongsToCms(): void {
        (new EntityTables($this->connection))->create($this->levels);
        $id = EntityIds::generate();

        $this->connection->insert(DatabaseSchema::ENTITIES, ['id' => $id, 'concrete_identifier' => 'default:entity_tables_note']);

        self::assertSame(EntityIds::NIL, $this->connection->fetchOne('SELECT owner FROM entities WHERE id = ?', [$id]));
    }

    public function testTheDatabaseRefusesToDeleteAnOwnerThatStillOwnsARow(): void {
        (new EntityTables($this->connection))->create($this->levels);
        $owner = $this->insertEntity(EntityIds::NIL);
        $this->insertEntity($owner);

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->connection->delete(DatabaseSchema::ENTITIES, ['id' => $owner]);
    }

    public function testCreateWithoutTheCmsLevelIsRejectedBeforeTouchingTheDatabase(): void {
        try {
            (new EntityTables($this->connection))->create([$this->levels[1]]);
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            self::assertFalse($this->connection->createSchemaManager()->tablesExist([DatabaseSchema::ENTITIES]));
        }
    }
}
