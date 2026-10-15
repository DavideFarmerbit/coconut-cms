# Storage / Editor v2 — Cleanup Before Building (Round 17)

**Status: open (2026-10-14).** Item 1, surfaced while designing
`PROPOSAL_FOR_V2_DEFAULT_TRACKING.md`'s own `Unique`-enforcement rule — not something that
proposal introduces, a gap already present in the current, already-decided architecture,
independent of whether that proposal is ever adopted — is resolved. Item 2, surfaced the same
way but considerably larger in scope, is not yet resolved — recorded here as a direction with
open sub-questions, not a finished decision. Item 3, surfaced while working through that same
proposal's own retype-capture question, is a pre-existing gap in the native migration pipeline
itself, independent of whether that proposal is ever adopted. It is also not yet resolved,
recorded here as a direction with open sub-questions. Worked per
[[feedback_coconut_cms_development_workflow]].

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

## Open, large in scope

### 2. An Owned entity's identity is ephemeral, not stable — "no carve-out for Owned entities" in the undo log may need reversing — not yet resolved

**What's wrong.** "Content undo, draft, and revision history" states, as a deliberate,
foundational rule: every entity a flush touches gets its own independent
`EntityChangeRecord`, "no carve-out for Owned entities," justified by "an Owned entity has a
full `entities` row exactly like a Shared one, so nothing structurally distinguishes it in
the undo/logging path." That justification assumes an Owned entity's own identity is as
stable as a standalone or Shared-referenced row's. It isn't. "Retargeting an
`OwningReference`/Owned-`Collection`'s own item type while staying Owned" already specifies
the ordinary mechanism for changing what concrete type occupies an Owned slot: capture the
old row's full field tree, cascade-delete it, construct a new one from a converter or the
class default — the same shape as Unreal destroying and recreating an instanced subobject
when its class changes. That's not a rare migration path, it's the normal way this already
works. An `EntityChangeRecord` keyed to an Owned entity's own id becomes orphaned the moment
that id is destroyed this way — undoing an edit made before the swap has nothing coherent
left to reference, since the row it names no longer exists and the slot now holds something
else entirely.

**What we want to do.** Fold an Owned entity's edits into its nearest *non-Owned* ancestor's
own `EntityChangeRecord` instead of giving it an independent one — recursively through
arbitrary Owned depth (an Owned entity that itself owns further Owned entities folds all the
way to the first standalone or Shared-referenced ancestor, not just one level up, since an
intermediate Owned entity's own id is exactly as ephemeral as the one that motivates this in
the first place).

**What stands in the way.**

- This reverses a deliberate decision, not an oversight — the 2026-09-30 cleanup pass
  explicitly dropped the older "folds into owner's diff" rule in favor of independent records,
  specifically because Owned entities had just gained full `entities` rows. That reasoning
  needs to be named as superseded, not silently dropped.
- It also reopens Round 13's own item 2, which considered bubbling a dedicated-child-table
  write up an ownership chain and explicitly rejected it, citing "no carve-out for Owned
  entities" as the reason. If that principle reverses, Round 13's own conclusion needs
  revisiting for consistency, not left standing on a premise that no longer holds.
- `expectedOperationId` ("Content write path") currently carries a real mechanism built on the
  independent-record assumption: "the latest such position touching it or anything it
  transitively owns — recursive over the owned subtree, found by walking what's still live...
  plus, for anything no longer live to walk to directly, that entity's own
  `EntityChangeRecord`-captured `owner`/`owner_field`." If Owned edits fold directly into the
  root's record at write time, the root's own latest record position already answers this —
  no walk needed. That's a real simplification, but it means this section gets rewritten, not
  just patched.
- The reason each `EntityChangeRecord` captures its entity's own `owner`/`owner_field` — "lets
  concurrent-write protection trace a since-deleted Owned descendant's place in its former
  owner's subtree without needing a live `entities.owner` link" — is itself justified by Owned
  descendants having independent records to trace. That justification goes away if they don't.
- `ROADMAP_V2.md`'s own "Done when" assertions (Phase 3.3 onward, wherever they assert an
  Owned entity gets its own `EntityChangeRecord`) would need rewriting to match, not just
  `ARCHITECTURE_V2.md`.
- Mechanically, whatever builds the record at write time needs a way to find "the nearest
  non-Owned ancestor" for an entity that might be several Owned levels deep — presumably the
  same `owner`/`owner_field` walk already reused elsewhere (e.g. the owner-deletion
  auto-expansion), not a new lookup, but this hasn't been confirmed.

**Open questions, not decided yet:**

1. Fold all the way to the nearest non-Owned ancestor (recursive), or only one level up to the
   direct owner? The direct-owner-only version doesn't actually fix the problem for anything
   Owned two or more levels deep, since the intermediate owner's own id is just as ephemeral —
   but this hasn't been explicitly confirmed as the chosen direction, only reasoned toward.
2. Is the `expectedOperationId` simplification (root's own latest record position, no
   recursive subtree walk) accepted as a consequence worth taking, given it means rewriting
   that mechanism rather than patching it?
3. Does `FieldPermission`'s own "still per-field, independent regardless of Owned/Shared" stay
   true unchanged, or does record-folding have any bearing on it? (Current read: unaffected,
   since permission is about who can write a field, not how the write gets logged — but not
   explicitly verified.)

### 3. Native schema changes have no deploy-time safety net against undeclared drift, and no durable record of the old shape or the old default — not yet resolved

**What's wrong.** "Migrations and schema mutation" already says a native rename or retype is
"passed explicitly... instead of inferred by diffing two snapshots." Nothing actually checks
this at deploy time. A schema-diffing tool cannot tell a renamed field from a dropped field
plus an unrelated new field of the same type. If a developer forgets to declare a rename, the
tool reads it as drop-and-add instead. Data is lost silently, not loudly. Every other
structural violation in this design fails loudly at the earliest possible point (Embed-of-Embed
cycles, table-name collisions, defaulted-instance completeness). This one does not.

Separately, neither document says how a deploy relates to a request, or how registration
relates to running a migration. `EntityRegistrar::register()` runs "at registration time," but
nothing says when that is. A real deploy can upload new code before its matching migration
runs. A live request can hit the new code in that gap. The code and the database can disagree
about a field's shape for the whole time that gap stays open. This risk exists for any native
retype, with or without touched/defaulted tracking layered on top.

Both gaps surfaced together while working through `PROPOSAL_FOR_V2_DEFAULT_TRACKING.md`'s own
open question: where does a retype's converter get "the old value" for a field that was only
ever tracking a live default, once the old resolver method might not survive the same commit
that changes the field's type. Answering that needs some durable record of the old shape and
the old default. The deploy-ordering gap above rules out writing that record at ordinary,
per-request registration time: an ordinary request can run before a human ever triggers the
matching migration, and would overwrite the one copy of "the old state" before anything gets
to use it.

**What we want to do.**

- Give every native class a persisted compiled schema, one row per class, parallel in shape to
  `prototypes.schema` for an editor-created one. `EntityRegistrar::register()`'s existing
  full-field-tree walk produces this as a byproduct, the same way it already produces the
  defaulted-instance completeness check and the "who points at shape X" reverse index. The
  compiled schema records each field's structural shape (name, kind, type or target) and, once
  `PROPOSAL_FOR_V2_DEFAULT_TRACKING.md` lands, each field's currently-resolved default or pin.
- Add a strict check at the start of registration. Compare the schema just computed from live
  code against the persisted schema left by the last successful registration. Block outright,
  naming the difference, if anything differs without an explicitly-declared fixup for this
  deploy (a rename mapping, a retype's converter and signature, a reparent, an explicit drop).
  Only once every difference is accounted for does registration persist the new compiled
  schema and let the request proceed.
- Run this check at the start of every request, not as a separate deploy step. There is no
  long-running process to run it once at boot instead. If the check fails, every request fails
  the same way, loudly, until the mismatch is fixed. This acts as a maintenance mode without
  needing a dedicated maintenance-mode feature: the site goes down hard instead of serving one
  request against a half-migrated schema.
- This also answers `PROPOSAL_FOR_V2_DEFAULT_TRACKING.md`'s own open question. A retype's
  converter, for a field currently tracking a live default, reads its old value off the
  persisted compiled schema left by the previous successful registration, never off live code
  that might already be gone. The compiled schema only ever updates after a registration that
  blocked every live request until it succeeded, so there is no window where it gets
  overwritten before a migration gets the chance to use it.

**What stands in the way.**

- Bricking every request until a human fixes a mismatch is the point, not a flaw to design
  around. It turns a would-be silent data-loss bug into a loud, site-wide failure, matching the
  "reject outright, name the problem" posture this design already applies everywhere else, now
  applied to deploy-time drift instead of a single registration-time check.
- This changes what "Migrations and schema mutation" means for native specifically. Today it
  describes generation-time declarations (a rename mapping, a converter) without describing
  anything that verifies nothing else slipped through undeclared. This adds that verification
  as a first-class part of registration, not a tool a developer might remember to run.
- Editor-created prototypes need none of this. `prototypes.schema` is already a live, persisted
  row, with no code or deploy step involved and no diffing ambiguity, since every editor-created
  mutation is already an explicit, named operation, never inferred. The native/editor split this
  introduces is not a new asymmetry. It is the same reviewed-deploy-versus-live-mutation split
  the rest of the document already organizes itself around.

**Open questions, not decided yet:**

1. What exactly counts as "this deploy's own explicitly-declared fixups," as an input the
   strict check reads. Today, a rename mapping and a retype converter are already passed at
   migration-generation time. Does the check read these straight from the generated migration
   file, or does a developer separately redeclare them for the check to compare against? Not
   decided.
2. Whether the structural half of the compiled schema (field shapes) and the default-value half
   (each field's currently-resolved default or pin) are one persisted artifact or two. Both come
   from the same walk, but the default half only matters once
   `PROPOSAL_FOR_V2_DEFAULT_TRACKING.md` itself lands. Building the structural half first, on
   its own, is possible either way.
3. Whether the check runs against every native class on every request, or only against classes
   actually touched by a pending migration. Checking everything, every request, is the simplest
   rule and matches "no special-casing" everywhere else in this design, but its per-request cost
   has not been measured.
4. Roadmap placement. This changes Phase 1.1/1.2's own registration machinery, not a late phase.
   Needs deciding at the merge pass whether it lands inside Phase 1 itself or as its own
   sub-phase, alongside wherever `PROPOSAL_FOR_V2_DEFAULT_TRACKING.md`'s own phase gets chosen.
