<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\StoredEntity;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;

/**
 * A content change still running, as the code on its new shape sees it: some
 * aggregates are left behind in the old shape. EntityReader and EntityWriter
 * take one, so a left-behind aggregate reads as the new shape, and what they
 * write counts as converted. Works on aggregates, by their root's id.
 */
interface PendingConversion
{
    /**
     * The new shape's schemas, keyed by identifier: what the reader and the
     * writer work with, ahead of what's stored.
     * @return array<string, EntitySchema>
     */
    public function schemas(): array;

    /**
     * The new shape's stored default values, keyed by identifier.
     * @return array<string, FieldValues>
     */
    public function defaults(): array;

    /** Whether the aggregate rooted at $id is still in the old shape. */
    public function isLeftBehind(string $id): bool;

    /** The aggregate rooted at $id as the new shape holds it; null when its type is gone. */
    public function converted(string $id): ?StoredEntity;

    /**
     * Every level table an $identifier entity has rows in, in either shape.
     * @return list<string>
     */
    public function tablesOf(string $identifier): array;

    /** Records that the aggregate rooted at $id was written in the new shape, with the token $written. */
    public function mark(string $id, string $written): void;
}
