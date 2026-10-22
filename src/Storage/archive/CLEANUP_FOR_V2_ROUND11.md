# Storage / Editor v2 — Cleanup Before Building (Round 11)

**Status: done (2026-10-13).** An eleventh audit pass over `ARCHITECTURE_V2.md`/
`ROADMAP_V2.md`, checking the two documents against each other for inconsistencies, missing
pieces, and design flaws, and separately checking both against the standalone-document rule
([[feedback_v2_docs_standalone]]) for stray references to pre-rewrite docs/concepts, and
for the architecture-never-names-roadmap direction of that same rule. The hygiene check came
back clean this round — no stray references found in either direction — so there's no
hygiene section below. Eight items below, all resolved. Worked one item at a time per
[[feedback_coconut_cms_development_workflow]]. Ordered by how much it changes what gets
built, not alphabetically.

## Missing pieces

### 1. The `prototypes` table is used but never defined — **resolved (2026-10-12)**

`prototypes.parent` is cited three times in `ARCHITECTURE_V2.md` — "Migrations and schema
mutation" (prototype-level rename's reference-fixup list), "Reparenting" (the dangling-parent
auditing predicate, applied to `prototypes.parent` instead of a field's own target), and
prototype substitution's own reference-fixup list — as if it's an already-established
table/column. But nowhere does the document specify this table's schema, or the storage shape
for an editor-created field's own definition (kind, target, permission, validators, explicit
default) that `SchemaEditor` actually reads and writes. Compare this to the column-by-column
spec "Global entity identity" gives `entities` — the metadata table underpinning all of
Phase 6 gets no equivalent treatment, despite being load-bearing for prototype rename,
dangling-parent auditing, and prototype substitution alike.

**Needs a decision**: name the `prototypes` table's columns (at minimum `identifier` and
`parent`, since those are already depended on by name) and say where a field's own definition
lives — either a full column layout here, or an explicit statement that field-storage layout
is implementation API, not architecture (the same posture already taken toward native
migration-tool invocation parameters, see "Deferred").

**Resolved: `prototypes` gets `identifier`, `parent`, and one `schema` JSON column** — new
section "Prototype storage: the `prototypes` table" in `ARCHITECTURE_V2.md`, right after
"Global entity identity." `identifier`/`parent` are named plainly, matching what the three
existing citations already depend on. A field's own definition (kind, target, permission,
`FieldValidator` rules by name, explicit default) lives inside `schema`, hydrated by
`PrototypeRegistry::fieldsOf()` into the same `FieldDescriptor[]` shape native reflection
produces, written back by `SchemaEditor` on every field-level mutation.

This went a different way than either option the item itself posed. JSON storage for
`schema` doesn't violate "No blobs," because that rule governs entity *content*
(independently filterable/sortable per field) and a prototype's own field definitions are
never content — nothing ever runs a SQL `WHERE` against one field's own kind or target; every
consumer already loads the row and inspects the hydrated `FieldDescriptor[]` in PHP. "No
blobs"'s own opening line was tightened to say "entity content" instead of "at all,
anywhere," since it was previously phrased broadly enough to contradict this. The exact
internal shape of the JSON document, and whether `SchemaEditor` rebuilds it whole or patches
it in place, stay implementation API, not architecture, the same posture already held for
native migration-tool parameter names.

### 2. The promised closed menu of `PrototypeValidator` rules for editor-created prototypes is never populated — **resolved (2026-10-12)**

"Validation" states plainly: "editor-created prototypes pick from a closed menu only." But no
roadmap phase ever defines a single concrete, reusable, admin-selectable `PrototypeValidator`
rule. Phase 6.1 is explicit that the only thing it actually proves end-to-end there is
`Unique` — and immediately clarifies "arbitrary native-only `PrototypeValidator` logic was
already proven native-side in Phase 1.3 and has no editor-created equivalent, by design." Since
`Unique` is explicitly its own mechanism, not a `PrototypeValidator` kind ("Validation"'s own
bolded sentence), the closed menu this rewrite actually ships has zero `PrototypeValidator`
entries in it. The capability Architecture advertises for admin-authored content types
("content types can be assembled entirely in the editor," "The goal") doesn't exist in the
build plan as written.

**Needs a decision**: either commit a phase to building at least one concrete built-in
`PrototypeValidator` rule (e.g. promoting the "end date after start date" example already used
for native-side testing into a real registered, reusable rule), or rewrite "Validation" to say
plainly that the closed-menu registry ships empty in this rewrite — a mechanism designed to
accept rules later with no new machinery, not a promise that one exists today.

**Resolved: the premise was wrong, not just the roadmap coverage.** "Closed menu" had
conflated two different things: an editor-created prototype can never write arbitrary logic
inline (a real safety constraint — the live editor can't execute PHP a browser session just
typed in), and the registry it picks from being limited to a small, framework-fixed set
(which doesn't follow from that constraint at all, and was never a deliberate decision).
"Validation" now describes an open registry instead: any PHP code, framework or host
application, registers a `FieldValidator`/`PrototypeValidator` class under a name at boot,
and the admin-facing menu lists whatever is currently registered, with no cap. The framework
ships no concrete built-in `PrototypeValidator` rule of its own — Phase 6.1 proves the
open-registration mechanism end to end with its own test-registered rule (reusing the "end
date after start date" example already hand-written for native in Phase 1.3) instead.
Every other "closed menu"/"closed-menu" reference in both documents (field retype's
converter selection, prototype substitution's converter selection, `Unique`'s shared editor
affordance) was reworded to "registered menu" for the same reason.

### 3. `editorExtensible`'s positive-case semantics are never stated — **resolved (2026-10-12)**

The flag is introduced at `#[Entity(table: ..., editorExtensible: ...)]` ("Shape comes from a
neutral descriptor") and only ever discussed afterward in terms of *revoking* it ("Reparenting"
— deploy-time fallback, "needs review" marking for a native class's existing editor-created
subclasses). What the flag actually gates when `true` — i.e., where `createPrototype()`/
`reparent()` is supposed to check it before letting an admin subclass a native class in the
first place — is never described in `ARCHITECTURE_V2.md`, and never tested in
`ROADMAP_V2.md` (Phase 6.1's creation path is explicitly scalar/no-parent-complexity; Phase
6.3's reparenting done-when covers cycle rejection but not an `editorExtensible` check).

**Needs a decision**: state what `editorExtensible: true`/`false` actually permits or blocks,
and at which call(s) it's checked, then add the corresponding roadmap assertion (most likely
to Phase 6.3's "Done when," since that's where reparenting onto an arbitrary native parent is
first exercised).

**Resolved: checked only when the candidate new parent is native.** `reparent()` (and
`createPrototype()`'s own parent argument) rejects outright, before any mutation runs, when
the candidate new parent is a native class whose own `#[Entity(editorExtensible: ...)]` is
`false` — a second structural check alongside the existing cycle-rejection check in
"Reparenting," reading the native parent's own attribute at the moment of attachment, not a
stored flag. An editor-created identifier carries no `editorExtensible` flag of its own, so
attaching one as a parent is always allowed regardless. Phase 6.3 gained a matching bullet
and "Done when" assertion, proving the rejection, the `true` success case, and the
always-allowed editor-created-parent case as three distinct scenarios.

### 4. Phase 6's own stated goal isn't covered by any of its "Done when" criteria — **resolved (2026-10-12)**

Phase 6's intro promises every mutation introduced in the phase "bumps the global
`schema_version` sequence from Phase 5.3, exercising its restore-blocking guard end to end for
the first time." Phase 5.3 itself defers this exact test ("the guard can't be meaningfully
exercised end to end until [Phase 6] — noted, not a blocker"). But none of 6.1/6.2/6.3/6.4's
"Done when" blocks mention `schema_version` or the restore-blocking guard at all. The guard
Phase 5.3 explicitly left for Phase 6 to prove is never actually proven anywhere in the roadmap
as written.

**Needs a decision**: add a "Done when" assertion to the earliest sub-phase where it's
testable (6.1, since `createPrototype()` is the first schema mutation that exists) — something
like: creating a prototype bumps `schema_version`, and a revision-history restore attempting to
reach back across that bump is rejected by Phase 5.3's guard.

**Resolved: added to Phase 6.1's "Done when," as suggested, once only.** `schema_version` is
one global counter, and the guard's rule is "blocked if any schema change happened since" —
proving the block once, at the earliest testable point, exercises the same mechanism 6.2's
`addField()`/etc. and 6.3's `reparent()` would otherwise re-exercise with no new behavior to
show, so neither gained its own repeat assertion.

While fixing this, found a related but distinct gap one level down: Phase 5.3 introduces
revision-history restore as a bullet ("reads one entity's own `EntityChangeRecord` chain,
restores by flushing a new changeset") but its own "Done when" never tested a basic
*successful* restore at all — only pruning-then-undo-refusal, pruning permission, and
empty-`Revision` pruning. Fixed in the same pass: Phase 5.3's "Done when" now also asserts
that restoring to an earlier state resets an entity's fields exactly and produces its own new
`Revision`, appending to history rather than rewriting it.

### 5. `RequiredValidator` is named but never built — **resolved (2026-10-12)**

`ARCHITECTURE_V2.md` references `RequiredValidator` by name three times ("FK `ON DELETE`
policy," twice, and "Migrations and schema mutation"'s `NoType` discussion) as an existing
`FieldValidator` an app can attach to gate an optional `Reference` field at write time. Phase
1.3 only commits to "a couple of default validators (e.g. a length check, a format check)" —
`RequiredValidator` specifically is never named in any phase's bullets or "Done when."

**Needs a decision**: name `RequiredValidator` explicitly as one of Phase 1.3's built-in
validators (it's the natural fit, already described by Architecture), or explain what's
actually meant to back the behavior Architecture attributes to it if not that class.

**Resolved: named explicitly as one of Phase 1.3's built-ins, alongside the length/format
checks.** Unlike item 2's `PrototypeValidator` example, this wasn't a speculative
illustration — "FK `ON DELETE` policy" leans on `RequiredValidator` actually existing to
explain a real distinction (a `Reference`'s schema-level nullability and its content-level
required-ness are independent), and it's basic, universally-needed functionality rather than
a bespoke rule. Phase 1.3's "Done when" gained an assertion on a plain scalar field (the only
field kind that exists at that point); Phase 3.2's "Done when" gained the specific scenario
Architecture actually describes — `RequiredValidator` rejecting an empty `Reference` field
at write time while the column stays nullable and the FK policy stays `SET NULL`.

### 6. `entities.position` gets no reserved-sentinel treatment for a non-Owned row — **resolved (2026-10-12)**

"Global entity identity" gives `owner` and `owner_field` explicit reserved sentinel values
(the nil UUID; the empty string) specifically so neither column is ever "a plain `NULL`" on a
non-Owned row. `position` gets the same nullable treatment in principle (`-1` for a
non-collection Owned relationship, an ordinal otherwise) but no stated value — reserved or
otherwise — for a standalone/Shared-referenced row. Likely harmless in practice (nothing
appears to compare `position` across rows with different `owner`/`owner_field`), but the
document doesn't say so, which is a different level of rigor than the explicit sentinel
discussion right next to it.

**Needs a decision**: either state explicitly that a plain `NULL` is fine here (and why — no
query ever needs a non-null sentinel for `position` the way `owner`/`owner_field` need one),
or give it a reserved value matching the same posture as the other two columns.

**Resolved: `-1` for a standalone/Shared-referenced row too, same sentinel as a
non-collection Owned relationship, not a third `NULL` state.** Nothing ever needs to tell the
two `-1` cases apart from `position` alone — `owner`/`owner_field` already does that, and
every query filtering on `position = -1` already scopes to a specific `owner`/`owner_field`
pair first. Unlike `owner`/`owner_field`, `position` is never folded into a `Unique` group's
own index, so there was never a real correctness requirement forcing a sentinel here the way
there was for those two — the `-1` reuse is purely for consistency with their "never a plain
`NULL`" posture, and it's simpler than introducing a new, third not-applicable state right
next to two existing ones. Phase 1.2's "Done when" gained a matching assertion.

### 7. `Repository::lazyFind()` is cited in Architecture but never committed to a roadmap phase — **resolved (2026-10-13)**

"Permissions" names `Repository::find()`/`lazyFind()` together as the two read paths
`FieldPermission` filters identically. `ROADMAP_V2.md` only ever names `find()` (Phase 1.2) and
`findMany()` (Phase 8.2) — `lazyFind()` itself is never named in any phase's bullets, even
though Phase 3.2's "Done when" already tests the laziness behavior it presumably backs
("loading an entity with a Shared reference... field triggers no query... until the field is
actually accessed").

**Needs a decision**: name `Repository::lazyFind()` explicitly in Phase 3.2 (where the laziness
behavior it backs is first introduced and tested), or confirm the behavior is meant to live
somewhere else and correct the Architecture reference.

**Resolved: named explicitly in Phase 3.2, as suggested, plus one gap found while tracing
it.** The citation itself was slightly mis-pointed — `find()`/`lazyFind()` are actually named
together at the tail of "Admin list/filter views," not in "Permissions" — a minor detail that
doesn't change the finding. More substantially, `ARCHITECTURE_V2.md` itself never defined
what `lazyFind()` does before naming it in passing; "Identity Map + Repository + lazy
loading" now states it explicitly: a proxy returned immediately, no query run until a
property is first accessed, with a `Reference`/`Collection` field resolving its target
through this same method rather than a separate laziness mechanism — and the method is
equally callable directly, independent of any field. Phase 3.2 gained a matching bullet and
a direct-call "Done when" assertion, distinct from the existing field-mediated one, since
Architecture treats it as its own callable method, not purely a hidden implementation detail
behind `Reference` fields.

A related design question came up while discussing this (should lazy loading become
opt-in via a field attribute, eager by default) and was resolved as **no** — eager-by-default
on a CMS-shaped entity risks combinatorial over-fetching across its own `Reference`/
`Collection` fields, which is exactly why Doctrine (the pattern this design already follows)
defaults associations to lazy too. A field-level attribute also bakes in one answer per
declaration when the real need, if one ever appears, is a per-*query* eager-load hint (a
`Query`/`Repository` parameter, not a `FieldDescriptor` one) — deferred, not designed now,
since nothing in the architecture has surfaced a concrete need for it yet.

## Real inconsistencies

### 8. "RESTRICT-protected by a live `Reference`" contradicts "FK `ON DELETE` policy" — **resolved (2026-10-13)**

"Migrations and schema mutation"'s prototype-deletion discussion says a prototype's own rows
might still be "`RESTRICT`-protected by a live `Reference` elsewhere," as part of explaining
why the cascade-delete has to run through `WriteExecutor` rather than a bulk bypass. But "FK
`ON DELETE` policy" states, twice, that a `Reference`'s target-FK is "always `SET NULL`...
never `RESTRICT`" — a `Reference` can never block a delete this way by this document's own
rule. The only real `RESTRICT` anywhere in this design is `entities.owner`'s, which protects a
row with live Owned descendants, not a row that's merely Shared-referenced. The underlying
point (deletion needs `WriteExecutor`'s downward Owned-subtree expansion *and* its sideways
`Reference`-nulling, not just one of the two) is sound; the specific sentence misattributes
`RESTRICT` to the wrong relationship kind.

**Needs a decision**: reword the sentence to name the two real reasons separately — some rows
may have live Owned descendants (`entities.owner`'s `RESTRICT`), and some may be
live-referenced by a `Reference` elsewhere (handled by the sideways `SET NULL` expansion, not a
`RESTRICT`) — rather than attributing `RESTRICT` to `Reference`.

**Resolved: reworded as suggested, with one refinement found while rewriting it.** The two
reasons aren't just different in *kind* (which relationship), they're different in *what
`WriteExecutor` actually buys*: for Owned descendants, `entities.owner`'s `RESTRICT` would
really block a raw delete outright, so the downward expansion is what makes the delete
possible at all; for a `Reference`, nothing ever blocks the delete (always `SET NULL`), so
the sideways expansion isn't about possibility — it's what keeps the referrer's own nulled
FK a tracked write effect instead of an invisible database-level side effect, matching the
reasoning "Content undo, draft, and revision history" already relies on elsewhere. Checked
`ROADMAP_V2.md` for the same misattribution while there — its own two `RESTRICT`/`Reference`
mentions (Phase 6.4) were already correctly worded, nothing to fix.
