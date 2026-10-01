# Storage / Editor v2 — Cleanup Before Building (Round 4)

**Status: open.** A fourth audit pass over `ARCHITECTURE_V2.md`/`ROADMAP_V2.md`, done after
`CLEANUP_FOR_V2.md`, `CLEANUP_FOR_V2_ROUND2.md`, and `CLEANUP_FOR_V2_ROUND3.md`'s items were
folded back into both documents. 4 items found. Ordered by how much it changes what gets
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

### 2. The Shared-collection pivot's "nothing else, ever" rule contradicts `NoType` conversion

"References and collections" states the Shared-collection pivot "carries `position` and
nothing else, ever... instead of growing more columns onto the pivot" — phrased as an
absolute rule, same tone as "No blobs."

"Migrations and schema mutation"'s `NoType` section then has a `Reference` used as a
`Collection`'s item kind add exactly that: "the same blob, per existing item, lands on a new
column added to that collection's own dedicated table (the Shared pivot, carrying `position`
already)." `ROADMAP_V2.md` Phase 6.4's "Done when" bar bakes this into an acceptance test, so
it isn't just prose, it's load-bearing.

Telling detail: when "No blobs" itself gets a narrow exception for `NoType`, the document
explicitly calls it out ("a deliberate, narrow exception to 'No blobs' above"). The pivot's
"nothing else, ever" rule gets no equivalent callout before being violated — reads as missed,
not an accepted tradeoff.

**Open**: add the same explicit-exception language to the pivot rule that "No blobs"
already got, naming `NoType` conversion as the one case that adds a column to a pivot table.

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

## Lower-severity / worth a note

### 4. Reparenting's destructive path gets much less ceremony than everything else

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
