# Storage / Editor v2 — Proposal: Live Default Tracking

**Status: proposed (2026-10-14).** Written after Round 16 (`CLEANUP_FOR_V2_ROUND16.md`), the
last completed cleanup pass before implementation. Not yet merged into `ARCHITECTURE_V2.md`/
`ROADMAP_V2.md` — this document records a new mechanism those two will need to absorb, not a
fix to an existing inconsistency between them. Written assuming `CLEANUP_FOR_V2_ROUND17.md`'s
own item 2 (an Owned entity's edits are attributed to its nearest non-Owned ancestor's own
`EntityChangeRecord`, not an independent one of its own) is already folded into the baseline
architecture — this document doesn't re-derive that, it relies on it. Reached through
discussion, working one sub-problem at a time, per
[[feedback_coconut_cms_development_workflow]]. The concrete mechanism for editor-created
cascading defaults (see "Cascading defaults" below) is the one piece still to be designed.

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
- **Almost every field is eligible.** A companion boolean column added directly to the
  content table would roughly double the width of every table in the schema, immediately, for
  every shape — not an occasional cost for unusually wide shapes, the way `#[Embed]`
  flattening's column-count risk is, but a universal one from day one.

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
holding a stale real value that happens to currently match.

## Validation runs against the resolved value, never the raw column

`RequiredValidator`, `FieldValidator`, and `PrototypeValidator` all check a field's *resolved*
candidate value, never the raw stored column directly — the same candidate-state assembly
that already hydrates `Reference` values into real objects before any validator runs
("Uniqueness") does the same live-default resolution for an untouched field before validation
sees it. This matters specifically for `RequiredValidator`: a required field left untouched
has `NULL` in its content column by the invariant above, but its *resolved* value is whatever
the current default produces, which is a well-formed, non-`NULL` value — the same posture
`RequiredValidator` already takes toward a `NoType` value ("a well-formed value, not a `NULL`
violating a `NOT NULL` column"). Without this, a required field would spuriously fail its own
required-ness check every time it's correctly left tracking a perfectly valid default, which
would defeat the entire mechanism for exactly the fields where getting it right matters most.

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

## `Unique`: no two claimants in a scope may resolve to the same effective value

Within one `Unique` scope, no two claimants — a row or item still tracking its own live
default, or one holding an explicit stored value — may ever resolve to the same effective
value. Enforcement differs depending on whether every potential claimant in a scope shares one
common default value, or each has its own — a distinction that follows cardinality, not
ownership.

**Singular fields** (standalone rows, Shared-referenced rows, and singular Owned
relationships, all three alike): every claimant in the scope resolves to the *same*
default — one field, one class default, one scope. A real `UNIQUE` index on the state table's
own flag column, scoped identically to however the `Unique` group itself is scoped, enforces
"at most one claimant may be defaulted" directly. Claiming that flag — writing `NULL` content,
`defaulted` state — requires both of the following, checked atomically in the same write:

1. No other row in the same scope currently holds `defaulted` for this field (the `UNIQUE`
   index on the state column itself).
2. No existing *touched* row in that scope already stores a real value equal to today's live
   default — checked as a locking read (`SELECT ... FOR UPDATE` or equivalent) against the
   state table's row for that scope, inside the same transaction as the write, not a bare
   query-then-decide. This has to be locking to stay race-free: a plain unlocked query would
   be exactly the "look for a duplicate, then write" pattern this design already forbids.

If either check fails, the write is rejected outright — the same refusal any other duplicate
already gets, naming the collision — the same "fail before, not during" posture held
everywhere else in this design. No automatic fallback to a different value: resolving one on
the caller's behalf would mean guessing. (The separate distinctness-check-with-converter
machinery "Defaulted instances" specifies is for a different moment — a deliberate, reviewed
schema-mutation backfill where a developer explicitly supplies the converter — not an ordinary
content write, which has no converter available to it at all. That machinery already has the
same shape as this rule, though: "the first row can reuse the default, every row after that
needs the converter to produce something genuinely distinct" is exactly "at most one claimant
may stay defaulted," already present in the existing migration machinery before any of this.)

Check 2 exists because of a temporal gap, not an instantaneous contradiction: a value can
never equal its *own* contemporaneous default at the moment it's written (that write would be
classified defaulted instead, by the same comparison rule) — but a row touched earlier, when
the default was something else, stays frozen at whatever it chose, and the default can later
change to match it. A new write claiming defaulted now has to check against that old, frozen
value, not just against other defaulted claimants. Reading is unaffected by this either way —
each row resolves its own value correctly regardless of what any other row holds; only writing
(and the `Unique` check itself) has to account for it.

**`EntityChangeRecord` captures the resolved value for a change, never an abstract "set to
default" marker.** A revision record is an immutable snapshot of what the entity actually
showed at that moment; if it stored a marker instead, viewing that same past revision later —
after the live default has since changed again — would show whatever the *new* default
currently resolves to, not what was actually there when the revision was made, which isn't a
historical record at all, it's a live pointer pretending to be one. Whatever builds the record
from a `WriteEffect` resolves the live default at that moment for any field that ended up
defaulted, the same "resolved, not raw" rule `RequiredValidator` already follows above.

Undo needs no separate rule given this: restoring a past value is just another write of
that recorded, concrete value through this exact same comparison-and-`Unique` path — if the
restored value happens to equal today's live default, it becomes defaulted; if not, it's
touched — automatically, with nothing about touched/defaulted state needing to be preserved or
decided specially for undo.

This is harmless, not wrong, for singular Owned specifically: a `Unique` group on anything
Owned is always owner-scoped, never spanning different owners ("Uniqueness"), so there's only
ever one row per scope regardless — the check simply never finds a second claimant to compare
against, the same reason the ordinary content-level check "never actually fires" there today.

**Collection items**: each position resolves to its *own* distinct default value, not a value
shared across the whole scope, so the state-column index above doesn't apply here — a
collection's own declared default already has to be internally pairwise-distinct under its own
`Unique` group before any row exists, checked at registration (native) or save
(editor-created) ("Defaulted instances"), which rules out sibling-vs-sibling collisions among
defaulted items entirely. What remains is a defaulted item colliding with a *different*,
explicitly-touched sibling: an explicit write to one item is checked against every other
still-defaulted sibling's own resolved value, made race-free by the same serialization any
write to a collection's own membership already requires — the owning entity's current
`expectedOperationId` ("Content write path") — not a new index. This is "all the same"
mechanism regardless of item kind — scalar, value-object, embed, or Owned-reference — the
check only cares about resolved values, not what produced them.

Worth noting, not designing around: a `Unique` group declared on a specific subclass's own
fields, inside a polymorphic Owned collection, only ever compares items that are that exact
concrete subclass within the same owner's collection — a sibling holding a different subclass
simply has no row at that level at all. In a typical heterogeneous collection this check often
finds nothing to compare against, the same structurally-moot shape singular Owned's check has,
not a gap, just rarely triggered.

Either way, required vs. optional stops mattering — both get the same uniform treatment, and
nothing about a field being `Unique`-constrained excludes it from live tracking anymore.

## This gives up the database's own `NOT NULL` guarantee, universally, not as an opt-in

Every ordinary content field — scalar, value-object, embed-flattened, `Reference`,
`OwningReference`/Owned-`Collection`, required or optional alike — becomes unconditionally
nullable at the schema level, since any of them can end up `NULL`-and-defaulted. This isn't a
per-field choice; it's the universal shape of the mechanism. The only columns that keep a real
`NOT NULL` are the system-generated structural ones — `owner`, `owner_field`, `position`,
`<field>_count` — which were never part of this mechanism at all and keep their existing
reserved-sentinel posture for unrelated reasons ("Global entity identity"/"Uniqueness"); not
exceptions carved out of this rule, just never subject to it.

This reverses something the current architecture doc currently assumes elsewhere: the
`NoType` passage ("a well-formed value, not a `NULL` violating a `NOT NULL` column") only
makes sense if an ordinary field is expected to sit behind a real `NOT NULL` column today.
This needs calling out explicitly when this proposal is reconciled with `ARCHITECTURE_V2.md`.

The loss is narrower than it first looks, though. A required field's class already can't be
registered (native) or saved (editor-created) without a resolvable defaulted instance
("Defaulted instances") — that precondition predates this proposal. Combined with
`RequiredValidator` checking the *resolved* candidate value rather than the raw column (see
"Validation" above), a required field can never actually present as missing to anything going
through the ordinary read/write path. The guarantee doesn't disappear, it moves: from "the
database physically can't store nothing here" to "the class structurally can't exist without
a way to produce something here, and nothing ever reads the raw column without resolving
through it first."

What's honestly lost is defense-in-depth against a *bug* — something that leaves content
`NULL` while the state table says touched, or skips checking state entirely. A real `NOT NULL`
constraint catches that outright regardless of what application code does; here it depends on
the content/state invariant being correctly maintained by the write path, not enforced by
schema. Recovering that at the database level would need a cross-table check (content `NULL`
only valid if the matching state-table row agrees), which isn't expressible as a plain column
constraint, only a trigger spanning two tables — judged not worth building for this.

## Nothing is eagerly created — every relationship defers until first touch

Every relationship kind — a singular `OwningReference`, an Owned `Collection`, a Shared
`Collection`, a non-entity `Collection` — stays at zero rows, nothing materialized, until the
first explicit touch (an edit, an add, a remove, a reorder). Hydrating an untouched
relationship resolves its current default fresh from `#[DefaultInstance]`, the same way an
untouched scalar field does, type included — if the default itself changes which concrete
type it produces, an untouched relationship picks that up on its very next read, same as any
other live-tracked value, with no migration and nothing to delete or recreate.

**Owned items don't need an exception to this, and treating them as one was based on an
assumption that doesn't hold.** The original reasoning was that an Owned item is a real entity
with its own identity, so it can't be resolved purely on demand once anything might address it
individually. But this design's own mechanism for retargeting an `OwningReference`/Owned-
`Collection` item's concrete type while staying Owned already works by capturing the old row's
full field tree, cascade-deleting it, and constructing a fresh one — the ordinary, expected way
to change what occupies an Owned slot, not a rare migration path, the same shape as Unreal
destroying and recreating an instanced subobject when its class changes. An Owned item's
identity is already disposable by design; eagerly creating it to protect that identity was
protecting something this design doesn't actually treat as stable anywhere else. The first
touch to a still-virtual Owned relationship materializes a real row, of whatever concrete type
the current default produces, and the write that does so is an ordinary create — logged
through this design's existing undo path exactly like any other Owned entity's first write,
attributed to its nearest non-Owned ancestor's own `EntityChangeRecord`.

**Every relationship field — singular or collection, Owned or not — gets one `initialized`
boolean of its own**, living in the same side table as everything else. This tracks whether
*anything* in the relationship has ever been explicitly touched, a fact distinct from row
count and not derivable from it: "the live default currently has zero/one items" and "an admin
deliberately emptied this" look identical from row presence alone, and only the flag tells
them apart. It's also the cheap, explicit answer to "has anything here ever been touched"
without scanning every field of every item to find out. The first touch to any field, any add,
remove, or reorder, flips it and — for a collection — materializes every sibling implied by the
current default at that same moment, not just the one being touched, since position and count
are only coherent relative to the whole set.

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
