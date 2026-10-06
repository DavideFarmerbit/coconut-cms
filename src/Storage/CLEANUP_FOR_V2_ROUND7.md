# Storage / Editor v2 — Cleanup Before Building (Round 7)

**Status: open (2026-10-05).** A seventh audit pass over `ARCHITECTURE_V2.md`/
`ROADMAP_V2.md`, done after Round 6's items were folded back into both documents. Unlike
prior rounds' files, this one is still an open task list — items below are findings from
the audit, not yet decided or folded back into either document. Worked one item at a time
per [[feedback_coconut_cms_development_workflow]]. Ordered by how much it changes what gets
built, not alphabetically.

## Real inconsistencies

### 1. `Actor`'s introduction in Phase 5.3 comes after Phase 5.2 already needs an identity check, and `ROADMAP_V2.md`'s claim about its scope is broader than `ARCHITECTURE_V2.md`'s own — **resolved (2026-10-06)**

Roadmap Phase 5.2 ("Undo, redo, authorization") requires checking that "the Revision
belongs to the requesting user/session" before computing the inverse changeset — this is
the mechanism behind Architecture's "Undo authorization... attaches at the `Revision`"
paragraph. But `Persistence\Permission\Actor` isn't introduced until the very next
sub-phase, 5.3, described there as "the minimal interface every permission check in this
design is built on."

That phrase is also broader than what `ARCHITECTURE_V2.md`'s own "Permissions" section
claims: "A minimal `Actor` interface (`hasRole(string): bool`) backs **all three**" —
naming `SchemaPermission`/`FieldPermission`/`HistoryPermission` specifically, not the
undo-Revision-ownership check, which is an identity/ownership comparison ("does this
Revision belong to this requester"), not a role check. Neither document ever states what
the 5.2 check is actually built on, given `Actor` doesn't exist yet at that point and may
not even be the right kind of interface for an ownership comparison anyway.

**`Revision.actor` was never meant to be an `Actor` instance in the first place — a clue
hiding in plain sight, since `Revision` (introducing the `actor` field) lands in 5.1, a
full sub-phase before `Actor` itself exists in 5.3.** Both sub-problems dissolve once that's
made explicit: `Revision.actor` is a plain, opaque identifier (presumably a UUID) naming
whoever triggered the flush, nothing more. The 5.2 ownership check is then a plain value
comparison against that field, available from 5.1 onward, needing nothing from 5.3 at all —
the sequencing problem disappears. And `Actor` stays exactly as narrow as Architecture's own
"Permissions" section already said: a pure adapter interface backing the three named
role-based checks (`SchemaPermission`/`FieldPermission`/`HistoryPermission`), never identity
storage of its own — `ROADMAP_V2.md`'s "every permission check in this design" phrasing was
simply an overclaim, corrected to match.

This raised a real design question, though: for `Revision.actor` to be useful (showing "who
edited what" in revision history, not just an opaque value), something needs to own a table
of real registered users. Where does that live? `Persistence\` can't own it directly as a
concrete dependency — `Persistence\Changeset\Undo\Revision` would then depend on something
nothing else in `Persistence\` needs, and worse, there's no existing user/auth system
anywhere in this codebase to hook into (checked: no `User`/`Admin` class exists outside
`Storage\`). The resolution: user registration is **out of scope for this document
entirely**, the same deferral posture already used for `Editor\`'s own design. `Actor`
stays a pure wrapper/adapter interface, exactly as it was already described — whatever
system eventually registers real users (a new, separate namespace, not designed here, not
even named yet) implements `Actor` over its own data to answer `hasRole()`. `Revision.actor`
stays a plain UUID with no enforced database-level FK (which would itself be a schema
dependency on a table `Persistence\` doesn't own) — the same loosely-coupled-identity
posture `entities.id` itself already uses. Resolving that UUID into a real person's name for
display is left entirely to whatever ends up owning user registration.

Folded into `ARCHITECTURE_V2.md` ("Content undo, draft, and revision history" — a clause on
`Revision.actor`'s type, and the "Undo authorization" paragraph clarified as a value
comparison, not an `Actor::hasRole()` call; "Permissions" — `Actor` described explicitly as
a pure adapter, not identity storage; "Explicitly out of scope" — gained a new bullet naming
user/admin registration and authentication) and `ROADMAP_V2.md` (Phase 5.1's `Revision`
bullet, Phase 5.2's undo-authorization bullet, and Phase 5.3's `Actor` bullet, all reworded
to match). Described in each document in the resolved design's own terms, per the
standalone requirement (see [[feedback_v2_docs_standalone]]).

### 2. `NoType` was never reconciled against the original `FieldDescriptor` factory enumeration — **resolved (2026-10-06)**

"Shape comes from a neutral descriptor" states the complete set of named static factories —
`scalar()`, `valueObject()`, `embed()`, `reference()`, `collection()` — framed as making
"invalid combinations... unrepresentable rather than just unlikely." `NoType` is introduced
much later (6.4) as a sixth, structurally-Value-Object `FieldDescriptor` kind, but the
original enumeration is never revisited to account for it. Unclear whether it has its own
factory, reuses `valueObject()`, or is constructed only by internal framework code (plausible,
since it's never developer-declared the way the other five kinds are — but never stated
either way).

**Decided: `NoType` is its own distinct `FieldKind`, not a special-cased `valueObject()`
reusing a reserved `Type` class.** The alternative (treat `NoType` as plain `valueObject()`
pointed at a framework-reserved `Type` class, no new kind needed at all) was considered and
rejected: nothing would then stop an admin from manually creating an ordinary field that
happens to target that same reserved `Type` class, making "genuinely degraded" and
"coincidentally same type" indistinguishable from the `FieldKind` alone. A real sixth kind
closes this off, and also makes the dangling-target auditing tool's job a direct kind check
rather than an identity check against a reserved class. The text's own wording already
pointed this way — "a reserved `FieldDescriptor` **kind**," not "a special case of the Value
Object kind."

**`NoType` has no public factory, unlike the other five.** It is never developer-declared —
no native class or `SchemaEditor` field is ever authored directly as `NoType` — so it's
constructed only by the framework's own capture/retype machinery, the same "private
constructor, named static factories" pattern already governing `FieldDescriptor`, just with
this one factory being internal rather than part of the public API the other five make up.

Folded into `ARCHITECTURE_V2.md` ("Shape comes from a neutral descriptor" — a new sentence
naming `NoType` as the sixth, factory-less kind; the "`NoType`" bullet under "Migrations and
schema mutation" — clarified as its own `FieldKind`, not a special-cased `valueObject()`)
and `ROADMAP_V2.md` (Phase 6.4's `NoType` bullet reworded to match). Described in each
document in the resolved design's own terms, per the standalone requirement (see
[[feedback_v2_docs_standalone]]).

## Missing pieces

### 3. `expectedOperationId`'s storage/generation mechanism is never specified anywhere — **resolved (2026-10-06)**

`entities`' columns are fully enumerated in "Global entity identity": `id`, `owner`,
`owner_field`, `position`, `concrete_identifier`. No version/operation-id column appears
there or anywhere else. "Content write path"'s concurrent-write protection needs "the
latest operation touching it or anything it transitively owns," but nothing says where
that value is stored or how it's computed.

This also creates a sequencing problem: `ROADMAP_V2.md` Phase 4 ("Changeset write path")
already tests `expectedOperationId` staleness detection in its "Done when" — but
`Revision`/`EntityChangeRecord`, the only mechanism either document ever describes for
tracking "an operation," doesn't exist until Phase 5. Either a separate, undocumented
optimistic-lock mechanism is needed, or Phase 4's "Done when" depends on machinery that
isn't built until the following phase.

**Decided: no new column, no second counter.** `expectedOperationId` is sourced directly
from the undo log — the monotonic sequence position of the most recent `Revision` whose
`EntityChangeRecord` touched the entity. A dedicated `entities`-level counter was considered
and rejected: it would be a second, independently-maintained mechanism tracking the same
fact `EntityChangeRecord` already tracks, the same objection that already ruled out a
parallel `unique`-flag mechanism in Round 6 item 2. Reusing the undo log also closes a
correctness gap a counter-column design would have had: `Clear` (and the deletion half of
`Remove`) makes an owned row vanish entirely with no surviving row to carry a version bump —
but it still produces its own `EntityChangeRecord`, so the signal survives the row's own
deletion for free.

**Consequence: the mechanism cannot exist before `EntityChangeRecord` does, so it moves out
of Phase 4 entirely and into Phase 5.1.** Phase 4's own "Done when" previously claimed to
prove `expectedOperationId` staleness-rejection, which it never actually could — nothing in
Phase 4 sources a real value for it. Phase 5.1 ("Logging only, no undo yet," renamed
"Logging and concurrent-write protection, no undo yet") is the first point `EntityChangeRecord`
exists, so it's the first point this can be genuinely built and tested, not just described.

**One more real gap surfaced along the way: a since-deleted Owned descendant can't be found
by walking `entities.owner`, because its own `owner` link is gone with the row.** For nested
ownership (`X` owns `Y` owns `Z`, `Z` removed), checking `X`'s whole subtree needs to notice
`Z`'s disappearance without a live link from `Z` back up to `X`. Fixed by having
`EntityChangeRecord` also capture the entity's own `owner`/`owner_field` as of that write
(for both an update and a delete) — cheap, since it's already read during the write
regardless. Checking a subtree then means: walk what's still live via `entities.owner`
normally, and for anything missing, consult its own deletion record's captured owner to keep
resolving upward through whatever of the chain still exists (only the actual leaf that
changed is ever "new" in a race; an ancestor that was also deleted is a different rejection
case, not a stale-id mismatch).

Folded into `ARCHITECTURE_V2.md` ("Content write path" — the "Concurrent-write protection"
paragraph now names its actual source and the owner/owner_field-capture mechanism for
deleted descendants; "Content undo, draft, and revision history" — `EntityChangeRecord`'s
own description gained the `owner`/`owner_field` capture) and `ROADMAP_V2.md` (the
`expectedOperationId` bullet and its "Done when" clause moved from Phase 4 to a renamed
Phase 5.1, with Phase 4's own "Not yet" list noting where it went and why). Described in
each document in the resolved design's own terms, per the standalone requirement (see
[[feedback_v2_docs_standalone]]).

### 4. `MediaAsset`'s physical-file-byte reclaim is asserted in `ARCHITECTURE_V2.md` but never scheduled in `ROADMAP_V2.md` — **resolved (2026-10-06)**

"Media/file fields" describes reclaiming a removed `MediaAsset`'s physical file bytes by
piggybacking on the manual pruning tool (Phase 5.3) rather than an automatic sweep. No
Roadmap phase tests this — not 3.4, where `MediaAsset` is introduced as "the worked example
exercising both Owned... and Shared... at once," and not any later phase either. Unlike
`Draft`, which gets an explicit callout under "Not covered by this roadmap," this gap is
silent. Neither document ever states where or how file bytes are actually persisted (local
disk, object storage, an adapter interface) in the first place.

**Decided: file-byte storage and reclaim is out of scope for this document entirely, the
same deferral posture as user registration (item 1) and `Draft`.** Pulling on why the
"piggybacking" language never got scheduled anywhere surfaced the real reason: it implied an
already-designed integration between the pruning tool and file storage, but nothing else in
either document supports that — no event/hook mechanism exists anywhere else in this design,
and inventing one just for this would be new, unmotivated machinery. File-byte storage also
isn't one of the four backend concerns this roadmap actually covers
(`Entity`/`Schema`/`Changeset`/`Permission`) — `MediaAsset` is, from `Persistence\`'s own
point of view, an ordinary entity with whatever locator field its own storage needs, nothing
more. The manual pruning tool stays scoped exactly as already described: it prunes
`EntityChangeRecord`/`Revision` rows, full stop.

**One deferred extension point is named explicitly, though, rather than left silent — it
needs no structural change to add later, which is what makes deferring it safe.** The
pruning tool already has to read a record's full structured per-field content before
deleting it (`EntityChangeRecord` is a structured diff, never an opaque blob — "No blobs"
already guarantees this). An optional, injectable observer notified with that same content
immediately before the delete runs — letting an application decide for itself whether a
just-pruned value means some referenced file's bytes are now safe to reclaim — is addable at
that point with zero schema change, so there's no structural prerequisite missing today that
deferring it would foreclose. This is the test applied generally to anything proposed for
deferral here: easy to add later with no structural change, defer it; needs one, decide it
now.

Folded into `ARCHITECTURE_V2.md` ("Media/file fields" — rewritten to state the scope
boundary plainly and name the deferred observer hook; "Explicitly out of scope" — gained a
matching bullet) and `ROADMAP_V2.md` (Phase 5.3's pruning-tool bullet, noting the hook is a
deliberate deferral, not an oversight). Described in each document in the resolved design's
own terms, per the standalone requirement (see [[feedback_v2_docs_standalone]]).

## Roadmap coverage gaps

### 5. Lazy loading / `IdentityMap` correctness have no "Done when" in any phase — **resolved (2026-10-06)**

"Identity Map + Repository + lazy loading" states a hard requirement: "References/
collections must not eagerly hydrate on an entity's own load — only on actual access," plus
the standard same-id-resolves-to-same-instance identity-map guarantee. `IdentityMap` is
named once, in Phase 1.2's bullet list ("identity map scoped per request"), but no phase's
"Done when" anywhere tests either guarantee — not Phase 1.2 itself, and not Phase 3 where
references/collections (the things that must stay lazy) are introduced. This is exactly the
bug class the *old* roadmap had to retrofit-fix after the fact (its own Phase 6.1 "lazy
loading" audit fix, per [[project_storage_editor_status]]) — worth a real test in this
rewrite rather than risking the same rediscovery.

**Decided: no design change needed, purely a missing-test-coverage gap.**
`ARCHITECTURE_V2.md` already states both guarantees correctly; `ROADMAP_V2.md` just never
assigned either one a "Done when." Split across two natural points instead of one, since
they become testable at different times:

- **Identity-map reuse** (same id resolves to the same PHP instance) needs nothing beyond
  what Phase 1.2 already builds — added to its "Done when" directly.
- **Laziness** needs an actual reference/collection field to prove against, so it lands in
  Phase 3 instead: the full laziness-plus-identity-map-reuse proof in 3.2 (Shared, the first
  point either kind of field exists), a shorter laziness-only re-proof in 3.3 (confirming the
  same rule holds for Owned's reverse-indexed-query shape, not just a stored FK), and a
  laziness-only proof in 3.4 (non-entity collections have no identity of their own to reuse,
  so only the laziness half applies there).

Folded into `ROADMAP_V2.md` only — `ARCHITECTURE_V2.md` already described the requirement
correctly; this item was purely a scheduling gap, not a design one. Phase 1.2's "Done when"
gained the identity-map clause; Phases 3.2/3.3/3.4 each gained a laziness clause scoped to
what's newly provable at that point.

## Open design questions

### 6. One class-level attribute and one field-level attribute, instead of a bunch of attributes

"Shape comes from a neutral descriptor" implies a scattered attribute surface without ever
enumerating it: class-level `#[Table]`, `#[EditorExtensible]`, `#[DefaultInstance]` are each
named separately, and a native field's kind/parameters presumably reflect off one attribute
per `FieldDescriptor` factory (`scalar()`, `valueObject()`, `embed()`, `reference()`,
`collection()`, each with its own kind-specific parameters), never actually named or
enumerated as attributes anywhere. Proposal: collapse to exactly one class-level attribute
and one field-level attribute, each carrying everything needed at that level (for a field:
kind, kind-specific parameters, permission, validators, `Unique` membership, default) as
named arguments, rather than a bunch of independent, possibly-overlapping attributes. Already
touched by Architecture's own "Deferred" ("Exact attribute/API surface"), but this narrows it
to a specific proposal rather than leaving the shape unstated. Needs its own design pass: what
the single field-level attribute's parameter shape looks like, whether a single attribute can
still keep the closed-set-of-mutually-exclusive-kinds property the separate named factories
exist to guarantee ("makes invalid combinations unrepresentable"), and whether an
editor-created field (which has no PHP attribute at all, being pure data) needs its own
equivalent single-shape representation for consistency.

### 7. Name and design the optional migrator class used in retype

Every retype operation throughout "Migrations and schema mutation" refers to "a supplied
converter" / "a converter class" without ever naming a concrete interface or class — also
already listed under Architecture's own "Deferred" ("Exact attribute/API surface for...
converter classes"). Worth resolving now that the retype mechanism itself is fully decided:
a name, and an interface shape matching the contract already settled elsewhere in the
document (invoked once per existing entity row of the table being retyped, never once per
item inside a single row's own collection; input is the row's own captured `NoType` value,
or for a collection field the whole assembled array; output is one new value or one new
array per call).

### 8. Entity-type deletion should optionally take a replacement class + a data-migration class, mirroring a field retype's converter

Today, "Prototype/class deletion... cascade-deletes every existing entity row of exactly that
concrete type" unconditionally — the only content-preserving path a prototype deletion has is
the `NoType`-capture machinery for *other* fields that merely pointed at the deleted rows,
never for the deleted rows' own data. Proposal: let a prototype deletion optionally carry a
replacement identifier plus a migration class, the same shape a field-level retype's optional
converter already has, so existing rows of the deleted type get migrated into the replacement
type instead of lost to cascade-delete. Needs its own design pass: how this composes with
cascade-delete of Owned descendants (does the replacement row inherit the old row's own owned
subtree, or does that still cascade regardless), whether it's available to `SchemaEditor` for
editor-created prototypes or native-migration-only, and whether the migration class's
per-row contract should match retype's exactly or needs its own shape given it's migrating a
whole row rather than one field.
