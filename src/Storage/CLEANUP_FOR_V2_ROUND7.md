# Storage / Editor v2 — Cleanup Before Building (Round 7)

**Status: mostly resolved, item 8 has a known open follow-up (2026-10-06).** A seventh audit
pass over `ARCHITECTURE_V2.md`/`ROADMAP_V2.md`, done after Round 6's items were folded back
into both documents, plus three open design questions the user added afterward (items 6-8),
plus a ninth item (reparenting/`#[Embed]` interaction) surfaced while working through 7-8.
8 of 9 items are fully decided and folded back into `ARCHITECTURE_V2.md`/`ROADMAP_V2.md`
directly. **Item 8 is folded in but known too narrow** — working through its own mechanics
(see item 9's note, and the live discussion around collapsing "swapping a prototype" into
already-defined operations) surfaced that it needs to reach descendant prototypes' existing
rows too, not just exact-type rows; expect a follow-up revision. This file is kept as a
historical record of the discussion behind each decision, not as an open task list, item 8's
asterisk aside. Worked one item at a time per
[[feedback_coconut_cms_development_workflow]]. Ordered by how much it changes what gets
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

### 6. One class-level attribute and one field-level attribute, instead of a bunch of attributes — **resolved (2026-10-06)**

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

**Landed on a narrower consolidation than originally proposed, once worked through with
concrete examples.** The actual complaint was about *singular, always-at-most-one-per-class*
facts being needlessly split (`#[Table]`, `#[EditorExtensible]`), not about every per-field
concern needing to live in one call. Field-kind markers stay one-per-kind
(`#[Scalar]`/`#[ValueObject]`/`#[Embed]`/`#[Reference]`/`#[Collection]`, mirroring
`FieldDescriptor`'s own five factories 1:1) — this already fully preserves the
"invalid combinations unrepresentable" guarantee, since each attribute class's own
constructor still only accepts its own kind's parameters; there was never a need to
collapse these into a single kind-discriminated attribute and risk that guarantee at all.
`FieldPermission`, `FieldValidator`, `Unique`, and `PrototypeValidator` all stay their own
independent attributes too — `Unique`/`PrototypeValidator` because they're inherently
repeatable (a class can declare more than one of each), and permission/validators because
they're orthogonal to kind and apply identically regardless of it, so bundling them into a
kind marker would couple unrelated concerns without buying anything.

What *does* consolidate: `#[Table]` and `#[EditorExtensible]` — both singular, both
always-at-most-one-per-class — into one `#[Entity(table: ..., editorExtensible: ...)]`.
`#[DefaultInstance]` was considered for the same treatment and rejected: attribute arguments
must be compile-time constant expressions, and PHP has no form of "reference to this
method" that qualifies — not an array-callable (defeats the purpose of removing
indirection) and not first-class callable syntax (`Product::blank(...)` produces a
`Closure`, which isn't a constant expression either). `#[DefaultInstance]` stays on the
static factory method itself, exactly as Architecture's own prose already implied
("a static factory carrying `#[DefaultInstance]`") — never referenced by name from
`#[Entity(...)]`.

Folded into `ARCHITECTURE_V2.md` ("Shape comes from a neutral descriptor" — a new paragraph
naming the attribute-to-factory mapping and the `#[Entity]` consolidation; every other
`#[Table]`/`#[EditorExtensible]` reference in the document updated to match; "Deferred"
gained a one-line pointer) and `ROADMAP_V2.md` (Phase 1.1's `#[Table]` bullet and Phase 6.3's
heading/bullets/"Done when," all reworded to `#[Entity(...)]`, noting `editorExtensible`
exists unused from Phase 1.1 until 6.3 gives it meaning — decided once, not introduced in
two pieces five phases apart). Described in each document in the resolved design's own
terms, per the standalone requirement (see [[feedback_v2_docs_standalone]]).

### 7. Name and design the optional migrator class used in retype — **resolved (2026-10-06)**

Every retype operation throughout "Migrations and schema mutation" refers to "a supplied
converter" / "a converter class" without ever naming a concrete interface or class — also
already listed under Architecture's own "Deferred" ("Exact attribute/API surface for...
converter classes"). Worth resolving now that the retype mechanism itself is fully decided:
a name, and an interface shape matching the contract already settled elsewhere in the
document (invoked once per existing entity row of the table being retyped, never once per
item inside a single row's own collection; input is the row's own captured `NoType` value,
or for a collection field the whole assembled array; output is one new value or one new
array per call).

**Named `FieldRetypeConverter`** (not just `RetypeConverter` — item 8 needed its own,
differently-shaped converter, so the field-scoped one needed a name that says so):
`convert(mixed $captured): mixed`, exactly the contract already settled. **Also needed: a
way to reject an incompatible retype outright, and to let an admin-facing surface list only
the retypes a given field could actually use**, since nothing previously let the framework
check a supplied converter's assumptions against reality before running it. Resolved by
having `FieldRetypeConverter` declare itself statically — `from(): FieldRetypeSignature` /
`to(): FieldRetypeSignature`, checked by reflection alone, no instantiation needed.
`FieldRetypeSignature` is its own small value object (private constructor, named factories
mirroring `FieldDescriptor`'s own five kinds — deliberately not `FieldDescriptor` itself,
which describes a real field and shouldn't grow a partial/wildcard mode): a kind, plus an
optional, wildcardable target (`null` means "any target of this kind," recursively for a
`Collection`'s own item signature). Wildcards were deliberately included from the start
rather than added later if needed, on the premise that a converter handling a whole class of
retypes (not one fixed pair) is a real, anticipated use case, not a hypothetical one. A
retype naming a converter whose declared `from()`/`to()` don't match the field's actual
current shape and the candidate target shape is rejected outright, naming the mismatch — the
same fail-loudly posture as every other structural violation in this design.

A static method was chosen over a reflection attribute for the declaration specifically
because of what item 6 (one-attribute-per-declaration-site) already surfaced: attribute
arguments must be compile-time constant expressions, and "kind plus a possibly-wildcarded
target" doesn't comfortably fit that constraint the way a plain method return value does.

### 8. Entity-type deletion should optionally take a replacement class + a data-migration class, mirroring a field retype's converter — **resolved (2026-10-06)**

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

**The first real fork: does the replacement preserve the original row's `entities.id`, or is
it a brand-new, unrelated entity (the old one still cascade-deleted, the replacement just a
salvage of its data)?** Decided: **id preservation.** The weaker, salvage-only version was
considered first and rejected — it would leave every existing `Reference` pointing at the old
row nulled out exactly as an ordinary deletion already does, which undercuts the entire point
of calling this a "migration" rather than "deletion plus best-effort data recovery
somewhere else."

**That decision is what let the whole feature collapse into an optional enhancement on the
existing deletion operation, rather than a new one.** `Persistence\Schema\EntityRetypeConverter`
(`convert(array $capturedFieldTree): object`, declaring plain `from()`/`to()` prototype
identifiers — not wildcarded, unlike `FieldRetypeConverter`'s signature, since "replace X
with Y" is already a one-off, explicitly-named pair each time it's triggered, so the
declaration is purely a self-consistency check) is an optional pair of arguments on the same
`deletePrototype()` call:

- **Without them**: nothing changes — ordinary cascade-delete, ordinary `NoType` capture for
  anything pointing at the deleted type, exactly as already specified.
- **With them**: every row of exactly the deleted type — root, or an
  `OwningReference`/Owned-`Collection`-item, already "a full `entities` row exactly like a
  Shared one" per existing text — migrates in place instead of being cascade-deleted, `id`
  preserved, via the *same* insert/remove-a-CTI-level machinery "Reparenting" already
  specifies (drop rows from the old type's own level down to whatever's shared with the
  replacement's chain, if any; insert rows from there down to the replacement's own level) —
  the only new thing is that the converter's output replaces `#[DefaultInstance]` backfill as
  the value source. Every `#[Embed]` occurrence of the deleted type migrates in place through
  the *same* converter instead of degrading to `NoType` — possible because an owned row's own
  `NoType` capture already "mirrors `Embed`'s own capture," so root, Owned, and Embed all
  capture the identical full-field-tree shape elsewhere in this design; one converter serves
  all three, no separate embed-specific converter ever needed. Every plain
  `Reference`/`Collection`-of-reference occurrence gets its declared target type updated
  automatically, no converter involved at all, because none is needed: the id never changes,
  so retargeting a pointer's declared type is pure metadata, exactly as mechanical and
  lossless as prototype-rename propagation already is — not an exception to "no automatic
  fixing, ever," the same exemption rename propagation already earns, for the same reason.

This directly answers two of the three open sub-questions from the original proposal:
Owned-descendant cascade composes for free (an Owned descendant of the migrated row is
untouched by any of this — it's still owned by the same, unchanged `entities.id`); and the
per-row contract does need its own shape distinct from `FieldRetypeConverter`'s, exactly as
guessed, which is why it's a separately-named interface. The third (native vs. editor-created
availability) resolved to **both, uniformly** — native declares the replacement + converter
in the same reviewed migration, with the migration tool auto-discovering every
referencing/embedding site via the reverse-index machinery "Migrations and schema mutation"
already generalizes for exactly this kind of fan-out, instead of the developer hand-editing
each site; editor-created triggers it through `SchemaEditor`, the converter picked from a
closed, pre-registered menu, the same posture `PrototypeValidator` already uses.

Folded into `ARCHITECTURE_V2.md` ("Migrations and schema mutation" — a new paragraph naming
`FieldRetypeConverter`/`FieldRetypeSignature` right after the general retype-converter
contract; a new block on optional-replacement prototype deletion, `EntityRetypeConverter`,
and the three migration/retargeting rules, placed right after cascade-delete's own
paragraph) and `ROADMAP_V2.md` (Phase 6.2 gained the `FieldRetypeConverter` bullet and
matching "Done when" clauses; Phase 6.4 gained the `EntityRetypeConverter` bullet and
matching "Done when" clauses). Described in each document in the resolved design's own
terms, per the standalone requirement (see [[feedback_v2_docs_standalone]]).

**Note:** working through item 8's own mechanics further (below, item 9, and ongoing)
surfaced that "every row of exactly the deleted type" above is too narrow a scope for a
*substitution* specifically — a descendant prototype's existing rows also have a physical
row at the substituted level that needs migrating, which ordinary prototype *deletion*
correctly does not reach but a level *swap* must. Left as-is here since it's still being
worked through; expect a follow-up revision once that's settled.

### 9. Reparenting never specified its own interaction with `#[Embed]` targets — **resolved (2026-10-06)**

Surfaced while working through items 7-8: does reparenting a shape that's also used as an
`#[Embed]` target actually update every embedding site's own flattened columns? Neither
document ever said. "Reparenting" describes CTI-level insert/remove purely in terms of an
entity's own table structure; "an embedded shape gaining a field backfills every table that
embeds it" exists (Phase 2) but was never connected to reparenting as one of the ways a
shape gains a field; the mirror-image direction (a field disappearing from an embedded
shape dropping the corresponding column at every site) was never stated at all, for *any*
trigger, reparenting or an ordinary `dropField()` alike.

**Decided: reparenting's column-level consequences only ever show up at `#[Embed]` sites,
never at the reparented shape's own entity tables.** A CTI level is an already-existing
table (the parent class's own, shared by every other subclass extending it), so reparenting
an entity only ever adds/removes *a row* at that level — never a column. An `#[Embed]` site
has no separate level tables at all; the whole chain flattens into one row on someone else's
table, so a shape gaining or losing a level there *does* change column shape. Both
directions reuse the existing reverse-index discovery already generalized for rename/retype
propagation — no new discovery mechanism, just the previously-missing "and here's what
happens when fields disappear, not just appear" half: a level inserted adds new columns,
backfilled via `#[DefaultInstance]` (Phase 2's existing rule, reparenting being one more
trigger for it, not a separate mechanism); a level removed drops the now-stray columns,
true for a direct `dropField()` on an embedded shape too, not reparent-specific. Unlike the
entity-table side's row deletions (which already go through the ordinary `Changeset` path
and produce their own `EntityChangeRecord`s), this column add/drop is ordinary schema-level
DDL — no content-level undo trace, consistent with schema mutations generally.

Folded into `ARCHITECTURE_V2.md` ("Migrations and schema mutation," a new paragraph right
after "Reparenting"'s own entity-side row-deletion paragraph) and `ROADMAP_V2.md` (Phase
6.3 gained a bullet and matching "Done when" clauses, including a clause proving the
drop-direction isn't reparent-specific). Described in each document in the resolved design's
own terms, per the standalone requirement (see [[feedback_v2_docs_standalone]]).
