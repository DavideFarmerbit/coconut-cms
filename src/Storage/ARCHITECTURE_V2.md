# Storage / Editor Architecture — v2 (rewrite)

Status: **a standalone technical description of the system's current, decided shape —
nothing implemented yet.** Each concept is described in its own terms; this document is
never a changelog of how a decision was reached, what it replaced, or what came before it.

Not a frozen spec. Revisit as implementation surfaces constraints this discussion didn't
anticipate.

## Namespaces and migration path

The old `Storage` namespace covered too many distinct concepts at once (entity
persistence, schema/migrations, editor authoring, write-path/undo, permissions) — part of
what made Phase 8 feel entangled. The rewrite splits it into four backend namespaces
nested under one shared parent, **`Persistence\`**, kept purely for filesystem/`src\`-root
tidiness — nesting doesn't reintroduce the old entanglement, since each of the four stays
just as cleanly separated a namespace as it would be at the `src\` root, only its prefix
changes:

- **`Persistence\Entity\`** — the persistence/runtime core: entities table, identity map,
  repositories, row mapping, query builder. Also where every write, content-triggered or
  schema-triggered, is safely organized and executed: `WriteOperation` (a create/update/
  delete instruction, possibly naming a `TempId` placeholder instead of a real id),
  `TempId` itself, `WriteExecutor` (expands, sorts, and executes a batch of
  `WriteOperation`s against `Repository`, inside one transaction — see "Executing
  multi-entity writes safely"), and `WriteEffect` (the per-operation outcome `WriteExecutor`
  returns: the resolved id, the values actually applied). None of these four have any
  concept of logging.
- **`Persistence\Schema\`** — the shape/mutation half: `FieldDescriptor`, prototype
  registry, `SchemaBuilder`/`SchemaSynchronizer`/`SchemaEditor`, migrations,
  rename/retype/reparent, attributes (`#[Entity]`, `#[DefaultInstance]`, the five field-kind
  markers, ...). `EntityRegistrar` lives here too — despite its name, its job
  is registering a native class's *schema*, not runtime entity state. Every schema
  mutation builds its own `Persistence\Entity\WriteOperation`s and hands them straight to
  `Persistence\Entity\WriteExecutor` — this namespace never imports `Persistence\Changeset\`
  at all, which is what makes "a schema-triggered write is never logged" structural rather
  than a convention someone could forget.
- **`Persistence\Changeset\`** — the content write path specifically, promoted to its own
  namespace rather than a subfolder of `Persistence\Entity\`, since undo is really just a
  different thing done with the same batch rather than a separate subsystem: `Changeset`
  (a named collection of `Persistence\Entity\WriteOperation`s for one content-editing
  flush) and `ChangesetFlusher` (hands that collection to `Persistence\Entity\WriteExecutor`,
  then builds `Revision`/`EntityChangeRecord` rows from the `WriteEffect`s it gets back) at
  the top, **`Persistence\Changeset\Undo\`** (`Revision`, `EntityChangeRecord`, conflict
  detection) nested underneath. Draft (`DraftStore`/
  `DraftPreview`) lives in `Editor\` instead, not here — it has no meaning outside an
  authoring UI, unlike undo, which benefits any flush regardless of who's writing; see
  "Content undo, draft, and revision history" below.
- **`Persistence\Permission\`** — shared by all of the above rather than split across
  them: `Actor`, `SchemaPermission`, `FieldPermission`, `HistoryPermission`,
  `RolePermission`, and any other auth-level class.

**`Editor\`** — the admin-facing authoring surface, consuming the other four rather than
merging into any of them — stays its own top-level namespace, not nested under
`Persistence\`: it's the UI-facing surface built on top of this system, not part of the
persistence system itself, a separate later track per `ROADMAP_V2.md`.

Same pattern `Routing\` and `Core\Error\` already use in this codebase: one class per
file, subdirectories mirror sub-namespaces, `tests/` mirrors `src/` 1:1 folder-for-folder,
plus a `Fixtures/` folder for test-only support classes.

**The old `Storage\` namespace is left untouched during the rewrite, not migrated,
extended, or deleted.** It keeps working exactly as it does today and stays available as
a running reference to consult while the new `Persistence\`/`Editor\` code is built —
deliberately not reused or built on top of, to keep the rewrite a clean-room effort rather
than dragging the old entanglement forward. `Storage\` gets retired once it's no longer
needed for that reference purpose — no fixed point in the roadmap for that, revisit once
`Persistence\` actually covers everything `Storage\` did.

## The goal

Unchanged from the original design: an admin editor in the spirit of Unreal's
UObject/Details-panel system — classes displayed and edited through reflection, PHP
attributes customizing how each property renders. Two supporting pieces, also unchanged
in spirit: content types can be assembled entirely in the editor with no PHP class behind
them, and editor operations are undo-able client-side. This document focuses on the
storage/entity layer underneath both.

## Shape comes from a neutral descriptor

Two sources produce the same `FieldDescriptor[]`: reflecting a hand-crafted PHP class's
attributes, or reading a stored schema an admin authored through the editor. One shared
downstream pipeline (persistence, validation, migration) never needs to know which source
a shape came from.

`FieldDescriptor`: private constructor, named static factories (`scalar()`, `valueObject()`,
`embed()`, `reference()`, `collection()`) — makes invalid combinations (a scalar kind with
a referenced shape set, a collection with no item kind) unrepresentable rather than just
unlikely. Same pattern already used for `Route::structured()`/`Route::simple()` in this
codebase. A sixth kind, `NoType` (see "Migrations and schema mutation"), exists too, but has
no public factory among these five — it's never developer-declared, only produced by the
framework's own capture/retype machinery, so the five above remain the complete
developer-facing surface a native class or `SchemaEditor` field is ever declared through.

**For a native class, each of the five factories above has exactly one matching PHP
attribute** (`#[Scalar]`, `#[ValueObject]`, `#[Embed]`, `#[Reference]`, `#[Collection]`) that
`EntityRegistrar` reflects on to build the matching `FieldDescriptor` — one attribute per
field, never two stacked for one declaration. `FieldPermission`, the `FieldValidator` list,
`Unique` (a class-level group, never per-field — see "Uniqueness"), and `PrototypeValidator`
each stay their own independent attribute rather than folding into a kind marker's own
parameters: unlike kind, which is mutually exclusive by construction, these are orthogonal
and freely combine regardless of kind, so bundling them in would buy nothing while coupling
unrelated concerns to one attribute class. At the class level,
`#[Entity(table: ..., editorExtensible: ...)]` is the one attribute carrying both the
table-name override and the editor-extensibility flag — both singular,
always-at-most-one-per-class facts, unlike `Unique`'s or `PrototypeValidator`'s own
repeatable declarations, which stay separate for the same reason those stay separate from
each other. `#[DefaultInstance]` stays on the static factory method
itself, never referenced by name from `#[Entity(...)]` — attribute arguments must be
compile-time constant expressions, and a callable reference to a method isn't one in any form
that would actually resolve the method PHP-side (PHP's own first-class callable syntax
produces a `Closure`, which attributes can't hold either).

**Which of the two sources an identifier resolves through is decided by one test, with no
stored flag or lookup of its own**: a native identifier is a real PHP class-string, so
`class_exists($identifier)` tells the two kinds apart for free. `PrototypeRegistry` is
stateless either way — every method resolves directly, reading the database for an
editor-created identifier, reading the already-loaded class via reflection for a native
one — so `fieldsOf()`/`chainOf()`/`instantiate()` can never go stale: there is no snapshot
of any of them sitting anywhere to go stale in the first place.

A native identifier's table name is a different case, and correctly so: decided once, when
registration runs, and frozen for the life of the process, since native schema can't change
without a redeploy and nothing needs to ever revisit it. An editor-created identifier's
table name is never frozen anywhere; it resolves live, off the same stored row
`PrototypeRegistry` already consults for that identifier's parent, every call, the same way
its fields already do. One table-name lookup, branching on the same `class_exists()` test
used everywhere else an identifier's kind matters — not a second, independently-built map
that can end up knowing about a different set of identifiers than the first.

## Global entity identity: one shared `entities` table

Every entity, native or editor-created, gets a row in one shared `entities` table sitting
above every prototype's own Class Table Inheritance chain — one root, period, not one per
prototype family:

- `id` — uuid, the identity every chain's own top level is now an FK back to.
  **Re-confirmed** (not just carried over from the original baseline): uuid over an
  autoincrementing PK, deliberately — avoids a single global sequence shared by every
  entity in the system regardless of prototype, and keeps id generation possible without a
  round-trip to the database first.
- `owner` — nullable, self-referencing FK to `entities.id`, `ON DELETE RESTRICT`. Not
  `CASCADE` — see "References and collections" and "Content write path": deletion of an
  Owned subtree is app-mediated, and this constraint is what makes skipping that a hard
  failure instead of a silent one.
- `owner_field` — nullable, disambiguates which Owned relationship put this row here when
  the same shape is owned by more than one relationship.
- `position` — nullable, `-1` for a non-collection Owned relationship, an ordinal
  otherwise (orders an Owned collection).
- `concrete_identifier` — the row's actual type, native class or editor-created
  prototype, resolved through the prototype registry to find its real table(s).

`entities` is chain position 0 for every identifier, standalone or already part of an
inheritance chain — there's no more "chain of one, no join" case. Resolving a row's
concrete type is one indexed lookup (`SELECT concrete_identifier FROM entities WHERE id =
?`), not a per-chain discriminator.

**Class Table Inheritance, arbitrary depth**, native and editor-created levels freely
mixed. No cap needed: growing an existing level (add a nullable column) is already
cheaper than adding a new level, so real depth stays shallow in practice; each level's
join is a cheap single-row parent-PK-to-derived-PK lookup regardless, nothing like an EAV
self-join; and where cost could matter (list views across many rows) is a Query-builder
concern, not a depth-specific one — see "Admin list/filter views" below.

**Cost accepted deliberately**: every read, including a standalone native class with no
declared parent, now joins to `entities` once. Traded for removing the need for any
per-relationship dedicated table (see "References and collections" below) and any
discriminator-per-chain-root mechanism.

## No blobs. Every field is a real column or a real table.

The single biggest change from the old design: there is no JSON-blob storage tier at all,
anywhere. Migrating data inside an opaque blob is the exact kind of problem this rewrite
exists to avoid. Consequences:

- `queryable` is retired outright, not carried forward in any reduced form. Its only job
  was the storage-tier switch — every field being filterable/sortable via SQL depended on
  it being `true`. With no second tier to switch into, every field is already a real,
  filterable/sortable column regardless, so there's no correctness gap left for a flag to
  cover. What would be left is a pure indexing decision (does this column get a database
  index) — a performance concern, not a capability one, with no mechanism to even act on
  it yet (`SchemaEditor`'s closed set of safe mutations has no index-toggling operation).
  Same posture as the Embed column-count risk below: not designed preemptively, revisit if
  and when an actual schema demonstrates the need.
- A **collection of scalars, value-object-primitives, or `#[Embed]` items** (non-entity
  items) can no longer live as a JSON array. It gets its own dedicated child table:
  `(ownerId, position, value column(s))`, `CASCADE`-deleted with the owner. A Shared
  collection's own pivot table ("References and collections", below) is this exact same
  `(position, value column(s))` shape, just with `value column(s)` being one target-FK
  column instead — not a second, independently-arrived-at mechanism. This is a
  different, simpler mechanism than an Owned-entity collection below — the items have no
  identity of their own, nothing to look up in `entities` at all. For a scalar or
  value-object item, `value column(s)` is exactly one column; for an Embed item, it's the
  embedded shape's fields flattened into that child table's own columns, same recursive
  flattening and restrictions (no `Reference`/`Collection` at any depth, cycle detection at
  registration) as an Embed field on an ordinary entity row — the only difference is which
  table the flattened columns land on, the owner's own row or this dedicated child table.

**Tradeoff accepted knowingly**: unlimited-depth Embed flattening plus no blob tier means a
deeply-nested shape can produce a wide table — real limits exist (Postgres ~1600
columns/table, MySQL row-size ceilings) that the old design's blob tier partly existed to
avoid. Almost certainly fine for realistic CMS content shapes; revisit only if an actual
schema approaches these limits, not preemptively.

## Entity vs. Value Object vs. Embed

Same DDD test as before, decided per field declaration, never baked into a shape itself:
does it need to be shared/referenced from more than one place, found/queried
independently, have its own lifecycle, or does the business track "the same X" over time?
Yes to any → Entity. No to all → not an Entity — but "not an Entity" now splits cleanly
into two different mechanisms instead of one:

- **Value Object = a custom primitive type**, full stop. Exactly one column, a real
  custom type class doing PHP-value ↔ DB-value conversion (the standard approach — the
  same shape as a Doctrine custom `Type`). Never has its own identity, never doubles as
  an Entity anywhere. `FieldDescriptor::valueObject(name, customTypeClass, ...)`.
- **Embed = reusing an Entity-capable shape at one specific field site**, flattening its
  fields into the owner's own row as real columns (`address.city` → `address_city`),
  recursively through nested embeds and value-object members. The *same* registered
  shape can independently be a full standalone `Entity` (own table, referenced normally)
  somewhere else at the same time — the choice is per field-declaration, never a property
  of the shape. The column prefix is the *field's* own name, not the shape's — so the same
  shape embedded twice under different field names on one entity (`billingAddress`,
  `shippingAddress`) disambiguates for free (`billingAddress_city`,
  `shippingAddress_city`), with no separate mechanism needed beyond the ordinary
  flattening rule already stated here.

**An Embed target's field tree is restricted to scalar + value-object + nested-embed
fields only — no `Reference`, no `Collection`, at any depth.** This mirrors Doctrine's
own embeddables exactly: Doctrine's `#[Embeddable]` classes cannot declare
`#[ManyToOne]`/`#[OneToMany]`/`#[ManyToMany]`/`#[OneToOne]` at all — a shape needing
either of those isn't an embeddable, it's an entity. Adopted deliberately rather than
solving a harder problem (an embedded shape has no identity, so a `Collection` field
inside one has no natural owner to attach a child table to). A shape that needs a
reference or a collection must be used as a real `Entity`, never as an `#[Embed]` target.
**Rejected eagerly, at registration time** — same posture as every other structural
violation in this design (Embed-of-Embed cycles, defaulted-instance completeness): a
`Reference` or `Collection` field found anywhere in an Embed target's field tree fails
registration immediately, never a runtime or migration-time surprise.

**Embed-of-Embed cycle detection is required at registration time** (A embeds B embeds A
would otherwise recurse forever generating columns). Same posture as the existing
table-name-collision and defaulted-instance-completeness checks below — fail loudly at
registration, not at migration time.

## References and collections

- **Shared, singular** (`SharedReference`): a real FK column on the referencing side,
  always nullable. A `Reference` is pointer semantics, never value semantics — it can never
  be required at the schema level, so the column is never anything but nullable and the
  delete policy is always `SET NULL`, never `RESTRICT` (see "FK `ON DELETE` policy").
- **Shared, collection**: the same `(position, value column(s))` dedicated-table shape "No
  blobs" already established for non-entity collections, not a separate mechanism that
  happens to converge later — a real pivot/join table, many-to-many, with its own `position`
  column, ordered from day one, same as Owned and non-entity collections, where
  `value column(s)` is exactly one target-FK column instead of a scalar/value-object column
  or an `#[Embed]` shape's own flattened columns. Every rule that shape already carries over
  unchanged: the table's own name is derived the same way, drops and renames the same way,
  and converts its value column(s) to `NoType` the same way. The pivot
  carries `position` and exactly one target-FK column, nothing else, ever: a relationship
  that needs richer per-row metadata (a note, a date, anything beyond order) isn't a bare
  many-to-many anymore and should be modeled as a real join-entity (an Owned collection of
  small entities, each holding a Shared singular reference to the actual target) instead of
  growing the pivot's own column count. That target-FK column's own type still changes like
  any other field's when the collection's item kind is retyped (`NoType` included, see
  "Migrations and schema mutation") — a swap of that one column, never an addition
  alongside it, so retyping never actually grows what the pivot carries. The target-FK
  column is always nullable, same as the singular case, and a pivot row surviving with a
  null FK (see "FK `ON DELETE` policy") is exactly what makes `COUNT(*)` on the pivot table
  an always-accurate slot count, filled or not.
- **Owned, singular** (`OwningReference`): no column on the owner's own table. Found via
  `SELECT * FROM entities WHERE owner = ? AND owner_field = ? AND position = -1`, hydrated
  through the owned entity's own repository. Deletion is app-mediated via `entities.owner`
  (see "FK `ON DELETE` policy" below), not a raw database cascade. Unlike `Reference`, an
  `OwningReference` *can* be required (Owned data is locally manufacturable, so a
  defaulted instance is always available to satisfy it, see "Defaulted instances").
- **Owned, collection**: same query without the `position = -1` filter, ordered by
  `position`. An owned collection item lives in its own ordinary table via its own CTI
  chain — fully polymorphic, no per-relationship child table needed, no dedicated
  Owned-child-table mechanism at all. This is the payoff of the shared `entities` table:
  the same reusable shape can be Owned by any number of unrelated relationships through
  the exact same `owner`/`owner_field` columns, no dedicated table per relationship. Every
  Owned-collection field also gets a `<field>_count` column on the *owner's* own declaring
  table, uniformly, whether its item kind is required or optional — no special-casing. An
  absent *optional* owned item leaves no row at all, the same rule as a singular optional
  `OwningReference` creating no row when empty, just applied per slot (the Unreal
  `TArray<Instanced> UObject*` pattern: a fixed number of slots, each optionally holding an
  instanced sub-object). That means `COUNT(*) FROM entities WHERE owner = ? AND owner_field
  = ?` undercounts the true slot count the moment any slot is empty — position values alone
  stop uniquely encoding the array's length once gaps are legitimate. The stored count is
  what keeps "remove this slot" (count shrinks, later positions shift down) and "clear this
  slot's content" (count unchanged, the slot survives, just empty) distinguishable
  operations. Shared and non-entity collections need no equivalent: each already has a real
  dedicated table row per slot regardless of whether that slot's own value is null, so a
  plain row count is already accurate.
- **Non-entity collection** (scalar, value-object, or `#[Embed]` items): the dedicated
  child table described under "No blobs" above — not the `entities` mechanism, since the
  items aren't entities.

**FK `ON DELETE` policy**: `SET NULL` always for a Shared reference's target-FK, singular
or collection item, never `RESTRICT`. A `Reference` is pointer semantics, not value
semantics — it cannot be required at the schema level, full stop, so there's no "required"
case for `RESTRICT` to ever protect; the app-level topological sort in the write path
already handles safe ordering, and a Shared target simply going missing from a field is an
ordinary, always-legal outcome. `RequiredValidator` may still gate a Shared field at write
time as ordinary content validation ("you can't save without picking one") — a decision
made at the point of writing, never a schema-level guarantee, and it never changes the
column's own nullability or the FK's delete policy, both of which stay exactly as described
here regardless of whether `RequiredValidator` is attached. (`NoType` conversion is a
separate, rarer trigger — the whole target *prototype* disappearing, not an ordinary row
delete — and is unaffected by this.)

`RESTRICT` for `entities.owner`, uniformly across every Owned relationship — not `CASCADE`,
and not the same mechanism as the Shared case above: this is a self-referencing structural
column, not a reference with its own required/optional distinction, and an `OwningReference`
*can* be required (unlike `Reference`). Owned deletion is conceptually cascading (an Owned
child dies with its owner) but mechanically app-mediated: deleting an entity that still has
Owned descendants is rejected unless every descendant was already deleted first, each one
through the ordinary write path where it gets its own `EntityChangeRecord` (see "Content
write path"). The alternative, `CASCADE`, would let the database quietly clean up any
descendant an app-level bug missed — silently skipping its log entry along with it,
undermining "every touched entity gets a record" below; `RESTRICT` turns that same bug into
a hard failure instead.

A Shared collection's pivot table splits rather than following one rule for both FKs. A
pivot row carries no independently-logged content of its own (not an entity, gets no
`EntityChangeRecord`), which is what makes `CASCADE` safe on the owner side: the owner-side
FK is `CASCADE`, the same semantic every other table-based collection already has (a
non-entity collection's own dedicated table is "`CASCADE`-deleted with the owner," "No
blobs"). The target-side FK is `SET NULL` instead, same policy and same reason as the
singular case above: cascading here would delete the pivot row itself, losing the slot and
silently shrinking the collection's true count — exactly what a surviving `SET NULL`'d row
(position intact, FK empty) exists to prevent.

## Uniqueness

A shape declares that some of its own fields, together, must be unique — never a flag
living on one `FieldDescriptor`, always a named group of one or more field names, the same
mechanism whether the group has one member or several. Lives alongside `FieldValidator`/
`PrototypeValidator` as one more recognized kind of validation declaration, not a separate
attribute system: deliberately, so a reader checking what a shape requires never has two
independent places to look. A shape may carry more than one such group at once, each
independent of the others (a single-field group and a three-field group coexist freely,
each its own constraint).

**Enforcement is always a real database constraint, never an application-level check run
before the write.** A proactive "look for a duplicate, then write" pass is a race two
concurrent writers can both pass before either commits; a real constraint can't be raced.
Because a declared group is a recognized kind, not opaque validator logic, the framework
treats it as schema work: at registration/schema-sync time it adds a real composite (or
single-column) `UNIQUE` index to whatever table the named fields physically land on; at
write time it relies on that index for atomic enforcement and turns any violation into a
friendly, named refusal rather than a raw constraint error. This is always achievable for a
group specifically because, by the exclusion below, it only ever names fields of the one
shape declaring it.

**Where the constraint physically lands, by how the declaring shape is used:**

- **Standalone, Shared-referenced, or Owned** (singular or collection): every such row of a
  concrete type lives in the one shared physical table its whole chain is rooted through, so
  the constraint is global — every standalone row and every Owned row under every
  relationship, compared together. Deliberate: there is no narrower storage to scope it to,
  since the shared `entities` table exists specifically to avoid a dedicated table per
  relationship.
- **`#[Embed]` target**: each embedding site gets its own, independently-flattened copy of
  the columns (`House.homeAddress_street` and `Order.deliveryAddress_street` are unrelated
  columns, possibly on unrelated tables, per "Entity vs. Value Object vs. Embed"). A declared
  group materializes as an *independent* composite index *at each site*, never spanning
  sites — propagated to every embedding table the same way a rename/retype already fans out,
  but each site's resulting constraint stands alone.
- **Collection cardinality** (Owned collection, Shared pivot, or a non-entity/`#[Embed]`
  collection's own dedicated child table): every owner's items share one physical table, so
  the table's own scoping column (`owner`/`owner_field`, or the dedicated table's `ownerId`)
  is folded into the materialized index automatically, alongside whatever fields were named —
  otherwise the constraint would wrongly span every different owner's collection system-wide
  instead of staying scoped to one owner's own items.

**Two structural exclusions, both rejected at registration time — same posture as every
other structural violation in this design** (Embed-of-Embed cycles, a `Reference`/
`Collection` field inside an `#[Embed]` target):

- **A group can never name a field reached through a `Reference`.** The compared columns
  would live on the *referenced* row's own table, not on any table the constraint could be
  declared against — and a `Reference` is pointer semantics to something with its own
  independent life (see "FK `ON DELETE` policy"), so constraining it from the referrer's side
  is the wrong model regardless. A rule that genuinely needs to compare what a collection
  *references* is not left unsolved by this exclusion: it's an ordinary `PrototypeValidator`
  rule instead, and needs nothing new to be safe — candidate-state assembly already hydrates
  reference values into real objects before validation runs, so the rule reads the referenced
  objects' own fields directly, no new database access needed; and the race a database
  constraint would otherwise prevent is already closed by the existing concurrent-write
  protection, since any write touching a collection's own membership already requires the
  owning entity's current `expectedOperationId` (see "Content write path"), serializing
  concurrent edits to the same collection for an unrelated reason.
- **A group can never span fields declared at different levels of a chain.** A parent's own
  fields and a descendant's own newly-declared fields live on two different physical tables
  joined through `entities` — the same structural reason a `Reference`-reached field can't be
  named, one `UNIQUE` index is always exactly one table. A descendant is free to declare its
  own group purely over its own fields; the two never combine into one constraint.

**Adding or removing membership, with no kind change underneath, never touches existing
data** — same posture as every other non-retype field operation (see "Migrations and schema
mutation"): it runs a friendly pre-check over the current live values, refusing and naming
the collision if any duplicate already exists, then issues the real `ALTER ... ADD UNIQUE`.
Removing membership is always safe, no check needed. A kind change bundled together with a
membership change goes through the full retype mechanism instead, described below.

**Cleanup on rename or removal is mostly already free, with one new case.** Dropping the
whole table, or the sole field of a single-field group, drops the index with it. Renaming a
field or the declaring class needs the index's own column/name reference renamed as part of
the same rename-propagation already described below — one more artifact that fan-out
reaches, not a new mechanism. Dropping one field out of a *multi*-field group while the rest
remain is refused outright unless the group itself is explicitly narrowed or removed in the
same operation — silently shrinking a guarantee as a side effect of an unrelated field
removal is exactly the kind of silent consequence this design refuses everywhere else.

## Defaulted instances: backfilling a new field or a new parent-level row

Every native class, every shape ever used as an `#[Embed]` target, and every shape ever
used as an `OwningReference`/Owned-`Collection` target must be able to produce a defaulted
instance: a real zero-argument constructor, or a static factory carrying
`#[DefaultInstance]` (needed because reflection has no other way to know which static
method is *the* one). **A plain `SharedReference` target is deliberately excluded** — a
`Reference` is pointer semantics, never value semantics (see "FK `ON DELETE` policy"), so
there's no sense in which a "default target" could ever be manufactured; an unfillable
Shared reference simply stays null until a human links something, same as any other
optional reference. Editor-created fields carry their own explicit default instead, set
through the field-authoring UI — this already composes into "the whole prototype has a
default instance" with no separate mechanism needed, since every one of its own fields is
already required to resolve one.

**Resolution order**: field's own explicit default (editor-created only) → the field's
type's own defaulted instance, resolved recursively → throw. One mechanism, several call
sites: an ordinary new column's backfill, a newly-required parent-level row (reparenting),
an `#[Embed]`-propagated column (a shape gaining a field backfills every table that embeds
it), and a retype with no converter supplied (see "Migrations and schema mutation") — a
converter is never mandatory for a retype, only useful when the new value should be
*derived* from the old one rather than simply reset to default.

**A backfill only ever touches the one field being added — never the rest of an already-populated
row.** Resolving a default means constructing the declaring class's own defaulted instance
and reading off whatever value it assigned to *that* field alone; every other already-existing
field on the row is left exactly as it is, never reset or reconstructed from that same
instance. This needs no special case for a `Collection`-cardinality field either: whatever
value the resolution step produces for it — an array from an explicit multi-item default, or
whatever the constructor itself assigned (`[1, 2, 3]` for a scalar collection, three built
`Address` instances for an `#[Embed]` collection, three built entities for an Owned
`Collection`) — gets persisted through that field's own already-established per-kind
physical mapping, the same mapping any ordinary entity creation already uses for that kind.
Backfill is never a separate "how many items" algorithm; it's "resolve one value for one
field, then persist it exactly like a create would."

**A field participating in a `Unique` group (see "Uniqueness") is the one case the plain
defaulted-instance path can never satisfy once more than one existing row needs a value** — a
single constructed default, reused identically for every row, is guaranteed to collide the
moment a second row needs one. Backfilling such a field gains the same optional converter
retype already has (see "Migrations and schema mutation" for exactly how it's invoked — once
per existing row needing a value, never once per item inside a single row's own collection),
and the same row-level pairwise-distinctness check runs on its output before committing
anything; not supplied, the backfill is refused outright rather than falling back to the
reused-default path, since that path can never be distinct past the first row. An optional,
unique field needs none of this regardless of row count: backfilling to `NULL` is always
safe, since standard `UNIQUE` semantics never treat two `NULL`s as colliding.

**Fail as early as possible.** For native classes: `EntityRegistrar::register()` walks
every registered class's full field tree, recursively through every `#[Embed]`/
`OwningReference`/Owned-`Collection` target (never a plain `SharedReference` target, which
needs none), and requires a defaulted instance for each distinct class found, before any
schema work starts. For editor-created fields: rejected at field-save time if the
referenced type has neither an explicit default nor a defaulted instance — the same
`SharedReference` exclusion applies, never rejected for lacking one.

## Migrations and schema mutation

**Native classes**: developer-triggered, reviewed migration files, Doctrine DBAL
`Schema`/`Comparator` for diffing and DDL generation. A human reviews generated DDL before
it touches production.

**Editor-created subclasses**: safe runtime DDL only, scoped to that subclass's own schema,
never the parent's — add a field, drop a field, rename a field, retype a field (see below).
Nothing else, ever, at the field level; this is enforced by `SchemaEditor` exposing no other
field-level mutation method, not just by policy. **Adding a field**'s physical shape is a
pure function of the field's own kind, not a fifth operation per kind: a column on the
subclass's own table for scalar/value-object/singular-`Reference`/`Embed`-flattening, a
dedicated pivot/child table for a `Collection`, no physical change at all beyond the registry
record for an `OwningReference` or Owned `Collection` (see "References and collections", "No
blobs"). This has to be true: `SchemaEditor` is the live, unreviewed counterpart to a native
class's own attribute-declared fields (see "Shape comes from a neutral descriptor"), and it
has to support every `FieldDescriptor` kind a PHP class can declare, not a scalar-only
subset, or admin-authored content types would permanently fall short of "content types can
be assembled entirely in the editor" ("The goal").

**Removing a field** runs the same per-kind mapping as adding, just undone: drop the
column for scalar/value-object/singular-`Reference`/`Embed`-flattening; drop the dedicated
pivot/child table outright for a `Collection` ("A Shared-collection or non-entity-collection
field's own dedicated table..." further below). An `OwningReference` or Owned `Collection`
needs one more step beyond that mapping: whatever owned content already exists at that
relationship has to be cleaned up too, since nothing else ever will — spelled out next.

**Removing an `OwningReference` or Owned `Collection` field cascade-deletes every existing
owned entity at that relationship.** Scoped to every entity that currently declares or
inherits the field, not just one row. Each deletion runs through
`Persistence\Entity\WriteExecutor`, which already expands a delete in two directions
("Executing multi-entity writes safely"): downward into anything each owned entity in
turn owns, and sideways into any outside Shared reference pointing at one of them. This
reuses the same machinery prototype
deletion and reparenting's level-removal already reuse, not a third, separate bulk-delete
mechanism.
Skipping it would orphan every owned row at that relationship permanently — findable only
by `owner`/`owner_field`, with no declaring field left to resolve it through. Applies the
same way whether the field disappears via `dropField()` or a native class dropping the
property through the reviewed-migration tool. The `Collection` case also drops the field's
own `<field>_count` column, same reasoning as its `NoType`-conversion drop ("`NoType`"); a
singular `OwningReference` has no column to drop, as always.

**Rename — no attributes, ever, for either kind.** Passed explicitly at the point the
change is triggered instead of inferred by diffing two snapshots or tracked with
in-code markers: for a native class, the migration-generation invocation takes an explicit
mapping ("since last run: `oldField` → `newField`") as an argument. For editor-created,
the signal is inherent — `SchemaEditor` performs one explicit admin-triggered rename
action, never inferred from a diff. Prototype-level rename works the same way and fixes up
every stored reference to the old identifier: `prototypes.parent`, `entities.concrete_identifier`,
every other prototype's `reference()`/`embed()`/`collection()` pointing at it, and any
`owner_field` values. **Renaming a field on a shape used as an `#[Embed]` target
propagates to every table that embeds it** — same reasoning as new-field backfill below:
every embedding table has its own flattened copy of that column (`address.city` →
`address_city`), so the rename must run against each one, not just the shape's own
declaration.

**Renaming a field that declares an `OwningReference` or an Owned `Collection` updates
`entities.owner_field` for every existing row at that relationship** — the same rename
trigger as above, just an `UPDATE entities SET owner_field = ? WHERE owner = ? AND
owner_field = ?` scoped to that relationship's existing rows, run alongside the rename
instead of a column `ALTER` (an Owned relationship has no column of its own to `ALTER`,
per "References and collections"). Without this, every already-owned row silently stops
resolving under the field's new name the moment the rename lands — `entities.owner` still
gates cascade-delete correctly, but the relationship itself reads back empty, no error —
the exact kind of silent failure the explicit-mapping rename mechanism exists to prevent
everywhere else. Not needed for a `Reference` or `Embed` field rename, which are already
covered by the column/table renames above; this is specific to the no-column case an
Owned relationship is. **An Owned `Collection` field's own `<field>_count` column does
need an ordinary rename alongside this** — unlike the relationship itself, the count column
is a real column on the owner's own declaring table, renamed the same way any other column
would be, run as part of the same rename operation.

**Every table name is checked for collisions at registration time, not discovered later as
a migration failure**: an entity's own table name (derived from the class's short name, or
pinned via `#[Entity(table: ...)]`) and every field's own dedicated table (the pivot table,
or the "No blobs" child table, both named the same derived-or-pinned way) all land in one
shared namespace; two unrelated identifiers landing on the same name fail registration
immediately with an actionable error. Same fail-loudly-at-registration posture as the
`#[Embed]`-cycle-detection and defaulted-instance-completeness checks above.

**A Shared-collection or non-entity-collection field's own dedicated table (the pivot
table, or the "No blobs" child table) is a derived name too, not a pinned one** — same as
an entity's own table name falls back to a derivation from the class's short name absent
an explicit `#[Entity(table: ...)]`. Renaming the field renames this dedicated table too, through the
same explicit mapping above, never inferred by diffing; renaming the declaring
prototype, when that prototype's own table name is itself derived rather than
`#[Entity(table: ...)]`-pinned, automatically fans out to every dedicated table its own fields derive a
name from — one explicit trigger, mechanically computed consequences, the same pattern as
the Embed-target column propagation just above, not something the developer separately
re-declares per affected table. Removing the field drops the table outright: for native,
whatever DDL `Comparator` produces once the field's declaration disappears from the class,
reviewed like any other native DDL; for editor-created, a `SchemaEditor` operation with the
same safe-DDL-only scoping `dropField()` already has, just at table granularity.

**Retype is a real `ALTER`, never drop-and-recreate. A converter is always optional, never
mandatory** — see "Retype, fully generalized" below: given one, it derives the new value
from the old; given none, resolution falls back to the field's own class default, never a
guess at converting the existing value. For native: an optional converter is passed
alongside the rename mapping at migration-generation time. For editor-created: the admin
optionally supplies a converter class through `SchemaEditor`. **Retyping a field on an
`#[Embed]` target propagates the same way rename does** — every embedding table runs the
same `ALTER` + converter-or-class-default against its own flattened copy of the column.

**Finding "every site that points at shape X"** — needed by new-field backfill above, by
both propagation rules just above, and by the dangling-target auditing and deletion
machinery below — reuses discovery machinery this design already builds for other
reasons, not a new scan, and generalizes past `#[Embed]` to every kind of "points at X": a
`#[Reference]` target, a `#[Embed]` target, a `Collection`'s item kind, and a Value
Object's custom `Type` class. For native classes, `EntityRegistrar::register()`'s existing
full-field-tree walk (already visiting every field of every kind while looking for
defaulted instances) also records the reverse edge for each one — shape X to every site
pointing at it — as a byproduct of that same pass, no extra cost. For editor-created
schemas, the same live-scan the dangling-reference auditing tool below already performs
over every stored schema, checking a different predicate (a field's kind, or a
collection's item kind, points at X) instead of "does this target still exist." A
non-entity collection's dedicated child table is one of the sites this walk/scan finds,
not a separate mechanism from an ordinary field on the declaring prototype's own row — same
abstract site, different landing table.

**This reverse index only answers "what *declares* a pointer at X"** — a schema-level
question. "What's actually *using* one right now" (does any existing row hold a live,
non-null value through one of those fields) is a separate, data-level query, needed by the
deletion machinery below and by any future admin-facing warning before a destructive
action. Keeping these two distinct matters: a tool built on only the schema-level index
would under- or over-count what a deletion actually touches.

**Retype, fully generalized: every retype is capture-to-`NoType` followed by
reconstruct-from-`NoType`, never a bespoke mechanism per pair of kinds.** The capture half
is always the same fixed, built-in logic (`NoType`'s own section below) — not
admin-authored, and identical whether triggered by a deliberate retype or by an upstream
deletion. The reconstruct half resolves the new kind's value from that blob, and **supplying
a converter is always optional, never mandatory**: given one, its job is "derive the new
value from the old one"; given none, resolution falls back to the field's own class default
(`#[DefaultInstance]` resolution, the same mechanism "Defaulted instances" already requires
for an ordinary new-field backfill) — a converter only earns its keep when the new value
should carry something forward from the old one, never because the framework needs one to
proceed. An ordinary scalar/value-object retype already looked like this (an `ALTER` plus an
optional converter); every case below is the same two-step shape, not a separate mechanism.

**The converter itself is always invoked once per existing entity row of the table being
retyped — one object, never a whole-table batch call, and never invoked once per item inside
a single row's own collection.** For a singular field, that one call's only argument is the
one row's own captured `NoType` value. For a `Collection`-cardinality field, that one call's
argument is still a single value — the whole assembled array of that one row's own items, one
dense entry per position (an empty placeholder standing in for any gap a sparse Owned
collection left behind, so position is never lost), never a separate call per item. The
output mirrors this: one new value for a singular field, one new array for a collection, both
from that same single per-row call. If the target field participates in a `Unique` group (see
"Uniqueness"), the framework collects every *row's* produced value — one value or one array
per row, across the whole table, never per item inside any one row's own collection — once the
converter has run across every row needing one, and checks that row-level set for pairwise
distinctness, and against any existing live values, before committing anything — refusing the
entire retype, naming the collision, if it isn't distinct. The converter stays exactly as
already specified; producing genuinely distinct values is whoever writes the converter's own
responsibility, never
something the framework orchestrates on their behalf.

**The converter is a named interface, `FieldRetypeConverter`** (`convert(mixed $captured):
mixed`, matching the contract just described) **that also declares, statically, which
retypes it's valid for** — `from(): FieldRetypeSignature` and `to(): FieldRetypeSignature`,
checked by reflection alone, no instantiation needed. `FieldRetypeSignature` is its own
small value object (private constructor, named factories mirroring `FieldDescriptor`'s own
five kinds, deliberately not `FieldDescriptor` itself, which describes a real field and
shouldn't grow a partial/wildcard mode): a kind, plus an optional target/item-kind that's
`null` to mean "any target of this kind" — recursively, for a `Collection`'s own item
signature. A retype naming a supplied converter whose `from()`/`to()` don't match the
field's actual current shape and the candidate target shape is rejected outright, naming the
mismatch, the same fail-loudly posture as every other structural violation in this design.
The same declared signature is what lets an admin-facing surface (`Editor\`, later) list
only the converters compatible with a given field's current shape, rather than every
registered converter regardless of fit — built on reflection over `from()` alone, no new
backend capability.

- **Retargeting a singular `Reference`** (it used to point at `Category`, now it should
  point at `Tag`): capture the old FK (old type and id) — the old target itself is never
  touched, it simply stops being referenced by this one field. A `Reference` is pointer
  semantics, never value semantics (see "FK `ON DELETE` policy"), so there's no class
  default to fall back to and no requirement that one exist: no converter leaves the new FK
  column null, same as an ordinary optional reference; a supplied converter may produce a
  new-target entity (found or freshly created) whose id lands in the rebuilt FK column, or
  simply return nothing.
- **A collection's item kind crossing into or out of `Reference`**: the same capture/
  reconstruct split, landing in whatever value column(s) the new item kind needs — one
  target-FK column, or whatever columns a scalar/value-object/`#[Embed]` item needs ("value
  column(s) is a function of kind" from "No blobs"). A supplied converter takes the *whole*
  captured array of old items as input and returns a new array of any length — never a
  forced one-call-per-item mapping, since filtering, merging, or expanding are all
  legitimate; no converter re-defaults the whole collection from scratch rather than
  touching the old items at all.
- **Retargeting an `OwningReference`/Owned-`Collection`'s own item type while staying
  Owned** (`Warranty` → `Guarantee`): capture reads the owned row's full
  recursively-flattened field tree before it's deleted through the ordinary cascade-delete
  path (`NoType`'s existing `OwningReference`/Owned-`Collection`-item capture, unchanged). An
  `OwningReference`/Owned-`Collection` *can* be required (Owned data is locally
  manufacturable, unlike Shared) — a supplied converter may produce a new owned entity
  (found or freshly created), `owner`/`owner_field`/`position` set to match; no converter
  falls back to the field's own class default instead (which may itself be empty, if the
  field is optional). For the collection case the converter again takes the whole captured
  array and returns one of any length, same as the `Reference`-crossing case above — never
  assumed to produce exactly one replacement per existing item.
- **Retyping into or out of `#[Embed]`**: always needs *something* to land, singular or
  collection-item — `#[Embed]` is value semantics only, the same posture as any shape
  that must carry a `#[DefaultInstance]` to exist as an Embed target at all. A supplied
  converter produces the new embedded value(s); no converter falls back to the target
  shape's own class default, never left absent.
- **Crossing between Owned and Shared, for the same field**: capture the old side exactly
  as `NoType` already does for whichever kind it was — drop the FK column for Shared,
  leaving the old target entity completely untouched since other things may still reference
  it; or read-then-cascade-delete for Owned, same as always. **The framework never supplies
  a default that copies data across or forks a duplicate entity.** Owned data "only exists
  for this entity" in the same sense `#[Embed]` does, so silently forking a Shared copy of
  what used to be owned, or silently deleting-and-relabeling a Shared row that might have
  other referrers, are both exactly the kind of silent magic "no attempt to guess" already
  rules out everywhere else. No converter leaves the new side at its own ordinary default —
  null for Shared, since it can never be required; empty, or a defaulted instance if
  required, for Owned — and whether the new side ends up populated with carried-over data
  instead is entirely the converter's own explicit choice (e.g. "read the still-alive
  Shared target and copy its fields into a fresh owned entity" is a converter an
  admin/developer can write, never something the framework does unasked).

**Required vs. optional governs whether *something* must end up present, for
`OwningReference`/Owned-`Collection` only — never for `Reference`, which can never be
required at all** (see "FK `ON DELETE` policy"). For Owned: an optional target accepts
nothing, no row created; a required target needs a valid result — the class default if no
converter was supplied, or whatever a supplied converter returns — before the retype can
succeed, or it fails loudly, same "no attempt to guess, refuse loudly" posture as every
other retype. For Shared: there is no required case, ever; a null FK is always an
acceptable outcome, full stop.

This closes "Flipping an existing relationship between Owned and Shared" entirely, not just
narrows it: the thing that made it look hard was assuming it had to preserve the original
row's identity through the swap. It never does — fork a new entity (or none, or the class
default) via the converter, exactly like any other retype crossing a relationship kind, and
the old side is handled exactly as its own kind's capture already specifies (untouched if
Shared, ordinarily cascade-deleted if Owned). No narrower version of this problem is left
open.

**Changing a field's cardinality (`Collection` ↔ singular) is the one orthogonal axis this
doesn't fold into** — a deliberate retype in its own right, never a side effect of anything
else, and distinct from the deletion-triggered `NoType` path, which explicitly preserves
cardinality (`NoType`'s storage shape is a function of cardinality, never the reverse) —
that mechanism never collapses a collection into a singular value, and this one doesn't
touch it. Collapsing an existing `Collection` field into a singular one needs a converter
that picks or combines the field's existing items into one value, or, if none is supplied,
falls back to the singular field's own class default; the collection's own dedicated table
(the Shared pivot, or the "No blobs" child table) drops once the conversion's done, same as
removing any other collection field. The reverse (a singular field becoming a `Collection`)
needs a converter that decides how to produce items from the one existing value, or falls
back to the collection field's own class default, landing in a freshly created dedicated
table the same shape any other collection field gets. The two axes compose freely when a
retype changes both at once — kind and cardinality are independent, each already reducible
to "drop the old physical representation, build whatever the new one needs, an optional
converter bridges them, the class default fills in when none is supplied."

**Reparenting** (add/change/remove a class's parent) is mechanically uniform for native
and editor-created once every chain already has `entities` as its structural top:
inserting or removing one CTI level, backfilled via the defaulted-instance mechanism
above. No approval gate beyond a warning shown in the editor UI when an admin triggers it
against an already-populated prototype; a developer triggering it from code is assumed to
already know what they're doing.

**That "no approval gate" posture is a data-safety judgment call, not a structural-validity
one, and the two are checked differently.** Reparenting is rejected outright, before any
mutation runs, if the candidate new parent is, or descends from, the entity being
reparented — the same fail-loudly posture as every other structural violation in this
design (Embed-of-Embed cycles, table-name collisions, defaulted-instance completeness),
just triggered at the point `reparent()` is invoked rather than at registration time, since
the parent link being checked doesn't exist to check until then. One check, native and
editor-created alike: walk the candidate new parent's own chain and reject, naming the
cycle, if the entity being reparented appears in it anywhere — this covers a direct
self-reparent (the entity is its own candidate parent) and reparenting onto any current
descendant with no separate case for either, and transitively protects every live subclass
of the reparented entity too, since a subclass's own chain already runs through the entity
being reparented, so the same single check keeps it from looping for them as well. This is
the one thing "no approval gate beyond a warning" above never meant to cover: a warning is
for a destructive-but-valid operation an admin might still want to confirm; a cycle isn't a
destructive operation at all, it's a structurally unresolvable one, and `chainOf()`'s own
truncation rule doesn't catch it either — truncation only ever triggers on an *unresolvable*
link, and every link in a cycle still resolves, just forever.

**Reparenting onto a level the entity already had a row for (most commonly: fixing a
broken parent, see below) needs no backfill at all** — the defaulted-instance mechanism
exists for a level an entity never had a row for; a level it already had, that only
became temporarily unreachable, already has a valid row sitting there, untouched.
**Removing a level, for any reparent, deletes that level's now-stray data for the
reparented entity and every one of its live subclasses, as an immediate, direct part of
the same reparent operation** — through the same `Persistence\Entity\WriteExecutor`
"Deleting a prototype also cascade-deletes every existing entity row" already uses (see
"Executing multi-entity writes safely"), not a second, separate bulk-delete mechanism that
would have to re-solve the same dependency-ordering problem on its own.

**The mirror-image case — backfilling a newly-added level's row — reuses the same
mechanism too.** A backfilled row's own defaulted-instance children, if the level being
added declares any, are constructed and written in the one order that's already safe: the
child first, then the row that references it.

**Reparenting a shape never touches column shape at its own entity tables — only at
wherever it's used as an `#[Embed]` target.** A CTI level is an already-existing table (the
parent class's own table, shared by every other subclass that extends it too), so
reparenting an entity only ever adds or removes *a row* at that level, never a column. An
`#[Embed]` site has no such separate level tables — the whole chain is flattened into one
row on someone else's table — so a shape gaining or losing a CTI level *does* change column
shape there, the same reverse-index discovery "Finding every site that points at shape X"
already generalizes for rename/retype propagation reaching every such site: a level
*inserted* adds that level's own fields as new columns, backfilled via `#[DefaultInstance]`
— the same "an embedded shape gaining a field backfills every table that embeds it" rule
Phase 2 already states, reparenting being one more trigger for it, not a separate mechanism;
a level *removed* drops the now-stray columns at every embedding site — the previously
unstated mirror-image direction, true as much for an ordinary `dropField()` on an embedded
shape as for a reparent-triggered removal. Unlike the entity-side row deletions above, this
is ordinary schema-level DDL — a column add/drop, the same as any other `addField()`/
`dropField()`.

**This reaches every live subclass of the reparented shape too, not just the shape named in
the `reparent()` call — the same cascading reach the entity-row-side deletion already has
("for the reparented entity and every one of its live subclasses"), extended here to
`#[Embed]` sites.** `fieldsOf()` resolves a shape's whole chain, so a subclass's own
resolved field tree already includes whatever its reparented ancestor contributes; a
subclass independently used as its own `#[Embed]` target somewhere else needs that site
found and updated too, via the same reverse-index, not just sites embedding the shape
`reparent()` was literally called on.

**`PrototypeRegistry::chainOf()` doesn't throw when a stored parent identifier fails to
resolve — it truncates the chain there**, treating the affected identifier as rooted at
`entities` directly from that point on. This is the shared primitive every case below
builds on. Nothing about any table or row changes to make this true: the tables on either
side of the break were already correctly linked, resolution just stops walking past it.
Because of that, it needs no defaulted-instance backfill (that mechanism is for a level
that never had a row; here every remaining level already does). Two consequences fall out
for free, not extra mechanism: a subclass of the affected identifier inherits the same
truncated view the moment *its own* `chainOf()` walk reaches that same point further up
(its own parent link was never touched, never needed to be); and a chain with more than
one broken link in a row still resolves correctly, since this only ever needs to find the
first unresolvable link walking up from the leaf, and never needs to know what's further
up a chain it's already discarding.

**Revoking `#[Entity]`'s `editorExtensible` flag, and deleting a prototype with live editor-created
subclasses, both invalidate the parent link of every *direct* editor-created subclass of
the revoked/deleted identifier** (a subclass further down the chain is unaffected at the
link level, since its own link was never about the identifier that disappeared — but it
inherits the truncated view above regardless). What happens around that shared primitive
differs by trigger, because one is reviewed/deploy-gated and the other is live/unreviewed
— the same distinction this design already draws everywhere else between native and
editor-created schema changes:

- **Native-triggered**: the stored parent identifier is left exactly as-is on every
  affected direct subclass, never silently rewritten, and the deploy step that reviewed
  the triggering change also marks every affected direct subclass with a "missing parent,
  needs review" flag. The `entities`-direct truncation above already makes the subclass
  fully functional (minus whatever fields lived only on the now-unreachable parent level);
  the flag exists purely so an admin can later make a deliberate call — accept the
  fallback permanently, or reparent somewhere more meaningful — not because anything is
  actually broken or blocked in the meantime.
- **Editor-created-triggered**: stays broken until manually fixed, deliberately — no
  deploy gate reviewed this change, so nothing auto-applies, consistent with the "no
  automatic fixing, ever" posture used everywhere else in this design. The stored parent
  identifier is kept, never blanked, specifically so an admin-facing surface can display
  what it used to point at. Ordinary content reads/writes on the subclass's
  still-resolving fields keep working normally through the ordinary write path (via the
  same truncation); `SchemaEditor` blocks saving *this subclass's own schema* until a
  valid parent is explicitly set.

**Collecting every subclass in either state needs no new backend mechanism** — it's the
same predicate the dangling-target auditing tool below already evaluates (does a stored
identifier still resolve through `PrototypeRegistry`), applied to `prototypes.parent`
instead of a field's own target. `Editor\` (a separate, later track) will eventually list
these, flag them on a schema's own edit view, and offer a recap page jumping to each one —
none of that changes what the backend needs to expose now, only that the predicate stays
queryable, which keeping the stale identifier already guarantees.

**Prototype/class deletion drops the prototype's own table and every dedicated table a
field it declares owns** — its own CTI-chain table, plus any Shared-collection pivot or
"No blobs" child table its own fields derive a name from, the same set "renaming the
declaring prototype" above already fans out across for a rename; deletion is the same fan
out, just dropping instead of renaming. There's no reason to keep any of that storage
around once nothing can resolve the identifier through `PrototypeRegistry` anymore. This is
about the deleted identifier's *own* dedicated tables only — "Allowed to dangle" below is
entirely about *other* prototypes' fields that merely reference the deleted identifier,
never about the deleted identifier's own data or its own fields' tables.

**Deleting a prototype also cascade-deletes every existing entity row of exactly that
concrete type**, across its whole chain down to `entities` itself — a harder case than
dangling, and a different one: such a row has no resolvable shape left at all, nothing
like a stale pointer sitting harmlessly on some other row. Scoped to the *exact* concrete
type: deleting a prototype that has live editor-created subclasses doesn't touch those
subclasses' own existing instances (they're a different concrete type, and the subclass
itself wasn't deleted) — it only triggers the parent-revocation fallback above for them.
This cascade-delete runs through `Persistence\Entity\WriteExecutor` (topological sort,
Owned-subtree expansion — see "Executing multi-entity writes safely"), not a bulk bypass —
which is exactly why it needs the mechanism below to stay possible at all when some of
those rows are still `RESTRICT`-protected by a live `Reference` elsewhere. Reusing it is a
structural choice: it already solves the dependency-safe ordering problem, no reason to
re-solve it in a second, separate bulk-delete mechanism.

**Prototype deletion optionally takes a replacement identifier and an `EntityRetypeConverter`
— not a separate operation, the same `deletePrototype()` call, just with two more optional
arguments.** Without them, nothing above changes: ordinary cascade-delete, ordinary `NoType`
capture for anything pointing at the deleted type, exactly as already described. Supplying
them turns the deletion into a substitution, reusing four already-defined mechanisms, no
fifth one invented for this:

- **Every CTI level, for every row that has one at the old type's own position in its chain
  — the old type's own exact-type rows, and every live subclass's rows too — is reconciled
  against the replacement's own chain by simple comparison, not by singling out a "shared
  prefix" as a special case: a level present in *both* chains keeps its existing row
  (never deleted) but gets its values updated from the converter's output for that level —
  the converter legitimately produces a full instance of the replacement type, inherited
  fields included, and there's no reason to discard that for a level that happens to
  survive; a level present only in the *old* chain has its row deleted; a level present
  only in the *new* chain has a row inserted, filled from the converter's output for it.**
  `entities.id` is preserved throughout — only `concrete_identifier` and whichever levels
  actually differ change. This is the exact same insert/remove-a-level primitive
  "Reparenting" already specifies, just reused per-row across the old type's whole live
  population instead of once for a single entity's own `reparent()` call, and sourced from
  the converter's output instead of `#[DefaultInstance]` backfill wherever a level is newly
  inserted. All three outcomes reuse `Persistence\Entity\WriteExecutor` the same way
  "Reparenting" already does for its own single-entity case, now run at bulk scale across
  every migrated row (see "Executing multi-entity writes safely").
- **Every prototype whose declared parent was exactly the old type gets reparented onto the
  replacement** — an ordinary `reparent()` call per direct child, using the same per-level
  reconciliation above. This already reaches every further descendant for free: `reparent()`
  already cascades its own level-removal "for the reparented entity and every one of its
  live subclasses," and its `#[Embed]`-propagation (see "Reparenting," `#[Embed]`
  interaction) already reaches every site embedding *the shape being reparented* — extended
  to also reach every one of *that shape's own* live subclasses' embedding sites too, the
  same cascading reach the entity-row side already has, not a narrower one.
- **Every `#[Embed]` occurrence of the old type, or of any of its live subclasses, singular
  or collection-item, gets the same per-level reconciliation above applied to its own
  flattened columns, through the exact same converter, instead of degrading to `NoType`.**
  Possible because an owned row's own `NoType` capture already "mirrors `Embed`'s own
  capture" — a standalone/root row, an `OwningReference`/Owned-`Collection`-item row
  (already "a full `entities` row exactly like a Shared one"), and an `#[Embed]` site all
  capture the identical full-field-tree shape elsewhere in this design, so the *same*
  converter instance, the same `convert(array $capturedFieldTree): object` call, serves all
  three physical contexts uniformly — once per entity-table row for the first two, once per
  embedding-table row for the third. No separate embed-specific converter, no adapter
  between contexts. An `#[Embed]` site has no `entities`-rooted row of its own to begin
  with, so this reconciliation is a column add/drop/backfill at every embedding table, the
  same as "Reparenting," `#[Embed]` interaction already describes — not a new exception
  carved out for substitution specifically.
- **Every plain `Reference`/`Collection`-of-reference field pointing at the old type or any
  of its live subclasses has its declared target type updated to the replacement
  automatically — no converter involved, because no value needs deriving.** The id never
  changes, so retargeting a pointer's *declared* type is pure metadata — reusing the exact
  reference-fixup list prototype-rename already performs (`prototypes.parent`,
  `entities.concrete_identifier`, every `reference()`/`embed()`/`collection()` pointer,
  `owner_field` values), which already handles this correctly since a substitution and a
  rename touch the same stored pointers, substitution just additionally has real data to
  migrate at the levels that actually change shape. Not an exception to "no automatic
  fixing, ever" — the same exemption rename propagation already has, for the same reason:
  nothing here can silently lose data, since nothing here touches data at all.

**Any `Unique` group declared at a level this reconciliation touches needs the same
pairwise-distinctness guarantee field-level retype already has, not a weaker one just
because the values arrived via a bulk per-row converter instead of a single-field one.**
Before committing anything, the framework collects every migrated row's produced value for
each such group — whether that group lives on a level present in both chains (keeping its
row, values updated) or one present only in the new chain (freshly inserted) — and checks
that set for pairwise distinctness among the migrated rows themselves, and against any of
the replacement type's own already-existing live values at that same level, refusing the
entire substitution and naming the collision if either check fails. This is not a fifth
mechanism: it's the exact same check `retype()`'s own converter output already requires
(see "Migrations and schema mutation" above), reused here because this reconciliation is
itself already level-by-level, the same granularity a `Unique` group is already scoped to
("a group can never span fields declared at different levels of a chain," see
"Uniqueness") — nothing about a `Unique` group distinguishes a value arriving through
`EntityRetypeConverter` from one arriving through `FieldRetypeConverter`.

`EntityRetypeConverter` declares its own applicability via `from(): string`/`to(): string`
(plain prototype identifiers, not wildcarded — unlike `FieldRetypeConverter`'s signature,
this operation is already a one-off, explicitly-named pair each time it's triggered, so the
declaration is purely a self-consistency check, rejecting a mismatched converter outright).
Finding every site to migrate or retarget reuses the same reverse-index discovery
machinery "Migrations and schema mutation" already generalizes for rename/retype
propagation and dangling-target auditing — not a new scan.

**Native and editor-created genuinely differ here, unlike most of this design's other
native/editor-created splits.** For editor-created, every bullet above is immediate and
automatic once the admin triggers the substitution through `SchemaEditor`, the converter
picked from a closed, pre-registered menu — the same posture `PrototypeValidator` already
uses — because editor-created schemas are data, with nothing analogous to a compiled
language's own type-safety requirements standing in the way. For native, the *values and
DDL* are equally tool-driven from the explicit "old type replaced by new type, with this
converter" declaration (never inferred by diffing, same posture rename already holds to) —
but a native class whose own property type-hint or attribute `target` parameter names the
old type, or whose own `extends` clause names it, is PHP source referencing a class that's
about to stop existing, which no migration tool can rewrite on the developer's behalf; the
developer edits that source themselves, as an ordinary reviewed code change, and the
explicit declaration is what then drives the tool's own DDL generation and per-row
migrations against the result.

**Deleting a prototype, or a native class other editor-created schemas still hold live
references into, must always succeed and must not silently discard what the deleted rows
used to mean to whatever referenced them** — the same way a native class disappearing from
the codebase is already unstoppable from an editor-created schema's perspective. A
`Reference` can never be required (see "FK `ON DELETE` policy"), so nothing ever blocks
this delete the way `RESTRICT` would for a required value — deleting a `Tag` row still
pointed at by `Article.category` just lets the FK `SET NULL`, the same as any other
optional reference's target disappearing. Left at that, though, the fact that
`Article.category` *used to* point at this specific `Tag` is lost the instant the delete
runs, with nothing left to retype from and no trace at all — the deletion is
schema-triggered, so it produces no `EntityChangeRecord` either (see "Executing
multi-entity writes safely"). The fix is to retype the referencing field to `NoType` as part of the same
operation, before the delete runs, preserving that information instead of letting it
degrade to a bare `null`:

- **`NoType`** is a reserved `FieldDescriptor` kind — its own distinct `FieldKind`, not a
  special-cased `valueObject()` reusing a reserved `Type` class, so a genuinely degraded
  field can never be confused with an ordinary Value Object field that happens to target the
  same internal type — structurally shaped like a Value Object regardless (one column for a
  singular field, one column on a collection's own dedicated table for a collection-item),
  that any `Reference`, `Embed`, `Collection`-item, Value-Object, `OwningReference`, or Owned
  `Collection`-item field gets converted into when its declared target becomes
  unresolvable or its current value must be invalidated by an upstream deletion. No public
  factory constructs it (see "Shape comes from a neutral descriptor") — only the framework's
  own capture/retype machinery ever does. **Its
  purpose is to leave a later `retype()` something concrete to convert from** — not to
  serve as a historical record. An ordinary content-triggered deletion already has one
  independently, through the undo/revision-history pipeline (see "Content undo, draft, and
  revision history"); the deletions `NoType` capture sits in front of are schema-triggered
  and never get that trail either (see "Executing multi-entity writes safely"), which is exactly
  why `NoType` capturing the value here matters — it's the only trace that survives. That
  purpose is what fixes both what gets captured and where it's stored:
  enough of the old value survives to feed a converter, landed whichever way an ordinary
  field of that cardinality is already landed elsewhere in this design, never a new storage
  shape invented just for this. This is a deliberate, narrow exception to "No blobs" above,
  reserved for exactly this degraded state, never a general storage tier for ordinary data.
  The same capture logic also runs as the first half of any *deliberate* retype (see
  "Migrations and schema mutation" above), not only a forced one — `NoType` is simply
  whatever sits between the old kind's capture and the new kind's reconstruction, whether or
  not an upstream deletion was involved.
- **Singular `Reference`**: the blob (old target type and id) lands on a new column added
  to the referencing row itself, replacing the dropped FK column. **`Reference` used as a
  `Collection`'s item kind**: the same blob, per existing item, lands on a new column added
  to that collection's own dedicated table (the Shared pivot, carrying `position` already)
  — the FK-to-target column on that table is what gets dropped, not the table itself; the
  collection keeps its own existing table, ordering, and cardinality, only its item kind
  becomes `NoType`.
- **`Embed`, singular or `Collection`-item**: every value from the *full
  recursively-flattened field tree* (including nested embeds and nested value-object
  members, not just the shape's own top-level fields — the same recursive resolution
  `PrototypeRegistry::fieldsOf()` already uses for ordinary flattening) is captured into one
  blob, landed the same way as the `Reference` case above (a new column on the referencing
  row for singular, a new column on the collection's own dedicated table for
  collection-item), then the now-redundant flattened columns (all of them, recursively) get
  dropped — consolidating what was many dead columns into one recoverable blob, losing
  nothing. This resolves independent of any delete even being involved: deleting an
  *embedded* prototype can't leave its flattened columns sitting on every embedding table
  forever (real, unreclaimable schema debris feeding the exact column-count risk "No blobs"
  already flagged) and can't just drop them either (silent data loss).
- **Value Object, singular or `Collection`-item**: the old custom `Type` class name and raw
  value, landed the same way as the two cases above.
- **`OwningReference`**: there's no column to replace on the owner's own row — a live
  `OwningReference` never has one (see "References and collections") — so a new column is
  added to the owner's own row to hold the blob, the same shape every other singular
  `NoType` field already lands in. What it captures mirrors `Embed`'s own capture: every
  value from the owned row's *full recursively-flattened field tree*, read off it before
  it's deleted — **including, recursively, the full captured subtree of any
  `OwningReference`/Owned-`Collection` field found inside that tree, to any depth**, reusing
  the same `owner`/`owner_field` lookup `WriteExecutor`'s own Owned-subtree-expansion
  already walks ("Executing multi-entity writes safely"), not a new traversal. Without this, a nested
  owned row one level deeper than the field actually being retyped would be silently
  destroyed by that same cascade-delete with no trace anywhere, while the top-level row's
  own data survives in the blob — an inconsistency, not an accepted tradeoff. (An
  `#[Embed]` target's own field tree can never hit this case: it's already restricted to
  scalar/value-object/nested-embed fields only, no `Reference`/`Collection` at any depth,
  so this recursion only ever applies to an entity being captured, never to `#[Embed]`'s
  own flattening.) Deletion itself doesn't change at all — the owned row still goes
  through `Persistence\Entity\WriteExecutor` (full topological sort, the downward
  Owned-subtree expansion for anything *it* in turn owned, and the sideways expansion that
  finds and nulls any outside Shared reference pointing at it — "Executing multi-entity
  writes safely"), exactly as any other delete, unlogged since it's schema-triggered; the
  capture is just a read that happens first,
  the same sequencing `Embed`'s own conversion already uses. **Owned `Collection`-item**:
  same capture, same ordinary deletion of every existing
  item — but landed in a newly-created dedicated child table scoped to that field
  (`ownerId`, `position`, blob), created on demand the moment the first item needs it, the
  exact same shape "No blobs" already uses for a non-entity collection's own dedicated
  table. **The field's own `<field>_count` column, present on the owner's declaring table
  while the relationship was live ("References and collections"), is dropped as part of
  this same conversion** — not preserved, not repurposed. The dedicated child table gives
  every slot a real row regardless of its blob's own content, the same reason every other
  collection's dedicated table already needs no count column of its own; `<field>_count`
  only ever existed to cover the live-Owned-Collection case's sparse rows, and that case no
  longer applies once the field is `NoType`.
- **`NoType`'s storage shape is therefore purely a function of cardinality — a column for
  singular, a dedicated table for a collection — never of which kind of field it used to
  be.** A live singular `OwningReference` has no column at all, and a live Owned
  `Collection` has only its own `<field>_count`, never a per-item value column — both lose
  that minimal structure the moment they degrade to `NoType`: a new column appears for the
  singular case, dropping `<field>_count` in favor of the dedicated child table for the
  collection case. Once degraded it's an ordinary `NoType` field like any other, read and
  later retyped through the exact same path every other `NoType` field uses, no Owned-aware
  special-casing anywhere in `Repository`. Retyping it back into a live
  `OwningReference`/Owned-`Collection` — or into a live `Reference`, or anything else — is
  the same capture/reconstruct retype mechanism above, not a separate problem: the converter
  produces a new owned entity (found or freshly created, or none if the field is optional)
  from the blob, same as any other `NoType` reconstruction — a live Owned `Collection`
  reconstructed this way gets a fresh `<field>_count` the same way adding any other field
  does, not a special case.
- `RequiredValidator` needs no change for any of the above: a `NoType` value is a
  well-formed value, not a `NULL` violating a `NOT NULL` column.
- This is exactly where an admin-facing warning before a destructive delete earns its
  keep (`Editor\`, later) — *"this is referenced/embedded/owned by all of these, sure?"*
  — built on the same reverse index and the same data-level "is anything live" query, not
  a new backend capability.

**Everything else referencing a deleted identifier already has its own way of catching
it, at the point that matters for that context:**

- **A native class referencing a deleted native class** fails loudly at registration
  time, for free — `EntityRegistrar::register()`'s existing full-field-tree walk (already
  needed for defaulted-instance discovery) reflects on every `#[Reference]`/`#[Embed]`
  target it finds; if that target class no longer exists, the reflection call itself
  throws before the app ever serves a request. No new mechanism needed, this is a
  pre-existing check catching a new case.
- **Deleting a native class that other native code still references** goes through the
  same reviewed-migration tool that already takes explicit rename/retype mappings — a
  deletion is declared there too, and the tool surfaces every remaining reference to the
  deleted identifier as part of that same reviewed diff, instead of only failing later at
  registration.
- **Anything referencing a deleted identifier from an editor-created schema** — reference,
  embed, collection-item, or Value-Object target, native or editor-created, plus the same
  "parent no longer valid" case above — can't rely on a PHP-level throw, since editor
  schemas are data, not compiled code. Two enforcement points instead: `SchemaEditor`
  blocks *saving* a schema with a broken field until it's fixed or removed, and a separate
  auditing tool proactively scans every editor-created schema for exactly this class of
  breakage (dangling reference/embed/collection/Value-Object target, parent revoked,
  parent deleted), so an admin can find and fix these without first having to stumble into
  each broken schema individually. Existing data already went through the `NoType`
  conversion above regardless, by the time any of this is found.

**No schema-level undo/redo, deliberately.** Schema mutations are immediate and permanent
from the write path's perspective — recoverable only through a full database
backup/restore, the same posture native migrations already have (a bad migration is
already "roll back the deploy," not "invert one DDL statement"). This retires the
two-log (`SchemaUndoLog` + content `UndoLog`) bridging design the old system was building
toward — there's only one undo log now, and it's content-only (see below).

## Executing multi-entity writes safely

**`Persistence\Entity\WriteExecutor` is what every database write runs through,
unconditionally — content-triggered or schema-triggered, logged or not.** It takes a
batch of `WriteOperation`s (a create/update/delete instruction, possibly naming a `TempId`
placeholder instead of a real id for an entity created in the same batch), organizes them
into a safe, complete execution order, and runs them against `Repository` inside one
database transaction. It returns a `WriteEffect` per operation — the resolved id, the
values actually applied — and has no concept of logging at all.

**Full topological sort**, not a bounded heuristic — CMS content nests arbitrarily deep,
and a hand-maintained list of "supported nesting patterns" doesn't scale. Dependency edges
are derived automatically from the batch's own reference structure — an Owned
relationship's `owner` pointer is just another edge, not a special case. Read in opposite
directions for inserts vs. deletes. **Cycles are rejected outright**, naming the cycle —
this covers an ownership cycle (`A.owner = B`, `B.owner = A`) for free, the same
reference-graph check, no separate carve-out.

**Deleting an owner auto-expands to everything it transitively owns**, appended as
explicit delete operations before the sort runs — reusing the same `owner`/`owner_field`
lookup `Repository` already needs for ordinary Owned hydration. This is what makes
`entities.owner`'s `RESTRICT` (see "References and collections") never actually fire in
normal operation, whatever triggered the delete. This expansion is `WriteExecutor`'s job,
not `Repository::delete()`'s — `Repository::delete()` stays a single-entity,
CTI-chain-aware primitive; `WriteExecutor` only reuses `Repository`'s existing
owned-descendant read, not a duplicate of it.

**Deleting any entity also finds and nulls every live Shared reference pointing at it** —
a sideways expansion, before the sort runs, rather than letting the database's own `SET
NULL` fire as an untracked side effect.

Composes for free: downward into everything an owner transitively owns, each of those
independently expanding sideways too. One mechanism, run uniformly, whatever triggered the
write.

**Logging is a separate layer on top of `WriteExecutor`, not inside it — `Persistence\
Changeset\ChangesetFlusher` builds `Persistence\Changeset\Undo\` (`Revision`,
`EntityChangeRecord`, conflict detection) rows from the `WriteEffect`s `WriteExecutor`
returns it, engaged exactly when the write originates from the content write path** (an
ordinary content edit, undo/redo, revision-history restore). **A schema-triggered write
never engages it, regardless of which row operations it contains** — delete, update, and
insert alike, because `Persistence\Schema\`'s mutation methods call `WriteExecutor`
directly and never construct a `Changeset` at all. Reparenting's removal and backfill
insertion, a prototype's cascade-delete, `dropField()`'s cascade-delete of Owned
descendants, substitution's three-way reconciliation, and an `#[Embed]`-site's column
backfill/drop are all schema-triggered — none of them ever produce a `Revision` or
`EntityChangeRecord`.

**No changelog exists for what a schema mutation did, beyond a full database
backup/restore** — consistent with "No schema-level undo/redo, deliberately." Using the
undo log as one would be misleading regardless: it would only ever capture whichever row
operations happened to be a delete, never the update/insert half of the same operation. A
readable diff, if ever needed, is an injectable pre-change/post-change hook comparing
captured state — the same deferred-extension-point posture the pruning tool's observer
hook already uses — not the undo log's job.

## Content write path

Explicit `Changeset`, not auto-diffing — reuses the same batch the undo system needs
anyway, avoiding a classic Unit-of-Work's automatic dirty-checking machinery entirely. A
`Changeset` is a named collection of `Persistence\Entity\WriteOperation`s for one
content-editing flush — the same instruction type any schema mutation builds too, just
labeled and batched here specifically so `ChangesetFlusher` has something to log from
afterward. `ChangesetFlusher` hands that collection to `Persistence\Entity\WriteExecutor`
for the actual organizing and executing (see "Executing multi-entity writes safely");
every content flush uses it unconditionally, the same as any schema-triggered write.

**What's specific to content is that `ChangesetFlusher` builds a `Revision`/
`EntityChangeRecord` from every `WriteEffect` `WriteExecutor` returns it.** Because every
entity a flush touches gets its own independent `EntityChangeRecord` (see "Content undo,
draft, and revision history"), the sideways Shared-reference-nulling expansion
`WriteExecutor` already performs is what keeps that invariant true even for an entity
whose only connection to a delete is "it referenced the thing that disappeared" (singular
field or collection item — the change being "one item nulled" for the latter, never the
pivot row, which carries no independently-logged content of its own, see "FK `ON DELETE`
policy") — without that expansion, the reference would go null with no record of the
change, and undoing the deletion would restore the deleted entity but not the reference to
it.

**A third expansion flavor, specific to an Owned collection: inserting or removing a slot
shifts every later sibling's `position` and adjusts the owner's own `<field>_count`, both
logged as part of the same changeset.** Four operations fall out of combining "does the
slot's content change" with "does the slot itself survive" (see "References and
collections" for the `<field>_count` distinction this builds on):

- **Clear** (an optional item's content removed, the slot survives empty) and **Fill** (an
  optional item created at an already-counted, previously-empty slot) are both already fully
  covered by an ordinary create or delete through the existing path — no sibling shift, no
  count change, nothing new needed.
- **Remove** (the slot itself goes away) is the same delete as Clear, plus the expansion:
  every later sibling's `position` decremented, the owner's own `<field>_count` decremented.
  **Insert** (the collection grows a slot, anywhere including the end) is the mirror: the
  same create as Fill, plus every later sibling's `position` incremented and the count
  incremented. "Append" is Insert with nothing after the insertion point to shift, not a
  separate case.

A required item kind only ever has Insert/Remove available — Clear has no valid empty state
to leave a required slot in, refused outright, the same required-vs-optional boundary
already drawn elsewhere in this design. Which of the four is meant can never be inferred
from the create/delete alone — it's an explicit choice at the point the change is added to
the changeset, the same never-inferred posture rename already uses.

This shift computation is `Persistence\Changeset\`'s own job, done before the batch ever
reaches `WriteExecutor` — unlike the downward/sideways expansions ("Executing
multi-entity writes safely"), which are generic and need to be shared with
schema-triggered writes too, nothing about a schema mutation ever needs a slot-shift
(`dropField()` removes a whole field, never one slot), so there's no reason to push this
into `WriteExecutor`'s own scope. `ChangesetFlusher` adds the sibling-`position`/
`<field>_count` updates as ordinary `WriteOperation`s into its own batch, alongside the
slot's own create/delete, and `WriteExecutor` sorts and executes all of them together
exactly as it would any other batch. Every shifted sibling gets its own ordinary
`EntityChangeRecord`, same as any other touched entity — undo already restores a whole
`Revision` atomically, so reversing a shift needs no new mechanism. Composes for free with
`WriteExecutor`'s own downward/sideways expansions too: Remove is still fundamentally
"delete this owned entity," so if that item itself owns descendants or is
Shared-referenced from elsewhere, both of those expansions still run exactly as already
described, no special-casing for the slot-shift case.

**Concurrent-write protection**: an `expectedOperationId` receipt, reject-by-default with
an explicit override to retry. The id itself is sourced from the undo log, not a second,
parallel counter: the monotonic sequence position of the most recent `Revision` whose
`EntityChangeRecord` touched the entity (see "Content undo, draft, and revision history") —
reusing the same monotonic-sequence idiom `Revision` already carries. This is also why the
mechanism can't exist before the undo log does; `ROADMAP_V2.md` sequences it accordingly.
For an entity with Owned descendants, the id it must match
is the latest such position touching *it or anything it transitively owns* — recursive over
the owned subtree, found by walking what's still live via the same lookup the expansion
above uses, plus, for anything no longer live to walk to directly, that entity's own
`EntityChangeRecord`-captured `owner`/`owner_field` to keep resolving upward through
whatever of the chain still exists — not just its
own direct changes. Deliberately narrow: this applies to Owned only (an Owned child has no
life apart from its owner, so a change anywhere underneath is a change to the owner as far
as a concurrent caller is concerned) and never to Shared (a Shared entity has independent
life by definition; its operation id never bubbles to whatever references it).
**This redefinition is scoped to write-time concurrency protection only** — it does not
change the undo conflict check (still per-entity, per-field, unbubbled; see "Content undo,
draft, and revision history"), `EntityChangeRecord` logging (still one independent record
per touched entity, Owned or Shared), or `FieldPermission` (still per-field, independent
regardless of Owned/Shared). **Accepted tradeoff**: the whole owned subtree becomes one
serialization unit — two unrelated concurrent edits to two different Owned relationships
on the same root (e.g. a Product's `MediaAsset` gallery and its separately-Owned
`Warranty` singular reference) can spuriously conflict with each other, even though
neither touches what the other changed. (An `Embed` field like `Pricing` doesn't need this
tradeoff to conflict with a concurrent edit elsewhere on the same root — it's flattened
into the owner's own row, so any two edits touching that same row already conflict under
ordinary single-row concurrency, independent of the Owned-subtree-bubbling rule above.)

## Content undo, draft, and revision history

Content-only now (schema mutations are excluded per above).

**Structure adopts Hibernate Envers's shape (the grouping), not its storage (full
snapshots).** One lightweight `Revision` record per flush — whatever triggered it: an
ordinary publish, a Ctrl+Z undo, or a revision-history restore — holding just metadata (a
monotonic sequence position, actor, kind), no diff data of its own. `actor` is a plain,
opaque identifier naming whoever triggered the flush, not an instance of
`Persistence\Permission\Actor` — resolving it to a real registered user (for display, e.g.
"who edited this") is left to whatever owns user registration, a namespace not designed in
this document, the same deferral posture as `Editor\` itself (see "Explicitly out of
scope"). Every entity that
flush actually touched gets its own independent `EntityChangeRecord` (before/after per
changed field, not a full snapshot — kept as a diff, unlike Envers's own full-row audit
tables, for the storage-efficiency reasons already decided), FK'd back to that shared
`Revision` — **no carve-out for Owned entities.** Each `EntityChangeRecord` also captures
the entity's own `owner`/`owner_field` as of that write, for both an update and a delete —
cheap, since it's already read during the write regardless. This is what lets concurrent-
write protection (see "Content write path") trace a since-deleted Owned descendant's place
in its former owner's subtree without needing a live `entities.owner` link to walk to it
directly. The old design's "Owned folds into the
owner's own diff, no independent record" rule is retracted: it made sense when an Owned
row lived in a per-relationship child table with no identity of its own, but an Owned
entity now has a full `entities` row exactly like a Shared one, so nothing structurally
distinguishes it in the undo/logging path anymore. One rule for every touched entity,
Owned or Shared, is also what lets the per-touched-entity conflict check in undo (below)
cover Owned for free, with no ownership-based special case. (This is specific to
undo/logging — write-time concurrency protection treats Owned differently, on purpose;
see "Content write path".) This answers both "what did this one database transaction cause, across
everything it touched" (query by `Revision` id) and "what's this one entity's own
history" (query that entity's own `EntityChangeRecord` chain) without either query
fighting the other's retention needs.

**No automatic pruning, anywhere, ever — manual only, and gated by a dedicated
`Persistence\Permission\HistoryPermission`** — a narrow capability check via the same `Actor::hasRole()`
`SchemaPermission`/`FieldPermission` are already built on, kept as its own permission
rather than folded into `SchemaPermission`: pruning destroys content history, it isn't a
schema mutation, and conflating the two would make `SchemaPermission` mean two different
things. This is real historical data, not
disposable cache; deciding what to discard is a deliberate, human-triggered action (by
age, by entity, by prototype, whatever an admin judges appropriate at the time), never a
background policy. This is also what makes atomic multi-entity undo viable again: a
`Revision`'s `EntityChangeRecord`s only disappear when a human explicitly prunes them, not
as a side effect of some other entity's unrelated edit frequency — the "independent
per-entity retention clocks diverge and fragment a shared operation" problem earlier
turns got stuck on doesn't arise from ordinary operation anymore. It can still happen if
an admin manually prunes one touched entity's old history but not another's — accepted as
a rare, deliberate, admin-caused edge case (see the refusal behavior below), not a routine
one to design around.

**A `Revision` left with zero `EntityChangeRecord`s after pruning is left as an empty
husk, never auto-deleted.** Deleting it automatically the moment its last child disappears
would itself be a form of automatic pruning — a side effect of a different, unrelated
decision — which is exactly what's ruled out above. The same manual pruning tool can also
target a `Revision` row directly, empty or not, as its own explicit action; pruning stays a
deliberate decision about whatever was actually selected, never an automatic consequence of
pruning something else.

- **Draft belongs to `Editor\`, not this document.** Unlike undo, it has no meaning for a
  write path that isn't a human composing an edit through the admin UI, so it isn't a
  persistence-layer concern (see "Namespaces and migration path"). Whatever it ends up
  being is built from `Persistence\Changeset\Changeset` plus `ChangesetFlusher` to publish
  — the same way `RemoteCommand` below is built from `Revision` — but its actual shape
  (what triggers one, how a read is supposed to differ from the ordinary live state while
  one is pending, how it's scoped across a multi-entity changeset, whether it's shared or
  per-user) isn't decided here and is left to `Editor\`'s own design pass.
- **Ctrl+Z can undo a whole `Revision`, including one that touched multiple entities.**
  Undoing `Revision` R: gather every `EntityChangeRecord` under it; for *each* entity it
  touched, independently check that entity's own subsequent chain for anything touching
  the same field since (the same per-entity conflict check as before, just run once per
  touched entity instead of once total). If every touched entity's record still exists and
  passes its own check, apply the whole inverse atomically (the same topological-sort
  changeset machinery already built for ordinary writes), producing a **new** `Revision`
  recording the undo — append-only, consistent with "redo is just undo-the-undo." **If any
  touched entity's record is missing (manually pruned) or fails its own conflict check,
  the whole undo is refused outright, naming exactly what's blocking it — never a silent
  partial revert.**
- **Redo** needs no new mechanism: undoing is itself a logged `Revision`, redoing is
  undoing the undo.
- **Undo authorization, reversing the v1 stance, attaches at the `Revision`.** The old
  design concluded no dedicated server-side check was needed beyond ordinary
  `FieldPermission` write-gating, reasoning that a client only ever holds its own
  operation ids. **Decided differently here**: that leaves a real loophole — nothing
  stops a client calling the undo endpoint directly with a `Revision` id it didn't itself
  produce, and if the caller happens to have ordinary write permission on the affected
  field(s), the server would undo someone else's `Revision` passing as the caller's own
  Ctrl+Z. Undoing a specific `Revision` now requires a real check, behind
  `Persistence\Permission\`, that the `Revision`'s own stored `actor` matches the
  requesting user/session's own identifier — a plain value comparison, never an
  `Actor::hasRole()` call, since this is about preventing impersonation of someone else's
  undo, not about whether the caller holds the right role — checked once, before the
  inverse changeset is even computed — in addition to, not instead of, the ordinary
  write-gate (which *is* `Actor`-gated, via `FieldPermission`, exactly as already decided).
  Revision-history restore (the separate, deliberate surface below)
  is explicitly exempt from this check — reaching into anyone's past state there is the
  entire point of that surface.
- **Client-side command stack**: `LocalCommand` (synchronous, in-memory, no server
  round-trip) vs. `RemoteCommand` (references a specific `Revision` id, needs a visible
  pending state, never optimistically reverts before server confirmation) — one
  independent stack per open editing tab, not per session. A single visible user action
  that touched multiple entities in one flush still pushes exactly one `RemoteCommand`,
  referencing that one `Revision` — Ctrl+Z reverts everything it grouped, per the
  mechanism above.
- **Revision-history restore** (the separate, deliberate "view/restore any past state" UI
  surface, distinct from Ctrl+Z): reads one entity's own `EntityChangeRecord` chain and
  restores it to a chosen past state by flushing a new changeset — itself producing a new
  `Revision`, same as everything else. **By default cannot reach back past any schema
  change anywhere** — a single global, monotonically-incrementing `schema_version` (the
  same monotonic-sequence idiom `Revision` itself already uses), bumped by every schema
  mutation of any kind, native or editor-created, including every propagating side effect
  (an `#[Embed]` rename's fan-out to every embedding table, an `OwningReference` rename's
  `owner_field` fixup, reparenting, prototype deletion's `NoType` conversions, ...). Every
  `EntityChangeRecord` is stamped with the current `schema_version` at write time; restore
  refuses by default unless a chosen record's stamped version still matches the current
  one. Deliberately global rather than scoped per prototype: correctly enumerating every
  propagation path a given mutation might touch is exactly the bookkeeping the refinement
  below defers, not something the conservative default needs to get right first. Worth
  narrowing later by bookkeeping exactly what a given schema operation actually touched and
  only blocking restore for the touched part — reusing the same never/conditional/always
  classification already used for schema-mutation kinds (purely-additive changes never
  block; a single-field change like drop/rename/retype blocks only that field;
  reparent/delete block more broadly) — but the conservative, global default is what's
  decided now; the refinement is not.

## Identity Map + Repository + lazy loading

Standard pattern (Fowler's PoEAA / Doctrine's `EntityManager`+`UnitOfWork`): resolving the
same entity id twice within a request yields the same PHP instance. Lazy loading (when a
query runs) and the identity map (whether a second resolution reuses the first) are
complementary — a lazy proxy consults the identity map before running a fresh query.
Scope is per-request, built fresh and discarded at request end; a cross-request cache is a
separate, later optimization layered on top, not conflated with this correctness
guarantee. References/collections must not eagerly hydrate on an entity's own load — only
on actual access.

## Validation

`FieldValidator` — a composable list per field (required, length, format, ...), never a
growing pile of `FieldDescriptor` flags. `PrototypeValidator` — a separate entity-level
interface for cross-field rules (end date after start date), evaluated against the whole
candidate state; native classes get arbitrary logic, editor-created prototypes pick from a
closed menu only. `Unique` (see "Uniqueness") lives in this same family as one more
recognized kind, naming one or more of a shape's own fields that together must be unique —
the one kind the framework also materializes as a real database constraint rather than
evaluating purely against candidate state. Server-side validation is mandatory regardless of
what the client already checked.

Client-side pre-validation splits into three tiers behind one clean interface
(`describe()` emits `{type, ...params}`, never needs to know which tier applies): native
HTML5 constraint attributes, a small shared registry of named JS algorithms (IBAN, credit
card checksum, ...) for things the browser doesn't validate natively, and genuinely bespoke
logic that stays server-round-trip-only.

## Admin list/filter views

A query-builder resolving each field to its real column *and* which chain-level table
holds it: `Query::for($identifier)->where(...)->orderBy(...)->after($cursor)->limit($n)`.
**Cursor/keyset pagination from day one**, never `OFFSET`/`LIMIT` — a standard keyset
cursor (sort-column value + primary-key tiebreaker) is straightforward once every sortable
field is a real, typed column. One real multi-table `JOIN` across the whole chain with
per-level column aliasing replaces a one-query-per-level approach for a list of rows.
`count()` reuses the same `WHERE`, independent of pagination.

**Polymorphic hydration of a `Query`'s own result rows is opt-in, never the default** — a
row that's physically a more specific subtype only gets fully hydrated as that subtype
when the caller explicitly asks (batched by distinct `concrete_identifier` present on the
page), keeping cost predictable and visible at the call site rather than an implicit,
unpredictable per-row tax.

Deliberately out of scope: filtering an outer list by something inside a collection
sub-structure (`EXISTS`-style semantics) — meaningfully harder, rarer need.

**`Query`'s hydrated results go through the same read-time `FieldPermission` filter as
`Repository::find()`/`lazyFind()`** — one filtering step, not a separate one `Query` would
need to reimplement, applied per field exactly as declared regardless of which path
resolved the row.

**`where()`/`orderBy()` on a field the actor can't read is rejected, the same as if that
field didn't exist** — not just stripped from the hydrated result. Allowing it through
would leak the field's values via a side channel (sort order, or which rows pass a filter)
even though `FieldPermission` scrubs the field itself from what's returned; a cursor
carries the sort field's own value, which would leak it just by existing. Same posture as
every other read-time `FieldPermission` check, just applied to the query's inputs instead
of only its output rows.

## Permissions

`SchemaPermission` gates every schema mutation from the first commit that makes runtime
mutation possible at all. One check at the call's own entry point — `reparent()`'s subclass
and `#[Embed]`-site cascade, and `deletePrototype()`'s substitution reconciliation across
every migrated row, are never separately re-checked, since both already run inside the one
call that was already gated. `FieldPermission` enforced at three points: read-time filtering,
a mandatory server-side write gate (before validation), and a client-side UX-only gate.
`HistoryPermission` gates manual pruning (see "Content undo, draft, and revision history")
— its own narrow permission, not folded into `SchemaPermission`, since pruning destroys
content history rather than mutating schema. A minimal `Actor` interface
(`hasRole(string): bool`) backs all three — a pure adapter, never identity storage of its
own: whatever registers real users (a separate namespace, not designed in this document)
implements it over its own data. `Revision.actor` (see "Content undo, draft, and revision
history") is a related but distinct concept, a plain opaque identifier rather than an
`Actor` instance — `Persistence\` never needs a class-level dependency on wherever user
registration ends up living, only a value to compare and, eventually, resolve for display.

**`FieldPermission` is a per-`FieldDescriptor` declaration, not a per-table or per-entity
one** — every field, at whatever level of a shape's own tree, carries its own permission
from the point it's declared, resolved the same way `fieldsOf()` already resolves any
other per-field property (type, validators). This is what makes granularity a non-issue
for an `#[Embed]` field specifically: a sub-field like `pricing.wholesaleCost` keeps its
own independently-declared permission (distinct from `pricing.listPrice`'s) whether
`PricingInfo` ends up flattened into the owner's row via Embed or given its own table as a
standalone Entity — Embed only changes where the field's data physically lives, never a
re-declaration of its permission at some coarser grain. An Owned or Shared reference's
nested entity needs no special-casing either, for the same underlying reason plus one more
layer: the relationship field itself (e.g. `Product.currentPricing`) carries its own
permission like any field, and the referenced entity's own fields carry theirs
independently — two already-ordinary checks composed, not a new mechanism.

## Media/file fields

`MediaAsset` is the worked example exercising both Owned (inline upload, tied to its
owner for deletion and concurrency purposes — see "Content write path" — though still
logged with its own independent `EntityChangeRecord` history like any entity) and Shared
(media library, referenced from wherever it's used) at once. Removing a `MediaAsset`
reference is immediate at the database level. Where and how the underlying file bytes are
actually stored, and whether/when they get reclaimed, is outside this document's four
namespaces entirely — the same deferral posture as user registration ("Explicitly out of
scope") — `MediaAsset` is, from `Persistence\`'s own point of view, an ordinary entity with
whatever locator field its own storage needs, nothing more.

**One deferred extension point is worth naming, though, since it needs no structural change
to add later.** The manual pruning tool already has to read a record's full structured
per-field content before deleting it (`EntityChangeRecord` is a structured diff, never an
opaque blob — "No blobs" already rules out anything else). An optional, injectable observer
notified with that same content immediately before the delete runs — so an application can
decide for itself whether a just-pruned value means some referenced file's bytes are now
safe to reclaim — is addable at that point with zero schema change. Not built in this
rewrite.

## Deferred — not resolved in this document

- **Fine-grained revision-history reachability across a schema change.** Conservative
  default (blocked) is decided; the touched-field-bookkeeping refinement is not.
- **Exact attribute/API surface** for `#[DefaultInstance]`, converter classes, rename/retype
  invocation parameters — this document is architecture, not the implementation API. One
  structural piece of this is decided, though (see "Shape comes from a neutral descriptor"):
  which concerns get their own attribute versus consolidate into one, not every parameter
  name and type.
- **Naming conventions pass.** Namespace-level naming is decided (`Persistence\Entity\`/
  `Persistence\Schema\`/`Editor\`, see above), but class/method-level terminology
  (`Owned`/`Shared`, `Embed`,
  `FieldDescriptor`, ...) is still carried over from the old design unchanged and remains
  a candidate for renaming later.

## Explicitly out of scope

Full-text/cross-content-type search (a flagged shelf item, build only once a real need
shows up). Real-time concurrent multi-editor collaboration on the same entity — whatever
authoring-workflow mechanism `Editor\` eventually settles on is expected to stay
single-writer-at-a-time, never true concurrent editing. EAV — actively rejected after
confirming it's what ACF/WordPress actually do under the hood, and specifically the problem
this whole design exists to avoid. User/admin registration and authentication —
`Persistence\Permission\Actor` is a pure adapter interface over whichever system owns real
user identity; that system, and how it's wired up, isn't designed here. File-byte storage
and reclaim for `MediaAsset` (see "Media/file fields") — the pruning tool's optional
post-prune observer hook needs no structural change to add later, so it's deferred rather
than designed now.
