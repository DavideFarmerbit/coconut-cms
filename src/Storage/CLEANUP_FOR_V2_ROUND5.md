# Storage / Editor v2 — Cleanup Before Building (Round 5)

**Status: resolved (2026-10-02).** A fifth audit pass over `ARCHITECTURE_V2.md`/
`ROADMAP_V2.md`, done after Round 4's items were folded back into both documents. All 4
items found have since been decided and folded back into `ARCHITECTURE_V2.md`/
`ROADMAP_V2.md` directly — this file is kept only as a historical record of that audit pass
and the discussion behind each decision, not as an open task list. Ordered by how much it
changes what gets built, not alphabetically.

## Real inconsistencies

### 1. `SchemaEditor`'s "nothing else, ever" claim contradicts `createPrototype()`/`reparent()` — **resolved (2026-10-02)**

"Migrations and schema mutation" states, for editor-created subclasses: "Nothing else, ever,
beyond these four field-level operations [add/drop/rename/retype a field]; this is enforced
by `SchemaEditor` exposing no other mutation method, not just by policy."

But `ROADMAP_V2.md` Phase 6.1 names a fifth method explicitly: `Persistence\Schema\SchemaEditor::createPrototype()`.
Phase 6's own intro lists a sixth in the same breath as the field-level four: "Every mutation
introduced across this phase — `createPrototype()`, `addField()`/`dropField()`/`rename()`/
`retype()`, `reparent()` — also bumps the global `schema_version` sequence." Phase 6.3
describes `reparent()` the same unprefixed way as the four field ops. Phase 6.4's prototype
deletion is a further operation that's neither a field op nor one of these two.

As written, "SchemaEditor exposing no other mutation method" is false — there are at least
six mutation entry points (create, add, drop, rename, retype, reparent), plus deletion, not
four. This matters because the sentence is framed as an enforced structural guarantee, not
just description; an implementation built around "exactly these 4 methods, nothing else" has
to be weakened the moment `createPrototype()`/`reparent()` land — the exact kind of late
retrofit this rewrite exists to avoid.

**Decided**: the wording was too strong — it was only ever meant to close the set of
*field-level* operations, not all of `SchemaEditor`. Also turned out to be misplaced, not
just unscoped: the sentence sat at the end of the "adding a field handles every
`FieldDescriptor` kind" digression, reading as if it concluded that specific point rather
than the opening list of four operations it actually refers to. Fixed both at once by moving
it to directly follow "add a field, drop a field, rename a field, retype a field (see
below)" and rewording in place ("Nothing else, ever, **at the field level**... no other
**field-level** mutation method") rather than appending a new sentence to explain the scope —
cheaper than explaining the exception, since the sentence no longer claims more than it
means. Folded into `ARCHITECTURE_V2.md` ("Migrations and schema mutation"); no
`ROADMAP_V2.md` change needed, since the roadmap never repeated the unscoped claim itself.

### 2. Retype converter stated as both mandatory and optional — **resolved (2026-10-02)**

"Migrations and schema mutation" opens with: "Retype is a real `ALTER`, never
drop-and-recreate, and **always needs an explicit converter** — no attempt to guess how to
convert existing data... No converter supplied for a retype that needs one → refuse loudly."

Later in the same section, "Retype, fully generalized" states the opposite: "**supplying a
converter is always optional, never mandatory**: given one, its job is 'derive the new value
from the old one'; given none, resolution falls back to the field's own class default."
`ROADMAP_V2.md` Phase 6.2 confirms the second version ("A converter is always optional:
supplied, it derives the new value from the old; omitted, the field falls back to its own
class default").

The second version is the one actually worked out in detail (it's what makes `Reference`
retargeting with no class default to fall back to make sense at all). The first paragraph
reads like leftover phrasing from before the generalized mechanism existed, and directly
misleads a reader who hits it before reaching the later section.

**Decided**: converter is always optional, never mandatory — the opening paragraph's
"always needs an explicit converter" / "refuse loudly" framing was simply wrong, not a
different rule needing reconciling. Rewrote it to state the optional-converter/
class-default-fallback mechanism directly and point at "Retype, fully generalized" rather
than repeating a contradicting claim ahead of it; dropped the blanket "refuse loudly" line
entirely, since the actual refusal condition is narrower and already stated correctly
elsewhere ("Required vs. optional governs..." — only a **required**
`OwningReference`/Owned-`Collection` target with neither a converter nor a class default
refuses loudly; `Reference` has no required case at all). Folded into `ARCHITECTURE_V2.md`
("Migrations and schema mutation"); no `ROADMAP_V2.md` change needed, it already matched
the optional-converter version.

## Missing pieces

### 3. Draft granularity doesn't define how a multi-entity changeset's "one pending draft per entity" lock composes — **resolved (2026-10-02), by removing Draft from this design's scope**

"Content undo, draft, and revision history" defined Draft as "a persisted-but-unflushed
changeset plus an in-memory apply/preview function" — the same `Changeset` type Phase 4 makes
inherently multi-entity — but stated the sharing/locking rule as "one pending draft **per
entity**... a second editor opening it takes over or hits a conflict warning." If a single
draft's changeset touches more than one entity, it was never written down whether the
"pending draft" slot is occupied on every touched entity or just one.

Digging into *why* raised a bigger question than the locking detail: Draft had never actually
been justified anywhere in either document — no discussion of what triggers one, what a read
is supposed to look like against a pending draft, or its lifecycle — unlike every other
mechanism here, which gets a reasoned "why" before the "how." Comparing it to Undo exposed the
actual problem: Undo is needed by the persistence layer's own correctness story regardless of
who's writing (a migration script's flush benefits from being loggable/revertible exactly like
a human's). Draft has no meaning for anything other than a human composing an edit through an
authoring UI and not yet committing to it — the same bucket as `LocalCommand`/`RemoteCommand`,
which this document already keeps under `Editor\`, not `Persistence\`.

**Decided**: Draft doesn't belong in this design at all — pulled `Persistence\Changeset\Draft\`
out of "Namespaces and migration path," reframed the "Draft" bullet in "Content undo, draft,
and revision history" as a pointer to `Editor\` rather than settled mechanics, and softened
the "Explicitly out of scope" real-time-collaboration note to not assert draft mechanics that
are no longer decided here. Removed Phase 5.3 from `ROADMAP_V2.md` entirely (renumbering 5.4 →
5.3, fixing the two downstream `Phase 5.4` cross-references in Phase 6), and added Draft to
the `Editor\` entry under "Not covered by this roadmap." This closes the original locking
question by making it moot for this document — it's `Editor\`'s own design pass to work out
whenever that track starts, same as everything else about that UI.

### 4. Owned-subtree cascade-delete can silently null an unrelated Shared reference, with no acknowledgment — **resolved (2026-10-02), generalized into a real fix, not a tradeoff**

Nothing stops an entity from being simultaneously Owned by one relationship and independently
Shared-referenced by a completely unrelated field elsewhere (e.g. a `MediaAsset` inline-owned
by a product's gallery that's also directly linked from an unrelated banner field via
`SharedReference`). When the owner's subtree cascade-deletes ("Deleting an owner auto-expands
to everything it transitively owns"), the unrelated Shared reference's FK just goes
`SET NULL` per the ordinary FK policy.

First pass at resolving this tried to wave it away as an "accepted tradeoff" by reasoning that
"a Shared target simply going missing from a field is an ordinary, always-legal outcome"
already covers it — wrong, caught on challenge. That sentence is about *schema-level
legality* (no `RESTRICT`, the FK is allowed to go null); it says nothing about whether the
side effect is supposed to be *tracked*. Once that conflation is removed, the real problem
shows up: the referencing entity genuinely was touched by this flush (its data changed), so
leaving it unlogged directly violates this document's own stated undo rule ("every entity
that flush actually touched gets its own independent `EntityChangeRecord`"). That's not a
tradeoff, it's a hole — and it isn't specific to the Owned-cascade case at all: an ordinary
single-entity delete of *any* Shared-referenced target has the exact same hole today (a
silent DB-level `SET NULL`, no `EntityChangeRecord` for the referrer, no way for undo to
restore the reference).

**Decided**: generalize the existing delete-expansion mechanism with a sideways counterpart
to the downward one already there. "Deleting an owner auto-expands to everything it
transitively owns" expands *downward* into owned descendants; a new, parallel rule expands
*sideways*: deleting any entity also finds every entity whose declared field currently holds
a live Shared reference to it (singular or collection item, reusing the same data-level
"what's actually using one right now" query prototype deletion's `NoType` machinery already
needed) and adds that field's own change to the *same* changeset, rather than letting the
database fire an untracked `SET NULL`. For a collection item the change is logged against the
collection's own declaring entity, never the pivot row (which "carries no
independently-logged content of its own," already decided elsewhere) — one rule, no
singular-vs-collection special case. This composes with the downward expansion for free: each
owned descendant's own deletion independently triggers the same sideways check, so the
original Owned-cascade scenario is just one instance of the general rule, not a case needing
its own handling. Folded into `ARCHITECTURE_V2.md` ("Content write path") and
`ROADMAP_V2.md` (Phase 4's bullet list and "Done when," plus Phase 5.2's "Done when" for the
undo-side payoff).
