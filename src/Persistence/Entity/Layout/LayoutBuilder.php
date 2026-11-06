<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Layout;

use Closure;
use InvalidArgumentException;
use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\UniqueGroup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Works out where each compiled schema's own fields are stored: which
 * columns, which side tables, which Collection tables. Reads schemas only,
 * never the database.
 */
final readonly class LayoutBuilder
{
    public function __construct(
        private SchemaLookup $schemas,
    ) {
    }

    /** Where $identifier's own level stores its own fields. */
    public function levelOf(string $identifier): LevelLayout {
        $schema = $this->schemas->get($identifier);
        $parts = LayoutParts::forLevel($schema->table);

        $this->addFields($parts, $schema->fields, FieldPath::of(...));
        $this->addUniqueGroups($parts, $schema, FieldPath::of(...));

        return new LevelLayout(
            identifier: $identifier,
            table: $schema->table,
            stateTable: ColumnNames::stateTable($schema->table),
            columns: $parts->columns(),
            owned: $parts->owned(),
            collections: $parts->collections(),
            uniqueIndexes: $parts->uniqueIndexes(),
        );
    }

    /**
     * @param Closure(FieldName): FieldPath $pathOf Turns one of $fields' names into its full path.
     * @param list<string> $columns The stored columns of the Embeds they're reached through.
     * @param string $idPrefix What their owner_field starts with, the same way.
     */
    private function addFields(LayoutParts $parts, FieldDescriptorCollection $fields, Closure $pathOf, array $columns = [], string $idPrefix = ''): void {
        foreach ($fields as $name => $field) {
            $identity = $field->identity() ?? throw new LogicException(sprintf('Field "%s" has no stored column: its schema was stored without one.', $name));
            $own = $identity->column ?? throw new LogicException(sprintf('Field "%s" was never given a column.', $name));
            $this->addField($parts, $field, $pathOf($name), [...$columns, $own], $idPrefix . $identity->id);
        }
    }

    /**
     * Dispatches on the field's kind.
     * @param list<string> $columns The stored columns along its path, its own last.
     * @param string $id Its full id: its Embeds' joined with its own.
     */
    private function addField(LayoutParts $parts, FieldDescriptor $field, FieldPath $path, array $columns, string $id): void {
        $column = ColumnNames::flattened($columns);

        match ($field->kind()) {
            FieldKind::Scalar, FieldKind::ValueObject, FieldKind::Reference, FieldKind::TypeReference => $parts->addColumn(new ValueColumn($path, $column, $field)),
            FieldKind::Embed => $this->addEmbed($parts, $field->target(), $path->embed(...), $columns, $id . FieldPath::EMBED),
            FieldKind::OwningReference => $parts->addOwned(new OwnedLayout($path, $id, $column, null)),
            FieldKind::Collection => $this->addCollection($parts, $field->itemDescriptor(), $path, $column, $id),
        };
    }

    /**
     * Flattens $target's whole chain into $parts, along with every unique
     * group its levels declare: each Embed site gets its own index.
     * @param Closure(FieldName): FieldPath $pathOf
     * @param list<string> $columns The stored columns of the Embeds on the way, this one last.
     */
    private function addEmbed(LayoutParts $parts, string $target, Closure $pathOf, array $columns = [], string $idPrefix = ''): void {
        $this->addFields($parts, $this->schemas->hierarchyFieldsOf($target), $pathOf, $columns, $idPrefix);

        for ($current = $target; $current !== null; $current = $this->schemas->get($current)->parent) {
            $this->addUniqueGroups($parts, $this->schemas->get($current), $pathOf);
        }
    }

    /** An Owned Collection's items are entities rows; every other kind gets its own table. */
    private function addCollection(LayoutParts $parts, FieldDescriptor $item, FieldPath $path, string $column, string $id): void {
        if ($item->kind() === FieldKind::OwningReference) {
            $parts->addOwned(new OwnedLayout($path, $id, $column, ColumnNames::countOf($column)));

            return;
        }

        $table = ColumnNames::collectionTable($parts->table, $column);
        $items = LayoutParts::forItems($table, $path);

        if ($item->kind() === FieldKind::Embed) {
            $this->addEmbed($items, $item->target(), FieldPath::of(...));
        } else {
            $items->addColumn(new ValueColumn(null, ColumnNames::VALUE, $item));
        }

        $parts->addCollection(new CollectionLayout(
            path: $path,
            flagColumn: $column,
            table: $table,
            stateTable: $item->kind() === FieldKind::Embed ? ColumnNames::stateTable($table) : null,
            itemColumns: $items->columns(),
            uniqueIndexes: $items->uniqueIndexes(),
        ));
    }

    /**
     * One unique index per group $schema declares, over the columns its
     * fields land on. Must run after those fields were added to $parts.
     * @param Closure(FieldName): FieldPath $pathOf
     */
    private function addUniqueGroups(LayoutParts $parts, EntitySchema $schema, Closure $pathOf): void {
        foreach ($schema->uniqueGroups as $group) {
            $parts->addUniqueIndex($this->uniqueColumnsOf($parts, $schema, $group, $pathOf));
        }
    }

    /**
     * @param Closure(FieldName): FieldPath $pathOf
     * @return list<string>
     */
    private function uniqueColumnsOf(LayoutParts $parts, EntitySchema $schema, UniqueGroup $group, Closure $pathOf): array {
        $columns = [];
        foreach ($group as $name) {
            $kind = $schema->fields->get($name)->kind();
            if ($kind === FieldKind::OwningReference || $kind === FieldKind::Collection) {
                throw new InvalidArgumentException(sprintf(
                    '%s declares a unique group over "%s", a %s: it has no column to index.',
                    $schema->identifier,
                    $name,
                    $kind->name,
                ));
            }

            foreach ($parts->columnsUnder($pathOf($name)) as $column) {
                if (!self::isIndexable($column->field)) {
                    throw new InvalidArgumentException(sprintf(
                        '%s declares a unique group over "%s", stored as TEXT: MySQL can\'t index it whole.',
                        $schema->identifier,
                        $column->path,
                    ));
                }

                $columns[] = $column->name;
            }
        }

        return $columns;
    }

    /** Whether $field's column can be part of a unique index: anything not stored as TEXT. */
    private static function isIndexable(FieldDescriptor $field): bool {
        return match ($field->kind()) {
            FieldKind::Scalar => $field->scalarType() !== ScalarType::Text,
            FieldKind::ValueObject => $field->indexable(),
            default => true,
        };
    }
}
