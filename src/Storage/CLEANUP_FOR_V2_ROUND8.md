# Storage / Editor v2 — Cleanup Before Building (Round 8)

**Status: in progress.** An eighth audit pass over `ARCHITECTURE_V2.md`/`ROADMAP_V2.md`,
targeted specifically at Round 7's own items 6-9 (the attribute consolidation, the two
retype-converter interfaces, and the reparenting/`#[Embed]` interaction) — checking not just
that those items were folded back consistently, but that the mechanics they introduced are
themselves correct, cohesive, and complete once followed through independently rather than
taken on the strength of Round 7's own "resolved" framing. Terminology/cross-reference
consistency held up (no stale `#[Table]`/`#[EditorExtensible]` references, single
definitions for `FieldRetypeConverter`/`EntityRetypeConverter`/`FieldRetypeSignature`,
roadmap phases matching architecture prose). The gaps below are new — none were raised or
touched by Round 7. Not yet resolved; to be worked one item at a time per
[[feedback_coconut_cms_development_workflow]]. Ordered by how much it changes what gets
built, not alphabetically.

## Missing pieces

### 1. `reparent()` has no cycle-detection guard

Every other structural hazard in this design gets an explicit fail-loudly check at the
point the hazard could be introduced: Embed-of-Embed cycles at registration time
(`ARCHITECTURE_V2.md` "Entity vs. Value Object vs. Embed"), table-name collisions at
registration time, defaulted-instance completeness at registration time. `reparent()`
(Phase 6.3, Round 7 item 9's subject) never gets an equivalent guard, even though it
mutates the exact same kind of structure (`prototypes.parent`) that chain resolution walks.

Nothing stops reparenting a prototype onto one of its own current descendants (or onto
itself), which would make `prototypes.parent` cyclic. `PrototypeRegistry::chainOf()`'s only
documented non-happy-path is truncating at the first *unresolvable* parent link
("`PrototypeRegistry::chainOf()` doesn't throw when a stored parent identifier fails to
resolve — it truncates the chain there") — a cyclic chain isn't unresolvable in that sense,
every link still resolves, so this truncation rule doesn't catch it, and nothing else is
stated to. Worst case, `chainOf()` (and anything built on it — `fieldsOf()`,
`SchemaBuilder::tablesForChain()`) loops forever the moment such a cycle exists.

Needs its own design pass: where the check runs (at the point `reparent()` is called, same
"fail loudly, not at migration/runtime" posture as everything else), what it walks (the
candidate new parent's own chain upward, checking whether the entity being reparented
already appears in it), and whether native and editor-created need the same enforcement or
split the way other mutations do (a native reparent is a reviewed migration; an
editor-created one is live and unreviewed).

### 2. Entity-type substitution (Round 7 item 8) never checks `Unique` collisions in converter output

The field-level retype mechanism ("Migrations and schema mutation") is explicit: if the
target field participates in a `Unique` group, the framework collects every row's produced
value across the whole table and checks that set for pairwise distinctness — and against any
existing live values — before committing anything, refusing the entire retype and naming the
collision if it isn't distinct.

Prototype deletion's optional replacement + `EntityRetypeConverter` path (Round 7 item 8) is
structurally the same shape — one converter, invoked once per existing row, producing new
field values at whichever CTI levels are reconciled — but neither the architecture block
describing it nor `ROADMAP_V2.md`'s Phase 6.4 "Done when" ever mentions checking that output
against a `Unique` group declared on the replacement type (or on a level common to both
chains). A supplied `EntityRetypeConverter` could silently produce colliding values for a
field under a `Unique` constraint, for rows being substituted in bulk, with nothing to catch
it — the opposite of how the symmetric field-level mechanism already treats this exact risk.

### 3. Logging/undo status of substitution's bulk row mutations, and reparent's backfill insertions, is unspecified

"Every touched entity gets its own independent `EntityChangeRecord` — no carve-out for Owned
entities" is stated as a near-universal invariant ("Content undo, draft, and revision
history"). The design is explicit about where schema-triggered *content* changes land on
either side of that invariant: a reparent's removed-level row deletions are explicitly
logged ("Each stray row still gets its own ordinary `EntityChangeRecord`, the same as any
other delete"); a reparent's Embed-site *column* add/drop is explicitly exempted as ordinary
schema DDL ("no `EntityChangeRecord` of its own, consistent with schema mutations
generally").

Two cases fall in between and are never placed on either side:

- **Reparent's own backfill *insertions*** (a newly-added level's row, built via
  `#[DefaultInstance]`) — only the deletion half of reparent is confirmed logged; the
  insertion half is never addressed.
- **Entity-substitution's per-row value updates/inserts/deletes** (Round 7 item 8) — real
  content changes potentially spanning every existing row of a type and its live subclasses.
  The architecture block describing this mechanism contains zero mentions of `Changeset`,
  `EntityChangeRecord`, or undo.

This matters because the two answers have real consequences either way: logged means this
bulk operation needs to run through `Changeset`/`ChangesetFlusher` (topological sort and
all) the way ordinary writes do, which is heavier than anything else triggered by a single
admin action elsewhere in this design; unlogged means every migrated row's revision history
has a silent gap at the point its data changed, and undo/revision-restore can never reach
behind it for that row — which would need to be reconciled with the `schema_version` cutoff
mechanism rather than left implicit.

## Roadmap coverage gaps

### 4. `SchemaPermission` is never tested for reparenting or prototype deletion/substitution

Phase 6.1 and 6.2's "Done when" clauses explicitly assert `SchemaPermission` gating ("an
admin without `SchemaPermission` cannot create a prototype"; "each gated by
`SchemaPermission`"). Phase 6.3 (reparenting, Round 7 item 9) and Phase 6.4 (deletion, plus
item 8's replacement/converter substitution) never mention `SchemaPermission` once in either
their bullet lists or "Done when" clauses — despite `ARCHITECTURE_V2.md`'s "Permissions"
section claiming generically that it "gates every schema mutation from the first commit that
makes runtime mutation possible at all." The two highest-blast-radius mutations in the whole
design (bulk cascade-delete-with-replacement, and reparenting) are exactly the ones with no
roadmap proof that the generic claim actually holds for them.

Likely just a missing-test-coverage gap, same genre as Round 7 item 5 — `ARCHITECTURE_V2.md`
already asserts the rule, `ROADMAP_V2.md` just never assigned it a "Done when" for these two
phases. Worth confirming that's really all this is before treating it as resolved, given how
much item 8 changed about what Phase 6.4 actually does.

### 5. `deletePrototype()` is never named in `ROADMAP_V2.md`

`ARCHITECTURE_V2.md` names the method explicitly ("the same `deletePrototype()` call, just
with two more optional arguments"). Every sibling schema-mutation operation —
`createPrototype()`, `addField()`/`dropField()`/`rename()`/`retype()`, `reparent()` — is
named explicitly in `ROADMAP_V2.md` too. Prototype deletion, including the substitution
variant Round 7 item 8 added to it, is only ever described in prose in the roadmap, never
given its method name. Minor on its own; grouped here because Phase 6.4 is the same phase
item 4 above flags for missing permission coverage, and both read as the roadmap's treatment
of this one phase being less rigorous than its neighbors.
