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

Phases with more than one genuinely distinct mechanism are split into numbered
sub-phases (`1.1`, `1.2`, ...), each ending in its own independently testable "Done when"
— the same granularity the old roadmap eventually needed (its own 6.1/6.2/6.3, and Phase
8's Step A-F split) applied proactively here instead of discovered after the fact.

## Phase 1 — Foundation: `entities` table, generic identifier resolution, one native scalar entity

**Goal**: `entities` is the root of every chain from row one; a hand-written native class
with scalar fields round-trips through real storage, but every access path goes through
`Schema\PrototypeRegistry`'s generic interface, not native reflection directly.

### 1.1 — Registry and registration plumbing, no data yet

- `Schema\FieldDescriptor`/`FieldKind` — `scalar()` factory only for now ("Shape comes
  from a neutral descriptor"). Later kinds' factories (`valueObject()`, `embed()`,
  `reference()`, `collection()`) are added in the phase that implements them, not stubbed
  early.
- The fixed `entities` table (`id` uuid, `owner`, `owner_field`, `position`,
  `concrete_identifier`) ("Global entity identity").
- `Schema\PrototypeRegistry` interface (`fieldsOf(identifier)`, `instantiate(identifier,
  values)`, `chainOf(identifier)`) — one implementation for native classes via reflection,
  written so a second, editor-created implementation can be added in Phase 6 with zero
  change to any caller.
- `#[Table(string $name)]` attribute + derived-short-name fallback + collision guard, and
  **one registration entry point** (`EntityRegistrar::register($classes)`) resolving every
  level's table name once and constructing everything from that single source of truth —
  folding in what the old roadmap needed a dedicated later phase (6.2) to fix, built right
  the first time here.

**Done when**: the registry resolves a native class's `FieldDescriptor[]` and can
instantiate it from raw values; two unrelated classes that happen to derive the same short
table name fail registration with an actionable error. No data written yet.

### 1.2 — Sync and round-trip

- The `entities` table itself built and synced unconditionally, before any class-derived
  table.
- `Schema\SchemaBuilder`/`SchemaSynchronizer`: Doctrine DBAL `Schema`/`Comparator`-based
  sync. Every field is a real column, full stop — there's no blob tier to ever build
  ("No blobs. Every field is a real column or a real table.").
- CTI chain of at least 2 for every identifier (`entities` + the class's own table) — no
  "chain of one, no join" case, even for a standalone class with no declared parent.
- `Entity\Repository`/`IdentityMap`: insert writes the root row into `entities` first
  (this is where `id` originates), then the class's own row; find/delete join through
  `entities`; identity map scoped per request.
- A throwaway second `Schema\PrototypeRegistry` implementation (a fake, not the
  editor-created one Phase 6 builds for real) — exercised through `Entity\Repository` in a
  test here, not deferred to Phase 6, to catch a native-specific assumption leaking into
  `Repository`/`SchemaBuilder` immediately instead of five phases later.

**Done when**: a scalar-only native class actually round-trips create/read/update/delete
through `Entity\Repository` — which calls only `PrototypeRegistry`, never reflects
directly — rooted under `entities` via CTI, backed by a migration-generated table; the same
round-trip also works end to end through the throwaway second `PrototypeRegistry`
implementation, proving `Repository` never assumed native reflection along the way.

### 1.3 — Validation, uniqueness, and backfill correctness

- `Schema\FieldValidator` strategy interface, a couple of default validators.
- Uniqueness: `unique` flag, real `UNIQUE` constraint, friendly pre-check.
- `#[DefaultInstance]` + resolution order + the eager-failure registration walk
  ("Defaulted instances") — brought in now, not deferred to a late phase, since
  backfilling a new field on an already-populated class is an ordinary event the moment
  real migrations exist.

**Done when**: a hand-written native class with a mix of unique and plain scalar fields
round-trips fully; the unique constraint is enforced; adding a new field to an
already-populated class backfills every existing row via `#[DefaultInstance]` resolution.

**Not yet** (end of Phase 1): entity references/collections, value objects/embed,
native CTI extension (a native class extending another), the `Changeset` write path
(direct `Repository` calls only — nothing needs multi-entity atomicity yet), undo/draft,
permissions enforcement, editor-created prototypes actually existing (the registry's
*shape* supports a second identifier kind; nothing produces one yet), `Query`, any UI.

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

### 3.1 — Native CTI extension

- Native prototype extension via CTI (a native class extending another native class) —
  `SchemaBuilder::tablesForChain()` grows one level beyond the now-mandatory `entities`
  root.

**Done when**: a base/derived native inheritance pair round-trips through the CTI join,
proven in isolation before any relationship complexity is layered on top.

### 3.2 — Shared references and collections

- Shared singular (FK column, `RESTRICT`), Shared collection (real pivot table)
  ("References and collections").

**Done when**: a native class can Shared-reference another (`RESTRICT`-protected), and a
Shared collection round-trips through a real pivot table; a `Reference` or `Collection`
field declared on an `#[Embed]` target (now that both kinds exist) is rejected at
registration time, not left to fail later.

### 3.3 — Owned references and collections

- Owned singular / Owned collection via `entities.owner`/`owner_field`/`position` — built
  this way from day one, no per-relationship dedicated table detour (the old design's
  first attempt, superseded before this rewrite even started). The genuinely novel
  mechanism in this design, isolated here deliberately.
- The `#[DefaultInstance]` completeness walk extended to also cover every reachable
  `#[Reference]` target, not just `#[Embed]`.

**Done when**: a native class can Owned-collection a shape (`CASCADE` via
`entities.owner`); deleting an owner cascades correctly to every Owned child, at any
depth, through one `entities`-scoped query; the same reusable shape Owned by two unrelated
relationships (disambiguated by `owner_field`) round-trips correctly.

### 3.4 — Non-entity collections and `MediaAsset`

- Non-entity collection (scalar, value-object, or `#[Embed]` items): a dedicated child
  table (`ownerId`, `position`, value column(s)), `CASCADE`-deleted with the owner; an
  `#[Embed]` item flattens its shape's fields into that child table's own columns, same
  recursive flattening as an Embed field on an ordinary entity row.
- `MediaAsset` as the worked example exercising both Owned (inline upload) and Shared
  (media library) at once ("Media/file fields") — ties 3.2 and 3.3 together.

**Done when**: a non-entity collection (scalars or value-object items) round-trips through
its dedicated child table, ordered and cascade-deleted with the owner; a collection of
`#[Embed]` items round-trips with each position's shape flattened into its own row;
`MediaAsset` demonstrates both Owned and Shared usage at once.

**Not yet** (end of Phase 3): the `Changeset` write path (a create-and-attach-in-one-call
isn't atomic until Phase 4 — this phase's own tests create the referenced entity first, as
two separate steps), undo/draft, permissions, editor-created prototypes, `Query`.

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
flush commits atomically; a changeset creating a new Owned child and its owner in the same
flush commits atomically too — the higher-risk mechanism (Phase 3.3 calls Owned "the
genuinely novel mechanism in this design"), not left covered only by the Shared case; two
new entities referencing each other in one changeset are rejected with an error naming the
cycle; a stale `expectedOperationId` is rejected; a changeset deleting a referencer and
what it references in the wrong order is corrected by the sort, not left to rely on
`RESTRICT` as a backstop.

## Phase 5 — Undo, draft, revision history (content-only)

**Goal**: every flush is loggable and recoverable, per the Envers-style `Revision` +
per-entity `EntityChangeRecord` design; nothing is ever pruned automatically.

### 5.1 — Logging only, no undo yet

- `Changeset\Undo\Revision` (metadata-only, one per flush) + `Changeset\Undo\EntityChangeRecord`
  (per-entity diff, FK'd to the `Revision`) ("Content undo, draft, and revision history").

**Done when**: every flush produces a correct `Revision` and correct per-entity
`EntityChangeRecord`s, including for a multi-entity flush — verified by inspection, no
undo capability exists yet.

### 5.2 — Undo, redo, authorization

- Undoing a whole `Revision`, across however many entities it touched: an independent
  conflict check per touched entity, atomic inverse apply through the same
  `ChangesetFlusher`, outright refusal (never silent-partial) if any touched entity's
  record is missing or conflicted.
- Redo (undo-the-undo, no new mechanism).
- Undo authorization: a `Permission\` check that the `Revision` belongs to the requesting
  user/session, checked once, before the inverse changeset is even computed.

**Done when**: a multi-entity `Revision` undoes atomically with a per-entity conflict
check; redo restores it exactly; undoing with someone else's `Revision` id is rejected.

### 5.3 — Draft

- `Changeset\Draft\DraftStore`/`DraftPreview`: a persisted-but-unflushed changeset plus an
  in-memory apply/preview function; publishing flushes the exact same changeset through
  the exact same path, producing a `Revision` like any other flush.

**Done when**: a draft builds, previews, and publishes through the exact same write path
as an ordinary flush.

### 5.4 — Revision-history restore and manual pruning

- Revision-history restore: reads one entity's own `EntityChangeRecord` chain, restores by
  flushing a new changeset. The "blocked past any schema change" guard is written here but
  can't be meaningfully exercised until Phase 6 introduces schema mutation — noted, not a
  blocker.
- A manual pruning tool: explicit, human-triggered, no automatic policy of any kind, gated
  by a new `Permission\HistoryPermission` ("Permissions").

**Done when**: manually pruning one touched entity's `EntityChangeRecord` and then
attempting to undo the `Revision` it belonged to produces a clean, specific refusal, not a
silent partial revert; pruning without `HistoryPermission` is rejected; pruning the last
`EntityChangeRecord` under a `Revision` leaves it as an empty row, not auto-deleted, and
that empty `Revision` can itself be pruned by a separate explicit call.

**Not yet** (end of Phase 5): `FieldPermission` enforcement (Phase 7), `SchemaPermission`,
editor-created prototypes, schema mutation, `Query`.

## Phase 6 — Admin-authored schema

**Goal**: admins can create prototypes and safely mutate editor-created subclasses at
runtime. `DynamicEntity` and the editor-created branch of `PrototypeRegistry` are
exercised for the first time here — plugging into machinery every prior phase already
built generically, no retrofit.

### 6.1 — Second identifier kind, minimal creation path

- `Schema\DynamicEntity` + the editor-created implementation of
  `PrototypeRegistry::instantiate()`/`fieldsOf()` — the second identifier kind the
  registry interface has supported since Phase 1.
- `Schema\SchemaEditor::createPrototype()` — minimal, scalar fields only, no parent
  complexity yet.
- `Permission\SchemaPermission` gating from the start, not bolted on after.

**Done when**: an admin without `SchemaPermission` cannot create a prototype; one who does
can create a fresh editor-created prototype with scalar fields, immediately
readable/writable through the exact same `Entity\Repository`/`Changeset\ChangesetFlusher`
every native class already uses — no separate wiring step, since the wiring already
existed.

### 6.2 — Mutating an existing, populated prototype

- `addColumn()`/`dropColumn()`, `rename()` (prototype and field, explicit old→new mapping
  passed in, never inferred, never an attribute), `retype()` (field, collection-item-kind,
  and `Reference` target type, an explicit converter class required, refused otherwise) —
  safe-DDL-only, scoped to the subclass's own table ("Migrations and schema mutation").

**Done when**: an already-populated editor-created prototype can have a column
added/dropped/renamed/retyped safely, each gated by `SchemaPermission`, each reflected
immediately through the ordinary read/write path; renaming/retyping a field on a shape
used as an `#[Embed]` target (introduced in Phase 2) propagates to every table embedding
it, not just the shape's own declaration; retargeting a `Reference` field to a different
target type converts every existing row through its required converter, refusing loudly
if any row's existing target has no valid mapping.

### 6.3 — Reparenting and `EditorExtensible`

- `reparent()` — mechanically uniform insert/remove of one CTI level, backfilled via
  `#[DefaultInstance]`.
- `#[EditorExtensible]` attribute + revocation (falls back to `entities` directly, lazily,
  on the next schema save) — the same mechanism as deleting a prototype with live
  editor-created subclasses.

**Done when**: reparenting an editor-created prototype backfills correctly; revoking
`#[EditorExtensible]` on a native class with a live editor-created subclass falls back to
`entities` on the next schema save, data intact until then.

### 6.4 — Deletion policy and auditing

- Prototype/class deletion, dangling-allowed by design: native-referencing-native fails at
  registration (already free, from Phase 1's field-tree walk); native deletion goes
  through the reviewed-migration tool; anything on the editor-schema side gets a
  `SchemaEditor` save-time block plus a separate, standalone auditing tool scanning every
  editor-created schema for breakage.

**Done when**: deleting a prototype referenced elsewhere dangles, caught at the documented
point for whichever context (native registration, reviewed migration, or the editor
auditing tool) applies.

**Not yet** (end of Phase 6): `FieldPermission`, `Query`.

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

### 8.1 — Base query builder

- `Entity\Query`: `Query::for($identifier)->where(...)->orderBy(...)->after($cursor)->limit($n)->get()`
  ("Admin list/filter views").
- Resolves a field to its real column *and* which chain-level table holds it.
- One multi-table `JOIN` across the whole chain with per-level column aliasing, replacing
  a one-query-per-level approach.
- Cursor/keyset pagination (sort-column value + primary-key tiebreaker) from the start,
  never `OFFSET`/`LIMIT`; `count()` shares the same `WHERE`, independent of pagination.

**Done when**: a list view for an identifier filters/sorts by a field declared at any
level of its own chain, native or editor-created, returns a stable cursor-paginated page
with a correct has-more signal, base-typed rows only; `count()` matches the same filter
independent of pagination; an actor without read permission on a field never sees it in a
`Query` result row, same `FieldPermission` filter as `Repository::find()`; filtering or
sorting by a field the actor can't read is rejected outright, not silently allowed to leak
that field's values through sort order or which rows pass the filter.

### 8.2 — Opt-in polymorphic hydration

- `Entity\Repository::findMany(array $ids)`, batched by chain level.
- `Query::hydrateConcreteTypes()`, off by default.

**Done when**: a page mixing several distinct concrete subtypes costs exactly one extra
batched lookup per distinct subtype when polymorphic hydration is explicitly requested,
and nothing extra when it isn't.

## Shelf items (unchanged from `ARCHITECTURE_V2.md`)

Not numbered phases — pick up only once a real need shows up: full-text/cross-content-type
search, real-time concurrent multi-editor collaboration.

## Not covered by this roadmap

- **`Editor\`** — the admin-facing authoring UI and the client-side `LocalCommand`/
  `RemoteCommand` stack. A separate, later track, once enough of the above exists to build
  and drive it against.
- **Fine-grained revision-history reachability across a schema change** — still listed as
  open in `ARCHITECTURE_V2.md`'s "Deferred" section; pick a phase for it once it's actually
  resolved there.
