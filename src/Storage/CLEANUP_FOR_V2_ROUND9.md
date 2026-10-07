# Storage / Editor v2 — Cleanup Before Building (Round 9)

**Status: in progress.** A ninth audit pass over `ARCHITECTURE_V2.md`/`ROADMAP_V2.md`,
independent of Round 8's own targeted scope (Round 7's attribute consolidation and
retype-converter mechanics) — a fresh pass checking the two documents against each other
for inconsistencies, missing pieces, and design flaws, and separately checking both against
the standalone-document rule ([[feedback_v2_docs_standalone]]) for stray references to
pre-rewrite docs/concepts. Items below are open, not yet decided. Worked one item at a time
per [[feedback_coconut_cms_development_workflow]]. Ordered by how much it changes what gets
built, not alphabetically.

## Missing pieces

### 1. `FieldDescriptor`/attribute surface never specifies how `Reference`/`Collection` select Owned vs. Shared — **resolved (2026-10-07)**

"Shape comes from a neutral descriptor" enumerated exactly five `FieldDescriptor` factories,
each with exactly one matching PHP attribute — "one attribute per field, never two stacked
for one declaration." But "References and collections" treats Shared and Owned as having
completely different physical storage (FK column vs. no column at all), different
`ON DELETE` policy (`SET NULL` vs. app-mediated `RESTRICT`), and different required-ness
rules (`Reference` can never be required; `OwningReference`/Owned `Collection` can be) —
for both the singular and collection cardinality. Nowhere did the document say what
parameter or sub-factory actually selected Owned vs. Shared.

**Decided: `OwningReference` is its own sixth `FieldKind`/factory/attribute, not a
parameter on `Reference`** — recovered from the pre-rewrite design (which already had
`FieldKind::OwningReference` as its own enum case), not invented fresh. This threads
through `Collection` for free: `collection()`'s own `itemDescriptor` param takes any of the
other five factories' output, so an Owned collection is just `collection(name,
owningReference(...))` and a Shared one is `collection(name, reference(...))` — no
`ownedCollection()` ever needed, Owned-ness rides entirely on the item descriptor, not on
`collection()` itself.

**While designing this, also settled the full six-factory signature set** (previously only
`valueObject()`'s signature was shown anywhere, and inconsistently named):
`scalar(name, type)`, `valueObject(name, type)`, `embed(name, type)`, `reference(name,
type)`, `owningReference(name, type, required)`, `collection(name, itemDescriptor)`. `type`
is deliberately one name reused across every non-`Collection` factory even though its
domain varies (a `ScalarType` enum case, a custom `Type`-class string, or a shape
identifier) — replaces the old inconsistent `customTypeClass` naming on `valueObject()`.
`required` exists only on `owningReference()`, since it's the one kind where
required-vs-optional changes real structural behavior (defaulted-instance backfill vs. an
absent slot), not just a `FieldValidator` gate. `name` is explicit on every factory (even
though the matching `#[...]` attribute never repeats it, since `EntityRegistrar` reads it
off the reflected property) because `FieldDescriptor` has to mean the same thing whether it
came from native reflection or from `SchemaEditor`, which has no property to read a name
from. `#[Collection]`'s own attribute params are necessarily flatter than `collection()`'s
own signature (`itemKind` enum case + whichever of `type`/`required` it needs, instead of a
nested `FieldDescriptor` object), since attribute arguments must be compile-time constants
— `EntityRegistrar` reconstructs the real nested item descriptor from those flattened
params.

Folded into `ARCHITECTURE_V2.md` ("Shape comes from a neutral descriptor" — rewritten
around the six factories and their signatures; "Entity vs. Value Object vs. Embed"'s
`valueObject()` signature updated to match; "References and collections" and "Defaulted
instances"' stray `SharedReference` naming replaced with plain `Reference`, since
`OwningReference` no longer needs a `Shared`-prefixed sibling to disambiguate against; the
`FieldRetypeSignature` cross-reference updated from "five kinds" to "six") and
`ROADMAP_V2.md` (Phase 1.1's factory list gained `owningReference()`; Phase 3.2/3.3 now name
`reference()`/`owningReference()`/`collection()` explicitly instead of only in prose; the
`NoType` paragraph's kind count updated to six). Described in each document in the resolved
design's own terms, per the standalone requirement (see [[feedback_v2_docs_standalone]]).

## Real inconsistencies

### 2. Adding an Owned-`Collection` field claims "no physical change" but also mandates a new `<field>_count` column

"Migrations and schema mutation" states adding a field is "...no physical change at all
beyond the registry record for an `OwningReference` or Owned `Collection`." But "References
and collections" requires "Every Owned-collection field also gets a `<field>_count` column
on the owner's own declaring table, uniformly, whether its item kind is required or
optional." Adding that column *is* a physical schema change — the "no physical change"
claim is only actually true for singular `OwningReference` (which really does have zero
columns, live), not for Owned `Collection`. The removal side of the same "Migrations and
schema mutation" section already gets this right ("The `Collection` case also drops the
field's own `<field>_count` column"), so only the add-side wording is wrong.
`ROADMAP_V2.md` repeats the same incorrect claim verbatim in Phase 6.2's `addField()`
bullet, while Phase 3.3's own bullet ("Every Owned-collection field gets a `<field>_count`
column...") and Phase 6.2's own "Done when" ("...also dropping the field's own
`<field>_count` column for the `Collection` case") both correctly assume the column exists
— so the roadmap contradicts itself in the same way.

**Open**: split "Adding a field"'s `OwningReference`/Owned-`Collection` case into two —
singular `OwningReference` (truly no physical change beyond the registry record) and Owned
`Collection` (adds the `<field>_count` column) — in `ARCHITECTURE_V2.md`, and apply the
same split to `ROADMAP_V2.md` Phase 6.2's `addField()` bullet.

### 3. `Unique` group scope for an Owned relationship is stated two incompatible ways

"Uniqueness"'s first scoping bullet says a group declared on a "Standalone,
Shared-referenced, or Owned (singular **or collection**)" shape is global — "every
standalone row and every Owned row under every relationship, compared together." The very
next bullet, "Collection cardinality" (which explicitly includes "Owned collection"), says
the table's own scoping column (`owner`/`owner_field`) is folded into the index
specifically so the constraint does *not* span every owner — "otherwise the constraint
would wrongly span every different owner's collection system-wide." These directly
conflict for the Owned-collection case. `ROADMAP_V2.md` Phase 3.3's "Done when" sides with
the second (owner-scoped) reading explicitly: "...proving the owner-scoping column was
folded into the real constraint rather than left as a group spanning every owner's items at
once."

**Open**: narrow the first bullet's "(singular or collection)" to "(singular)" — and in the
same pass, confirm that Owned-*singular* really is meant to be globally unique across every
unrelated owner (worth double-checking that's the intended semantics and not just leftover
wording from before the collection case was carved out), since nothing else in the document
tests or motivates that specific choice.

**Minor, related**: "Content undo, draft, and revision history"'s restore-blocking
discussion says the future touched-field refinement would reuse "the same
never/conditional/always classification already used for schema-mutation kinds (purely-
additive changes never block; a single-field change like drop/rename/retype blocks only
that field; reparent/delete block more broadly)" — but no such named classification is
actually established anywhere earlier in the document; it only appears here, inline, as
part of describing an unresolved refinement. Either point to where it's actually defined or
drop "already used."

## Documentation hygiene

### 4. `ARCHITECTURE_V2.md` still narrates decision history and names pre-rewrite concepts

The document's own `Status` line says it's "a standalone technical description of the
system's current, decided shape... never a changelog of how a decision was reached, what
it replaced, or what came before it" (tightened for exactly this reason during Round 8
item 3.1). Several passages still violate it:

- "...part of what made **Phase 8** feel entangled" (Namespaces and migration path) — cites
  the old roadmap's phase number.
- "**Re-confirmed** (not just carried over from the original baseline): uuid over an
  autoincrementing PK" (Global entity identity) — narrates decision history.
- "The single biggest change **from the old design**: there is no JSON-blob storage tier"
  (No blobs).
- "...real limits exist... that **the old design's** blob tier partly existed to avoid" (No
  blobs).
- "This retires the two-log (`SchemaUndoLog` + content `UndoLog`) bridging design **the old
  system** was building toward" (Migrations and schema mutation) — names classes that don't
  exist anywhere else in this document.
- "**The old design's** 'Owned folds into the owner's own diff, no independent record' rule
  is retracted" (Content undo, draft, and revision history).
- "**The old design** concluded no dedicated server-side check was needed... **Decided
  differently here**" (Content undo, draft, and revision history).
- "...class/method-level terminology... is still carried over **from the old design**
  unchanged" (Deferred).
- Softer instance, worth a judgment call rather than an automatic fix: "This closes
  'Flipping an existing relationship between Owned and Shared' entirely, not just narrows
  it: the thing that made it look hard was assuming it had to preserve the original row's
  identity through the swap" (Migrations and schema mutation) — doesn't name an old
  document, but narrates how the problem used to look rather than just stating the decided
  mechanism.

**Open**: reword each to describe the current decided shape in its own terms, with no
reference to "the old design"/"the old system"/"original baseline"/old phase numbers —
same treatment Round 8 item 3.1 already gave the `Status` line itself.

### 5. `ROADMAP_V2.md` cites `AUDIT.md` and the old roadmap directly, instead of only `ARCHITECTURE_V2.md`

- "Supersedes `ROADMAP.md` the same way `ARCHITECTURE_V2.md` supersedes `ARCHITECTURE.md` —
  **the old roadmap's** phases 1-8C stay as-is..." (intro).
- "This is a deliberate reversal of **the old roadmap**, where global entity identity
  landed at **Phase 8** and generic identifier-based repository access landed at **Phase 7
  Step A**... which is exactly what **`AUDIT.md`** found half-migrated and inconsistent
  (items 8 and 14: ...)" (intro) — the clearest violation: names a second document by
  filename and item number.
- "...the same granularity **the old roadmap** eventually needed (its own 6.1/6.2/6.3, and
  **Phase 8's** Step A-F split)..." (intro).
- ~~"...no per-relationship dedicated table detour (**the old design's** first attempt,
  superseded before this rewrite even started)" (Phase 3.3)~~ — **fixed incidentally**
  while resolving item 1 above (that line was rewritten to name `owningReference()`
  explicitly, and the old-design clause dropped along with it).

**Open**: reword the intro section (the remaining three instances) to justify Phase 1's
generic-identifier design in `ROADMAP_V2.md`'s own terms rather than contrasting against
`AUDIT.md` or the old roadmap's phase numbers.
