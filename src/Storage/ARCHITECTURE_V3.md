# Storage/Editor Architecture — v3

Status: a standalone technical description of the system's current, decided shape.
Updated alongside implementation, one decision at a time, so this document never
outpaces the code the way earlier rounds did. A decision recorded here may still be
ahead of the code; where that's true, it's noted inline rather than left implicit.

Scope: the schema-registration, compilation, and caching pieces of the system only.
For everything else (namespaces, the write path, undo, permissions),
ARCHITECTURE_V2.md, CLEANUP_FOR_V2_ROUND17.md and PROPOSAL_FOR_V2_DEFAULT_TRACKING still 
apply, until this document grows to cover them too.

## Three stages

A native PHP class and an editor-created entity both produce the same data shape:
field descriptors, permissions, validators, unique groups, and dense default
values. Two separate producers build this shared `EntityBlueprint`, one per
source; for native, that's `NativeEntityReader`. A third stage, the compiler
(`SchemaCompiler`), consumes a batch of already-built blueprints at once rather
than one at a time: dependency resolution, cycle detection, and registering
every result with the request's `SchemaRegistry`.

`SchemaCompiler::compile()` takes blueprints, never identifiers: it has no way
to turn a bare identifier into a blueprint for every source, since a native one
can always be read via reflection but an editor-created one can't be found
without going to wherever its data actually lives, which the compiler itself
has no business knowing about. Building the blueprint, by whatever means the
source requires, is entirely the caller's job, before compile() is ever called.

`EntitySchema` holds only what gets persisted: identifier, parent, table,
editorExtensible, fields, fieldPermissions, fieldValidators, uniqueGroups,
prototypeValidators. No object factory, no default values of any kind. Those are
the producers' own concern; the compiler reads them off a blueprint, flattens
them into one key per leaf, and writes the result to `SchemaFieldValueStore`
(see below) rather than ever carrying them on `EntitySchema` itself.

A dependency outside the given batch is resolved by checking `SchemaRegistry`
(already compiled, this request or an earlier one, and stored); if it isn't there, that's an error, not a
trigger to compile it on the caller's behalf. Whatever gathers a batch (a
hot-path recompile for native, an admin save for editor) is responsible for
including everything it depends on.

## The registry: memory first, then the store

`SchemaRegistry::get()` returns the schema already in memory this request.
Otherwise it loads it from `SchemaStore`. Otherwise it throws. It never
compiles: the compiler only ever takes blueprints, and only a producer builds
those. `SchemaCompiler` registers every schema it commits, so the rest of the
request is served from memory.

`SchemaStore` keeps one row per schema: the schema as JSON (through
`EntitySchemaCodec`), its own fingerprint, and the fingerprints of everything it
was compiled against. A schema is always read whole, so it's never split into
columns. A validator rule is stored as the identifier its class was registered
under, plus its own `toStorage()` configuration. A value object field's class
is stored as the identifier `ValueObjectRegistry` holds it under. Renaming or
moving either class doesn't break what's stored. The same registry is what
lets the editor offer value objects by name. A compile writes schemas and values in one
transaction, as long as both stores share a connection.

## Compilation happens at different moments per source

Native: no durable "save" moment exists for PHP code. A lightweight hot-path check
(comparing each entity class's declaring file's mtime/hash against what was last
recorded) decides whether a full recompile is needed. On a mismatch, the pipeline
runs live for the changed classes, diffs the result against the last migrated
schema, and either applies a migration automatically or refuses to proceed,
depending on configuration (lockdown vs. best-effort; detail not yet decided).

Editor-created: the admin action that creates or edits an entity type is the only
moment its raw definition exists. The full pipeline runs once, there, and its
result is written straight to the database as the already-compiled schema. Reading
an editor-created schema never re-runs the pipeline.

Either way, the database always holds the last compiled, persisted `EntitySchema`
for every identifier. Live recompilation from source only happens to detect or
resolve drift, never as part of an ordinary read.

## Dependency fingerprints catch stale schemas

A schema's fingerprint hashes its own encoded schema and its own stored values,
never a dependency's. It changes whenever either changes, and only then.

Every stored schema also records the fingerprint of each schema it depends on,
transitively: its parent, its Embed targets, its Reference and OwningReference
targets, every class its default values actually hold, and everything those
depend on in turn. `SchemaRegistry::get()` compares each one against the
dependency's current stored fingerprint. Any mismatch throws
`StaleSchemaException`, naming the dependency. The registry can't recompile, so
whoever gathers batches has to.

Values don't go stale this way: they're resolved live. What does go stale is
what compiling decided. Which values were stored depends on what they'd
otherwise resolve to. A table's columns depend on its Embed targets' fields.
The checks on OwningReference classes depend on those classes' hierarchies.

The dependencies are recorded flat rather than as hashes nested inside hashes,
because OwningReferences can form cycles, and a nested hash can't be computed
around one. Recording them flat still catches a change several levels down: a
leaf records its grandparent directly, even when the parent in between stores
nothing of its own.

Fingerprints are about compiled results. Noticing that a native class's PHP
file changed, so that it needs compiling at all, is a separate hot-path check
(see above; not yet implemented).

## Reading a native class's default values into plain data

`NativeEntityReader` reads a native class's default instance into plain,
dense data in the same reflection pass that builds its field descriptors, rather
than walking the class a second time just for values.

A property is a field only when it carries a field-kind attribute. Any
other property is ignored: never stored, never checked.

An Embed, Reference, OwningReference or ValueObject field's target is the
property's own declared type, never an attribute argument. It must be a single
class, nullable or not: `object`, a builtin or a union is rejected. A wrong
target can't be declared at all, since PHP itself enforces the property type.
A Collection still names its `itemTarget`: `array` says nothing about its
items. Editor-created fields have no property, so their descriptors carry the
target explicitly.

A value object class implements `StorableValue`: `toStorage()` gives the one
string it's stored as, plain or JSON, and `fromStorage()` rebuilds it. Two
value objects are equal when they store as the same string. Its static
`indexable()` says whether that string always fits in 255 characters. The
reader records the answer on the field's descriptor, so changing it is a
schema change like any other. A value object is only ever stored through
`StorableValues::toStorage()`, which throws when an indexable one goes past
255 characters. An over-long default fails at compile time, an over-long
content value before any SQL runs. MySQL would otherwise reject it, or,
without strict mode, silently cut it.

The result is a tree of `ValueNode`s, rooted at the blueprint's `defaultValues`,
an `ObjectNode` of every field the class declares or inherits. An Embed or
OwningReference holding nothing is null in a node's place; a leaf holding
null is a `LeafNode` holding null. Each node class has a `from()` that rejects
any other kind of node, so a malformed blueprint fails instead of being
misread.

- Scalar, Reference: a `LeafNode` of the raw value, never recursed into.
  Reference carries no recursion either way, since it doesn't own what it
  points to.
- ValueObject: a `LeafNode` of the string `toStorage()` gives, never the object
  itself, or of null. Everything after the reader only ever sees that string.
- Embed: an `ObjectNode` of the embedded object's own fields, read through the
  *declared* target class, never the runtime object's actual class. Type
  erasure: a subclass assigned there only contributes the declared class's own
  fields.
- OwningReference: an `OwnedNode` of the referenced object's concrete class and
  an `ObjectNode` of its own fields, read through that *actual* class.
  Polymorphic: the class is recorded because it can be any subclass of the
  declared target.
- Collection: a `CollectionNode` of its items, each following the rule for its
  own item kind, recursively.

## Flattening default values into keys

`SchemaCompiler` walks each class's default values and gives each leaf one key.
The key is a `FieldPath`: the path from the class down to that leaf. It is not
a column of the class. Everything past a `.` lives in another table. Only the
keys whose value isn't defaulted get stored (see below).

- Embed: fields joined with `:`. Example: `chassis:engine:piston:diameter`.
- OwningReference: traversed with `.`. The bare key holds the concrete class,
  or null. An OwningReference has no value of its own there otherwise, and
  unlike Embed its class isn't fixed by the schema. Example: `engine` holds the
  class, `engine.horsepower` and `engine.piston:diameter` hold what the owner
  set on it.
- Collection: the bare key holds the item count. Each item is traversed with
  `.` and its index. Example: `tags` (count), `tags.0`, `wheels.0.size`,
  `engines.0` (class) and `engines.0.horsepower`.

`FieldPath` is built segment by segment, never by joining strings. Its string
form is what gets stored. A `FieldName` never contains `:` or `.` and is never
all digits, so a stored key parses back into a `FieldPath` without the schema.
The separators are the storage format's own, not real column names: `_` would
be ambiguous, since field names contain it. When a real column name is needed,
it's derived from the path (not yet implemented: nothing needs it yet).

`compile()` computes first and commits last. It builds every schema and every
value, and runs every check, without touching `SchemaRegistry` or
`SchemaFieldValueStore`. A lookup of a target's fields reads the batch's own
schemas first, and the registry only for something outside the batch. That
rule lives in `SchemaLookup`, shared by the compiler and the resolver. So a
schema being recompiled is never read from an older registration. Only when
everything has passed does it commit: all values in one transaction, then all
schemas into the registry. A failure at any point leaves both untouched.

## One field value store, not two

Native and editor-created identifiers need the exact same two things: "what's
the effective value of this field right now," and "what did it last resolve to,
so a migration can detect drift." Building two separate mechanisms for this (one
reflection-backed, one override-table-backed) was a mistake made earlier in this
round and corrected: `SchemaFieldValueStore` is the one mechanism for both.

- `save($identifier, $values)` / `saveAll()` / `load($identifier)`: a plain
  `(identifier, FieldPath) -> value` store, one `FieldValues` per identifier
  (built with a `FieldValuesBuilder`, immutable once built), written by the compiler for native
  and directly by an admin save for editor-created. It knows nothing about
  schemas. It remembers what it loaded for the rest of the request, and
  forgets whatever a save overwrites, so resolving through many sources
  doesn't reload the same rows. Only writes through the same instance are
  seen: something else writing to the table mid-request isn't.
- `diff($identifier, $currentValues)`: added/removed/changed against whatever
  was last saved, path for path, not "something in this subtree differs."
  It returns a `FieldValuesDiff`.

## Resolving a value

`FieldValueResolver::resolve($subject, $path)` finds a path's effective
value. A `Subject` is a class, or an instance of one: the class plus values of
its own, held in front of the class. An instance overrides its class the same
way a subclass overrides its parent, so everything below applies to both. It needs schemas, so it lives outside the store, which only reads and
writes rows. It is not a repository concern either: the compiler, migrations
and the editor all need the same answer.

It works through sources (`ValueSource`), checked in order. A source is
either a class's stored values or values given directly, such as an
instance's, under a key prefix. The first source holding the key wins. A
stored null is a value, not a miss. A class's own sources are itself, then
each of its parents, with no prefix. An instance's are its own values, then
its class's. Each step down the path extends them:

- Embed: the same sources under `field:`, then the target's own sources.
- OwningReference: first resolve the bare field from the same sources, which
  gives the class it holds. Then the same sources under `field.`, then that
  class's own sources.
- Collection: the first source holding the bare field owns the collection; its
  value is the item count, and an index must be below it. An item's sources
  are only that owner under `field.index.`, then the item class's own sources:
  an Embed item's target, or the class an owned item holds. Never another
  source's items, so a collection is never inherited item by item.

So for `Car` and `engine.horsepower`: `engine.horsepower` in Car, then in Car's
parents, then `horsepower` in the class `engine` holds, then in that class's
parents. Nothing left to try: throw `NoFieldValueException`.

Two more entry points serve the compiler:
(each works through a `ValueExclusion`: one path, or subtree, skipped in the
subject's own values while walking the sources)
- `resolveFallback($subject, $path)`: what `resolve()` would give if the
  subject's own values held nothing at `$path`. Everything else they hold
  still counts, e.g. the class its OwningReference holds.
- `resolveWithoutSubtree($subject, $path, $subtree)`: the same, with
  nothing held at `$subtree` or under it: a collection's item, with the whole
  collection gone.

`withBatch($schemas, $values)` gives a resolver that reads a batch ahead of the
registry and the store, for a batch being compiled and not committed yet.

## Storing only what isn't defaulted

`SchemaCompiler` stores a value only when it differs from what resolving it
would give anyway. Otherwise a class would pin every inherited value, and never
follow a later change to its parent, its Embed targets, or the classes it owns.

1. Flatten every class's full default values, inherited fields included. For a
   class in the batch, these are its effective values.
2. With a resolver over the batch, keep each value only if
   `resolveFallback()` gives something different, or nothing at all. A root
   class's own fields always have nothing to fall back to, so they're kept.
3. Collections are all or nothing. If the count or any item differs from the
   collection `resolveWithoutSubtree()` gives, the collection is kept whole:
   count, every item's class, every scalar item. Within an item, each of its
   own fields is still kept only if it differs from the item's own class.

## Where content is stored

`LayoutBuilder` reads a compiled schema and works out where each of its own
fields is stored: a `LevelLayout`. `DatabaseSchema` turns level layouts into
the Doctrine schema of every table they need. Neither reads the database.

- `entities`: one row per entity, native or editor-created. `id` (a UUID,
  `CHAR(36)`), `owner`, `owner_field`, `position`, `concrete_identifier`.
  `owner` points at another `entities` row and is `RESTRICT`ed. A row with no
  real owner points at the seeded `Cms` row, the nil UUID.
- One table per inheritance level, named by the schema's `table`. Its `id`
  is the entity's `id`. It copies `owner` and `owner_field`, so a unique index
  can include them. It holds one column per value its own fields store:
  - Scalar and ValueObject: one column.
  - Reference: the target's id, a foreign key to `entities`, `SET NULL`.
  - Embed: its leaves, flattened. The path's `:` becomes `__`:
    `chassis:engine:piston:diameter` is `chassis__engine__piston__diameter`.
  - OwningReference: no column. The owned row is an `entities` row whose
    `owner_field` holds the path, e.g. `engine` or `chassis:spare`.
  - Owned Collection: its items are `entities` rows too. Only the count is a
    column here: `engines_count`.
- One table per other Collection, named after the level's table and the
  path: `car__wheels`. Keyed by `owner` and `position`. The item kind only
  decides the value columns: one `value` column for a Scalar, ValueObject or
  Reference item, the flattened fields for an Embed item. An Embed item can't
  hold an OwningReference or a Collection anywhere in its embedded fields,
  and compiling rejects it. Its only address is its position, which changes
  on every reorder, so nothing nested under it would have a stable key. Use
  an Owned Collection instead: its items have an identity. A singular Embed
  has no such limit, since its path never changes. This could be loosened
  later with a new storage mechanism. Starting strict keeps that door closed
  until it's needed.
- A touched-state side table per level, `car__state`, one row per entity.
  One boolean per path an instance can hold at that level: every value
  column, and the bare path of every OwningReference and Collection. An
  Embed item's fields get the same, in a side table of the Collection's own:
  `car__wheels__state`, keyed by `owner` and `position` like the items.

A Collection is all or nothing. Untouched, it has no rows, and resolves from
defaults. Touched, its rows are the whole Collection. Inside a touched
Collection, an Embed item's fields still follow the item class's default
until set, the same as the compiler stores a class's default Collection. So
the first touch writes every item the current default implies: a field that
differs from the item class is stored and flagged, any other stays null and
keeps following the item class. Scalar, ValueObject and Reference items are
the value themselves, so they're always stored and need no flags.

Every content column is nullable: an untouched value is null and resolves to
its default. Column types follow the field: `VARCHAR(255)` for String, `TEXT`
for Text, `BIGINT` for Int, `DECIMAL(20, 6)` for Decimal, an enum's backing
type for Enum. A ValueObject is `VARCHAR(255)` when `indexable()`, `TEXT`
otherwise.

Every unique group becomes a unique index starting with the scope it applies
to: `owner` and `owner_field` on a level, `owner` on a Collection's table. An
Embed target's groups repeat at every site that embeds it. A group can't
cover a `TEXT` column, an OwningReference or a Collection: MySQL can't index
the first, and the others have no column.

The layout fails rather than letting the database fail later: two values
landing on one column, two tables with one name, or any name longer than
MySQL's 64 characters.

## Identity

An entity's id is a UUID, version 7, made by `EntityIds` without asking the
database. Version 7 ids grow with time, so new rows land at the end of the
primary key rather than at random places in it.

`IdentityMap` is the source of truth for which object is which entity, for
one request. It holds objects weakly, and keeps one object per id: loading the
same entity twice gives the same object. An object it doesn't know has never
been saved or loaded.

A native class may declare one `#[Id]` property, in itself or an ancestor.
The repository fills it on save and on load, so code holding the object can
read its id without asking. It's never a field and never a default: it must
be a nullable string defaulting to null, and it stays null on an Embed or an
entity never saved. A class only embedded or owned simply doesn't declare one.
`IdProperty` checks this, and the reader calls it, so a wrong one fails at
compile time.

`Cms` is the one seeded entity, with the nil UUID as its id. It's its own
owner, so `entities.owner`'s foreign key has a row to point at, and every row
without a real owner points at it. `EntityTables` creates the content tables
on a fresh database and seeds this row. `Cms` is an ordinary native class
otherwise, and declares no fields yet.
