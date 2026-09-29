# Storage / Editor — Audit

**Status: working document, not a spec.** Triggered after Phase 8C by a real question:
does this system actually survive every kind of change to an entity's shape, native or
editor-created, across every nesting kind (value objects, embed, owned references, owned
reference collections, shared references, shared reference collections)? The six change
types in scope: class rename, class deletion, member rename, member type change,
reparenting, member add/remove.

This document just collects what the audit finds. Nothing here is a commitment until it
graduates into `ROADMAP.md` as an actual phase — findings get reclassified, merged, or
thrown out as the audit continues. Each section holds what's confirmed so far and any
decisions already made during the audit (dated); open questions sit at the bottom of
their section and stay there until resolved, then fold into the section's own prose.

Baseline: `tests/Storage` is 164/164 green as of 2026-09-29. Green does not mean correct
for the scenarios this audit cares about — see section 2, the rename example.

## 1. Schema mutations must all be undoable

Decided (2026-09-29): every schema mutation is undoable, including prototype creation,
prototype deletion, rename, and reparenting — not just `AddColumn`/`DropColumn` like
today.

Decided (2026-09-29): `UndoLog` (content) and `SchemaUndoLog` (schema) stay as two
separate logs, bridged by cross-log conflict checks, not merged into one shared type.
Merging would force `EntityChangeRecord` (a per-entity field diff) and a schema operation
(DDL plus kind-specific metadata, a different shape per kind) into one artificial common
shape.

Decided (2026-09-29): the two logs share one monotonic sequence (both pull ids from one
counter), not wall-clock timestamps, so operations from either log are comparably ordered
without reintroducing the clock-skew/tie-breaking problems already rejected once for
retention (see `ARCHITECTURE.md`, "The universal undo-log").

Decided (2026-09-29): conflict classification when bridging is per schema-operation-kind,
not blanket:
- Never conflicts: `AddColumn`, `CreatePrototype` — purely additive.
- Conflicts only if it touched the same field: `DropColumn`.
- Always conflicts, any field: `Rename`, `Reparent`, `DeletePrototype` — the identifier
  itself, or whether the row's shape exists in its old form at all, changes.

The third bucket is what makes "undo a rename before you can reach an older content
changeset on that prototype" an enforced guarantee instead of an assumption.

**Open:**
- `SchemaOperation`'s shape (`{id, kind, prototypeIdentifier, field, snapshot}`) only
  fits `AddColumn`/`DropColumn`. Needs to generalize to private-constructor-plus-named-
  factories (same discipline as `EntityChange`/`FieldDescriptor`), one factory per kind,
  each carrying only what it needs: `createPrototype()` (full definition),
  `deletePrototype()` (full-table snapshot), `rename()` (old/new identifier + table),
  `reparent()` (old/new parent, backfilled-row snapshot).
- Exact scope of "always conflicts" for `Rename`/`Reparent`/`DeletePrototype` — does it
  block content operations across the whole chain (subclasses too), or only the exact
  identifier the schema op targeted?
- Whether undoing a schema op needs its own reverse check against content operations
  that happened after it (e.g. undoing `CreatePrototype` once rows have since been
  inserted into it) — the same bridging problem, opposite direction.

## 2. Rename staleness

`entities.concrete_type` staleness (`ROADMAP.md` Step C Fix 2) is a confirmed live bug,
not just a documented gap: `PrototypeRegistry::renameReferences()` only updates
`prototypes.parent`/`prototype_fields.referenced_shape`, never `entities.concrete_type`.
The existing rename test
(`EntityRegistrarTest::testRenameMovesTheOldTableToTheNewClassesDerivedNameAndFixesReferences`)
inserts a pre-rename `entities` row but never calls `find()` on it after renaming — green
while the path it should catch is broken. Fix is already fully specified in
`ROADMAP.md`; mechanical once schema-op undo logging (section 1) exists to attach it to.

`owner_field` staleness (Step C Fix 1) — confirmed absent, but confirmed there is
currently no field-level rename operation anywhere in the code, so this is presently
hypothetical, contingent on the member-rename policy decision in section 3.

Content `ChangesetOperation`/`EntityChangeRecord::$prototypeClass` staleness — stored as
a bare string, no fixup path, no rename-awareness at all today. Likely resolved
structurally once section 1's "Rename always conflicts" rule exists (a pre-rename
content operation can't be reached without undoing the rename first, so the stale
identifier is never dereferenced while stale) — needs confirming once section 1's open
items are settled, not assumed.

**Open:**
- Confirm section 1's conflict-bridging actually eliminates the need to rewrite
  `prototypeClass` in stored `ChangesetOperation`s once built, or whether a direct
  fixup is still needed as a backstop.

## 3. Member (field) rename policy

No field/member-level rename operation exists anywhere in the code today —
`SchemaEditor::rename()` only renames whole prototypes. `ROADMAP.md` Phase 6.3 forbids
member renames by policy (model as add-new-column, deprecate-old), but that reasoning
was written for scalar/queryable native columns specifically — unclear whether it was
ever meant to cover relationship field names (Owned/Shared reference and collection
names) or editor-created fields too.

The original audit ask ("member name changes... has to hold true for... owned reference
collections") implies member rename should actually work, in tension with Phase 6.3's
forbidden-by-policy stance.

**Open:**
- (A) Keep member rename forbidden everywhere, scalar and relationship fields alike,
  native and editor-created alike. `owner_field` staleness then never occurs by
  construction — nothing to build.
- (B) Build a real field-rename operation, at least for editor-created prototypes.
  Needs `owner_field` fixup (cheap, same shape as the `concrete_type` fix) plus a fixup
  for blob-stored field keys, a genuine per-row data migration (every entity's JSON blob
  has the old key baked into its stored data) — never designed anywhere yet.

## 4. Operations that don't exist at all yet

Not buggy, not partially designed — zero code:

- Prototype/content-type deletion, native or editor-created. `SchemaEditor` has no
  delete method. Not even a `ROADMAP.md` phase/step names it, despite being on the
  original audit ask.
- Reparenting (`ROADMAP.md` Step D) — `grep` for "reparent" in `src/Storage` returns
  nothing.
- Defaulted instances / `#[DefaultInstance]` (`ROADMAP.md` Step F) — `grep` returns
  nothing. Needed by reparenting's backfill and by "add a new field to an
  already-populated class."
- Dropping (or adding) an `EmbeddedValueObject` or `Collection` field —
  `SchemaEditor::snapshotColumn()` explicitly throws for both kinds today
  ("Dropping a %s field is not supported yet.").
- A fresh, parentless editor-created prototype — `createPrototype()` requires a parent;
  `SchemaEditor`'s own docblock admits this.
- Flipping `queryable`, `unique`, or `Ownership` on an already-existing field — no
  mutation path exists; `SchemaEditor`'s only writes are whole-field
  add/drop/rename/create-prototype.
- Native class deletion (the PHP file is simply removed from the codebase) — no
  detection, no decommission workflow, nothing.

**Open:** all of the above need an actual design pass, not just an implementation pass —
none has decided data-migration mechanics yet (see section 5 for the general shape of
that problem).

## 5. Snapshot/restore shape needed for member removal, by nesting kind

Raised directly by section 1's `DropColumn`-equivalent work: "restore the data on undo"
needs a genuinely different snapshot shape per field kind, not one flat
`entityId => value` map:

- Scalar/choice/reference (works today): `entityId => single value`.
- `EmbeddedValueObject`: multiple real columns (dot-flattened queryable sub-fields) plus
  leftover blob values for non-queryable sub-fields — a sub-shape per row, not one
  column.
- Shared `Collection`: every `(owner_id, item_id)` pair from the join table; the
  referenced items themselves are untouched.
- Owned reference / Owned `Collection`: the hard case. An owned item has no life apart
  from the relationship, so dropping the field means the owned items are deleted, not
  detached, and they can themselves carry nested owned/embedded structure. Undo means
  restoring a whole entity subtree, recursively, not a flat value map.

**Open:**
- Whether to build full recursive subtree snapshot/restore for the Owned case in the
  same pass as scalar/embed/shared-collection, or explicitly scope it out as a flagged
  follow-up (same "documented boundary" posture used elsewhere) until the rest is solid.

## 6. Storage-location migrations for already-populated data

Several operations look like ordinary field edits but actually mean moving
already-persisted data between physically different storage locations — the
schema-mutation metadata side is easy, the data-movement side has no design anywhere:

- Flipping `queryable` on an existing field (JSON blob ↔ real column).
- Flipping `Ownership` (Owned ↔ Shared) on an existing relationship (`entities.owner` ↔
  real FK column ↔ pivot table) — a completely different physical shape.
- Changing a collection's item kind between scalar/embed and `EntityReference` (blob ↔
  pivot table).
- Type changes on a native queryable column (e.g. `String → Int`) — DBAL's `Comparator`
  will happily generate the `ALTER COLUMN TYPE`, but nothing validates or converts
  existing incompatible data first.

**Open:** all four, no design started.

## 7. Schema-level referential integrity

No check prevents deleting or renaming a prototype while some other prototype's
`FieldDescriptor` still declares a `reference()`/`embed()`/`collection()` pointing at
it, independent of whether any row-level data currently exists. Row-level `RESTRICT`
only guards actual rows, never schema definitions.

**Open:** design not started.

## 8. `#[Embed]` still native-only in practice (`ROADMAP.md` Step E)

`SchemaBuilder::addColumns()` and `realColumnNames()` both still call
`PrototypeShape::ofClass()` directly (`SchemaBuilder.php:164,194`) instead of the
identifier-resolving callable `ARCHITECTURE.md`/`ROADMAP.md` Step E already call for. An
editor-created identifier cannot be an `#[Embed]` target today, despite the architecture
doc treating "any identifier is a valid embed target" as already decided.

Bigger than a one-file fix: `RowMapper::split()`/`join()` have the exact same problem
for the value-hydration side, not just schema sync. Both call `PrototypeShape::ofClass()`
directly for a nested embed field (`RowMapper.php:69,106`), and `join()` instantiates the
embedded object with raw `(new ReflectionClass(...))->newInstanceArgs(...)`
(`RowMapper.php:108`) instead of `PrototypeRegistry::instantiate()`. Fixing `SchemaBuilder`
alone would let an editor-created embed target sync its columns correctly but still break
on every actual read/write, since `RowMapper` would still fail to resolve or instantiate
it.

**Open:** implementation only, not design — the fix is already specified in
`ROADMAP.md` Step E for `SchemaBuilder`, but needs the identical treatment extended to
`RowMapper`, which isn't mentioned there yet.

## 9. `EditorExtensible` revocation

No defined behavior for revoking `#[EditorExtensible]` on a native class that already
has live editor-created subclasses.

**Open:** design not started.

## 10. Search index rename staleness (pre-emptive)

Not built yet, it's a Shelf item, but flagged now since it will inherit the exact same
disease as `entities.concrete_type`: a future `search_index.prototype` column goes stale
on rename the same way, unless whatever eventually builds full-text search wires
rename-fixup in from the start instead of as an afterthought the way `concrete_type` was.

**Open:** nothing to do until the feature itself is built — noted so it isn't forgotten
when it is.

## 11. Test suite audit

164/164 green in `tests/Storage`, confirmed unreliable as a correctness signal for
exactly the scenarios this audit cares about — the existing rename test inserts data
before a rename and never checks `find()` afterward, so it's green while the code path
it should catch is broken. Once any of the above gets fixed, the existing suite needs a
systematic pass, not just new tests bolted on, since a test that looks like it covers a
scenario (like the rename test) may not.

**Open:** deferred until the fixes above land — auditing tests before fixing the code
they're wrong about is premature.

## 12. Confirmed fine, no action needed

- The "safe DDL only" posture (`ARCHITECTURE.md`, "Entity prototypes") is actually
  enforced by construction today, not just by policy: `SchemaEditor` exposes no
  rename-column, retype, or arbitrary-DDL path at all, only `addColumn`/`dropColumn`/
  `rename`/`createPrototype`. Nothing bypasses the restriction because nothing else
  exists yet. Worth re-checking once section 4's missing operations get built, so this
  stays true rather than becoming stale.

## 13. `EntityManager` and `SchemaEditor` don't share a table map

Found 2026-09-29, reading `IdentityMap`/`EntityManager`/`SchemaEditor` together, not
raised anywhere before this. `EntityManager::$tables` is `private readonly array` —
fixed forever at construction (typically via `EntityRegistrar::register()`).
`SchemaEditor::$tables` is a structurally separate, mutable `private array` that grows
via `createPrototype()` and gets rewritten via `rename()`. Nothing connects the two.

Concretely: the moment `SchemaEditor::createPrototype()` or `rename()` runs, any
`EntityManager` already in hand (and everything built from it — `Repository`, `Query`)
still holds the old map and cannot resolve the new/renamed identifier at all —
`EntityManager::repository($newIdentifier)` throws "No table registered for..." until
something constructs a fresh `EntityManager` from a merged table map. This is a bigger
problem than any single section above: it means the schema-mutation half of the system
(`SchemaEditor`) and the read/write half (`EntityManager`/`Repository`/`Query`) are not
actually usable together within one request today, not "undo is incomplete for this,"
but "using both halves back to back doesn't work at all" without a manual rebuild step
nobody has designed yet.

Compounding it: rebuilding `EntityManager` to pick up the new map also discards its
`IdentityMap`, since one lives inside the other. Anything already hydrated earlier in
that same request silently loses the "same id resolves to the same instance" guarantee
across that rebuild boundary.

**Open:**
- Design not started. Needs either a shared, mutable table-registry object both
  `EntityManager` and `SchemaEditor` hold a reference to (so a mutation in one is
  visible to the other immediately), or an explicit, designed "refresh" step that
  reconstructs `EntityManager` from `SchemaEditor`'s current map while preserving (or
  deliberately, visibly discarding) the existing `IdentityMap`.

## 14. `DraftPreview` is native-only, drafts don't work for editor-created prototypes

Found 2026-09-29. `DraftPreview::preview()` calls `PrototypeShape::ofClass($change->prototypeClass)`
(`DraftPreview.php:30`) and instantiates the previewed entity with
`(new ReflectionClass($change->prototypeClass))->newInstanceArgs($hydrated)`
(`DraftPreview.php:37`) — both native-reflection-only, both throw for an editor-created
identifier instead of going through `EntityManager::fieldsOf()`/`instantiate()` the way
`ChangesetFlusher`/`Repository` already do.

Notably inconsistent within the same class: `DraftPreview::currentEntity()`, a few lines
away, correctly calls `$this->entityManager->repository($change->prototypeClass)->find($id)`
— the native/editor-created-aware path. So this isn't "drafts were never built for
editor-created prototypes," it's one method updated for the split and a sibling method
in the same file that wasn't. Since Draft is one of the three core write-path pipelines
in `ARCHITECTURE.md`, this means previewing (and therefore publishing) a draft against
any editor-created prototype is broken today.

**Open:**
- Mechanical fix once flagged: route both call sites through `EntityManager` the same
  way `currentEntity()` already does. Worth a pass over the rest of `Changeset/` for the
  same pattern (a method calling `PrototypeShape`/`ReflectionClass` directly instead of
  through `EntityManager`) before assuming this is the only instance.
