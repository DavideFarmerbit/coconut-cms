# Storage / Editor v2 — Cleanup Before Building

**Status: resolved (2026-09-30).** Was a working punch list, not a spec — produced by
auditing `ARCHITECTURE_V2.md` and `ROADMAP_V2.md` against each other for inconsistencies,
missing pieces, and design flaws, before Phase 1 starts. All 20 items below have since
been decided and folded back into `ARCHITECTURE_V2.md`/`ROADMAP_V2.md` directly — this
file is kept only as a historical record of that audit pass, not as an open task list.
Ordered by how much it changes what gets built, not alphabetically.

## Real inconsistencies

### 1. `ROADMAP_V2.md` cites a resolved issue as still open

"Not covered by this roadmap" says *"schema-level delete referential integrity for the
referencing-prototype case... still listed as open in `ARCHITECTURE_V2.md`'s Deferred
section."* Stale — this was fully resolved (the "allowed to dangle" three-context policy:
native-referencing-native fails at registration, native deletion goes through the
reviewed-migration tool, editor-schema-side gets a `SchemaEditor` save-time block plus an
auditing tool) and removed from Deferred. The roadmap's closing section was never updated
to match.

**Fix**: remove that clause from the roadmap's closing section.

### 2. Owned entities and undo — does an Owned entity get its own `EntityChangeRecord`?

The original design (never explicitly retracted) says Owned relationships get "no
independent undo/revision entry at all — its before/after values fold straight into the
owning entity's own logged diff." The Envers-style rewrite of the undo section just says
"every entity that flush actually touched gets its own independent `EntityChangeRecord`,"
with no carve-out for Owned. Given Owned entities now have full `entities` rows just like
Shared ones, dropping the old fold-into-owner rule is plausible — but that's a decision,
not something to leave ambiguous, and it changes what Phase 5 needs to test.

**Open**: decide one way, write it into `ARCHITECTURE_V2.md`'s undo section explicitly.

### 3. "Flipping Owned ↔ Shared" is missing from the Deferred list

Flagged mid-design as "still a real structural migration" and left open, but never
actually written into `ARCHITECTURE_V2.md`'s Deferred section — unlike its three sibling
problems (retype, collection item-kind change, queryable-flip), which all got resolved.
Right now there's no written record this is unsolved.

**Fix**: add it back to Deferred.

### 4. Inconsistent phase-level "Done when" roll-ups in the roadmap

Phases 3, 5, and 6 each have a "(whole of Phase N)" summary bar that only restates its
*last* sub-phase's content, silently dropping the earlier ones:

- Phase 3's roll-up never mentions native CTI extension (3.1) or non-entity collections
  (3.4's first bullet).
- Phase 5's roll-up never mentions logging (5.1), undo/redo/auth (5.2), or draft (5.3).
- Phase 6's roll-up never mentions create (6.1), mutate-existing (6.2), or
  reparent/revocation (6.3).

Phase 1's roll-up is more complete. Phase 8 has no roll-up at all.

**Open**: either fix all roll-ups to be complete, or drop the redundant roll-up framing
entirely and let the sub-phase bars stand alone — maintaining the same summary in two
places is the same duplicated-source-of-truth problem this rewrite exists to eliminate
elsewhere.

## Missing pieces

### 5. No eager-rejection rule for a `Reference`/`Collection` field inside an `Embed` target

The restriction itself is stated ("no Reference, no Collection at any depth") but nothing
says this must be *rejected at registration time* — the obvious fit given the same
eager-failure posture used for cycle detection and defaulted-instance completeness
elsewhere, but not written down, and not tested anywhere in the roadmap either.

### 6. Retype/rename on an `Embed` target's own field doesn't propagate

New-field backfill on an embedded shape explicitly propagates to every embedding table.
Retyping or renaming an *existing* field on that same shape presumably needs identical
propagation (every embedding table has its own copy of that flattened column) — never
stated.

### 7. Retargeting a `Reference` field's own type is unaddressed

Retype and collection-item-kind-change both cover *value* conversion. Nothing covers "this
reference used to point at `Category`, now it should point at `Tag`" — a structurally
harder problem (existing FK values may not correspond to valid rows of the new target type
at all).

### 8. Whether a `Collection`'s item kind can be `Embed` is ambiguous

The old system explicitly allowed collection items to be `EmbeddedValueObject`. V2's
"non-entity collection" description only says "scalar or value-object-primitives," never
mentions embed items, even though a repeated struct (a collection of `Address`, say) is a
reasonable thing to want.

### 9. `FieldPermission` granularity against a flattened `Embed` field is unstated

Can read/write be granted on `address.city` independently of `address.zip`, or only on the
whole `address` field as declared? Given every sub-field is a real column, per-leaf-column
seems like the natural answer, but it's never said.

### 10. `Query` is never cross-referenced with `FieldPermission`

Two independent sections in `ARCHITECTURE_V2.md`; nothing confirms a `Query` result goes
through the same read-time permission filter `Repository::find()` does.

### 11. Draft's access model vs. the new undo-authorization check

Undo now requires proving a `Revision` belongs to the requesting user. Drafts were
described (unchanged from the old design) as potentially shared — "a second editor takes
over the existing draft" — which sounds like drafts are *not* per-user the same way undo
now is. Worth an explicit contrasting line instead of leaving it implicit.

### 12. Manual pruning has no permission gate

Deliberately destructive, deliberately manual, and currently ungated by any permission
concept in either document.

### 13. `SET NULL`'s mechanical precondition is unstated

A `SET NULL` FK constraint requires the column to actually be nullable at the DB level —
meaning "optional (no `RequiredValidator`)" needs to be the thing that *derives* the
column's own nullability, not just the ON-DELETE policy choice. Not wrong, just an
unstated assumption load-bearing enough to deserve a sentence.

### 14. Orphaned `Revision` cleanup is unaddressed

What happens to a `Revision` row once every one of its `EntityChangeRecord`s has been
manually pruned — deleted too, or left as an empty husk forever — is never addressed.

## Roadmap sequencing/testing gaps

### 15. Phase 1 never actually proves genericity

Phase 1 only ever exercises one `PrototypeRegistry` implementation (native). The entire
premise of this rewrite is "no retrofit needed later because it was generic from day one,"
but nothing in Phase 1's done-when bar verifies the interface can support a second
implementation until Phase 6 — six phases later. A cheap fake/test-double second
implementation, exercised through `Repository` in a Phase 1 test, would catch a
native-specific assumption leaking in immediately instead of at Phase 6, which is exactly
the failure mode this roadmap restructuring exists to avoid.

**Fix**: add a throwaway second `PrototypeRegistry` implementation + a test exercising it
through `Repository`, to Phase 1 (1.1 or 1.2).

### 16. Phase 4 only exercises a Shared-relationship multi-entity write

Its done-when bar (create a Tag, attach to a Product) never tests an Owned-relationship
creation within the same atomic flush, even though Phase 3.3 itself calls Owned "the
genuinely novel mechanism in this design." The higher-risk mechanism is the one *not*
covered by the atomicity test.

**Fix**: add an Owned-relationship case to Phase 4's done-when bar.

## Lower-severity / worth a note

### 17. No-blobs + unlimited-depth `Embed` flattening: column-count/row-size limits

Postgres (~1600 columns/table) and MySQL (row-size ceilings) both have real limits. Almost
certainly fine in practice, but the old design's blob tier existed partly to avoid this,
and that tradeoff is gone now without comment.

### 18. Cursor pagination sorting by a field the actor can't read

Interaction between `Query`'s cursor mechanism and `FieldPermission` read-filtering isn't
considered.

### 19. Ownership-cycle rejection isn't explicitly stated

`A.owner = B`, `B.owner = A` is presumably caught for free by the changeset topological
sort's existing cycle rejection, but this isn't stated as covering the ownership edge
specifically, only the general reference-graph one.

### 20. `entities.id` as uuid was never explicitly re-confirmed

Stated once in the original baseline, flagged early as a tradeoff vs. an autoincrement PK,
never explicitly revisited despite nothing contradicting it since. Worth a conscious
"yes, still want uuid" before Phase 1.1 locks in the schema.
