<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use XyloIsCoding\CoconutCms\Api\Operation\OperationResult;
use XyloIsCoding\CoconutCms\Api\Output\ForHumans;
use XyloIsCoding\CoconutCms\Api\Output\TextTable;
use XyloIsCoding\CoconutCms\Persistence\Entity\Record\EntityRecord;

/**
 * One standalone entity, as the caller may read it: its type, its id, and
 * its values by field. A value is in stored form; an Embed or an owned
 * object is a nested `{type, id, values}`, an Embed's id null; a Reference
 * is `{type, id}`; a Collection a list. A field the caller may not read is
 * left out.
 */
final readonly class EntityData implements OperationResult, ForHumans
{
    /** @param array<string, mixed> $values By field name, as EntityRecord holds them. */
    public function __construct(
        public string $type,
        public string $id,
        public array $values,
    ) {
    }

    public static function of(EntityRecord $record): self {
        return new self($record->type, (string) $record->id, $record->values);
    }

    public function table(): TextTable {
        $rows = [];
        foreach ($this->values as $name => $value) {
            $rows[] = [$name, self::text($value)];
        }

        return new TextTable(['FIELD', 'VALUE'], $rows, sprintf('%s %s', $this->type, $this->id));
    }

    /** $value in one line: a scalar as is, anything else as JSON. */
    public static function text(mixed $value): string {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
