# Storage / Editor v2 — Cleanup Before Building (Round 7)

**Status: open (2026-10-05).** A seventh audit pass over `ARCHITECTURE_V2.md`/
`ROADMAP_V2.md`, done after Round 6's items were folded back into both documents. Unlike
prior rounds' files, this one is still an open task list — items below are findings from
the audit, not yet decided or folded back into either document. Worked one item at a time
per [[feedback_coconut_cms_development_workflow]]. Ordered by how much it changes what gets
built, not alphabetically.

## Real inconsistencies

### 1. `Actor`'s introduction in Phase 5.3 comes after Phase 5.2 already needs an identity check, and `ROADMAP_V2.md`'s claim about its scope is broader than `ARCHITECTURE_V2.md`'s own

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

### 2. `NoType` was never reconciled against the original `FieldDescriptor` factory enumeration

"Shape comes from a neutral descriptor" states the complete set of named static factories —
`scalar()`, `valueObject()`, `embed()`, `reference()`, `collection()` — framed as making
"invalid combinations... unrepresentable rather than just unlikely." `NoType` is introduced
much later (6.4) as a sixth, structurally-Value-Object `FieldDescriptor` kind, but the
original enumeration is never revisited to account for it. Unclear whether it has its own
factory, reuses `valueObject()`, or is constructed only by internal framework code (plausible,
since it's never developer-declared the way the other five kinds are — but never stated
either way).

## Missing pieces

### 3. `expectedOperationId`'s storage/generation mechanism is never specified anywhere

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

### 4. `MediaAsset`'s physical-file-byte reclaim is asserted in `ARCHITECTURE_V2.md` but never scheduled in `ROADMAP_V2.md`

"Media/file fields" describes reclaiming a removed `MediaAsset`'s physical file bytes by
piggybacking on the manual pruning tool (Phase 5.3) rather than an automatic sweep. No
Roadmap phase tests this — not 3.4, where `MediaAsset` is introduced as "the worked example
exercising both Owned... and Shared... at once," and not any later phase either. Unlike
`Draft`, which gets an explicit callout under "Not covered by this roadmap," this gap is
silent. Neither document ever states where or how file bytes are actually persisted (local
disk, object storage, an adapter interface) in the first place.

## Roadmap coverage gaps

### 5. Lazy loading / `IdentityMap` correctness have no "Done when" in any phase

"Identity Map + Repository + lazy loading" states a hard requirement: "References/
collections must not eagerly hydrate on an entity's own load — only on actual access," plus
the standard same-id-resolves-to-same-instance identity-map guarantee. `IdentityMap` is
named once, in Phase 1.2's bullet list ("identity map scoped per request"), but no phase's
"Done when" anywhere tests either guarantee — not Phase 1.2 itself, and not Phase 3 where
references/collections (the things that must stay lazy) are introduced. This is exactly the
bug class the *old* roadmap had to retrofit-fix after the fact (its own Phase 6.1 "lazy
loading" audit fix, per [[project_storage_editor_status]]) — worth a real test in this
rewrite rather than risking the same rediscovery.

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
