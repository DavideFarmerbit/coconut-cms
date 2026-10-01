# Storage / Editor v2 — Cleanup Before Building (Round 3)

**Status: resolved (2026-10-01).** A third audit pass over
`ARCHITECTURE_V2.md`/`ROADMAP_V2.md`, done after `CLEANUP_FOR_V2.md` and
`CLEANUP_FOR_V2_ROUND2.md`'s items were folded back into both documents. All items below
(8 found, plus item 2b split out from item 2 during resolution) have since been decided
and folded back into `ARCHITECTURE_V2.md`/`ROADMAP_V2.md` directly — this file is kept
only as a historical record of that audit pass, not as an open task list. Ordered by how
much it changes what gets built, not alphabetically.

## Real inconsistencies

### 1. `owner_field` goes stale on an Owned-relationship field rename — **resolved (2026-10-01)**

This was explicitly flagged in the old v1 design as "Step C Fix 1" and deferred, never
built: `entities.owner_field` stores the name of the field on the *owner's* class that
declares an Owned relationship (e.g. `currentPricing`). "Migrations and schema mutation"
fixes up `owner_field` values during a *prototype*-level rename (renaming the owned
*type*), and separately handles `#[Embed]`-field rename propagation — but never addresses
renaming the *field itself* on the owner's class (e.g. `Product.currentPricing` →
`Product.pricing`), which is exactly what `owner_field` encodes. Given v2's rename
mechanism is otherwise built to be rigorous about precisely this class of problem
(explicit mapping, propagation to every affected site), and the issue has known prior
history, this reads as an oversight — it isn't even carried into the "Deferred" section.

**Decided**: reuse the existing explicit-mapping rename mechanism. Renaming a field that
declares an `OwningReference` or an Owned `Collection` runs an `UPDATE entities SET
owner_field = ? WHERE owner = ? AND owner_field = ?` scoped to that relationship's
existing rows, alongside the rename — not a column `ALTER` (an Owned relationship has no
column of its own), the same no-column case "References and collections" already
describes. Folded into `ARCHITECTURE_V2.md` ("Migrations and schema mutation") and
`ROADMAP_V2.md` (Phase 6.2).

### 2. `NoType` names "Collection-item" as a kind it converts, but never works out that case — **resolved (2026-10-01)**

The sentence introducing `NoType` lists "a `Reference`, `Embed`, `Collection`-item, or
Value-Object field" as the four kinds that convert — but the very next sentence's worked
examples (what the blob captures) only cover three: Reference, Embed, Value Object.
Collection-item is dropped. This isn't just a missed sentence: `NoType` is "structurally a
Value Object" — one column's worth of blob — but a Collection lives in a pivot table
(Shared) or a dedicated child table (non-entity), never a column on the owner's own row.
It's unclear what "converting a Collection-item field to `NoType`" means mechanically:
does the whole collection field collapse into one blob on the owner's row, does each
pivot/child-table row get its own per-row preserved record, or does it just not apply to
collections at all (in which case the opening sentence is wrong to list it)? Phase 6.4's
"Done when" bar doesn't test this case either.

Working through this surfaced a bigger reframe: `NoType`'s actual purpose is to leave a
later `retype()` something concrete to convert from, not to serve as a historical record
(undo/revision history already covers that independently, for any entity's own deletion).
Read that way, `NoType`'s storage shape should be a pure function of cardinality — a
column for singular, a dedicated table for a collection — never of which kind of field it
used to be. This also surfaced that `OwningReference`/Owned-`Collection` were entirely
unaddressed (see item 2b below, filed as its own item since it grew into a distinct
question), and that an earlier candidate resolution (flip the deleted-prototype's own rows
to a `NoType` identity *in place*, leaving every referencing site untouched) didn't hold up
under scrutiny — it assumes `Reference` FKs always target `entities.id` rather than the
specific concrete table (unstated, possibly false), it silently drops the existing
cascade-delete/Owned-expansion machinery for the dying row's own descendants, it adds a
permanent blob column to the universal `entities` table paid by every entity in the system
forever, and it spreads `NoType`-awareness into the identity-resolution layer itself
(`find()`/`lazyFind()`/`Query` materialization) rather than keeping it scoped to ordinary
field hydration.

**Decided**: `Reference` used as a `Collection`'s item kind converts the same way a
singular `Reference` does, just per-item — the blob (old target type and id) lands on a
new column added to the collection's own dedicated/pivot table, replacing the dropped
target-FK column; the collection itself, its table, and its `position` ordering are
untouched, only the item kind becomes `NoType`. `Embed`/Value-Object collection-items
already worked this way (one blob column added to the collection's own dedicated table,
same as the singular case lands on the owner's row) — no change needed there, just made
explicit. Folded into `ARCHITECTURE_V2.md` ("Migrations and schema mutation") and
`ROADMAP_V2.md` (Phase 6.4).

### 2b. `OwningReference`/Owned-`Collection` had no `NoType` treatment at all — **resolved (2026-10-01)**

Neither document said what happens to an `OwningReference` or an Owned `Collection`'s item
kind when the owned type's own prototype is deleted. Unlike `Reference`/`Embed`/Value
Object, an Owned relationship has no column (singular) or dedicated table (collection) to
convert in the first place — "no column on the owner's own table" is the entire point of
the Owned mechanism (see "References and collections"). A first pass considered leaving
it unaddressed on the theory that the owned row's own deletion is already fully captured
by its `EntityChangeRecord`, so nothing is actually lost — rejected once the purpose
clarification above landed: `EntityChangeRecord` answers "what happened historically," not
"what can a live `retype()` convert from right now," which is what `NoType` is actually
for, and an Owned relationship has exactly the same retype-ability need `Reference` does.

**Decided**: reuse the same primitives as everywhere else, applied to the one case that
doesn't have a slot to put them in yet. An `OwningReference` converting to `NoType` gets a
*new* column added to the owner's own row — the owned row's own full recursively-flattened
field tree is captured into it (the same capture `Embed` already does), read off the owned
row before it's deleted through the ordinary, unchanged Phase 4 cascade-delete path (full
topological sort, Owned-subtree expansion for anything it in turn owned). An Owned
`Collection`-item converting to `NoType` gets the same capture, landed in a newly-created
dedicated child table scoped to that field, the same shape "No blobs" already uses for a
non-entity collection. This makes `NoType`'s storage shape a pure function of cardinality,
never of originating field kind: an `OwningReference`/Owned-`Collection` field stops being
the no-column/no-table case the moment it degrades, and from then on reads/retypes through
the exact same path every other `NoType` field already uses, no Owned-aware special-casing
anywhere in `Repository`. (Retyping back into a live `OwningReference` specifically — i.e.
re-adopting the no-column representation — is itself the same shape of problem as
"Flipping an existing relationship between Owned and Shared," already in "Deferred"; this
doesn't add a new gap, the existing one just resurfaces here.) Folded into
`ARCHITECTURE_V2.md` ("Migrations and schema mutation") and `ROADMAP_V2.md` (Phase 6.4).

### 3. The concurrency-tradeoff example conflates an Embed field with an Owned relationship — **resolved (2026-10-01)**

"Content write path" illustrates the owned-subtree-serialization tradeoff with "two
unrelated concurrent edits to two different Owned relationships on the same root (e.g. a
Product's `MediaAsset` gallery and its `Pricing` embed)." But `Pricing` is explicitly
called an *embed* — and "Entity vs. Value Object vs. Embed" defines Embed as flattening
into the owner's own row, not a relationship at all. Editing an embed field always
conflicts with any other edit to that same row (ordinary single-row concurrency,
independent of the Owned-subtree-bubbling rule) — it doesn't actually illustrate the
tradeoff the sentence claims to illustrate.

**Fixed**: example corrected to two genuinely distinct Owned relationships (a `MediaAsset`
gallery and a separately-Owned `Warranty` singular reference), with a parenthetical noting
why an `Embed` field wouldn't have illustrated the tradeoff in the first place (it
conflicts under ordinary single-row concurrency regardless of the Owned-subtree rule).
Folded into `ARCHITECTURE_V2.md` ("Content write path").

### 4. `ARCHITECTURE_V2.md` points to a check it never describes — **resolved (2026-10-01)**

The Embed-of-Embed cycle-detection paragraph says "Same posture as the existing
table-name-collision ... checks below" — but the table-name-collision guard is only ever
described in `ROADMAP_V2.md` (Phase 1.1, `#[Table]` + derived-short-name fallback +
collision guard), never in `ARCHITECTURE_V2.md` itself. This reverses the documents' own
stated convention (roadmap references architecture section headings and builds against
them, "not a re-explanation here" — not the other direction).

**Fixed**: added a short paragraph to "Migrations and schema mutation" stating the
collision rule itself (every table name — an entity's own, derived or `#[Table]`-pinned,
and every field's own dedicated table — shares one namespace; a collision fails
registration immediately), cross-referencing back to the `#[Embed]`-cycle-detection
posture it already echoed. The original "checks below" forward-reference now resolves
correctly, no reword needed. Folded into `ARCHITECTURE_V2.md` ("Migrations and schema
mutation").

## Roadmap coverage gaps

### 5. `queryable`'s redefinition has zero roadmap coverage — **resolved (2026-10-01)**

"No blobs" redefines `queryable` as "purely an indexing decision" (does this real column
get a database index). No phase in `ROADMAP_V2.md` ever adds or tests an index for a
queryable field — unlike its sibling `unique`, which gets an explicit Phase 1.3 bullet and
a concrete "Done when" test.

Re-examined whether the roadmap gap was the actual problem, or whether `queryable` should
exist at all post-"no blobs": its entire v1 job was the storage-tier switch (blob vs.
column), which is the one capability gap every field being a real column already closes
completely. What's left — should this column get a DB index — is a pure performance
concern with no correctness stake, fully expressible for native classes through an
ordinary reviewed migration already (no `FieldDescriptor` flag needed), and currently
unactionable for editor-created schemas anyway (`SchemaEditor`'s closed mutation set has
no index-toggling operation).

**Decided**: drop `queryable` from the vocabulary entirely rather than give it reduced
roadmap coverage. If admin-driven index control becomes a real need later, that's a new
`SchemaEditor` capability to design then — same "revisit only if demonstrated, not
preemptively" posture already used for the Embed column-count risk right next to it.
Folded into `ARCHITECTURE_V2.md` ("No blobs. Every field is a real column or a real
table."). No `ROADMAP_V2.md` change needed — it never referenced `queryable` to begin
with, which is what surfaced this in the first place.

### 6. `SET NULL` FK policy is specified but never built or tested — **resolved (2026-10-01)**

"FK `ON DELETE` policy" spells out `SET NULL` for an optional (no `RequiredValidator`)
Shared reference, including the mechanical nullability precondition. Phase 3.2's "Done
when" bar only exercises the `RESTRICT` case (a required Shared reference) — the optional
Shared-reference path, and its `SET NULL` behavior on deletion of the referenced row, is
untested anywhere in the roadmap.

**Fixed**: Phase 3.2's bullets now name both FK policies explicitly (`RESTRICT` when
required, `SET NULL` when optional), and its "Done when" bar adds the optional-reference
case: nullable FK column, `NULL`-on-delete instead of blocked, proven alongside the
`RESTRICT` case. Folded into `ROADMAP_V2.md` (Phase 3.2).

### 7. `Actor` has no explicit build step — **resolved (2026-10-01)**

`Persistence\Permission\Actor` (`hasRole(string): bool`) is relied on starting Phase 5.4
(`HistoryPermission`), and again at 6.1 (`SchemaPermission`) and Phase 7
(`FieldPermission`), but no phase ever lists building the interface itself.

**Fixed**: added a bullet building `Actor` to Phase 5.4, its first point of use, noting
it's reused as-is by `SchemaPermission` and `FieldPermission` later. Folded into
`ROADMAP_V2.md` (Phase 5.4).

## Lower-severity / worth a note

### 8. "Dotted-path disambiguation" is untraceable — **resolved (2026-10-01)**

Phase 2's "Done when" requires "the same shape embedded twice under different field names
on one entity works via dotted-path disambiguation" — but that term, and the mechanism it
names, is never introduced anywhere in `ARCHITECTURE_V2.md`. The flattening example given
there (`address.city` → `address_city`) suggests the field name (not the shape name) is
already the disambiguating column prefix, which would make this work "for free" without
needing a named mechanism at all — but nothing in the architecture doc actually says so.

**Fixed**: confirmed the "for free" reading. Added a sentence to "Entity vs. Value Object
vs. Embed" stating explicitly that the column prefix is the field's own name, not the
shape's, so two embeds of the same shape under different field names disambiguate via the
existing flattening rule alone. Reworded `ROADMAP_V2.md`'s Phase 2 "Done when" to match
(dropped the "dotted-path" term, named the actual mechanism instead). Folded into
`ARCHITECTURE_V2.md` ("Entity vs. Value Object vs. Embed") and `ROADMAP_V2.md` (Phase 2).
