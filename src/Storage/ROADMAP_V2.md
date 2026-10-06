# Storage / Editor — Development Roadmap v2 (rewrite)

Phased build plan for `ARCHITECTURE_V2.md`. Section names below (`"Like this"`) refer to
headings in that document; build against it, not a re-explanation here. Supersedes
`ROADMAP.md` the same way `ARCHITECTURE_V2.md` supersedes `ARCHITECTURE.md` — the old
roadmap's phases 1-8C stay as-is under the old `Storage\` namespace, left untouched as a
reference (see `ARCHITECTURE_V2.md`, "Namespaces and migration path").

**Scope**: this roadmap covers `Persistence\Entity\`, `Persistence\Schema\`, `Persistence\Changeset\`, and `Persistence\Permission\` — the
backend. `Editor\` (the admin-facing authoring UI, including the client-side
`LocalCommand`/`RemoteCommand` stack) is a separate, later track, built once enough
backend exists to author and drive it against — not numbered as phases here.

**The one structural fix this roadmap is built around**: `entities` is the universal root
from Phase 1, and every phase from Phase 1 onward builds `Persistence\Entity\Repository`/
`Persistence\Changeset\ChangesetFlusher`/`Persistence\Entity\Query` against `Persistence\Schema\PrototypeRegistry`'s generic
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
`Persistence\Schema\PrototypeRegistry`'s generic interface, not native reflection directly.

### 1.1 — Registry and registration plumbing, no data yet

- `Persistence\Schema\FieldDescriptor`/`FieldKind` — `scalar()` factory only for now ("Shape comes
  from a neutral descriptor"). Later kinds' factories (`valueObject()`, `embed()`,
  `reference()`, `collection()`) are added in the phase that implements them, not stubbed
  early.
- The fixed `entities` table (`id` uuid, `owner`, `owner_field`, `position`,
  `concrete_identifier`) ("Global entity identity").
- `Persistence\Schema\PrototypeRegistry` (`fieldsOf(identifier)`, `instantiate(identifier,
  values)`, `chainOf(identifier)`) — resolves a native identifier via reflection;
  `class_exists($identifier)` is the test that distinguishes it from the second,
  editor-created identifier kind Phase 6 gives a real resolution path. Stateless from day
  one: nothing caches a resolved shape, so there's nothing to go stale once Phase 6 starts
  writing to the second kind's own storage.
- `#[Entity(table: ...)]` (see "Shape comes from a neutral descriptor") + derived-short-name
  fallback + collision guard, and **one registration entry point**
  (`EntityRegistrar::register($classes)`) resolving every native level's table name once, at
  registration time, and constructing everything from that single source of truth. Scoped to
  native only: an editor-created identifier's table name is never frozen into this same map —
  it resolves live through `PrototypeRegistry` instead, the moment Phase 6 gives it something
  to resolve. `#[Entity]`'s own `editorExtensible` parameter exists from here on too, unused
  until Phase 6.3 gives it meaning — one attribute, decided once, not two introduced five
  phases apart.

**Done when**: the registry resolves a native class's `FieldDescriptor[]` and can
instantiate it from raw values; two unrelated classes that happen to derive the same short
table name fail registration with an actionable error. No data written yet.

### 1.2 — Sync and round-trip

- The `entities` table itself built and synced unconditionally, before any class-derived
  table.
- `Persistence\Schema\SchemaBuilder`/`SchemaSynchronizer`: Doctrine DBAL `Schema`/`Comparator`-based
  sync. Every field is a real column, full stop — there's no blob tier to ever build
  ("No blobs. Every field is a real column or a real table.").
- CTI chain of at least 2 for every identifier (`entities` + the class's own table) — no
  "chain of one, no join" case, even for a standalone class with no declared parent.
- `Persistence\Entity\Repository`/`IdentityMap`: insert writes the root row into `entities` first
  (this is where `id` originates), then the class's own row; find/delete join through
  `entities`; identity map scoped per request.
- A throwaway second identifier kind for `Persistence\Schema\PrototypeRegistry` (a fake, not
  the editor-created one Phase 6 builds for real), including a fake table-name resolution,
  not just `fieldsOf()`/`instantiate()` — exercised through `Persistence\Entity\Repository`
  in a test here, not deferred to Phase 6, to catch a native-specific assumption leaking
  into `Repository`/`SchemaBuilder` immediately instead of five phases later.

**Done when**: a scalar-only native class actually round-trips create/read/update/delete
through `Persistence\Entity\Repository` — which calls only `PrototypeRegistry`, never reflects
directly — rooted under `entities` via CTI, backed by a migration-generated table; the same
round-trip also works end to end through the throwaway second identifier kind, table name
included, resolved without ever appearing in the native case's own registration-time map,
proving `Repository` never assumed native reflection or a pre-registered table name along
the way; resolving the same id twice within one request via `Repository::find()` returns
the identical PHP instance, not two separate objects, proving the identity map from
"Identity Map + Repository + lazy loading" ("identity map scoped per request" above).

### 1.3 — Validation, uniqueness, and backfill correctness

- `Persistence\Schema\FieldValidator` strategy interface, a couple of default validators,
  each exposing `describe(): array` — proven here by checking the shape it returns for a
  couple of built-ins (e.g. a length check, a format check), not yet consumed by anything;
  the client-side mapping of that output to an HTML5 attribute, a shared JS algorithm
  registry, or a server-only fallback is `Editor\`'s own concern (see "Not covered by this
  roadmap"), nothing to build here.
- `Persistence\Schema\PrototypeValidator` strategy interface, proven with one hand-written,
  arbitrary-logic example (end date after start date) on a native class with two plain
  scalar fields — needs nothing beyond what this phase already has, same as `Unique` below.
  `describe()` proven the same way as `FieldValidator`'s.
- `Unique`: one of `PrototypeValidator`'s built-in kinds, naming one or more of a class's
  own fields, materialized as a real composite (or single-column) `UNIQUE` index at
  registration time, with a friendly pre-check ahead of the real constraint ("Uniqueness").
  Single-field and multi-field groups both land here — composite grouping among plain scalar
  fields on one standalone class needs nothing beyond what this phase already has; the
  scoping/exclusion rules that depend on `#[Embed]`, references, collections, or CTI
  extension are proven later, in the phase that introduces each.
- `#[DefaultInstance]` + resolution order + the eager-failure registration walk
  ("Defaulted instances") — brought in now, not deferred to a late phase, since
  backfilling a new field on an already-populated class is an ordinary event the moment
  real migrations exist.

**Done when**: a hand-written native class with a mix of `Unique` and plain scalar fields
round-trips fully; a single-field `Unique` group and a multi-field one are both enforced,
each independently of the other; an arbitrary-logic `PrototypeValidator` rejects a candidate
state that violates it and accepts one that doesn't; `describe()` on a couple of
`FieldValidator` built-ins and on the arbitrary-logic `PrototypeValidator` each return the
expected shape; adding a new field to an already-populated class backfills every existing
row via `#[DefaultInstance]` resolution.

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
- `EntityRegistrar::register()`'s full-field-tree walk records the reverse edge for every
  `#[Embed]` site it visits (shape X to every table embedding it), as a byproduct of the
  same pass, not a separate scan — this is what new-field backfill above, and rename/retype
  propagation (Phase 6.2), both query.

**Not yet**: references/collections, native CTI extension, `Changeset`, undo/draft,
permissions, editor-created prototypes, `Query`.

**Done when**: a value-object field round-trips as a single custom-typed column; an embed
field flattens recursively, including a nested embed and a nested value-object member; the
same shape embedded twice under different field names on one entity disambiguates for
free via the field's own name as column prefix (`billingAddress_city` vs.
`shippingAddress_city`); a test embedding A-in-B-in-A is rejected at registration instead
of recursing forever; backfilling a new field on an embedded shape finds every embedding
table via the reverse edge, not a fresh scan; a `Unique` group declared on the embedded
shape's own fields materializes as an independent composite index at each of the two
embedding sites, proven by the same shape embedded twice disambiguating here too — a
collision at one site never blocks an otherwise-identical value at the other.

## Phase 3 — References & collections, native CTI extension, `MediaAsset`

**Goal**: entity-to-entity references/collections (Shared + Owned) work via
`entities.owner`, and a native class can extend another.

### 3.1 — Native CTI extension

- Native prototype extension via CTI (a native class extending another native class) —
  `SchemaBuilder::tablesForChain()` grows one level beyond the now-mandatory `entities`
  root.

**Done when**: a base/derived native inheritance pair round-trips through the CTI join,
proven in isolation before any relationship complexity is layered on top; a `Unique` group
naming one field declared on the base class and one declared on the derived class is
rejected at registration time, not left to fail as a migration-time surprise once two
physical tables are involved.

### 3.2 — Shared references and collections

- Shared singular (FK column, always nullable, `SET NULL` on the target's deletion — a
  `Reference` can never be required at the schema level, so `RESTRICT` is never an option
  here), Shared collection (real pivot table with its own `position` column, owner-side FK
  `CASCADE`, target-side FK `SET NULL` same as the singular case) ("References and
  collections", "FK `ON DELETE` policy").

**Done when**: a native class can Shared-reference another, the FK column always nullable;
deleting the referenced row sets the column to `NULL`, never blocked; a Shared collection
round-trips through a real pivot table, ordered by `position`; deleting the owner-side
entity removes its own join rows via `CASCADE`; deleting a target referenced by a collection
item sets that pivot row's own FK to `NULL` instead of removing the row, preserving the
slot's `position` and the collection's true count rather than silently shrinking it; a
`Reference` or `Collection` field declared on an `#[Embed]` target (now that both kinds
exist) is rejected at registration time, not left to fail later; a `Unique` group naming a
field reached through a `Reference` (now that one exists) is rejected at registration time
too, for the same reason — the compared column lives on the referenced row's own table, not
on any table the constraint could be declared against; loading an entity with a Shared
reference or collection field triggers no query for the target until the field is actually
accessed; accessing it then triggers exactly one query; resolving the same target id a
second time in the same request — through the same field again, or found independently
elsewhere — returns the identical PHP instance with no second query, proving laziness and
the identity map compose the way "Identity Map + Repository + lazy loading" describes, not
each proven in isolation.

### 3.3 — Owned references and collections

- Owned singular / Owned collection via `entities.owner`/`owner_field`/`position` — built
  this way from day one, no per-relationship dedicated table detour (the old design's
  first attempt, superseded before this rewrite even started). The genuinely novel
  mechanism in this design, isolated here deliberately.
- `entities.owner` is `ON DELETE RESTRICT`, not `CASCADE` — deletion of an owner's Owned
  descendants is app-mediated. The auto-expanding, logged version of that lives in
  `Changeset` (Phase 4); here, proven via direct, explicit `Repository` deletes in
  dependency order.
- The `#[DefaultInstance]` completeness walk extended to also cover every reachable
  `OwningReference`/Owned-`Collection` target, not just `#[Embed]` — never a plain
  `#[Reference]` target, which needs no default at all (`Reference` can never be required,
  see "FK `ON DELETE` policy").
- Every Owned-collection field gets a `<field>_count` column on the owner's own declaring
  table, uniformly for required and optional item kinds — needed because an absent
  *optional* owned item leaves no row at all, so counting `entities` rows undercounts the
  true slot count the moment any slot is empty ("References and collections").

**Done when**: a native class can Owned-collection a shape via
`entities.owner`/`owner_field`/`position`; the same reusable shape Owned by two unrelated
relationships (disambiguated by `owner_field`) round-trips correctly; deleting an owner
while an Owned child still references it fails with a constraint violation, and only
succeeds once every Owned descendant, at any depth, is deleted first — proven here via
direct `Repository` calls, since `Changeset` doesn't exist until Phase 4 (the full
auto-expanding, logged version of this delete is Phase 4's own done-when, not retested
here); an Owned collection with an optional item kind round-trips with an empty slot in the
middle (not just at the end), the stored count correctly reflecting the true slot count
rather than the number of live rows; removing a slot shrinks the stored count and shifts
later positions down, while clearing a slot's content leaves the count and every position
untouched — proven here via direct `Repository`/manual-update calls computing the shift and
count change by hand, since `Changeset` doesn't exist until Phase 4 (the auto-expanding,
explicit-intent, logged version of this is Phase 4's own done-when, not retested here); a
`Unique` group declared on the Owned-collection item's own fields allows the
same combination to appear once under two different owners while rejecting a second
occurrence under the same owner, proving the owner-scoping column was folded into the real
constraint rather than left as a group spanning every owner's items at once; loading an
owner triggers no `entities.owner`/`owner_field` lookup for an Owned singular or collection
field until it's actually accessed, the same laziness rule as 3.2's Shared case, now proven
for the reverse-indexed-query shape Owned uses instead of a stored FK.

### 3.4 — Non-entity collections and `MediaAsset`

- Non-entity collection (scalar, value-object, or `#[Embed]` items): the same
  `(ownerId, position, value column(s))` dedicated-table shape 3.2's Shared-collection pivot
  already built, reused here rather than a second mechanism — `value column(s)` holds a
  scalar/value-object value or an `#[Embed]` shape's own flattened columns instead of a
  target-FK, `CASCADE`-deleted with the owner the same way the pivot's own owner-side FK
  already is; an `#[Embed]` item flattens its shape's fields into that child table's own
  columns, same recursive flattening as an Embed field on an ordinary entity row.
- `MediaAsset` as the worked example exercising both Owned (inline upload) and Shared
  (media library) at once ("Media/file fields") — ties 3.2 and 3.3 together.

**Done when**: a non-entity collection (scalars or value-object items) round-trips through
its dedicated child table, ordered and cascade-deleted with the owner; a collection of
`#[Embed]` items round-trips with each position's shape flattened into its own row;
`MediaAsset` demonstrates both Owned and Shared usage at once; a `Unique` group declared on
an `#[Embed]`-collection item's own fields allows the same combination to appear once under
two different owners while rejecting a second occurrence under the same owner, the same
owner-scoping proof as 3.3's Owned-collection case, now for a dedicated child table instead
of `entities`; loading an owner triggers no query against a non-entity collection's own
dedicated table until the field is actually accessed, the same laziness rule as 3.2/3.3,
proven here for the dedicated-child-table shape rather than a live subtype needing identity-
map reuse, since a non-entity item has no identity of its own to resolve twice.

**Not yet** (end of Phase 3): the `Changeset` write path (a create-and-attach-in-one-call
isn't atomic until Phase 4 — this phase's own tests create the referenced entity first, as
two separate steps), undo/draft, permissions, editor-created prototypes, `Query`.

## Phase 4 — Changeset write path

**Goal**: every multi-entity write goes through an explicit, atomic changeset with a real
topological sort — no more manual two-step create-then-attach.

- `Persistence\Changeset\Changeset`/`EntityChange`/`TempId` ("Content write path").
- `Persistence\Changeset\ChangesetSorter`: full topological sort resolving `TempId` dependency edges;
  cycles rejected outright with a clear, named error.
- Delete expansion, downward: adding a delete for entity X to a `Changeset` also adds a
  delete for every entity in X's owned subtree, at any depth, before `ChangesetSorter` runs
  — reusing `Repository`'s existing owned-descendant lookup, not a new query ("Content write
  path").
- Delete expansion, sideways: deleting any entity X also finds every entity whose declared
  field currently holds a live Shared reference to X (singular or collection item) and adds
  that field's own `SET NULL` change to the same changeset, instead of letting the
  database's `ON DELETE SET NULL` constraint fire untracked — logged against the
  collection's own declaring entity for a collection-item case, never the pivot row
  ("Content write path"). Composes with the downward expansion above automatically: each
  owned descendant's own deletion independently triggers this same sideways check.
- Owned-collection slot expansion: an explicit Remove or Insert adds the sibling-`position`
  shift and the owner's own `<field>_count` adjustment to the same changeset automatically,
  as its own logged operations — never left for the caller to compute by hand the way Phase
  3.3 proved the physical mechanics. Clear and Fill need no expansion at all, both already
  an ordinary create/delete ("Content write path"). Composes with the downward/sideways
  expansions above for free: Remove is still an ordinary delete underneath, so an owned item
  that itself owns descendants or is Shared-referenced from elsewhere triggers both of those
  the same as any other deletion.
- `Persistence\Changeset\ChangesetFlusher`: applies a changeset as one atomic database transaction.

**Not yet**: undo/draft, permissions, editor-created prototypes, `Query`, concurrent-write
protection (`expectedOperationId` is sourced from the undo log's own monotonic sequence —
see "Content write path" — so it can't exist before `EntityChangeRecord` does; lands in
Phase 5.1 instead).

**Done when**: a changeset creating a new Tag and attaching it to a Product in the same
flush commits atomically; a changeset creating a new Owned child and its owner in the same
flush commits atomically too — the higher-risk mechanism (Phase 3.3 calls Owned "the
genuinely novel mechanism in this design"), not left covered only by the Shared case; two
new entities referencing each other in one changeset are rejected with an error naming the
cycle; a changeset deleting a referencer and
what it references in the wrong order is corrected by the sort, not left to rely on
`RESTRICT` as a backstop; deleting an owner with populated Owned descendants, at any depth,
auto-expands into an explicit delete for each one, correctly ordered, each producing its
own logged operation — not left to `entities.owner`'s `RESTRICT` constraint to reject the
whole transaction; deleting an entity that's Shared-referenced elsewhere produces a logged
field-change on the referencing entity (null-ing the FK) in the same flush, not just a raw
database-level side effect, proven for both a singular reference and a collection item (the
latter logged against the collection's own declaring entity); deleting an owner whose
transitively-owned subtree includes an entity that's *also* Shared-referenced from outside
that subtree produces both expansions in the same flush — the owned entity's own deletion,
and the outside referrer's logged field-change — with no special-casing for how the delete
was reached; an explicit Remove of a middle slot in an Owned collection shifts every later
sibling's `position` down and decrements the owner's own `<field>_count`, each shifted
sibling producing its own logged operation, all in the same flush as the item's own deletion;
an explicit Insert at a middle position does the mirror-image shift upward and increments the
count; a Clear or a Fill produces no sibling shift and no count change, proven alongside
Remove/Insert rather than assumed identical from Phase 3.3's own test of the physical
mechanics; removing a slot whose own item owns further descendants or is Shared-referenced
from outside the collection triggers the downward/sideways expansions in the same flush,
proving the composition rather than assuming it from the two mechanisms being independently
correct.

## Phase 5 — Undo, revision history (content-only)

**Goal**: every flush is loggable and recoverable, per the Envers-style `Revision` +
per-entity `EntityChangeRecord` design; nothing is ever pruned automatically.

### 5.1 — Logging and concurrent-write protection, no undo yet

- `Persistence\Changeset\Undo\Revision` (metadata-only, one per flush) + `Persistence\Changeset\Undo\EntityChangeRecord`
  (per-entity diff, FK'd to the `Revision`) ("Content undo, draft, and revision history").
  `Revision.actor` is a plain opaque identifier, not a `Persistence\Permission\Actor`
  instance — available here already, needing nothing from 5.3. Each `EntityChangeRecord`
  also captures the entity's own `owner`/`owner_field` as of that write.
- Concurrent-write protection: an `expectedOperationId` receipt, reject-by-default with an
  explicit override to retry ("Content write path") — buildable for the first time here,
  since it's sourced from `EntityChangeRecord`'s own monotonic sequence position, not a
  separate counter. For an entity with Owned descendants, the expected id covers its whole
  owned subtree: found by walking what's still live, plus, for anything removed, that
  entity's own captured `owner`/`owner_field` to keep resolving upward through whatever of
  the chain remains.

**Done when**: every flush produces a correct `Revision` and correct per-entity
`EntityChangeRecord`s, including for a multi-entity flush — verified by inspection, no
undo capability exists yet; a stale `expectedOperationId` is rejected, including one that's
only stale on an entity's owned subtree rather than the entity itself, and including one
that's stale only because a deeply-nested Owned descendant was removed entirely, resolved
via that descendant's own captured `owner`/`owner_field` rather than a live
`entities.owner` walk.

### 5.2 — Undo, redo, authorization

- Undoing a whole `Revision`, across however many entities it touched: an independent
  conflict check per touched entity, atomic inverse apply through the same
  `ChangesetFlusher`, outright refusal (never silent-partial) if any touched entity's
  record is missing or conflicted.
- Redo (undo-the-undo, no new mechanism).
- Undo authorization: a plain equality check that the `Revision`'s own stored `actor`
  matches the requesting user/session's own identifier — a value comparison, not an
  `Actor::hasRole()` call, so it needs nothing from 5.3's `Actor` interface — checked once,
  before the inverse changeset is even computed.

**Done when**: a multi-entity `Revision` undoes atomically with a per-entity conflict
check; redo restores it exactly; undoing with someone else's `Revision` id is rejected;
undoing a `Revision` that deleted a Shared-referenced entity restores both the deleted
entity and the referrer's nulled FK, from the one `EntityChangeRecord` Phase 4's sideways
expansion already produced for the referrer — not left to only restore the deleted entity
itself.

### 5.3 — Revision-history restore and manual pruning

- `Persistence\Permission\Actor` (`hasRole(string): bool`) — a pure adapter interface,
  implemented by whatever registers real users (a separate namespace, not built here);
  introduced here at its first point of use and reused as-is by `SchemaPermission` (Phase
  6.1) and `FieldPermission` (Phase 7), no changes needed later. Backs exactly these
  role-based checks, not 5.2's undo-authorization check, which compares `Revision.actor` by
  value instead and needs no role interface at all.
- Revision-history restore: reads one entity's own `EntityChangeRecord` chain, restores by
  flushing a new changeset. Needs a single global, monotonically-incrementing
  `schema_version` sequence, stamped onto every `EntityChangeRecord` at write time — the
  "blocked past any schema change" guard compares a chosen record's stamped version against
  the current one. The sequence and the stamping/comparison are built here; nothing bumps
  the sequence yet until Phase 6 introduces schema mutation, so the guard can't be
  meaningfully exercised end to end until then — noted, not a blocker.
- A manual pruning tool: explicit, human-triggered, no automatic policy of any kind, gated
  by a new `Persistence\Permission\HistoryPermission` ("Permissions"). No post-prune
  observer hook built here (see "Media/file fields") — deliberately deferred, not an
  oversight, since the tool already reads a record's full structured content before
  deleting it, so the hook is addable later with no schema change.

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
built generically, no retrofit. Every mutation introduced across this phase —
`createPrototype()`, `addField()`/`dropField()`/`rename()`/`retype()`, `reparent()` — also
bumps the global `schema_version` sequence from Phase 5.3, exercising its restore-blocking
guard end to end for the first time.

### 6.1 — Second identifier kind, minimal creation path

- `Persistence\Schema\DynamicEntity` + the editor-created implementation of
  `PrototypeRegistry::instantiate()`/`fieldsOf()` — the second identifier kind the
  registry interface has supported since Phase 1.
- `Persistence\Schema\SchemaEditor::createPrototype()` — minimal, scalar fields only, no parent
  complexity yet. Non-scalar field addition is deliberately not stubbed here — it lands in
  6.2's `addField()`, once an existing prototype can be safely mutated at all.
- An editor-created prototype can attach a `PrototypeValidator` rule at creation time,
  picked from the closed menu Phase 1.3's native-side mechanism already supports built-in
  kinds for — `Unique` is the first one exercised here, since it's the only kind this phase
  needs to prove end to end; arbitrary native-only logic was already proven native-side in
  Phase 1.3 and has no editor-created equivalent, by design ("Validation").
- `Persistence\Permission\SchemaPermission` gating from the start, not bolted on after.

**Done when**: an admin without `SchemaPermission` cannot create a prototype; one who does
can create a fresh editor-created prototype with scalar fields, immediately
readable/writable through the exact same `Persistence\Entity\Repository`/`Persistence\Changeset\ChangesetFlusher`
every native class already uses — no separate wiring step, since the wiring already
existed; a fresh editor-created prototype created with a `Unique` group attached enforces it
immediately, the same real-constraint mechanism Phase 1.3 already proved for native classes,
reused rather than rebuilt for the second identifier kind.

### 6.2 — Mutating an existing, populated prototype

- `addField()`/`dropField()`/`rename()`/`retype()` — safe-DDL-only, scoped to the
  subclass's own schema ("Migrations and schema mutation"). `rename()` takes an explicit
  old→new mapping, never inferred, never an attribute. `addField()` accepts any
  `FieldDescriptor` kind, not just scalar: a column for
  scalar/value-object/singular-`Reference`/`Embed`-flattening, a new dedicated pivot/child
  table for a `Collection`, no physical change beyond the registry record for an
  `OwningReference` or Owned `Collection` — this is what actually lets an editor-created
  prototype gain a relationship field at all, deferred from 6.1's scalar-only creation path.
  `retype()` covers every field kind, including collection-item-kind changes,
  `Reference`/`OwningReference`/Owned-`Collection` target-type changes, and crossing
  between Shared, Owned, and `#[Embed]` — one capture-to-`NoType` plus
  reconstruct-from-`NoType` mechanism throughout. A converter is always optional: supplied,
  it derives the new value from the old; omitted, the field falls back to its own class
  default. A supplied converter is a `Persistence\Schema\FieldRetypeConverter`, declaring its
  own `from()`/`to()` as a `FieldRetypeSignature` (kind + optional, wildcardable target) that
  `retype()` checks by reflection against the field's actual current shape and the candidate
  target shape before running anything, rejecting a mismatched converter outright, naming the
  mismatch ("Migrations and schema mutation"). `dropField()` on an `OwningReference` or Owned `Collection` cascade-deletes every
  existing owned entity at that relationship, across every entity with the field, through
  the same ordinary `Changeset` path Phase 4's delete expansion already built (downward into
  anything each owned entity in turn owns, sideways into any outside Shared reference
  pointing at one) — not left as a pure registry-record change the way adding the field was.
- Adding or removing a field's membership in a `Unique` group, with no kind change bundled
  alongside it, takes the narrow path described in "Uniqueness": a friendly pre-check against
  current live values, then the real index `ALTER`, never routed through `retype()`'s
  capture/reconstruct machinery since no value ever changes. Bundled with a kind change in
  the same admin action, it goes through `retype()` instead, which already needs to know
  about `Unique` membership regardless (see the distinctness check below).
- Attaching or removing a `PrototypeValidator` rule on an already-populated editor-created
  prototype, picked from the same closed menu 6.1's creation path already draws from —
  mutating which rules are attached is governed by `SchemaPermission` the same as any other
  schema mutation, no separate permission needed.

**Done when**: an already-populated editor-created prototype can have a field of any kind
added, each landing in the physical shape its kind implies (a column, a new dedicated
table, or nothing beyond the registry record for an Owned relationship) without disturbing
existing rows; a column can be added/dropped/renamed/retyped safely, each gated by
`SchemaPermission`, each reflected immediately through the ordinary read/write path;
dropping an `OwningReference` or Owned `Collection` field that has existing owned entities
cascade-deletes every one of them, at any depth, each producing its own logged operation
through the same Phase 4 machinery — not left orphaned, findable only by
`owner`/`owner_field` with no declaring field left to resolve them, and also dropping the
field's own `<field>_count` column for the `Collection` case;
renaming/retyping a field on a shape used as an `#[Embed]` target (introduced in Phase 2)
propagates to every table embedding it, not just the shape's own declaration; retargeting a
`Reference` field to a different target type converts every existing row through its
converter if one is supplied, or leaves the FK null otherwise — never a failure, since a
`Reference` can never be required; retyping a `Collection` field into a singular one (or the
reverse) through its own converter, or its own class default if none is supplied, drops the
now-unused dedicated table (or creates a freshly-needed one), proven as its own case
distinct from a deletion-triggered `NoType` conversion, which never changes cardinality;
retyping a `Collection`'s item kind across the entity/non-entity boundary (a collection of
value-object items retyped into a collection of `Reference` items, and the reverse) passes
the whole captured array to a single converter call that returns a new array of any
length — not a forced one-call-per-item mapping — dropping the old value column(s) and
adding whatever the new item kind needs, proven alongside the same-shape item-kind retype
case rather than assuming every item-kind change is a same-column swap, and proven with a
converter that deliberately returns fewer items than it received; a supplied
`FieldRetypeConverter` whose declared `from()` doesn't match the field's actual current
shape, or whose `to()` doesn't match the candidate target shape, is rejected before any row
is touched, proven for a kind mismatch and for a wildcarded-vs-concrete-target mismatch; a
field's current shape resolves to a `FieldRetypeSignature` that matches every converter
registered with a wildcarded `from()` for that kind, not just an exact-target one; renaming a
Shared-collection or non-entity-collection field (introduced in Phase 3) renames its own
dedicated table too, not just the field's metadata; removing such a field drops that
dedicated table outright; renaming a field that declares an `OwningReference` or an Owned
`Collection` (introduced in Phase 3.3) updates `entities.owner_field` for every existing
row at that relationship, proven by reading an already-owned row back correctly under the
field's new name after the rename; renaming an Owned `Collection` field also renames its own
`<field>_count` column (introduced in Phase 3.3) alongside the `owner_field` update, an
ordinary column rename rather than a new mechanism; adding a field to a `Unique` group with
no existing duplicates among its current values succeeds, and is refused, naming the
collision, when a duplicate already exists; retyping a field that participates in a `Unique`
group, across a prototype with more than one existing row, is refused outright when no
converter is supplied, and succeeds when a supplied converter's output is checked and found
pairwise-distinct across every row, proven alongside a case where the converter's output
collides and the whole retype is refused rather than partially applied.

The same phase also proves the Owned-crossing cases that come with the general retype
mechanism: retargeting an `OwningReference`/Owned-`Collection`'s own item type while staying
Owned (e.g. `Warranty` to `Guarantee`) produces new owned entities from a supplied
converter's output (any length, not necessarily matching the original count) or from the
field's own class default if no converter is supplied, deleting every old owned row through
the ordinary cascade-delete path — proven to also run that path's sideways expansion
(Phase 4): an old `Warranty` row that happens to be Shared-referenced from an unrelated
field gets that referrer's FK nulled and logged in the same flush, not left to a raw
database-level side effect; retyping a field between Shared, Owned, and `#[Embed]`
(e.g. a Shared reference becoming Owned, or an `#[Embed]` becoming Owned, and the reverse of
each) never forks a duplicate entity or silently deletes a still-referenced row — the result
is only ever what a supplied converter explicitly produces, or each kind's own ordinary
default otherwise (null for Shared, always; empty or a defaulted instance for Owned); a
required `OwningReference`/Owned-`Collection` field's retype fails outright only if neither
a converter nor a class default produces something valid, while an optional one always
accepts an empty result and leaves the new side absent — proven distinctly from `Reference`,
which has no required case to fail at all; attaching a `Unique` group to an already-populated
editor-created prototype through this path, not just at creation, enforces it immediately on
the next write, and removing it stops enforcing without touching existing data.

### 6.3 — Reparenting and `#[Entity]`'s `editorExtensible` flag

- `reparent()` — mechanically uniform insert/remove of one CTI level, backfilled via
  `#[DefaultInstance]` for a level the entity never had a row for; immediately deletes the
  removed level's now-stray data for the reparented entity and every live subclass when
  removing a level, through the ordinary `Changeset` path like any other delete (introduced
  in Phase 4) — reusing the topological sort/Owned-subtree-expansion machinery, a structural
  choice, not an attempt to make the deletion undo-able (it isn't, same as the reparent
  itself: `RemoteCommand` is never pushed for a `SchemaEditor` operation, and
  Revision-history restore is independently cut off past it by `schema_version`, Phase 5.3);
  no backfill needed when reparenting onto a level the entity already had a row for (the two
  sides of the same mechanism).
- **Cycle rejection**: before any mutation runs, `reparent()` walks the candidate new
  parent's own chain and rejects outright, naming the cycle, if the entity being reparented
  appears in it anywhere — covers a direct self-reparent and reparenting onto any current
  descendant with one check, native and editor-created alike, and transitively protects
  every live subclass of the reparented entity too, since a subclass's own chain already
  runs through it.
- Reparenting a shape used as an `#[Embed]` target propagates to every embedding site as a
  column change, never a row change (unlike the entity-table side above, which never changes
  columns) — a level inserted adds that level's own fields as new columns at each site,
  backfilled via `#[DefaultInstance]`, reusing Phase 2's existing "gaining a field backfills
  every embedding table" rule; a level removed drops the now-stray columns at each site, the
  previously-missing mirror-image direction, true for an ordinary `dropField()` on an embedded
  shape too, not just a reparent. Ordinary schema-level DDL, no `EntityChangeRecord` of its
  own, found via the same reverse-index discovery Phase 6.2's rename/retype propagation
  already uses. Reaches every live subclass of the reparented shape too, not just the shape
  `reparent()` was called on — the same cascading reach the entity-row-side deletion already
  has, extended to `#[Embed]` sites, since a subclass's own resolved field tree already
  includes whatever its reparented ancestor contributes.
- `PrototypeRegistry::chainOf()` truncates at the first stored parent identifier that
  fails to resolve, instead of throwing — the shared primitive both halves of
  `editorExtensible` revocation below build on.
- `#[Entity]`'s `editorExtensible` flag (introduced unused back in Phase 1.1) + revocation,
  split into two passes: native-triggered
  (deploy step auto-applies the `entities`-direct fallback, marks every affected direct
  subclass "missing parent, needs review") and editor-created-triggered (stays broken,
  stored parent identifier kept as-is, `SchemaEditor` blocks saving that subclass's own
  schema until a valid parent is set, ordinary content reads/writes keep working via the
  same truncation) — the same split as deleting a prototype with live editor-created
  subclasses, which triggers the editor-created-triggered half for them.

**Done when**: reparenting a prototype directly onto itself, or onto one of its own current
descendants, is rejected outright before any mutation runs, naming the cycle, proven for
both a direct descendant and a case reached only through a live subclass's own chain;
reparenting an editor-created prototype onto a brand-new level backfills
correctly; reparenting it back onto a level it already had a row for needs no backfill and
the data round-trips as it was; reparenting away from a level deletes that level's data for
the reparented entity and its subclasses immediately, not left stray, each deleted row
producing its own `EntityChangeRecord` the same as any other delete rather than vanishing
unlogged; revoking `#[Entity]`'s `editorExtensible` flag on a native class with a live editor-created
subclass falls back to
`entities` at deploy time with a "needs review" marker, content still readable/writable
minus the vanished level's fields; the same revocation triggered by an admin deleting an
editor-created prototype instead stays broken and blocks that subclass's own schema save
until fixed, with no deploy-time auto-fix; a chain with two broken links in a row (deleted
grandparent and deleted parent) still resolves correctly down to `entities`; reparenting a
shape used as an `#[Embed]` target onto a brand-new level adds that level's fields as new
columns at every embedding site, backfilled correctly, not left for the embedding site to
somehow infer; reparenting away from a level drops the now-stray columns at every embedding
site, proven as a schema-only change with no `EntityChangeRecord` produced, distinct from
the entity-table-side row deletion's own logged version above; dropping a field directly
(not via reparenting) from a shape used as an `#[Embed]` target drops the corresponding
column at every embedding site the same way, proving the propagation isn't reparent-specific;
reparenting a shape with a live subclass that's independently used as its own `#[Embed]`
target elsewhere updates that subclass's own embedding site too, not just sites embedding the
shape `reparent()` was directly called on, proving the cascading reach matches the
entity-row-side deletion's own subclass reach rather than stopping one level short.

### 6.4 — Deletion policy, `NoType`, and auditing

- Prototype/class deletion drops the prototype's own table, every dedicated table a field
  it declares owns (a Shared-collection pivot, a "No blobs" child table — the same set its
  own rename already fans out across, per 6.2), and cascade-deletes every existing entity
  row of exactly that concrete type, through the ordinary `Changeset` path (not a bulk
  bypass) — scoped to the exact type, so a deleted prototype's live editor-created
  subclasses keep their own existing instances untouched (only their parent link is
  affected, per 6.3).
- Prototype/class deletion optionally takes a replacement identifier and an
  `Persistence\Schema\EntityRetypeConverter` (`convert(array $capturedFieldTree): object`,
  declaring its own `from()`/`to()` prototype identifiers as a self-consistency check) —
  the same call, not a separate operation ("Migrations and schema mutation"). Without them,
  nothing changes from the bullet above. Supplied, every row with a physical row at the old
  type's own level — exact-type rows and every live subclass's rows — is reconciled against
  the replacement's own chain level by level: a level present in both chains keeps its
  existing row, values updated from the converter's output; a level present only in the old
  chain is deleted; a level present only in the new chain is inserted, filled from the
  converter's output — the same insert/remove-a-CTI-level primitive 6.3's `reparent()` already
  built, reused per-row across the old type's whole live population instead of once per
  entity, converter output replacing `#[DefaultInstance]` backfill wherever a level is newly
  inserted. Every direct child prototype of the old type is reparented onto the replacement
  via an ordinary `reparent()` call using this same reconciliation, which already cascades to
  every further descendant and to every descendant's own `#[Embed]` sites for free (6.3's own
  cascading reach). Every `#[Embed]` occurrence of the old type or any live subclass migrates
  in place through the *same* converter instead of degrading to `NoType` — one converter,
  invoked once per entity-table row for the chain-level case above and once per
  embedding-table row here, since both capture the identical full-field-tree shape elsewhere
  in this design. Every plain `Reference`/`Collection`-of-reference occurrence has its
  declared target type updated automatically, no converter needed, reusing prototype-rename's
  own reference-fixup list (`prototypes.parent`, `entities.concrete_identifier`,
  `reference()`/`embed()`/`collection()` pointers, `owner_field` values) rather than a second,
  substitution-specific fixup mechanism. For native, the developer edits every affected
  class's own source (type-hints, attribute `target` parameters, `extends` clauses) as an
  ordinary reviewed code change first — PHP referencing a class about to stop existing isn't
  something a migration tool can rewrite on its behalf — and the explicit replacement+converter
  declaration then drives the tool's own DDL generation and per-row migrations against the
  result; for editor-created, every step above is immediate and automatic once triggered
  through `SchemaEditor`, the converter picked from a closed, pre-registered menu.
- `NoType`: a reserved `FieldDescriptor` kind — its own distinct `FieldKind`, structurally
  shaped like a Value Object but never a special-cased `valueObject()` reusing a reserved
  `Type` class, and with no public factory; only the framework's own capture/retype
  machinery ever constructs one, unlike the five developer-facing kinds from Phase 1.1
  onward ("Shape comes from a neutral descriptor") — that a `Reference`, `Embed`,
  `Collection`-item, Value-Object, `OwningReference`, or Owned `Collection`-item field
  converts into when its target becomes unresolvable or its value must be invalidated by an
  upstream deletion — to leave a later `retype()` something to
  convert from, not as a historical record (undo/revision history already covers that
  independently). Capturing what can be preserved in a blob is the one deliberate, narrow
  exception to "no blobs"; where that blob lands is purely a function of cardinality, never
  of which kind of field it used to be — a new column on the declaring row for a singular
  field, a new column on the field's own dedicated/pivot table for a collection-item.
  Converting a live `Reference` to `NoType` drops its FK column entirely (not a null-out) —
  prototype deletion was never blockable for a `Reference` in the first place (it can never
  be `RESTRICT`ed), so this isn't about avoiding a failure; it's what preserves the old
  target's identity instead of letting the ordinary `SET NULL` silently erase it, giving a
  later `retype()` something concrete to convert from. Converting every embedding field to
  `NoType` when the embedded prototype disappears reclaims what would otherwise be
  permanent dead columns, using the same recursive field-tree resolution Phase 2 already
  built for flattening, not a shallow top-level-only walk. Converting an `OwningReference`
  or Owned `Collection`-item to `NoType` doesn't change deletion itself at all — the owned
  row(s) still go through the ordinary cascade-delete path built in Phase 4 — it only adds
  a capture-before-delete step (same recursive flattening as the `Embed` case) and, for the
  first time, a real column/dedicated table for what was previously the no-column/no-table
  Owned representation.
- Reverse-index discovery (Phase 2) generalizes past `#[Embed]` to also find `Reference`
  targets, `Collection` item kinds, and Value Object custom-type classes — one mechanism,
  four predicates, reused for backfill/rename/retype propagation, dangling-target
  auditing, and now `NoType` conversion. A separate, data-level query (which existing rows
  currently hold a live value, not just which schemas declare a possible one) feeds the
  deletion/conversion machinery and any future admin-facing warning before a destructive
  delete (`Editor\`, later).
- Native-referencing-native fails at registration (already free, from Phase 1's
  field-tree walk); native deletion goes through the reviewed-migration tool; anything on
  the editor-schema side gets a `SchemaEditor` save-time block plus a separate, standalone
  auditing tool scanning every editor-created schema for breakage, now including dangling
  Value Object custom-type targets alongside reference/embed/collection targets and
  parent revoked/deleted.

**Done when**: deleting a prototype with existing rows still live-referenced elsewhere by a
`Reference` succeeds without ever being blocked (a `Reference` can never be `RESTRICT`ed),
converting that reference to `NoType` instead of leaving a bare, uninformative `null` where
it used to point; deleting a prototype that's a Shared `Collection`'s item kind converts
every existing item, in place in that collection's own pivot table, to `NoType`, preserving
`position` and the collection's own cardinality rather than leaving the slot a bare `null`
FK; deleting a prototype that declares a Shared-collection or non-entity-collection field of
its own drops that field's own dedicated table too, not just its CTI-chain table, proven as
its own case distinct from a field-level removal (which already drops the same table);
deleting a prototype used as an `#[Embed]` target converts every embedding field (including
one with a nested embed inside it) to `NoType`, capturing every flattened value before
dropping the now-redundant columns; deleting a prototype that's owned via an
`OwningReference` converts the owner's
field to `NoType` on a newly-added column, capturing the owned row's own recursively-
flattened values before it's deleted through the ordinary Phase 4 cascade-delete path, not
before; the same for an Owned `Collection`-item, landing in a newly-created dedicated
child table instead and dropping the field's now-redundant `<field>_count` column, since the
dedicated table's own row-per-slot already makes `COUNT(*)` accurate without one; deleting a
prototype cascade-deletes its own existing instances but
leaves a live editor-created subclass's own instances fully intact, flagged only via 6.3's
parent-link mechanism; the auditing tool finds a dangling Value Object target the same way
it finds a dangling reference/embed/collection target; deleting a prototype with a supplied
replacement and `EntityRetypeConverter` preserves every existing row's `entities.id` across
the type swap, readable immediately as the replacement type, not left as a freshly-deleted-
and-recreated row with a new id; the same operation also reaches a live subclass's own
existing rows — not just the exact-type rows the unconditional cascade-delete bullet above is
scoped to — reparenting the subclass's prototype onto the replacement and swapping its own
physical row at the old type's level, while its own lower-level row is left completely
untouched; when the old and new types share a common ancestor level, that level's existing
row is updated in place from the converter's output rather than deleted-and-reinserted,
proven by a converter that deliberately changes an inherited field's value and seeing it
actually persist; the same operation migrates an existing `#[Embed]` occurrence of the
deleted type, or of a live subclass independently used as its own `#[Embed]` target, in place
through that same converter rather than leaving it `NoType`; an existing plain `Reference`
pointing at the deleted type or a live subclass resolves to the replacement type afterward
with no converter involvement at all; a supplied `EntityRetypeConverter` whose declared
`from()`/`to()` don't match the deletion's actual old and new identifiers is rejected before
anything is touched.

**Not yet** (end of Phase 6): `FieldPermission`, `Query`.

## Phase 7 — Field-level permissions

**Goal**: `FieldPermission` enforced everywhere a field's value is read or written.

- `Persistence\Permission\FieldPermission` strategy interface, a default role-based implementation.
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

- `Persistence\Entity\Query`: `Query::for($identifier)->where(...)->orderBy(...)->after($cursor)->limit($n)->get()`
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

- `Persistence\Entity\Repository::findMany(array $ids)`, batched by chain level.
- `Query::hydrateConcreteTypes()`, off by default.

**Done when**: a page mixing several distinct concrete subtypes costs exactly one extra
batched lookup per distinct subtype when polymorphic hydration is explicitly requested,
and nothing extra when it isn't.

## Shelf items (unchanged from `ARCHITECTURE_V2.md`)

Not numbered phases — pick up only once a real need shows up: full-text/cross-content-type
search, real-time concurrent multi-editor collaboration.

## Not covered by this roadmap

- **`Editor\`** — the admin-facing authoring UI, the client-side `LocalCommand`/
  `RemoteCommand` stack, **Draft** (`DraftStore`/`DraftPreview`), and consuming every
  `describe()` output `FieldValidator` exposes (mapping it to a native HTML5 constraint
  attribute, a shared registry of named JS algorithms, or a server-only fallback) — none of
  which have a meaning for a write path that isn't a human composing an edit through this
  UI, so none of it is a persistence-layer concern. A separate, later track, once enough of
  the above exists to build and drive it against; Draft's own lifecycle and shape are
  undecided, see `ARCHITECTURE_V2.md`'s "Content undo, draft, and revision history."
- **Fine-grained revision-history reachability across a schema change** — still listed as
  open in `ARCHITECTURE_V2.md`'s "Deferred" section; pick a phase for it once it's actually
  resolved there.
