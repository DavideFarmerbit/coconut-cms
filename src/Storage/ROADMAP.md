# Storage / Editor — Development Roadmap

Phased build plan for everything decided in `ARCHITECTURE.md`. Each phase ends in
something independently testable, not a half-built layer — dependency-ordered, not
UI-first, since the architecture doc only ever designed the backend/storage engine, not
an admin frontend. Section names below (`"Like this"`) refer to headings in
`ARCHITECTURE.md`; build against that document, not a re-explanation of it here.

Scope note: this roadmap covers the Storage/Editor subsystem only. The Routing and
Core/Error subsystems (`src/Routing/`, `src/Core/Error/`) are already implemented and
tested, a separate, already-completed track.

## Phase 1 — Core entity persistence

**Goal**: one native PHP entity class round-trips through real storage. No entity
references yet — scalar and embedded-value-object fields only, one table per prototype.

- `FieldDescriptor` / `FieldKind` ("Shape comes from a neutral descriptor")
- Native-class reflection extraction (the `ofClass()` half of the two-source principle;
  `ofClosure()`-equivalent for editor-assembled shapes waits until Phase 5)
- Real columns for `queryable` fields, JSON blob for the rest ("Storage")
- Doctrine DBAL `Schema`/`Comparator`-based migrations for native classes ("Schema
  evolution")
- Identity Map + Repository (no lazy relation loading yet — nothing to lazily load)
- `FieldValidator` strategy interface, a couple of default validators
- Uniqueness: `unique` flag, real `UNIQUE` constraint, friendly pre-check
  ("Uniqueness validation")

**Not yet**: entity references/collections, inheritance, undo, draft, permissions,
admin-created schema, any UI.

**Done when**: a hand-written native class with a mix of queryable and blob-only
scalar fields can be created, read, updated, and deleted through the Repository, backed
by a migration-generated table, with a validator and a unique field both enforced.

## Phase 2 — Relationships & inheritance

**Goal**: entity-to-entity references and collections work, both flavors, plus
prototype extension via inheritance.

- Entity vs. Value Object test applied for real ("Entity vs. Value Object")
- Shared references/collections: FK on the referencing side, join table for
  many-to-many, `RESTRICT` ("Join tables", FK `ON DELETE` policy)
- Owned references/collections: back-pointer FK on the owned side, `CASCADE`, a
  dedicated per-relationship Class-Table-Inheritance-derived table for every Owned
  relationship, no exceptions ("Ownership: Owned vs. Shared")
- Class Table Inheritance for native prototype extension ("Entity prototypes" — native
  side only; editor-created subclassing waits for Phase 5)
- `MediaAsset` as the concrete worked example of both Owned (inline upload) and Shared
  (media library) usage ("Media/file fields")

**Not yet**: undo, admin-created prototypes, permissions.

**Done when**: a native class can reference another (Shared, `RESTRICT`-protected) and
own a collection of exclusively-owned child rows (Owned, `CASCADE`, its own derived
table) at the same time, and a base/derived native inheritance pair round-trips through
the CTI join correctly.

## Phase 3 — Changeset write path & universal undo-log

**Goal**: every write goes through an explicit, loggable changeset — no direct property
mutation — and every flush is recoverable. Exercised via API/tests; no client UI yet.

- `Changeset` / `EntityChange` / `TempId`, replacing direct mutation ("Exact changeset
  shape", "Write path")
- Topological sort resolving `TempId` dependency edges for atomic multi-entity writes
- `ChangesetOperation` / `SchemaOperation` logging, count-based retention **per Shared
  entity** ("The universal undo-log")
- Concurrent-write protection: `expectedOperationId` receipt, reject-by-default with
  explicit override ("Concurrent-write protection on an ordinary publish")
- Server-side half of undo: conflict detection across every entity a changeset spanned,
  `RESTRICT` covering the referential-conflict case for free ("Undo scope and conflict
  detection")

**Not yet**: client-side undo stack, draft, admin-created schema.

**Done when**: a multi-entity changeset (e.g. create-and-attach across a Shared and an
Owned relationship) flushes atomically, produces a correct inverse, and a conflicting
concurrent write is rejected with the receipt mechanism, all provable through tests
without any UI.

## Phase 4 — Draft & client-side undo

**Goal**: edits can be staged before publishing, and Ctrl+Z works correctly once a real
editor starts driving multiple concurrent editing contexts.

- Draft: persisted-but-unflushed changeset + in-memory apply/preview, reusing the
  Phase 3 write path wholesale ("Draft: a persisted-but-unflushed changeset")
- `LocalCommand` / `RemoteCommand` client-side stack — **one independent stack per open
  editing tab, not one per session** ("Client-side command stack")
- Ctrl+Z (per-tab, auto-blocking on conflict) vs. deliberate revision-history restore
  (confirm-with-warning) — same underlying log, different UI surfaces and different
  conflict-refusal posture ("Undo scope and conflict detection")
- Same-entity-opened-twice collapses to the existing "second opener" rule regardless of
  whether it's two tabs, two browsers, or two actors — no new mechanism, just confirm it
  in practice

**Not yet**: admin-created schema, permissions enforcement.

**Done when**: a draft can be built, previewed, and published through the exact same
write path as Phase 3; two independent editing contexts (simulated, doesn't require a
finished tab UI) each undo only their own history.

## Phase 5 — Admin-authored schema

**Goal**: admins can create new prototypes and grow/shrink editor-created subclasses at
runtime, safely, gated from the start.

- `EditorExtensible` attribute, editor-created subclasses via Class Table Inheritance,
  arbitrary chain depth ("Entity prototypes")
- Safe-DDL-only mutation: add nullable column, drop column — nothing else — on an
  editor-created subclass's own table only, never the parent's
- Dropping a column logged as a `SchemaOperation`, pre-drop snapshot for undo (reuses
  Phase 3's undo-log wholesale)
- Schema changes are never draftable — DDL runs immediately, outside the flush
  transaction ("Schema changes are never draftable")
- `SchemaPermission` gating every one of the above from the first commit that makes
  runtime schema mutation possible, not bolted on after ("Schema-level authorization")
- A minimal `Actor` interface (`hasRole(string): bool`) and a `RolePermission`
  implementing `SchemaPermission` — `ARCHITECTURE.md` references `Actor` throughout but
  never actually defines it; this phase is where a shape for it first becomes load-bearing

**Scoping decision (2026-09-24, confirmed before starting the phase)**: this phase
covers *schema mutation only* — creating a prototype, adding/dropping a column,
permission-gated, undoable. It deliberately does **not** wire editor-created prototypes
into `Repository`/`ChangesetFlusher` for reading/writing instances. Every prototype so
far has been a native PHP class, reflected on and hydrated via `newInstanceArgs()`; an
editor-created prototype has no class to reflect on or instantiate, so representing an
*instance* of one needs a new, generic value-holder (something like a `DynamicEntity`)
threaded through `Repository`, `RowMapper`, `ChangesetFlusher`, and `EntityManager` —
a genuinely separate, sizable piece of work from safely mutating schema, deferred
rather than folded in here. Likely lands naturally alongside Phase 7 (the first phase
that needs to actually *browse* editor-created content), or as its own follow-up phase.

**Not yet**: field-level permission enforcement (Phase 6), admin browsing UI (Phase 7),
Repository/ChangesetFlusher support for editor-created prototype instances (see scoping
decision above).

**Done when**: an admin without `SchemaPermission` cannot create a subclass or alter one
they don't have write access to; one who does can add/drop a column on their own
editor-created subclass, safely, undoably, with the parent's table never touched. This
is about the schema existing and being safely mutable, not about reading or writing
content through it yet.

## Phase 6 — Field-level permissions

**Goal**: `FieldPermission` enforced everywhere a field's value is read or written.

- `FieldPermission` strategy interface, `RolePermission` default ("Field-level
  permissions")
- Three enforcement points: read-time filtering, mandatory server-side write gate
  (before validation), client-side UX-only gate
- Confirm the "generalizes to undo/revision-restore for free" claim in practice, not
  just on paper

Note: this only depends on Phase 1 (a field and an actor), not on Phases 2–5 — it's
grouped here for cohesion with `SchemaPermission`, but could move earlier if a real
need for field-level gating shows up before Phase 6's slot.

**Done when**: an actor without read permission for a field never sees it in a hydrated
entity; an actor without write permission has a changeset touching that field rejected
server-side regardless of what the client sent.

## Phase 6.1 — Audit fixes: lazy loading, cross-field validation, delete ordering

**Goal**: close three gaps between what `ARCHITECTURE.md` decided and what Phases 1-6
actually built, surfaced by an explicit audit (2026-09-24) rather than caught
incrementally. Unlike the Phase 5 `DynamicEntity` deferral, these were never flagged as
deliberate scoping calls at the time, worth a dedicated pass rather than folding
silently into whatever phase touches that code next.

- **Lazy loading.** `Repository::readReference()`/`readCollection()` currently resolve
  eagerly on every `find()` — loading a Product hydrates every Tag it has immediately.
  Needs an actual lazy mechanism (a proxy, or an explicit deferred-fetch wrapper) so
  touching an entity never eagerly hydrates its Shared references/collections, the
  concern that started this entire design in the first place
  ("Identity Map + Repository + lazy loading")
- **`PrototypeValidator`.** A new interface at the entity level for cross-field rules
  (end date after start date), evaluated against the whole hydrated candidate state,
  reusing `DraftPreview`'s apply-in-memory function per the doc, enforced alongside
  `FieldValidator` in the write path. Same native-arbitrary-logic vs.
  editor-created-closed-menu asymmetry used everywhere else admin-authored schema is
  more restricted than native code ("Validation")
- **Delete-vs-delete ordering.** `ChangesetSorter` only derives dependency edges from
  `TempId` scanning, never from live reference data, so a changeset deleting a
  referencer and what it references in the wrong order relies entirely on the
  `RESTRICT` rollback backstop rather than being silently reordered the way inserts
  already are ("Write path")

**Done when**: loading an entity with Shared references/collections doesn't hydrate any
of them until actually touched; a cross-field rule rejects an invalid changeset before
flush, for both a native class's arbitrary rule and an editor-created prototype picking
from a closed menu; a changeset deleting two reference-linked entities in the wrong
order succeeds by being reordered, not by raising (and relying on) a constraint
violation.

## Phase 6.2 — Native entity registration: single setup path, declared table names

**Goal**: close a real gap found by design discussion (2026-09-25), not an audit: wiring
up a native class today is three independent, hand-coordinated steps (build the right
`Table` via `SchemaBuilder::tableFor()`/`tablesForChain()`, depending on whether the
class is standalone or part of an inheritance chain; sync it; separately list
`class => tableName` in `EntityManager`'s `$tables` map by hand) with nothing enforcing
the same table name is used in both places. This phase collapses that into one function
and lets a native class declare its own table name the same way it declares everything
else about its shape: an attribute, reflected, with a derived fallback, rather than a
manually maintained array.

- `#[Table(string $name)]`, an optional class-level attribute (same placement as
  `#[EditorExtensible]`) naming a class's table explicitly.
- A shared derivation function, the one piece Phase 6.3's rename operation also reuses:
  reads `#[Table]` if present, else lowercases
  `(new ReflectionClass($class))->getShortName()`. Deliberately the short name, not the
  fully-qualified one: deriving from the FQCN would make moving a class to a different
  namespace, a pure code-organization change, silently force a table rename too, exactly
  the kind of accidental-classification event the native-rename design is trying to
  avoid. Sanitization matches what `SchemaEditor::createPrototype()` already does for
  editor-created prototypes.
- Collision guard: if two registered classes derive to the same table name without
  either declaring an explicit `#[Table]`, fail loudly at registration time naming both
  classes, rather than silently aliasing one onto the other. A real risk only once table
  names are derived automatically instead of hand-typed into a visibly-shared array.
- One registration entry point taking a list of native classes: walks each one's chain
  via `PrototypeShape::chainOfClass()` (already exists), picks `SchemaBuilder::tableFor()`
  (standalone) or `tablesForChain()` (chain of 2+) accordingly, resolves every level's
  table name through the shared derivation function above, runs
  `SchemaSynchronizer::syncAll()`, and constructs `EntityManager` from that exact same
  resolved map, one source of truth, so the DBAL side and `EntityManager`'s map can never
  drift apart the way they can today.

**Done when**: registering a plain native class, and separately a base+derived native
inheritance pair, each need exactly one function call, no hand-built `$tables` array at
the call site; a class with an explicit `#[Table]` uses it, one without falls back to
its derived short name; two unrelated classes that happen to share a short class name
fail registration with an actionable error instead of silently colliding.

## Phase 6.3 — Rename safety for entity prototypes

**Goal**: a bounded, correct fixup operation for renaming an entity (native class or
editor-created prototype), the other half of the same design discussion. Field/member
renames are explicitly out of scope, already policy-forbidden ("Entity prototypes": "a
true rename all stay off the table entirely; a rename is better modeled as add a new
column, deprecate the old one"), for native fields the existing reviewed-migration
workflow already covers a rename correctly (a human editing the generated add+drop diff
into a real `RENAME COLUMN`).

- **What already works for free.** A native-to-native class-string reference is never
  persisted as literal text, `PrototypeShape` always re-derives `referencedShape` live
  via reflection on the current PHP type declaration. A consistent native class rename
  (the class itself plus every referencing property's type) needs zero data migration
  for this reason alone.
- **Table rename is not a special case split between native and editor-created.**
  `rename()` resolves the new table name through the exact same attribute-or-derive
  function Phase 6.2 introduces: an explicit `#[TableName]` on the new declaration takes
  precedence, otherwise the new short name is derived and run through the same collision
  guard against every other registered table. It then performs the physical rename for
  whichever identifier kind it's given, a native class's own table, or an editor-created
  prototype's table plus any of its own Collection fields' join/child tables
  (`{oldTable}_{fieldName}`, the same own-table set `SchemaEditor::syncOwnTable()`
  already isolates via its `str_starts_with($tableName . '_')` filter), and fixes up
  every `prototypes.parent`/`prototype_fields.referenced_shape` row that mentions the
  old identifier. FK constraints pointing at the renamed table need no manual fixup,
  PostgreSQL/MySQL/SQLite all track a foreign key by the table's internal identity, not
  by re-parsing its name, so a table rename updates them automatically.
- **Shape of the fix.** One operation, `SchemaEditor::rename(old, new, actor)` (naming
  tentative), covering both identifier kinds through the same `isEditorCreated()` branch
  `SchemaEditor` already uses everywhere else, gated by `SchemaPermission` like every
  other schema mutation; the native path has no `Actor` to check against (developer-only,
  triggered by a CLI command, not the editor), so permission gating there is a no-op by
  construction, not a special case to build. A permanent redirect-table was considered
  and deliberately not chosen as the primary mechanism, floated only as a possible
  secondary safety net if the direct fixup ever proves insufficient in practice.

**Done when**: renaming a native class that declares an explicit `#[TableName]` leaves its
table untouched, only fixing stale `parent`/`referenced_shape` rows; renaming one that
relied on the derived fallback renames its table to match automatically; renaming an
editor-created prototype renames its table, its own collection join/child tables, and
every stored reference to it, all through the one operation; a rename that would collide
with an existing table is rejected by the same guard Phase 6.2 introduced.

## Phase 7 — Admin list/filter views

**Goal**: browse, filter, and sort content across native and editor-created prototypes,
including fields defined at any inheritance level, backed by a real query-builder and
cursor pagination, not anything `OFFSET`-based. Two dependency-ordered pieces (design
discussion, 2026-09-28): the query-builder can't hydrate a result row into anything
without the first piece existing, so it's built first even though the roadmap bullet
that originally named it came second.

### Step A: a DynamicEntity representation, wired into Repository/ChangesetFlusher

Picks up the work Phase 5 deliberately deferred: `Repository`/`ChangesetFlusher` have
only ever known how to reflect and instantiate a real native class, this is the first
phase that actually needs to *read and write* editor-created content, not just mutate
its schema (`SchemaEditor`'s job, unchanged).

- `DynamicEntity`: identifier plus a values array, the editor-created counterpart to a
  hydrated native instance, no class to reflect on or instantiate
- `PrototypeRegistry::instantiate(identifier, values)`: native resolves through
  reflection's `newInstanceArgs()`, editor-created builds a `DynamicEntity` instead, the
  one place this decision gets made, reused by every call site that currently
  instantiates a native class directly
- `PrototypeRegistry::prototypeValidatorsOf(identifier)`: walks the whole chain, only
  pulling `#[PrototypeValidation]` off native levels, so a native ancestor's cross-field
  rule still applies to an editor-created subclass of it, matching how
  `FieldValidator`/`FieldPermission` already flow down a mixed chain
- `Repository`'s remaining native-only calls (`PrototypeShape::chainOfClass()`/
  `ownFieldsOfClass()`, raw `newInstanceArgs()`) route through new thin `EntityManager`
  delegate methods to its own `PrototypeRegistry`, instead of calling `PrototypeShape`
  directly
- `EntityManager` gains an optional `?PrototypeRegistry $registry = null` constructor
  parameter, defaulting to a fresh one built from the same connection when not supplied,
  the same "optional, sensible default" idiom already used for `?Actor $actor = null` and
  `?callable $mustPrecede = null` elsewhere; lets a caller share one instance with
  `SchemaEditor` instead of always getting two independent (if harmlessly stateless) ones
- `RowMapper::propertiesOf()` gets an `instanceof DynamicEntity` branch to read values
  back out without reflection
- `ChangesetFlusher`'s two remaining native-only `PrototypeShape::ofClass()` calls
  (`apply()`, `deleteMustPrecede()`) route through the same `EntityManager` delegates
- Table names stay exactly as today: one explicit `$tables` map (identifier => table
  name) covering both native and editor-created identifiers, same mechanism, no new
  dynamic lookup; an editor-created identifier's entry is added the same way a native
  one already is, not derived on demand

**Done when**: an editor-created prototype's instance can be created, found, updated,
and deleted through the exact same `Repository`/`ChangesetFlusher` path a native class
already uses, including a chain mixing native and editor-created levels, and a native
ancestor's `PrototypeValidator` still applies to an editor-created subclass of it.

### Step B: the query-builder

- `Query::for($identifier)->where(...)->orderBy(...)->after($cursor)->limit($n)->get()`,
  scoped to `$identifier`'s own chain (base through itself), never sideways across
  sibling subclasses or down into further subclasses of it, each editor-created content
  type is independently admin-managed everywhere else in this design, this isn't a new
  exception ("Admin list/filter views")
- Resolves a field to its real column *and* which chain-level table holds it, the same
  "declaring table" resolution `Repository` already has, reused rather than duplicated
- A genuine new SQL capability: one real multi-table `JOIN` across the whole chain with
  per-level column aliasing (`level__column`, `id`/`data` collide across every level
  otherwise), replacing `Repository::find()`'s one-query-per-level approach, which would
  be an N+1 disaster for a list of rows instead of a single lookup by id
- Cursor/keyset pagination (sort-column value + primary key tiebreaker), committed to
  from the start per `ARCHITECTURE.md`, not `LIMIT`/`OFFSET`; this roadmap previously
  said the opposite, a stale mismatch corrected here (2026-09-28). A portable OR-chain
  `WHERE` (`col > ? OR (col = ? AND id > ?)`), avoiding row-value-comparison portability
  issues, plus a fetch-`N+1`-to-detect-a-next-page trick
- `count()` reusing the exact same `WHERE`, independent of pagination, for a "showing
  X-Y of Z" display
- Deliberately out of scope: filtering by something inside a collection/join table
  (`EXISTS`-style semantics), already called out as a non-goal in `ARCHITECTURE.md`

**Done when**: a list view for an identifier can filter/sort by a field declared on any
level of its own chain (native or editor-created), returns a stable page via cursor with
a correct has-more signal, and `count()` matches the same filter independent of
pagination.

## Phase 8 — Global entity identity

**Goal**: every entity (native or editor-created) gets a row in one shared `entities`
table, id, an optional self-referencing `owner`, and its concrete type, making
polymorphic references, Owned-relationship storage, and native/editor reparenting fall
out of one mechanism instead of three separate ones. Replaces the "Polymorphic entity
references" plan that previously occupied this phase outright (design discussion,
2026-09-28): that plan's per-chain discriminator (its Step A), its per-subtype Owned
child tables (its Step C), and the dedicated-table-per-Owned-relationship rule in
`ARCHITECTURE.md` ("Ownership: Owned vs. Shared") are all superseded, not extended, by
this phase.

**What this buys, concretely.** The old plan needed three separate mechanisms: a
discriminator column repeated on every chain's own root, a per-relationship Owned child
table carrying its own CTI structure for subtypes, and a dedicated table for every
Owned relationship in the first place, because a shared `owner_id` column couldn't
target more than one table depending on who owns the shape (`ARCHITECTURE.md`,
"Ownership: Owned vs. Shared", "Why an Owned relationship always needs its own
dedicated table"). A single shared identity table removes the reason for all three at
once: `owner` can point at any entity regardless of its concrete type, because every
entity now shares one id space, not one per prototype family.

**What this costs.** Every entity read, including a previously zero-join standalone
native class, now joins to `entities`. Accepted (design discussion, 2026-09-28): the
common case stops being free, in exchange for deleting three separate mechanisms and
their open questions.

Four dependency-ordered steps.

### Step A: the shared `entities` table

- One fixed table, not derived from any class, defined once in `SchemaBuilder` the same
  way `ID_COLUMN`/`BLOB_COLUMN` are fixed constants today: `id` (autoincrement PK),
  `owner` (nullable, self-referencing FK to `entities.id`, `ON DELETE CASCADE`
  unconditionally, `owner` is only ever populated for an Owned relationship, which
  always cascades, so no per-field policy branch is needed here the way
  `EntityReference` columns still need one), `owner_field` (nullable string), `position`
  (nullable int, `-1` for a non-collection Owned relationship), `concrete_type`
  (string).
- `EntityRegistrar::register()` builds and syncs this table unconditionally, first,
  before any class-derived table, the one new bootstrap path that isn't reached through
  `resolveTables()`/`resolveDefinitions()`'s class-walking, since `entities` belongs to
  no class.
- `owner`, `owner_field`, `concrete_type` are never separately declared anywhere.
  `concrete_type` is whatever identifier `Repository::insert()` is actually inserting,
  native or editor-created. `owner`/`owner_field`/`position` are derived from whichever
  `Ownership::Owned` `FieldDescriptor` caused the write, its own already-declared
  `name`, not a new attribute needing its own authoring surface.

**Done when**: `entities` exists and syncs correctly alongside every other table; a
fresh native class registered through `EntityRegistrar` gets it without any change to
how that class declares its own fields.

### Step B: every chain gets `entities` as its root

- `entities` becomes chain position 0 for every identifier, native or editor-created,
  standalone or already part of an inheritance chain, `SchemaBuilder::tablesForChain()`/
  `tableFor()` both grow this implicit level. There's no more "chain of one, no join"
  case, every entity is a chain of at least two from now on.
- `Repository::insert()` writes the root row into `entities` first, this is where `id`
  actually originates now, not the class's own former-root table, then proceeds through
  the rest of the chain exactly as today, using that id.
- `Repository::delete()` is unaffected in spirit, deleting the `entities` row already
  cascades down through every derived level via the existing per-level CASCADE join, and
  now also cascades to every Owned entity via `entities.owner`, for free, same
  mechanism.
- Discriminator resolution (`EntityManager::concreteIdentifierOf()`, replacing the old
  plan's per-chain walk) becomes one indexed lookup: `SELECT concrete_type FROM
  entities WHERE id = ?`. No more "walk `chainOf($identifier)` and read whatever sits
  at position 0," there's only ever one root table for every identifier now.
- `Repository::find()`/`materialize()`/`Query`'s row-to-object step resolve the
  concrete identifier from that lookup before hydrating, same polymorphic-hydration
  requirement the old plan's Step B already described, simpler to implement since
  there's one shared root instead of one per prototype family.
- `Query::fromClause()` prepends the `entities` join for every query.

**Done when**: a `Vegetable` assigned anywhere a `Product`-typed reference is declared
reads back as a fully-hydrated `Vegetable`, through a direct reference, a `find()` on
the base identifier, and a `Query` result row, both native and editor-created, the same
acceptance bar the old plan's Step B set, now met structurally instead of through a
per-chain discriminator.

### Step C: Owned relationships move onto `entities.owner`/`owner_field`/`position`

- A singular Owned reference no longer gets a column on the owner's own table. A
  `Product.featuredImage` (Owned `MediaAsset`) is found by `SELECT * FROM entities
  WHERE owner = ? AND owner_field = 'featuredImage' AND position = -1`, then hydrated
  through `MediaAsset`'s own repository, same as any other entity, nothing
  `Product`-specific about `MediaAsset`'s own table.
- An Owned collection is the same query without the `position = -1` filter, ordered by
  `position`, no more per-relationship child table (`{ownerTable}_{field}`), and
  therefore no more version of the old plan's Step C problem (giving that child table
  its own CTI structure for subtypes): an owned `Vegetable` collection item already
  lives in the ordinary `vegetable` table via its own chain (Step B), fully polymorphic
  with zero extra mechanism.
- `SchemaBuilder::addReferenceColumn()` drops its Owned/`CASCADE` branch entirely (only
  `Ownership::Shared` reaches it now, always `RESTRICT`). `SchemaBuilder::collectionTables()`
  drops its whole Owned branch (only the Shared/pivot-table branch remains).
- `Repository`'s reference/collection read-write paths (`readReference()`,
  `readCollection()`, `writeCollection()`, `rowWithReferences()`) gain a new Owned
  branch: writing means inserting/updating the item through its own repository and
  setting `owner`/`owner_field`/`position` on its `entities` row; reading means the
  reverse-indexed lookup above. An index on `(owner, owner_field)` backs it.
- **Shared is untouched**: a singular Shared reference stays a direct FK column
  (`RESTRICT`); a Shared collection stays a real pivot table. Only Owned moves.
- Reordering a collection is an ordinary multi-entity `Changeset`, each moved item's
  `EntityChange::update()` touches its own `position` field, no new write-path
  mechanism needed.
- The undo-log's pre-delete snapshot, which already needs to walk every Owned child of
  an entity being deleted, simplifies to one query (`SELECT * FROM entities WHERE
  owner = ?`) instead of a sweep across as many per-relationship tables as the owner has
  Owned fields.

**Done when**: a `Product` with both an Owned singular reference and an Owned
collection of the same referenced type (two fields, same target type, disambiguated
only by `owner_field`) round-trips correctly through insert, find, and delete-cascade;
an owned item's own subtype fields persist and rehydrate with no dedicated child table
involved.

### Step D: reparenting, uniform mechanism, backfill still open

- Native and editor-created reparenting (add/change/remove a class's parent) become the
  mechanically identical operation once every chain already has `entities` as its
  structural top: inserting or removing one CTI level between `entities` and the
  class's own table, no more "was this previously a root or not" special case to split
  on.
- **Not resolved by this phase, and not treated as blocking it**: backfilling values for
  a newly-required base's own fields on already-existing rows has no more of an answer
  here than the old plan's own Step D left it, a human (or an explicit admin-supplied
  default) still has to decide those values. Since there's no real data in this system
  yet (2026-09-28), this is deliberately left open rather than designed against a
  hypothetical migration, revisit once real data makes it a live question.

**Done when**: a documented, correct procedure exists for inserting/removing a CTI
level for both native and editor-created classes structurally (the metadata/DDL side);
the backfill-values question is explicitly logged as still open, not silently assumed
solved.

## Shelf items

Not numbered phases — pick these up only once a real need shows up, not preemptively.

### Full-text & cross-content-type search

The architecture doc is explicit this shouldn't be built preemptively:

- `searchable` flag on `FieldDescriptor`, independent of `queryable`
- Flat `search_index` table, `SearchIndex` strategy interface with a swappable default
- Hooks into the Phase 3 write path for free; Owned entities' text folds into their
  owner's index entry, same as everywhere else Owned/Shared already applies

### Client-side validation tiers

Needs an actual admin editor UI to attach to, which doesn't exist yet ("Validation"):

- Tier 1: `FieldValidator::describe()`'s `{type, ...params}` mapped to native HTML5
  constraint attributes (`required`, `minlength`/`maxlength`, `min`/`max`/`step`,
  `pattern`), validated by the browser itself, no JS required
- Tier 2: a small shared registry of JS validator functions, keyed by the same `type`
  strings the PHP side uses, for named reusable algorithms the browser doesn't support
  natively (IBAN, credit card checksum, phone format)
- Tier 3 (bespoke, one-off logic) stays server-round-trip-only, the rare exception once
  tiers 1 and 2 are reasonably filled out
- The concurrent-write "override and publish anyway" retry flow also lands here: the
  backend primitive already works today (retry the same flush with a fresh or omitted
  `expectedOperationId`), it just has no UI to trigger it and has never been exercised
  as that specific flow
