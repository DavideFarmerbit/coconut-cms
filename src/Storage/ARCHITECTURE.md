# Storage / Editor Architecture — Design Notes

Status: **future phase, nothing in this document is implemented yet.** This is a decision
log from an early design discussion, kept here so the reasoning isn't lost before the
work actually starts. Revisit and revise as real constraints show up.

## The goal

An admin editor in the spirit of Unreal's UObject/Details-panel system: classes are
displayed and edited on screen through reflection, with PHP attributes customizing how
each property renders (label, group/category, widget). Because the shape is reflected
rather than hand-wired per content type, generic mechanisms fall out almost for free:
a generic property editor UI, generic persistence, and — because the format is
self-describing and typed — copy/paste of whole field groups across records. That last
one is a genuine WordPress differentiator: ACF/WP field values are untyped serialized
blobs keyed by string names with no schema, so there's no safe generic copy/paste
primitive the way there is here.

Two other pieces belong to the same phase and are referenced throughout this document:

- **Editor-reflected properties**: the source of a content type's shape doesn't have to
  be a hand-written PHP class. A site owner can assemble a content type's fields
  entirely through the editor UI, with no class ever written. Any design here has to
  treat "reflect a real PHP class" as *one* source of shape information, not the only
  one.
- **Undo queue**: editor operations are recorded as invertible commands so Ctrl+Z works
  client-side. See "Undo / command queue" below — it turns out to share real structure
  with the server-side write path.

## Decided so far

### Shape comes from a neutral descriptor, not directly from PHP reflection

Doctrine's model (attributes on a real class, reflected to build a schema) assumes the
class exists in code. That assumption breaks the moment a content type is assembled
entirely in the editor with no PHP class behind it. The fix: define a neutral
`FieldDescriptor` (name, type, label, group, "reference to another shape" for
nested/edit-inline objects, "collection of X", roughly) as the one shape representation
everything downstream (form rendering, persistence, copy/paste) actually operates on.

Two different **sources** can produce `FieldDescriptor[]`:
- Reflecting a hand-crafted PHP class's attributes (the Doctrine-like path).
- Reading a stored schema definition that the editor itself authored (the
  fully-dynamic path, no class involved).

This is the same shape as `Routing\RouteArguments::ofClass()` vs. `ofClosure()`
already built in this codebase: two different sources of `ReflectionParameter[]`, one
shared downstream pipeline that doesn't care which source it came from. Same move, one
level up.

Not yet decided: the exact attribute/descriptor field list, and the runtime shape of an
editor-assembled instance (no PHP class exists for it). Current lean: a generic dynamic
value-bag object (shape reference + associative values, accessed generically), not a
class synthesized at runtime via `eval()` — we already backed away from `eval()`-based
class generation once in this codebase (see the `Rout`/`RouteData` design discussion)
for the same reasons: fragile, hard to debug, and here it'd be worse since the shape can
change at any time through the editor, not just once at bootstrap.

### Storage: real columns for queryable fields, a JSON blob for everything else

Four storage strategies were originally weighed (Doctrine's own menu, roughly): flat
columns, embeddables (nested fields inlined as extra columns), join tables, JSON
columns — plus EAV (Entity-Attribute-Value), which is what WordPress/ACF actually use
under the hood (`wp_postmeta`: one row per `(post_id, meta_key, meta_value)`), confirmed
by checking ACF's real behavior rather than assuming. EAV was initially preferred over
JSON specifically because it stays relationally queryable — every value its own row,
indexable with ordinary SQL — where JSON needs special DB functions and usually isn't
cheaply indexable.

**That reasoning stopped applying once schema migrations were settled as core
infrastructure needed regardless (see below) — EAV's entire justification was avoiding
`ALTER TABLE`. Once real migration tooling exists anyway for the completely ordinary
case of a class's fields changing over time, maintaining EAV as a second, weaker path to
queryability alongside real columns doesn't buy anything.**

**Decided: queryable always means a real column, full stop — no EAV.** Anything not
explicitly marked `#[Queryable]` lives in a single JSON blob column instead. Once
nothing in that tier is ever filtered, sorted, or joined against, JSON's one real
weakness relative to EAV (poor indexability) stops mattering, and its strengths (natural
nesting, natural collections/repeaters with no need for ACF's flatten-into-indexed-keys-
plus-count-row trick, simpler to implement — one column, not N EAV rows to keep
consistent) make it the better default for the non-queryable tier. Each stored blob
still needs a small version tag so an old shape can be read and upgraded later — the
per-record versioning idea carries over unchanged, just simpler now that there's only
one tier that needs it.

### Schema evolution: migrations are core infrastructure, not optional to defer

The small set of real relational columns needs ordinary migrations, and this is **not
optional infrastructure to defer**: the moment any `#[Queryable]` field exists at all, a
hand-crafted class's property being renamed, retyped, or removed is an entirely ordinary
event that needs a real schema change to follow it — needed for the completely ordinary
developer-facing case, regardless of anything about editor-assembled content.

Decided: reuse **Doctrine DBAL's `Schema`/`Comparator` components** (usable standalone,
without adopting the full Doctrine ORM) for the actual diffing and DDL generation,
rather than hand-building schema-diffing logic — that's genuinely hard, engine-specific
code that's already been solved well. Our own responsibility is narrower: translate the
`#[Queryable]` subset of a class's `FieldDescriptor`s into DBAL's `Schema`/`Table`/
`Column` representation; DBAL does the comparison against the live database and
produces the `ALTER TABLE` statements.

Trigger policy for native classes: the standard migrations workflow every mature ORM
already has — diff at development time, generate a reviewable migration file, apply
through a deliberate deploy step. A human reviews the generated DDL before it ever
touches production. Nothing exotic here, well-trodden ground.

### Entity prototypes: native classes are fixed, editor-created subclasses aren't

Resolves what was an open question ("should admins ever trigger real schema changes at
runtime?") completely, once framed the right way: not "new vs. existing," but **native
(PHP-declared) vs. editor-created** — exactly the same line Unreal draws between a
native `UCLASS` and a Blueprint. You cannot add a `UPROPERTY` to a native C++ class at
runtime; you subclass it and add properties to the subclass. Same rule here:

- **A native class's declared properties are permanently fixed.** No runtime path to
  change them, ever. Only a developer editing the source and running a real migration
  (see above) can add, remove, or retype one.
- **An "entity prototype" is always either a fresh definition or an explicit subclass**
  of an existing prototype — native or itself editor-created — created through the
  editor. A consumer wanting admin-authored Products defines a native `Product` base
  class (fixed, developer-owned schema) and has actual product instances be an
  editor-created subclass of it, so admins get to freely shape *that*, while `Product`'s
  own declared properties stay off-limits.
- **Editor-created subclasses can be freely grown and shrunk, even after they already
  have rows** — this is genuinely safe, not just convenient, for two compounding
  reasons. First, it's always scoped to that subclass's own table (see Class Table
  Inheritance below), never the native parent's table, never a sibling subclass, at any
  depth in the chain. Second, it's restricted to cheap, safe DDL operations only: adding
  a nullable column and dropping a column — both metadata-only or near-instant on modern
  Postgres (11+)/MySQL (8+). Changing a column's type, adding `NOT NULL` without a
  default, or a true rename all stay off the table entirely; a rename is better modeled
  as "add a new column, deprecate the old one."
- **Dropping a property loses whatever data lived in that column** — this is exactly the
  kind of operation that should trigger the backup/revision mechanism first (see "Undo /
  command queue" below), so an admin's mistake is recoverable through the same
  revision-history pipeline rather than being a new problem to solve.
- **A native class must opt in to being subclassable by admins** — Unreal's
  `Blueprintable` flag, essentially. Not every native class should be extensible by
  admins by default (an internal infrastructure class was never meant to be content).
  The exact mechanism for marking this isn't specified yet (see "Open questions").

**Mechanism for "extends": Class Table Inheritance, not Concrete Table Inheritance.**
Two ways to map inheritance onto tables were considered. Concrete Table Inheritance
(every prototype gets its own fully standalone table, all columns duplicated, no joins
ever) is simpler to read from, but makes "show me all Products regardless of which
sub-prototype created them" — exactly the aggregate admin-list query a CMS needs —
awkward, requiring a `UNION` across however many concrete tables happen to exist, a set
that grows every time an admin creates a new prototype. Class Table Inheritance (a
derived prototype gets a new table holding only its *added* columns, plus a foreign key
back to the parent prototype's table, joined when hydrating a full instance) keeps the
base prototype's table as a natural, single-table home for "all instances of the base
type," while still only ever needing `CREATE TABLE` for the new derived table — never
touching the existing parent table, so the safety property above holds. This is also a
named, real Doctrine strategy (`JOINED` inheritance), not invented from scratch. It
composes uniformly regardless of whether the prototype being extended is native or
itself editor-created — same mechanism either way.

**What this does and doesn't cover**: creating a new prototype (from scratch, or
extending an existing one) is always safe — always `CREATE TABLE` on an empty table,
regardless of who triggers it. Growing an *existing, already-populated* prototype is
only ever safe for editor-created subclasses specifically, restricted to the safe
operation set above; for native classes it's never available at all, by design — not a
safety workaround, but what "native" is supposed to mean.

### Join tables: when they're actually worth it

Worth it when **both** hold: the item has a real, stable table to reference (a native
class, or an editor-created prototype — the latter now qualifies too, since entity
prototypes get real Class-Table-Inheritance-backed tables regardless of who created
them; "editor-created" no longer means "no fixed columns" the way it did before entity
prototypes existed), **and** the relationship needs something SQL is good at:

- True many-to-many relationships (tags/categories — near-guaranteed to want a real
  pivot table regardless of anything else decided here).
- One-to-many children that need independent querying across parents (comments: "all
  comments awaiting moderation site-wide" has nothing to do with any one parent post).
- Collections needing real aggregation (order line items → "revenue per product across
  all orders").
- Needing the database to enforce referential integrity (a real FK guarantees a
  reference is valid; a value living in the JSON blob referencing an id does not).
- Large or fast-growing collections that need independent pagination/indexing without
  touching the parent's own row on every insert.

Not worth it: small purely-presentational edit-inline structs that only ever load/save
as a unit with their owner and are never queried independently (an SEO-metadata struct
on a page, say) — a value-object column, or a spot in the JSON blob, is simpler and
sufficient there.

### Entity vs. Value Object is the line that actually decides "does this get a table"

The mechanical question isn't about the shape of the data (the same struct — e.g.
Address — can legitimately be either, depending on usage) but about identity, using the
standard DDD test, reduced to four checkable questions:

1. Does it need to be shared/referenced from more than one place, such that editing it
   in one place should be visible everywhere it's referenced? → Entity.
2. Does it need to be found/queried independently of whatever currently owns it? →
   Entity.
3. Does it have its own lifecycle, independent of any single owner? → Entity.
4. Does the business talk about "the same X" over time despite changed attributes (vs.
   two value objects with equal attributes being simply interchangeable)? → Entity.

Yes to any → Entity (own table, own repository, own identity-map presence, referenced
via FK). No to all → Value Object (embedded in the owner's storage somehow — inline
columns, or a spot in the owner's JSON blob — never its own table, because there's no
identity to key a table on).

This can't be inferred automatically by the system — whether an Address needs to be
shareable is a business judgment, not something derivable from the shape of the data.
Doctrine doesn't try to infer it either (`#[Embedded]` vs. `#[OneToOne]`/`#[ManyToOne]`
is always explicit). Same here: one deliberate choice at the point a field gets defined,
whether that's an attribute on a hand-crafted class or a plain-language prompt in the
editor UI ("will this ever be shared across more than one record?" / "do you need to
search by this on its own?" / "does this only make sense as part of its owner?").

**Embeddable column-unwrapping** (`address.city` → a real `address_city` column) is a
Value-Object-level storage tactic, not a third option competing with Entity vs. Value
Object — it's what to reach for when a value object (no identity/sharing need) has a
specific sub-field that needs DB-level querying, without promoting the whole value
object to a full Entity just to get that one field indexable.

### Identity Map + Repository + lazy loading

Standard pattern (Fowler's PoEAA; this is literally what Doctrine's
`EntityManager`/`UnitOfWork` does): resolving the same entity id twice within a request
must yield the *same* PHP instance, not two independently-hydrated copies that could
silently drift apart if one gets mutated. This is a correctness property, not just a
performance optimization.

Two distinct, complementary mechanisms:
- **Lazy loading** — *when* a query runs (an association doesn't fetch until touched).
- **Identity map** — *whether* a second resolution of the same id reuses the first
  result. A lazy proxy, once triggered, should consult the identity map before running
  a fresh query.

**Repository** is the natural home for "fetch + hydrate + lazy-load + register with the
identity map" per entity type — this is what "a special query class that can fetch,
hydrate, and cache the result only when asked for" (the original phrasing that started
this thread) actually is in standard vocabulary.

**Scope: per-request, not persistent.** Unreal's object graph lives in one long-running
process; a PHP web request is stateless and short-lived unless a persistent-worker
runtime (RoadRunner/Swoole/FrankenPHP) is deliberately introduced, which is a much
bigger commitment and out of scope for now. The identity map should be built fresh per
request and discarded at the end — exactly how Doctrine's `EntityManager` is scoped by
default. A *cross-request* cache (APCu/Redis, keyed by class+id) is a legitimate
separate optimization layered on top later, but has its own invalidation problem and
should not be conflated with the in-request identity map's correctness guarantee.

Not a fit for reusing `Core\Container`/`Kernel` as-is: `Container` caches by a fixed
`Identifier` bound once at bootstrap; an identity map's keys (`Tag:5`, `Tag:12`, ...)
are dynamic, discovered at runtime as things get touched during a request. Same
underlying principle ("resolve once, cache by key, reuse the instance"), different
structure.

### Undo / command queue

Editor operations are recorded as invertible commands, undo stack lives client-side —
same pattern as Unreal's own editor transactions (`FScopedTransaction`) and how
Figma/Google Docs do it. Lean: **session-scoped, not required to survive a page
reload/crash** — that's what real editors of this kind actually do, and persisting a
durable command log (IndexedDB client-side, or streamed to the server) is a
substantially bigger lift that isn't clearly justified yet.

Important operations should also produce **server-side backups**, and this falls out
almost for free *if* the generic serialization pipeline above is solid: a backup is just
"keep the last N snapshots instead of overwriting, prune older ones" — the exact same
shape already implemented for log rotation
(`Core\Error\Logger\DefaultErrorLogger`: `error-YYYY-MM-DD.log`, prune anything past
`retainDays`). Worth reusing that pattern directly for content revision history rather
than designing a new one.

Also worth designing together rather than separately: the client-side command stream
and the server-side "what changed, what needs to be flushed" tracking (see Unit of Work
below) are conceptually the same kind of diff. Two independent systems solving
adjacent problems is a likely source of drift.

### Write path: explicit changeset (not auto-diffing), with real topological sort

Four options were weighed: naive immediate-write (no batching, atomicity left to the
caller to remember); a classic Doctrine-style Unit of Work with automatic dirty-checking
(snapshot every hydrated object, diff at flush time — real, but one of the largest,
most intricate subsystems in any ORM); an explicit changeset that reuses the undo
command stream as the source of "what changed" instead of auto-diffing; and full event
sourcing (command log as the durable source of truth, current-state tables as a
derived projection) — genuinely more capable (perfect audit trail, arbitrary
time-travel) but a real paradigm shift disproportionate to what a CMS needs.

**Decided: explicit changeset, sourced from the same command stream already needed for
undo** — no separate automatic dirty-checking machinery. This avoids building the most
expensive part of a classic Unit of Work by reusing something already committed to.

**Command vs. changeset are separate concepts.** "Command" is the undo-aware,
editor-facing recording of a user action. "Changeset" is the lower-level "these fields
→ these values" data the flush/transaction engine actually consumes. The command system
is a convenience layer that *produces* a changeset — it isn't the only way to produce
one. Bulk/programmatic writes (CSV import, a migration script) build a changeset
directly and go through the same flush engine, without synthesizing fake undo-able
commands or touching the undo stack at all.

**Write ordering: full topological sort, not a bounded heuristic.** A simpler rule
("when a changeset creates a new related entity inline, insert it first") was
considered and rejected — CMS content can nest arbitrarily deep (a Product creating a
new Category creating a new Category-Image creating ...), and a hand-maintained list of
"which inline-creation patterns are supported" would need extending by hand every time a
new pattern shows up in practice. Full topological sort handles arbitrary depth without
that maintenance burden. Concretely:

- Dependency edges are derived **automatically** from the changeset's own reference
  structure, not manually declared by whoever builds the changeset. This requires the
  changeset format to support a **temporary/placeholder id** for an entity being
  created in the same flush, so "attach this new Tag to this Product" can be expressed
  before the Tag has a real database id — a concrete new requirement now placed on the
  still-open "exact changeset shape" item below.
- The same edges are read in **opposite directions** for inserts vs. deletes: on
  insert, a referenced entity must be written before its dependent (so the real
  generated id exists to put in the FK column); on delete, it's the reverse. Updates
  generally don't need ordering among themselves, unless an update introduces a brand
  new reference to something also being created in the same flush — that reference then
  behaves like an insert for ordering purposes.
- **Cycles need an explicit answer.** Two new entities in the same flush referencing
  each other can't be resolved by any ordering. Decided: reject the changeset with a
  clear error naming the cycle. A "deferred edge" escape hatch (insert both with a
  nullable FK left null, patch it in on a second pass) is deliberately not being built
  until an actual case demonstrates it's needed.
- Scope of the sort itself is small (Kahn's-algorithm-sized, bounded to whatever's in
  one flush — typically a handful of entities, not a performance concern). The real
  work is the edge derivation (temporary-id resolution) and getting insert/delete
  direction and cycle detection right, not the sort algorithm itself.

### Admin list/filter views: resolved, once EAV was removed

This was flagged as the item most likely to force a rethink, and instead it mostly
dissolved once EAV was removed from the storage model. Since queryable always means a
real, properly-typed column now, admin filtering/sorting never touches a self-join at
all for anything actually surfaced in a list view — the entire EAV multi-field
self-join cost that motivated treating this as high-risk no longer applies to it.

- **A query-builder abstraction** (`Query::for(Product::class)->where('price', '>',
  100)->orderBy('created_at')`) resolves each field to its real column via
  `FieldDescriptor` metadata, so callers never need to know or care about storage
  details — same uniformity principle as everywhere else in this design.
- **Cursor/keyset pagination, committed to from the start** — not OFFSET/LIMIT. The
  reason cursor pagination was originally set aside was specifically that sorting by an
  EAV-backed field needs a cursor encoding a joined value plus a tiebreaker, which is
  genuinely fiddly to get right. With sorting only ever happening on real, typed
  columns, a standard keyset cursor (sort-column value + primary key tiebreaker) is
  straightforward and correct from day one, with none of the OFFSET-pagination
  weaknesses (degrading performance at depth, instability under concurrent writes) to
  accept as a trade-off. Total-count display ("showing 21–40 of 1,532") still needs its
  own `COUNT(*)` with the same `WHERE`, independent of pagination style.
- **Filtering/sorting by something inside a repeated/collection sub-structure stays out
  of scope** — but now trivially so, since nested collections live in the JSON blob by
  construction (never marked `#[Queryable]`), so this was never something the query
  builder needs to support in the first place, not a deliberately deferred capability.

### Validation: strategy pattern, not a growing pile of `FieldDescriptor` flags

Business rules (required, length limits, ranges, format, cross-field rules) are a
different concern from shape/type, and rather than growing `FieldDescriptor` into a
kitchen-sink of validation flags, they're pulled out into the same
interface-plus-swappable-implementations shape already used twice in real code in this
codebase (`RouteValueCaster`/`DefaultRouteValueCaster`, `ErrorLogger`/
`DefaultErrorLogger`) — new validation kinds are just new classes, never a change to
`FieldDescriptor` itself.

```php
interface FieldValidator
{
    public function validate(mixed $value): bool;
    public function describe(): array; // e.g. ['type' => 'maxLength', 'value' => 255]
}
```

`FieldDescriptor` holds a **list** of validators, not one — required and max-length are
independent, composable rules (all must pass), the same way Symfony's Validator
component attaches a list of Constraint objects per property rather than one combined
constraint.

**Cross-field rules** (end date after start date) can't live on one field's descriptor
at all — they're a separate `PrototypeValidator` interface at the entity level,
evaluated against the whole hydrated set of field values, using the same
strategy-pattern shape. Native classes can implement arbitrary logic here; editor-created
prototypes can only pick from whatever built-in `PrototypeValidator` implementations
exist (a closed menu, not arbitrary code) — an inherent, accepted asymmetry, the same
one already accepted for admin-authored schema versus native-code flexibility elsewhere
in this document.

**Server-side validation is mandatory, not a design choice** — client-side is always
bypassable, so the server independently re-validates every changeset regardless of what
the client already checked. It hooks in before the topological sort / before the flush
transaction opens: a validation failure rejects the whole changeset outright, same
"fail before, not during" shape as changeset cycle detection.

**Client-side pre-validation, without duplicating logic, splits into three tiers —**
most validators land in the first two, not the third:

1. **Native HTML5 constraint attributes** — `required`, `minlength`/`maxlength`,
   `min`/`max`/`step`, `pattern` (a browser-matched regex), `type="email"`/`"url"`/
   `"number"`. `describe()`'s `{type, ...params}` maps directly to one of these, and the
   **browser itself** validates via the native Constraint Validation API — no JS
   required at all, just a generic `type` → attribute lookup table. Covers most common
   cases.
2. **Named, reusable algorithms the browser doesn't support natively** — IBAN, credit
   card checksum, phone format, postal code by country. Not bespoke — standard,
   nameable patterns. `describe()` returns `{type: 'iban'}`, and a small **shared
   registry of JS validator functions**, keyed by the same `type` strings the PHP side
   uses, performs the check generically. Real code, but shared/reusable, not per-field.
3. **Genuinely bespoke, one-off logic** with no reusable name behind it. Only this tier
   is stuck with `describe()` returning a plain label and waiting on the server
   round-trip — should be the rare exception once tiers 1 and 2 are reasonably filled
   out, not the default assumption.

**Clean decoupling worth keeping**: the PHP `FieldValidator` only ever emits
`{type, ...params}` from `describe()` — it never needs to know or declare which tier a
given `type` falls into. That decision lives entirely in the client-side registry.
Adding client-side support for a previously-server-only type later is purely a
client-side change; the PHP validator class and its `describe()` output never need to
change.

## Open questions — not yet decided

Roughly in order of how much they threaten the decisions already made above (the rest
can probably layer on without disturbing what's already settled):

1. **Field-level permissions.** Not just "can this user edit this content type" but
   potentially per-field (e.g. a flag only admins can touch). Unaddressed.
2. **Draft/publish state and revisions.** Ties back to the backup idea above — does a
   draft duplicate the whole record, or overlay pending changes on the published one?
3. **Media/file fields.** Images and uploads likely want their own handling (storage
   backend, thumbnails, metadata) rather than being just another field value — probably
   its own Entity type eventually, not designed yet.
4. **Exact `FieldDescriptor` shape and attribute design**, including the exact
   mechanism for opting a native class in to being admin-subclassable (Unreal's
   `Blueprintable` flag, essentially — agreed in principle, not designed in detail).
   Named as a concept throughout, not specified field-by-field yet.
5. **Exact changeset shape.** Needs to express field-level changes per entity plus
   temporary/placeholder ids for not-yet-persisted entities referenced within the same
   flush (see Write path above) — not specified in detail yet.
6. **FK `ON DELETE` behavior vs. app-level delete ordering.** The topological sort
   handles delete ordering at the application level — worth deciding later whether the
   database's own `CASCADE`/`RESTRICT` constraints are also relied on as a backstop, or
   whether the app-level sort is treated as the only safety net.
