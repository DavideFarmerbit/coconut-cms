<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelLayout;

/**
 * Creates the content tables on a fresh database, and seeds the Cms row the
 * rest point at. Not a migration: it only ever creates, never alters.
 */
final readonly class EntityTables
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Creates every table $levels need, then the Cms row. Cms's own level must
     * be among them.
     * @param list<LevelLayout> $levels
     */
    public function create(array $levels): void {
        $cms = $this->cmsLevelIn($levels);

        $this->connection->createSchemaManager()->createSchemaObjects(DatabaseSchema::of($levels));

        // Its own owner, so the self-referencing foreign key has a row to
        // point at. Never written through the ordinary write path.
        $this->connection->transactional(static function (Connection $connection) use ($cms): void {
            $connection->insert(DatabaseSchema::ENTITIES, [
                'id' => EntityIds::NIL,
                'owner' => EntityIds::NIL,
                'owner_field' => '',
                'position' => -1,
                'concrete_identifier' => Cms::IDENTIFIER,
            ]);
            $connection->insert($cms->table, ['id' => EntityIds::NIL, 'owner' => EntityIds::NIL, 'owner_field' => '']);
            $connection->insert($cms->stateTable, ['id' => EntityIds::NIL]);
        });
    }

    /** @param list<LevelLayout> $levels */
    private function cmsLevelIn(array $levels): LevelLayout {
        foreach ($levels as $level) {
            if ($level->identifier === Cms::IDENTIFIER) {
                return $level;
            }
        }

        throw new InvalidArgumentException(sprintf('The levels to create must include %s, the row every other row without an owner points at.', Cms::IDENTIFIER));
    }
}
