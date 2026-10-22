# Storage / Editor v2 — Cleanup Before Building (Round 12)

**Status: done (2026-10-13).** A twelfth audit pass over `ARCHITECTURE_V2.md`/`ROADMAP_V2.md`,
checking the two documents against each other for inconsistencies, missing pieces, and design
flaws, and separately checking both against the standalone-document rule
([[feedback_v2_docs_standalone]]) for stray references to pre-rewrite docs/concepts or to the
documents' own drafting history, and for the architecture-never-names-roadmap direction of
that same rule. The hygiene check caught one stray reference this round. Three items below.
Working one at a time per [[feedback_coconut_cms_development_workflow]]. Ordered by how much
it changes what gets built, not alphabetically.

## Hygiene

### 1. `ARCHITECTURE_V2.md` narrates its own drafting history — **resolved (2026-10-13)**

"Content undo, draft, and revision history" says the no-automatic-pruning design "is also what
makes atomic multi-entity undo viable again: a `Revision`'s `EntityChangeRecord`s only
disappear when a human explicitly prunes them... the 'independent per-entity retention clocks
diverge and fragment a shared operation' problem **earlier turns got stuck on** doesn't arise
from ordinary operation anymore." This directly violates the document's own opening promise
("a standalone technical description... never a changelog of how a decision was reached, what
it replaced, or what came before it") — and does so more plainly than a stale cross-reference
would, since it names prior conversation turns rather than even a prior document.

**Resolved: deleted the clause, reworded as a plain statement of the current design's
property.** Now reads "...not as a side effect of some other entity's unrelated edit
frequency — independent per-entity retention clocks never diverge and fragment a shared
operation this way," with no reference to how that conclusion was arrived at.

## Missing pieces

### 2. `PrototypeRegistry::instantiate()`'s editor-created return value is never named, and the roadmap invents an ungrounded class to fill the gap — **resolved (2026-10-13)**

"Shape comes from a neutral descriptor" explains how a native identifier resolves to a
`FieldDescriptor[]` via reflection, and how `PrototypeRegistry` is "stateless either way —
every method resolves directly, reading the database for an editor-created identifier, reading
the already-loaded class via reflection for a native one." But it never says what
`instantiate(identifier, values)` actually *returns* for an editor-created identifier. For a
native identifier the answer is obvious (an instance of the native PHP class), but an
editor-created identifier has no native class behind it — nothing in `ARCHITECTURE_V2.md`
names or describes the generic runtime object that holds that entity's field values in PHP.

`ROADMAP_V2.md` Phase 6.1 quietly fills this gap by introducing `Persistence\Schema\
DynamicEntity` — a name that appears nowhere in `ARCHITECTURE_V2.md`, grounded in nothing the
architecture actually says. This breaks "roadmap only names architecture as its source": the
roadmap is supposed to build against decided architecture, not decide architecture of its own
on the way past.

**Resolved: added to "Shape comes from a neutral descriptor," right after `instantiate()`'s
existing source-splitting description.** `instantiate()` returns a real native instance for a
native identifier as before; for an editor-created identifier it returns a
`Persistence\Schema\DynamicEntity` — one generic PHP class, shared by every editor-created
shape, holding the resolved `identifier` plus a field-name-keyed value map
(`getField(string)`/`setField(string, mixed)`, never a declared property, since there isn't
one to bind to). This is what lets `Repository`/`WriteExecutor`/`Query`/validators stay
source-agnostic past `fieldsOf()` down into the object itself — reading/writing by name
through the map is the same operation as reflecting a native object's declared property, just
through a different accessor. `DynamicEntity` carries no behavior of its own; admin-authored
validation/defaults attach by name through the existing registries, the same restriction that
already keeps the live editor from running arbitrary PHP. `ROADMAP_V2.md`'s own Phase 6.1
reference needed no wording change — it already named `DynamicEntity` correctly, it just
wasn't grounded in anything architecture said until now.

### 3. Backfilling a new `Unique`-group field with a required converter is documented but never built or tested — **resolved (2026-10-13)**

"Defaulted instances" states a specific rule: a field participating in a `Unique` group is
"the one case the plain defaulted-instance path can never satisfy once more than one existing
row needs a value" — a single constructed default always collides past the first row.
Backfilling such a field needs the same optional-converter mechanism retype already has, plus a
row-level pairwise-distinctness check on the converter's output before committing anything; not
supplied, the backfill is refused outright once more than one row needs a value.

Grepping every "Done when" clause in `ROADMAP_V2.md`, this scenario is never exercised. Phase
1.3 only tests ordinary backfill (no `Unique` field involved, and no `addField()` mechanism
exists yet regardless). Phase 6.2 tests the same distinctness check for **retype**, but not for
**add-field backfill on an already-populated prototype carrying a `Unique` group** — the
specific case "Defaulted instances" calls out by name. Phase 6.2 is the natural home for it,
since that's exactly where `addField()` on a populated prototype is built and tested; the
assertion is just missing from its "Done when" list.

**Resolved: added to Phase 6.2's "Done when," right after the existing retype-distinctness
assertion.** Adding a brand-new field already declared part of a `Unique` group to an
already-populated prototype with more than one existing row is refused outright when no
backfill converter is supplied, and succeeds when a supplied converter's output is checked and
found pairwise-distinct across every row — proven alongside a collision case where the whole
`addField()` is refused rather than partially applied, the same distinctness check the
adjoining retype assertion already uses, just applied to a backfill instead of a retype.
