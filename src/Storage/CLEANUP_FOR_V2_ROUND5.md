# Storage / Editor v2 — Cleanup Before Building (Round 5)

**Status: open (2026-10-02).** A fifth audit pass over `ARCHITECTURE_V2.md`/`ROADMAP_V2.md`,
done after Round 4's items were folded back into both documents. 4 items found, none decided
yet. Ordered by how much it changes what gets built, not alphabetically.

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

### 3. Draft granularity doesn't define how a multi-entity changeset's "one pending draft per entity" lock composes

"Content undo, draft, and revision history" defines Draft as "a persisted-but-unflushed
changeset plus an in-memory apply/preview function" — the same `Changeset` type Phase 4 makes
inherently multi-entity — but states the sharing/locking rule as "one pending draft **per
entity**... a second editor opening it takes over or hits a conflict warning."

If a single draft's changeset touches more than one entity (e.g. a Product and its Owned
`MediaAsset` gallery edited together), it's not written down whether the "pending draft" slot
is considered occupied on every entity the changeset touches (so opening *any* of them
surfaces the same draft and triggers the take-over/conflict check), or only on one primary
entity (in which case opening one of the *other* touched entities wouldn't know a draft
already covers it, and a second editor could start a conflicting one). The per-entity lock
and the multi-entity changeset it wraps don't obviously compose as currently stated.

**Open**: decide which, and state it. Likely answer given how everything else in this design
treats "touched by the same changeset" as the unit of conflict (concurrent-write protection,
undo's per-`Revision` grouping): the draft slot is occupied on every entity the underlying
changeset touches, not just one. Needs folding into `ARCHITECTURE_V2.md` ("Content undo,
draft, and revision history") and Phase 5.3's "Done when" bar in `ROADMAP_V2.md`.

### 4. Owned-subtree cascade-delete can silently null an unrelated Shared reference, with no acknowledgment

Nothing stops an entity from being simultaneously Owned by one relationship and independently
Shared-referenced by a completely unrelated field elsewhere (e.g. a `MediaAsset` inline-owned
by a product's gallery that's also directly linked from an unrelated banner field via
`SharedReference`). When the owner's subtree cascade-deletes ("Deleting an owner auto-expands
to everything it transitively owns"), the unrelated Shared reference's FK just goes
`SET NULL` per the ordinary FK policy ("FK `ON DELETE` policy" — "a Shared target simply going
missing from a field is an ordinary, always-legal outcome").

This is architecturally legal and doesn't need new mechanism — a Shared reference can always
go null, full stop. But unlike every other edge case in this design (the Owned-subtree
concurrency over-conflict tradeoff, the Shared-collection pivot split, ...), this interaction
is never named as a deliberate, accepted tradeoff anywhere. As written it reads like an
oversight rather than a decision an implementer can point to.

**Open**: decide whether this needs anything beyond a documentation callout (most likely: no
new mechanism, just an explicit "accepted tradeoff" note next to "Deleting an owner
auto-expands to everything it transitively owns" in "Content write path," parallel to the
concurrency tradeoff already called out there), or whether it's worth a narrower
admin-facing warning later (`Editor\`, same bucket as the destructive-delete warning already
deferred there).
