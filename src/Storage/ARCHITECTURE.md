# Storage / Editor Architecture — Design Notes

Status: **future phase, nothing in this document is implemented yet.** The original
design pass is complete, and a subsequent audit pass caught real drift between
decisions made many turns apart plus a handful of gaps never raised at all — both
corrected, with the gaps logged as a fresh "Open questions" list at the end. This is
still a decision log, not a spec frozen in stone. Revisit and revise as real
implementation surfaces constraints this discussion didn't anticipate.

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
  client-side. See "Undo, draft, and revisions" below — it turns out to share real
  structure with the server-side write path, and to actually be three related but
  distinct pipelines, not one.

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

**`#[Queryable]` gets a real database index, not just a real column.** The entire point
of marking a field this way is admin-list filtering/sorting performance — a column with
no index behind it wouldn't deliver on that at all. Worth stating explicitly rather than
leaving implied.

**Important carve-out, found missing during an audit pass: `EntityReference` fields —
singular or inside a `Collection` — always get a real column or table, regardless of
their own `queryable` flag.** This was previously unstated and led to a real internal
contradiction (a `collection()` of tags was simultaneously described as needing "a real
pivot table" in one section and living in the JSON blob "by construction" in another —
see "Join tables" and "Admin list/filter views"). The fix: `queryable` on an
`EntityReference` field only ever answers "can an admin list be filtered/sorted by
this field's value" — a query-builder capability question — never "which storage tier
this field lives in." A reference to a real Entity always needs a real FK, full stop,
because the entire `RESTRICT`/`CASCADE` policy, the undo-log's conflict detection, and
referential integrity generally all assume one exists. Only `EmbeddedValueObject`
fields (and collections of them) are governed by the "queryable or blob" choice above —
they have no identity to key a table on regardless, so there's no FK to preserve either
way.

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
  kind of operation the universal undo-log pipeline covers (see "Undo, draft, and
  revisions" below — a `SchemaOperation` entry, snapshotted before the drop), so an
  admin's mistake is recoverable through the same mechanism as any other undo, not a new
  problem to solve.
- **A native class must opt in to being subclassable by admins** — Unreal's
  `Blueprintable` flag, essentially (named `#[EditorExtensible]` here — see the naming
  note in "Exact `FieldDescriptor` shape" below for why). Not every native class should
  be extensible by admins by default (an internal infrastructure class was never meant
  to be content). The concrete mechanism is a class-level `#[EditorExtensible]`
  attribute — see "Exact `FieldDescriptor` shape" below for the actual definition, and
  "Schema-level authorization" for who, specifically, is allowed to use it.

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
itself editor-created — same mechanism either way, **to arbitrary depth**: a native
class can be subclassed by an editor, and that editor-created subclass can itself be
subclassed by an editor again, indefinitely, mixing native and editor-created levels
freely. Nothing in this design assumes a fixed or bounded chain depth anywhere — each
level just adds one more joined table.

**No cap on that depth is needed, and this isn't just an assumption — worth actually
examining the cost rather than leaving it unexamined, which is what an audit pass
flagged as missing here.** Three things hold at once:

- **Realistic depth is probably shallow anyway, not by luck but as a direct
  consequence of a decision already made.** Growing an editor-created subclass in
  place (adding a nullable column to your *existing* level) is already the cheaper
  alternative to adding a new level — "I need one more field" never requires more
  depth. A new level only makes sense for genuine taxonomic branching
  (`ElectronicsProduct` and `ClothingProduct` as distinct kinds sharing a base), and
  that pattern branches sideways — siblings at the same depth — not linearly deeper.
- **Hydrating one instance is cheap regardless of depth, and it's a fundamentally
  different cost shape than the EAV problem this whole design moved away from.** Each
  level's join is parent-primary-key to derived-primary-key — about the cheapest join
  a database can do — for exactly one row. Ten levels deep is ten cheap single-row
  joins, nowhere near EAV's actual problem (many rows, many simultaneous filter
  conditions, self-joining a large table). This also isn't a novel risk being
  introduced here — Class Table Inheritance is Doctrine's own `JOINED` strategy, used
  in real production systems at exactly this cost profile.
- **Where cost could plausibly matter — list views needing fields from multiple
  inheritance levels across many rows at once — isn't a new problem depth
  introduces.** It's the same query-builder mechanism "Admin list/filter views"
  already resolved, just needing one thing made explicit that was previously left
  implicit: resolving a field to its real column also means resolving *which table, at
  which inheritance level* holds it, and joining that in. If that ever becomes a real
  bottleneck in practice, it reuses the same documented-but-not-built read-model
  escalation already on the list for full-text/cross-content search — a denormalized
  view combining commonly-needed fields across levels — not a depth-specific
  escalation invented separately.

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

**The pivot table above is specifically the Shared case** (see "Ownership: Owned vs.
Shared" further below) — two independent entities, each with their own lifecycle,
related many-to-many. A one-to-many collection whose items are exclusively **owned** by
one parent (repeater items that have no life outside their parent) doesn't use a join
table at all, even if each item still needs its own row for querying — it's a
back-pointer FK directly on the item's own row, `CASCADE`, per that section.

### FK `ON DELETE` policy: `RESTRICT` for Shared references, `CASCADE` for Owned ones

Resolves a real tension between two already-decided mechanisms rather than being a
stylistic pick: the app-level topological sort already computes safe delete order
itself, and the universal undo-log only knows about operations the application
explicitly processes through a changeset. Leaning on database-level `CASCADE` broadly
would let the database silently delete rows the application never logged — invisible to
the undo pipeline, permanently unrecoverable through it.

- **`RESTRICT` (the engine's strictest option) is the default for genuine
  entity-to-entity references** (Product → Tag, Product → Category). The app-level
  topological sort is the actual mechanism that makes deletes happen in a safe order;
  the constraint is a correctness backstop for if that logic ever has a bug — a clear,
  rolled-back error, not silent data loss — never something the normal path is expected
  to hit.
- **`CASCADE` for Owned relationships** — anywhere a row has no independent meaning
  without its owner (see "Ownership: Owned vs. Shared" below). The Class Table
  Inheritance base→derived link was the first case decided here, but it turns out to be
  one instance of this general rule rather than a special case of its own: a derived
  table's row has no independent meaning without its base row, much closer to how a
  value object relates to its owner than to how two real entities reference each other —
  exactly the same reasoning that applies to a repeater item exclusively owned by one
  parent. Deleting the owner is already one `ChangesetOperation`; whatever it owns
  disappearing along with it is an implementation detail of carrying that out, not an
  independent event anyone would want to undo separately.
- **`SET NULL` is a legitimate choice only for references that are genuinely
  optional** — tied to whether that reference field actually carries a
  `RequiredValidator`; a required reference should never be allowed to silently go null.

**Confirmed this doesn't break undo, and why it's actually fine:** `CASCADE` only
affects what the database does *after* a delete is issued — it has no bearing on
whether the snapshot taken *before* the delete was complete. The undo-log already
requires snapshotting an entity's full state before any delete to compute its inverse;
as long as that snapshot walks the whole inheritance chain (base table plus every
derived level — exactly the same join an ordinary read already performs), the snapshot
is complete regardless of what the database cleans up afterward. Restoring after undo
needs no new machinery either — it's just flushing an insert changeset built from the
snapshot, going through the exact same base-then-derived insert ordering already used
to create a new instance of that prototype from scratch.

The one thing this depends on, worth being explicit about so it isn't silently missed
when this gets built: the snapshot-before-delete step has to actually invoke the
full-chain hydration, not just read the base table's own row. The capability already
exists — it's the same join every ordinary read already performs — it just has to be
invoked at the right moment.

**Chains go to arbitrary depth (see Class Table Inheritance above), and `CASCADE` needs
to be set consistently at every level, not just the first**, so deleting the root
entity cleans up the whole chain regardless of how many native and editor-created
levels sit beneath it. There's also no meaningful "delete just one middle layer"
operation — deletion always targets the whole entity, top to bottom, at whatever depth
the chain happens to be.

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

### Ownership: Owned vs. Shared decides who gets independent undo history

A further split within Entity, needed once a changeset can touch more than one entity at
once (create a Tag, attach it to a Product) — mirrors Unreal's asset-vs-subobject
distinction: a UObject is a top-level asset only if nothing else owns it; if something
does, it's a subobject serialized as part of its owner. Same class either way — it's
decided per relationship (per field), not baked into the prototype.

- **Shared**: independently referenceable from more than one place (Tag, referenced by
  many Products). This is the ordinary entity-to-entity reference already covered by the
  default `RESTRICT` policy — FK on the referencing side pointing outward for a scalar
  reference, or a real join table for a many-to-many collection (see "Join tables"
  above), with only the join *row* disappearing on either side's delete, never the
  entities themselves. **Gets its own independent undo/revision history**, per the
  count-based per-entity retention already decided in "The universal undo-log".
- **Owned**: exclusively belongs to one relationship, no life apart from it (a repeater
  item that only exists as part of one Product; a `MediaAsset` uploaded inline for one
  field's exclusive use, as opposed to one picked from a shared media library). For
  `CASCADE` to work at the database level at all, the FK has to point the *other*
  direction from a Shared reference — the owned row carries a FK back up to its owner,
  not the reverse. This is exactly the shape Class Table Inheritance's base→derived link
  already uses; `CASCADE` there turns out to be one instance of this general rule, not a
  special case of its own (see "FK `ON DELETE` policy" above, updated to match). **Gets
  no independent undo/revision entry at all** — its before/after values fold straight
  into the *owning* entity's own logged diff, exactly like an embedded value object
  already folds into its owner's JSON blob. Nothing extra to prune, nothing that can go
  stale independently of its owner, no gap in anyone's history.

**Why an Owned relationship always needs its own dedicated table, never a shared
`owner_id` column reused across different owning relationships:** a `CASCADE`-backed FK
column has one fixed target table. If the same reusable shape (a generic gallery-item
struct, say) were Owned by both `Product` and `Page`, one shared column can't point at
two different target tables, and a generic nullable polymorphic column gives up the real
referential integrity this whole FK-policy discussion exists to keep. So every Owned
relationship gets its own table — a Class-Table-Inheritance-style derived table carrying
the shape's normal fields plus that one relationship's own owner-FK-plus-`CASCADE`
column — regardless of how many other relationships reuse the same base shape elsewhere,
or whether one of them happens to be Shared instead.

This was briefly considered as something a class-level flag could opt out of for the
simple single-owner case — rejected. A flag on the shape itself can promise "nobody will
reference me as Shared," but it can't promise "only ever one specific owning
relationship will embed me," since nothing stops a later, unrelated module (this system
is explicitly moddable) from embedding the same shape as Owned a second time from a
completely different owner type. Only pinning a shape to one named owner class at its own
definition could actually guarantee that — at which point it stops being a reusable
prototype and becomes a nested type scoped to one specific class, a different and
narrower feature this system doesn't need. Always generating the per-relationship table
is the only version that's safe by construction rather than by convention, at the cost of
one extra join for the (probably rare) case where a shape really is only ever owned by
exactly one relationship.

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

### Undo, draft, and revisions: three pipelines, not one, and why they're separate

These three concepts turned out to need pulling apart rather than being designed as one
"undo/history" feature — each answers a genuinely different question, and conflating
them was the source of most of the confusion working through this.

**Draftable** answers "should this be hidden from the public until it's ready?" — a
question that only makes sense for content value changes. Nobody "sees" a schema, so
there's nothing to hide there.

**Undoable** answers "can this already-applied operation be reversed?" — genuinely
universal, applying equally to a content publish and an admin dropping a column on an
editor-created prototype. Both are just "an operation that changed state," and wanting
to reverse either is the same underlying need.

#### Draft: a persisted-but-unflushed changeset, not a duplicate row

A draft doesn't need its own storage/identity concept — it's the changeset the write
path already produces, just **persisted instead of flushed**. This reuses the entire
write path with zero new mechanism for publishing:

- "Publish" is flushing an already-built changeset through the write path exactly as
  designed — same topological sort, same temp-id resolution, same transaction. No
  separate merge/promote step to reconcile two divergent copies of an entity.
- A brand-new, never-published entity's draft is just a changeset targeting a temporary
  id, same mechanism already built for inline entity creation within a flush — "draft of
  something new" and "draft of an edit to something existing" reduce to the same
  concept, no special-casing between them.
- Public-facing reads are entirely unaffected by drafts in progress — the published row
  sits untouched until an actual flush happens.

Two genuinely new pieces this needs, both smaller than a duplicate-row scheme would
have required: somewhere to **persist** an unflushed changeset (a small store keyed by
target entity/session, not a duplicate table), and an **apply-in-memory** function
(hydrated entity + changeset → preview, no write, no transaction, no topological sort
needed for a single entity's own preview — ordering only matters across multiple
entities' relative writes). A draft referencing a not-yet-real inline entity via a temp
id needs the preview to resolve that id to its in-progress values, not a real row — a
real detail to remember when this gets built, not a blocker.

Deliberately out of scope: real-time concurrent multi-editor collaboration on the same
entity (one pending draft per entity for v1 — a second editor takes over the existing
draft or hits a conflict warning), and multiple named draft checkpoints beyond what the
client-side undo stack already provides.

**Schema changes are never draftable.** A changeset's own validation checks field names
against `FieldDescriptor[]`, which means the schema must already exist before a
changeset referencing it is even well-formed — schema changes are logically prior to
and separate from any changeset that would use them. There's also a concrete technical
reason beyond the conceptual one: DDL and DML are different statement categories, and
some engines (MySQL notably) implicitly commit any open transaction the moment a DDL
statement runs — bundling a schema change into the same flush transaction as content
edits would silently break the write path's atomicity guarantee. Schema changes go
straight through the DBAL-based DDL machinery when triggered, immediately, never queued
as a draft.

One interaction between the two falls out naturally rather than needing new detection
logic: an in-progress draft that predates an *additive* schema change stays valid (it
simply doesn't reference the new field, no different from any other field it doesn't
set). A draft referencing a field that's since been *dropped* fails the ordinary
changeset validation step at publish time — the field no longer exists in the current
`FieldDescriptor[]` — surfacing as a normal rejected-changeset error, not something that
needs special-case handling.

#### The universal undo-log: one pipeline behind two things that looked separate

Content revisions and the schema-change backup-on-drop were originally designed as two
unrelated safety nets. They're actually the same shape: **log every state-changing
operation with enough information to compute its inverse, retain a bounded amount,
prune the rest.** The underlying idea — retain-and-prune rather than keep everything
forever — is the same one already used for log rotation, but the *trigger* is
deliberately different, worth being precise about since an earlier draft of this
section conflated the two: `DefaultErrorLogger`'s actual retention (`pruneOldFiles()`)
is a **time-window** cutoff (anything older than `retainDays`), not a count. For this
pipeline specifically, **retention is count-based — keep the last N operations per
entity/prototype, not "operations from the last N days"** — a deliberate divergence,
not an oversight: a heavily-edited entity could otherwise accumulate unboundedly many
revisions within a time window, while a rarely-touched one would lose its last
meaningful edit after N idle days for no good reason. Time-based retention fits log
volume (which correlates with calendar time); count-based fits content history (which
correlates with edit activity, not the calendar). **This is specifically per *Shared*
entity** (see "Ownership: Owned vs. Shared" above) — an Owned relationship has no
independent entry of its own to retain or prune in the first place, since its changes
are already folded into its owning entity's own record. One pipeline, two categories of
operation:

- `ChangesetOperation` — a flushed changeset. Its inverse is the changeset that
  restores the previous field values (this is exactly what "restore to a previous
  revision" already meant). **Stored as a diff — before/after per changed field —
  not a full snapshot of the entity.** A diff is strictly better for this purpose: it's
  exactly what field-level conflict detection already needs to check (see below), it's
  proportional in size to what actually changed rather than the whole entity regardless
  of how small the edit was, and "what did this look like at revision N" is still fully
  reconstructable by replaying the ordered diffs — it just isn't a single stored read.
  See "Exact changeset shape" for the concrete `EntityChangeRecord` this produces.
- `SchemaOperation` — a DDL action. Adding a column inverts to dropping it (cheap,
  already-safe by construction). Dropping a column inverts to re-adding it *and*
  restoring the data that was in it — which is why the pre-drop snapshot needs to be
  part of the logged operation, not an afterthought.

"Undo," at this tier, means "apply the logged inverse of the most recent (or a chosen)
operation" — the same mechanism regardless of whether what's being undone is a content
publish or an admin dropping a column.

**Redo needs no new mechanism at all.** Since undo is already implemented as "compute
and flush a new inverse changeset," not as an in-place rollback, undoing an operation
just appends *another* logged operation to the same history — the inverse of the
original. Redo is simply "undo the undo": apply the inverse of *that* operation, which
reconstructs the original by definition. The append-only, log-everything design already
covers it.

**File-backed entities (`MediaAsset`, see Media/file fields) need one explicit rule for
this to actually hold, though**: undoing "add media" computes the inverse as "delete
this `MediaAsset` row," and if that also immediately reclaimed the underlying file
bytes, redo would break — re-creating the row has nothing to point back to once the
bytes are gone, and the same problem hits a plain revision-restore after enough time has
passed. **Decided: removing a `MediaAsset` reference is immediate at the database
level, but reclaiming the physical file bytes is deferred, piggybacking on the same
retention-window pruning sweep already governing this whole pipeline** — a file's bytes
stay on disk as long as anything still surviving the retention window (an undo-log
entry, a revision, or the current live state) could still reference them; only once the
pruning sweep removes the last such reference does it become safe to actually delete the
file. Fourth reuse of the same retain-and-prune shape in this document (count-based, per
the correction above — an entity's surviving revisions, not a calendar window), not a
one-off special case for media.

This is describing a **Shared** `MediaAsset` — one living in a media library, referenced
from wherever it's used, with its own independent retention window (see "Ownership:
Owned vs. Shared" above). An **Owned** `MediaAsset` (uploaded inline for one field's
exclusive use) has no independent retention window of its own to piggyback on — its
byte-reclamation timing follows whatever retention window governs its *owning* entity
instead, same as its row's changes already fold into that owner's own logged diff.

#### Client-side command stack: not every entry is a free, local revert

The original framing (session-scoped, client-side, matching Unreal's
`FScopedTransaction` and how Figma/Google Docs do it) still holds for the common case,
but the stack isn't as homogeneous as that implied — whether undoing an entry can be a
synchronous, in-memory revert depends on whether triggering it already caused a
server-side effect:

```php
interface Command
{
    // marker interface — LocalCommand and RemoteCommand are the only implementations
}

final readonly class LocalCommand implements Command
{
    // reverts synchronously, in-memory, no server involved — typing into a field,
    // not yet saved anywhere
}

final readonly class RemoteCommand implements Command
{
    public function __construct(
        public string $operationId, // references a specific ChangesetOperation/SchemaOperation
    ) {
    }
    // undoing this calls the server with $operationId — not a vague "undo my last
    // thing," an explicit "undo operation #12345", which matters especially for schema
    // changes where other admins might be touching the same shared prototype
}
```

Saving a draft, publishing, and an admin adding/dropping a column are all
`RemoteCommand`s — there's no "local, unconfirmed" phase for any of them; a schema
change in particular is server-side from the instant it's triggered. Typing into a
field before it's saved anywhere is a `LocalCommand`.

Two consequences worth being explicit about, since getting them wrong is a correctness
bug, not a rough edge: undoing a `RemoteCommand` needs a visible pending state, since
it's a real network round trip, not an instant operation. And the client must **not**
optimistically revert its displayed state before the server confirms the undo
succeeded — for a `LocalCommand` that's safe, there's no server state to drift from; for
a `RemoteCommand`, reverting the UI before confirmation risks showing something that
doesn't match what the database actually holds if the undo fails (a conflict, a network
error). From the user's perspective Ctrl+Z always looks the same either way — it's only
under the hood that some entries resolve instantly and others wait on a response.

#### Undo scope and conflict detection: who can undo what, and what happens if something changed since

**Ctrl+Z is inherently per-user, with no scoping decision needed to make it so.** A
`RemoteCommand`'s `operationId` only ever gets pushed onto *this user's own* client-side
stack, because that stack is only ever populated by this user's own actions — there is
no path by which a user's Ctrl+Z could reach an operation they never triggered, since
their client never recorded anyone else's. The universal undo-log itself (see above) is
genuinely shared and per-entity — that's the correct source of truth for **revision
history**, a separate, deliberate UI surface where an admin can view and restore *any*
past state, including one authored by someone else. The two surfaces read from the same
underlying log, but Ctrl+Z only ever reaches into the calling user's own slice of it.

**Two genuinely different kinds of conflict can arise between when an operation
happened and when someone tries to undo it — only one of them needs new machinery:**

1. **A later operation changed a value the undo would overwrite.** Caught by an
   explicit application-level check: before applying an undo, check whether any
   operation logged *after* the target touched any of the same `(entity, field)` pairs
   the target operation touched — across every entity the target spanned, not just one,
   since a single changeset can atomically touch several (create a Tag, attach it to a
   Product). For a *creation*, every field of the created entity counts as touched, not
   just a fixed subset — undoing a creation means deleting the whole entity, and if
   anything about it has changed since, silently deleting it would destroy real work.
   Nothing in the database catches this on its own; it has to be an explicit check.
2. **A later operation created a new dependency on an entity the undo would delete,
   without ever touching that entity's own fields.** E.g., after Tag #7 is created,
   someone attaches it to a *different* Product — an operation that never touches any
   of Tag #7's own fields, so check 1 can't see it. This is already handled for free:
   `RESTRICT` (the default FK policy decided above) means the database itself refuses
   the delete with a constraint violation the moment the undo's inverse changeset tries
   to remove a still-referenced row — the undo transaction fails cleanly, rolled back,
   no corruption. No new mechanism needed, though a proactive pre-check purely for a
   friendlier error message ("can't undo: Tag 'sale' is now also used by Product #99"
   instead of a raw constraint violation) is a reasonable optional UX polish, not a
   correctness requirement.

**Neither kind of conflict is auto-reconciled — both surface as a refusal, not a
silent merge.** Attempting to compute a three-way merge here would be exactly the kind
of disproportionate machinery already ruled out for full event sourcing; Git's own
`revert` takes the same posture (detect, surface, let a human decide). The two UI
surfaces differ only in how the refusal is presented: Ctrl+Z auto-blocks outright, since
it's an implicit, un-signposted action; the deliberate revision-history restore can
reasonably show a confirm-with-warning instead ("this would lose the following changes
made since — proceed?"), since navigating there and choosing a specific past state is
already an informed, deliberate act, not a surprise.

**Executing a multi-entity undo needs no new ordering logic.** Undo is already "compute
the inverse, then flush it" — and flushing any changeset, inverse ones included, already
goes through the same topological sort the write path always uses. Multi-entity undo
ordering is correct by construction, not a separate problem to solve.

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
- **Filtering an outer list by something inside a repeated/collection sub-structure
  stays out of scope, regardless of whether that collection is blob-stored or backed by
  a real join table** (see the `EntityReference`-always-gets-a-column carve-out in
  "Storage" above — a collection of tags does get a real pivot table, but that's about
  referential integrity, not about admin-list query capability). The query builder was
  never meant to support "find Products that have a tag matching X" — that needs
  `EXISTS`-style semantics against the join table, meaningfully harder than filtering a
  scalar column, and a much rarer real need. Deliberately out of scope either way, not
  something that fell out for free.

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

### Exact `FieldDescriptor` shape, and the `EditorExtensible` attribute

Almost every prior decision feeds into this one, so it's assembled rather than picked
from options. Private constructor plus named static factories — the same pattern
already used for `Route::structured()`/`Route::simple()` in this codebase — rather than
one constructor with independent nullable properties, specifically to make invalid
combinations (a `String` kind with a `referencedShape` set, a `Collection` with no
`collectionItemKind`) unrepresentable instead of just unlikely:

```php
final readonly class FieldDescriptor
{
    private function __construct(
        public string $name,
        public FieldKind $kind,
        public string $label,
        public ?string $group,
        public bool $queryable,
        public bool $unique,
        /** @var FieldValidator[] */
        public array $validators,
        public ?string $referencedShape = null,
        public ?FieldKind $collectionItemKind = null,
        public ?array $choiceOptions = null,
    ) {
    }

    public static function scalar(string $name, FieldKind $kind, string $label, ?string $group = null, bool $queryable = false, bool $unique = false, array $validators = []): self { /* asserts $kind is String/Int/Float/Bool; $unique implies $queryable — a UNIQUE constraint needs a real column regardless of what was passed for $queryable, see "Uniqueness validation" */ }
    public static function choice(string $name, array $options, string $label, ?string $group = null, bool $queryable = false, array $validators = []): self { /* sets choiceOptions */ }
    public static function embed(string $name, string $shapeClass, string $label, ?string $group = null, array $validators = []): self { /* queryable always false — the container isn't a column; individual nested fields marked queryable are, recursively */ }
    public static function reference(string $name, string $shapeClass, string $label, ?string $group = null, bool $queryable = false, bool $unique = false, array $validators = []): self { /* always a real FK column regardless of $queryable/$unique — those only control indexing/constraints on a column that exists either way */ }
    public static function collection(string $name, FieldKind $itemKind, ?string $referencedShape, string $label, ?string $group = null, array $validators = []): self { /* EntityReference items get a real join table (see "Join tables"); EmbeddedValueObject/scalar items live in the JSON blob, always non-queryable — filtering an outer list by a value *inside* a collection stays out of scope for v1 either way, per "Admin list/filter views" */ }
}

enum FieldKind
{
    case String;
    case Int;
    case Float;
    case Bool;
    case Choice;              // enum-like; options live in $choiceOptions, not a real PHP enum
    case EmbeddedValueObject; // no identity — inlined into the owner's storage
    case EntityReference;     // has identity — stored as an FK
    case Collection;          // repeated instances of $collectionItemKind
}
```

Deliberately **no `optional` property** — an earlier draft had one, but it either
duplicates or contradicts the `FieldValidator[]` list, which already covers "required"
as a composable rule (what wins if `optional: true` but a `RequiredValidator` is also
present?). Same shape-vs-business-rule conflation splitting `FieldValidator` out of
`FieldDescriptor` was meant to prevent in the first place. Storage columns just stay
nullable by default; the validator layer is what actually gates whether an empty value
is acceptable, before a write ever happens.

**`queryable` applies recursively**, which is what resolves "define extra columns
programmatically" for a nested struct without needing a separate mechanism: an embedded
value object's own fields are themselves `FieldDescriptor`s, so marking one `queryable`
inside an otherwise-blob value object is the entire story — the DBAL-translation step
walks the whole `FieldDescriptor` tree and dot-flattens any `queryable` one it finds at
any depth (`address.city` → `address_city`), the same embeddable column-unwrapping
mechanism already agreed on.

**For native classes**, an attribute carries only what reflection can't already tell
us — plain scalar `kind` is inferred from the property's real PHP type, the same way
`RouteValueCaster` infers casting behavior from a `ReflectionParameter`'s type rather
than needing it redeclared. A property whose type is another class needs one of two
explicit attributes, since "is this shared/referenced or just embedded content" is
exactly the Entity-vs-Value-Object judgment already decided as never inferable:

```php
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Field
{
    public function __construct(
        public ?string $label = null,
        public ?string $group = null,
        public bool $queryable = false,
        public array $validators = [],
    ) {
    }
}
```

`#[Embed]` and `#[Reference]` sit alongside `#[Field]` for the class-typed-property
case, mirroring Doctrine's own `#[Embedded]` vs. `#[ManyToOne]` split. A property
carrying more than one of these needs to be rejected at reflection time with a clear
error — not silently resolved by picking one — an implementation detail worth
remembering, not a design gap.

**For editor-assembled prototypes**, the same `FieldDescriptor`/`FieldKind` objects are
built directly from stored schema rows rather than reflection — no attributes involved,
just constructing the same value objects from different data, per the two-sources
principle from the very first decision in this document.

**`EditorExtensible` is class-level, not per-field** — it doesn't belong in
`FieldDescriptor` at all (renamed from an earlier working name, `Blueprintable` —
worth being deliberate about the name here, since it's easy to confuse with a
different, separate concern: this attribute is specifically about *extensibility* — can
admins create a subclass of this at all — not about *visibility* — whether a prototype
shows up as its own manageable content type in the admin UI, which would be a distinct,
not-yet-designed concern, reserved under a different name (`EditorType`, Unreal's
`BlueprintType` equivalent) precisely so the two don't get conflated later):

```php
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class EditorExtensible
{
    public function __construct(
        public ?SchemaPermission $permission = null,
    ) {
    }
}
```

**Reading a prototype's full effective shape** means walking its whole inheritance
chain and concatenating each level's own `FieldDescriptor[]` — the shape-level
counterpart to Class Table Inheritance needing a join up the chain at the storage
level.

**Known, accepted limitation**: collection-of-collection (a list of lists) isn't
representable in this shape — `collectionItemKind` has no way to itself be `Collection`
with its own nested item kind. Scoped out deliberately rather than left ambiguous; rare
enough for a CMS content model that it isn't worth the added complexity until a real
case demands it.

### Exact changeset shape

Most of the requirements were already scattered across earlier decisions (temp ids for
inline creation, the topological sort scanning for dependency edges, validation running
against a changeset before flush); this assembles them into one concrete shape, same
private-constructor-plus-named-factories discipline as `FieldDescriptor` and `Route` —
a `Delete` entry carrying field values, or a `Create` missing a `prototypeClass`, should
be unrepresentable, not just unlikely.

```php
enum EntityChangeKind
{
    case Create;
    case Update;
    case Delete;
}

final readonly class TempId
{
    public function __construct(
        public string $id,
    ) {
    }
}

final readonly class EntityChange
{
    private function __construct(
        public EntityChangeKind $kind,
        public string|TempId $target,   // TempId for a new entity, a real id otherwise
        public ?string $prototypeClass, // required for create, null otherwise
        /** @var array<string, mixed> */
        public array $values,           // field name => new value; empty for delete
        public ?string $expectedOperationId = null, // the "receipt" — see "Concurrent-write protection"
    ) {
    }

    public static function create(TempId $tempId, string $prototypeClass, array $values): self { }
    public static function update(string $entityId, array $values, ?string $expectedOperationId = null): self { }
    public static function delete(string $entityId, ?string $expectedOperationId = null): self { }

    // Restoring a deleted entity reuses its original real id rather than getting a
    // fresh one — the one legitimate exception to "creation always gets a new
    // identity." Needed so anything still referencing that id (unaffected by the
    // delete/restore round trip) keeps working without repointing.
    public static function restore(string $originalId, string $prototypeClass, array $values): self { }
}

final readonly class Changeset
{
    public function __construct(
        /** @var EntityChange[] */
        public array $changes,
    ) {
    }
}
```

`TempId` is exactly what the topological sort scans `$values` for to derive dependency
edges automatically — a value that's a `TempId` means "this depends on that `Create`
entry," no manual edge declaration needed, matching what was already decided for the
write path.

**`Changeset` (intent) and `ChangesetOperation` (the logged record) are deliberately
different shapes, not the same object at two stages.** A `Changeset` only ever carries
*new* values and may reference temp ids — it's "what I want this to become." The server
builds the logged `ChangesetOperation`/`EntityChangeRecord` (see "The universal
undo-log" above) at flush time by reading each entity's *current* live value
immediately before applying the change — the only place a true, non-stale "previous
value" can come from, since a value the client cached locally could easily be stale by
the time a flush actually runs. This read is also the natural hook point for the
conflict-detection check from "Undo scope and conflict detection" — it's already
reading current state at exactly the moment that check needs it.

**Two smaller things this shape resolves as a consequence, not new design:**
cross-field (`PrototypeValidator`) checks need the fully-resolved candidate state
(current entity with the changeset's new values overlaid) — exactly the same
apply-in-memory function already built for draft preview, reused rather than
duplicated. And raw changeset values (which might arrive as loosely-typed JSON from an
editor UI, or already-typed PHP values from programmatic code) need a casting step
against each field's `FieldDescriptor`-declared kind before validation runs — a
pipeline stage, not a new class needing full design here.

### Media/file fields: storage backend, `MediaAsset`'s shape, and thumbnails

Two pieces were already resolved as a consequence of earlier decisions rather than
needing new design: a media field is just an `EntityReference` to a `MediaAsset` entity
(no special changeset handling, including for inline-created media within a flush — the
existing temp-id/topological-sort mechanism already covers it), and file-byte cleanup
on delete/undo is deferred to the undo-log's retention-window pruning sweep (see "The
universal undo-log" above), not immediate. What was still open — the actual
upload/storage-backend/thumbnail pipeline — resolves as follows:

**Storage backend: the same interface-plus-swappable-default pattern already used
three times** (`RouteValueCaster`, `ErrorLogger`, `FieldValidator`) — a `FileStorage`
interface (`put`/`get`/`delete`/`urlFor`) with a `LocalFileStorage` default writing to a
configured directory, matching this project's demonstrated aim at portable/shared
hosting rather than assuming object-storage infrastructure exists. An S3-compatible
implementation is a natural later addition for anyone who needs it, without touching
`MediaAsset` or the changeset mechanism at all — nothing else needs to know which
backend is in play. Storing raw bytes directly in a database column was considered and
rejected explicitly, not just omitted: it bloats backups, most databases aren't built to
serve large blobs efficiently, and it fights against keeping real columns narrow and
queryable, already decided elsewhere in this document.

**`MediaAsset` is its own Entity**, with the metadata fields worth filtering admin lists
by — filename, mime type, file size — marked `#[Field(queryable: true)]`, direct reuse
of the existing mechanism, nothing new. It holds a *reference* (a storage key/path) to
where `FileStorage` put the bytes, never the bytes themselves.

**Thumbnails/renditions are Value Objects embedded in `MediaAsset`, not their own
Entities — this falls straight out of the Entity-vs-Value-Object test already decided,
not a fresh judgment call.** Running the four questions against a thumbnail: shared or
referenced independently of its parent image? No. Queried independently? No. Its own
lifecycle? No — it dies the moment its parent does. Is "the thumbnail" a persistent
thing people think of as the same thing over time, independent of the image it belongs
to? No. Every answer says Value Object — a rendition is a couple of embedded scalar
fields (storage key, width, height) per named size (`thumbnail`, `medium`), not a
separate table.

**Thumbnail generation is synchronous, at upload time — not a background queue**,
matching the same "build the narrow default, document the escalation" pattern used
throughout this document (the schema-migration trigger policy, the admin list/filter
views resolution). No job-queue mechanism exists anywhere in this design; introducing
one just for this would
be disproportionate new infrastructure. Resizing a handful of named sizes is fast enough
in practice for a synchronous default; async/queued generation is the explicit
escalation path if it ever becomes a real bottleneck, not something to build
preemptively.

**Validation needs no new mechanism** — file size limits and allowed mime types are
just ordinary `FieldValidator` implementations (`MaxFileSizeValidator`,
`AllowedMimeTypeValidator`), same interface as everything else. One thing worth being
firm about, since it's a real security requirement and not a nice-to-have: **validate
the actual file content against its claimed type, not just the `Content-Type` header or
file extension** — trusting either is a classic upload vulnerability (a PHP file renamed
to `.jpg`). Uploaded files also need to live somewhere the webserver won't execute as
code, regardless of what gets uploaded.

**One dependency worth naming, not solving here**: uploads arrive as `POST` requests,
and `Route`/`Router` don't check HTTP method at all yet — a real, already-flagged bug in
the implemented `Routing` code, separate from this document, but a genuine prerequisite
before upload handling could actually be wired up through the routing layer.

### Field-level permissions: a separate strategy interface, not another `FieldValidator`

Genuinely different from validation, not another flavor of it: `FieldValidator` only
ever sees the value being set, with no notion of *who* is setting it — permission is
about the actor, which needs its own interface rather than bolting an actor parameter
onto value validation:

```php
interface FieldPermission
{
    public function canRead(Actor $actor): bool;
    public function canWrite(Actor $actor): bool;
}
```

Fifth reuse of the same strategy-pattern shape in this document (`RouteValueCaster`,
`ErrorLogger`, `FieldValidator`, `FileStorage`, now this). A simple default like
`RolePermission` (checks the actor holds a given role) covers the common case;
`FieldDescriptor`'s factories gain an optional `permission: ?FieldPermission = null`
parameter, same shape as `validators` — null meaning "no restriction beyond whatever
content-type-level access already governs this entity," not something every field needs
to specify.

**Three enforcement points, mirroring the client/server split already established for
validation:**

1. **Read** — filters which fields even appear when hydrating an entity for display (an
   editor form, an API response). An actor without read permission for a field
   shouldn't see it exists, not just see it disabled.
2. **Write, server-side, mandatory** — hooks into the exact place validation already
   hooks into: checked against every field name in an incoming changeset's
   `EntityChange::$values`, *before* validation even runs — "is this actor even allowed
   to touch this field" is a more fundamental gate than "is the value well-formed."
   A violation rejects the whole changeset outright, same "fail before, not during"
   posture as everything else in the write path.
3. **Write, client-side** — pure UX convenience: don't render an editable input for a
   field the current actor lacks write permission for. Never authoritative, same
   relationship client-side validation already has to the server-side check.

**This generalizes to undo and revision-restore for free, with zero special-casing —
worth stating explicitly since it's a genuine consequence of the design, not an
assumption.** Ctrl+Z only ever operates on operationIds the current actor's own client
recorded — if they never had write permission for a field, they could never have
triggered an operation touching it, so it can't appear in their own stack to begin
with. The deliberate revision-history restore path can target any past operation,
including ones by other actors — but restoring is just "build and flush a changeset,"
and that changeset goes through the exact same write-permission check as any other
flush. No new logic needed for either path.

**Definition source follows the same two-source principle from the very first decision
in this document**: a native class expresses permission via an attribute
(`#[Field(permission: new RolePermission('admin'))]`), an editor-assembled field gets
the same `FieldPermission` value attached through whatever the editor's
schema-definition UI provides — same shared downstream shape, same
`RouteArguments::ofClass()`/`ofClosure()` precedent this design keeps returning to.

### Schema-level authorization: `SchemaPermission`, attached to `EditorExtensible`

Closes a gap `FieldPermission` never covered: it gates who can read/write a field's
*value* on an entity instance, but nothing gated who is allowed to trigger a shape
change itself — create a subclass of an `EditorExtensible` prototype, or add/drop a
column on one that already exists.

Sixth reuse of the same strategy-pattern shape in this document, just pointed at
prototypes instead of field values:

```php
interface SchemaPermission
{
    public function canRead(Actor $actor): bool;
    public function canWrite(Actor $actor): bool;
}
```

**Granular, per-prototype — same reasoning as `FieldPermission`'s per-field
granularity, deliberately chosen over one single global "can manage schema"
capability**: different `EditorExtensible` classes plausibly warrant different roles
(a store manager might reasonably extend `Product`, nobody but a superadmin should
extend anything touching `User`). `EditorExtensible` gains an optional
`permission: ?SchemaPermission` parameter, same shape as `Field`'s `permission`
parameter — `null` meaning "no restriction beyond ordinary admin access," not something
every extensible class needs to specify.

**Same three enforcement points as `FieldPermission`, applied to prototypes instead of
values:**

1. **Read** — filters which extension points an actor even sees in the schema-builder
   UI ("create a subclass of Product" shouldn't appear as an option at all for an actor
   without permission, not just be disabled).
2. **Write, server-side, mandatory** — checked the moment any schema-mutating action is
   attempted (create-subclass, add-column, drop-column), before the DBAL diff/DDL ever
   runs. A violation rejects outright — same "fail before, not during" posture as every
   other write-path check in this document.
3. **Write, client-side** — pure UX convenience, hide the "add column"/"create
   subclass" affordance for an actor who lacks permission. Never authoritative.

**An editor-created prototype inherits its parent's `SchemaPermission` unless it sets
its own.** Only native classes carry the `#[EditorExtensible]` attribute directly — an
editor-created subclass is implicitly further-extensible by construction (nothing about
"this was itself created via the editor" should require re-opting-in to being
extended again) — so it needs the same permission concept without the same attribute
mechanism; defaulting to the parent's value keeps admins from having to configure
permission on every single level of a chain, same motivation as inheritance elsewhere
in this document defaulting to "additive, not a burden on every level."

One check covers all of create-subclass, add-column, and drop-column uniformly, rather
than splitting into per-operation permissions — consistent with this document's general
preference for one strategy interface over a growing pile of narrower flags (see
"Validation" above), and justified the same way dropping a column doesn't need a
*stricter* check than adding one: the data-loss risk either could cause is already
covered by the undo-log, not something authorization needs to additionally weight.

### Entities are data, not routable content — no entity→URL mapping is needed

Closes the audit's "no stated connection from a stored entity to a servable URL" item
by rejecting the premise rather than answering it — this was never a gap, it's a
boundary this document should have stated explicitly from the start.

Entities (native or editor-created prototypes) are pure data: shape, storage, identity,
relationships. Nothing about them inherently makes them visible on the public web.
Three distinct rendering contexts, three distinct concerns, deliberately decoupled:

- **Public-facing pages are their own concept**, built using the `Routing` subsystem
  already established in this codebase (`Route`/`Router`/`RoutePattern`) — a route
  reads whatever entity data it needs (by id, slug, whatever the pattern captures) and
  renders it. A Product detail page at `/products/{slug}` is a `Route` that happens to
  read a Product entity; the Product itself has no "own" URL, the route decides to
  expose one. This isn't a missing piece to design here — `Routing` already fully
  solves "map a URL to behavior," and that behavior can trivially include "load this
  entity and render it" without `Storage` needing to know anything about URLs at all.
- **The admin editor is a third, separate rendering context** — displaying and editing
  entities directly, via the `FieldDescriptor`-driven generic editor UI this whole
  document designs, through its own internal URL scheme (e.g. `/admin/products/42/edit`).
  Also not the entity's "own" URL — an entity can be edited without ever being publicly
  viewable, and vice versa.
- **Most entities never get a public URL at all** — a Tag, a Category, a `MediaAsset`
  rendition, most editor-created subclass rows — only whichever ones a developer (or an
  editor-authored page-like content type, if that's ever built) explicitly wires a
  `Route` to.

### Uniqueness validation: a `FieldDescriptor` flag, not a new validator interface

Closes the "neither `FieldValidator` nor `PrototypeValidator` can express uniqueness"
gap. Every validator in this design is a pure function — it only ever sees the value(s)
being set, never anything about other rows — and uniqueness can't be expressed that way
at all, since it inherently depends on every *other* existing row. That's not a reason
to bolt a database-querying variant onto the validator interfaces, though: it belongs in
the same bucket as `queryable` — schema metadata, not a value-correctness strategy.

`FieldDescriptor` gains a `unique: bool` flag (`scalar()` and `reference()` only — the
two factories where it's meaningful). **`unique: true` implies `queryable: true`
regardless of what was passed for it** — a `UNIQUE` database constraint needs a real
column to attach to, the same hard requirement `EntityReference` already has on
`queryable` for a different reason. In practice this is rarely a real constraint on
anyone: the fields that need uniqueness (slugs, SKUs, usernames) are almost always
fields you'd want queryable anyway.

**Enforcement is two-layered, the same "backstop plus optional friendlier pre-check"
shape already used for the FK `RESTRICT` conflict case:**

- **Authoritative: a real `UNIQUE` database constraint**, generated via the same
  DBAL-based schema-migration mechanism already built for columns and indexes — no new
  infrastructure. A violation fails the flush transaction cleanly, rolled back, no
  corruption.
- **Friendly: a proactive `SELECT ... WHERE field = ? AND id != ?` check**, hooked into
  the exact same point `FieldValidator`/`PrototypeValidator` checks already run at —
  before the flush transaction opens — purely to produce a clear message ("this slug is
  already taken") instead of a raw constraint violation. The `id != ?` exclusion only
  applies when updating an existing row; a brand-new creation has nothing to exclude.

**Scope falls out of Class Table Inheritance's structure for free, no extra design
needed.** A `unique` field declared on a base prototype gets its constraint on the base
table — which every concrete instance has exactly one row in regardless of which
derived subclass it actually is — so uniqueness is automatically enforced across the
*whole* inheritance family. Declared on a field that only exists at a derived level, the
constraint is naturally scoped to just that level's own table. Same mechanism either
way, no special-casing.

**Optional, not required**: an async "check availability" endpoint (checking as an
admin types/blurs the field, before full submission) reusing the exact same query as the
authoritative pre-check — just an earlier-firing version of the same round trip, not a
new validation tier alongside the three already established for client-side checks.

### Concurrent-write protection on an ordinary publish

Closes the last remaining item about two admins publishing to the same entity around
the same time, with no undo involved — the classic "lost update" problem: Alice opens
Product #42 (title "Blue Shirt"), Bob opens it too and sees the same thing. Alice
changes the title to "Red Shirt" and saves. A few minutes later Bob, still looking at
his now-stale screen, saves a change of his own — and without protection, that save
silently overwrites Alice's "Red Shirt" with whatever Bob had, and nobody is ever told
Alice's edit just vanished.

**This turns out to need almost nothing new — it's the same conflict-detection check
already built for undo, just compared against a different reference point.** The undo
check is "has anything happened *after the operation being undone* touched the same
fields." This is "has anything happened *after the version I started editing from*
touched the same fields." Same underlying primitive, generalized to take an arbitrary
reference point instead of specifically "the thing being undone."

**The one genuinely new piece: each `EntityChange` needs to carry a "receipt" — which
operation it was based on.** `update()`/`delete()` gain an optional
`expectedOperationId`: when an entity gets loaded for editing, the response includes
its current last-known operation id (nothing exotic — this is the same idea as an HTTP
`ETag` handed back as `If-Match` on the next write, just using an operation id instead
of an opaque tag); the eventual publish sends that same id back. At flush time, if
anything logged *after* that id touched the same fields, the whole changeset is
rejected — same "fail before, not during" posture used everywhere else in the write
path. `expectedOperationId` is null for `create` (nothing prior to be based on) and can
reasonably be left null for bulk/programmatic writes that accept the small risk — only
the interactive editor path should always supply it.

**On conflict, the same two-tier response already established for undo conflicts
applies again, not a new decision:** reject by default with a clear message (same
posture as Ctrl+Z's auto-block), but allow an explicit "override and publish anyway"
action — reusing the confirm-with-warning treatment already given to the deliberate
revision-restore path, since choosing to overwrite someone else's concurrent change is
a legitimate, informed choice an editor can make (they coordinated out-of-band, or the
other change was clearly a mistake), not something that should be flatly impossible.

**Nothing extra needed for the other conflict kind either**: an entity being deleted
while something new depends on it is already covered for free by the existing FK
`RESTRICT` policy, regardless of which path (undo or an ordinary publish) triggered the
delete.

### Full-text and cross-content-type search

`queryable` already solves ordinary column-based filtering, but a long body/description
field deliberately living in the JSON blob (see "Storage" above) has no index to search
inside — and "search across Products, Pages, and Media at once" can't be scoped to any
one prototype's own table the way everything else in this document has been. Resolved
by reusing patterns already established elsewhere rather than inventing new ones:

- **A new `searchable: bool` flag on `FieldDescriptor`, independent of `queryable`.** A
  field can be either, both, or neither — `title` might be both queryable and
  searchable; a long `body` field is typically searchable only, since nothing needs to
  sort or filter by it as a column.
- **One flat, denormalized `search_index` table** (`entity_id`, `prototype`,
  concatenated searchable text) — not per-prototype, not tied to any Class Table
  Inheritance chain. This is what actually answers the cross-content-type half of the
  problem: it's cross-content-type by construction, being one shared table regardless
  of how many different prototypes feed into it.
- **A `SearchIndex` interface, swappable default implementation** — same shape as every
  other extension point in this document (`FileStorage`, `ErrorLogger`, `FieldValidator`,
  `FieldPermission`, `SchemaPermission`). The built-in default is the `search_index`
  table plus ordinary SQL matching, working out of the box; the door stays open to swap
  in a real engine (Postgres full-text, Meilisearch, Elasticsearch) later without
  touching anything else — the documented-escalation treatment this item was always
  going to get, not built preemptively.
- **Updates hook into the write path for free.** Recomputing an entity's searchable text
  and calling `index()` happens as part of a successful flush, the same hook point every
  other write-path consequence in this document already uses. Undo, revision-restore,
  and draft-publish all keep the search index correct with zero special-casing, since
  each of them is already just "flush a changeset" under the hood.
- **An Owned entity's text folds into its owner's index entry, not its own** —
  consistent with "Ownership: Owned vs. Shared" above: an Owned repeater item has no
  independent identity to search *for* on its own, but its content should still be
  findable when searching its owner, the same way its changes already fold into the
  owner's own logged diff rather than getting an independent undo entry.

## Open questions

None remaining from either the *original* list or the audit-surfaced list — both passes
were fully resolved into "Decided so far" above. Left as a section header rather than
deleted entirely, since new gaps have surfaced here twice already and probably will
again.
