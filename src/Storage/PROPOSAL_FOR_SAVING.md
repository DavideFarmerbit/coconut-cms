# Proposal: Saving

Status: proposed, under review. Nothing here is built. "Open points" lists
what isn't settled.

The standard is now an e-commerce site that migrates in production, where a
developer's only worry is the classes handling backward compatibility.
"Availability" and "Running a batch" were written before that standard, and
don't meet it: a move in one transaction, and types going `read-only` or
`closed` for the length of a move.

Direction so far, to be worked into the sections below:

- **Migrations:** expand, backfill in batches, switch, contract later. New
  columns go beside the old ones. Nothing is moved aside.
- **Deploys:** an optional step runs before an atomic code switch. It expands
  and backfills while the old release serves traffic.
- **Translation:** converter classes on stored-form values, up and optionally
  back. They run in the backfill and, for rows left behind, at load. An old
  row is read through its stored schema, so no old class is needed.
- **No dual write (to be proved):** each row records the schema version that
  wrote it. The backfill converts it again after an old-release save, and
  scans until no old rows remain.
- **Old input:** a page loaded before the switch sends the old shape after it,
  such as a customer at checkout. A save must not fail for that. Changes by
  old paths are translated before `StoredFormEditor`: renames and converters
  automatically, and a pre-edit hook for custom cases, on the `changes` map in
  stored form. It lasts a grace period after the switch. CMS operations and
  CMS functions handling post data pass through here. Code reading `$_POST`
  directly handles its own backward compatibility.
- **Known risk: empty new columns.** An old-release save leaves the new column
  empty until the next scan. Loading by id still works, through the converter,
  but a query on the new field misses the row for that moment. To watch it: a
  test covering that sequence, a count of old rows per type in `check()`, and
  an optional short write pause at the switch, per type.

## Goals

- **One flexible save.** One batch can hold entity changes, schema changes
  and a plugin's own changes.
- **Only what must fail fails.** An entity missing a dependency is rejected,
  with what depends on it. A type using a missing type is rejected. Unrelated
  entities and types are saved.
- **No all-or-nothing saves.** A batch isn't atomic. Each change gets its own
  result, and each failure is explicit.
- **Localized migrations.** A migration affects the types it changes. Every
  other type stays readable and writable.
- **Simultaneous saves.** Two saves, both with schema and entity changes, both
  end well. If they share nothing, both succeed. If they conflict, only the
  conflicting changes are rejected.

## What the code does today

Already local:

- `Migrator::changedTypes()` finds the types a migration changes, from
  per-type fingerprints and dependencies.
- `rootsHolding()` moves only the aggregates holding those types.
- `checkContent()` reports failures per entity, before anything changes.

Global, and in the way:

- `migration_state` is one row. One started migration blocks every read and
  write, whatever types it touches.
- The schema revision is one number. Any schema save makes every other schema
  save stale.
- `StateKey` is one hash. A pending change anywhere blocks everything. An edit
  to any rule file makes a migration pending.
- `expand()` and `contract()` compare the whole database with one target.
  So two migrations can't overlap: `contract()` would drop the columns and
  `_old__` tables of the other one.
- `MigrationState::assertSettled()` checks once and then remembers. A request
  already past it can still write after a migration starts.

Wider than it needs to be:

- A type holding a changed type only through a Reference moves too.
- Any fixup that changes types moves every type declaring a type reference,
  whatever its base (`declaresTypeReference()`).

Stopped migrations are recovered already: a failure in the move restores the
tables at once, and the next `migrate()` cleans up after a crash. A crash still
blocks the site until then, and only `migrate()` recovers it.

## The model

- **A batch** is everything sent in one save request. It has an id made in the
  admin.
- **A change** is one thing to save, with a stable key: an entity's aggregate
  (`entity:<id>`), one type's schema operations (`schema:type:<identifier>`), or
  a plugin's own change.
- **A dependency** says one change needs another, or needs something stored.
- **A group** is a set of changes that depend on each other in a loop. A group
  saves whole or not at all. It's the only atomic thing, and only because no
  order can save it. A change with no loop is a group of one.
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
| `rejected` | It failed its own check or write. |
| `skipped` | Something it depends on failed. It lists what. |
| `stale` | What it was built on changed. The user reviews it. |
| `unavailable` | A type it needs is being migrated. It can be sent again. |

What wasn't saved stays unsaved in the admin. The server tries once and never
retries on its own.

## Dependencies

The server works dependencies out from the changes, and never trusts the
client for them:

- an entity needs its type, and each entity it references;
- a type needs its parent, and each type its fields name;
- a plugin's handler declares its changes' dependencies.

A dependency is met when it's stored, or when a change in this batch saves it.

The admin works them out too, but only to ask "this also needs X; save it
too?". If it misses one, the server reports exactly that.

Rejection spreads only along dependencies:

1. The changes are sorted into groups, dependencies first.
2. Each group is tried in that order.
3. When a group fails, what depends on it is `skipped`.
4. Everything else goes on.

## Running a batch

1. **Log.** The batch is stored in the save log.
2. **Plan.** The server finds dependencies and groups. A change with a
   dependency that can't be met is rejected here.
3. **Schema.** The surviving schema groups are split by the types they touch.
   Those that overlap form one migration. Each migration runs on its own
   (see "Availability"), and a failed one affects only its groups and what
   depends on them.
4. **Entities and plugin changes.** Each group runs in its own transaction,
   in dependency order. A group of several entities is written in passes:
   rows with references empty, then references, then deletes.
5. **After commit.** Queued work runs. It must be safe to run twice.
6. **Answer.** The log and the response get every result.

Each group's transaction locks its entities in sorted id order, compares their
versions, and runs the normal validation and permissions.

The passes work because a Reference is always nullable. An owned object is
written with its owner.

## Availability

A row per type replaces the single "started" mark. It holds the type's
availability and its version. Migrations set it only for the types they touch.

| State | Reads | Writes | When |
|---|---|---|---|
| `open` | yes | yes | No migration, or only definitions change: a new type, rules, permissions |
| `read-only` | yes | no | Content moves, and columns stay in place: an added field, a rename |
| `closed` | no | no | A column or table is moved aside: a retype |

**Why expand doesn't need a site lock.** Expand adds tables and nullable
columns, so old readers and writers go on working. A move runs in one
transaction, so readers see the old rows until it commits. Only moving a column
aside breaks a reader, and only for that type. Contract removes what no current
schema uses.

**Which types a migration takes:**

- each type `changedTypes()` finds;
- the type of every root whose aggregate moves. `rootsHolding()` finds these,
  which covers a subtype held where only its parent is declared.

**Closing the race with running requests.** A write transaction reads the row
of each type it writes `FOR SHARE`. A migration takes its rows `FOR UPDATE`, in
sorted order, sets their state, and commits. That waits for writers already
running, and later writers see the new state. It must commit before DDL, which
commits on its own. SQLite has one writer, so it only reads the rows.

**Reading across an unavailable type.** An entity stays readable when a type it
references is closed: the reference is a ghost, and only loading the target
fails. A query reaching a closed type, by a path or a subtype, is refused with
`unavailable`.

**Migrations run one at a time** in the first stage, as now: the second waits
for the migration lock. Every other type stays open meanwhile, so waiting costs
that one save a little time. Overlapping migrations would need `expand()` and
`contract()` limited to their own tables, which can come later.

DDL should use a short `lock_wait_timeout`, so an `ALTER` waiting on a long
transaction fails with `unavailable` and doesn't queue up that table's traffic.

## Two simultaneous saves

Save A changes `boat` and writes a boat. Save B changes `character` and writes
a character.

**They share nothing.** A takes the migration lock, and `boat` becomes
`read-only`. B waits for the lock. Everything else stays open. Then B runs, and
`character` becomes `read-only`. Both entities are written. Both are `saved`.

**They both change `boat`.** A runs. B then finds `boat` at a newer version, so
its boat changes are `stale`, what depends on them is `skipped`, and its
unrelated changes are saved.

**B only writes a boat, while A migrates `boat`.** B's boat is `unavailable`,
and its other changes are saved.

**Staleness is per type.** Each schema change carries the fingerprint of each
type it's built on. Fingerprints exist already, so the single revision can go.

## Failure reports

Each failure is data: its change's key, a stable code, a plain message, and
optionally a path and details.

- The server reports every failure it can find, not only the first.
- `skipped` names the changes that caused it, so the report can give the root
  cause.
- The admin gives each unit its own failures. An entity's unit puts each error
  at its field.
- Words have three levels: core defaults per kind, a unit adding to them, or a
  unit replacing them.

The content browser could say:

> "Fire Sword" was saved, but it couldn't be added to the browser.
> Its folder "Weapons" was deleted.

## Recovery

A row per running migration records its types, and whether its transaction
committed. That decides the recovery:

- **Not committed:** stored schemas are still the old ones, so the tables are
  put back to match them. Each `_old__` column or table is restored, and what
  expand added is dropped. The save's changes are `rejected`.
- **Committed:** contract runs, and the types reopen.

`TableChanges` compares the database with a target, so restoring is another
comparison with the stored schemas. A failed migration can restore itself at
once. A crash is restored by the next request that finds the type unavailable
and the migration lock free. So a crash leaves only its own types unavailable,
for one request, and needs no terminal.

## The save log

Every batch gets a row: its id, actor, time, changes and results.

- **A record** of who saved what, and of each failure.
- **Safe retries.** If a response is lost, the admin sends the same id again and
  gets the stored results.

## In the admin

Whatever holds unsaved work registers a provider. Providers declare what's
pending. The core collects, asks and sends, and never decides what saving means.

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

A unit works out `changes()` from what's stored and what's there now. So a new
class declares a type and a content item. If only the type saved, it then
declares only the item. Two units claiming one change key send it once.

A Save button means "this unit and what it needs". Save all means every pending
unit. Signing out, closing a tab and leaving the page read the same list.

Saving part of the schema draft needs two things: `DraftWriter` writing chosen
types, and saved edits becoming the draft's new starting point.

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

## Stages

Each stage works on its own.

1. **Fix the data loss.** Done: restoring, and the committed mark.
2. **Availability per type,** replacing the "started" mark, with the
   `FOR SHARE` check. Editor saves stop blocking the site.
3. **Narrow what a migration takes:** Reference holders, and type references by
   their base.
4. **Batches of entities:** one operation, ids made in the admin, entity
   versions, groups, per-change results, the save log.
5. **Schema changes in batches:** per-type staleness, `DraftWriter` writing
   parts of the draft, and a batch of operations split into groups.
6. **The admin's mechanic:** providers, and removing `SaveParticipant`, the
   save guards and the "Schema changes" tool.
7. **Native deploys per type,** with `StateKey` split by type.
8. **Overlapping migrations,** only if waiting proves too slow.

## Open points

1. **One migration at a time.** Is a short wait for the second migration
   enough, with overlapping migrations left for later?
2. **Splitting schema operations.** `DraftWriter` writes one ordered chain.
   Operations across types, such as a moved field or a rename swap, tie those
   types into a group. Does splitting by type hold up for every operation? This
   needs a prototype.
3. **`pendingClass`.** Results per change and an unsaved item in the admin may
   replace it. If the tab closes, a saved class has no item. Is an "Unplaced"
   view enough?
4. **`read-only` during a commit.** A request holding the old schema can read
   after the move commits and find columns emptied. Is one failed read
   acceptable, or should reads compare the type's version?
5. **A schema change depending on an entity,** such as a default referencing an
   unsaved entity. Refuse it for now?
6. **Save log contents.** Full values, or a summary once a batch is closed? How
   long is it kept?
7. **Client ids.** A create must refuse an id that exists. Should the form be
   checked too?
8. **Entity versions** need a system column, and system columns aren't migrated
   today. How is it added?
9. **Files.** A file store, upload and cleanup in the core, or per plugin?
10. **The queue.** What runs after-commit work on a site with no worker?
11. **Existing operations.** Do `schema.save` and the `entities.*` writes stay?
12. **SQLite** rebuilds tables and has one writer, so availability helps it
    less. Is that acceptable?

## Prior art

- **Batches with local ids:** JSON:API Atomic Operations, Salesforce Composite.
  Salesforce's `allOrNone: false` is the closest to per-change results.
- **Insert, then fill in references:** Doctrine's UnitOfWork.
- **Expand and contract:** the usual way to change a schema under live traffic.
- **Version checks on save:** Contentful's `X-Contentful-Version`.
- **Side effects queued in the transaction:** the transactional outbox.
