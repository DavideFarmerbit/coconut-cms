# Storage / Editor v2 — Cleanup Before Building (Round 3)

**Status: open — working punch list, not a spec.** A third audit pass over
`ARCHITECTURE_V2.md`/`ROADMAP_V2.md`, done after `CLEANUP_FOR_V2.md` and
`CLEANUP_FOR_V2_ROUND2.md`'s items were folded back into both documents. Items below are
not yet decided. Ordered by how much it changes what gets built, not alphabetically. As
each item is resolved, its entry gets a **resolved (date)** tag and a summary of the
decision, same as the two prior rounds, and the change gets folded back into
`ARCHITECTURE_V2.md`/`ROADMAP_V2.md` directly.

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

### 2. `NoType` names "Collection-item" as a kind it converts, but never works out that case

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

**Open**: decide whether `NoType` actually applies to `Collection`-item fields and, if so,
what it preserves and where it's stored; otherwise correct the opening sentence to drop
"Collection-item" from the list and explain what happens to a dangling collection-item
target instead (if anything different from the ordinary CASCADE/dangling-row handling
already described elsewhere).

### 3. The concurrency-tradeoff example conflates an Embed field with an Owned relationship

"Content write path" illustrates the owned-subtree-serialization tradeoff with "two
unrelated concurrent edits to two different Owned relationships on the same root (e.g. a
Product's `MediaAsset` gallery and its `Pricing` embed)." But `Pricing` is explicitly
called an *embed* — and "Entity vs. Value Object vs. Embed" defines Embed as flattening
into the owner's own row, not a relationship at all. Editing an embed field always
conflicts with any other edit to that same row (ordinary single-row concurrency,
independent of the Owned-subtree-bubbling rule) — it doesn't actually illustrate the
tradeoff the sentence claims to illustrate.

**Open**: fix the example to cite two genuinely distinct Owned relationships (e.g. a
`MediaAsset` gallery and a separate Owned `Warranty` singular reference), confirming the
tradeoff's reasoning still holds with a correct example.

### 4. `ARCHITECTURE_V2.md` points to a check it never describes

The Embed-of-Embed cycle-detection paragraph says "Same posture as the existing
table-name-collision ... checks below" — but the table-name-collision guard is only ever
described in `ROADMAP_V2.md` (Phase 1.1, `#[Table]` + derived-short-name fallback +
collision guard), never in `ARCHITECTURE_V2.md` itself. This reverses the documents' own
stated convention (roadmap references architecture section headings and builds against
them, "not a re-explanation here" — not the other direction).

**Fix**: either add a short description of the table-name-collision rule to
`ARCHITECTURE_V2.md` (it's referenced as if already established there), or reword the
cycle-detection paragraph to point at `ROADMAP_V2.md` instead of "below".

## Roadmap coverage gaps

### 5. `queryable`'s redefinition has zero roadmap coverage

"No blobs" redefines `queryable` as "purely an indexing decision" (does this real column
get a database index). No phase in `ROADMAP_V2.md` ever adds or tests an index for a
queryable field — unlike its sibling `unique`, which gets an explicit Phase 1.3 bullet and
a concrete "Done when" test.

**Fix**: add a `queryable` bullet (and done-when assertion) to whichever phase makes sense
— likely Phase 1.3 alongside `unique`, or Phase 8 where indexed lookups actually start to
matter.

### 6. `SET NULL` FK policy is specified but never built or tested

"FK `ON DELETE` policy" spells out `SET NULL` for an optional (no `RequiredValidator`)
Shared reference, including the mechanical nullability precondition. Phase 3.2's "Done
when" bar only exercises the `RESTRICT` case (a required Shared reference) — the optional
Shared-reference path, and its `SET NULL` behavior on deletion of the referenced row, is
untested anywhere in the roadmap.

**Fix**: add an optional-reference case to Phase 3.2's bullets/"Done when": declaring a
Shared reference with no `RequiredValidator` makes the FK column nullable and
`SET NULL`-on-delete, proven by deleting the referenced row and observing the column goes
to `NULL` rather than being blocked.

### 7. `Actor` has no explicit build step

`Persistence\Permission\Actor` (`hasRole(string): bool`) is relied on starting Phase 5.4
(`HistoryPermission`), and again at 6.1 (`SchemaPermission`) and Phase 7
(`FieldPermission`), but no phase ever lists building the interface itself.

**Fix**: add a one-line bullet building `Actor` to Phase 5.4 (its first point of use).

## Lower-severity / worth a note

### 8. "Dotted-path disambiguation" is untraceable

Phase 2's "Done when" requires "the same shape embedded twice under different field names
on one entity works via dotted-path disambiguation" — but that term, and the mechanism it
names, is never introduced anywhere in `ARCHITECTURE_V2.md`. The flattening example given
there (`address.city` → `address_city`) suggests the field name (not the shape name) is
already the disambiguating column prefix, which would make this work "for free" without
needing a named mechanism at all — but nothing in the architecture doc actually says so.

**Open**: either add a sentence to "Entity vs. Value Object vs. Embed" confirming
column-prefix-by-field-name is what disambiguates two embeds of the same shape, or correct
the roadmap wording if something more involved was actually intended.
