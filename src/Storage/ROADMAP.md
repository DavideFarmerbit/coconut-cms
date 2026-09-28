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

## Phase 8 — Polymorphic entity references

**Goal**: a reference or Shared collection field declared against a base identifier
(native or editor-created) can hold, store, and correctly rehydrate as any concrete
subtype of it, assigning a `Product`-typed field a `Vegetable` or a `Meat`. Not covered
in `ARCHITECTURE.md`, surfaced by design discussion (2026-09-28) rather than tied to an
existing decided section.

**Writing already works, today, for free.** CTI means a subtype's row shares its `id`
with every ancestor level, so a `product_id` FK is already satisfied by any subtype's
row, no schema change needed there. `Repository::referencedId()` resolves by object
identity through the `IdentityMap`, not by class, so assigning a `Vegetable` instance to
a `Product`-typed field already stores correctly, native or editor-created, right now.
**The entire gap is on the read side**: `readReference()`/`readCollection()`/`find()`
all assume the field's declared (or requested) identifier is the concrete one, so a
`Vegetable` row read back through a `Product`-typed anything comes back missing
`Vegetable`'s own fields, or fails outright if its constructor differs.

Four dependency-ordered steps.

### Step A: a discriminator column on each chain's root, populated once at insert

- The root (topmost, parentless) level of every chain gets a small extra column
  (tentatively `_prototype`, a leading underscore to avoid ever colliding with a real
  declared field, the same reasoning `id`/`data` already rely on), storing the row's
  own concrete identifier. Matches how Doctrine's own Joined Table Inheritance places
  its discriminator, "in the topmost table of the hierarchy," not copied onto every
  level: a derived level's own table already answers "at least this subtype" just by a
  row existing there, the only open question a discriminator ever needs to answer, "is
  it something further derived than that," only ever needs one authoritative answer,
  and every level already shares the same `id` to look it up by.
- Every root, unconditionally, not just `#[EditorExtensible]` natives or
  editor-created ones: a plain native subclass (`Product extends BaseProduct`, neither
  one `#[EditorExtensible]`) is already an ordinary CTI chain today with no attribute
  gating it, so there's no way to know upfront which tables might one day become the
  base of a subclass, native subclassing needs no admin/editor involvement at all.
- Looking it up is always dynamic, never cached or hardcoded: walk `chainOf($identifier)`
  and read the column off whatever table currently sits at position 0. This is what
  makes Step D's reparenting safe for free, once a class's chain actually changes,
  every lookup already asks fresh, there's nothing stale to invalidate.
- Populated by `Repository::insert()` on the root's own row, always `$this->class` (the
  leaf actually being inserted), never touched by `update()`, an entity's own concrete
  type never changes after creation.
- A field literally named `_prototype` is rejected the same way any other reserved-name
  collision already is elsewhere in this design.

**Done when**: every chain's root table, native or editor-created, chain of one level or
many, has the column, and every insert populates it correctly regardless of which level
of the chain is actually being inserted.

### Step B: polymorphic hydration everywhere a row turns into an object

- `EntityManager::concreteIdentifierOf(identifier, id)`: one small, indexed lookup
  (read the discriminator off the chain's root row) resolving a base identifier plus an
  id to the row's actual concrete identifier.
- `Repository::find()`/`materialize()` become the one place this actually gets decided:
  when the discriminator disagrees with `$this->class`, hydration delegates entirely to
  the concrete identifier's own Repository (its own chain reaches levels this Repository
  never even knows about), reusing the exact same Identity Map integration, not a
  parallel path.
- `readReference()`/`readCollection()`'s Shared branch, and
  `EntityManager::hydrateReferences()`'s id-resolution path, all resolve the concrete
  identifier before building a lazy ghost or Repository lookup, instead of assuming the
  field's declared `referencedShape` is the concrete type.
- `Query` stays scoped exactly as Phase 7 built it, one identifier's own chain only for
  filtering/sorting/pagination, but the row-to-object step (`materialize()`) becomes
  polymorphic the same way `find()` does: a `Query::for('Product')` result can come back
  as a mix of `Product`/`Vegetable`/`Meat` instances, each fully hydrated, without
  `Query` itself needing to know about any of them upfront.

**Done when**: a `Vegetable` assigned to a `Product`-typed reference field (native or
editor-created) reads back as a fully-hydrated `Vegetable`, not a partial `Product`,
through all four paths this phase touches, a direct reference, a Shared collection, a
direct `find()` on the base identifier, and a `Query` result row, both native and
editor-created subtypes.

### Step C: Owned collections (structural gap this surfaces, mechanism not settled yet)

Owned collections have a deeper, pre-existing gap this surfaces rather than causes:
`SchemaBuilder::collectionTables()`'s Owned branch builds one flat child table sized
for the declared item type's own flattened fields, no per-CTI-level child tables exist
at all, so a `Vegetable`-shaped owned item has nowhere to store its own extra fields
today, independent of polymorphism.

- Shape of the fix: give an Owned collection's child table the same base-plus-derived
  structure a top-level CTI chain already has, just namespaced per (owner table, field)
  instead of being independently addressable: `{ownerTable}_{field}` stays the base
  child table, `{ownerTable}_{field}_{subtype}` holds a concrete subtype's own extra
  fields, joined by id, same as any other CTI derived level.
- **Always on, no opt-out or opt-in flag, every Owned collection** (design discussion,
  2026-09-28): considered and rejected gating this behind a flag, for the same reason
  Step A's discriminator column has no conditional form. A plain native subclass, or an
  editor-created one, can appear on the declared item kind at any time with no attribute
  or admin action gating it, so a flag set at declaration time could always be wrong the
  moment reality diverges from it. It's also free for the common, non-polymorphic case
  (a `GalleryItem`-style repeater with no subtype, ever): the discriminator is already
  sitting on the row `readCollection()` already fetched, comparing it against the
  declared item kind costs nothing new, and it always matches, so nothing further ever
  runs. The one extra lookup (a subtype's own child table) only fires for a row that
  genuinely is a subtype, exactly when that work is actually needed.
- **Open question, not resolved yet**: *when* does `{ownerTable}_{field}_{subtype}`
  actually get created? A native subtype is knowable upfront, through reflection, same
  as everything else native. An editor-created subtype of the declared item kind can
  appear at any time, admin-triggered, with no existing mechanism that knows every
  Owned-collection field across the app that would need a new child table the moment
  it's created. Auto-creating it on first write (DDL as a side effect of an ordinary
  insert, not through `SchemaEditor`) is the leading candidate, but it's a genuinely new
  kind of automatic schema evolution, not an extension of anything already decided in
  `ARCHITECTURE.md`, worth confirming on its own before building it, not assuming it as
  part of this phase's "already decided" scope.

**Done when**: an Owned collection whose declared item kind turns out to have a
concrete subtype can hold that subtype's instance with its own extra fields,
round-tripping correctly through insert and find, for both a native subtype and an
editor-created one, with zero behavior change for a collection whose items never have
one.

### Step D: a parent changing, being added, or being removed

Once Step A's root-only discriminator exists, whether a class is currently a root or a
derived level is itself something that can change, a class gaining, losing, or
switching its parent restructures its whole chain, not just its own table. This is a
much bigger problem than Phase 6.3's rename: renaming only ever had to fix stale text
referencing an identifier, here the chain shape itself changes, which can mean existing
rows needing brand-new counterpart rows in a table that didn't apply to them before, or
a level's own columns needing to merge into (or split out of) another table entirely,
real data migration, not just metadata and a table rename.

- **Editor-created: parent changed is the only real case.** `SchemaEditor::createPrototype()`
  requires a `$parent` argument, a parentless editor-created prototype isn't a
  supported state at all, so "parent added"/"parent removed" don't apply here, only
  re-pointing from one existing parent to a different existing one. This is the more
  tractable case: `SchemaEditor`/`PrototypeRegistry` already own every table and every
  piece of metadata involved (unlike native), so a real, admin-triggerable operation is
  plausible here, the open part is exclusively the backfill question below, not the
  metadata/DDL side, which existing machinery already mostly covers.
- **Native: all three cases are real**, and none of them touch `PrototypeRegistry` at
  all, a native class's parent is never persisted anywhere, `parentOf()` always reflects
  `getParentClass()` live. Which means the "change" already happened the moment the
  developer edited their source, what's actually missing isn't a metadata fixup, it's
  making the *database* catch up to a chain shape the source already declares:
  - *Added* (a previously-parentless class gets a new ancestor): every existing row
    needs a new counterpart row inserted into the new base's table, same id, values
    for whatever fields that base declares.
  - *Changed* (an existing parent is swapped for a different one): existing rows need
    counterpart rows in the new base's table instead of the old one, plus deciding what
    happens to the now-orphaned rows in the old one.
  - *Removed* (a class becomes standalone): the old base's columns need to fold into
    the class's own table, or that data is lost.
- **Open question, not resolved yet**: how much of this is ever safe to automate versus
  belongs entirely to the existing native "reviewed migration" workflow. The backfill
  values needed for a newly-required base's own fields aren't derivable from anything
  the system already knows, a human (or an explicit admin-supplied default/mapping)
  has to decide them either way. Whether any part of this becomes a real, callable
  operation (mirroring `SchemaEditor::rename()`'s shape) or stays "identify what
  changed, hand off to a human-authored migration" the same way any other native schema
  change already does, isn't decided yet.

**Done when**: at minimum, a documented, correct fixup procedure exists for each of the
four cases (three native, one editor-created), covering what has to change and in what
order; whether any of them become an automated, callable operation versus stay a
manual/reviewed migration is a decision this step's own investigation should resolve,
not something assumed going in.

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
