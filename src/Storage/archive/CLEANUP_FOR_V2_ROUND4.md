# Storage / Editor v2 — Cleanup Before Building (Round 4)

**Status: resolved (2026-10-01).** A fourth audit pass over
`ARCHITECTURE_V2.md`/`ROADMAP_V2.md`, done after `CLEANUP_FOR_V2.md`,
`CLEANUP_FOR_V2_ROUND2.md`, and `CLEANUP_FOR_V2_ROUND3.md`'s items were folded back into both
documents. 11 items found (7 surfaced mid-resolution while working through the original 4,
same as Round 3's item 2b) — all 11 have since been decided and folded back into
`ARCHITECTURE_V2.md`/`ROADMAP_V2.md` directly. This file is kept only as a historical record
of that audit pass and the discussion behind each decision, not as an open task list.
Ordered by how much it changes what gets built, not alphabetically.

## Real inconsistencies

### 1. No stated mechanism for giving an editor-created prototype a Reference/Embed/Collection/Owned field at all — **resolved (2026-10-01)**

`ARCHITECTURE_V2.md` ("Migrations and schema mutation") declared `SchemaEditor`'s mutation
set *closed*: "add a nullable column, drop a column, rename a field, retype a field...
Nothing else, ever; this is enforced by `SchemaEditor` exposing no other mutation method,
not just by policy." The `queryable` discussion ("No blobs") reinforces this same closed set
as the reason an index-toggle can't exist yet.

But `ROADMAP_V2.md` Phase 6.1 scoped prototype *creation* to "scalar fields only, no parent
complexity yet," and no later phase ever introduced an operation for attaching a `Reference`,
`Embed`, `Collection`, or `OwningReference` field to an editor-created prototype. Yet Phase
6.2's "Done when" bar assumed these already existed to mutate: "retargeting a `Reference`
field to a different target type," "renaming a Shared-collection... field... renames its own
dedicated table," "renaming a field that declares an `OwningReference`." A `Collection` needs
a whole new pivot/child table; an `OwningReference` needs *no* column at all — neither fits
"add a nullable column." Since content types with no PHP class behind them are the stated
goal of the whole system ("The goal"), this is core functionality with no written path to
exist, not an edge case.

Working through this clarified that no new architectural decision was actually needed: "Shape
comes from a neutral descriptor" already means editor-schema storage has to represent any
`FieldDescriptor` kind, since the downstream pipeline can't tell or care where a shape came
from. `SchemaEditor` is the live, unreviewed counterpart to a native class's own
attribute-declared fields — it has to support every kind a PHP class can declare, or
admin-authored content types permanently fall short of "assembled entirely in the editor."
So the actual gap was narrower than it first looked: (a) the closed-set wording was phrased
as if every field were a single column, and (b) the roadmap never wrote down the build step
for adding a non-scalar field at all, even though 6.2 already tested mutating one.

**Decided**: generalize `SchemaEditor`'s closed set from column-level to field-level — `add a
field`/`drop a field`/`rename a field`/`retype a field`, where adding a field's physical
shape is a pure function of its kind (a column for scalar/value-object/singular-`Reference`/
`Embed`-flattening, a dedicated pivot/child table for a `Collection`, no physical change
beyond the registry record for an `OwningReference` or Owned `Collection`) — still exactly
four operations, not one per kind. Folded into `ARCHITECTURE_V2.md` ("Migrations and schema
mutation") and `ROADMAP_V2.md` (Phase 6.1's bullet now notes non-scalar addition is deferred
to 6.2 on purpose; Phase 6.2's `addColumn()`/`dropColumn()` renamed to `addField()`/
`dropField()` with the kind-to-shape mapping spelled out, and its "Done when" bar now tests
adding a field of any kind, not just mutating one assumed to already exist).

### 2. The Shared-collection pivot's "nothing else, ever" rule reads as contradicting `NoType` conversion — **resolved (2026-10-01)**

"References and collections" states the Shared-collection pivot "carries `position` and
nothing else, ever... instead of growing more columns onto the pivot" — phrased as an
absolute rule, same tone as "No blobs." "Migrations and schema mutation"'s `NoType` section
then has a `Reference` used as a `Collection`'s item kind add exactly that: "the same blob,
per existing item, lands on a new column added to that collection's own dedicated table (the
Shared pivot, carrying `position` already)." Read against each other, this looked like the
same missed-exception pattern as item 1: an absolute rule stated once, then quietly violated
later without the callout "No blobs" gave its own `NoType` exception.

Turned out to be imprecise wording, not an actual contradiction. A Shared collection's items
are always `Reference`s, so the pivot's one non-`position` column was never "extra metadata"
in the first place — it's the item kind's own value representation, the same
`(position, value column(s))` shape the non-entity-collection dedicated table already uses
for scalar/embed items. `NoType`'s "new column added... replacing the dropped FK column" is a
1-for-1 swap of that single value column's type (triggered by an ordinary collection
item-kind retype, a mechanism already documented elsewhere), never a second column added
alongside it — column count never grows. The "nothing else, ever" rule was always about
disallowing a *second*, unrelated metadata column (a note, a date); it was never about
freezing the one value column's type.

**Decided**: no exception needed, just precision. Reworded the pivot's own rule to name the
target-FK column explicitly ("`position` and exactly one target-FK column, nothing else,
ever") and to state directly that this one column's type can still change like any other
field's on retype, `NoType` included, without that ever counting as "growing" what the pivot
carries. Folded into `ARCHITECTURE_V2.md` ("References and collections"); no `ROADMAP_V2.md`
change needed, since Phase 6.4's existing done-when test was already correct, only the
architecture's own wording was ambiguous.

## Missing pieces

### 3. Revision-restore's schema-change cutoff has nothing to check against — **resolved (2026-10-01)**

"Content undo, draft, and revision history" says restore "by default cannot reach back past
any schema change to that prototype." But "Migrations and schema mutation" retires
schema-level logging entirely — "No schema-level undo/redo, deliberately," "recoverable only
through a full database backup/restore," "there's only one undo log now, and it's
content-only." Nothing in either document introduced a record of *when* a schema mutation
happened per prototype, yet the restore guard needs exactly that to decide which
`EntityChangeRecord`s predate a change. `ROADMAP_V2.md` Phase 5.4 noted the guard "can't be
meaningfully exercised until Phase 6" but didn't flag that the data it needs doesn't exist
either.

A first candidate fix (a `schema_version` counter *per prototype*) ran into a bigger problem
on review: schema mutations routinely affect more than the one prototype named in the call —
an `#[Embed]` rename fans out to every embedding table's own columns, an `OwningReference`
rename fixes up `entities.owner_field` on the owning side, prototype-level rename touches
every other prototype's stored `reference()`/`embed()`/`collection()` pointers. Scoping the
counter per prototype means correctly enumerating every propagation path a mutation might
reach — exactly the bookkeeping the document already defers as a separate, later refinement
("bookkeeping exactly what a given schema operation touched"), not something the conservative
default should need to get right first.

**Decided**: one single **global**, monotonically-incrementing `schema_version` (the same
monotonic-sequence idiom `Revision` already uses) instead of one per prototype. Every schema
mutation of any kind, native or editor-created, anywhere, bumps it. Every
`EntityChangeRecord` is stamped with the current value at write time; restore refuses by
default unless a chosen record's stamped version still matches the current one. Deliberately
coarser than strictly necessary — it can't miss a propagation path because it doesn't need
to know propagation paths exist — consistent with the document's own framing that the
current default isn't yet scoped to "exactly what was touched" (that precision is the named,
already-deferred refinement). Folded into `ARCHITECTURE_V2.md` ("Content undo, draft, and
revision history") and `ROADMAP_V2.md` (Phase 5.4 builds the sequence and the
stamping/comparison; Phase 6's intro now notes every mutation introduced there bumps it,
exercising the guard end to end for the first time).

### 4. Collection item-kind retype crossing the entity/non-entity boundary isn't actually covered by "treated identically to a retype" — **resolved (2026-10-01)**

Surfaced while double-checking that the four non-Owned collection kinds (scalar,
value-object, `#[Embed]`, Shared-`Reference`) genuinely share one retype story, per item 2's
resolution. "Changing a collection's item kind is treated identically to a retype" is stated
as one blanket rule, but ordinary retype elsewhere always assumes a stable column count (a
single `ALTER` plus a converter). That assumption breaks for a collection item-kind change
that also changes the dedicated table's shape: an `#[Embed]`-item collection (N flattened
columns) becoming a `Reference`-item collection (1 FK column) needs the converter to
*manufacture real entities* for every existing item, not just convert a column's type; a
scalar-item collection becoming an `#[Embed]`-item collection needs columns *added*, not one
retyped in place. Neither direction was worked out anywhere, and Phase 6.4's "Done when" bar
didn't test either.

Turned out to need no new mechanism, just composing two things already decided elsewhere. A
collection's dedicated table already carries whichever value column(s) its item kind needs —
one for scalar/value-object, the embedded shape's own flattened columns for `#[Embed]`, one
target-FK column for `Reference` — so a retype that changes item kind can drop the old value
column(s) and add the new ones, same `ALTER`-plus-converter posture as any other retype, just
not always a same-column swap. Crossing into/out of `Reference` specifically is the same
umbrella as retargeting a singular `Reference` field's own type, generalized: the converter
produces a found-or-created entity on the way in, or consumes the existing referenced entity
on the way out — exactly what the original retype sentence's own example already implied
("each value-object item becomes a newly-created entity row") without ever spelling out the
mechanics.

**Decided**: no new mechanism — state explicitly that item-kind retype can change the
dedicated table's column count (not just one column's type), and that crossing into/out of
`Reference` reuses the Reference-retargeting converter shape already described, generalized
from entity-to-entity to any-value-to-entity. Folded into `ARCHITECTURE_V2.md` ("Migrations
and schema mutation") and `ROADMAP_V2.md` (Phase 6.2's "Done when" bar, as its own case
alongside the same-shape item-kind retype).

**Correction (2026-10-01, superseded by item 6 below)**: this item originally scoped Owned
collection item-kind change out as "the same shape of problem as Flipping Owned/Shared" —
wrong, caught while working through item 6. An Owned item's own type changing is just the
same capture/reconstruct retype applied to an Owned entity instead of a dedicated-table row;
it was never actually coupled to Flipping Owned/Shared's real hard part (identity
preservation). See item 6.

### 6. Retype, fully generalized: one capture-to-`NoType`/reconstruct-from-`NoType` mechanism, closing "Flipping Owned/Shared" entirely — **resolved (2026-10-01)**

Surfaced from three different threads converging: a reader question about whether
`OwningReference` retype was really unsolved, a re-check of item 4's own Owned-collection
carve-out, and a direct request to audit every retype scenario for remaining special cases.
Three gaps turned out to be the same gap:

- `OwningReference`/Owned-`Collection` retargeting its own live item type while staying
  Owned (`Warranty` → `Guarantee`) was never written into either document.
- `NoType` repairing back into a live `OwningReference`/Owned-`Collection` was explicitly
  marked "the same shape of problem as Flipping Owned/Shared" — on inspection, wrong: that
  framing assumed identity preservation was required, but re-adopting a live Owned
  relationship from a `NoType` blob is *manufacturing a new entity from captured data*, the
  same entity-manufacturing converter pattern item 4 already established for crossing into
  `Reference`, not a re-routing of an already-live target's own identity.
- "Flipping an existing relationship between Owned and Shared" itself, still listed in
  "Deferred," turned out to only look hard under the same unstated assumption: that the
  *same* row's identity had to survive the swap. Dropping that assumption (fork a new
  entity, or none, via the converter; leave the old side exactly as its own kind's capture
  already handles it — untouched if Shared since other things may still reference it,
  ordinarily cascade-deleted if Owned since nothing else legitimately could) makes it fully
  mechanical with no new machinery.

**Decided**: every retype is now stated as one shape — capture the old kind to `NoType`
(always the same fixed, already-specified logic, run identically whether triggered by a
deliberate retype or an upstream deletion), then reconstruct the new kind from that `NoType`
blob via an explicit, admin/developer-supplied converter. This subsumes Reference
retargeting, collection item-kind crossing, Owned-to-Owned retargeting, and Owned↔Shared/
`#[Embed]` crossing as the same two-step mechanism, not four separate ones. Two added rules
make the Owned-crossing cases safe by construction rather than by convention:
  - **The framework never supplies a default that copies data across or forks a duplicate
    entity** for Owned↔Shared/`#[Embed]` crossing specifically — only the converter the
    admin/developer writes can choose to do that. Prevents exactly the two failure modes
    flagged mid-discussion: silently forking a Shared duplicate of owned data, or silently
    deleting/relabeling a Shared row that might have other referrers.
  - **Required vs. optional governs whether the converter must return something, uniformly
    for `Reference` and `OwningReference`/Owned-`Collection` alike** — ordinary field
    nullability, not a special Owned rule. Optional accepts a null result (no FK, no owned
    row created); required needs a valid result (a defaulted instance counts) or the retype
    fails loudly.

Also fixed: `NoType` capture of an `OwningReference`/Owned-`Collection`-item now explicitly
recurses into any further-nested `OwningReference`/Owned-`Collection` field found in the
captured subtree, to any depth, reusing the same `owner`/`owner_field` lookup the
Owned-subtree-expansion delete path already walks — without this, a nested owned row one
level deeper than the field being retyped would be silently destroyed by the same
cascade-delete with no trace, while the top-level row's own data survived in the blob, an
inconsistency caught while working through the recursion depth. Confirmed this can never
apply to `#[Embed]`'s own flattening, since an `#[Embed]` target's field tree already
excludes `Reference`/`Collection` at any depth.

**"Flipping an existing relationship between Owned and Shared" is removed from "Deferred"
entirely**, not narrowed — nothing about it remains unsolved once identity preservation is
off the table, and the converter is free to leave the new side empty or absent regardless.
Folded into `ARCHITECTURE_V2.md` ("Migrations and schema mutation," the `NoType` section,
and "Deferred") and `ROADMAP_V2.md` (Phase 6.2's `retype()` bullet and "Done when" bar).

### 5. Changing a field's cardinality (`Collection` ↔ singular) — **resolved (2026-10-01)**

Also surfaced while re-checking the collection-mechanism unification: nothing in either
document said what happens when a field stops being a `Collection` (or a singular field
becomes one) — not even listed in "Deferred." Worth distinguishing sharply from the
deletion-triggered `NoType` path, which was already decided to *preserve* cardinality
("`NoType`'s storage shape is... a column for singular, a dedicated table for a collection,
never of which kind of field it used to be") — a first draft of this fix conflated the two,
which would have silently reversed that already-resolved invariant.

**Decided**: cardinality change is a deliberate retype, not a side effect of anything else,
and entirely separate from the `NoType`/deletion path (which never changes cardinality).
Collapsing a `Collection` into a singular field needs an explicit converter that picks or
combines the field's existing items into one value, same "no attempt to guess, refuse loudly"
posture as any other retype; its dedicated table drops once the converter's consumed it.
Expanding a singular field into a `Collection` needs an explicit converter that decides how
to produce items from the one existing value, landing in a freshly created dedicated table.
No new mechanism either way — an `ALTER` plus a converter, same as every other retype, just
one where the physical shape changes from a column to a table or back. Folded into
`ARCHITECTURE_V2.md` ("Migrations and schema mutation") and `ROADMAP_V2.md` (Phase 6.2's
"Done when" bar, as its own case distinct from `NoType` conversion).

### 7. `Reference` was wrongly allowed to be "required," and retype converters were wrongly treated as mandatory and per-item — **resolved (2026-10-01)**

Surfaced from a close read of item 6's own wording, working outward from the "C++ `MyClass`
vs `MyClass*`" framing already used for required/optional. Five compounding corrections to
material that predates this cleanup round (some of it predates this whole rewrite):

- **A `Reference` (Shared) can never be required, full stop** — it's pointer semantics, not
  value semantics, so there's no sense in which a "required but missing" state could even
  exist. `RESTRICT` was never actually a reachable policy for a Shared target-FK; `SET NULL`
  is the only one. This directly contradicted "FK `ON DELETE` policy," which stated
  `RESTRICT` as the Shared default, "References and collections," which stated `RESTRICT`
  for singular `SharedReference`, and `ROADMAP_V2.md` Phase 3.2, which tested a
  `RESTRICT`-protected case that should never have existed. Also invalidated Phase 3.3's
  claim that the defaulted-instance completeness walk covers "every reachable `#[Reference]`
  target" — it should only ever have covered `#[Embed]` and `OwningReference`/
  Owned-`Collection` targets, both locally manufacturable; a Shared target never needs one.
- **A Shared collection's pivot table doesn't get one `CASCADE` rule for both FKs.** The
  owner-side FK is `CASCADE` (unchanged, same semantic every table-based collection already
  has). The target-side FK is `SET NULL`, not `CASCADE` — cascading would delete the pivot
  row itself, losing the slot and silently shrinking the collection's true count, exactly
  the problem a surviving null-FK row exists to prevent.
- **Supplying a retype converter is always optional, never mandatory.** "Required" never
  meant "a converter must be given" — it means "something valid must exist afterward," and
  the automatic fallback when no converter is supplied is the field's own class default
  (`#[DefaultInstance]` resolution), the same mechanism an ordinary new-field backfill
  already uses. A converter only earns its keep when the new value should be derived from
  the old one.
- **A collection retype's converter takes the whole captured array as input and returns a
  new array of any length** — never a forced one-call-per-item mapping. It may filter,
  merge, or expand; "no converter" re-defaults the whole collection from scratch rather than
  touching the existing items at all. This corrects "runs its required converter per
  existing item" language in both item 4's and item 6's own resolutions above.
- **`#[Embed]` retype always needs something to land** — the same "required" posture as any
  shape that must already carry a `#[DefaultInstance]` to qualify as an Embed target at all;
  there's no optional/absent case for `#[Embed]`, unlike `Reference` (never required) or
  `OwningReference`/Owned-`Collection` (required or optional, field's own choice).

**Decided**: all five, as stated. Folded into `ARCHITECTURE_V2.md` ("References and
collections," "FK `ON DELETE` policy," "Defaulted instances," and the retype-generalization
section) and `ROADMAP_V2.md` (Phase 3.2, Phase 3.3, Phase 6.2's bullet and both "Done when"
paragraphs).

### 8. Owned-collection item slots need an explicit, stored count — **resolved (2026-10-01)**

Surfaced from the Unreal `TArray<Instanced> UObject*` pattern: an Owned collection whose
item kind is *optional* can have a legitimately empty slot, and an empty slot leaves no row
at all in `entities` (same rule as a singular optional `OwningReference` creating no row
when absent, just applied per slot). That means `COUNT(*) FROM entities WHERE owner = ? AND
owner_field = ?` undercounts the true slot count the moment any slot is empty — a 2-item
dense array and a 3-slot array with one empty middle slot look identical from `entities`
alone, since position values stop uniquely encoding structure once gaps are legitimate.
Shared collections never have this problem (a pivot row survives with a null FK, see item 7,
so `COUNT(*)` on the pivot is always accurate) and neither do non-entity collections (their
dedicated-table row always exists regardless of whether its value is null) — it's unique to
Owned, which has no table of its own to count rows from at all.

**Decided**: every Owned-collection field gets a `<field>_count` column on the *owner's* own
declaring table, uniformly for required and optional item kinds alike — no special-casing,
since the column is free and a single uniform rule is simpler than conditioning it on the
item kind's own required/optional status. This is what makes "remove this slot" (count
shrinks, later positions shift down) and "clear this slot's content" (count and every
position untouched, the slot survives, just empty) distinguishable operations instead of one
conflated operation. Folded into `ARCHITECTURE_V2.md` ("References and collections") and
`ROADMAP_V2.md` (Phase 3.3, bullet and "Done when").

### 9. Prototype deletion never explicitly drops that prototype's own collection fields' dedicated tables — **resolved (2026-10-01)**

"Prototype/class deletion drops the prototype's own table" — singular, referring to the
prototype's own CTI-chain table. It never said what happens to dedicated tables belonging to
*that prototype's own* Shared-collection or non-entity-collection fields (its own pivots/
child tables), as distinct from "removing a field drops its dedicated table," which only
covers removing a field while the prototype itself survives. Almost certainly these should
drop too — a pivot/child table only makes sense scoped to rows that are themselves being
cascade-deleted by the same operation — but it wasn't written down, and an implementation
that only dropped "the prototype's own table" literally would leak orphaned tables.

Double-checked the companion question while resolving this: does *renaming* a prototype
already handle its own pivot/child tables? Yes — "renaming the declaring prototype... fans
out to every dedicated table its own fields derive a name from" was already stated. That
check surfaced one real gap it exposed in passing, though: the rename paragraph for an Owned
relationship only mentioned updating `entities.owner_field`, never the field's own
`<field>_count` column (item 8) — a real column on the owner's own table that needs an
ordinary rename alongside it, which nothing said until now.

**Decided**: deletion is the same fan-out rename already uses, dropping instead of renaming —
a prototype's own table, plus every dedicated table a field it declares owns, all go
together. Folded into `ARCHITECTURE_V2.md` ("Migrations and schema mutation," both the
deletion paragraph and the Owned-relationship rename paragraph for the `<field>_count` fix)
and `ROADMAP_V2.md` (Phase 6.2's and Phase 6.4's "Done when" bars).

## Lower-severity / worth a note

### 10. Reparenting's destructive path gets much less ceremony than everything else — **resolved (2026-10-01)**

"Reparenting" lets an admin (no deploy review) immediately and permanently delete a CTI
level's "now-stray data," gated only by "a warning shown in the editor UI." Compared against
manual history pruning (its own dedicated `HistoryPermission`, "real historical data, not
disposable cache"), this looked under-ceremonied. That turned out to be the wrong baseline —
pruning destroys deliberately-preserved content history, which this stray data never was.
The right comparison is prototype deletion, which is at least as destructive (loses a whole
class's worth of data) and gets the identical treatment: ordinary `SchemaPermission`, no
extra ceremony named anywhere. By that comparison reparenting was already consistent, not
under-ceremonied.

That comparison surfaced a real asymmetry anyway, just a different one, and the reasoning
needed correcting twice before landing right. First pass: prototype deletion's
cascade-delete explicitly runs through the ordinary `Changeset` path, so every row it
destroys gets its own `EntityChangeRecord`; reparenting's stray-row removal wasn't treated
that way, called out as happening immediately, "not deferred to the manual pruning tool,"
reasoned as "a duplicate of current state," which undersells it — those were live field
values, genuinely destroyed, structurally identical to any other entity deletion. Second
pass, after the first fix was challenged: the motivation for routing it through the
ordinary path got framed as data-safety ("this makes the data recoverable"). It isn't, for
either operation, and that was never the real reason to use that path. The actual reason,
for both prototype deletion and reparenting alike, is structural: reuse the existing
topological-sort/Owned-subtree-expansion machinery instead of building a second, separate
bulk-delete mechanism that would have to re-solve the same dependency-ordering problem on
its own.

Whether the resulting `EntityChangeRecord`s are "recoverable" turned out to be moot besides:
neither undo mechanism can ever reach them. Ctrl+Z only ever targets a `Revision` referenced
by a `RemoteCommand` on the initiating client's own command stack, and a `RemoteCommand` is
only ever pushed by an ordinary content-editing action, never by a `SchemaEditor` operation
or a native migration — nothing ever puts these `Revision`s within Ctrl+Z's reach to begin
with. "Revision-history restore" is independently blocked from reaching back past them
anyway by the global `schema_version` cutoff (item 6), since both operations are schema
mutations that bump it.

**Decided**: route both prototype deletion's cascade-delete and reparenting's stray-row
deletion through the ordinary `Changeset` path, for the structural/consistency reason
alone — not framed as a safety net, because it only ever functions as one in the narrowest
sense: the data stays inspectable as forensic `EntityChangeRecord` history (a human can
read it and act on it manually) without that record ever being a path back to the
pre-mutation state through either undo mechanism, both of which are independently closed
off regardless. Folded into `ARCHITECTURE_V2.md` ("Reparenting") and `ROADMAP_V2.md`
(Phase 6.3's bullet and "Done when").

### 11. The Shared-collection pivot and the "No blobs" child table are never named as one shared mechanism — **resolved (2026-10-01)**

Surfaced from a reader's question, not a direct audit find: a Shared-collection pivot and a
non-entity-collection's dedicated child table are structurally the same
`(position, value column(s))` shape, with `value column(s)` just varying by item kind (one
target-FK column vs. one scalar/value-object column vs. the embedded shape's own flattened
columns). "No blobs" introduces one, "References and collections" introduces the other,
separately, and nothing ever states the equivalence outright — later sections (rename,
`NoType`) treat them consistently in passing, but a reader has to notice the pattern rather
than being told it, unlike nearly everything else in this document, which calls out shared
mechanisms explicitly.

Before writing the fix, re-verified the equivalence actually still holds after everything
else decided this round (the pivot's target-FK is now always nullable/`SET NULL` rather than
`CASCADE`, the owner-side FK split, `NoType` conversion, count-tracking) — it does, across
every dimension touched: both `CASCADE` the same way on the owner side, both derive/rename/
drop their table name the same way, both convert their value column(s) to `NoType` the same
way, and neither needs the Owned-only count column since a slot's row always exists in both,
value-nullness aside.

**Decided**: name the equivalence explicitly in both directions. Folded into
`ARCHITECTURE_V2.md` — "No blobs" now states the Shared pivot is this exact same
`(position, value column(s))` shape at the point the non-entity table is introduced, and
"References and collections" now opens the Shared-collection bullet by naming it as that
established shape (one target-FK column as its `value column(s)`) rather than introducing it
as if it were unrelated, with a one-line reminder that every existing rule (naming, rename,
drop, `NoType` conversion) already carries over unchanged. No `ROADMAP_V2.md` change
needed — this was purely a documentation-clarity gap, nothing behavioral to test
differently.
