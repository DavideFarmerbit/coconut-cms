# Storage / Editor v2 — Proposal: Live Default Tracking

**Status: proposed (2026-10-14).** Written after Round 16 (`CLEANUP_FOR_V2_ROUND16.md`), the
last completed cleanup pass before implementation. Not yet merged into `ARCHITECTURE_V2.md`/
`ROADMAP_V2.md` — this document records a new mechanism those two will need to absorb, not a
fix to an existing inconsistency between them. Reached through discussion, working one
sub-problem at a time, per [[feedback_coconut_cms_development_workflow]].

## The problem

A field left untouched at creation currently gets its value resolved once, at write time,
through the existing `#[DefaultInstance]` chain ("Defaulted instances"), and written as an
ordinary stored value — indistinguishable from a value a user deliberately chose. If a
developer later changes that default, every row created before the change stays frozen at
the old value forever, even though nobody ever actually chose it. There is currently no way
to tell "the user picked this" apart from "nobody ever touched this, it just happened to
start out at the default."

The goal: an untouched field should keep tracking whatever the *current* default resolves
to, indefinitely, with no migration step ever required when the default changes — the same
behavior Unreal's own CDO (Class Default Object) system already gives every `UObject`
property that an instance hasn't overridden, which this design's own "The goal" section
already takes as its model.

## Touched-tracking must be a real signal, not a value comparison

Deciding whether a field is untouched can never be done by comparing its current value
against the computed default. A user who deliberately sets a field to a value that happens
to equal today's default is indistinguishable, under that comparison, from a user who never
touched it at all — and would incorrectly start drifting the moment the default changes,
which is the exact bug this mechanism exists to prevent, just inverted. Touched-ness has to
be recorded explicitly at the point a field is written, independent of what value it ends up
holding.

## Storage: one side table per CTI level, not a column on the content table

Touched-state lives in a dedicated table, one per CTI level, 1:1-keyed with that level's own
table — never as a nullable value on the content column itself, and never as a boolean column
added directly next to it.

Two reasons rule out keeping it on the content table:

- **Reusing a column's own NULL to mean "untouched" conflates two unrelated facts.** A
  field's real domain might legitimately need a genuine null as data, independent of whether
  it's been touched; forcing those into one sentinel either breaks for that field or forces a
  value-object wrapper just to recover a real null. It outright breaks for `Reference`: NULL
  already means "no target," and once a `Reference` field can carry an explicit default (see
  below), NULL can no longer also mean "untouched, tracking that default" without losing the
  ability to tell it apart from "deliberately cleared."
- **Almost every field is eligible** — everything except a required `Unique` field (see
  below). A companion boolean column added directly to the content table would roughly
  double the width of every table in the schema, immediately, for every shape — not an
  occasional cost for unusually wide shapes, the way `#[Embed]` flattening's column-count
  risk is, but a universal one from day one.

A side table avoids both: the content table's own columns keep meaning exactly what they
already mean, and nothing on them is ever repurposed. The join/write-coordination cost this
adds is small and already paid elsewhere in this design — `WriteExecutor` already writes
across every level of a CTI chain atomically for one entity, and every read already pays one
join to `entities` as an accepted tradeoff ("Cost accepted deliberately"). One more 1:1-keyed
join is the same category of cost, not a new one.

Each level's side table holds one boolean per eligible field declared at that level, same
"physical shape is a pure function of kind" pattern already used for the level's own content
table.

## Reading: untouched means resolved fresh, every time, nothing cached

Hydration consults the side table for each eligible field. Touched: read the stored value
normally, exactly as today. Untouched: ignore whatever the content column holds and resolve
the field's *current* default fresh — the same `#[DefaultInstance]` resolution "Defaulted
instances" already specifies, just invoked at read time instead of once at creation. Nothing
is ever cached and nothing is ever migrated: a default change takes effect for every
untouched row on its very next read, at zero write cost, because there was never a stored
snapshot to go stale in the first place.

`Query::where()`/`orderBy()` on an eligible field needs the matching read: comparing against
the live default inlined as a literal, keyed off the side table's own flag instead of
`IS NULL` — the same shape `COALESCE` would give a nullable column, just driven by the side
table. This is new work for `Query`, not free, but necessary for query results to agree with
what hydration actually returns.

## `Unique`: required fields can never participate, optional fields need nothing new

A real `UNIQUE` index constrains physically stored values. A value that's resolved at read
time and never written anywhere has nothing for that index to see — and this design has
already ruled out an application-level "check then write" pass as an acceptable substitute,
since it's a race two concurrent writers can both pass ("Uniqueness"). So:

- **A required `Unique` field can never live-track.** It always resolves to a real, explicit,
  distinct, immediately-stored value — reusing the exact distinctness-check-with-converter
  machinery "Defaulted instances" already specifies for backfill, just also triggered at
  ordinary creation (and, for a collection item, at first touch — see below).
- **An optional `Unique` field needs no new mechanism at all.** This design already treats
  multiple `NULL`s as non-colliding for an optional `Unique` field, which is exactly the right
  semantics here too: several untouched rows sharing the same live default aren't a real
  collision, since none of them represents a deliberate choice.

This is not a new exception. It's the same required/optional line "Defaulted instances"
already draws for `Unique` fields, reapplied here.

## Deferred materialization survives a required-`Unique` field, if the default is deterministic

A collection (or item) carrying a required `Unique` field doesn't have to eagerly materialize
at creation. It can stay fully defaulted, nothing stored, right up until the first real write
— as long as the field's own default resolution is a pure, deterministic function (same
output on every call). Several untouched rows silently sharing the same deterministic default
is tolerated the same way shared `NULL`s are: not a real collision until something is
actually chosen. At first touch, the existing distinctness-check-with-converter machinery
runs exactly as already specified, comparing only against rows already materialized — any
other still-virtual sibling isn't a real claim on that value yet, and will run its own check
at its own first-touch moment.

A **non-deterministic** default resolver (randomness, `now()`, any side effect) can never
participate in deferred/live tracking at all, for any field, `Unique` or not — this is a
precondition for eligibility in general, not a `Unique`-specific carve-out. The displayed
value for an untouched row would otherwise differ on every single read, which is already
broken independent of uniqueness. (Unreal's own CDO system hits the identical failure mode:
non-deterministic code in a `UObject` constructor is a documented anti-pattern, since the CDO
bakes in one frozen random draw at class-load time and diffing a freshly spawned instance's
own fresh draw against it is incoherent.) A field that still needs a stable-but-unique value
before first touch should derive it deterministically from something already known and
stable pre-write — the owner's own id (already assigned with no DB round-trip) plus the
item's position, for example — rather than true randomness.

## `Reference` fields can have a default after all

"Defaulted instances" currently excludes `Reference` entirely, reasoning that no default
target could ever be manufactured. That reasoning only rules out the *framework*
constructing one automatically — it doesn't rule out a human picking one. An editor-created
`Reference` field can carry an explicit, admin-picked default through the same
entity-reference-picker UI already used to set any other editor-created field's explicit
default, and that default participates in the same live-tracking mechanism as any other
field, with no interaction with `Unique` at all (`Reference` is already excluded from
`Unique` groups for an unrelated, pre-existing reason).

Native classes declaring a `Reference` default is accepted as in-scope too, but the mechanism
isn't designed yet — it would need something in the shape of `Cms`'s own seeded-singleton
lookup pattern (a static resolution to a known, already-existing row) rather than an
entity-picker UI. Left as a follow-up.

## Owned singular and Owned collection items: no special case for identity

An `OwningReference`/Owned-`Collection` item is a real entity with its own identity —
unlike a scalar value, it can't be resolved purely on demand once anything might need to
address it individually (an edit, an undo entry, a permission check). So the row, once it
exists, starts with every one of its own fields untouched, each individually live-tracking
its own current default — the same per-field mechanism recursively, one level deeper. The
first edit to any one field flips only that field's own bit; everything else on the row keeps
tracking independently.

## Collections: one "initialized" flag per collection field, uniform across all three kinds

A collection's *membership* — how many slots exist, which are populated, their order — is a
different question from any one item's own field values, and is tracked separately: either
fully defaulted (nothing materialized, the whole array resolved fresh from the live class
default on every read) or touched (fully materialized real rows/items, ordinary from then on)
— never partially one or the other, since position and count are only coherent relative to
the whole set. One boolean per collection field, living in the same per-level side table as
every other touched-bit, governs this. It behaves identically for Owned, Shared, and
non-entity collections alike — none of their *slots* carry independent identity that
membership-tracking itself needs to worry about; the identity question for Owned items is
already handled above, at the per-item level, once the collection is materialized.

The first touch to any item (add, remove, edit, or reorder) flips the collection's
`initialized` flag and materializes the *entire* current default set as real rows/items at
that moment, before applying the requested change on top. A required-`Unique` field anywhere
in the default set defers the same way described above, provided its default is deterministic
— it doesn't force the whole collection to materialize early on its own.

`<field>_count` (Owned collections only) needs no change and no flag of its own.
It keeps its existing, sole job — the true slot count including gaps, meaningful once the
collection is materialized — and the collection's own `initialized` flag already answers "has
anything been touched" on its behalf. An earlier version of this proposal considered
overloading `<field>_count`'s own nullability to serve double duty as that marker; rejected,
since it recreates the exact two-meanings-on-one-column conflation the side table exists to
avoid, just one level up.

## Open, not designed here

- The native-side mechanism for declaring a `Reference` field's explicit default.
- The exact deterministic-derivation recipe for a required-`Unique` field's stable-but-virtual
  identity before first touch, beyond "owner id plus position" as one example.
- Whether/how `Editor\` surfaces "tracking the live default" versus "explicitly set" to an
  admin, and whether an explicit "reset to default" affordance is worth adding — a natural
  extension once this mechanism exists, not decided here.
