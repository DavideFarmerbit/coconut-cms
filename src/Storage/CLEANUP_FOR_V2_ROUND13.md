# Storage / Editor v2 — Cleanup Before Building (Round 13)

**Status: open.** A thirteenth audit pass over `ARCHITECTURE_V2.md`/`ROADMAP_V2.md`. Checked
the two documents against each other for inconsistencies, missing pieces, and design flaws.
Also checked the standalone-document rule ([[feedback_v2_docs_standalone]]): no stray
references to pre-rewrite docs/concepts, and the roadmap cites only the architecture. No
hygiene violations found this round. Four items below. Working one at a time per
[[feedback_coconut_cms_development_workflow]]. Ordered by how much each one changes what gets
built.

## Missing pieces

### 1. Cardinality retype never says what happens to a Collection field's `<field>_count` column

"Changing a field's cardinality" lets a Collection field become singular, or a singular field
become a Collection. For the Shared and non-entity cases, it says the dedicated table drops (or
a new one gets created). An Owned Collection has no dedicated table. Its only Collection-specific
storage is the `<field>_count` column on the owner's own row.

Every other event in that column's life is spelled out elsewhere: added when the field is added
(line 632), dropped when the field is removed (line 659), renamed when the field is renamed
(line 687), dropped when the field degrades to `NoType` (lines 1215-1227), and rebuilt fresh if a
`NoType` field is retyped back into a live Owned Collection (line 1235). The cardinality section
is the one place this column's fate goes unstated. `ROADMAP_V2.md` Phase 6.2 (line 586-588)
repeats the same gap: it only mentions dropping/creating "the dedicated table."

**Question for discussion:** should the cardinality section just say "drops `<field>_count` too,
for the Owned case" and "creates `<field>_count`, for the Owned case" alongside the existing
table-drop/create language? That would make the rule symmetric with every other `<field>_count`
event already documented.

## Real inconsistencies

### 2. Collection-membership concurrency protection doesn't actually work for Shared collections — **resolved (2026-10-13)**

"Uniqueness" (line 530) and "Content write path" (line 1393) both say a write touching a
collection's own membership is serialized by the owning entity's `expectedOperationId`. But
`expectedOperationId` is sourced only from `EntityChangeRecord` (line 1395-1397): "the monotonic
sequence position of the most recent `Revision` whose `EntityChangeRecord` touched the entity."

"FK `ON DELETE` policy" (line 434) separately states that a Shared collection's pivot row "carries
no independently-logged content of its own (not an entity, gets no `EntityChangeRecord`)."

Put together: adding or removing an item from a Shared collection changes only the pivot table.
It never changes the owning entity's own row, and produces no `EntityChangeRecord` for the owner.
So the owner's `expectedOperationId` never advances from that edit. Two concurrent edits to the
same Shared collection would both read the same unadvanced id and both succeed — the opposite of
"serializing concurrent edits to the same collection," which is the claim the Uniqueness section
relies on to justify not needing a database-level check of its own.

A second consequence falls out of the same fact: a Shared collection's membership changes are
never recorded in undo history at all, since nothing produces an `EntityChangeRecord` for them.
Every other kind of content edit gets one. This isn't called out anywhere as a deliberate
exclusion (unlike Draft or schema mutations, which are explicitly excluded from undo).

**Resolved, and broadened while fixing it: a write to any collection field backed by its own
dedicated child table now produces an `EntityChangeRecord` for the collection's declaring
entity.** The original report only named Shared collections, but "No blobs" already states a
Shared pivot table and a non-entity collection's own child table (scalar, value-object, or
`#[Embed]` items) are "this exact same `(position, value column(s))` shape... not a second,
independently-arrived-at mechanism" — so a fix scoped to pivots only would have left the
identical gap sitting in the non-entity-collection case. Added to "Content write path," right
after the Owned-collection slot-shift mechanics, covering both: a row in either dedicated
table is never an entity and gets no `EntityChangeRecord` of its own, but adding, removing, or
changing one still changes the declaring entity's own content, so `ChangesetFlusher`
attributes that write to the declaring entity's own `EntityChangeRecord` for the flush. This
follows the existing "no carve-out for Owned entities" rule exactly — the record attaches to
the declaring entity itself, Owned or standalone, never bubbled further up an ownership chain,
the same way every other entity write already works. Also added a short clarifying
cross-reference to "FK `ON DELETE` policy" (the pivot row still carries no
*independently*-logged content of its own, but the write is logged, against the declaring
entity) and a new `ROADMAP_V2.md` Phase 5.1 "Done when" assertion proving a flush confined to
either kind of dedicated table still produces the declaring entity's `EntityChangeRecord` and
advances its `expectedOperationId`.

Considered and rejected: bubbling the record up to the declaring entity's ultimate standalone
owner, if the declaring entity is itself Owned. That would reverse "no carve-out for Owned
entities," which the document states repeatedly and relies on elsewhere (undo's per-entity
conflict check, revision-history restore) — a much bigger change than this item needs.

### 3. `entities.owner`/`owner_field`/`position` are called "nullable," but must never actually be null

"Global entity identity" introduces all three columns as "nullable" (lines 187, 191, 193). But
"Reserved sentinel values" (lines 491-495) requires every row to carry a non-null sentinel
instead — the nil UUID for `owner`, the empty string for `owner_field`, `-1` for `position` — and
says so explicitly: "never a plain `NULL`" (line 226). The whole point of the sentinel is to
avoid SQL's "two `NULL`s are never equal" rule silently breaking the `Unique`-group guarantee for
standalone rows.

A reader who builds the schema from the literal column definitions ("nullable," no default)
would produce exactly the schema that breaks that guarantee.

**Question for discussion:** reword the three column definitions to say they're always populated
(never actually null, sentinel or real value), rather than "nullable"?

### 4. "Namespaces" names concrete Draft classes that "Content undo, draft, and revision history" says aren't decided yet

"Namespaces" states "Draft (`DraftStore`/`DraftPreview`) lives in `Editor\` instead, not here"
(line 53) — naming two specific classes as if settled. "Content undo, draft, and revision
history" says the opposite: "Draft's own lifecycle and shape... [isn't] decided here and is left
to `Editor\`'s own design pass" (line 1478). `ROADMAP_V2.md`'s "Not covered by this roadmap"
section repeats the same two class names.

**Question for discussion:** drop the `DraftStore`/`DraftPreview` names from "Namespaces" (and the
roadmap) until `Editor\`'s own design pass actually settles them, and just say "Draft lives in
`Editor\`, not here"?
