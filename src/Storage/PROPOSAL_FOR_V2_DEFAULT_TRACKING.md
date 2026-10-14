# Storage / Editor v2 — Proposal: Live Default Tracking

**Status: proposed (2026-10-14).** Written after Round 16 (`CLEANUP_FOR_V2_ROUND16.md`), the
last completed cleanup pass before implementation. Not yet merged into `ARCHITECTURE_V2.md`/
`ROADMAP_V2.md` — this document records a new mechanism those two will need to absorb, not a
fix to an existing inconsistency between them. Reached through discussion, working one
sub-problem at a time, per [[feedback_coconut_cms_development_workflow]]. The concrete
mechanism for editor-created cascading defaults (see "Cascading defaults" below) is the one
piece still to be designed.

## The problem

A field left untouched at creation currently gets its value resolved once, at write time,
through the existing `#[DefaultInstance]` chain ("Defaulted instances"), and written as an
ordinary stored value — indistinguishable from a value a user deliberately chose. If a
developer later changes that default, every row created before the change stays frozen at
the old value forever, even though nobody ever actually chose it.

The goal: an untouched field should keep tracking whatever the *current* default resolves
to, indefinitely, with no migration step ever required when the default changes — the same
behavior Unreal's own CDO (Class Default Object) system already gives every `UObject`
property an instance hasn't overridden, which this design's own "The goal" section already
takes as its model.

## Deciding touched vs. defaulted: value comparison, not an explicit signal

Whether a field counts as touched is decided by comparing the value being written against
the field's own current default, at write time — not by an explicit "this was provided"
signal from the caller. This is a deliberate choice, not an oversight: an explicit signal
would need native PHP classes to adopt a new authoring convention just to express it (nullable
property types as a stand-in for "not provided," or routing every assignment through a
touched-aware setter), which is real, invasive surface area with no equivalent problem on the
editor-created side, where every interaction is already a discrete, explicit action.

**Tradeoff accepted knowingly**: a user who deliberately sets a field to a value that happens
to equal today's default is, under comparison, indistinguishable from someone who never
touched it, and will silently start tracking a later default change too. This is the same
property Unreal's own system has — property delta serialization against the CDO/archetype is
itself value comparison, not an independently tracked override bit — and it's a known,
accepted characteristic there rather than something the engine works around. Given this
design already takes Unreal's system as its model, the same tradeoff is accepted here, in
exchange for one uniform rule that needs no special authoring convention on either side.

## Storage: one side table per CTI level, not a column on the content table

Touched-state lives in a dedicated table, one per CTI level, 1:1-keyed with that level's own
table — never as a nullable value on the content column itself, and never as a boolean column
added directly next to it.

Two reasons rule out keeping it on the content table:

- **A column's own `NULL` can't mean two things at once.** A field's real domain might
  legitimately need a genuine null as data, independent of whether it's been touched; forcing
  both into one sentinel either breaks for that field or forces a value-object wrapper just to
  recover a real null. It outright breaks for `Reference`: `NULL` already means "no target,"
  and once a `Reference` field can carry an explicit default (see below), `NULL` can no longer
  also mean "untouched, tracking that default" without losing the ability to tell it apart
  from "deliberately cleared."
- **Almost every field is eligible** — everything except where a `Unique` constraint applies
  (see below, now fully compatible rather than excluded). A companion boolean column added
  directly to the content table would roughly double the width of every table in the schema,
  immediately, for every shape — not an occasional cost for unusually wide shapes, the way
  `#[Embed]` flattening's column-count risk is, but a universal one from day one.

A side table avoids both: the content table's own columns keep meaning exactly what they
already mean. The join/write-coordination cost this adds is small and already paid elsewhere
in this design — `WriteExecutor` already writes across every level of a CTI chain atomically
for one entity, and every read already pays one join to `entities` as an accepted tradeoff
("Cost accepted deliberately"). One more 1:1-keyed join is the same category of cost, not a
new one.

Each level's side table holds one boolean per eligible field declared at that level, same
"physical shape is a pure function of kind" pattern already used for the level's own content
table.

**Invariant: the content column holds a real value if and only if the state table marks that
field touched, never otherwise.** Whenever a write's comparison determines a field now equals
its current default — whether it was previously touched and is being reverted, or is being
set for the first time — the content column is explicitly nulled in the same write, never left
holding a stale real value that happens to currently match. A row or item that's eagerly
created already defaulted (see "Collections" below) starts this way from the moment it's
created: `NULL` content, `defaulted` state, never a materialized copy of today's default.

## Reading: untouched means resolved fresh, every time, nothing cached

Hydration consults the side table for each eligible field. Touched: read the stored value
normally, exactly as today. Untouched: ignore whatever the content column holds (always
`NULL`, per the invariant above) and resolve the field's *current* default fresh — the same
`#[DefaultInstance]` resolution "Defaulted instances" already specifies, just invoked at read
time instead of once at creation. Nothing is ever cached and nothing is ever migrated: a
default change takes effect for every untouched row on its very next read, at zero write cost.

`Query::where()`/`orderBy()` on an eligible field needs the matching read: comparing against
the live default inlined as a literal, keyed off the side table's own flag instead of
`IS NULL` — the same shape `COALESCE` would give a nullable column, just driven by the side
table. New work for `Query`, not free, but necessary for query results to agree with what
hydration actually returns.

## Determinism is a precondition for participating at all

A field's default resolver has to be a pure, deterministic function — same output on every
call — to take part in this mechanism, full stop. This isn't specific to `Unique` or to any
one field kind; a non-deterministic resolver (randomness, `now()`, any side effect) would make
an untouched row's displayed value different on every single read, which is already broken
independent of anything else. (Unreal's own CDO system hits the identical failure mode:
non-deterministic code in a `UObject` constructor is a documented anti-pattern, since the CDO
bakes in one frozen random draw at class-load time and diffing a freshly spawned instance's
own fresh draw against it is incoherent.) A field that still needs a stable-but-unique value
should derive it deterministically from something already known and stable — the owner's own
id (already assigned with no DB round-trip) plus the item's position, for example — rather
than true randomness.

## `Unique`: a constraint on the state table's own flag, not an exclusion

A real `UNIQUE` index constrains physically stored values, and this design has already ruled
out an application-level "check then write" pass as an acceptable substitute for enforcing one
(a race two concurrent writers can both pass — "Uniqueness"). Rather than excluding `Unique`
fields from this mechanism, the constraint moves onto the state table's own flag column:

**At most one row or item per `Unique` scope may hold the `defaulted` flag at a time**,
enforced by a real `UNIQUE` index on that flag column, scoped identically to however the
`Unique` group itself is scoped (owner-folded for an Owned-collection item, global for a
standalone row, and so on — no new scoping concept, the same shape the content-column index
already uses). Claiming that flag — writing `NULL` content, `defaulted` state — requires both
of the following to hold, checked atomically in the same write:

1. No other row or item in the same scope currently holds `defaulted` for this field (the
   `UNIQUE` index on the state column itself).
2. No existing *touched* row in that scope already stores a real value equal to today's live
   default (the ordinary content-column distinctness check, run with the live default as the
   candidate value).

If either check fails, the write can't claim the defaulted flag — the value must instead be
resolved to something genuinely distinct and stored as touched, through the same
distinctness-check-with-converter machinery "Defaulted instances" already specifies for
backfill, just also triggered here. Both checks are real, race-free database operations: the
first is a real index, and the second compares against a deterministic, computable constant
(today's live default), not against other uncertain concurrent writes — nothing racy about it.

This fully closes the collision risk, in both directions: two untouched rows can't both
coast on the same live value (check 1), and a deliberately-written value can't silently
duplicate what the one remaining defaulted row is tracking (check 2). It also means required
vs. optional stops mattering — both get the same uniform treatment, and nothing about a field
being `Unique`-constrained excludes it from live tracking anymore.

## Collections: every kind eagerly creates its rows, no special case

**Every collection — Owned, Shared, or non-entity alike — eagerly creates its rows or items at
creation time, matching whatever cardinality the live default specifies at that moment.** No
branch on whether a `Unique` field happens to be present anywhere in the collection.

Two reasons converge on eager creation, for different collection kinds, unified into one rule
rather than kept as separate special cases:

- **Owned items are real entities with their own identity** — unlike a scalar value, they
  can't be resolved purely on demand once anything might need to address one individually (an
  edit, an undo entry, a permission check). This was the original motivation.
- **A deferred (zero-row) collection can't let a real `Unique` constraint do its job at all.**
  If nothing physically exists until first touch, a collection of three `Unique`-constrained
  items all silently defaulting to the same value sits there colliding indefinitely — not
  delayed, genuinely undetectable, for as long as nobody happens to touch it. That's not a
  timing nuance, it's a permanent hole in "always a real constraint, never a maybe-someday
  check." Eager creation closes it: every item is a real row with a real state-table entry the
  moment the collection exists, so the `Unique`-on-state-column mechanism above applies
  immediately, the same way it does for any other row.

Each eagerly-created item starts with every field defaulted (`NULL` content, `defaulted`
state), exactly as the invariant above requires, never a materialized copy of today's values.

**Every collection field also gets one `initialized` boolean of its own**, living in the same
side table as everything else, uniform across all three collection kinds. This tracks whether
*anything* inside the collection has ever been explicitly touched — a fact distinct from row
count (which eager creation already makes accurate from the start) and not derivable without
it: scanning every field of every item just to answer "has anything here ever been touched" is
exactly the kind of check worth a cheap, explicit flag instead of a scan. The first touch to
any field, any add, remove, or reorder, flips it. It also resolves the one remaining ambiguity
row-presence alone can't: "the live default currently has zero items" and "an admin
deliberately emptied this" look identical from row count alone, and only the flag tells them
apart.

`<field>_count` (Owned collections only) is untouched by any of this. It still exists for a
different reason — an optional Owned item can legitimately have no row at all, so cardinality
can't always be read off row count — and the collection's own `initialized` flag answers "has
anything been touched" without needing `<field>_count` to carry a second meaning.

## `Reference` fields can have a default after all

"Defaulted instances" currently excludes `Reference` entirely, reasoning that no default
target could ever be manufactured. That reasoning only rules out the *framework* constructing
one automatically — it doesn't rule out a human picking one. An editor-created `Reference`
field can carry an explicit, admin-picked default through the same entity-reference-picker UI
already used to set any other editor-created field's explicit default, and that default
participates in the same live-tracking mechanism as any other field, with no interaction with
`Unique` at all (`Reference` is already excluded from `Unique` groups for an unrelated,
pre-existing reason).

Native classes declaring a `Reference` default is accepted as in-scope too, but the mechanism
isn't designed yet — it would need something in the shape of `Cms`'s own seeded-singleton
lookup pattern (a static resolution to a known, already-existing row) rather than an
entity-picker UI. Left as a follow-up.

## Owned singular and Owned collection items

An `OwningReference`/Owned-`Collection` item's row always exists from the moment its
relationship is created — there's no deferred-creation step for Owned at all, singular or
collection, for the identity reason given above. Every field on that row starts out
individually untouched, comparing against its own current default the same way as any other
field — eager existence and per-field touched state are separate facts, and the row existing
doesn't mean any of its fields are touched.

A singular `OwningReference` field gets the same `initialized` flag a collection field gets,
for the same reason: it's the cheap, explicit answer to "has anything on this owned row ever
been touched," which per-field state alone doesn't give you without scanning every field.

## Cascading defaults: the owning entity's default can pin specific nested fields

An untouched field's live default resolves recursively: whatever the immediately enclosing
level's own default-resolution pins for it, or, failing that, whatever the field's own class
resolves to, further down. A `Car`'s own default can deliberately set its default `Engine`'s
`horsepower` to a specific value while every other field of that same default `Engine` keeps
tracking `Engine`'s own live default — the same layered pattern Unreal's own subobject/
archetype property overrides already use (a Blueprint can override specific properties of an
instanced subobject while every other property still inherits from that subobject's own class
CDO, composing through further subclassing).

**Native: falls out for free.** If `Car::defaultInstance()` builds its default `Engine` by
starting from `Engine::defaultInstance()` and writing `horsepower` onto it through the same
write path ordinary content edits already use, the value comparison that decides touched vs.
defaulted runs exactly the same way it does for any other write — no new mechanism needed.

**Editor-created: genuinely new, and the next thing to design concretely.** An editor-created
field's stored default can't stay a flat value for an owned/embed-kind field — it needs a
sparse, recursively-structured representation (which of the nested shape's own fields this
default pins vs. leaves tracking their own class default), plus a field-authoring UI
affordance for choosing that per nested field, recursively. This is open.

## Open

- The native-side mechanism for declaring a `Reference` field's explicit default.
- **The concrete editor-created mechanism for cascading/sparse defaults** — the next design
  pass.
- Whether/how `Editor\` surfaces "tracking the live default" versus "explicitly set" to an
  admin, and whether an explicit "reset to default" affordance is worth adding.
