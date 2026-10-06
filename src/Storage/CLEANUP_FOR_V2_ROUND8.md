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

### 1. `reparent()` has no cycle-detection guard — **resolved (2026-10-06)**

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

**Decided: a hard rejection, orthogonal to the existing "no approval gate beyond a
warning" posture, not a replacement for it.** That existing posture is a data-safety
judgment call (an admin might genuinely want a destructive-but-valid reparent); a cycle
isn't destructive-but-valid, it's structurally unresolvable, the same category as
Embed-of-Embed cycles and table-name collisions, which already fail outright with no
override anywhere else in this design. Triggered at the point `reparent()` is invoked
rather than at registration time, since the parent link being checked doesn't exist to
check until then.

**The check collapses to one case, native and editor-created alike**: reject
`reparent(X, newParent: Z)` if `X` appears anywhere in `Z`'s own chain — covering a direct
self-reparent (`Z === X`) and reparenting onto any current descendant with no separate
case for either. This transitively protects every live subclass of `X` too, with no extra
mechanism: a subclass's own chain already runs through `X`, so if `X`'s new parent chain
doesn't loop back through `X`, it can't loop back through `X` for the subclass either.

Folded into `ARCHITECTURE_V2.md` ("Reparenting" — a new paragraph right after the "no
approval gate beyond a warning" sentence, drawing the line between the two kinds of check)
and `ROADMAP_V2.md` (Phase 6.3 gained a "Cycle rejection" bullet and a matching "Done when"
clause, including the subclass-reached case). Described in each document in the resolved
design's own terms, per the standalone requirement (see [[feedback_v2_docs_standalone]]).

### 2. Entity-type substitution (Round 7 item 8) never checks `Unique` collisions in converter output — **resolved (2026-10-06)**

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

**Decided: reuse the exact same pairwise-distinctness check, not a weaker or
substitution-specific one.** Nothing about a `Unique` group distinguishes a value arriving
through `EntityRetypeConverter` from one arriving through `FieldRetypeConverter` — both are
just "a value landing on a field, at commit time, from a converter." Before committing
anything, the framework collects every migrated row's produced value for each `Unique`
group declared at a level the reconciliation touches (a level kept with updated values, or
a level freshly inserted — never a level being dropped, since that data is going away
regardless) and checks that set for pairwise distinctness among the migrated rows
themselves, and separately against the replacement type's own already-existing live values
at that same level — refusing the whole substitution, naming the collision, if either check
fails. This composes for free with the existing per-level reconciliation rule rather than
needing its own traversal: a `Unique` group can never span fields declared at different
levels of a chain ("Uniqueness"), so it's already scoped to exactly one level, the same
granularity the reconciliation already walks.

Folded into `ARCHITECTURE_V2.md` ("Migrations and schema mutation" — a new paragraph right
after the four-mechanisms bullet list for prototype-deletion substitution, before
`EntityRetypeConverter`'s own `from()`/`to()` declaration) and `ROADMAP_V2.md` (Phase 6.4
gained a bullet alongside the `EntityRetypeConverter` one, and its "Done when" gained a
clause covering both a migrated-rows-collide and a collides-with-existing-row case).
Described in each document in the resolved design's own terms, per the standalone
requirement (see [[feedback_v2_docs_standalone]]).

### 3. Logging/undo status of substitution's bulk row mutations, and reparent's backfill insertions, is unspecified — **resolved (2026-10-06)**

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

**Decided: whether a schema-triggered data change goes through `Changeset` (and is
therefore logged) turns entirely on a real constraint, not on symmetry or forensic
completeness for its own sake.** The reason reparent's own level-removal goes through
`Changeset` was never actually "deletions deserve a forensic trail" — it's that
`entities.owner`'s own `RESTRICT` (see "References and collections") makes an unmanaged
bulk delete genuinely unsafe the instant the removed level declares an Owned relationship
whose own owned children, or anything transitively owned deeper, are still live.
`Changeset`'s topological sort and Owned-subtree-expansion is the only mechanism that
already solves that ordering problem safely; `EntityChangeRecord` is a byproduct of using
that path, not the reason for using it.

Insertions and in-place updates have no equivalent hazard: a backfilled row's own
defaulted-instance children, if any, are written in the one order that's already safe
(child first, then the row referencing it) — nothing like `RESTRICT` ever blocks a
well-ordered `INSERT`/`UPDATE`, so nothing forces either through `Changeset`. This resolves
both open cases, and is consistent with what the design already decided elsewhere without
stating the reason explicitly (ordinary new-field backfill, `#[Embed]`-site column
backfill — neither goes through `Changeset` either):

- **Reparent's backfill insertion**: a direct write, not logged, no `EntityChangeRecord`.
- **Substitution's three per-level outcomes split by the same rule**: a level present only
  in the old chain is an ordinary entity-row deletion and goes through `Changeset`, logged,
  for the identical `RESTRICT`-driven reason, at bulk scale across every migrated row. A
  level kept with updated values, or a level freshly inserted, is a direct write, not
  logged — same as reparent's own backfill.
- **Substitution's `#[Embed]`-occurrence reconciliation**: never goes through `Changeset`
  and is never logged, regardless of which of the three per-level outcomes applies to a
  given site — an `#[Embed]` site has no `entities`-rooted row to begin with, and its own
  column changes are already schema-level DDL in every other case this design treats them.

Each unlogged case is named as a deferred extension point, the same posture as the pruning
tool's post-prune observer hook (Round 7 item 4, "Media/file fields"): adding a hook later,
if some future need wants one of these observed or logged, needs no structural change
today, so it's deferred rather than designed now.

Folded into `ARCHITECTURE_V2.md` ("Reparenting" — a new paragraph on the backfill-insertion
side, explaining the `RESTRICT`-driven reasoning explicitly rather than leaving it implicit;
the substitution block — a clause on the CTI-level-reconciliation bullet and a clause on the
`#[Embed]`-occurrence bullet) and `ROADMAP_V2.md` (Phase 6.3's `reparent()` bullet and "Done
when," and Phase 6.4's substitution and `#[Embed]`-occurrence bullets and "Done when," all
gained the matching logged-vs-direct-write split). Described in each document in the
resolved design's own terms, per the standalone requirement (see
[[feedback_v2_docs_standalone]]).

### 3.1 — fixes

Item 3's own closing paragraph overstates what actually landed in the folded-back text.

"Each unlogged case is named as a deferred extension point, the same posture as the pruning
tool's post-prune observer hook" (above) claims this for all three unlogged cases. Only one
of them actually got it: `ARCHITECTURE_V2.md`'s reparent-backfill-insertion paragraph
("Reparenting") explicitly says "Worth naming as a deferred extension point... adding a hook
here later... needs no structural change today, so it's deferred rather than designed now."
Neither substitution-side unlogged case — the CTI-level-reconciliation bullet's
kept-with-updated-values/freshly-inserted outcomes, nor the `#[Embed]`-occurrence
reconciliation bullet — got an equivalent sentence, in either `ARCHITECTURE_V2.md`'s
substitution block or `ROADMAP_V2.md`'s Phase 6.4. "Each" is wrong; it's true for one case
out of three.

Not yet resolved: either add the matching deferred-extension-point sentence to both
substitution-side unlogged cases (in `ARCHITECTURE_V2.md`'s substitution block and the
corresponding `ROADMAP_V2.md` Phase 6.4 bullets), or narrow this document's own claim to
the reparent-insertion case only, whichever better reflects the actual design intent.

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
