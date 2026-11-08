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
- A migration still rewrites the aggregates a rename changes, until stage 5. A rename
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
   The change is recorded first. This is short DDL, and only one runs at a time. It uses a short `lock_wait_timeout`, so an `ALTER` waiting on a long
   transaction fails and doesn't queue up that table's traffic.
2. **Live.** The change becomes `live` and the stored schemas become its version, in one
   transaction. From then on every load reads the new shape, converting a row that isn't
   there yet through the translator, and every save writes it.
3. **Backfill.** Rows are converted in small batches, resumable, without holding the
   migration lock. Each aggregate is converted under the lock a save takes, on its root,
   so no save lands between the read and the write. The save tries first, for about 2
   seconds. If it finishes, the status is `saved`. If not, the status is `migrating`, and
   the command carries on from where it stopped. Cron runs the command.
4. **Contract.** Old columns go, once no row needs them and no old code runs. It can
   come much later.

**Changes stack.** The history is ordered, and holds nothing back. A second change to
the same type applies at once, while the first is still catching up.

- A load reads the row's version, reads the row in that version's shape, and runs only
  the later changes on it.
- A save writes the latest shape and the latest version.
- The backfill takes a row straight to the latest shape.
- Old columns go oldest change first, once no row of their types is below them.

**A stop at any point is safe.** Every step can run again.

- Expand compares the database with the target.
- A change is recorded before anything depends on it.
- Each aggregate converts in its own transaction, so a crash loses at most the one in
  progress, and the next run skips what's at the latest version.
- The backfill's lock expires, so a dead worker blocks nobody.
- A test stops the process at every step, resumes, and compares the result with a run
  that was never stopped.

**Nothing running the backfill** doesn't stop the site. The command records when it last
ran, and `check()` and the admin say so when a change is waiting and it looks missing.
Until then, only the query gaps below stay open.

**A write token and a version replace dual write.** Every save makes one fresh token,
with `EntityIds::generate()`, and sets it on every row it writes: `written` on each
`entities` row, `_written` on each level row, whatever columns it changes. It's random,
not counted, so two saves never share one.

The `entities` row also holds `version` and `version_written`. `version` is the
position in the change history of the shape the row is in. Every save sets it to the
latest, and sets `version_written` to its own token. An old release knows neither
column, so it leaves them as they were, but it still sets `written`:

| The root's `entities` row | Its shape |
|---|---|
| `version_written` equals `written` | The shape of `version` |
| They differ | An old release wrote it last: the shape before the running native change |

So an old release needs no change and knows nothing about a migration, however it
writes the row. The backfill converts that aggregate again.

- There's no table to create per change, and nothing to drop at contract. The two
  columns are part of the `entities` table from the start, with an index on concrete
  identifier and version.
- A row is behind when its version is below the newest change that converts content
  of its types. A change needing nothing from rows, such as a rename, gives the same
  shape, so a row at the version before it isn't behind and isn't rewritten.
- An aggregate is the unit, because a save and the backfill both lock its root. Only
  the root's row counts.
- A gained level needs nothing of its own: an aggregate with no row at that level is
  below the version that gained it.
- A save by the new code writes the new shape and its version in one transaction.
  Without the version, the next load would read the old column, which that save didn't
  fill.
- Until stage 13 every save rewrites its whole aggregate, so comparing with `written`
  gives the same answer as comparing level by level. Stage 13 decides whether a save to
  one level must raise the whole aggregate's version, or whether versions go per level.
- A Collection table has no token. A save changing only a Collection's items must
  still set the token on the owner's level row.
- `written` is also what a client sends back to detect a stale save.

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

**A gained level** is a new table with a foreign key to `entities`. The version covers
it, even when its values come from fields the old release can change.

**The plan outlives its request.** Conversions run long after planning. Each change is
recorded in the change history, in the fixup language below. Any process reads the plan
from there, and rebuilds the old schemas reading an old row needs.

### The fixup language

Every fixup has one stored form: JSON, in a fixed set of kinds. It's the one way the
system says what a change means, and every part that needs to know reads it:

| Reader | What it does with the fixups |
|---|---|
| Loading an old row | Reads it in its shape, and runs the later fixups on its values |
| The backfill | The same, then writes the latest shape |
| Old input | Runs a request built on an old shape through the later fixups |
| Query rewrite | Rewrites a condition on an old path to the new one |
| Catching up a database | Replays the changes it hasn't applied |

**The kinds** are the fixups that exist today: add, drop, rename, move and retype a field;
add, drop, rename, replace and reparent a type; copy and move values; unique groups and
rules; and the set-asides a break needs. A new kind is a change to the language, made with
care, never per project.

**Each fixup is a complete statement.** Everything it does is in the language: what it
changes, what it produces, and its converter by registered name and parameters. Applied to
version n, it gives version n + 1, with nothing else to look up:

```json
{"retypeField": {"type": "dy:yacht", "field": "cabins",
  "to": {"kind": "scalar", "scalarType": "string"},
  "converter": {"kind": "coconut-cms:number-to-text"}}}
```

Today some fixups read part of their result from the target schemas while they run: the
new field of `RetypeField`, `AddField` and `MoveField`, the new parent of `Reparent`, the
table of `MoveTable`, the added type of `AddType` and `ReplaceType`, the identifiers of
`RenameTypes`. The target only knows the final shape, which breaks twice:

- An editor save changing one field twice. `FixupAtStep` works around it today.
- A native database skipping a release whose step retyped a field that a later release
  retyped again. Today that migration is refused.

So a developer still writes `RetypeField::converting(type, field, converter)`, and the
planner completes it from the target, the one moment the target is right. What's recorded
is the complete fixup, and `FixupAtStep` goes.

**Logic stays in PHP, named.** A converter or initializer is its registered identifier and
its parameters, as the editor already sends it: `{"kind": "coconut-cms:text-to-number",
"to": "int"}`. Native steps follow the same rule: every converter and initializer they use
is registered, with an identifier. A class must stay in the code while a database still
needs a change using it, and `check()` warns about one that's gone.

**The change history** is a table, one row per change, in order: its position, which is
what an entity's `version` names, the schema version it was built on and the one it gives,
its source, native or editor, its fixups, and its status. Its fixups are
every one the plan ran, in the order it ran them: the declared ones, the ones the policy
chose, and the set-asides a break needed. The policy's choices are recorded as made, so
replaying a change never asks the policy again, whatever it is by then. So the history
moves a database up or down between any two versions, like git for schemas.

**Old versions are rebuilt, not stored.** The history's first entry holds the schemas as
they were when it started, its base. Any version is the base with every fixup up to it
applied, in order. One entry per change: an editor save or a native step.

- A process caches each version it rebuilds.
- Each rebuild is checked against that version's hash in the history: a mismatch is an
  error at once, never a row read with the wrong schema.
- If rebuilding from the base costs too much, a change keeps a copy of each type it
  touched, as it is after the change, so a version is assembled from the newest copy of
  each type, as git does with files. Only if measuring shows the need.

**Down is exact for schemas, not always for content.** A version is rebuilt from the base,
so a schema goes back exactly. Content goes back through each fixup's way back:

- Renames and moves always go back.
- A retype goes back when its converter converts back. A lossy one, such as text to
  number, can't bring back what it lost.
- A dropped field's values exist only while its old column does, until the grace period
  ends (open point 17). After contract, going back gives that field its default.
- A set-aside keeps what it captured, so going back restores it. Native
migrations and editor saves both record into it. It replaces "editor changes store their
operations" and "native plans are rebuilt from code". A step deleted from the code no
longer blocks a database still missing it: the history has it.

**A change's status** lives on its history row: `recorded`, `live`, `done` or
`contracted`. Rows left and rows failed are counted when asked, by query, never stored, so
they can't drift. The admin shows `migrating` for a change that's `live` with rows left,
and a save's result is read from the row.

**Schema versions** are a hash of the stored schemas, with the version each was built on.
An import built on a version prod has moved past is refused as a conflict, and never merged
silently.

**Limits.**

- A query on a retyped field rewrites only when its converter also converts back. Renames
  and moves always rewrite. Otherwise a query on the old shape is refused, clearly.
- Converters stay arbitrary PHP: the language names them, it doesn't contain them.
- How long the history is kept, and whether old entries are squashed, is open.

### The history is the one source

The stored schemas are the history's newest version, written in the same transaction
that makes a change `live`. They're a projection: rebuilt from the history if they ever
disagree, never edited on their own. Whatever says where a database stands, today
`migration_state`, the schema revision, `StateKey` and `migration_steps`, moves onto the
history too, as versions per type (stage 6).

### The translator

One component moves values from any version to any other, through the history: forwards
with each fixup's conversion, backwards with its way back. Everything that meets an old
shape uses it, so there's one place where a change's meaning is applied.

```
Request (client at version c)
  ├─ schema changes ─► Plan ─► History (append) ─► Expand
  │                              └─► Stored schemas = its newest version
  └─ entity changes ─► Translator (c → latest) ─► Writer (latest shape + version)

Load:     row at version v ─► Translator (v → latest) ─► Translator (latest → c) ─► answer
Backfill: load and write, in a loop
Query:    paths at version c ─► Translator (c → latest) ─► query on the latest columns
Contract: from the history, once no row is below a change and the grace period is over
```

| Use | From | To |
|---|---|---|
| Load an old row | The row's version | Latest |
| Answer an old client | Latest | The client's version |
| Old input | The client's version | Latest |
| Backfill | The row's version | Latest, then written |
| Query rewrite | The client's version, on paths | Latest |

- Forwards always works: every fixup converts up. Backwards works when every fixup on the
  way can: renames and moves always, a retype when its converter converts back.
  Otherwise the caller gets a clear refusal: "schema changed, reload".
- `AggregateConversion`, built in stage 3, is most of the forward half. The backward half
  and path rewriting come with stage 8.
- Native and editor changes differ only in what appends to the history.

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

### Old output

A client holding an old shape breaks on an answer in the new one: a loaded admin tab, a
JavaScript client, another app on the API. A page the server renders needs nothing, since
it's built from the new shape.

- Every request names the schema version it was built on. Old input needs it anyway.
- The answer is translated back to that version when every fixup between can go back:
  renames and moves always, a retype when its converter converts back.
- Otherwise the answer is a clear "schema changed, reload" error, never a shape the client
  can't read. The admin then reloads its type definitions.

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
new shape and the latest version, and the backfill has nothing left to do for that boat.

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
   a `PendingConversion`. Nothing creates such a change yet: stage 5 does. Stage 5
   replaces its per-change marker tables with the version on the `entities` row.
4. **The fixup language.** A stored JSON form for every fixup, each carrying its own
   targets, converters named by registered identifier, and the change history recorded
   by native migrations and editor saves alike, with the stored schemas as its newest
   version, and the translator's forward half. See "The fixup language", "The history is
   the one source" and "The translator".
5. **Content changes by backfill,** for editor saves, in place of the move in one
   transaction, with plans read from the history. First one change end to end: expand,
   live, the save's own backfill, the command, contract, failed rows, and a test
   stopping at every step. Then changes stacking on each other.
6. **State per type, on the history.** `migration_state`, the single revision, the single
   `StateKey` and `migration_steps` give way to versions per type, read from the history.
7. **Native deploys:** `migrate --prepare` and `migrate --finish`.
8. **Old input and old output:** translating changes by old paths, and the pre-edit
   hook, from the history; answers translated back to the client's version, or a clear
   "schema changed, reload" error.
9. **Narrow what a migration takes:** Reference holders, and type references by base.
10. **Batches of entities:** one operation, ids made in the admin, entity versions,
    groups, per-change results, the save log.
11. **Schema changes in batches,** with `DraftWriter` writing parts of the draft.
12. **The admin's mechanic:** providers, in place of what it replaces.
13. **Diff writes,** measured first: under the lock, a save compares with what's stored
    and writes only the rows that differ. Validation still checks the whole aggregate.
    The write tokens make this safe for the backfill, and versions may move to comparing
    with `_written` on the changed level.
14. **Atomic operations,** for values like stock, when something needs them.

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
    them into prod. The export carries the history; the local edits record theirs; prod
    replays what it hasn't applied, and refuses an export built on a version it has moved
    past. Can two edits that don't touch the same types be merged instead? Not tested.
17. **Removed fields kept for a while.** Contract drops a removed field's column after a
    grace period, not at once, so an admin can undo a removal. How long, and how is an
    undo offered?
18. **DDL that can't run online.** Every expand statement states `ALGORITHM=INSTANT`, or
    `INPLACE, LOCK=NONE`, so MySQL fails instead of rebuilding a table under a lock. What
    does the change do then: a shadow table, as gh-ost does, or refuse?

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
