# Proposal: Saving and Migrating

Status: proposed. Stages 1 to 3 are built, the rest isn't. `RetypeSpikeTest`
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
values, a value becoming a list, a reparent. **A change applies when it's saved, and
rows catch up.** Nobody waits for a backfill: not an admin, not a later change, not an
entity saved in the same batch. They go through four steps.

1. **Expand.** New columns and tables go beside the old ones, never in their place.
   The change gets its own marker table and its record. This is short DDL, and only one
   runs at a time. It uses a short `lock_wait_timeout`, so an `ALTER` waiting on a long
   transaction fails and doesn't queue up that table's traffic.
2. **Live.** The new schema is stored in the same step. From then on every load reads
   the new shape, converting a row that isn't there yet, and every save writes it.
3. **Backfill.** Rows are converted in small batches, resumable, without holding the
   migration lock. Each aggregate is converted under the lock a save takes, on its root,
   so no save lands between the read and the write. The save tries first, for about 2
   seconds. If it finishes, the status is `saved`. If not, the status is `migrating`, and
   the command carries on from where it stopped. Cron runs the command.
4. **Contract.** Old columns and markers go, once no row needs them and no old code
   runs. It can come much later.

**Changes stack.** The record of changes is ordered, and holds nothing back. A second
change to the same type applies at once, while the first is still catching up.

- A load finds the newest change whose marker matches the row, reads the row in that
  shape, and runs only the later changes on it.
- A save writes the latest shape and marks the latest change.
- The backfill takes a row straight to the latest shape.
- Old columns go oldest change first, once no row needs them.

**A stop at any point is safe.** Every step can run again.

- Expand compares the database with the target.
- A change is recorded before anything depends on it.
- Each aggregate converts in its own transaction, so a crash loses at most the one in
  progress, and the next run skips what's marked.
- The backfill's lock expires, so a dead worker blocks nobody.
- A test stops the process at every step, resumes, and compares the result with a run
  that was never stopped.

**Nothing running the backfill** doesn't stop the site. The command records when it last
ran, and `check()` and the admin say so when a change is waiting and it looks missing.
Until then, only the query gaps below stay open.

**A write token and a marker table replace dual write.** Every level table has one
`_written` column, and the `entities` row has `written`. Every save sets a fresh
token on each row it writes, whatever columns it changes. Each content change has its
own marker table, with one row per aggregate: its root's id and the token the backfill
saw. An aggregate is left behind when it has no marker, or when the marker differs
from the root's `written`. So an old release needs no change and knows nothing about
a migration, however it writes the row. The backfill converts that aggregate again.

- The marker table touches no level table, so it needs no `ALTER` on a busy table, and
  contract drops one table. Deleting an entity removes its marker by a foreign key.
- An aggregate is the unit, because a save and the backfill both lock its root.
- A gained level needs nothing of its own: an aggregate with no row at that level has
  never been marked.
- A save by the new code writes the new shape and its marker in one transaction.
  Without the marker, the next load would read the old column, which that save didn't
  fill.
- Until stage 12 every save rewrites its whole aggregate, so comparing with `written`
  gives the same answer as comparing level by level. Stage 12 compares with `_written`
  on the changed level, so a save to another level costs no repeated conversion.
  Marker tables exist only during a change, so that needs no data migration.
- A Collection table has no token. A save changing only a Collection's items must
  still set the token on the owner's level row.
- `written` is also the version a client sends back to detect a stale save.

**Reading a row left behind.** An old row is read through the change's old schema into
stored-form values, and the converter brings them up. So the new class is filled
correctly, by id, with no old class. A filter or a sort on a new or retyped field misses
that row until it's converted, so the admin shows the type as `migrating`.

**Translation** lives in converter classes on stored-form values, up and optionally
back. The backfill and the load path run the same code.

**A failing row fails alone.** While the save's time budget can read every row, the
check before the save still refuses the whole change, and nothing changes. Past that
budget the change goes live, and a row its converter can't take:

- gives a clear error when that entity is loaded, and every other entity is served;
- is listed by `check()` and the admin, with the reason;
- keeps its old value in the old column, so nothing is lost;
- holds back that change's contract, until an admin fixes the row or discards its value.

**Unique indexes** on an existing column are built at once, and duplicates refuse them.
On a retyped or converted field, the index goes on the new column at expand. While the
save's time budget can convert every row, the check before the save still finds every
clash. Past that budget, the first row to reach the index keeps the value, and the later
one fails alone, as above.

**A gained level** is a new table with a foreign key to `entities`. The marker table
covers it, even when its values come from fields the old release can change.

**The plan outlives its request.** Conversions run long after planning, and they're
PHP objects, not data.

- Each change's record stores its old schemas: reading an old row needs them, and the
  stored schemas are the new ones once it's live.
- A native change stores its step ids too. The fixups are in the code, and a step can't
  be deleted while a database hasn't applied it, so a process rebuilds the plan with the
  planner the first time it meets a left-behind aggregate.
- An editor change stores its operations as their JSON, and a process rebuilds the plan
  from those. An operation naming a converter with no registered name can't be stored,
  so a content change saved that way is refused, with a clear error. The API can only
  name registered ones.

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

There's no code switch, so every process moves to the new shape when the save stores
it. The save's own backfill finishes a small table on the spot, and the result is
`saved`, as if nothing had happened. A big table returns `migrating`, and it's fully
usable meanwhile. Entities in the same batch save in the new shape. A native deploy
works the same way once its code is live: only what starts the change differs.

### Old input

A page loaded before the switch sends the old shape after it, such as a customer at
checkout. A save must not fail for that. Changes by old paths are translated before
`StoredFormEditor`: renames and converters automatically, and a pre-edit hook for
custom cases, on the `changes` map in stored form. It lasts a grace period after the
switch. CMS operations and CMS functions handling post data pass through here. Code
reading `$_POST` directly handles its own backward compatibility.

### Known risk: rows left behind

An unconverted row is missing from a filter on the new field, and a unique index doesn't
cover it. To watch it: a test covering that sequence, a count of rows left per type in
`check()`, and an optional short write pause per type.

The reverse gap: a save by the new release leaves the old columns empty, so an old
release still running can't read that row. An atomic code switch keeps that window to
requests in flight. A rollback widens it: see open point 4.

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
| `migrating` | A schema change is live, and old rows are still catching up. |
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
3. **Schema.** Definition changes commit. Content changes run expand and go live, then
   try their backfill, returning `saved` or `migrating`.
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

**They share nothing.** Each expand runs in turn and takes moments. Both changes are
live at once, and both entities are written in the new shape. Nothing else is affected.

**They both change `boat`.** A applies. B was built on the `boat` A replaced, so its boat
changes are `stale`, what depends on them is `skipped`, and its unrelated changes are
saved. Once the admin reviews it, B applies on top of A, whatever A's backfill has
reached.

**B only writes a boat, while A's backfill runs.** B's save is ordinary. It writes the
new shape and its marker, and the backfill has nothing left to do for that boat.

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
3. **The real reader on left-behind rows.** Done. Marker tables, the converter at load,
   an entity with no row at a gained level, and a save marking what it writes:
   `ContentChange`, `AggregateConversion`, and `EntityReader` and `EntityWriter` taking
   a `PendingConversion`. Nothing creates such a change yet: stage 4 does.
4. **Content changes by backfill,** for editor saves, in place of the move in one
   transaction. First one change end to end: its record, expand, live, the save's own
   backfill, the command, contract, failed rows, and a test stopping at every step. Then
   changes stacking on each other.
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
    The write tokens make this safe for the backfill, and markers move to comparing
    with `_written` on the changed level.
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

`ContentChangeTest` proves the real reader and writer on a native change: V1 to V2
renames, retypes, gains a level, renames and drops a type and moves a field, with no row
moved, and every load gives what the one-transaction migration gives. Those numbers above predate it.

Not proved yet: a backfill on stored columns under load, a load racing a save, a
duplicate slipping in after the switch, replicas, MariaDB, and tables far larger than
this.

## Open points

1. **Which schema each release reads.** Stored schemas are one set of rows, and during
   a native deploy both releases read them. How does each find its own: two sets,
   selected by the code's key?
2. Settled: the save tries first, then a command, run by cron. A missing cron is reported.
   Slices in customers' requests were left out.
3. Settled: an entity never waits for a change, since the change is live when it's saved.
4. **Rolling back a deploy.** After the switch, new saves don't fill the old column. Is
   the back translation required to allow a rollback, or is rollback a new release?
5. **Dense content.** An added field or a changed default still rewrites every row.
   Can values following a default be left unwritten?
6. **`checkContent()`.** A full read is too heavy at this scale. It runs while it fits the
   save's time budget, and past that failures are per row. Does a sample replace it in
   the preview?
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
16. **Moving editor schemas to prod.** A developer exports only the current schemas, with
    no content, for privacy and size, edits them locally on an old version, and imports
    them into prod. Field ids may make this work with no stored fixups: the same id under
    a new name is a rename, and a missing id is a removal. Not tested. What decides when
    a retype needs a converter, and how is a stale export detected? Native steps still
    ship with the code, and a step deleted before a database applied it still blocks.

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
