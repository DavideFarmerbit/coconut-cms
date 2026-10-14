# Storage / Editor v2 — Cleanup Before Building (Round 17)

**Status: open (2026-10-14).** Item 1, surfaced while designing
`PROPOSAL_FOR_V2_DEFAULT_TRACKING.md`'s own `Unique`-enforcement rule — not something that
proposal introduces, a gap already present in the current, already-decided architecture,
independent of whether that proposal is ever adopted — is resolved. Round stays open in case
further items surface. Worked per [[feedback_coconut_cms_development_workflow]].

## Missing piece

### 1. A collection's own declared default can already violate a `Unique` group declared on its own item kind, and neither doc catches it — resolved (2026-10-14)

"Defaulted instances" states a field participating in a `Unique` group is the one case the
plain defaulted-instance path can't satisfy once more than one *existing row* needs a value,
requiring a converter and a pairwise-distinctness check across rows. That's scoped entirely
to *backfilling across multiple existing rows*. It says nothing about a single row's own
collection field: a collection's resolved default produces several items at once (`[1, 2, 3]`
for a scalar collection, several built entities for an Owned `Collection`), and if the
collection's item kind carries its own `Unique` group, a default that happens to produce two
equal items among them already violates that group — before a single row is ever created,
with no backfill or migration involved at all.

Neither doc catches this. "Uniqueness" describes where the constraint physically lands for a
collection item (owner-folded for Owned, child-table-`ownerId`-folded for non-entity/
`#[Embed]`) but never checks whether a *declared default* could already collide against it.
`ROADMAP_V2.md` Phase 3.3/3.4 prove a `Unique` group on a collection item rejects a repeat
under the same scope, but every one of those tests starts from explicitly constructed items,
never from a class's own declared default.

**Resolved: the same structural-violation posture this design already holds everywhere else
applies here too** — checked at the point of declaration, not left to surface later as a
runtime collision. For native classes, `EntityRegistrar::register()`'s existing walk
additionally checks a resolved default's own produced items for pairwise distinctness
wherever the collection's item kind carries a `Unique` group, throwing immediately if it
finds one. For editor-created fields, `SchemaEditor` runs the same check before accepting the
save. Scoped to Owned and non-entity/`#[Embed]` collections only — a Shared collection's item
kind is a plain `Reference`, which has no default concept at all under the currently-decided
architecture (a separate, not-yet-adopted proposal would change that for editor-created
`Reference` fields specifically; out of scope for this fix).

Folded into `ARCHITECTURE_V2.md`'s "Defaulted instances" section (new paragraph immediately
after the existing across-row distinctness rule) and `ROADMAP_V2.md` Phase 3.3 (new "Done
when" assertion, proven once) / Phase 3.4 (restated as reused, not reproven).
