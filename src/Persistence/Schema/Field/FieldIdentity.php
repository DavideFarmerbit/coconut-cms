<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

/**
 * What a stored field is, whatever it's called: its id and the column holding
 * its values. Neither comes from its name, so a rename changes neither. Stored
 * in the schema, and never derived: both can differ between databases.
 */
final readonly class FieldIdentity
{
    /**
     * @param string $id Generated once, never changed, never reused. What
     *        owner_field holds.
     * @param string|null $column The physical name of what holds its current
     *        values: its column, its Collection table's suffix, or its Embed's
     *        column prefix. Null until one is generated: a retyped field needs
     *        a new one.
     */
    public function __construct(
        public string $id,
        public ?string $column,
    ) {
    }

    /** The same field, with new content on its way: it needs a new column. */
    public function withoutColumn(): self {
        return new self($this->id, null);
    }
}
