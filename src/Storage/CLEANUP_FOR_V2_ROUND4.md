# Storage / Editor v2 — Cleanup Before Building (Round 4)

**Status: open.** A fourth audit pass over `ARCHITECTURE_V2.md`/`ROADMAP_V2.md`, done after
`CLEANUP_FOR_V2.md`, `CLEANUP_FOR_V2_ROUND2.md`, and `CLEANUP_FOR_V2_ROUND3.md`'s items were
folded back into both documents. 8 items found (3 surfaced mid-resolution while working
through the original 4, same as Round 3's item 2b). Ordered by how much it changes what gets
built, not alphabetically. Resolve one at a time; fold each decision back into
`ARCHITECTURE_V2.md`/`ROADMAP_V2.md` directly as it closes, same as every prior round.

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

### 3. Revision-restore's schema-change cutoff has nothing to check against

"Content undo, draft, and revision history" says restore "by default cannot reach back past
any schema change to that prototype." But "Migrations and schema mutation" retires
schema-level logging entirely — "No schema-level undo/redo, deliberately," "recoverable only
through a full database backup/restore," "there's only one undo log now, and it's
content-only." Nothing in either document introduces a record of *when* a schema mutation
happened per prototype, yet the restore guard needs exactly that to decide which
`EntityChangeRecord`s predate a change. `ROADMAP_V2.md` Phase 5.4 notes the guard "can't be
meaningfully exercised until Phase 6" but doesn't flag that the data it needs doesn't exist
either.

This is distinct from the already-deferred refinement (field-level reachability bookkeeping)
— the *conservative default* itself is listed as decided, not deferred, but has no
underlying mechanism to implement it with.

**Open**: decide what records a schema change's occurrence (a lightweight append-only
timestamp/sequence per prototype, stamped onto `EntityChangeRecord` at write time?) without
reintroducing the two-log bridging design this rewrite already retired.

### 4. Collection item-kind retype crossing the entity/non-entity boundary isn't actually covered by "treated identically to a retype"

Surfaced while double-checking that the four non-Owned collection kinds (scalar,
value-object, `#[Embed]`, Shared-`Reference`) genuinely share one retype story, per item 2's
resolution. "Changing a collection's item kind is treated identically to a retype" is stated
as one blanket rule, but ordinary retype elsewhere always assumes a stable column count (a
single `ALTER` plus a converter). That assumption breaks for a collection item-kind change
that also changes the dedicated table's shape: an `#[Embed]`-item collection (N flattened
columns) becoming a `Reference`-item collection (1 FK column) needs the converter to
*manufacture real entities* for every existing item, not just convert a column's type; a
scalar-item collection becoming an `#[Embed]`-item collection needs columns *added*, not one
retyped in place. Neither direction is worked out anywhere, and Phase 6.4's "Done when" bar
doesn't test either.

**Open**: decide whether item-kind retype across this boundary is in scope for v2 at all, and
if so, spell out the converter's actual job (produce/destroy entity rows, not just convert
values) and the table-shape change (columns added/dropped alongside the data conversion, not
a plain `ALTER`) separately from the same-shape case item 2 already covers.

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

### 6. Prototype deletion never explicitly drops that prototype's own collection fields' dedicated tables

"Prototype/class deletion drops the prototype's own table" — singular, referring to the
prototype's own CTI-chain table. It never says what happens to dedicated tables belonging to
*that prototype's own* Shared-collection or non-entity-collection fields (its own pivots/
child tables), as distinct from "removing a field drops its dedicated table," which only
covers removing a field while the prototype itself survives. Almost certainly these should
drop too — a pivot/child table only makes sense scoped to rows that are themselves being
cascade-deleted by the same operation — but it isn't written down, and an implementation
that only drops "the prototype's own table" literally would leak orphaned tables.

**Open**: state explicitly that deleting a prototype also drops every dedicated table
belonging to a field the prototype itself declares, alongside its own CTI-chain table.

## Lower-severity / worth a note

### 7. Reparenting's destructive path gets much less ceremony than everything else

"Reparenting" lets an admin (no deploy review) immediately and permanently delete a CTI
level's "now-stray data," gated only by "a warning shown in the editor UI." Compare that to
native migrations ("a human reviews generated DDL before it touches production") or manual
history pruning (its own dedicated `HistoryPermission`, framed as "real historical data, not
disposable cache... a deliberate, human-triggered action"). Reparenting causes comparably
permanent, unrecoverable (no schema-level undo) data loss but gets neither a named permission
check nor anything beyond a UI warning.

**Open**: confirm this is intentional (a UI warning is enough because stray-level data isn't
"real" history the way content history is) or decide reparenting-with-data-loss should sit
behind `SchemaPermission` plus something more than a warning.

### 8. The Shared-collection pivot and the "No blobs" child table are never named as one shared mechanism

Surfaced from a reader's question, not a direct audit find: a Shared-collection pivot and a
non-entity-collection's dedicated child table are structurally the same
`(position, value column(s))` shape, with `value column(s)` just varying by item kind (one
target-FK column vs. one scalar/value-object column vs. the embedded shape's own flattened
columns). "No blobs" introduces one, "References and collections" introduces the other,
separately, and nothing ever states the equivalence outright — later sections (rename,
`NoType`) treat them consistently in passing, but a reader has to notice the pattern rather
than being told it, unlike nearly everything else in this document, which calls out shared
mechanisms explicitly.

**Open**: add one sentence, probably in "References and collections," naming the Shared
pivot as the same `(position, value column(s))` shape "No blobs" already established, rather
than introducing it as an unrelated mechanism that happens to end up treated the same way
later.
