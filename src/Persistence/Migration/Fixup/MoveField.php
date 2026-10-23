<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroups;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * A value moved between two paths of a type's aggregate: across levels, into
 * or out of an Embed, across an owned boundary. A move is about data. On the
 * schemas, it only accounts for its own type losing `from` and gaining `to`,
 * when they are that type's own fields; any other type's change is a
 * difference of its own.
 */
final readonly class MoveField implements Fixup
{
    public FieldPath $from;
    public FieldPath $to;

    public function __construct(
        public string $type,
        string $from,
        string $to,
    ) {
        $this->from = FieldPath::fromString($from);
        $this->to = FieldPath::fromString($to);
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $schema = $schemas->get($this->type);

        $from = self::ownFieldOf($this->from);
        if ($from !== null && $schema->fields->has($from)) {
            $schema = $schema
                ->withFields($schema->fields->without($from))
                ->withUniqueGroups(UniqueGroups::withoutField($schema->uniqueGroups, $from));
        }

        $to = self::ownFieldOf($this->to);
        if ($to !== null && !$schema->fields->has($to) && $schemas->code($this->type)->fields->has($to)) {
            $schema = $schema->withFields($schema->fields->with($to, $schemas->codeField($this->type, $to)));
        }

        return $schemas->with($schema);
    }

    public function describe(): string {
        return sprintf('MoveField(%s, %s → %s)', $this->type, $this->from, $this->to);
    }

    /** The field $path names on the type itself, or null when it reaches past one. */
    private static function ownFieldOf(FieldPath $path): ?FieldName {
        $head = $path->head();

        return $path->tail() === null && $head instanceof FieldName ? $head : null;
    }
}
