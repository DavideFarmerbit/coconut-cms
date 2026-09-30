# Storage / Editor v2 — Cleanup Before Building (Round 2)

**Status: in progress (2026-09-30) — items 1-4 resolved and folded into
`ARCHITECTURE_V2.md`/`ROADMAP_V2.md`; items 5-6 partially decided, paused mid-discussion.**
A second audit pass over `ARCHITECTURE_V2.md`/`ROADMAP_V2.md`, done after
`CLEANUP_FOR_V2.md`'s 20 items were folded back into both documents. This is a punch list,
not a spec. Ordered by how much it changes what gets built, not alphabetically.

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

### 4. No described mechanism for "every table that embeds shape X" (now also: every dedicated per-field table's own name/lifecycle) — **resolved (2026-09-30)**

Three separate features depend on enumerating every physical table where a given shape's
fields are flattened: new-field backfill ("an `#[Embed]`-propagated column... backfills
every table that embeds it"), rename propagation, and retype propagation. All three
asserted the outcome ("propagates to every table that embeds it") without describing how
that set is discovered. Native classes only got a *forward* field-tree walk (for
defaulted-instance discovery), never a reverse index; editor-created schemas are data,
presumably requiring a live scan at rename/retype time — never stated. Also unclear
whether this set was meant to include the dedicated non-entity-collection child tables
from "No blobs" (which flatten the same shape by the same rules into a different landing
table) or only ordinary entity-row embeds.

**A second, related gap surfaced while resolving item 3 above, folded in here rather than
filed separately**: a Shared-collection pivot table and a non-entity-collection's dedicated
child table are both tables whose *existence and name* are tied to one specific field
declaration — unlike an ordinary column, which is what "Migrations and schema mutation"
actually describes renaming/retyping. Neither document said what happens to that dedicated
table when the field itself is renamed (does `product_tags` become `product_categories`?)
or removed (does the table get dropped?). Owned collections don't have this problem at all
— no per-field table, the item lives in its own already-independently-named CTI chain
table — so this is specific to Shared collections and non-entity collections. An initial
draft resolution proposed making these dedicated table names *immutable* once created
(reasoning from `#[Table]`'s explicit-pin escape hatch) — wrong, caught in review: an
entity's own table name is *only* immutable when `#[Table]` is explicitly set; the
derived-short-name fallback is fully coupled to the current class name and is expected to
rename right along with it (that's the entire reason the rename mechanism takes an
explicit mapping instead of inferring from a diff — so it can emit an `ALTER TABLE ...
RENAME` instead of a data-losing drop+create).

**Decided**: for discovery, native classes get the reverse edge as a byproduct of
`EntityRegistrar::register()`'s existing forward walk (no new scan); editor-created
schemas reuse the same live-scan the dangling-reference auditing tool already performs,
checking a different predicate. Non-entity-collection child tables are confirmed to be
found by this same walk/scan, not a separate mechanism. For the dedicated-table
name/lifecycle question: these tables are derived names, exactly like an entity's own
fallback-derived table name, not pinned ones — a field rename renames its own dedicated
table through the same explicit-mapping mechanism, and a prototype-level rename (when its
own table name is itself derived) automatically fans out to every dedicated table its
fields derive a name from, the same one-explicit-trigger/mechanically-computed-consequences
pattern already used for Embed-target column propagation. Field removal drops the
dedicated table outright (native: whatever `Comparator` produces once the field
disappears, reviewed like any other DDL; editor-created: a `SchemaEditor` operation with
`dropColumn()`'s existing safe-DDL-only scoping, at table granularity). Folded into
`ARCHITECTURE_V2.md` ("Migrations and schema mutation") and `ROADMAP_V2.md` (Phase 2,
Phase 6.2, Phase 6.4).

## Lower-severity / worth a note

### 5. `#[EditorExtensible]` revocation's lazy-fixup trigger is undefined for the native-side case — **partially decided, in progress (2026-09-30)**

Revocation "falls back to `entities` directly... applied lazily on the next schema
save/fixer run." Well-defined for editor-created subclasses (they have `SchemaEditor` save
events to hang the lazy fixer off of), but a native class losing `#[EditorExtensible]` has
no analogous "schema save" event — native schema changes are reviewed migration files, not
runtime saves. What actually triggers the fixer run in that case was never said.

**Decided so far**: native-triggered and editor-created-triggered revocation are two
separate passes, not one shared mechanism (an earlier draft proposed routing both through
the dangling-reference auditing tool — superseded by this split, which maps onto a
distinction the doc already relies on elsewhere: native changes are reviewed/deploy-gated,
editor-created changes are live/unreviewed).

- **Native-triggered** (a class loses `#[EditorExtensible]`, or is deleted): the mechanical
  reparent-to-`entities` for every affected direct subclass is applied automatically as
  part of the deploy step, since the triggering change was already human-reviewed there.
  It also leaves a "missing parent, needs review" marker — the deploy fixes the plumbing so
  the app keeps working, it doesn't make the final call on whether `entities`-direct is the
  actually-correct long-term parent.
- **Editor-created-triggered** (an admin deletes a prototype with live editor-created
  subclasses): stays broken until manually fixed, deliberately, matching the "no automatic
  fixing, ever" posture used everywhere else in this design. The stored `prototypes.parent`
  value is kept exactly as-is, never silently rewritten — at runtime the chain resolves as
  if rooted directly at `entities` (so the app doesn't hard-crash), meaning any fields that
  lived only on the now-vanished parent level become inaccessible until fixed. The editor
  UI shows the old parent's name, flagged invalid (e.g. rendered in red) rather than
  blanked out, so the admin knows what broke. `SchemaEditor` blocks saving this subclass's
  schema until a valid parent is explicitly set.

**Still open**:
- Does "saving is prevented" for the editor-created case mean only *schema* saves are
  blocked (working assumption: ordinary content reads/writes on this subclass's
  still-resolving fields keep working normally through `Changeset`, same as any other
  entity), or does it also block ordinary content read/write until the parent is fixed?
  Needs explicit confirmation either way.
- What the native-triggered "missing parent, needs review" marker actually looks like and
  where an admin acts on it (accept the `entities`-direct fallback as final vs. pick a
  different parent) isn't designed yet.

### 6. Dangling field-type targets: Value Objects were missing from the auditing scope, and the runtime behavior of a broken field is undecided — **open, in progress (2026-09-30)**

Surfaced while resolving item 5. The existing "dangling reference/embed/collection
target" auditing category (under "Prototype/class deletion") only lists
reference/embed/collection targets — it misses a fourth, structurally identical case:
`valueObject()`'s custom `Type` class can also be deleted (a developer removes a custom
type like `IBAN` or `Money` that some editor-created field still declares), leaving that
field's declared type dangling exactly the same way a missing `#[Reference]`/`#[Embed]`
target does. Scalars (`int`, `string`, `bool`, ...) are *not* at risk — they're PHP
built-ins, nothing user-defined to delete.

**Decided so far**: fold Value Object custom-type-class deletion into the same auditing
tool's scanned-breakage list, alongside reference/embed/collection target and
parent-revoked/parent-deleted.

**Still open, explicitly deferred for its own discussion**: what actually happens, at
read/write time, to a field whose declared type is broken (Reference, Embed, Collection
item kind, or now Value Object) before an admin fixes it — fail loudly (reject reads/writes
of that specific field, matching the "fail loudly, never silently" posture used everywhere
else in this design) versus degrade gracefully (expose the raw stored value in some
reduced/unvalidated form so an admin isn't looking at a totally inaccessible record).
Nothing is decided here yet.
