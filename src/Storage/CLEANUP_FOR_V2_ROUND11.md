# Storage / Editor v2 — Cleanup Before Building (Round 11)

**Status: open (2026-10-12).** An eleventh audit pass over `ARCHITECTURE_V2.md`/
`ROADMAP_V2.md`, checking the two documents against each other for inconsistencies, missing
pieces, and design flaws, and separately checking both against the standalone-document rule
([[feedback_v2_docs_standalone]]) for stray references to pre-rewrite docs/concepts, and
for the architecture-never-names-roadmap direction of that same rule. The hygiene check came
back clean this round — no stray references found in either direction — so there's no
hygiene section below. Eight items below, none resolved yet. Work one item at a time per
[[feedback_coconut_cms_development_workflow]]. Ordered by how much it changes what gets
built, not alphabetically.

## Missing pieces

### 1. The `prototypes` table is used but never defined

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

### 2. The promised closed menu of `PrototypeValidator` rules for editor-created prototypes is never populated

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

### 3. `editorExtensible`'s positive-case semantics are never stated

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

### 4. Phase 6's own stated goal isn't covered by any of its "Done when" criteria

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

### 5. `RequiredValidator` is named but never built

`ARCHITECTURE_V2.md` references `RequiredValidator` by name three times ("FK `ON DELETE`
policy," twice, and "Migrations and schema mutation"'s `NoType` discussion) as an existing
`FieldValidator` an app can attach to gate an optional `Reference` field at write time. Phase
1.3 only commits to "a couple of default validators (e.g. a length check, a format check)" —
`RequiredValidator` specifically is never named in any phase's bullets or "Done when."

**Needs a decision**: name `RequiredValidator` explicitly as one of Phase 1.3's built-in
validators (it's the natural fit, already described by Architecture), or explain what's
actually meant to back the behavior Architecture attributes to it if not that class.

### 6. `entities.position` gets no reserved-sentinel treatment for a non-Owned row

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

### 7. `Repository::lazyFind()` is cited in Architecture but never committed to a roadmap phase

"Permissions" names `Repository::find()`/`lazyFind()` together as the two read paths
`FieldPermission` filters identically. `ROADMAP_V2.md` only ever names `find()` (Phase 1.2) and
`findMany()` (Phase 8.2) — `lazyFind()` itself is never named in any phase's bullets, even
though Phase 3.2's "Done when" already tests the laziness behavior it presumably backs
("loading an entity with a Shared reference... field triggers no query... until the field is
actually accessed").

**Needs a decision**: name `Repository::lazyFind()` explicitly in Phase 3.2 (where the laziness
behavior it backs is first introduced and tested), or confirm the behavior is meant to live
somewhere else and correct the Architecture reference.

## Real inconsistencies

### 8. "RESTRICT-protected by a live `Reference`" contradicts "FK `ON DELETE` policy"

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
