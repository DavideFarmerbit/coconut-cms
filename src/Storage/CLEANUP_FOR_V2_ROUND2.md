# Storage / Editor v2 — Cleanup Before Building (Round 2)

**Status: open (2026-09-30).** A second audit pass over `ARCHITECTURE_V2.md`/
`ROADMAP_V2.md`, done after `CLEANUP_FOR_V2.md`'s 20 items were folded back into both
documents. This is a punch list, not a spec. Ordered by how much it changes what gets
built, not alphabetically.

## Real inconsistencies

### 1. Owned cascade-delete bypasses the undo/audit-log guarantee — **resolved (2026-09-30)**

"References and collections" decided Owned deletion is `CASCADE`, uniformly via
`entities.owner` — a DB-level FK constraint. `ROADMAP_V2.md` Phase 3.3's own "Done when"
confirmed this fired as a raw-SQL cascade, not an app-mediated one: *"deleting an owner
cascades correctly to every Owned child, at any depth, through one `entities`-scoped
query"* — tested in Phase 3, before `Changeset`/`ChangesetFlusher` exist at all (Phase 4).

But "Content undo, draft, and revision history" commits to *"no carve-out for Owned
entities... one rule for every touched entity, Owned or Shared"* — every entity a flush
touches gets its own `EntityChangeRecord`. If Owned descendants disappear via a database
FK trigger, `ChangesetFlusher` never observes those deletions and can't log them. That
silently broke undo of a Revision that deleted an owner (its Owned children never get
inverse-applied — no record exists for them) and revision-history restore of that owner
(recreates the owner but not what it owned).

**Decided**: `entities.owner` is `ON DELETE RESTRICT`, not `CASCADE`. Deleting an owner
auto-expands into an explicit delete for every entity in its owned subtree, added to the
`Changeset` before the topological sort runs (`Persistence\Changeset\`'s job, not
`Repository::delete()`'s, which stays a single-entity primitive) — so every descendant
passes through the ordinary write path and gets logged, and the `RESTRICT` constraint
becomes a hard backstop that should never actually fire in normal operation. This also
settled a second, related question: `expectedOperationId` for an entity with Owned
descendants now covers its whole owned subtree recursively, not just its own direct
changes (Owned only, never Shared) — narrowly scoped to write-time concurrency protection,
explicitly not changing the undo conflict check, `EntityChangeRecord` logging, or
`FieldPermission`, all of which stay per-entity/per-field with no ownership-based special
case as already decided. Folded into `ARCHITECTURE_V2.md` ("References and collections",
"Content write path", "Content undo...", "Media/file fields") and `ROADMAP_V2.md`
(Phase 3.3, Phase 4).

### 2. Stale class names in the namespace inventory — **resolved (2026-09-30)**

"Namespaces and migration path" listed `Persistence\Changeset\Undo\` as containing
`UndoLog`, `ChangesetOperation`, conflict detection. The actual design decided later in
the same document — and used consistently throughout `ROADMAP_V2.md` — is `Revision` +
`EntityChangeRecord` (the Envers-style shape). `UndoLog`/`ChangesetOperation` appeared
nowhere else in either document; it was leftover from before that decision and never
updated when the Envers-shape rewrite happened.

**Fixed**: replaced `(UndoLog, ChangesetOperation, conflict detection)` with
`(Revision, EntityChangeRecord, conflict detection)` in the namespace section.

## Missing pieces

### 3. Shared collections have no ordering mechanism — **resolved (2026-09-30)**

Owned collections get an explicit `position` column on `entities`; non-entity collections
get an explicit `position` column on their dedicated child table
(`ownerId, position, value column(s)`). Shared collections got only *"a real pivot/join
table, many-to-many"* — no position column anywhere in either document. A common CMS need
(an ordered "related articles" or "featured tags" list) had no supported mechanism, and
Phase 3.2's "Done when" didn't test ordering either, so this would've gone unnoticed until
an actual content shape needed it.

**Decided**: the Shared-collection pivot table gets its own `position` column too, ordered
from day one like its siblings. Scoped deliberately narrow — the pivot carries `position`
and nothing else, ever; a relationship needing richer per-row metadata isn't a bare
many-to-many anymore and should be modeled as a real join-entity (an Owned collection of
small entities, each holding a Shared singular reference to the actual target), not more
columns bolted onto the pivot. Considered and rejected: always using that join-entity
pattern instead of a `position` column, to avoid a second per-field-dedicated-table
lifecycle case — rejected because it forces a manually-authored join-entity for the common
case (native code) and has no answer at all for the editor-authored, no-code path (nothing
in this design synthesizes a hidden join-entity prototype automatically), which is a worse
cost than the lifecycle question below. Also decided in the same pass: the pivot's own two
FK columns are `CASCADE` on both sides (a join row isn't an entity, gets no
`EntityChangeRecord`, so nothing worth protecting is lost when either side disappears) —
unlike `entities.owner`, which stayed `RESTRICT` per item 1 above. Folded into
`ARCHITECTURE_V2.md` ("References and collections", "FK `ON DELETE` policy") and
`ROADMAP_V2.md` (Phase 3.2).

### 4. No described mechanism for "every table that embeds shape X" (now also: every dedicated per-field table's own name/lifecycle)

Three separate features depend on enumerating every physical table where a given shape's
fields are flattened: new-field backfill ("an `#[Embed]`-propagated column... backfills
every table that embeds it"), rename propagation, and retype propagation. All three assert
the outcome ("propagates to every table that embeds it") without describing how that set
is discovered. Native classes only get a *forward* field-tree walk (for defaulted-instance
discovery), never a reverse index; editor-created schemas are data, presumably requiring a
live scan at rename/retype time — never stated. Also unclear whether this set is meant to
include the dedicated non-entity-collection child tables from "No blobs" (which flatten
the same shape by the same rules into a different landing table) or only ordinary
entity-row embeds.

**A second, related gap surfaced while resolving item 3 above, folded in here rather than
filed separately**: a Shared-collection pivot table and a non-entity-collection's dedicated
child table are both tables whose *existence and name* are tied to one specific field
declaration — unlike an ordinary column, which is what "Migrations and schema mutation"
actually describes renaming/retyping. Neither document says what happens to that dedicated
table when the field itself is renamed (does `product_tags` become `product_categories`?)
or removed (does the table get dropped?). Owned collections don't have this problem at all
— no per-field table, the item lives in its own already-independently-named CTI chain
table — so this is specific to Shared collections and non-entity collections.

**Open**: name the actual embed-table-discovery mechanism (reverse index maintained at
registration time, vs. live scan over every registered native class + every stored
editor-created schema at rename/retype time), confirm it covers non-entity-collection
child tables too, and separately decide how a Shared-collection/non-entity-collection
field's own dedicated table tracks a rename and gets dropped on field removal.

## Lower-severity / worth a note

### 5. `#[EditorExtensible]` revocation's lazy-fixup trigger is undefined for the native-side case

Revocation "falls back to `entities` directly... applied lazily on the next schema
save/fixer run." Well-defined for editor-created subclasses (they have `SchemaEditor` save
events to hang the lazy fixer off of), but a native class losing `#[EditorExtensible]` has
no analogous "schema save" event — native schema changes are reviewed migration files, not
runtime saves. What actually triggers the fixer run in that case is never said.
