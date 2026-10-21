I've saved the identity decision to memory as well.

The next piece is step 1, the physical layout. Here is the shape I'd propose. It follows the v2 doc wherever v3 hasn't changed anything; the points that need your call are listed at the end.

## Tables

**`entities`** (one shared table)
- `id`: the UUID, primary key.
- `owner`: not null, foreign key to `entities.id`, `RESTRICT`. Defaults to the nil UUID, which is the seeded `Cms` row.
- `owner_field`: not null, defaults to `''`.
- `position`: not null, defaults to `-1`.
- `concrete_identifier`: the row's actual class.

**One table per inheritance level** (`EntitySchema::$table`)
- `id`: primary key, and a foreign key to `entities.id`.
- `owner`, `owner_field`: the copies, kept so unique indexes can include them.
- One column per stored path of this level's own fields:
    - Scalar and value object: one column.
    - Reference: one id column, foreign key to `entities.id`, `SET NULL`.
    - Embed: its leaves, joined with `__` (`chassis__engine__piston__diameter`).
    - OwningReference: no column. The owned row is found through its own `owner`/`owner_field`.
    - Owned collection: `<field>_count`.
- Every content column is nullable, as the default tracking proposal requires.
- Unique groups become `UNIQUE (owner, owner_field, ...fields)`.

**One table per non-owned collection** (`<level table>__<field>`)
- `owner`: foreign key to the level's row, `CASCADE`.
- `position`.
- Primary key `(owner, position)`.
- The value columns, as decided last time.

**A touched-state side table per level** (`<level table>__state`)
- `id`, 1:1 with the level row.
- One boolean per path an instance can hold at this level:
    - every leaf, Embed leaves included;
    - each OwningReference's bare key, standing for "initialized: the held class was chosen";
    - each collection's bare key, standing for "initialized: the collection was touched as a whole".

That flag set mirrors exactly what we built for schema values. An instance's touched flags are its own sparse set of stored paths, and the collection rule is all-or-nothing, the same as in the compiler. Hydration can then treat an instance as one more source in front of its class in the resolver. The only difference from the schema value store is where the values physically live.

## Column types

| Field | Column |
|---|---|
| String | `VARCHAR(255)` |
| Text | `TEXT` |
| Int | `BIGINT` |
| Bool | `BOOLEAN` |
| Float | `DOUBLE` |
| Date / DateTime | `DATE` / `DATETIME` (immutable) |
| Decimal | `DECIMAL(?, ?)` (open, see below) |
| Enum | the backed value: `VARCHAR` for a string enum, `BIGINT` for an int enum |
| ValueObject | `TEXT` (its `toStorage()` string) |
| ids | Doctrine `guid` (`CHAR(36)` on MySQL) |

## Needs your call

1. **Decimal precision.** `FieldDescriptor` has nowhere to put it. Options: a fixed default such as `DECIMAL(20, 6)`, or `#[Scalar]` gains `precision`/`scale` arguments.
2. **MySQL's 64-character identifier limit.** `__` chains plus table prefixes will exceed it, for example `engine__piston__...` or a collection table named `car__wheels`. Options: reject at compile time with a clear error, or shorten deterministically with a hash suffix. I'd reject: names stay readable, and a rename fixup handles it.
3. **The touched flag storage.** One boolean column per path, as the proposal says, or one JSON column holding the touched path set? Booleans can be queried and match the "no blobs" stance. JSON avoids a migration on the side table every time a field is added. I'd keep booleans, for consistency with everything else being real columns.
4. **The id type.** `CHAR(36)` is readable in a database client. `BINARY(16)` is smaller and makes faster indexes. I'd start with `CHAR(36)`.