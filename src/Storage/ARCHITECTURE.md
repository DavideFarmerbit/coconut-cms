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

### Storage: hybrid of real relational columns + EAV, JSON as last resort

Four storage strategies were weighed (Doctrine's own menu, roughly): flat columns,
embeddables (nested fields inlined as extra columns), join tables, JSON columns — plus
EAV (Entity-Attribute-Value), which is what WordPress/ACF actually use under the hood
(`wp_postmeta`: one row per `(post_id, meta_key, meta_value)`), confirmed by checking
ACF's real behavior rather than assuming.

Decision: **EAV over JSON as the default flexible/schema-less mechanism.** Both solve
"no fixed schema needed ahead of time," but EAV stays fully relational — every value is
its own row, addressable and selectively indexable with ordinary SQL — where JSON needs
special DB functions and is usually not cheaply indexable. JSON is explicitly the last
resort, not the default.

Known, accepted cost of EAV: filtering on *multiple* fields at once requires
self-joining the EAV table once per filtered field. This is the classic, well-documented
EAV weakness (see "Open questions" — admin list/filter views is where this becomes
concrete rather than theoretical).

Repeaters / collections without their own identity: ACF's technique is worth reusing —
flatten into indexed keys across multiple EAV rows (`items_0_title`, `items_1_title`,
...) plus one row holding just the count. No nesting at the storage level at all.

**Hybrid rule**: real relational columns/tables only for the fields that need genuine
DB-level querying, filtering, joins, or aggregation — explicitly marked as such (e.g. an
attribute akin to `#[Queryable]`) — and EAV for everything else. This mirrors what
WordPress itself does (`wp_posts` has real columns for title/status/date; `wp_postmeta`
is the EAV overflow for everything custom).

### Schema evolution splits into two much smaller problems

- EAV fields need no `ALTER TABLE`, ever — a new field is just a new `meta_key`. But
  each stored value (or record) needs a small version tag so an old shape can still be
  read and upgraded later. Per-record versioning, not a database migration.
- The small set of real relational columns still needs ordinary migrations, exactly
  like any traditional ORM — but that surface is deliberately kept small since it's
  opt-in per field.

### Join tables: when they're actually worth it

Worth it when **both** hold: the item's shape is fixed/hand-crafted (not
editor-assembled — a join table needs real predetermined columns same as flat columns
do), **and** the relationship needs something SQL is good at and EAV isn't:

- True many-to-many relationships (tags/categories — near-guaranteed to want a real
  pivot table regardless of anything else decided here).
- One-to-many children that need independent querying across parents (comments: "all
  comments awaiting moderation site-wide" has nothing to do with any one parent post).
- Collections needing real aggregation (order line items → "revenue per product across
  all orders").
- Needing the database to enforce referential integrity (a real FK guarantees a
  reference is valid; a JSON/EAV value referencing an id does not).
- Large or fast-growing collections that need independent pagination/indexing without
  touching the parent's own row on every insert.

Not worth it: editor-assembled shapes (fails the fixed-columns requirement), and small
purely-presentational edit-inline structs that only ever load/save as a unit with their
owner and are never queried independently (an SEO-metadata struct on a page, say) — EAV
or a value-object column is simpler and sufficient there.

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

Yes to any → Entity (own table/EAV space, own repository, own identity-map presence,
referenced via FK). No to all → Value Object (embedded in the owner's storage somehow —
inline columns, EAV rows scoped to the owner, or a sub-blob — never its own table,
because there's no identity to key a table on).

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

## Open questions — not yet decided

Roughly in order of how much they threaten the decisions already made above (the first
is the one most likely to force a rethink; the rest can probably layer on without
disturbing what's already settled):

1. **Admin list/filter views.** Everything discussed so far is fetch-by-id. Real admin
   screens need "all Products where category = X and price > 100, sorted, paginated" —
   exactly where EAV's multi-field self-join weakness stops being an abstract caveat
   and becomes a UI that has to actually work. Likely needs its own dedicated design
   pass, and probably the next one.
2. **Validation.** `FieldDescriptor` carries shape/type, not business rules (required,
   length limits, cross-field rules). Where does this live — more attribute metadata
   read by the same generic machinery? Does it need to run both client-side (editor
   feedback) and server-side (real enforcement, since client-side is always
   bypassable)?
3. **Field-level permissions.** Not just "can this user edit this content type" but
   potentially per-field (e.g. a flag only admins can touch). Unaddressed.
4. **Draft/publish state and revisions.** Ties back to the backup idea above — does a
   draft duplicate the whole record, or overlay pending changes on the published one?
5. **Media/file fields.** Images and uploads likely want their own handling (storage
   backend, thumbnails, metadata) rather than being just another field value — probably
   its own Entity type eventually, not designed yet.
6. **Exact `FieldDescriptor` shape and attribute design.** Named as a concept above,
   not specified field-by-field yet.
7. **Exact changeset shape.** Needs to express field-level changes per entity plus
   temporary/placeholder ids for not-yet-persisted entities referenced within the same
   flush (see Write path above) — not specified in detail yet.
8. **Exact EAV table shape.** Typed value columns (separate string/int/float columns
   or a `value_type` discriminator) vs. one text column; the precise count-row
   flattening mechanics for repeaters/collections.
9. **FK `ON DELETE` behavior vs. app-level delete ordering.** The topological sort
   handles delete ordering at the application level — worth deciding later whether the
   database's own `CASCADE`/`RESTRICT` constraints are also relied on as a backstop, or
   whether the app-level sort is treated as the only safety net.
