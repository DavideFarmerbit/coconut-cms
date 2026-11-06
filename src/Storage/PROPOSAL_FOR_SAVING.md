# Proposal: Saving and Migrating

Status: proposed. Stages 1 and 2 are built, the rest isn't. `RetypeSpikeTest`
proves parts of the design in plain SQL. "Open points" lists what isn't settled.

## The standard

An e-commerce site that migrates in production, under load. A developer's only
worry is the classes handling backward compatibility. The CMS does the rest.

## Goals

- **One flexible save.** One batch can hold entity changes, schema changes and a
  plugin's own changes.
- **Only what must fail fails.** An entity missing a dependency is rejected, with
  what depends on it. A type using a missing type is rejected. Unrelated entities
  and types are saved.
- **No all-or-nothing saves.** A batch isn't atomic. Each change gets its own
  result, and each failure is explicit.
- **No downtime for a migration.** Types keep being read and written while their
  content changes. Nothing blocks the site.
- **Simultaneous saves.** Two saves, both with schema and entity changes, both end
  well. If they share nothing, both succeed. If they conflict, only the conflicting
  changes are rejected.

## What the code does today

Already local:

- `Migrator::changedTypes()` finds the types a migration changes, from per-type
  fingerprints and dependencies.
- `rootsHolding()` moves only the aggregates holding those types.
- A move reads one aggregate, runs the fixups on stored-form values, and writes it.
  Converters never need the old class.
- A stopped migration is recovered: a failure in the move restores the tables, and
  the next `migrate()` cleans up after a crash.

In the way of the standard:

- A move runs in one transaction. See the measure under "Prototype results".
- `migration_state` is one row. One started migration blocks every read and write.
- The schema revision is one number, so any schema save makes every other one
  stale.
- `StateKey` is one hash, so a pending change anywhere blocks everything. An edit
  to any rule file makes a migration pending.
- `checkContent()` reads every affected aggregate before starting.
- `expand()` and `contract()` compare the whole database with one target, so two
  migrations can't overlap.
- `MigrationState::assertSettled()` checks once and remembers, so a request past it
  can still write after a migration starts.
- Content is dense, so an added field or a changed default rewrites every row.

Wider than it needs to be:

- A type holding a changed type only through a Reference moves too.
- Any fixup changing types moves every type declaring a type reference, whatever its
  base.

## Field identity

A field has two stored properties, and neither comes from its name:

- **Field id:** generated once, never changed, never reused.
- **Column:** the physical name of what holds its current values, opaque, such as
  `f_a1b2c3`: its column, its Collection table's suffix, or what an Embed adds to
  the names of its leaves, which hash the stored columns along their path. A new
  one is generated only when the content needs one.

| Change | Field id | Column | Data moved |
|---|---|---|---|
| Rename, of any field | Same | Same | None |
| Retype, or new meaning for the values | Same | New | Backfill |
| Remove, then add the same name | New | New | None |

- Both are stored in the schema and never derived. Only `LevelLayout` and
  `LayoutBuilder` work out a column today, so this changes one place.
- A column can't be worked out from a field's name and type: euros becoming cents
  keeps the type, and int to text to int comes back to a column that may still be
  in use.
- Column names and field ids can differ between databases: a fresh install never
  ran the old steps. Nothing may hard-code one. Everything goes through the layout.
  A full dump keeps working, since the schema travels with the data, and an export
  works through values. Copying rows between databases by hand doesn't.
- Ids exist only in the stored schema. A native rename still needs a declared step,
  which carries the id across.
- The schema keeps a field's former names for a grace period, for old input.
- `owner_field` and `_owner_field` hold the field id in place of the path, joined
  along an Embed path. They're the only row-level data depending on a field, so no
  rename touches a row. They're limited to 255 characters.
- A migration still rewrites the aggregates a rename changes, until stage 4. A rename
  moves no column and no table now, but the move runs as before.

## Migrating

Changes fall into two groups.

**Definition changes** only touch the stored schema, and take effect at once: a new
type, a rename, rules, permissions, making a field optional.

**Content changes** need a new column, table or level: a retype, new meaning for the
values, a value becoming a list, a reparent. They go through four steps.

1. **Expand.** New columns and tables go beside the old ones, never in their place.
   A marker column goes on the level the old code rewrites. This is short DDL, and
   only one runs at a time. It uses a short `lock_wait_timeout`, so an `ALTER`
   waiting on a long transaction fails and doesn't queue up that table's traffic.
2. **Backfill.** A background job converts rows in small batches, throttled and
   resumable, without holding the migration lock. Each row is converted under the
   lock a save takes, on its aggregate's root, so no save lands between the read and
   the write. Old columns stay filled.
3. **Switch.** Readers and writers move to the new columns.
4. **Contract.** Old columns and markers go, once no row is left and no old code
   runs. It can come much later.

**A write token and a marker replace dual write.** Every level table has one
`_written` column, and every save sets it to a fresh value on each level row it
writes, whatever columns it changes. The marker holds the token the backfill saw. A
row is left behind when the two differ. So an old release needs no change and knows
nothing about a migration, however it writes the row. The backfill converts that row
again.

- There's one token per level row, not one per field. A save changing another field
  on that level makes the row convert again, which is wasteful but harmless. A save
  touching another level leaves it alone.
- A Collection table has no token. A save changing only a Collection's items must
  still set the token on the owner's level row.
- An owned object has its own level rows, so it has its own tokens.
- The `entities` row has a token too, set by every save of the aggregate. It's the
  version a client sends back to detect a stale save.

**Reading a row left behind.** An old row is read through its stored schema into
stored-form values, and the converter brings them up. So the new class is filled
correctly, by id, with no old class. A query on the new field misses that row until
the next scan.

**Translation** lives in converter classes on stored-form values, up and optionally
back. The backfill and the load path run the same code. A failure in one row leaves
that row behind and reports it. It stops neither the batch nor the site.

**Unique indexes** go on the new column before the backfill. Empty values don't
clash, so a clash stops only the row causing it. The switch waits until no row has
failed.

**A gained level** is a new table with a foreign key to `entities`. Rows missing from
it are found by what's missing. If its values come from fields the old release can
change, it also needs a marker on a level the old release rewrites.

### Native deploys

A deploy may run one step before an atomic code switch:

1. `migrate --prepare`, from the new release on disk, expands and backfills while the
   old release serves traffic.
2. The code switches.
3. `migrate --finish` scans until no row is left, then contracts after a grace period.

The step is optional. Without it the converter still serves each entity by id, but
queries on new fields are incomplete until the backfill ends, and `check()` says so.

Rows keep being left behind while an old release is saving, so a backfill reaches
zero only after the switch.

### Editor saves

There's no code switch, so a schema change returns once expand has run, with the
status `migrating`. The backfill runs in the background. The switch comes when it
ends, and commits only the stored schema.

### Old input

A page loaded before the switch sends the old shape after it, such as a customer at
checkout. A save must not fail for that. Changes by old paths are translated before
`StoredFormEditor`: renames and converters automatically, and a pre-edit hook for
custom cases, on the `changes` map in stored form. It lasts a grace period after the
switch. CMS operations and CMS functions handling post data pass through here. Code
reading `$_POST` directly handles its own backward compatibility.

### Known risk: rows left behind

An old-release save leaves the new column empty until the next scan. A query on the
new field misses that row for that moment, and a unique index doesn't cover it. To
watch it: a test covering that sequence, a count of rows left per type in `check()`,
and an optional short write pause at the switch, per type.

## Saving

- **A batch** is everything sent in one save request. It has an id made in the admin.
- **A change** is one thing to save, with a stable key: an entity's aggregate
  (`entity:<id>`), one type's schema operations (`schema:type:<identifier>`), or a
  plugin's own change.
- **A dependency** says one change needs another, or needs something stored.
- **A group** is a set of changes that depend on each other in a loop. A group saves
  whole or not at all. It's the only atomic thing, and only because no order can save
  it. A change with no loop is a group of one.
- **A result** says what happened to each change.

```json
{
  "batch": "<id>",
  "results": [
    {"key": "schema:type:default:weapon", "status": "saved"},
    {"key": "schema:type:default:sword", "status": "skipped", "because": ["entity:<id>"]},
    {"key": "entity:<id>", "status": "rejected", "failures": [{"code": "validation_failed", "path": "name", "message": "..."}]}
  ]
}
```

| Status | Meaning |
|---|---|
| `saved` | It's stored. |
| `migrating` | A schema change is accepted, and its backfill is running. |
| `rejected` | It failed its own check or write. |
| `skipped` | Something it depends on failed. It lists what. |
| `stale` | What it was built on changed. The user reviews it. |
| `unavailable` | It needs something not ready yet. It can be sent again. |

What wasn't saved stays unsaved in the admin. The server tries once and never retries
on its own.

### Dependencies

The server works dependencies out from the changes, and never trusts the client for
them:

- an entity needs its type, and each entity it references;
- a type needs its parent, and each type its fields name;
- a plugin's handler declares its changes' dependencies.

A dependency is met when it's stored, or when a change in this batch saves it. The
admin works them out too, but only to ask "this also needs X; save it too?". If it
misses one, the server reports exactly that.

Rejection spreads only along dependencies:

1. The changes are sorted into groups, dependencies first.
2. Each group is tried in that order.
3. When a group fails, what depends on it is `skipped`.
4. Everything else goes on.

### Running a batch

1. **Log.** The batch is stored in the save log.
2. **Plan.** The server finds dependencies and groups. A change with a dependency that
   can't be met is rejected here.
3. **Schema.** Definition changes commit. Content changes run expand and return
   `migrating`.
4. **Entities and plugin changes.** Each group runs in its own transaction, in
   dependency order. A group of several entities is written in passes: rows with
   references empty, then references, then deletes. The transaction locks its entities
   in sorted id order, compares their versions, and runs the normal validation and
   permissions.
5. **After commit.** Queued work runs. It must be safe to run twice.
6. **Answer.** The log and the response get every result.

The passes work because a Reference is always nullable. An owned object is written
with its owner.

A new type has no rows, so it's active at once. A new class and its content item both
save in the same batch.

### Two simultaneous saves

Save A changes `boat` and writes a boat. Save B changes `character` and writes a
character.

**They share nothing.** Each expand runs in turn and takes moments. Both backfills run
in the background. Both entities are written. Nothing else is affected.

**They both change `boat`.** A's expand runs. B finds `boat` at a newer version, so its
boat changes are `stale`, what depends on them is `skipped`, and its unrelated changes
are saved.

**B only writes a boat, while A's backfill runs.** B's save is ordinary. It writes the
old shape and clears the marker, and the backfill converts the row again.

**Staleness is per type.** Each schema change carries the fingerprint of each type it's
built on. Fingerprints exist already, so the single revision can go.

### Failure reports

Each failure is data: its change's key, a stable code, a plain message, and optionally
a path and details.

- The server reports every failure it can find, not only the first.
- `skipped` names the changes that caused it, so the report gives the root cause.
- The admin gives each unit its own failures. An entity's unit puts each error at its
  field.
- Words have three levels: core defaults per kind, a unit adding to them, or a unit
  replacing them.
- A failed row in a backfill is reported per row, and listed by `check()`.

The content browser could say:

> "Fire Sword" was saved, but it couldn't be added to the browser.
> Its folder "Weapons" was deleted.

### The save log

Every batch gets a row: its id, actor, time, changes and results.

- **A record** of who saved what, and of each failure.
- **Safe retries.** If a response is lost, the admin sends the same id again and gets
  the stored results.

## In the admin

Whatever holds unsaved work registers a provider. Providers declare what's pending.
The core collects, asks and sends, and never decides what saving means.

```ts
interface SaveProvider {
    /** What it has unsaved now: one unit per thing the user recognizes. */
    pending(): readonly PendingSave[];
}

interface PendingSave {
    /** Stable while it's unsaved, such as `game:class:<draft id>`. */
    readonly key: string;
    /** What the user sees, such as "Fire Sword". */
    readonly label: string;
    /** What it adds to the batch, worked out from its current state. */
    changes(): readonly SaveChange[];
    /** The keys of what it needs saved with it. */
    needs(): readonly string[];
    /** Gets the results of its changes: clears what's saved, shows the failures. */
    applied(results: readonly SaveResult[]): void;
    /** Its own words for what failed; `defaults` are the core's. Optional. */
    describe?(results: readonly SaveResult[], defaults: readonly string[]): readonly string[];
    /** Called when the user discards it. */
    discard(): void;
}
```

A unit works out `changes()` from what's stored and what's there now. So a new class
declares a type and a content item. If only the type saved, it then declares only the
item. Two units claiming one change key send it once.

A Save button means "this unit and what it needs". Save all means every pending unit.
Signing out, closing a tab and leaving the page read the same list.

Saving part of the schema draft needs two things: `DraftWriter` writing chosen types,
and saved edits becoming the draft's new starting point.

This replaces `SaveParticipant`, the save guards and the "Schema changes" tool.

## On the server

A module registers one handler per kind of change:

```php
interface SaveHandler
{
    /** The kind it handles, such as "media:files". */
    public function kind(): string;

    /** @return list<string> The keys of what $change needs. */
    public function dependencies(SaveChange $change, SaveContext $context): array;

    /** Checks $group; reports each failure to $context. */
    public function check(array $group, SaveContext $context): void;

    /** Writes $group, inside its transaction. */
    public function write(array $group, SaveContext $context): void;

    /** Does what can't be rolled back, after the commit. Safe to run twice. */
    public function afterCommit(array $group, SaveContext $context): void;
}
```

`entity` is a handler like any other. `schema` is the one special kind.

## Stages

Each stage works on its own.

1. **Recover stopped migrations.** Done.
2. **Field identity.** Done. Field ids and stored columns in the schema, `owner_field`
   holding ids, and the write tokens. Dev databases are reset. See "Field identity" in
   `ARCHITECTURE_V3.md`.
3. **The real reader on left-behind rows.** Markers, the converter at load, and an
   entity with no row at a gained level.
4. **Content changes by backfill,** for editor saves: expand, background backfill,
   switch, contract, in place of the move in one transaction.
5. **State per type.** The single revision, the single `StateKey` and the single
   "started" mark go.
6. **Native deploys:** `migrate --prepare` and `migrate --finish`.
7. **Old input:** translating changes by old paths, and the pre-edit hook.
8. **Narrow what a migration takes:** Reference holders, and type references by base.
9. **Batches of entities:** one operation, ids made in the admin, entity versions,
   groups, per-change results, the save log.
10. **Schema changes in batches,** with `DraftWriter` writing parts of the draft.
11. **The admin's mechanic:** providers, in place of what it replaces.
12. **Diff writes,** measured first: under the lock, a save compares with what's stored
    and writes only the rows that differ. Validation still checks the whole aggregate.
    The write tokens make this safe for the backfill.
13. **Atomic operations,** for values like stock, when something needs them.

## Prototype results

`RetypeSpikeTest` runs in plain SQL, with the real writer and reader playing the old
release. It passes on MySQL, and on SQLite with its one lock test skipped. It uses a
marker cleared by a save's delete and insert: the write token was decided after it,
and replaces that when stage 3 builds it.

| Case | Shown |
|---|---|
| Scalar retype | The old release keeps reading and writing. An old save clears the marker, and the row converts again |
| Rows left behind | A query on the new field misses the row until the next scan, and never matches its old value |
| Interrupted backfill | It carries on. A save waits for a row being converted |
| References | A Reference points at `entities`, which a save never deletes, so it survives |
| Collections | Items rewrite with the owner's row, so none goes stale, and a shrunken list leaves none behind |
| A value becoming a list | The new table's `CASCADE` foreign key removes converted rows on an old save |
| Owned objects | Saving the root clears their markers too. A big aggregate saved often costs more re-converting |
| Unique indexes | A clash stops one row. An old save never trips the index. Duplicates fail the index cleanly |
| Gained levels | Missing rows are found. An old save leaves them. Deleting an entity removes them |

Load, on MySQL 8.4 on a laptop: 200,000 rows, four processes saving random rows, each
with a 5-second lock timeout. Saves were simplified, the conversion was plain SQL, and
there was no replica, so these compare approaches and don't predict production.

| Saves during | Took | Saves | p50 | p99 | Max | Failed |
|---|---:|---:|---:|---:|---:|---:|
| Nothing running | 3 s | 3,918 | 0.3 ms | 3.8 ms | 21 ms | 0 |
| Move in one transaction | 13.4 s | 500 | 0.6 ms | 5,020 ms | 5,346 ms | 8 |
| Backfill in batches of 500 | 14.0 s | 19,177 | 0.3 ms | 3.7 ms | 59 ms | 0 |
| Adding two columns | 0.01 s | 4 | 3.8 ms | 4.7 ms | 5 ms | 0 |
| Unique index on a filled column | 0.17 s | 223 | 0.3 ms | 9.3 ms | 10 ms | 0 |
| New column with its unique index | 0.44 s | 508 | 0.3 ms | 7.8 ms | 72 ms | 0 |
| Backfill into an indexed column | 13.1 s | 18,793 | 0.3 ms | 2.7 ms | 21 ms | 0 |

Not proved yet: the real reader and writer on stored columns, an entity with no row at
a gained level, Embeds, values following a default, a duplicate slipping in after the
switch, replicas, MariaDB, and tables far larger than this.

## Open points

1. **Which schema each release reads.** Stored schemas are one set of rows, and during
   a native deploy both releases read them. How does each find its own: two sets,
   selected by the code's key?
2. **What runs a backfill** on a site with no worker process: a command, cron, or small
   slices in ordinary requests?
3. **An entity depending on a change still `migrating`.** `unavailable`, sent again
   after the switch?
4. **Rolling back a deploy.** After the switch, new saves don't fill the old column. Is
   the back translation required to allow a rollback, or is rollback a new release?
5. **Dense content.** An added field or a changed default still rewrites every row.
   Can values following a default be left unwritten?
6. **`checkContent()`.** A full read is too heavy at this scale. Does a sample replace
   it in the preview, with failures per row in the backfill?
7. **Splitting schema operations.** `DraftWriter` writes one ordered chain. Operations
   across types tie those types into a group. Does splitting by type hold up for every
   operation? This needs a prototype.
8. **`pendingClass`.** Results per change and an unsaved item in the admin may replace
   it. If the tab closes, a saved class has no item. Is an "Unplaced" view enough?
9. **A schema change depending on an entity,** such as a default referencing an unsaved
   entity. Refuse it for now?
10. **Save log contents.** Full values, or a summary once a batch is closed? How long is
    it kept?
11. **Client ids.** A create must refuse an id that exists. Should the form be checked
    too?
12. **Hot rows.** Two sales both reading stock 5 both save 4. Atomic operations fix
    that, and they need their own rule checking. Hot data should also be its own entity.
13. **Files.** A file store, upload and cleanup in the core, or per plugin?
14. **Existing operations.** Do `schema.save` and the `entities.*` writes stay?
15. **Raw SQL.** Opaque columns need a way to read tables by hand: generated views, or a
    command printing a type's layout?

## Prior art

- **Stable ids with free renames:** Apache Iceberg, Delta Lake column mapping, Protocol
  Buffers, Salesforce, Airtable.
- **Renames through redirects:** Unreal's Core Redirects.
- **Online changes:** expand and contract, gh-ost, safe-pg-migrations.
- **Old data upgraded by version:** MongoDB's schema versioning, Unreal's custom
  versions.
- **Batches with local ids:** JSON:API Atomic Operations, Salesforce Composite.
- **Insert, then fill in references:** Doctrine's UnitOfWork.
- **Version checks on save:** Contentful's `X-Contentful-Version`.
- **Side effects queued in the transaction:** the transactional outbox.
