# Storage / Editor Architecture — v2 (rewrite)

Status: **decision log for a ground-up rewrite, nothing implemented yet.** Supersedes
`ARCHITECTURE.md`/`ROADMAP.md` conceptually — the old documents describe the system as
built through Phase 8C; this document is the result of deciding the original design had
become entangled enough (mid Phase 8) to be worth rebuilding with a more correct starting
shape, fixing naming along the way. `AUDIT.md`'s findings against the old system fed
directly into several decisions below (noted inline where relevant); most of its open
items are resolved here, a few are explicitly still open (see "Deferred" at the end).

Not a frozen spec. Revisit as implementation surfaces constraints this discussion didn't
anticipate.

## Namespaces and migration path

The old `Storage` namespace covered too many distinct concepts at once (entity
persistence, schema/migrations, editor authoring, write-path/undo, permissions) — part of
what made Phase 8 feel entangled. The rewrite splits it into four backend namespaces
nested under one shared parent, **`Persistence\`**, kept purely for filesystem/`src\`-root
tidiness — nesting doesn't reintroduce the old entanglement, since each of the four stays
just as cleanly separated a namespace as it would be at the `src\` root, only its prefix
changes:

- **`Persistence\Entity\`** — the persistence/runtime core: entities table, identity map,
  repositories, row mapping, query builder.
- **`Persistence\Schema\`** — the shape/mutation half: `FieldDescriptor`, prototype
  registry, `SchemaBuilder`/`SchemaSynchronizer`/`SchemaEditor`, migrations,
  rename/retype/reparent, attributes (`#[Table]`, `#[EditorExtensible]`,
  `#[DefaultInstance]`, ...). `EntityRegistrar` lives here too — despite its name, its job
  is registering a native class's *schema*, not runtime entity state.
- **`Persistence\Changeset\`** — the write path, promoted to its own namespace rather than
  a subfolder of `Persistence\Entity\`, since undo and draft are really just two different things done
  with the same object rather than separate subsystems: `Changeset`/`EntityChange`/
  `TempId`/`ChangesetSorter`/`ChangesetFlusher` at the top, **`Persistence\Changeset\Undo\`**
  (`Revision`, `EntityChangeRecord`, conflict detection) and
  **`Persistence\Changeset\Draft\`** (`DraftStore`, `DraftPreview`) nested underneath.
- **`Persistence\Permission\`** — shared by all of the above rather than split across
  them: `Actor`, `SchemaPermission`, `FieldPermission`, `HistoryPermission`,
  `RolePermission`, and any other auth-level class.

**`Editor\`** — the admin-facing authoring surface, consuming the other four rather than
merging into any of them — stays its own top-level namespace, not nested under
`Persistence\`: it's the UI-facing surface built on top of this system, not part of the
persistence system itself, a separate later track per `ROADMAP_V2.md`.

Same pattern `Routing\` and `Core\Error\` already use in this codebase: one class per
file, subdirectories mirror sub-namespaces, `tests/` mirrors `src/` 1:1 folder-for-folder,
plus a `Fixtures/` folder for test-only support classes.

**The old `Storage\` namespace is left untouched during the rewrite, not migrated,
extended, or deleted.** It keeps working exactly as it does today and stays available as
a running reference to consult while the new `Persistence\`/`Editor\` code is built —
deliberately not reused or built on top of, to keep the rewrite a clean-room effort rather
than dragging the old entanglement forward. `Storage\` gets retired once it's no longer
needed for that reference purpose — no fixed point in the roadmap for that, revisit once
`Persistence\` actually covers everything `Storage\` did.

## The goal

Unchanged from the original design: an admin editor in the spirit of Unreal's
UObject/Details-panel system — classes displayed and edited through reflection, PHP
attributes customizing how each property renders. Two supporting pieces, also unchanged
in spirit: content types can be assembled entirely in the editor with no PHP class behind
them, and editor operations are undo-able client-side. This document focuses on the
storage/entity layer underneath both.

## Shape comes from a neutral descriptor

Two sources produce the same `FieldDescriptor[]`: reflecting a hand-crafted PHP class's
attributes, or reading a stored schema an admin authored through the editor. One shared
downstream pipeline (persistence, validation, migration) never needs to know which source
a shape came from.

`FieldDescriptor`: private constructor, named static factories (`scalar()`, `valueObject()`,
`embed()`, `reference()`, `collection()`) — makes invalid combinations (a scalar kind with
a referenced shape set, a collection with no item kind) unrepresentable rather than just
unlikely. Same pattern already used for `Route::structured()`/`Route::simple()` in this
codebase.

## Global entity identity: one shared `entities` table

Every entity, native or editor-created, gets a row in one shared `entities` table sitting
above every prototype's own Class Table Inheritance chain — one root, period, not one per
prototype family:

- `id` — uuid, the identity every chain's own top level is now an FK back to.
  **Re-confirmed** (not just carried over from the original baseline): uuid over an
  autoincrementing PK, deliberately — avoids a single global sequence shared by every
  entity in the system regardless of prototype, and keeps id generation possible without a
  round-trip to the database first.
- `owner` — nullable, self-referencing FK to `entities.id`, `ON DELETE RESTRICT`. Not
  `CASCADE` — see "References and collections" and "Content write path": deletion of an
  Owned subtree is app-mediated, and this constraint is what makes skipping that a hard
  failure instead of a silent one.
- `owner_field` — nullable, disambiguates which Owned relationship put this row here when
  the same shape is owned by more than one relationship.
- `position` — nullable, `-1` for a non-collection Owned relationship, an ordinal
  otherwise (orders an Owned collection).
- `concrete_identifier` — the row's actual type, native class or editor-created
  prototype, resolved through the prototype registry to find its real table(s).

`entities` is chain position 0 for every identifier, standalone or already part of an
inheritance chain — there's no more "chain of one, no join" case. Resolving a row's
concrete type is one indexed lookup (`SELECT concrete_identifier FROM entities WHERE id =
?`), not a per-chain discriminator.

**Class Table Inheritance, arbitrary depth**, native and editor-created levels freely
mixed. No cap needed: growing an existing level (add a nullable column) is already
cheaper than adding a new level, so real depth stays shallow in practice; each level's
join is a cheap single-row parent-PK-to-derived-PK lookup regardless, nothing like an EAV
self-join; and where cost could matter (list views across many rows) is a Query-builder
concern, not a depth-specific one — see "Admin list/filter views" below.

**Cost accepted deliberately**: every read, including a standalone native class with no
declared parent, now joins to `entities` once. Traded for removing the need for any
per-relationship dedicated table (see "References and collections" below) and any
discriminator-per-chain-root mechanism.

## No blobs. Every field is a real column or a real table.

The single biggest change from the old design: there is no JSON-blob storage tier at all,
anywhere. Migrating data inside an opaque blob is the exact kind of problem this rewrite
exists to avoid. Consequences:

- `queryable` stops being a storage-tier switch (there's no second tier to switch into).
  What's left of it is purely an indexing decision — does this already-real column get a
  database index — independent of whether the field exists as a column at all (it
  always does).
- A **collection of scalars, value-object-primitives, or `#[Embed]` items** (non-entity
  items) can no longer live as a JSON array. It gets its own dedicated child table:
  `(ownerId, position, value column(s))`, `CASCADE`-deleted with the owner. This is a
  different, simpler mechanism than an Owned-entity collection below — the items have no
  identity of their own, nothing to look up in `entities` at all. For a scalar or
  value-object item, `value column(s)` is exactly one column; for an Embed item, it's the
  embedded shape's fields flattened into that child table's own columns, same recursive
  flattening and restrictions (no `Reference`/`Collection` at any depth, cycle detection at
  registration) as an Embed field on an ordinary entity row — the only difference is which
  table the flattened columns land on, the owner's own row or this dedicated child table.

**Tradeoff accepted knowingly**: unlimited-depth Embed flattening plus no blob tier means a
deeply-nested shape can produce a wide table — real limits exist (Postgres ~1600
columns/table, MySQL row-size ceilings) that the old design's blob tier partly existed to
avoid. Almost certainly fine for realistic CMS content shapes; revisit only if an actual
schema approaches these limits, not preemptively.

## Entity vs. Value Object vs. Embed

Same DDD test as before, decided per field declaration, never baked into a shape itself:
does it need to be shared/referenced from more than one place, found/queried
independently, have its own lifecycle, or does the business track "the same X" over time?
Yes to any → Entity. No to all → not an Entity — but "not an Entity" now splits cleanly
into two different mechanisms instead of one:

- **Value Object = a custom primitive type**, full stop. Exactly one column, a real
  custom type class doing PHP-value ↔ DB-value conversion (the standard approach — the
  same shape as a Doctrine custom `Type`). Never has its own identity, never doubles as
  an Entity anywhere. `FieldDescriptor::valueObject(name, customTypeClass, ...)`.
- **Embed = reusing an Entity-capable shape at one specific field site**, flattening its
  fields into the owner's own row as real columns (`address.city` → `address_city`),
  recursively through nested embeds and value-object members. The *same* registered
  shape can independently be a full standalone `Entity` (own table, referenced normally)
  somewhere else at the same time — the choice is per field-declaration, never a property
  of the shape.

**An Embed target's field tree is restricted to scalar + value-object + nested-embed
fields only — no `Reference`, no `Collection`, at any depth.** This mirrors Doctrine's
own embeddables exactly: Doctrine's `#[Embeddable]` classes cannot declare
`#[ManyToOne]`/`#[OneToMany]`/`#[ManyToMany]`/`#[OneToOne]` at all — a shape needing
either of those isn't an embeddable, it's an entity. Adopted deliberately rather than
solving a harder problem (an embedded shape has no identity, so a `Collection` field
inside one has no natural owner to attach a child table to). A shape that needs a
reference or a collection must be used as a real `Entity`, never as an `#[Embed]` target.
**Rejected eagerly, at registration time** — same posture as every other structural
violation in this design (Embed-of-Embed cycles, defaulted-instance completeness): a
`Reference` or `Collection` field found anywhere in an Embed target's field tree fails
registration immediately, never a runtime or migration-time surprise.

**Embed-of-Embed cycle detection is required at registration time** (A embeds B embeds A
would otherwise recurse forever generating columns). Same posture as the existing
table-name-collision and defaulted-instance-completeness checks below — fail loudly at
registration, not at migration time.

## References and collections

- **Shared, singular** (`SharedReference`): a real FK column on the referencing side,
  `RESTRICT`.
- **Shared, collection**: a real pivot/join table, many-to-many, with its own `position`
  column — ordered from day one, same as Owned and non-entity collections. The pivot
  carries `position` and nothing else, ever: a relationship that needs richer per-row
  metadata (a note, a date, anything beyond order) isn't a bare many-to-many anymore and
  should be modeled as a real join-entity (an Owned collection of small entities, each
  holding a Shared singular reference to the actual target) instead of growing more
  columns onto the pivot.
- **Owned, singular** (`OwningReference`): no column on the owner's own table. Found via
  `SELECT * FROM entities WHERE owner = ? AND owner_field = ? AND position = -1`, hydrated
  through the owned entity's own repository. Deletion is app-mediated via `entities.owner`
  (see "FK `ON DELETE` policy" below), not a raw database cascade.
- **Owned, collection**: same query without the `position = -1` filter, ordered by
  `position`. An owned collection item lives in its own ordinary table via its own CTI
  chain — fully polymorphic, no per-relationship child table needed, no dedicated
  Owned-child-table mechanism at all. This is the payoff of the shared `entities` table:
  the same reusable shape can be Owned by any number of unrelated relationships through
  the exact same `owner`/`owner_field` columns, no dedicated table per relationship.
- **Non-entity collection** (scalar, value-object, or `#[Embed]` items): the dedicated
  child table described under "No blobs" above — not the `entities` mechanism, since the
  items aren't entities.

**FK `ON DELETE` policy**: `RESTRICT` (strictest) is the default for Shared references —
the app-level topological sort in the write path is the real safe-ordering mechanism; the
constraint is a correctness backstop, not something the normal path expects to hit.
`RESTRICT` for `entities.owner` too, uniformly across every Owned relationship — not
`CASCADE`. Owned deletion is conceptually cascading (an Owned child dies with its owner)
but mechanically app-mediated: deleting an entity that still has Owned descendants is
rejected unless every descendant was already deleted first, each one through the ordinary
write path where it gets its own `EntityChangeRecord` (see "Content write path"). The
alternative, `CASCADE`, would let the database quietly clean up any descendant an app-level
bug missed — silently skipping its log entry along with it, undermining "every touched
entity gets a record" below; `RESTRICT` turns that same bug into a hard failure instead.
`SET NULL` only for a reference that's genuinely optional (no `RequiredValidator`) — that
absence is what the FK column's own nullability derives from, not a separate choice: a
`SET NULL` constraint mechanically requires the column to actually be nullable at the DB
level, so "optional (no `RequiredValidator`)" is the one place that decision is made,
feeding both the column's nullability and the FK policy together. A Shared collection's
pivot table is the one exception to all of the above: both its FK columns are `CASCADE`,
on either side — a pivot row carries no independently-logged content of its own (not an
entity, gets no `EntityChangeRecord`), so deleting either party removing its join rows
along with it loses nothing worth protecting, unlike `entities.owner`.

## Defaulted instances: backfilling a new field or a new parent-level row

Every native class, and every shape ever used as an `#[Embed]` target, must be able to
produce a defaulted instance: a real zero-argument constructor, or a static factory
carrying `#[DefaultInstance]` (needed because reflection has no other way to know which
static method is *the* one). Editor-created fields carry their own explicit default
instead, set through the field-authoring UI.

**Resolution order**: field's own explicit default (editor-created only) → the field's
type's own defaulted instance, resolved recursively → throw. One mechanism, three call
sites: an ordinary new column's backfill, a newly-required parent-level row (reparenting),
and an `#[Embed]`-propagated column (a shape gaining a field backfills every table that
embeds it).

**Fail as early as possible.** For native classes: `EntityRegistrar::register()` walks
every registered class's full field tree, recursively through every `#[Embed]`/
`#[Reference]` target, and requires a defaulted instance for each distinct class found,
before any schema work starts. For editor-created fields: rejected at field-save time if
the referenced type has neither an explicit default nor a defaulted instance.

## Migrations and schema mutation

**Native classes**: developer-triggered, reviewed migration files, Doctrine DBAL
`Schema`/`Comparator` for diffing and DDL generation. A human reviews generated DDL before
it touches production.

**Editor-created subclasses**: safe runtime DDL only, scoped to that subclass's own table,
never the parent's — add a nullable column, drop a column, rename a field, retype a field
(see below). Nothing else, ever; this is enforced by `SchemaEditor` exposing no other
mutation method, not just by policy.

**Rename — no attributes, ever, for either kind.** Passed explicitly at the point the
change is triggered instead of inferred by diffing two snapshots or tracked with
in-code markers: for a native class, the migration-generation invocation takes an explicit
mapping ("since last run: `oldField` → `newField`") as an argument. For editor-created,
the signal is inherent — `SchemaEditor` performs one explicit admin-triggered rename
action, never inferred from a diff. Prototype-level rename works the same way and fixes up
every stored reference to the old identifier: `prototypes.parent`, `entities.concrete_identifier`,
every other prototype's `reference()`/`embed()`/`collection()` pointing at it, and any
`owner_field` values. **Renaming a field on a shape used as an `#[Embed]` target
propagates to every table that embeds it** — same reasoning as new-field backfill below:
every embedding table has its own flattened copy of that column (`address.city` →
`address_city`), so the rename must run against each one, not just the shape's own
declaration.

**Retype is a real `ALTER`, never drop-and-recreate, and always needs an explicit
converter — no attempt to guess how to convert existing data.** For native: the converter
is passed alongside the rename mapping at migration-generation time. For editor-created:
the admin supplies/selects a converter class through `SchemaEditor`. No converter
supplied for a retype that needs one → refuse loudly, same "fail before, not during"
posture as everywhere else in this design. **Retyping a field on an `#[Embed]` target
propagates the same way rename does** — every embedding table runs the same `ALTER` +
converter against its own flattened copy of the column.

**Retargeting a `Reference` field's own type (it used to point at `Category`, now it
should point at `Tag`) is a retype, not a separate mechanism.** Same umbrella as above,
just a converter whose input/output are entities instead of raw values: given the
existing row's currently-referenced entity (loaded through the old FK id), the converter
produces the new-target entity (found or freshly created) and its id is stored in its
place; the FK constraint itself is dropped and recreated against the new target table as
an ordinary part of the same `ALTER`. A converter that can't produce a valid target for
some existing row fails the migration outright, same "no attempt to guess, refuse loudly"
posture as any other retype — there's no new failure mode here, only the general one.

**Changing a collection's item kind is treated identically to a retype** — a real
conversion via an explicit converter run per existing item (e.g. each value-object item
becomes a newly-created entity row), never a silent drop-and-empty.

**Reparenting** (add/change/remove a class's parent) is mechanically uniform for native
and editor-created once every chain already has `entities` as its structural top:
inserting or removing one CTI level, backfilled via the defaulted-instance mechanism
above. No approval gate beyond a warning shown in the editor UI when an admin triggers it
against an already-populated prototype; a developer triggering it from code is assumed to
already know what they're doing.

**`#[EditorExtensible]` revocation, and deleting a prototype with live editor-created
subclasses, are the same case.** Either condition — a native class stops being
extensible, or a prototype (native or editor-created) is deleted outright while it still
has editor-created subclasses — invalidates the parent link of every *direct*
editor-created subclass of it (a subclass further down the chain is unaffected, since its
own parent link was never about the parent above it). Each invalidated direct subclass
falls back to `entities` directly, via the same reparenting mechanism, applied lazily on
the next schema save/fixer run — not immediately. Existing data in the old parent-chain's
tables survives untouched until that fixer runs.

**Prototype/class deletion: allowed to dangle, deliberately, not blocked or cascaded —
because the two contexts that can reference a deleted identifier each already have their
own way of catching it, at the point that actually matters for that context:**

- **A native class referencing a deleted native class** fails loudly at registration
  time, for free — `EntityRegistrar::register()`'s existing full-field-tree walk (already
  needed for defaulted-instance discovery) reflects on every `#[Reference]`/`#[Embed]`
  target it finds; if that target class no longer exists, the reflection call itself
  throws before the app ever serves a request. No new mechanism needed, this is a
  pre-existing check catching a new case.
- **Deleting a native class that other native code still references** goes through the
  same reviewed-migration tool that already takes explicit rename/retype mappings — a
  deletion is declared there too, and the tool surfaces every remaining reference to the
  deleted identifier as part of that same reviewed diff, instead of only failing later at
  registration.
- **Anything referencing a deleted identifier from an editor-created schema** — reference,
  embed, or collection target, native or editor-created, plus the same "parent no longer
  valid" case above — can't rely on a PHP-level throw, since editor schemas are data, not
  compiled code. Two enforcement points instead: `SchemaEditor` blocks *saving* a schema
  with a broken field until it's fixed or removed, and a separate auditing tool
  proactively scans every editor-created schema for exactly this class of breakage
  (dangling reference/embed/collection target, parent revoked, parent deleted), so an
  admin can find and fix these without first having to stumble into each broken schema
  individually.

**No schema-level undo/redo, deliberately.** Schema mutations are immediate and permanent
from the write path's perspective — recoverable only through a full database
backup/restore, the same posture native migrations already have (a bad migration is
already "roll back the deploy," not "invert one DDL statement"). This retires the
two-log (`SchemaUndoLog` + content `UndoLog`) bridging design the old system was building
toward — there's only one undo log now, and it's content-only (see below).

## Content write path

Explicit `Changeset`, not auto-diffing — reuses the same command stream the undo system
needs anyway, avoiding a classic Unit-of-Work's automatic dirty-checking machinery
entirely. A changeset supports a temporary/placeholder id for an entity created in the
same flush.

**Full topological sort**, not a bounded heuristic — CMS content nests arbitrarily deep,
and a hand-maintained list of "supported nesting patterns" doesn't scale. Dependency edges
are derived automatically from the changeset's own reference structure — an Owned
relationship's `owner` pointer is just another edge in that same structure, not a special
case. Read in opposite directions for inserts vs. deletes (insert the referenced entity
first so its id exists; delete it last). **Cycles are rejected outright** with a clear
error naming the cycle — no deferred-edge escape hatch until an actual case demonstrates
it's needed. This covers an ownership cycle (`A.owner = B`, `B.owner = A`) for free: it's
the same reference-graph cycle check, not a mechanism needing its own carve-out.

**Deleting an owner auto-expands to everything it transitively owns.** Before the
topological sort runs, a delete targeting entity X gets every entity in X's owned subtree
(any depth) appended as its own explicit delete operation in the same changeset — reusing
the same `owner`/`owner_field` lookup `Repository` already needs for ordinary Owned
hydration, not a new query. This is what makes `entities.owner`'s `RESTRICT` (see
"References and collections") never actually fire in normal operation: by the time the
changeset flushes, every descendant is already gone through the ordinary path, deleted and
logged like any other changeset member. This expansion is `Persistence\Changeset\`'s job,
not `Persistence\Entity\Repository::delete()`'s — `Repository::delete()` stays a
single-entity, CTI-chain-aware primitive; only `Repository`'s existing owned-descendant
read is reused, not duplicated.

**Concurrent-write protection**: an `expectedOperationId` receipt, reject-by-default with
an explicit override to retry. For an entity with Owned descendants, the id it must match
is the latest operation touching *it or anything it transitively owns* — recursive over
the owned subtree, computed from the same lookup the expansion above uses — not just its
own direct changes. Deliberately narrow: this applies to Owned only (an Owned child has no
life apart from its owner, so a change anywhere underneath is a change to the owner as far
as a concurrent caller is concerned) and never to Shared (a Shared entity has independent
life by definition; its operation id never bubbles to whatever references it).
**This redefinition is scoped to write-time concurrency protection only** — it does not
change the undo conflict check (still per-entity, per-field, unbubbled; see "Content undo,
draft, and revision history"), `EntityChangeRecord` logging (still one independent record
per touched entity, Owned or Shared), or `FieldPermission` (still per-field, independent
regardless of Owned/Shared). **Accepted tradeoff**: the whole owned subtree becomes one
serialization unit — two unrelated concurrent edits to two different Owned relationships
on the same root (e.g. a Product's `MediaAsset` gallery and its `Pricing` embed) can
spuriously conflict with each other, even though neither touches what the other changed.

## Content undo, draft, and revision history

Content-only now (schema mutations are excluded per above).

**Structure adopts Hibernate Envers's shape (the grouping), not its storage (full
snapshots).** One lightweight `Revision` record per flush — whatever triggered it: an
ordinary publish, a Ctrl+Z undo, or a revision-history restore — holding just metadata (a
monotonic sequence position, actor, kind), no diff data of its own. Every entity that
flush actually touched gets its own independent `EntityChangeRecord` (before/after per
changed field, not a full snapshot — kept as a diff, unlike Envers's own full-row audit
tables, for the storage-efficiency reasons already decided), FK'd back to that shared
`Revision` — **no carve-out for Owned entities.** The old design's "Owned folds into the
owner's own diff, no independent record" rule is retracted: it made sense when an Owned
row lived in a per-relationship child table with no identity of its own, but an Owned
entity now has a full `entities` row exactly like a Shared one, so nothing structurally
distinguishes it in the undo/logging path anymore. One rule for every touched entity,
Owned or Shared, is also what lets the per-touched-entity conflict check in undo (below)
cover Owned for free, with no ownership-based special case. (This is specific to
undo/logging — write-time concurrency protection treats Owned differently, on purpose;
see "Content write path".) This answers both "what did this one database transaction cause, across
everything it touched" (query by `Revision` id) and "what's this one entity's own
history" (query that entity's own `EntityChangeRecord` chain) without either query
fighting the other's retention needs.

**No automatic pruning, anywhere, ever — manual only, and gated by a dedicated
`Persistence\Permission\HistoryPermission`** — a narrow capability check via the same `Actor::hasRole()`
`SchemaPermission`/`FieldPermission` are already built on, kept as its own permission
rather than folded into `SchemaPermission`: pruning destroys content history, it isn't a
schema mutation, and conflating the two would make `SchemaPermission` mean two different
things. This is real historical data, not
disposable cache; deciding what to discard is a deliberate, human-triggered action (by
age, by entity, by prototype, whatever an admin judges appropriate at the time), never a
background policy. This is also what makes atomic multi-entity undo viable again: a
`Revision`'s `EntityChangeRecord`s only disappear when a human explicitly prunes them, not
as a side effect of some other entity's unrelated edit frequency — the "independent
per-entity retention clocks diverge and fragment a shared operation" problem earlier
turns got stuck on doesn't arise from ordinary operation anymore. It can still happen if
an admin manually prunes one touched entity's old history but not another's — accepted as
a rare, deliberate, admin-caused edge case (see the refusal behavior below), not a routine
one to design around.

**A `Revision` left with zero `EntityChangeRecord`s after pruning is left as an empty
husk, never auto-deleted.** Deleting it automatically the moment its last child disappears
would itself be a form of automatic pruning — a side effect of a different, unrelated
decision — which is exactly what's ruled out above. The same manual pruning tool can also
target a `Revision` row directly, empty or not, as its own explicit action; pruning stays a
deliberate decision about whatever was actually selected, never an automatic consequence of
pruning something else.

- **Draft**: a persisted-but-unflushed changeset plus an in-memory apply/preview function
  — no duplicate-row scheme. Publishing is flushing the exact same changeset through the
  exact same write path, producing a `Revision` exactly like any other flush. **Not
  per-user the way undo now is**: one pending draft per entity, shared — a second editor
  opening it takes over or hits a conflict warning (see "Explicitly out of scope" for the
  collaboration boundary this implies), deliberately unlike the `Revision`-ownership check
  undo gets below. A draft has no owner to check because there's no second party's own
  operation it could be mistaken for; undo's check exists specifically to stop one user's
  Ctrl+Z from reverting a *different* user's already-flushed `Revision`, a scenario a
  shared, not-yet-flushed draft doesn't have.
- **Ctrl+Z can undo a whole `Revision`, including one that touched multiple entities.**
  Undoing `Revision` R: gather every `EntityChangeRecord` under it; for *each* entity it
  touched, independently check that entity's own subsequent chain for anything touching
  the same field since (the same per-entity conflict check as before, just run once per
  touched entity instead of once total). If every touched entity's record still exists and
  passes its own check, apply the whole inverse atomically (the same topological-sort
  changeset machinery already built for ordinary writes), producing a **new** `Revision`
  recording the undo — append-only, consistent with "redo is just undo-the-undo." **If any
  touched entity's record is missing (manually pruned) or fails its own conflict check,
  the whole undo is refused outright, naming exactly what's blocking it — never a silent
  partial revert.**
- **Redo** needs no new mechanism: undoing is itself a logged `Revision`, redoing is
  undoing the undo.
- **Undo authorization, reversing the v1 stance, attaches at the `Revision`.** The old
  design concluded no dedicated server-side check was needed beyond ordinary
  `FieldPermission` write-gating, reasoning that a client only ever holds its own
  operation ids. **Decided differently here**: that leaves a real loophole — nothing
  stops a client calling the undo endpoint directly with a `Revision` id it didn't itself
  produce, and if the caller happens to have ordinary write permission on the affected
  field(s), the server would undo someone else's `Revision` passing as the caller's own
  Ctrl+Z. Undoing a specific `Revision` now requires a real check, behind
  `Persistence\Permission\`, that the `Revision` actually belongs to the requesting user/session, checked once,
  before the inverse changeset is even computed — in addition to, not instead of, the
  ordinary write-gate. Revision-history restore (the separate, deliberate surface below)
  is explicitly exempt from this check — reaching into anyone's past state there is the
  entire point of that surface.
- **Client-side command stack**: `LocalCommand` (synchronous, in-memory, no server
  round-trip) vs. `RemoteCommand` (references a specific `Revision` id, needs a visible
  pending state, never optimistically reverts before server confirmation) — one
  independent stack per open editing tab, not per session. A single visible user action
  that touched multiple entities in one flush still pushes exactly one `RemoteCommand`,
  referencing that one `Revision` — Ctrl+Z reverts everything it grouped, per the
  mechanism above.
- **Revision-history restore** (the separate, deliberate "view/restore any past state" UI
  surface, distinct from Ctrl+Z): reads one entity's own `EntityChangeRecord` chain and
  restores it to a chosen past state by flushing a new changeset — itself producing a new
  `Revision`, same as everything else. **By default cannot reach back past any schema
  change to that prototype** — after a schema change, prior operations on that prototype
  are assumed invalid until proven otherwise. Worth refining later by bookkeeping exactly
  what a given schema operation touched and only blocking restore for the touched part —
  reusing the same never/conditional/always classification already used for
  schema-mutation kinds (purely-additive changes never block; a single-field change like
  drop/rename/retype blocks only that field; reparent/delete block more broadly) — but the
  conservative default is what's decided now; the refinement is not.

## Identity Map + Repository + lazy loading

Standard pattern (Fowler's PoEAA / Doctrine's `EntityManager`+`UnitOfWork`): resolving the
same entity id twice within a request yields the same PHP instance. Lazy loading (when a
query runs) and the identity map (whether a second resolution reuses the first) are
complementary — a lazy proxy consults the identity map before running a fresh query.
Scope is per-request, built fresh and discarded at request end; a cross-request cache is a
separate, later optimization layered on top, not conflated with this correctness
guarantee. References/collections must not eagerly hydrate on an entity's own load — only
on actual access.

## Validation

`FieldValidator` — a composable list per field (required, length, format, ...), never a
growing pile of `FieldDescriptor` flags. `PrototypeValidator` — a separate entity-level
interface for cross-field rules (end date after start date), evaluated against the whole
candidate state; native classes get arbitrary logic, editor-created prototypes pick from a
closed menu only. Server-side validation is mandatory regardless of what the client
already checked.

Client-side pre-validation splits into three tiers behind one clean interface
(`describe()` emits `{type, ...params}`, never needs to know which tier applies): native
HTML5 constraint attributes, a small shared registry of named JS algorithms (IBAN, credit
card checksum, ...) for things the browser doesn't validate natively, and genuinely bespoke
logic that stays server-round-trip-only.

## Admin list/filter views

A query-builder resolving each field to its real column *and* which chain-level table
holds it: `Query::for($identifier)->where(...)->orderBy(...)->after($cursor)->limit($n)`.
**Cursor/keyset pagination from day one**, never `OFFSET`/`LIMIT` — a standard keyset
cursor (sort-column value + primary-key tiebreaker) is straightforward once every sortable
field is a real, typed column. One real multi-table `JOIN` across the whole chain with
per-level column aliasing replaces a one-query-per-level approach for a list of rows.
`count()` reuses the same `WHERE`, independent of pagination.

**Polymorphic hydration of a `Query`'s own result rows is opt-in, never the default** — a
row that's physically a more specific subtype only gets fully hydrated as that subtype
when the caller explicitly asks (batched by distinct `concrete_identifier` present on the
page), keeping cost predictable and visible at the call site rather than an implicit,
unpredictable per-row tax.

Deliberately out of scope: filtering an outer list by something inside a collection
sub-structure (`EXISTS`-style semantics) — meaningfully harder, rarer need.

**`Query`'s hydrated results go through the same read-time `FieldPermission` filter as
`Repository::find()`/`lazyFind()`** — one filtering step, not a separate one `Query` would
need to reimplement, applied per field exactly as declared regardless of which path
resolved the row.

**`where()`/`orderBy()` on a field the actor can't read is rejected, the same as if that
field didn't exist** — not just stripped from the hydrated result. Allowing it through
would leak the field's values via a side channel (sort order, or which rows pass a filter)
even though `FieldPermission` scrubs the field itself from what's returned; a cursor
carries the sort field's own value, which would leak it just by existing. Same posture as
every other read-time `FieldPermission` check, just applied to the query's inputs instead
of only its output rows.

## Permissions

`SchemaPermission` gates every schema mutation from the first commit that makes runtime
mutation possible at all. `FieldPermission` enforced at three points: read-time filtering,
a mandatory server-side write gate (before validation), and a client-side UX-only gate.
`HistoryPermission` gates manual pruning (see "Content undo, draft, and revision history")
— its own narrow permission, not folded into `SchemaPermission`, since pruning destroys
content history rather than mutating schema. A minimal `Actor` interface
(`hasRole(string): bool`) backs all three.

**`FieldPermission` is a per-`FieldDescriptor` declaration, not a per-table or per-entity
one** — every field, at whatever level of a shape's own tree, carries its own permission
from the point it's declared, resolved the same way `fieldsOf()` already resolves any
other per-field property (type, validators). This is what makes granularity a non-issue
for an `#[Embed]` field specifically: a sub-field like `pricing.wholesaleCost` keeps its
own independently-declared permission (distinct from `pricing.listPrice`'s) whether
`PricingInfo` ends up flattened into the owner's row via Embed or given its own table as a
standalone Entity — Embed only changes where the field's data physically lives, never a
re-declaration of its permission at some coarser grain. An Owned or Shared reference's
nested entity needs no special-casing either, for the same underlying reason plus one more
layer: the relationship field itself (e.g. `Product.currentPricing`) carries its own
permission like any field, and the referenced entity's own fields carry theirs
independently — two already-ordinary checks composed, not a new mechanism.

## Media/file fields

`MediaAsset` is the worked example exercising both Owned (inline upload, tied to its
owner for deletion and concurrency purposes — see "Content write path" — though still
logged with its own independent `EntityChangeRecord` history like any entity) and Shared
(media library, referenced from wherever it's used) at once. Removing a `MediaAsset`
reference is immediate at the
database level; reclaiming the physical file bytes is deferred, piggybacking on the same
manual pruning tool described above, not an automatic sweep — a file's bytes stay on disk
as long as anything not yet manually pruned (an `EntityChangeRecord`, a live reference)
could still reference them, and reclaiming them is itself part of that same deliberate,
human-triggered action.

## Deferred — not resolved in this document

- **Flipping an existing relationship between Owned and Shared.** Still a real structural
  migration (an Owned child's own row has no reference column to promote, a Shared row's
  FK doesn't carry the owner/owner_field/position an Owned row needs) — unlike its sibling
  problems (retype, collection item-kind change, queryable-flip), which are all resolved
  above. Not designed here.
- **Fine-grained revision-history reachability across a schema change.** Conservative
  default (blocked) is decided; the touched-field-bookkeeping refinement is not.
- **Exact attribute/API surface** for `#[DefaultInstance]`, converter classes, rename/retype
  invocation parameters — this document is architecture, not the implementation API.
- **Naming conventions pass.** Namespace-level naming is decided (`Persistence\Entity\`/
  `Persistence\Schema\`/`Editor\`, see above), but class/method-level terminology
  (`Owned`/`Shared`, `Embed`,
  `FieldDescriptor`, ...) is still carried over from the old design unchanged and remains
  a candidate for renaming later.

## Explicitly out of scope (unchanged from the old design)

Full-text/cross-content-type search (a flagged shelf item, build only once a real need
shows up). Real-time concurrent multi-editor collaboration on the same entity (one pending
draft per entity; a second opener takes over or hits a conflict warning). EAV — actively
rejected after confirming it's what ACF/WordPress actually do under the hood, and
specifically the problem this whole design exists to avoid.
