# Storage / Editor v2 — Cleanup Before Building (Round 15)

**Status: in progress.** A fifteenth audit pass over `ARCHITECTURE_V2.md`/`ROADMAP_V2.md`, the
final one before implementation starts. Checked the two documents against each other for
inconsistencies, missing pieces, and design flaws, plus the standalone-document rule
([[feedback_v2_docs_standalone]]). No stray pre-rewrite references found this round. Items
below are open questions, not yet resolved. Ordered by how much each one changes what gets
built.

## Real inconsistencies

### 1. `expectedOperationId` never advances from a schema-triggered write that changes existing row data

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

Open question: should the ordinary write-time `expectedOperationId` check also compare
`schema_version` (reject if it's advanced since the client's last read, the same conservative
posture restore already takes), or is this an accepted gap given schema mutation against live
content is already a "warned, not blocked" operation? If the former, which phase absorbs it —
`schema_version` doesn't exist until Phase 5.3, after Phase 5.1 already builds
`expectedOperationId` — so retrofitting likely lands as a Phase 6 "Done when" addition rather
than touching the Phase 5.1 mechanism's own definition.

### 2. `ROADMAP_V2.md` names three classes `ARCHITECTURE_V2.md` never introduces

"Roadmap only names architecture as its source" is the rule Round 12 Item 2 established (for
`DynamicEntity`). Three more instances slipped past that round and every round since:

- **`IdentityMap`** (`ROADMAP_V2.md` line 83: "`Persistence\Entity\Repository`/`IdentityMap`").
  `ARCHITECTURE_V2.md`'s "Identity Map + Repository + lazy loading" section (line 1594) only
  ever says "the identity map" as a pattern name, never commits to a class.
- **`FieldValidatorRegistry`/`PrototypeValidatorRegistry`** (`ROADMAP_V2.md` line 518).
  `ARCHITECTURE_V2.md`'s "Validation" section (line 1621) describes the open-registration
  mechanism entirely in prose — "Any PHP code can register a `FieldValidator` or
  `PrototypeValidator` class under a name, at boot" — and never names the registry itself.

Open question: name these three in `ARCHITECTURE_V2.md` (the same fix Round 12 applied to
`DynamicEntity` — add a sentence to the relevant section committing to the class), or walk
the roadmap's own references back to prose? Given all three are real, load-bearing pieces
of the design (not implementation-API detail like migration parameter names), naming them
in Architecture looks like the better fit with the rest of the document.

### 3. Native-side `Unique`-group backfill-with-converter distinctness check is never roadmap-tested

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

Open question: add the missing assertion to Phase 1.3's own "Done when" (a native class
gaining a `Unique`-grouped field across more than one existing row, with and without a
converter, mirroring Phase 6.2's own two outcomes), or treat Phase 6.2's proof as sufficient
since the mechanism is explicitly shared and native migrations are deferred implementation-API
detail anyway?

## Worth a decision, lower confidence

### 4. `Query`'s "deliberately out of scope" carve-out names collection sub-structure filtering but not relationship-traversal filtering

"Admin list/filter views" explicitly excludes one case: "filtering an outer list by something
inside a collection sub-structure (`EXISTS`-style semantics) — meaningfully harder, rarer
need." It never says whether `Query::where()`/`orderBy()` can reach a field through a singular
`Reference`/`OwningReference` (e.g. sorting Products by `currentPricing.listPrice`) — "resolving
each field to its real column and which chain-level table holds it" reads as scoped to the
identifier's own CTI chain only, which would make single-step relationship-traversal filtering
unsupported too, just never said so explicitly the way the collection case is.

Open question: is this silence intentional (relationship-traversal filtering is out of scope,
same as the collection case, just not yet written down), or is a single-step join across a
`Reference`/`OwningReference` actually meant to be in scope for Phase 8 and simply unspecified?
