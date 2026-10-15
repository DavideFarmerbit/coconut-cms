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

- Give every native class a row in `prototypes` itself, not a separate, parallel table that
  happens to look similar. One schema representation for the whole system, not two shapes
  carrying the same kind of information: the `schema` column holds the exact same
  `FieldDescriptor[]`-serializable JSON for a native class as it already does for an
  editor-created one, including each field's currently-resolved default or pin alongside its
  structural shape (name, kind, type or target) in the same record, never split across two
  artifacts. Defaults aren't an optional add-on to a class's schema, they're a required part of
  it: a migration can't run without knowing what an untouched field currently resolves to, so
  there was never a coherent way to split "structure" from "defaults" into two things that get
  compared or consumed separately. `EntityRegistrar::register()`'s existing full-field-tree walk
  produces this row as a byproduct, the same way it already produces the defaulted-instance
  completeness check and the "who points at shape X" reverse index. `class_exists($identifier)`
  still is what distinguishes a native identifier from an editor-created one wherever that
  distinction matters (`PrototypeRegistry` resolution kind, `editorExtensible`, ...); having a
  `prototypes` row no longer implies "editor-created" the way it always has until now, so any
  code that was using "has a `prototypes` row" as a stand-in for that test needs to use
  `class_exists()` explicitly instead.
- This row is written at registration time and read by the strict check and by migrations. It
  is never read on the ordinary content-read path for a native identifier, which keeps calling
  live reflection directly, same as today, so this doesn't add a database round-trip to every
  native field access — only to the registration step itself, which already has to do
  comparable work to run the strict check at all.
- One side benefit worth naming: once native classes have real `prototypes` rows, the existing
  reverse-index and dangling-reference-auditing machinery (already built for editor-created
  schemas) can run uniformly across both kinds without any native-specific carve-out, since
  it's all just reading `prototypes.schema` rows at that point regardless of which kind
  authored them.
- On every request, compute the current schema fresh from live code and diff it against the
  persisted compiled schema left by the last successful registration. Any difference is a
  candidate for migration, not an immediate failure by itself.
- Fixups are declared directly in code as typed objects, never a separately generated migration
  file. One class per operation, each self-describing what it applies to and, where relevant,
  how to carry old data forward: `ClassRename(oldIdentifier, newIdentifier)`,
  `ClassReparent(class, newParent, ?migrator)` (migrator optional, falls back to
  `#[DefaultInstance]` backfill when omitted, same optional-converter shape `FieldRetype`
  already has, not a new pattern), `FieldRetype(class, fieldName, dataMigrator)` (rejected
  outright if the migrator's own declared old type doesn't match the field's actual current
  type, the same self-consistency check `FieldRetypeConverter::from()` already does), and
  equivalents for add/drop/reparent-removal. `fieldName` always names a field on the class that
  directly declares it, never a dotted path into an embedding or owning site — propagation to
  every site that embeds or owns that field is the existing reverse-index mechanism's job, not
  something the declaration itself has to spell out. These objects are ordinary code, reviewed
  the same way the rest of the commit is reviewed. There is no separate migration-file
  generation or review step for native at all.
- Strict mode (the only mode a production boot path ever runs): a found difference with no
  matching declared fixup blocks outright, naming the difference, before anything is attempted
  against the database. A found difference *with* a matching fixup runs that fixup as a
  migration. The migration validates everything it needs to before issuing a single DDL
  statement or touching a single row, so it either fully succeeds or is rejected outright,
  never left partially applied. Only once a migration (or the absence of any difference at all)
  succeeds does registration persist the newly-computed compiled schema and let the request
  proceed.
- Non-strict mode is a development convenience, not a production setting with a flag someone
  could leave on by mistake. It lives behind a separate bootstrap entry point a local dev
  command runs, one production's own bootstrap path never calls at all — not a runtime toggle
  sitting in ordinary config. In this mode, a found difference with no declared fixup is
  resolved by a fixed set of best-guess rules instead of blocking (the rules themselves are not
  decided yet, see below).
- Running this check at the start of every request, rather than as a separate deploy step, is
  what makes it work at all: there is no long-running process to run it once at boot instead.
  If strict mode blocks, every request fails the same way, loudly, until the mismatch is fixed
  by adding the missing declared fixup. Maintenance mode is a separate, optional tool on top of
  this, not the mechanism that provides the guarantee: an operator can turn it on deliberately
  while working through a reconciliation, to keep real traffic away from a site that would
  otherwise be failing every request in the meantime. The guarantee itself comes from strict
  mode blocking by default, with or without maintenance mode being used.
- This also answers `PROPOSAL_FOR_V2_DEFAULT_TRACKING.md`'s own open question. A retype's
  migrator, for a field currently tracking a live default, reads its old value off the
  persisted compiled schema left by the previous successful registration, never off live code
  that might already be gone. The compiled schema only ever updates after a registration that
  blocked every live request until it succeeded, so there is no window where it gets
  overwritten before a migration gets the chance to use it.
- The check runs against every native class on every request, not a selective subset —
  "only check classes touched by a declared fixup" is circular, since the one case this exists
  to catch is exactly the class with no declared fixup at all. This is affordable without
  skipping any class: computing each class's live schema via reflection is in-process, no I/O;
  the expensive part would be comparing that against the database on every single request when
  the answer is almost always "nothing changed." That comparison runs against a fast cache of
  the persisted side instead, with the database consulted only to confirm an apparent mismatch
  before acting on it — this also absorbs a multi-server deploy, where one server's
  registration already succeeded while another server's local cache hasn't caught up yet: it
  just finds the database already matches and updates its own cache, no redundant migration
  attempt. **The one invariant that has to hold exactly: the cache is written only after the
  database write is confirmed committed, never before and never alongside it.** If a migration
  fails, nothing commits, so the cache is never touched either — the next request still finds
  live code disagreeing with a cache that correctly still reflects the old, real state,
  re-verifies against the database, confirms it genuinely never updated, and blocks again, same
  as the first failure. Writing the cache speculatively, or in parallel with the database write
  instead of strictly after it, would let a failed migration look like a successful one to
  every request after it — silently worse than not caching at all.

**What stands in the way.**

- Bricking every request until a human adds the missing fixup is the point, not a flaw to
  design around. It turns a would-be silent data-loss bug into a loud, site-wide failure,
  matching the "reject outright, name the problem" posture this design already applies
  everywhere else, now applied to deploy-time drift instead of a single registration-time
  check.
- This changes what "Migrations and schema mutation" means for native specifically, more than
  item 3 first assumed. Today's document describes a reviewed, generated migration file as the
  native mechanism. That step goes away entirely under this plan — the declared-fixup objects
  *are* the reviewed artifact, reviewed as part of the ordinary commit, with registration itself
  doing the diffing and the DDL generation that a separate tool used to do.
- `ARCHITECTURE_V2.md`'s own "Reparenting" section currently has no converter/migrator option at
  all — backfill is stated as always going through `#[DefaultInstance]`. `ClassReparent`'s
  optional migrator argument is a real gap in that section, not just a detail of the migrator
  object's own shape: there's a legitimate case for it (splitting one flat class into base and
  derived levels, where the newly-inserted level's data should come from the existing row's own
  fields rather than a fixed class default), and the architecture doc needs its own update to
  say so, independent of how the migrator object ends up being declared.
- Editor-created prototypes need none of this. `prototypes.schema` is already a live, persisted
  row, with no code or deploy step involved and no diffing ambiguity, since every editor-created
  mutation is already an explicit, named operation, never inferred. The native/editor split this
  introduces is not a new asymmetry. It is the same reviewed-deploy-versus-live-mutation split
  the rest of the document already organizes itself around.

**Open questions, not decided yet:**

1. The non-strict mode's own best-guess rules for an undeclared difference (same field name and
   same type: no-op; same name, different type: probably a retype with no migrator; a field
   present only in the new code with no sibling removed: probably an add; etc.). Explicitly not
   decided yet — needs its own pass once the strict-mode mechanism itself is settled.
2. Where exactly the fast cache of the persisted schema lives (APCu, Redis, something else
   already in use elsewhere in the stack). The design around it (what gets cached, when it's
   written, what re-verifies against the database) is settled above — this is purely a choice
   of backend.
3. Roadmap placement. This changes Phase 1.1/1.2's own registration machinery, not a late phase.
   Needs deciding at the merge pass whether it lands inside Phase 1 itself or as its own
   sub-phase, alongside wherever `PROPOSAL_FOR_V2_DEFAULT_TRACKING.md`'s own phase gets chosen.
   Since native classes now get real `prototypes` rows, this also can't land before Phase 6.1
   introduces that table in the first place — native registration producing its own
   `prototypes` row has to come after the table exists, not before.
