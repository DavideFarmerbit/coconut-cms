# Storage / Editor v2 — Cleanup Before Building (Round 15)

**Status: done (2026-10-13).** A fifteenth audit pass over `ARCHITECTURE_V2.md`/
`ROADMAP_V2.md`, the final one before implementation starts. Checked the two documents
against each other for inconsistencies, missing pieces, and design flaws, plus the
standalone-document rule ([[feedback_v2_docs_standalone]]). No stray pre-rewrite references
found this round. Four items below, all resolved. Worked one at a time per
[[feedback_coconut_cms_development_workflow]]. Ordered by how much each one changes what
gets built.

## Real inconsistencies

### 1. `expectedOperationId` never advances from a schema-triggered write that changes existing row data — **resolved (2026-10-13)**

"Content write path" (line 1448) sources `expectedOperationId` only from the undo log's own
monotonic sequence: "the monotonic sequence position of the most recent `Revision` whose
`EntityChangeRecord` touched the entity." "Executing multi-entity writes safely" (line ~1352)
states flatly that a schema-triggered write "never engages" logging, "regardless of which row
operations it contains" — so `retype()`, `reparent()`'s level insert/remove, `addField()`
backfill, and prototype deletion/substitution all change a row's actual stored values without
ever producing an `EntityChangeRecord`.

Put together: a client holding an entity's `expectedOperationId` from before one of these
mutations runs submits an ordinary content edit afterward. The check compares the client's
remembered id against the entity's current one — which never moved, since nothing bumped it —
so the stale edit is accepted even though the row's physical data (and possibly its field's
whole kind, via `retype()`) changed underneath it. This isn't a rare interaction: "Reparenting"
(line 908) explicitly describes schema mutation against an already-populated prototype as
having "no approval gate beyond a warning shown in the editor UI," i.e. the design already
anticipates this running against live data without taking the table offline, but nothing
protects a concurrent content editor from racing it.

`schema_version` (from "Content undo, draft, and revision history") already exists and is
bumped by every schema mutation, including this exact class of write — it's just never
consulted by the write-time check, only by revision-history restore.

**Resolved: a second, independent receipt value, `expectedSchemaStamp`, closes the gap —
not by reusing the global `schema_version` sequence directly (rejected: its blast radius is
every entity in the system, not just the ones actually affected), but by stamping it
per-prototype instead.** Native identifiers need nothing: native schema is frozen for the
life of the process, so no live request can ever straddle a native schema change, which
rules out the race entirely on that side. Only editor-created identifiers can be mutated
live against already-populated content, so only `prototypes` needed anything new: one more
column, `last_modified_schema_version`, re-stamped with the current global `schema_version`
by every mutation that touches that row — not just the row named directly in the call, but
every row reached by that same mutation's own existing walks (`reparent()`'s live-subclass
cascade, embed-propagation's reverse index, substitution's per-level reconciliation and its
reference-retargeting fixup list). One uniform rule either way: a row is stamped the same
whether the mutation changed its own stored values or only a field's declared metadata (a
retargeted `Reference`'s type, for instance), never a case-by-case judgment about which kind
of change counts — the user flagged the reference-retargeting case specifically, since
substitution's fixup list reaches prototypes that never get their own data reconciled at all.

An entity's own relevant stamp is the highest `last_modified_schema_version` reachable two
ways: across every editor-created level of its own chain (`PrototypeRegistry::chainOf()`),
and, recursively, across everything it transitively owns, via the exact same subtree walk
`expectedOperationId` already performs — which is also how the user's own worked example
(a native `Product` owning, by reference, a native `Warranty` type, with an editor-created
`ExtendedWarranty` subclass actually stored there) falls out for free: `Product`'s own chain
never includes `Warranty`/`ExtendedWarranty` at all (`OwningReference` has no column on the
owner's own table), so it's the owned-subtree half of the walk that picks up
`ExtendedWarranty`'s own stamp, not the chain half.

Folded into `ARCHITECTURE_V2.md`'s "Prototype storage: the `prototypes` table" (the new
column) and "Content write path" (a new paragraph after the existing `expectedOperationId`
discussion, stating the check, its native-side exemption, the uniform stamping rule, and the
two-part relevant-stamp computation). `ROADMAP_V2.md` Phase 5.1 gained a forward pointer to
where the second half of the receipt actually lands; Phase 6.1 gained the column, the basic
stamp-and-return wiring, and a "Done when" proving the check stays narrow (an unrelated
prototype's creation never stale-rejects another prototype's own entity); Phase 6.2 gained
the first proof that it actually catches something (a `retype()`/`addField()`/etc. against
an already-populated prototype stale-rejects a stranded receipt even though
`expectedOperationId` alone would still look current); Phase 6.3 gained the live-subclass
cascade proof; Phase 6.4 gained the reference-retargeting-fixup proof, the one case where no
row's own data changes at all, only a field's declared target type.

### 2. `ROADMAP_V2.md` names three classes `ARCHITECTURE_V2.md` never introduces — **resolved (2026-10-13)**

"Roadmap only names architecture as its source" is the rule Round 12 Item 2 established (for
`DynamicEntity`). Three more instances slipped past that round and every round since:

- **`IdentityMap`** (`ROADMAP_V2.md` line 83: "`Persistence\Entity\Repository`/`IdentityMap`").
  `ARCHITECTURE_V2.md`'s "Identity Map + Repository + lazy loading" section (line 1594) only
  ever says "the identity map" as a pattern name, never commits to a class.
- **`FieldValidatorRegistry`/`PrototypeValidatorRegistry`** (`ROADMAP_V2.md` line 518).
  `ARCHITECTURE_V2.md`'s "Validation" section (line 1621) describes the open-registration
  mechanism entirely in prose — "Any PHP code can register a `FieldValidator` or
  `PrototypeValidator` class under a name, at boot" — and never names the registry itself.

**Resolved: named all three in `ARCHITECTURE_V2.md`, the same fix Round 12 applied to
`DynamicEntity`, rather than walking the roadmap's own references back to prose** — all
three are real, load-bearing pieces of the design, not implementation-API detail like
migration parameter names. "Identity Map + Repository + lazy loading" now commits to
`Persistence\Entity\IdentityMap` as the concrete class backing "the identity map," keyed by
identifier plus id and consulted by `Repository::find()`/`lazyFind()` before either issues a
query. "Validation" now names `Persistence\Schema\FieldValidatorRegistry`/
`PrototypeValidatorRegistry` as the two concrete registries the open-registration mechanism
registers against, one per kind, each a simple name-to-class map. No change needed in
`ROADMAP_V2.md` — it already used these exact names; only `ARCHITECTURE_V2.md` was missing
the commitment.

### 3. Native-side `Unique`-group backfill-with-converter distinctness check is never roadmap-tested — **resolved (2026-10-13)**

"Defaulted instances" states the backfill-converter-plus-distinctness mechanism generically,
not scoped to either identifier kind: "Backfilling such a field gains the same optional
converter retype already has... and the same row-level pairwise-distinctness check runs on its
output before committing anything." `ROADMAP_V2.md` Phase 6.2 proves this end to end — but
only for an editor-created prototype ("adding a brand-new field that's immediately declared
part of a `Unique` group to an already-populated prototype with more than one existing row is
refused outright when no backfill converter is supplied... and succeeds when a supplied
converter's output is checked and found pairwise-distinct").

Phase 1.3 is where native classes first get `Unique` groups, and it already has a
multi-row-capable round-trip test — but its own "Done when" only exercises the *ordinary*
`#[DefaultInstance]` backfill path ("adding a new field to an already-populated class
backfills every existing row"), never the `Unique`-grouped-field-with-converter variant Phase
6.2 later proves exclusively on the editor-created side. Every other mechanism this roadmap
introduces once and reuses later states that explicitly ("reuses Phase 1.3's native-side
mechanism directly," etc.); this is the one case where the shared mechanism's own
distinctness-check guarantee is asserted only on the side introduced second, with no matching
assertion on the side introduced first.

**Resolved: added the missing assertion to Phase 1.3's own "Done when"**, mirroring Phase
6.2's own two outcomes (refused outright with no converter across more than one existing
row; succeeds with a supplied converter whose output is checked pairwise-distinct; refused
again if that output collides) — reworded to say this is the same mechanism Phase 6.2 later
reuses, rather than a second one invented for the editor-created side. Phase 6.2's own
matching bullet reworded the other direction, to say it reuses Phase 1.3's mechanism rather
than reading as the only place this is proven.

### 4. `Query`'s "deliberately out of scope" carve-out names collection sub-structure filtering but not relationship-traversal filtering — **resolved (2026-10-13)**

"Admin list/filter views" explicitly excludes one case: "filtering an outer list by something
inside a collection sub-structure (`EXISTS`-style semantics) — meaningfully harder, rarer
need." It never says whether `Query::where()`/`orderBy()` can reach a field through a singular
`Reference`/`OwningReference` (e.g. sorting Products by `currentPricing.listPrice`) — "resolving
each field to its real column and which chain-level table holds it" reads as scoped to the
identifier's own CTI chain only, which would make single-step relationship-traversal filtering
unsupported too, just never said so explicitly the way the collection case is.

**Resolved: relationship-traversal filtering/sorting is real and in scope, and so — going
further than the original question — is collection-containment filtering, reversing the
original "deliberately out of scope" call.** Settled as two distinct mechanisms, not one,
since the cardinality problem differs: a singular `Reference`/`OwningReference` hop is
always 1:1-or-absent, so a dot-path (`where('category.name', ...)`) resolves as a real
`LEFT JOIN` through the hop — through the FK column for `Reference`, through
`entities.owner`/`owner_field`/`position = -1` for `OwningReference` — reusing the exact
multi-table join mechanism already built for the root identifier's own chain, applied one
hop further, recursively. `LEFT`, never `INNER`, specifically so an absent optional
relationship doesn't silently drop an otherwise-matching row.

A `Collection`-cardinality field can't use a plain join at all — it would fan the outer row
out once per matching item — so filtering by collection containment
(`Query::whereHas($field, fn($q) => $q->where(...))`/`whereDoesntHave()`) compiles to a
correlated `EXISTS`/`NOT EXISTS` against whichever physical shape that collection already
has (Shared pivot, `entities.owner`/`owner_field` lookup for Owned, or the "No blobs"
dedicated child table for non-entity), with the inner builder able to keep traversing
further through the same dot-path mechanism. **Sorting by something inside a collection
stays out of scope** — "order Products by their tags" has no single answer, a genuinely
harder aggregate-selection problem unrelated to the `EXISTS`-filtering case, so the original
scope note narrows rather than disappears.

`FieldPermission`'s existing "a field the actor can't read is rejected the same as if it
didn't exist" rule was extended explicitly to cover every hop either mechanism traverses,
not just the root identifier's own fields — the same rule, wider reach, since neither
mechanism existed to need it stated before now.

Folded into `ARCHITECTURE_V2.md`'s "Admin list/filter views" (a new dot-path paragraph, the
`whereHas()`/`whereDoesntHave()` paragraph replacing the old out-of-scope bullet, and the
`FieldPermission` extension); `ROADMAP_V2.md` gained a new Phase 8.3 for both mechanisms,
with its own "Done when" covering single- and multi-hop traversal, all three collection
physical shapes, negation, composition of the two mechanisms together, and the matching
permission-rejection cases.
