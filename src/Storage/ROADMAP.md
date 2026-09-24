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

## Phase 7 — Admin list/filter views

**Goal**: browse, filter, and sort content across native and editor-created prototypes,
including fields defined at any inheritance level.

- Query-builder resolving a filterable field to its real column *and* which
  inheritance-level table holds it, joining as needed ("Admin list/filter views")
- Plain `LIMIT`/`OFFSET` pagination (already decided as sufficient for v1)
- Picks up the work Phase 5 deliberately deferred: a `DynamicEntity`-style generic
  instance representation for editor-created prototypes, and wiring it into
  `Repository`/`ChangesetFlusher` — this phase is the first one that actually needs to
  *read* editor-created content, so it's the natural place for that to land, not a new
  scope addition

**Done when**: a list view for a base prototype can filter/sort by a field declared on
a derived editor-created subclass, joining the right table transparently.

## Shelf item — Full-text & cross-content-type search

Not a numbered phase — the architecture doc is explicit this shouldn't be built
preemptively. Pick this up only once a real need shows up:

- `searchable` flag on `FieldDescriptor`, independent of `queryable`
- Flat `search_index` table, `SearchIndex` strategy interface with a swappable default
- Hooks into the Phase 3 write path for free; Owned entities' text folds into their
  owner's index entry, same as everywhere else Owned/Shared already applies
