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
persistence, schema/migrations, editor authoring) — part of what made Phase 8 feel
entangled. The rewrite splits it into three: **`Entity\`** for the persistence/runtime
half (entities table, identity map, repositories, changesets, query builder, undo/draft),
**`Schema\`** for the shape/mutation half (`FieldDescriptor`, prototype registry,
migrations, rename/retype/reparent), and **`Editor\`** for the admin-facing authoring
surface sitting on top of both, consuming rather than merging into either — the same
pattern `Routing\` and `Core\Error\` already use in this codebase.

**The old `Storage\` namespace is left untouched during the rewrite, not migrated,
extended, or deleted.** It keeps working exactly as it does today and stays available as
a running reference to consult while the new `Entity\`/`Schema\`/`Editor\` code is built —
deliberately not reused or built on top of, to keep the rewrite a clean-room effort rather
than dragging the old entanglement forward. `Storage\` gets retired (or its name reclaimed
for something else) only once it's no longer needed for that reference purpose — no fixed
point in the roadmap for that, revisit once the new namespaces actually cover everything
`Storage\` did.

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
- `owner` — nullable, self-referencing FK to `entities.id`, `ON DELETE CASCADE`.
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
- A **collection of scalars or value-object-primitives** (non-entity items) can no longer
  live as a JSON array. It gets its own dedicated child table: `(ownerId, position,
  value column(s))`, `CASCADE`-deleted with the owner. This is a different, simpler
  mechanism than an Owned-entity collection below — the items have no identity of their
  own, nothing to look up in `entities` at all.

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

**Embed-of-Embed cycle detection is required at registration time** (A embeds B embeds A
would otherwise recurse forever generating columns). Same posture as the existing
table-name-collision and defaulted-instance-completeness checks below — fail loudly at
registration, not at migration time.

## References and collections

- **Shared, singular** (`SharedReference`): a real FK column on the referencing side,
  `RESTRICT`.
- **Shared, collection**: a real pivot/join table, many-to-many.
- **Owned, singular** (`OwningReference`): no column on the owner's own table. Found via
  `SELECT * FROM entities WHERE owner = ? AND owner_field = ? AND position = -1`, hydrated
  through the owned entity's own repository. `CASCADE` via `entities.owner`.
- **Owned, collection**: same query without the `position = -1` filter, ordered by
  `position`. An owned collection item lives in its own ordinary table via its own CTI
  chain — fully polymorphic, no per-relationship child table needed, no dedicated
  Owned-child-table mechanism at all. This is the payoff of the shared `entities` table:
  the same reusable shape can be Owned by any number of unrelated relationships through
  the exact same `owner`/`owner_field` columns, no dedicated table per relationship.
- **Non-entity collection** (scalar or value-object items): the dedicated child table
  described under "No blobs" above — not the `entities` mechanism, since the items
  aren't entities.

**FK `ON DELETE` policy**: `RESTRICT` (strictest) is the default for Shared references —
the app-level topological sort in the write path is the real safe-ordering mechanism; the
constraint is a correctness backstop, not something the normal path expects to hit.
`CASCADE` for every Owned relationship, uniformly via `entities.owner`. `SET NULL` only
for a reference that's genuinely optional (no `RequiredValidator`).

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
`owner_field` values.

**Retype is a real `ALTER`, never drop-and-recreate, and always needs an explicit
converter — no attempt to guess how to convert existing data.** For native: the converter
is passed alongside the rename mapping at migration-generation time. For editor-created:
the admin supplies/selects a converter class through `SchemaEditor`. No converter
supplied for a retype that needs one → refuse loudly, same "fail before, not during"
posture as everywhere else in this design.

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
are derived automatically from the changeset's own reference structure. Read in opposite
directions for inserts vs. deletes (insert the referenced entity first so its id exists;
delete it last). **Cycles are rejected outright** with a clear error naming the cycle — no
deferred-edge escape hatch until an actual case demonstrates it's needed.

**Concurrent-write protection**: an `expectedOperationId` receipt, reject-by-default with
an explicit override to retry.

## Content undo, draft, and revision history

Content-only now (schema mutations are excluded per above). Three still-distinct concerns:

- **Draft**: a persisted-but-unflushed changeset plus an in-memory apply/preview function
  — no duplicate-row scheme. Publishing is flushing the exact same changeset through the
  exact same write path.
- **Undo-log**: every flushed `ChangesetOperation` logged as a diff (before/after per
  changed field, not a full snapshot), count-based retention **per Shared entity** (an
  Owned relationship has no independent entry — its changes fold into its owner's own
  diff). Redo needs no new mechanism: undoing is itself a logged operation, redoing is
  undoing the undo.
- **Conflict detection on undo**: a later operation touching the same `(entity, field)`
  pair blocks the undo via an explicit check; a later operation creating a new dependency
  on an entity the undo would delete is already caught for free by `RESTRICT`.
- **Client-side command stack**: `LocalCommand` (synchronous, in-memory, no server
  round-trip) vs. `RemoteCommand` (references a specific `operationId`, needs a visible
  pending state, never optimistically reverts before server confirmation) — one
  independent stack per open editing tab, not per session.
- **Revision-history restore** (the separate, deliberate "view/restore any past state" UI
  surface, distinct from Ctrl+Z): **by default cannot reach back past any schema change to
  that prototype** — after a schema change, prior operations on that prototype are assumed
  invalid until proven otherwise. Worth refining later by bookkeeping exactly what a given
  schema operation touched and only blocking restore for the touched part — reusing the
  same never/conditional/always classification already used for schema-mutation kinds
  (purely-additive changes never block; a single-field change like drop/rename/retype
  blocks only that field; reparent/delete block more broadly) — but the conservative
  default is what's decided now; the refinement is not.

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

## Permissions

`SchemaPermission` gates every schema mutation from the first commit that makes runtime
mutation possible at all. `FieldPermission` enforced at three points: read-time filtering,
a mandatory server-side write gate (before validation), and a client-side UX-only gate. A
minimal `Actor` interface (`hasRole(string): bool`) backs both.

## Media/file fields

`MediaAsset` is the worked example exercising both Owned (inline upload, no life apart
from its one field) and Shared (media library, referenced from wherever it's used, own
independent retention window) at once. Removing a `MediaAsset` reference is immediate at
the database level; reclaiming the physical file bytes is deferred, piggybacking on the
same content-retention-window pruning sweep — a file's bytes stay on disk as long as
anything still inside the retention window (an undo-log entry, a revision, current live
state) could reference them.

## Deferred — not resolved in this document

- **Fine-grained revision-history reachability across a schema change.** Conservative
  default (blocked) is decided; the touched-field-bookkeeping refinement is not.
- **Exact attribute/API surface** for `#[DefaultInstance]`, converter classes, rename/retype
  invocation parameters — this document is architecture, not the implementation API.
- **Naming conventions pass.** Namespace-level naming is decided (`Entity\`/`Schema\`/
  `Editor\`, see above), but class/method-level terminology (`Owned`/`Shared`, `Embed`,
  `FieldDescriptor`, ...) is still carried over from the old design unchanged and remains
  a candidate for renaming later.

## Explicitly out of scope (unchanged from the old design)

Full-text/cross-content-type search (a flagged shelf item, build only once a real need
shows up). Real-time concurrent multi-editor collaboration on the same entity (one pending
draft per entity; a second opener takes over or hits a conflict warning). EAV — actively
rejected after confirming it's what ACF/WordPress actually do under the hood, and
specifically the problem this whole design exists to avoid.
