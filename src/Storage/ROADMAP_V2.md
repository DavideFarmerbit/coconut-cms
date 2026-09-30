# Storage / Editor — Development Roadmap v2 (rewrite)

Phased build plan for `ARCHITECTURE_V2.md`. Section names below (`"Like this"`) refer to
headings in that document; build against it, not a re-explanation here. Supersedes
`ROADMAP.md` the same way `ARCHITECTURE_V2.md` supersedes `ARCHITECTURE.md` — the old
roadmap's phases 1-8C stay as-is under the old `Storage\` namespace, left untouched as a
reference (see `ARCHITECTURE_V2.md`, "Namespaces and migration path").

**Scope**: this roadmap covers `Entity\`, `Schema\`, `Changeset\`, and `Permission\` — the
backend. `Editor\` (the admin-facing authoring UI, including the client-side
`LocalCommand`/`RemoteCommand` stack) is a separate, later track, built once enough
backend exists to author and drive it against — not numbered as phases here.

**The one structural fix this roadmap is built around**: `entities` is the universal root
from Phase 1, and every phase from Phase 1 onward builds `Entity\Repository`/
`Changeset\ChangesetFlusher`/`Entity\Query` against `Schema\PrototypeRegistry`'s generic
identifier interface — never against native reflection directly, even in Phase 1 when
native classes are the only identifier kind that exists. This is a deliberate reversal of
the old roadmap, where global entity identity landed at Phase 8 and generic
identifier-based repository access landed at Phase 7 Step A — both bolted on after years of
native-only code existed, which is exactly what `AUDIT.md` found half-migrated and
inconsistent (items 8 and 14: `SchemaBuilder`, `RowMapper`, `DraftPreview` calling
`PrototypeShape`/reflection directly, never updated when the generic path arrived). Here,
there's no later retrofit phase for this at all — Phase 6 (admin-authored schema) just
plugs a second identifier kind into an interface that's been there since Phase 1.

## Phase 1 — Foundation: `entities` table, generic identifier resolution, one native scalar entity

**Goal**: `entities` is the root of every chain from row one; a hand-written native class
with scalar fields round-trips through real storage, but every access path goes through
`Schema\PrototypeRegistry`'s generic interface, not native reflection directly.

- `Schema\FieldDescriptor`/`FieldKind` — `scalar()` factory only for now ("Shape comes
  from a neutral descriptor"). Later kinds' factories (`valueObject()`, `embed()`,
  `reference()`, `collection()`) are added in the phase that implements them, not stubbed
  early.
- The fixed `entities` table (`id` uuid, `owner`, `owner_field`, `position`,
  `concrete_identifier`), built and synced unconditionally, before any class-derived table
  ("Global entity identity").
- `Schema\PrototypeRegistry` interface (`fieldsOf(identifier)`, `instantiate(identifier,
  values)`, `chainOf(identifier)`) — one implementation for native classes via reflection,
  written so a second, editor-created implementation can be added in Phase 6 with zero
  change to any caller.
- `#[Table(string $name)]` attribute + derived-short-name fallback + collision guard, and
  **one registration entry point** (`EntityRegistrar::register($classes)`) resolving every
  level's table name once and constructing everything from that single source of truth —
  folding in what the old roadmap needed a dedicated later phase (6.2) to fix, built right
  the first time here.
- `Schema\SchemaBuilder`/`SchemaSynchronizer`: Doctrine DBAL `Schema`/`Comparator`-based
  sync. Every field is a real column, full stop — there's no blob tier to ever build
  ("No blobs. Every field is a real column or a real table.").
- CTI chain of at least 2 for every identifier (`entities` + the class's own table) — no
  "chain of one, no join" case, even for a standalone class with no declared parent.
- `Entity\Repository`/`IdentityMap`: insert writes the root row into `entities` first
  (this is where `id` originates), then the class's own row; find/delete join through
  `entities`; identity map scoped per request.
- `#[DefaultInstance]` + resolution order + the eager-failure registration walk
  ("Defaulted instances") — brought in now, not deferred to a late phase, since
  backfilling a new field on an already-populated class is an ordinary event the moment
  real migrations exist.
- `Schema\FieldValidator` strategy interface, a couple of default validators.
- Uniqueness: `unique` flag, real `UNIQUE` constraint, friendly pre-check.

**Not yet**: entity references/collections, value objects/embed, native CTI extension (a
native class extending another), the `Changeset` write path (direct `Repository` calls
only — nothing needs multi-entity atomicity yet), undo/draft, permissions enforcement,
editor-created prototypes actually existing (the registry's *shape* supports a second
identifier kind; nothing produces one yet), `Query`, any UI.

**Done when**: a hand-written native class with a mix of unique and plain scalar fields
round-trips create/read/update/delete through `Entity\Repository` — which calls only
`PrototypeRegistry`, never reflects directly — rooted under `entities` via CTI, backed by
a migration-generated table; the unique constraint is enforced; adding a new field to an
already-populated class backfills every existing row via `#[DefaultInstance]` resolution;
two unrelated native classes that happen to derive the same short table name fail
registration with an actionable error.

## Phase 2 — Value Object + Embed

**Goal**: custom primitive types and recursive field-flattening work.

- `FieldDescriptor::valueObject()` + a custom `Type`-class mechanism (one column,
  PHP-value ↔ DB-value conversion) ("Entity vs. Value Object vs. Embed").
- `FieldDescriptor::embed()` — field tree restricted to scalar + value-object +
  nested-embed only, no `Reference`/`Collection` at any depth, matching Doctrine's own
  embeddable restriction deliberately.
- Recursive column flattening (`address.city` → `address_city`), resolved through
  `PrototypeRegistry::fieldsOf($identifier)` for whichever identifier is named as the
  embed target — proving the generic-identifier decision from Phase 1 pays for itself
  immediately: this never needs the old system's Step-E-style retrofit, since there was
  never a native-only path to retrofit away from.
- Embed-of-Embed cycle detection at registration time.
- `#[DefaultInstance]` propagation extended: an embedded shape gaining a field backfills
  every table that embeds it.

**Not yet**: references/collections, native CTI extension, `Changeset`, undo/draft,
permissions, editor-created prototypes, `Query`.

**Done when**: a value-object field round-trips as a single custom-typed column; an embed
field flattens recursively, including a nested embed and a nested value-object member; the
same shape embedded twice under different field names on one entity works via dotted-path
disambiguation; a test embedding A-in-B-in-A is rejected at registration instead of
recursing forever.

## Phase 3 — References & collections, native CTI extension, `MediaAsset`

**Goal**: entity-to-entity references/collections (Shared + Owned) work via
`entities.owner`, and a native class can extend another.

- Shared singular (FK column, `RESTRICT`), Shared collection (real pivot table)
  ("References and collections").
- Owned singular / Owned collection via `entities.owner`/`owner_field`/`position` — built
  this way from day one, no per-relationship dedicated table detour (the old design's
  first attempt, superseded before this rewrite even started).
- Non-entity collection (scalar or value-object items): a dedicated child table
  (`ownerId`, `position`, value column(s)), `CASCADE`-deleted with the owner.
- Native prototype extension via CTI (a native class extending another native class) —
  `SchemaBuilder::tablesForChain()` grows one level beyond the now-mandatory `entities`
  root.
- `MediaAsset` as the worked example exercising both Owned (inline upload) and Shared
  (media library) at once ("Media/file fields").
- The `#[DefaultInstance]` completeness walk extended to also cover every reachable
  `#[Reference]` target, not just `#[Embed]`.

**Not yet**: the `Changeset` write path (a create-and-attach-in-one-call isn't atomic
until Phase 4 — Phase 3's own tests create the referenced entity first, as two separate
steps), undo/draft, permissions, editor-created prototypes, `Query`.

**Done when**: a native class can Shared-reference another (`RESTRICT`-protected) and
Owned-collection a shape (`CASCADE` via `entities.owner`) at the same time; a base/derived
native inheritance pair round-trips through the CTI join; `MediaAsset` demonstrates both
Owned and Shared usage; deleting an owner cascades correctly to every Owned child, at any
depth, through one `entities`-scoped query.

## Phase 4 — Changeset write path

**Goal**: every multi-entity write goes through an explicit, atomic changeset with a real
topological sort — no more manual two-step create-then-attach.

- `Changeset\Changeset`/`EntityChange`/`TempId` ("Content write path").
- `Changeset\ChangesetSorter`: full topological sort resolving `TempId` dependency edges;
  cycles rejected outright with a clear, named error.
- `Changeset\ChangesetFlusher`: applies a changeset as one atomic database transaction.
- Concurrent-write protection: an `expectedOperationId` receipt, reject-by-default with an
  explicit override to retry.

**Not yet**: undo/draft, permissions, editor-created prototypes, `Query`.

**Done when**: a changeset creating a new Tag and attaching it to a Product in the same
flush commits atomically; two new entities referencing each other in one changeset are
rejected with an error naming the cycle; a stale `expectedOperationId` is rejected; a
changeset deleting a referencer and what it references in the wrong order is corrected by
the sort, not left to rely on `RESTRICT` as a backstop.

## Phase 5 — Undo, draft, revision history (content-only)

**Goal**: every flush is loggable and recoverable, per the Envers-style `Revision` +
per-entity `EntityChangeRecord` design; nothing is ever pruned automatically.

- `Changeset\Undo\Revision` (metadata-only, one per flush) + `Changeset\Undo\EntityChangeRecord`
  (per-entity diff, FK'd to the `Revision`) ("Content undo, draft, and revision history").
- Undoing a whole `Revision`, across however many entities it touched: an independent
  conflict check per touched entity, atomic inverse apply through the same
  `ChangesetFlusher`, outright refusal (never silent-partial) if any touched entity's
  record is missing or conflicted.
- Redo (undo-the-undo, no new mechanism).
- Undo authorization: a `Permission\` check that the `Revision` belongs to the requesting
  user/session, checked once, before the inverse changeset is even computed.
- `Changeset\Draft\DraftStore`/`DraftPreview`: a persisted-but-unflushed changeset plus an
  in-memory apply/preview function; publishing flushes the exact same changeset through
  the exact same path, producing a `Revision` like any other flush.
- Revision-history restore: reads one entity's own `EntityChangeRecord` chain, restores by
  flushing a new changeset. The "blocked past any schema change" guard is written here but
  can't be meaningfully exercised until Phase 6 introduces schema mutation — noted, not a
  blocker.
- A manual pruning tool: explicit, human-triggered, no automatic policy of any kind.

**Not yet**: permissions enforcement beyond undo-authorization, editor-created prototypes,
schema mutation, `Query`.

**Done when**: a multi-entity `Revision` undoes atomically with a per-entity conflict
check; redo restores it exactly; undoing with someone else's `Revision` id is rejected; a
draft builds, previews, and publishes through the exact same write path as an ordinary
flush; manually pruning one touched entity's `EntityChangeRecord` and then attempting to
undo the `Revision` it belonged to produces a clean, specific refusal, not a silent
partial revert.

## Phase 6 — Admin-authored schema

**Goal**: admins can create prototypes and safely mutate editor-created subclasses at
runtime. `DynamicEntity` and the editor-created branch of `PrototypeRegistry` are
exercised for the first time here — plugging into machinery every prior phase already
built generically, no retrofit.

- `Schema\SchemaEditor`: `createPrototype()`, `addColumn()`/`dropColumn()`, `rename()`
  (prototype and field, explicit old→new mapping passed in, never inferred, never an
  attribute), `retype()` (field and collection-item-kind, an explicit converter class
  required, refused otherwise), `reparent()` — safe-DDL-only, scoped to the subclass's own
  table, never the parent's ("Migrations and schema mutation").
- `Schema\DynamicEntity` + the editor-created implementation of
  `PrototypeRegistry::instantiate()`/`fieldsOf()` — the second identifier kind the
  registry interface has supported since Phase 1.
- `#[EditorExtensible]` attribute + revocation (falls back to `entities` directly,
  lazily, on the next schema save) — the same mechanism as deleting a prototype with live
  editor-created subclasses.
- Prototype/class deletion, dangling-allowed by design: native-referencing-native fails at
  registration (already free, from Phase 1's field-tree walk); native deletion goes
  through the reviewed-migration tool; anything on the editor-schema side gets a
  `SchemaEditor` save-time block plus a separate, standalone auditing tool scanning every
  editor-created schema for breakage.
- `Permission\SchemaPermission` gating every one of the above from the first commit that
  makes runtime schema mutation possible at all — not bolted on after.

**Not yet**: `FieldPermission`, `Query`.

**Done when**: an admin without `SchemaPermission` cannot create or alter a prototype; one
who does can create a fresh editor-created prototype and add/drop/rename/retype a field on
it and reparent it, safely, with the result immediately readable/writable through the
exact same `Entity\Repository`/`Changeset\ChangesetFlusher` every native class already
uses — no separate wiring step, since the wiring already existed; revoking
`#[EditorExtensible]` on a native class with a live editor-created subclass falls back to
`entities` on the next schema save, data intact until then; deleting a prototype
referenced elsewhere dangles, caught at the documented point for whichever context
(native registration, reviewed migration, or the editor auditing tool) applies.

## Phase 7 — Field-level permissions

**Goal**: `FieldPermission` enforced everywhere a field's value is read or written.

- `Permission\FieldPermission` strategy interface, a default role-based implementation.
- Three enforcement points: read-time filtering on hydration, a mandatory server-side
  write gate inside the `Changeset` flush path (before validation), and a client-side
  UX-only gate (noted for the `Editor\` track, not built here).
- Confirmed to generalize to undo/revision-restore for free, in practice, not just on
  paper.

**Done when**: an actor without read permission for a field never sees it in a hydrated
entity, native or editor-created; an actor without write permission has a changeset
touching that field rejected server-side regardless of client input; undoing a `Revision`
that touches a field the actor can't write is rejected by the same gate.

## Phase 8 — Admin list/filter views

**Goal**: browse, filter, and sort across native and editor-created prototypes, any
inheritance level, cursor-paginated from the start.

- `Entity\Query`: `Query::for($identifier)->where(...)->orderBy(...)->after($cursor)->limit($n)->get()`
  ("Admin list/filter views").
- Resolves a field to its real column *and* which chain-level table holds it.
- One multi-table `JOIN` across the whole chain with per-level column aliasing, replacing
  a one-query-per-level approach.
- Cursor/keyset pagination (sort-column value + primary-key tiebreaker) from the start,
  never `OFFSET`/`LIMIT`; `count()` shares the same `WHERE`, independent of pagination.
- `Entity\Repository::findMany(array $ids)`, batched by chain level, backing opt-in
  polymorphic hydration: `Query::hydrateConcreteTypes()`, off by default.

**Done when**: a list view for an identifier filters/sorts by a field declared at any
level of its own chain, native or editor-created, returns a stable cursor-paginated page
with a correct has-more signal; `count()` matches the same filter independent of
pagination; a page mixing several distinct concrete subtypes costs exactly one extra
batched lookup per distinct subtype when polymorphic hydration is explicitly requested,
and nothing extra when it isn't.

## Shelf items (unchanged from `ARCHITECTURE_V2.md`)

Not numbered phases — pick up only once a real need shows up: full-text/cross-content-type
search, real-time concurrent multi-editor collaboration.

## Not covered by this roadmap

- **`Editor\`** — the admin-facing authoring UI and the client-side `LocalCommand`/
  `RemoteCommand` stack. A separate, later track, once enough of the above exists to build
  and drive it against.
- **Fine-grained revision-history reachability across a schema change** and **schema-level
  delete referential integrity for the referencing-prototype case** — both still listed as
  open in `ARCHITECTURE_V2.md`'s "Deferred" section; pick a phase for either once they're
  actually resolved there.
