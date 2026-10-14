# Storage / Editor v2 — Cleanup Before Building (Round 17)

**Status: open (2026-10-14).** Item 1, surfaced while designing
`PROPOSAL_FOR_V2_DEFAULT_TRACKING.md`'s own `Unique`-enforcement rule — not something that
proposal introduces, a gap already present in the current, already-decided architecture,
independent of whether that proposal is ever adopted — is resolved. Item 2, surfaced the same
way but considerably larger in scope, is not yet resolved — recorded here as a direction with
open sub-questions, not a finished decision. Worked per
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
