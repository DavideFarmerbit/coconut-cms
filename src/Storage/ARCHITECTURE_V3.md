# Storage/Editor Architecture — v3

Status: a standalone technical description of the system's current, decided shape.
Updated alongside implementation, one decision at a time, so this document never
outpaces the code the way earlier rounds did. A decision recorded here may still be
ahead of the code; where that's true, it's noted inline rather than left implicit.

Scope:

- **Schemas:** reading native classes, compiling them, storing schemas and
  their default values, and catching stale ones.
- **Content:** where an entity's values are stored, identity, and saving,
  loading, deleting, querying and validating native entities, and
  permissions.
- **Migrations:** when a schema changes after content is stored, native and
  editor-created types alike.
- **Booting** and the **databases** supported.

Not covered yet:

- editor-created entities' content, decided in
  `PROPOSAL_FOR_V3_DYNAMIC_ENTITIES.md`;
- namespaces, undo, and permissions on schemas and on history, for which
  `archive/ARCHITECTURE_V2.md` and `archive/CLEANUP_FOR_V2_ROUND17.md` still
  hold the latest design.

Everything in `archive/` is superseded: the v1 and v2 designs, their roadmaps,
and their cleanup rounds. Read it for history, or for an area this document
doesn't cover yet. Where the two disagree, this document wins.

## Three stages

A native PHP class and an editor-created entity both produce the same data shape:
field descriptors, permissions, validators, unique groups, and default
values. Separate producers build this shared `EntityBlueprint`: for native,
`NativeEntityReader`; for a type already stored, whatever its source,
`StoredBlueprintReader` (see "Editor-created types"). A third stage, the compiler
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
prototypeValidators, typePermission. No object factory, no default values of any kind. Those are
the producers' own concern; the compiler reads them off a blueprint, flattens
them into one key per leaf, and writes the result to `SchemaFieldValueStore`
(see below) rather than ever carrying them on `EntitySchema` itself.

A dependency outside the given batch is resolved by checking `SchemaRegistry`
(already compiled, this request or an earlier one, and stored); if it isn't there, that's an error, not a
trigger to compile it on the caller's behalf. Whatever gathers a batch (a
hot-path recompile for native, an admin save for editor) is responsible for
including everything it depends on.

## Native identifiers

Everything stored names an entity type by its identifier, a "namespace:key"
string, never by a PHP class name. A native class declares its own with
`#[Entity(identifier: 'shop:product')]`. Without one, it gets `default:` and
its short name, snake-cased: `BlogPost` is `default:blog_post`.
`NativeIdentifiers` works this out. The CMS's own native entities live in the
`coconut-cms` namespace, as `Cms` does: `coconut-cms:cms`.

So moving a class to another PHP namespace changes nothing stored. Renaming
an implicit one's short name does change its identifier. To rename one
safely, pin its old identifier on it first, then rename the class freely.

A class's table comes from its identifier, unless `#[Entity(table: ...)]`
names one. In the `default` namespace, the table is the key alone:
`default:blog_post` is `blog_post`. In any other namespace, it's the
namespace and the key: `coconut-cms:page` is `coconut_cms_page`. So two
packages' keys never land on one table. A `-` or `.` becomes `_`.

`NativeEntityReader` writes identifiers everywhere a type appears in a
blueprint: its own, its parent's, every Embed, Reference and OwningReference
target, and the type an owned object holds in its default values. Attributes
still name classes (`itemTarget: Engine::class`), so they stay checked by
PHP; the reader turns them into identifiers.

An editor-created type's identifier follows the same form, with the
namespace the admin gives or `default` (see "Editor saves are migrations").
One identifier is one type, whatever its source.

`NativeEntityRegistry` is the way back: which class an identifier stands for.
The native loader and object builder need it to build what's stored. Every
native entity class is registered at boot. Two classes with one identifier
are rejected there, and by `NativeEntityReader::readAll()`, which reads a
batch for `compile()`, keyed by identifier.

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
is stored as the identifier `ValueObjectRegistry` holds it under, and an Enum
field's enum as the one `EnumRegistry` holds it under, along with what it's
backed by. Renaming or moving any of these classes doesn't break what's
stored. The same registries are what let the editor offer value objects and
enums by name. A compile writes schemas and values in one
transaction, as long as both stores share a connection.

## Compilation happens at different moments per source

Native: no durable "save" moment exists for PHP code. A lightweight hot-path check
(comparing each entity class's declaring file's mtime/hash against what was last
recorded) decides whether a full recompile is needed. On a mismatch, the pipeline
runs live for the changed classes, diffs the result against the last migrated
schema, and either applies a migration automatically or refuses to proceed,
depending on configuration (lockdown vs. best-effort; detail not yet decided).

Editor-created: the admin action that creates or edits an entity type runs the
pipeline, as a migration (see "Editor saves are migrations"), and stores the
compiled schema. A native migration compiles every editor-created type again,
from its stored schema and defaults (see "Editor-created types"). Reading an
editor-created schema never runs the pipeline.

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
A Scalar's property must be declared as what its `ScalarType` holds, nullable
or not: `string` for String, Text and Decimal, `int`, `float`, `bool`,
`DateTimeImmutable` for Date and DateTime, a backed enum for Enum. An Enum's
class is that declared type, never an attribute argument. Loading puts stored
values back into the property, so a mismatch would otherwise only fail once
real data is read. A Collection still names its `itemTarget`: `array` says nothing about its
items. Editor-created fields have no property, so their descriptors carry the
target explicitly.

Every field records whether it may hold null, as a `Nullability`: `Nullable`
or `NotNullable`. A native field takes it from its property's type. A
Collection's items declare it as `itemNullability`, since `array` can't say
it. It's required for Scalar, ValueObject and OwningReference items, and
rejected for the rest. A Reference is always `Nullable`, singular or as items:
deleting its target nulls it. So a Reference property must be declared
nullable. An Embed and a Collection are never null, so neither property may be
declared nullable, and an Embed item takes no `itemNullability`. A
`NotNullable` OwningReference is allowed: a car always has an engine. The
descriptor factories take it as a required argument, never a default.

The compiler checks every default value against its field the same way a
migration checks what it writes (see "Checking what's written"). PHP's types
already rule out most mistakes, but never a Collection's items. An
editor-created type has no property at all.

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

- Scalar: a `LeafNode` of the plain value it's stored as, through
  `ScalarValues`: an Enum as its backing value, a Date as `Y-m-d`, a DateTime
  converted to UTC as `Y-m-d H:i:s`, whole seconds, a Decimal with exactly
  six decimal places and no leading zeros, the way MySQL gives a
  `DECIMAL(20, 6)` back. Anything else as is. So two equal values always
  compare equal, wherever they were read from, the database included. A
  Decimal with more than six places fails rather than being rounded.
  It also checks each value's kind: a Collection's items, or a value from
  the editor, are never checked by a property type. The one conversion it
  makes is PHP's own: an int widens to a float for a Float, so `3` and `3.0`
  store the same.
- Reference: a `LeafNode` of the target's id, never recursed into: it doesn't
  own what it points to. In a default instance it must be null for now: how
  a native class would name a default target isn't designed yet.
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
- One table per inheritance level, named by the schema's `table`. Its `_id`
  is the entity's `id`. It copies the entity's owner and owner field as
  `_owner` and `_owner_field`, so a unique index can include them. It holds
  one column per value its own fields store:
  - Scalar and ValueObject: one column.
  - Reference: the target's id, a foreign key to `entities`, `SET NULL`.
  - Embed: its leaves, flattened. The path's `:` becomes `__`:
    `chassis:engine:piston:diameter` is `chassis__engine__piston__diameter`.
  - OwningReference: no column. The owned row is an `entities` row whose
    `owner_field` holds the path, e.g. `engine` or `chassis:spare`.
  - Owned Collection: its items are `entities` rows too. Only the count is a
    column here: `_engines_count`.
- One table per other Collection, named after the level's table and the
  path: `car__wheels`. Keyed by `_owner` and `_position`. The item kind only
  decides the value columns: one `_value` column for a Scalar, ValueObject or
  Reference item, the flattened fields for an Embed item. An Embed item can't
  hold an OwningReference or a Collection anywhere in its embedded fields,
  and compiling rejects it. Its only address is its position, which changes
  on every reorder, so nothing nested under it would have a stable key. Use
  an Owned Collection instead: its items have an identity. A singular Embed
  has no such limit, since its path never changes. This could be loosened
  later with a new storage mechanism. Starting strict keeps that door closed
  until it's needed.
- A touched-state side table per level, `_state__car`, one row per entity.
  One boolean per path an instance can hold at that level: every value
  column, and the bare path of every OwningReference and Collection. An
  Embed item's fields get the same, in a side table of the Collection's own:
  `_state__car__wheels`, keyed by `_owner` and `_position` like the items.

System names never collide with field names. A field name never starts with
`_` and never holds `__`, and a type's own table never starts with `_`. Every
name the system adds to a table holding fields starts with `_`: `_id`,
`_owner`, `_owner_field`, `_position`, `_value`, `_<collection>_count`, and
the state tables, `_state__<table>`. The `entities` table holds no fields, so
its columns keep plain names. Without this, a field named `owner`, a
Collection named `state`, or a field named `spares_count` next to an Owned
Collection `spares` would each land on a name already taken.

A Collection is touched or not as a whole. Its rows are always every item,
its default's while it's untouched (see "Content is dense"). Inside a
touched Collection, an Embed item's fields still follow the item class's
default until set, the same as the compiler stores a class's default
Collection: a field that differs from the item class is flagged, any other
isn't, and both are stored. Scalar, ValueObject and Reference items are the
value themselves, so they need no flags of their own.

Every value column is nullable, whatever its field: a migration's move
rewrites rows whole in the new shape, giving no value to a column on its way
out, and old and new shapes share a table while it runs. Making a column
`NOT NULL` where its field is would mean temporary defaults through every
migration, and MySQL gives a `TEXT` column none: too fragile for a guarantee
the code already gives. A field that can't hold null never does: a native
save reads typed properties, and a migration checks every value it writes.
Column types follow the field: `VARCHAR(255)` for String, `TEXT`
for Text, `BIGINT` for Int, `DECIMAL(20, 6)` for Decimal, an enum's backing
type for Enum. A ValueObject is `VARCHAR(255)` when `indexable()`, `TEXT`
otherwise.

Every unique group becomes a unique index starting with the scope it applies
to: `_owner` and `_owner_field` on a level, `_owner` on a Collection's table. An
Embed target's groups repeat at every site that embeds it. A group can't
cover a `TEXT` column, an OwningReference or a Collection: MySQL can't index
the first, and the others have no column.

The layout fails rather than letting the database fail later: two values
landing on one column, two tables with one name, or any name longer than 58
characters: MySQL allows 64, less the six a migration may prefix a name with
(see "How data moves"). With the naming rule above, the first can't happen
any more, and the second only through an explicit table name. A migration
lays out every schema from code before anything else, a fresh install
included, so a collision fails it before any DDL runs, naming the type. The
compiler doesn't check this: the schema side never depends on the layout.

## Content is dense

Every row holds every effective value: a touched value, and an untouched one
too, as its default gives it. The state tables still say which is which: a
flag set means touched, pinned; clear means the value follows its default.

This replaces sparse content, where an untouched value was null and resolved
live. Sparse content followed a changed default for free, which mattered
while nothing noticed a default changing. Now every change is noticed: a
native default changes a fingerprint, so a migration runs, and an
editor-created one changes through the editor. So a changed default rewrites
the untouched values instead, and the database always holds what a load
shows. In exchange:

- A query filters and sorts on real columns, with indexes. It never needs to
  know about defaults.
- A unique group covers effective values: two entities holding the same
  value collide, touched or not. Sparse content let two untouched ones share
  a default unseen, since a unique index ignores null. So a unique field with
  a non-null default lets only one entity keep that default. A field meant to
  be optional but unique is nullable and defaults to null: a unique index
  ignores nulls.
- A load reads rows; nothing resolves defaults on the way.
- What the database holds reads plainly, in SQL or in a database tool.

Nothing is virtual any more. An untouched owned object is stored like any
other: an `entities` row with an id, and its level rows. An untouched
Collection's default items are stored as rows. So every car has its engine
stored from its first save, flagged untouched.

A changed default rewrites what follows it:

- an untouched value: set to the new default;
- an untouched owned slot: its object rewritten as the new default gives it.
  Still the same class, it's the same object: it keeps its id. Another class
  makes it another object, as assigning one does on a save: the old one is
  deleted, the new one gets a new id, and a Reference to the old one becomes
  null;
- an untouched Collection: its items replaced by the new default's;
- inside a touched Collection, an Embed item's untouched field: set to the
  item class's new default.

For a native type, the migration does it: a changed default changes the
type's fingerprint, so the migration moves every aggregate holding the type,
with or without a fixup, and writes it with the new defaults. A default is still never a
difference: it needs no step under any policy. For an editor-created type,
changing a default will be a migration of that type (not built yet). A
default changed any other way, behind the migration's back, isn't seen: a
load shows what's stored.

How it's built:

- `EntityRowsBuilder` writes every value, and every owned object and
  Collection item, touched or not, from the entity's dense values; the flags
  come from its touched ones. An owned object back at its defaults keeps its
  rows and its id, flagged untouched.
- `EntityRowsReader` reads every stored value, and the touched ones apart.
  `EntityReader` builds the tree from the stored values: every path holds a
  value, so nothing falls back to a default. `AggregateReader` takes the
  stored values as they are: they're each value's effective one under the
  old defaults.
- On a move, an owned object keeps its id while it holds the same class.
  One whose class changed gets a new id, and so does everything it owns.

## Identity

An entity's id is a UUID, version 7, made by `EntityIds` without asking the
database. Version 7 ids grow with time, so new rows land at the end of the
primary key rather than at random places in it. Within one process each id
is greater than the one before, even in the same millisecond: the 12 bits
after the version count the ids of a millisecond (RFC 9562's counter
method). So sorting by id gives creation order; across processes, only to the
millisecond.

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

## Reading an entity's values

`NativeValueReader` reads any native object into a tree of `ValueNode`s: the
reader does it for a default instance, content for a live entity. Then
`ValueFlattener` turns the tree into dense `FieldValues`, and `Overrides`
keeps only what a `Subject` changes from what it would otherwise resolve to.
The compiler runs `Overrides` on a class, against its parents and targets.
`NativeEntityValues` runs it on an instance, against its class.

So an entity's touched values follow the exact rules a class's stored values
do. A value is touched when it differs from what the class gives, decided by
comparing, never by tracking writes. Set back to the class's value, it's
untouched again. Owned objects are part of the entity's values, under their
paths, and compared against what the class pins on them. A Collection is
touched whole, and an Embed or owned item's fields only where they differ
from the item's class.

A Reference stores its target's id, from the `IdentityMap`. A target that was
never saved has none, and fails: it's never saved along on the side.

## From values to rows

`EntityRowsBuilder` turns an entity's `EntityValues` into every row it should
be stored as, and every row of what it owns. It's plain data: writing it is
`EntityWriter`'s job.

- The entity's `entities` row, then each level's row and state row, root
  level first. A value lands on the level whose class declares its field,
  touched or not. Touched, it's flagged; untouched, it isn't.
- A Collection gets all its rows, touched or not: one per item, plus one
  state row per Embed item.
- An owned object gets rows of its own, touched or not. Its `owner` is the
  entity owning it directly, `owner_field` the path it's held at, `position`
  its index in an Owned Collection or -1. An empty slot gets no row. Its path
  is flagged touched once anything at or under it is, its class included:
  from then on it stops following the default, even if its class still
  matches it, the same as any other value set by the instance.

Each `Row` says how to bind its values, from their own PHP kind: PDO would
otherwise send `false` as an empty string, which no boolean column accepts.

## Loading an entity

Loading is split in two. `EntityReader` reads any entity, native or
editor-created, and never touches a PHP class: `EntityRowsReader` reads its
rows into its values (each back in its stored form: MySQL gives a boolean
back as `0` or `1`), with an owned object's values from its own rows, under
its path. The resolver then shapes them into a tree on
`Subject::ofInstance()`; content is dense, so every path holds a value and
nothing falls back to a default. It returns a `LoadedEntity`: that tree, the
ids of its owned objects, and the ids its References hold.

`NativeEntityLoader` builds a PHP object from a `LoadedEntity`. Everything
native lives in `Entity/Native/`, so the line between the shared pipeline and
what only native classes need stays visible. Shared code names an entity
type by its identifier, never as a class: an editor-created one isn't one.

`NativeObjectBuilder` builds the object from that tree the way Unreal copies
a class default object. Each class's default instance is built once, then
for every object a clone of it gives the non-field state, and every field is
set from the tree. So a constructor runs once per class, not once per load.
A non-field object every instance must own a copy of is copied by the class's
own `__clone()`. A field is set exactly once, so a readonly one works too.

Owned objects load with their owner, each with its id, touched or not. A
Reference loads as a lazy ghost of its target's concrete class, looked up for
every referenced id in one query, since a ghost must be created as its final
class. Its fields load when first read; its `#[Id]` is readable before that.

A Reference may point at any entity: a standalone one, an owned object of its
own aggregate, or an owned object of another aggregate. It's a Reference, so
it reaches its target as itself: through it, the target loads on its own,
with its own type's rules. Editing the target through a Reference saves
nothing when the holder is saved; its owner saves it. The Reference becomes
null whenever the target is gone: its owner replaced it or was deleted, or a
migration dropped its type. That's what the foreign key does for any target.

`load()` takes a standalone entity's id only. An owned object is reached
through its owner, or through a Reference; loading its id throws.

An editor-created entity loads as its last native class: its native view.
`NativeViewClasses` finds it: the type's own class for a native type; for
an editor-created one, the nearest native ancestor's, up its stored
parents. The loader builds that class for the entity loaded, for every
owned object, and for every Reference's ghost. An `EditorEngine` held in an
`Engine` property loads as an `Engine`, or as the native subclass it
extends. Its values resolve on its real type, so a default the
editor-created type gives a native field is the view's value. The
`IdentityMap` keeps each entity's real type next to its id. An entity whose
type has no native ancestor has no native view: `load()` of it throws, and
names `loadDynamic()`.

Saving a native view keeps what its class can't see: see "Saving an
entity".

## Dynamic entities

`loadDynamic(id)` gives any standalone entity whole, whatever its type's
source, as a `DynamicEntity`: what code wanting a whole entity works with.
`load()` gives native code its native view; this gives the rest too.

A `DynamicEntity` has two parts, and each field is held by one of them:

- its native view, the object of its last native class: the very object
  `load(id)` gives. A field that class declares is read from it, live;
- its bag: every other field, those of its editor-created levels, in
  stored form.

A type with no native ancestor has no view, only a bag. A native type's
bag is empty.

There's one `DynamicEntity` per entity in a session: one per native view
object, one per id for an entity with none. So native code and generic
code editing the same entity see each other's changes.

A `DynamicEntity` is an `EntityView`: `type()`, `isA()`, `has()`, `get()`,
and its `id()` and `view()`. `get()` gives a value as a property holds it.
An Embed or an owned object comes back as the `DynamicEntity` standing for
it, one per object, wherever it's held:

- in the view: the native object's own `DynamicEntity`, its bag from what
  was read for its id. An `EditorEngine` in a native `engine` field is the
  `BoatEngine` the view holds, and its editor-created fields;
- in the bag: one made from what's stored there, with a native view of
  its own when its type has a native ancestor. The loader builds it as it
  builds any object, so ids, Reference ghosts and hidden fields behave
  alike.

A field the reader may not read throws `PermissionDenied` on `get()`;
`has()` answers false for it. `loadDynamic()` of an owned entity throws:
it's reached through its owner. `DynamicEntities` makes them for the
session.

`set(path, value)` takes a value in the form `get()` gives: an Embed or an
owned object as an `EntityView`, a `DynamicEntity` or a native object; a
Reference as its target, in either form. `DynamicValues` checks it where
it's set, the way PHP checks a typed property: its kind, its scalar or
enum type, whether it may be null, and that an entity is of the field's
target type or one extending it, a Reference's target saved already. A
wrong value throws `InvalidValue`, naming the path. Then:

- a field the view's class declares is set on the view, through its
  `#[Setter]` if it names one, called from the declaring class's scope
  (`Setters`). A setter refusing it is a `ValidationFailed` at the path;
- any other goes into the bag.

A path into an Embed, an owned object or a Collection's item sets it
there; an error there names the path `set()` was given. An owned object
set elsewhere is moved as itself, its id with it. Every path set is
remembered, by each entity from itself (`changed()`): what `afterEdit()`
will be told. Nothing is saved by `set()`.

`save()` takes a `DynamicEntity` as it takes a native object, and saves
it as its real type, with everything it owns (`DynamicEntityWriter`):

1. `afterEdit()` runs first, on the view of every entity of the aggregate
   whose `set()` changed it, the deepest first, each told the paths set on
   it, as an edit by path runs it. A refusal is a `ValidationFailed` at
   that entity's path, and nothing is written.
2. The aggregate is locked, and the values read: the view's fields from
   the view, the bag's from the bag, back in stored form. An owned object a
   view holds takes its other fields from the bag of the `DynamicEntity`
   standing for it, edits included; one nobody stood for keeps them as
   stored, by id, as a native view's save does. An owned object a bag
   holds is written from its `DynamicEntity`, its own view included.
3. The values are compared with the real type's defaults, and written as
   any save is.

Ids are handed out and remembered once the write succeeded: a
`DynamicEntity` takes its id, its view goes in the `IdentityMap` with it,
and the paths set are forgotten. Changes made on a view by native code are
saved too, but `afterEdit()` is only told what `set()` changed.

`newDynamic(type)` makes a new entity of any type, native or
editor-created, as a `DynamicEntity`. Its lifetime is a `new Car()`'s: no
id, and unknown to the `IdentityMap`, until its first save gives it one;
from then on it's the one object for its id. Dropped unsaved, nothing was
stored. Its values are its type's defaults, resolved on its real type, and
untouched until set. Its native view is built the way loading builds one:
a clone of the class's default instance, then every field set from those
defaults, so a default the editor-created type gives a native field is the
view's value. The constructor doesn't run per object. The objects it owns
are new too: their real type is kept without an id
(`IdentityMap::rememberType()`), and their first save fills what their
class can't see from their real type's defaults.

The API edits every entity through a `DynamicEntity`, native types
included: `StoredFormEditor` turns each change, a path and a value in
stored form, into its PHP form and calls `set()`; `create()`, `update()`
and `deleteById()` on a session make, load and save one. So
`entities.create`, `update` and `delete` work on editor-created entities
as on native ones.

The `IdentityMap` keeps one object per id, however the entity was reached:
loading an id twice, or reaching it through two References, or through a
Reference and through its owner, gives the same object. When a Reference
reached an owned object first, its owner's load reuses that object: a ghost
not used yet is filled from the owner's rows; one already in use is kept as
it is, the way loading a known entity gives it back.

## Saving an entity

Saving is split the same way as loading. `EntityWriter` writes any entity by
id, from its `EntityValues`. `NativeEntityWriter` reads a native object into
those values, hands out ids, and keeps the `IdentityMap` and each `#[Id]` in
step with what's stored.

A save writes a whole aggregate: an entity and everything it owns, in one
transaction. An owned entity is saved and deleted only through its owner. A
save or delete given one that's stored as owned fails. One never stored has
nothing marking it as owned, so it's saved standalone; its owner's next save
then fails, as below.

An owned object belongs to one aggregate at a time. A save that would insert
an id already stored elsewhere fails: the object is still stored in another
aggregate. Saving the aggregate it left first deletes it there, and it gets a
new id in its new one.

Saves and deletes of one aggregate run one at a time. Each locks the root's
`entities` row first, then reads the aggregate. A second one waits, then sees
what the first committed. The writer's transactions are read committed: the
lock on a root not stored yet then locks no gap, so two first saves never
block each other.

A save reconciles what's stored with what's wanted now:

1. A recursive query on `entities.owner` finds everything stored under the
   entity, at any depth. A standalone entity's row points at `Cms` through no
   field, so only a row with an `owner_field` counts as owned.
2. Every entity kept loses its level rows. Its side table and Collection rows
   go with them, by cascade. So every content row is rewritten whole, and a
   unique value moving from one row to another never collides with itself.
3. Each wanted entity's `entities` row is inserted or updated, owners first.
   An owned object moved to a new owner in the same aggregate keeps its id,
   and stops pointing at its old owner before that one is deleted.
4. Every entity no longer wanted is deleted, deepest first, since
   `entities.owner` restricts deleting an owner. This covers an owned object
   replaced by another, and one dropped from an Owned Collection. One back at
   its defaults is still wanted: it keeps its rows and its id, unflagged.
5. Every wanted level, side table and Collection row is inserted.

An owned object keeps its id while it's the same object, from the
`IdentityMap`. A new object gets a new id, even in the same place.
`NativeValueReader` tells the native writer which object sits at each owned
path, the same way `NativeObjectBuilder` does when building one.

A native view, an editor-created entity loaded as its last native class,
is saved as its real type. An aggregate holding one is written with
`EntityWriter::writeWith()`, which locks the aggregate, reads what's
stored, and only then asks for the values. `UnseenValues` completes each
entity whose real type goes deeper than its object's class: every field
the class can't see comes from what's stored for that entity's id, with
the owned objects under it and their ids, at any depth. An entity is
found by its id, not its place: one moved keeps those fields. One replaced
by a new object is a new entity, and the old one goes with what it held.
An entity not stored any more takes them from its real type's defaults.
Then the values are flattened and compared with the real type as for any
save, so a default the editor-created type gives a native field isn't
touched, and `concrete_identifier` keeps the real type. An aggregate with
no view is written as before, without the extra read.

The `IdentityMap` and `#[Id]` properties change only once the transaction
commits. Every object written is remembered with its id. Every entity deleted
is forgotten, and its object, if still around, has its `#[Id]` set back to
null. A failed save leaves both as they were.

Deleting an entity deletes everything it owns, deepest first. A Reference to
it is set to null by its foreign key, and loads as null.

The foreign key only reaches rows that exist. A save deletes what its
aggregate no longer owns before it inserts the aggregate's new rows, so a
Reference in those rows to an entity just deleted would fail its foreign key.
The writer writes it null instead: the same rule, applied by the code inside
the aggregate it holds. The foreign key applies it to every other aggregate,
which the writer never touches. A migration's `DropType` relies on the same
rule.

## Validating a save

Every save checks the rules its aggregate's types declare before it writes
anything. A broken rule throws `ValidationFailed`, which holds every error by
the path it's about: `name`, `chassis:weight`, `spares.1.horsepower`, or `""`
for the entity itself. So a form can show them all at once. Nothing is
written. A migration's own writes check nothing: content breaking a new rule
is caught on its next save.

What's checked is the whole aggregate, every value, touched or not: it's
what's stored. "`name` is required" with a default of `''` refuses a car saved
without a name.

- Each type's prototype rules run on its entity, each field's rules on its
  value. An Embed, an owned object and every Collection item are checked the
  same way, with their own types' rules: a rule on `Chassis.weight` applies to
  every car's chassis.
- A field rule may sit on any field kind; its `checkUsage()` refuses a field
  it doesn't fit. It receives the value as a property holds it: a scalar, an
  enum case, a `DateTimeImmutable`, a value object. An Embed or an owned
  object comes as an `EntityView`, an empty slot as null. On a Collection it
  runs once per item. So a rule on an owned slot can narrow the classes it
  takes, `$view->isA(...)`, and one on an Embed can check its fields
  together, at that site only.
- A prototype rule receives an `EntityView` of its entity.
- A Reference field reads as its target's id: checking the target's fields
  would mean loading it during the save.

`EntityView` is the read-only view of an entity's values, whatever its type's
source: `type()`, `isA()`, `has()`, `get(path)`. `get()` gives values as a
property holds them, an Embed or owned object as another `EntityView`, and
reaches through them: `get('engine.horsepower')`. During a save it's an
`EntitySnapshot` of the values about to be written. `DynamicEntity` will
implement it too (see `PROPOSAL_FOR_V3_DYNAMIC_ENTITIES.md`), so a rule written
once works on both.

A unique group is still the database's to enforce: nothing can race it. When
the database refuses a save, the writer works out which group was broken, so
the error names fields, not an index. A group is broken when another entity
stores the same values, the aggregate's own entities left out, or when two
rows of the save itself match, two items of one Collection. Each field of the
group gets the error, under its full path. One the writer can't explain is
rethrown as it came.

## Permissions

Every load, save, delete and query has an actor. `$persistence->as($actor)`
gives a `PersistenceSession` for it, and only a session loads, saves,
deletes and queries. There is no default actor. An `Actor` answers one
question: `hasRole($role)`. `SystemActor` holds every role. It is for code
the site trusts, such as a deploy command or an import, and never for a
request's user.

```php
$session = $persistence->as($currentUser);
$car = $session->load($id);
$session->save($car);
$session->query(Car::class)->where('price', Is::lessThan(100))->page();
```

Permissions come in two layers. A type's permission decides what an actor may
do to the type's entities at all. A field's permission then narrows what it
may read and write, field by field. An action must pass both. A missing role
means everyone, in both layers.

### Type permissions

A type declares a role per action with `#[TypePermission(read: 'staff',
create: 'editor', update: 'editor', delete: 'admin')]`:

- `read`: loading one, querying the type.
- `create`: saving a new one.
- `update`: saving a stored one with any value changed. A save that changes
  nothing needs no right.
- `delete`: deleting one, with everything it owns.

A refused action throws `PermissionDenied`, which names the type and the
action. Nothing is read or written.

A subtype may declare its own `#[TypePermission]`. Every declaration from the
type up to its root must hold. So a subtype can only add restrictions: a
`Truck` can require `fleet` on top of what `Vehicle` requires, but it can't
open up what `Vehicle` closes. One role per action is enough: "editor or
admin" is the role system's job, since `hasRole()` can answer true for an
admin asked about `editor`.

An owned object reached through its owner follows the root of its
aggregate. Only the root's type permission applies: reading, updating or
deleting a car covers its engine. A stricter rule inside an aggregate is a
field permission: on the owning field (`#[OwningReference,
FieldPermission(write: 'mechanic')] $engine`), or on the owned type's fields.

An entity reached through a Reference is judged as itself: its own type's
permission and its own fields' permissions apply, owned or not. So an
`Engine` type's own permission applies to an engine saved on its own, and to
any engine reached through a Reference. One engine can give two answers:
through its car, the car decides; through a garage's Reference, `Engine`
decides. A type that is both owned and referenced needs its own
`#[TypePermission]` set with that in mind.

A Reference is an accessor. It reaches its target even where the owner hides
it: a garage pointing at an engine in an admin-only field of a car shows that
engine to whoever may read the garage and engines. Making that link is a
choice of whoever made it. In the native API, an actor can only link an
object it holds, and a hidden field never loads, so it can't link what it
can't see. An API that sets a Reference by id must check, on save, that the
actor may read a new target where it lives: its root's type and every field
on the path to it (required there; no such API exists yet).

A Reference to an entity the actor may not read loads as a ghost. Its id can
be read. Any other use throws `PermissionDenied`. Saving the entity holding
the Reference writes it back unchanged, since a save only needs the id. The
actor may still point the field elsewhere, if it may write the field.
`load()` of that entity throws `PermissionDenied`. A null would be worse: it
means "no target", and a save would then erase the Reference.

One entity is one object per session, whichever route reached it. When the
actor reaches a ghost's target through its owner too, the ghost is filled
from the owner and becomes readable: the actor could read it there anyway.

Querying:

- A query on a type the actor may not read throws `PermissionDenied`.
- A subtype it may not read is left out, as if it had no entities. `count()`
  and pages agree, and every item listed is one it can load. Refusing the
  query instead would break every `Vehicle` list the day a restricted subtype
  is added.
- A path past a Reference to a type it may not read fails like an unknown
  field.
- Past a Reference to a type it may read, a target of a subtype it may not
  read joins as missing: every field past it reads null. A condition on it
  would otherwise show values the actor can't load.
- Past a Reference, the target's own type decides, owned or not, the same
  as when loading through it.

Finding the subtypes reads every stored schema once per session. That's
acceptable for now; anything faster is performance work (not built yet).

### Field permissions

A field declares its roles with `#[FieldPermission(read: 'admin', write:
'editor')]`. The type that declares the field holds its permission, and a
subtype can't change it. A permission on an Embed's field or an owned type's
field applies wherever that type is used.

Reading:

- A field the actor may not read is left unset on load. Reading it throws
  PHP's own error for an uninitialized property. So a template can't show it
  by accident, and `isset()` tells the code it's hidden.
- The session keeps the stored value it hid. A save writes it back as it
  was. An owned object inside a hidden field keeps its id, so a save by an
  actor who can't see it never replaces it.
- A readonly property can't have a read permission: a readonly property can't
  stay unset. Reading the class fails, saying so.
- Each session has its own objects. One object per id holds within a session,
  not across two: an object loaded as one actor never shows up in another
  actor's session with hidden fields filled in.

Writing:

- A save changing a field the actor may not write throws `PermissionDenied`,
  with every such field's path. Nothing is written.
- "Changing" means a value differs from what's stored. For a new entity, it
  means a value differs from its defaults. So an actor may save an entity
  that holds a field it can't write, as long as the field keeps its value.
- The check runs inside the write's transaction, after the aggregate is
  locked: it compares with the stored values that the write replaces.
- A field the actor may not write is compared whole, with everything under
  it. A field it may write is compared field by field inside, through Embeds,
  owned objects and Collection items.

Validators run as the system. They see every value, hidden or not, since a
rule must hold whoever saves. A refused rule names its path, which may be a
field the actor can't read.

Querying: a path through a field the actor may not read fails the same way as
a field that doesn't exist, at every step. It fails in a condition, a sort, a
Collection condition, and inside an `Item`. A condition on a hidden field
would show its value through which entities match, or in what order.

Permissions on a schema or on history are not built yet.

## Querying

`PersistenceSession::query()` lists a type's entities, filtered and sorted, a
page at a time:

```php
$page = $session->query(Car::class)
    ->where('maker', Is::equalTo($acme))
    ->where('chassis:weight', Is::greaterThan(1000))
    ->orderBy('price', Order::Descending)
    ->limit(20)
    ->after($cursor)          // the previous page's nextCursor; null for the first
    ->page();                 // Page: items, nextCursor (null on the last page)

$session->query(Car::class)->where(...)->count();
```

A `Query` is immutable: each call gives a new one. Nothing runs until
`page()`, `ids()` or `count()`, which throw `PersistenceUnavailable` the way
`load()` does.

What a query lists:

- The queried type's entities and its subtypes', each loaded as its own
  class.
- Standalone entities only. An owned entity is saved and deleted through its
  owner, and listed through it too: a query on a type only ever owned lists
  nothing.

How it names fields: by path, the way migrations do. `price`, and
`chassis:weight` into an Embed. Only fields of the queried type and its
ancestors: a `Vehicle` query can't filter on a field only `Car` declares. A
path leading to no value fails at once, saying why: a typo, a subtype's
field, an owned object itself, a Collection (see "Conditions on a
Collection").

A path steps through a singular relationship with `.`: `maker.name` through
a Reference, `engine.horsepower` through an owned object, as many steps as
it takes (`maker.country.name`, `engine.turbo.boost`, `chassis:maker.name`).
Past a step, the same rule holds: only the declared target's fields and its
ancestors'. Each step is a `LEFT JOIN`, made once however many conditions and
sorts use it: a Reference on its column, an owned object through its
`entities` row by owner, owner field and position. So an entity whose
relationship is empty still counts, and every field past it reads as null:
`Is::null()` matches it, and it sorts lowest.

Conditions are objects, never strings: `Is::equalTo()`, `Is::notEqualTo()`,
`Is::greaterThan()`, `Is::greaterThanOrEqualTo()`, `Is::lessThan()`,
`Is::lessThanOrEqualTo()`, `Is::in()`, `Is::null()`, `Is::notNull()`,
`Is::like()`. Every `where()` must hold. A value is given as the field's
property holds it: an enum case, a `DateTimeImmutable`, a value object, a saved
entity for a Reference. It's converted to stored form the way a save converts
it, so a query and a save never disagree; a value of the wrong type fails,
naming the path. Null is never a value: `Is::null()` asks for it.
`Is::notEqualTo()` matches a null too, as PHP's `null != $value` does. An
order condition applies only to a value with an order: a string, a number, a
date. `Is::like()` applies only to a String or Text.

Content is dense, so every column holds what a load shows: a condition is SQL
on a real column, and an index serves it. The query joins the `entities` row
with the queried type's level tables, root first. Joining the type's own
level table keeps exactly its entities and its subtypes'.

Pages are keyset pages, never `OFFSET`. `page()` needs a `limit()`, so a query
never loads everything by accident. It fetches one more entity than a page:
whether it's there says whether another page follows. The cursor holds the
last entity's sort values and its id, which breaks ties, and the next page
starts strictly after them. So a page never skips or repeats an entity, even
through ties or while entities are added. A cursor remembers the ordering it
came from: a query sorted any other way refuses it. Ties go by id: creation
order. A null sorts lowest, first ascending and last descending, as both
MySQL and SQLite sort it; a cursor compares with a null-safe equality, which
each platform writes its own way.

`count()` uses the same conditions, whatever the limit and cursor. `ids()`
gives the ids, in order, nothing loaded.

Loading a page loads each entity on its own, several queries each: fine for a
page of 20. Loading a page in one batch is performance work (not built yet).

### Conditions on a Collection

```php
$session->query(Car::class)
    ->whereHas('spares', Item::where('horsepower', Is::greaterThan(200))->andWhere('turbo.boost', Is::equalTo(5)))
    ->whereHas('tags', Item::is(Is::equalTo('red')))
    ->whereDoesntHave('wheels', Item::where('size', Is::lessThan(16)))
    ->whereCount('tags', Is::greaterThanOrEqualTo(3));
```

`whereHas()` keeps an entity with at least one item meeting an `Item`;
`whereDoesntHave()` keeps one with none. With no `Item`, any item counts: an
empty Collection meets `whereDoesntHave()`. An `Item` is built the way a
query is, never with a closure. Every condition in one `Item` holds for the
same item; two `whereHas()` may be met by two different items.

An `Item`'s paths are the item's own: an Embed item's fields, an owned item's,
a Reference item's target's, each able to step on through a relationship
(`turbo.boost`). An item that is a value, a Scalar, a ValueObject or a
Reference, is tested itself with `Item::is()`. An owned item's own
Collections can be asked about too: `Item::any()->whereHas('parts', ...)`.

Each is a correlated `EXISTS` or `NOT EXISTS` over the items' rows: a
Collection's table, or for an Owned Collection the `entities` rows it owns at
that path. Content is dense, so a Collection still at its default has its
items stored, and they count. An Owned Collection's empty slot has no row:
there's no item there to meet anything.

`whereCount()` compares a Collection's length as a load shows it, the same as
PHP's `count()`: an Owned Collection's empty slots count. An Owned Collection
stores its length in a column; any other Collection is counted.

Sorting by something inside a Collection isn't supported, on purpose: an
entity has many items, and none of them is "its" value to sort by.

## Migrations

A migration brings stored content in line with schemas that changed after it
was stored. `Migrator::migrate()` runs one, from the native classes read with
`NativeEntityReader::readAll()` and every declared `MigrationSteps`; a fresh
database is a migration from nothing. One migration runs at a time: each
holds a MySQL lock.

**Built**, except where noted inline. Decided but not built yet: editor
saves, with the editor operations that resolve `NoType` records.

A change to the CMS's own tables or system columns (`_id`, `_owner`,
`_position`, a count) isn't migrated. Expand would take a changed system
column for a field's, move it aside, and fail. While nothing but development
databases exist, such a change means resetting them. How to migrate one is
decided once a real database needs it.

### What a change costs

Content is dense: every value is stored, flagged when touched. So every
change that alters what's stored rewrites it:

- An added type needs nothing: it has no content yet.
- An added field, Collection or owned field gets new columns, tables or
  flags, filled with its default on every entity, unflagged.
- A changed default rewrites every value following it.
- A removed field or type, a rename, a retype, and a reparent or Embed
  target change move or drop what's stored.

All of these happen the same way: the aggregate is read, the fixups run, and
it's written whole with the new schemas and defaults.

A migration moves only the aggregates a change affects. An editor save is a
migration too, so it must not rewrite the whole database for one field. A
stored type is changed when:

- its compiled schema or stored defaults changed, or it's gone: its own
  fingerprint differs, or it's no longer compiled;
- anything it depends on changed: its parent, an Embed target, a target it
  owns or references. A stored type records the fingerprint of everything it
  depends on, transitively, and every type is compiled again, editor-created
  ones too, so a change anywhere below shows in its own record;
- a pending fixup moves the values of it, or of a type it depends on
  (`movedTypes()`), even with no schema changing: a step can move data
  alone. A fixup moves values wherever its type lives, in its subtypes and
  in every type holding it, and each of those depends on it. A type holding
  it only through a Reference moves too: a harmless superset.

The aggregates that move are those holding an entity of a changed type: as
the root, or owned at any depth, found by walking up from each such entity
to its root. That finds a subtype held where only its parent is declared: a
car holding a turbo engine moves when only the turbo changed. A type held
through an Embed has no entity of its own; its holder depends on it, so the
holder is changed too.

### Policies

A migration policy decides what happens to a difference no fixup declares:

- **Strict**, the default: every difference but an addition must be declared.
  Otherwise the migration throws, and the site stays blocked until it's fixed.
  An addition may still be declared, to give existing entities a value other
  than the default.
- **Developer**: a difference that can't lose stored data applies by itself.
  One that can must still be declared, or the migration throws.
- **Unsafe**: everything applies as best effort. An undeclared rename becomes
  a drop and an add; an undeclared retype resets to the default; a field that
  can no longer hold null loses its nulls.

A policy turns each difference left unexplained into the fixup that resolves
it, or into nothing, which blocks the migration. Those fixups run after the
declared steps, and nothing may differ afterwards:

- strict resolves the three additions: a type, a field, a unique group;
- developer adds every lossless change: a table moved, a unique group dropped,
  and a parent changed only by inserting levels, the new chain holding the
  whole old one;
- unsafe resolves everything: a removal is dropped, a change is reset, and a
  rename is a drop and an add. A change that only stops a field, or its
  items, holding null gets `MakeNotNullable` instead of a reset: only the
  nulls go. A field changing in another way too is reset, as before.

Other policies implement the same interface. Boot code passes the policy as an
object, and strict applies when it passes none. So a looser policy is always a
visible line of code, never a setting someone can leave on in production. The
same policy applies to an editor save.

The same change always follows the same rule, whatever the stored content
holds: a removed field nobody ever touched still needs its declaration.
Content can still make a declared change fail. An added unique group over
duplicate touched values, or a converter whose output doesn't fit the new
field, fails the migration before anything is written.

### What isn't a difference

Nothing stored depends on these, so none of them is a difference under any
policy:

- a changed default;
- changed validators or permissions: content breaking a new validator is
  caught on its next save;
- a field, or its items, becoming `Nullable`: every value stored stays valid.

A changed `editorExtensible` is a difference only where an editor-created
type extends the native type: it breaks that type (see "When native code
breaks an editor-created type").

The opposite, a field that can no longer hold null, is a difference: a stored
null would break. It needs `RetypeField::converting`, or `MakeNotNullable`
declared or chosen by the unsafe policy. The developer policy never applies
it by itself: it can lose data, even where no null is stored.

### Defaults normalize in the migration

A changed default needs no declaration under any policy. The migration moves
every aggregate holding the type, and compares each touched value with its
new default the way a save does: one that now equals it becomes untouched,
and follows the default from then on.

Later, as performance work, an aggregate whose only change is a default may
be updated in the database instead of moved (not built yet). The state
tables say which values follow the default, so an update per column and
concrete type replaces them; an untouched Collection or owned object is
rewritten from the new default per owner. A touched value that now equals
its default then becomes untouched at its next save, not in the migration:
a save already decides "touched" by comparing. Unreal works the same way: a
save writes only what differs from the archetype, so a value equal to a new
default stops being written at the next save. Tests must compare the update
with what a move writes, row by row, so the two never drift.

### What a migration touches

A native migration compares the native types stored with the native classes
in code. Each stored schema records its source, native or editor-created, so
a stored type missing from the code is a removed native type only when it's
native.

### Editor-created types

Editor-created types take part in every migration, the same as native ones.
Native code can't name an editor-created type: an attribute names a PHP
class. But an editor-created type can name native types and other
editor-created ones: as its parent, and as a field's target. So a change on
either side can reach it.

**A native migration** carries editor-created types along. The fixups run
over every stored schema, editor-created ones included: a renamed native
field they inherit, a renamed or replaced type they name, all follow. Then
every editor-created type is compiled again, with the new native schemas, in
the same batch. That checks it again, lays its own defaults over its new
ancestors, and gives it fingerprints that match its dependencies. Its
content moves the same way native content does.

Its own defaults move through the plan too. What it sets itself is stored
by field path, in stored form, so a native fixup that renames, retypes or
drops a field it sets, or renames a type an owned default holds, leaves that
value at an old path or in an old form. Compiling the native types again
doesn't touch it, so the plan must carry it over, the same way it carries
content.

**To review** (built, but not yet confirmed): only what the type sets
itself is carried over. Its default instance moves whole, every default
included, so each fixup finds the shape it expects: a later fixup must see a
renamed type's new identifier in an inherited owned slot too. But it keeps
track of the paths the type sets itself (`AggregateValues::ofDefaults()`),
and only those are kept (`ownValues()`), then stored as they are, pinned
(see below):

- a rename or a move takes the path along;
- a converter's output stays the type's own where the field was. In an
  Embed, each field keeps whether the type set it, and a field new to the
  Embed is its own if the type set anything of the Embed;
- a removal ends it: a reset, a dropped null, a removed field. A field
  dropped and then added again in the same plan is new to the type;
- `DropType` replaces an owned slot of its own with null, and it stays its
  own;
- an initializer's output is never its own: a field new to the type has
  nothing of its to carry over.

What it inherits is never kept, converted or not. It keeps following its new
ancestors, as a native subtype's defaults follow the code. The one known
limit: a general transformation that derives new fields from one the type
sets, such as splitting `name` into `firstName` and `lastName`, keeps
nothing of it. A transformation can't say where a value came from, so the
derived fields follow their ancestors' defaults, and the type's own `name`
is lost. Accepted for now: it only matters when a transformation reshapes a
field an editor-created type sets a default for. If real cases come up, a
transformation would declare where its outputs come from ("`firstName` and
`lastName` from `name`"), next to the fixups it declares, so an output
derived from what the type set stays its own.

To compile again, the compiler needs a blueprint, and an editor-created type
has no class to read one from. So `StoredBlueprintReader` builds one from any
stored type: its stored schema, and only the defaults the type sets itself,
as stored. The editor uses it too, for every type a save doesn't change.

A blueprint's defaults (`BlueprintDefaults`) come in two forms. A native
class gives them dense: its default instance, inherited fields included,
built from the code as it is now. The compiler keeps what differs from what
the type inherits: the class says what it sets only by value. A stored type
gives only its own, and those are **pinned**: the compiler stores them
exactly as given, never compares them again. Each of its fields is either
set, and keeps its value whatever its ancestors do, or inherited, and
follows them. A value set equal to what the parent gives stays set, the same
as a native subclass setting it in its constructor. Only the admin moves a
field between the two, in the editor: "set" and "inherit" are operations of
their own. A migration never does, except where the field itself is gone.

To check a stored type's defaults and find the types they hold, the compiler
still resolves its full default instance: its own values over everything it
inherits. It resolves every stored type against every type's new values at
once, never in sort order: a type held in an owned slot may come later in
the batch, or hold the first one back. The resolver works path by path, so
such cycles end. Resolving from the stored state instead would read a held
type's old values, or miss a field it renamed in the same batch.

The code's `StateKey` holds native fingerprints only. Editor-created types
live in the database, not in code, so an editor save never makes a native
migration pending.

### When native code breaks an editor-created type

A developer reviews a migration against their own database. Production holds
editor-created types they never saw. A native change can break one:

- a dropped type an editor-created field declares as its target, of any kind,
  Embed included;
- a dropped native parent, or one that's no longer `editorExtensible`: the
  editor-created type's chain is cut;
- a field added to a native type, named the same as a field an
  editor-created subtype declares;
- a unique group added to a native type, which editor-created content breaks;
- a native type taking an identifier an editor-created type has. This fails
  the same way two native classes with one identifier do, naming the
  editor-created type.

A dropped type that is only a subtype of a field's target breaks nothing:
the field stays valid, and only the values holding that subtype go, as
`DropType` says.

Each break is a difference, and the policy decides it, the same as any other
difference (`EditorBreaks` finds them; `SetAsideField` and `CutChain` set
them aside). A fixup the developer declares, `ReplaceType` above
all, resolves a break before the policy sees it. Then:

- **Strict** blocks the migration, naming the editor-created type and the
  break.
- **Developer** and **unsafe** resolve it. Setting values aside as a
  `NoType` record (see "NoType") keeps every one of them, so it's lossless,
  and the developer policy applies lossless changes by itself.
- A unique group broken by content always blocks, under every policy:
  nothing can be set aside to fix it.

A break is found by its schemas alone, never by the fixup that caused it.
After each fixup is planned, declared or the policy's, the planner asks of
every editor-created type: does each field's declared target exist, is its
chain whole, each ancestor there and still allowing extending, and does a
field it declares clash with one it now inherits? A break the schemas didn't
have before that fixup is one it caused. So every fixup takes part, a
`Transform` or one written later included, and none needs to know about
breaks. A break no fixup causes, such as a native type no longer
`editorExtensible` in the code, is found once every fixup ran.

A break is set aside just before the fixup that caused it: the set-aside
fixup goes before it in the plan, on the schemas as they were there, and the
causing fixup then runs on schemas where the broken field is gone. So the
capture holds the field's name, descriptor and values as every earlier
fixup left them, and none of the causing fixup's changes: a `DropType` finds
an owned object it would empty already captured, and an `AddField`'s
initializer never overwrites a clashing field's value. Every database
migrating to the same code ends up with the same records and captures,
however far behind it was: a field renamed in an earlier step is captured
under its new name everywhere. A `Transform`'s data side is one step, so a
capture goes before the whole transformation, which doesn't see the values
set aside. A break no fixup causes is set aside after every fixup.

Finding breaks reads schemas only, once per migration, while planning.
Captures happen while aggregates move, one aggregate at a time, only for the
fields that broke; each is written to its side table right after its
aggregate.

How each break resolves:

- **A field whose declared target is gone** leaves the type's fields. Its
  values become a `NoType` record.
- **A cut chain**: the type takes as its new parent the nearest ancestor
  still there that still allows extending: a native type still
  `editorExtensible`, or an editor-created type, which always allows it. With
  none, it becomes a root. Each level cut off leaves a lost-ancestor record,
  even one that declared no fields, so an admin always sees the chain
  changed. Each field it inherited from the levels cut off becomes a
  broken-field record, its values with it.
- **A name clash**: the native field takes the name, since the code holds
  it. The editor-created field leaves the type's fields, and its values
  become a `NoType` record. No field is renamed or given a generated name.

A dry run, `Persistence::check()`, plans a migration against a database and
changes nothing: no lock, no table, no row. It plans to the end even when
something blocks (`MigrationPlanner::draft()`; `plan()` throws on the same
draft), so every block and every break shows at once. Its `MigrationReport`
says whether the database is empty or behind, and lists the pending steps,
every fixup the migration would run, in order, every break with the
editor-created type it hits and what the policy in use does with it, and
every difference blocking it. `describe()` gives it as lines to print. Run
against production before a deploy, it shows what strict would block. A
`migrate --check` command wraps it once a command line exists (not built
yet). The dry run plans; it doesn't compile the editor-created types again,
so a type that would fail to compile shows only when the migration runs.

### Editor saves are migrations

An admin changing an editor-created type changes what's stored for it, and
for every type naming it. So an editor save runs as a migration: the same
planner, the same lock, the same expand, move and contract, and the
project's policy. While it runs, `migration_state` blocks the whole site,
the same as a native migration. Moving only the aggregates it affects keeps
that short. Blocking only the affected types is for later.

`Persistence::saveSchemas(EditorSave)` runs a save, and
`previewSchemas(EditorSave)` reports what it would do, changing nothing.
`EditorSave` holds the revision, the operations, and `acceptBreaks`. Both
give an `EditorSaveReport`: the fixups, the breaks, what blocks the save,
and which entries can't move. Both read every class, never the cache. A
save is refused, before it changes anything, when:

- the database doesn't match the code (`MigrationPending`,
  `MigrationUnfinished`),
- its revision isn't current (`StaleSchemaRevision`),
- an operation can't apply (`InvalidOperation`),
- the policy blocks a break (`MigrationBlocked`),
- it causes breaks without `acceptBreaks` (`BreaksNotAccepted`),
- an entry's values can't move (`EntriesFailToMove`).

The last one comes from a dry pass. Before the save marks a migration
started, it reads every aggregate the save affects, runs the plan over its
values, and checks what it would write, writing nothing. A converter that
cancels, or a value that doesn't fit its new field, fails there, naming the
entry. So an admin's save never leaves the site blocked: only a database
error during the run can, the same as for a native migration. A preview
runs the same pass, so it lists the failing entries.

A save stores exactly the defaults the admin set, pinned, and never works
out what's set by comparing values: a field the admin set to what its
parent gives stays set. "Set a field's default" and "inherit it" are
operations of their own. Every type the save doesn't change is rebuilt from
what's stored (see "Editor-created types").

The save takes a list of operations, never a before-and-after diff: a diff
can't tell a rename from a drop and an add. Each operation is one fixup, the
same classes code declares. The editor knows what the admin did, so it fills
each fixup's parameters from the action itself, and asks only where there's
a real choice, with the usual answer selected. It can show a preview on a
few real entries, since the content is right there.

| Operation | Fixup | What the editor asks |
|---|---|---|
| Add a field | `AddField` | Existing entries: the default, a value, or another field's value. |
| Remove a field | `DropField` | Nothing; it confirms, saying how many entries hold a value. |
| Rename a field | `RenameField` | Nothing. |
| Move a field up or down the chain | `MoveField` | Moving up: what the entries gaining it get, as for adding. |
| Change a field's kind or type | `RetypeField` | A converter that fits, or a reset. A built-in one is selected when it exists. |
| Make a field required | `MakeNotNullable` | Empty entries: the default, or cancel. |
| Rename a type | `RenameType` | Nothing. |
| Replace a type | `ReplaceType` | The type replacing it, then initializers and converters field by field. |
| Delete a type | `DropType` | Nothing; it confirms. |
| Change the parent | `Reparent` | What the fields gained get, as for adding. |
| Copy values between fields | `MoveValues` | The field to take them from. |
| Add a unique group | `AddUniqueGroup` | Nothing; refused, listing duplicates, if content breaks it. |

An operation says everything about its change. The save holds no type
definitions beside the operations: the server starts
from the stored definitions, rebuilt as blueprints, and applies each
operation in order. Each gives a change to the definitions and the fixups
moving the data there: "add a field" carries its descriptor, its default
and what existing entries get, and gives `AddField`. An operation moving no
data gives no fixup: creating a type, setting or inheriting a default,
changing a permission. So definitions and fixups come from one source, and
can't disagree. Each operation is one small class: it reads its own form,
checks it, and gives its change.

The operations work on a draft, `SchemaDraft`. It holds every
editor-created type's new definition, the fixups and the default changes.
It only reads native types: an operation changing one throws
`InvalidOperation`. A type the save creates holds no content, so it needs
no fixup. Its final definition joins the plan's starting state, as if it
had always been there, empty. So a field retyped to point at it finds it
from the first fixup. A created type can't take a name a type had when the
save started.

Defaults move in two parts. The stored defaults move with the fixups, the
same as in a native migration: renamed with their field, converted, or
dropped. Then the save's default changes apply, with the final names:
`SetDefault` pins a value at a path, and `InheritDefault` removes the pin.
The compile then checks every default against its field.

An editor-created field has no class to take a default from, so the save
says it. `AddField` takes the new field's default, and so does
`RetypeField` when it resets: without a converter, the stored default goes
with the values. Every kind needs one but an Embed, whose defaults are its
fields', set by path. A missing one is an `InvalidOperation`. The editor
fills in a default the admin can change, as it does when editing an
entity.

The server normalizes the list before planning, so nothing the admin
undid touches the database. The editor keeps every action for undo, and
shows the normalized list, from a preview, as what the save will run:
`EditorSaveReport::$operations`. `OperationNormalizer` first checks the
list as given, so an operation that can't apply is refused with its own
message. Then it follows every type and field by identity through the
operations, and gives what's left. Normalizing:

- Renames chain: `a` to `b` then `b` to `c` is one rename, and one back to
  where it started is none. Types the same.
- What the save creates takes in what's done to it after: a field added,
  then renamed, retyped, given a default or moved, is one "add a field"
  with its final name, descriptor and default; added, then removed, is
  nothing. A type the same.
- A default keeps its last change, under the final names. One back to what
  the type stores is none. One a retype already pins is none. Permissions,
  `editorExtensible` and tables will follow the same rule once operations
  change them (not built yet).
- An existing field removed, then added again with the same descriptor, is
  nothing: its values stay. With another descriptor, it's a reset
  (`RetypeField::reset`). With an initializer, it's removed and added
  again, so the initializer runs.
- An existing field retyped, then retyped back to its descriptor, is
  nothing: no converter runs, and its values stay as they are.
- A field the save adds, then retyped with a converter, stays an "add a
  field" and the retype after it: the converter changes what the
  initializer gave.
- What's left is ordered so each operation finds what it names: deletes,
  type renames, new types (parents first), then each type's removed,
  renamed, changed and added fields, then default changes. A rename waits
  until its new name is free. Two names swapped in a cycle can't be
  ordered: the save is refused, asking to save one rename first.

A default change's path follows a rename of its first field only: in
`engine:power`, `engine` follows a rename, `power` doesn't (not built
yet). It matters only when one save renames a field inside an Embed or an
owned object, and sets a default through it before the rename. Defaults
already stored aren't affected: they move with the fixups, at every depth.
Such a default would end on a path the type no longer has, and compiling
would drop it. So the save refuses it instead, as an `InvalidOperation`
saying the feature is missing: save the rename first, or set the default
with the new names. When the editor saves each type on its own, this
can't happen: the other type's rename is saved first, and a save built on
the old names comes back stale.

Saving several types at once does hit it. Signing out with unsaved work
offers "Save all", and so will any "save all tabs". Those can't be one save
per type sent in a row: the first raises the revision, so every one after
it comes back stale. They must be one save holding every type's
operations. So following a rename inside a default path, through Embeds
and owned objects, is needed before the schema editor joins "Save all".

A save applies only to the definitions its operations were built on. Every
migration that changes stored schemas, native or editor, raises a schema
revision, kept in the `schema_revision` column of `migration_state`. A
migration that finds nothing to change leaves it. A database no migration
ran on is at revision 0. `PersistenceSession::schemaRevision()` gives it,
and `coconut-cms:types.list` gives it with the types. A save sends the
revision it was built on. A save whose revision isn't current any more is
refused: the types changed since the admin started. The
editor then loads them again, replays the admin's operations on them, and
shows what no longer applies (not built yet). Nothing runs on definitions
the admin never saw.

One revision for every type refuses a save after any schema change, even
one to types the save never touches. A check per type can come later (not
built yet), with no change to what a save sends now. A save depends on its
footprint: the types its operations name, their ancestors, subtypes and
targets, and the types holding them, to a fixpoint. `types.list` gives each
type's own fingerprint. A save sends the fingerprints of its footprint. The
server works out the footprint again on the current schemas. The save is
stale when a type there is missing from what it sent, or has another
fingerprint, or a type it sent is gone. A name collision needs no check of
its own: applying the operations refuses it.

A save can break other editor-created types. Deleting
`shop:maker` breaks `shop:boat.maker`, a field pointing at it. The project's
policy decides each break, as in a native migration. The strict policy
blocks the save. The developer policy sets the field aside: its values are
kept as NoType records, and the site keeps running until someone fixes it.
The preview lists every break. A save causing breaks must say
`"acceptBreaks": true`, or it's refused, listing them: nobody breaks a type
without seeing it first.

So what a save stores is the plan's result, not the operations' own
definitions: a set-aside changes a type the admin never touched. The
operations' definitions are the target the fixups take new shapes from.
What's stored is every editor-created schema after every fixup and
set-aside, with the defaults the operations set, compiled again the way a
native migration compiles editor-created types.

Saving type changes is a right of its own (decided, not built): it changes
the shape of everything stored for those types, and blocks the site while
it runs. The project names one schema-editor role at boot; only a user
holding it, or the system actor, may save. Reading types stays each type's
own read permission. Permissions per type, who may change which types, may
come later; a save works the same either way.

The editor changes editor-created types only. Native types are the code's:
the editor may extend one that's `editorExtensible`, never rename, retype
or remove anything native. Such an operation is refused.

A general transformation is code only. The editor offers built-in
converters and initializers, data-driven classes with parameters, never
closures. They live in `Migration\Data\Builtin`, and a code step can use
them too:

- `ConstantValue(value)`: every existing entry gets one value.
- `CopyOfField(path)`: each entry gets the value it holds at another path.
- `NumberToText`: 150 as "150", the Decimal "12.500000" as "12.5".
- `TextToNumber(to, onFailure)`: text to an Int, Float or Decimal.
- `TextToEnum(enum, onFailure)`: text to the case it backs.
- `DateToDateTime`: a day to its midnight, in UTC.

A converter keeps null as null. A value that followed the old default
follows the new one: the stored default is converted the same way. Text
that holds no fitting value does what `OnFailure` says: the entry follows
the default, or the migration fails with `ConversionFailed`, naming the
value.

Not built yet: number to text with a format, one value to a Collection and
back (the first item, or joined), one Reference to a Collection of them.
Any converter or initializer registered in code is offered where it fits
(not built yet).

An editor-created identifier is "namespace:key". The editor uses the
namespace the admin gives, or `default`, the same as a native class. A save
naming an identifier any type already has is refused.

### Fixups are ordered steps

Fixups are typed objects in code, reviewed with the commit, grouped into
ordered steps. Boot code declares every step in one `MigrationSteps`, an id and
its fixups at a time, and the order they're added in is the order they run in:
two branches adding steps at once then meet as a merge conflict, never as an
older step silently slotted in before one already applied. Two steps sharing
an id are rejected as soon as the second is added. The database records the id
of every step it has applied, next to the schema stores.

```php
$steps = MigrationSteps::none()
    // First release: the car's price is renamed.
    ->add('2026-10-01-rename-price', [
        new RenameField('default:car', 'price', 'cost'),
    ])
    // Second release: the cost becomes text.
    ->add('2026-10-08-cost-as-text', [
        RetypeField::converting('default:car', 'cost', new PriceToText()),
    ]);
```

A database several steps behind catches up through every pending one. It holds
no schema for the states in between, so it can't move step by step. Instead,
each fixup transforms an entity's touched values by path, and the pending
steps' fixups compose, in order, into one transformation: a field renamed
`price` to `cost`, then later `cost` to `amount`, moves straight from `price`
to `amount`. Each entity moves once: read with the stored schemas, transformed
through every pending step, written with the schemas from code. A converter in
a later step receives what the earlier steps produced, which is what its
author wrote it against. The composed fixups, plus what the policy lets
through, must explain every difference between the stored schemas and the
code.

Under strict, an addition is a new type, a new field or a new unique group.
Anything else is declared, even when it loses nothing, like a table rename or
a reparent that only inserts a level.

A step can be deleted from the code once every database has applied it. A
database still missing it then can't explain its differences, and blocks.

A fresh database, with no stored schemas, has nothing to migrate. Its tables
are created from the code, and every step in the list is recorded as applied:
an old rename must never run against tables that never had the old name.

### Fixups transform values

Every fixup transforms two things: an aggregate's values, and the schemas.
Every pending step's fixups run, in order, over the stored schemas. Then the
result is compared with the code: whatever still differs is unexplained, and
goes to the policy. So a chain of renames needs nothing special, and a fixup
naming a type or field that doesn't exist at that point throws there, naming
its step: a typo never passes silently. Data still moves once, from the
stored schemas straight to the code's.

A fixup takes a new shape from its target: the code's schema for a native
type, the saved definition for an editor-created type in an editor save
(see "Editor saves are migrations"). Below, "the code" means the target.

The common fixups are shorthands:

- `RenameType(old, new)`: every parent and target naming the type follows
  it, and so does its table: it takes the table the code gives the renamed
  type, so a table derived from the identifier needs no `MoveTable` of its
  own.
- `DropType(type)`: deletes its entities and everything they own. References
  to them become null. Where one sits in another entity's owned field, or as
  an Owned Collection item, that slot becomes null, the same for both: what it
  held is gone. A converter or transformation before the drop can put
  something else there.
- `ReplaceType(type, with, initializers, converters, resets)`: every entity
  of the type becomes an entity of `with`, keeping its id, so References and
  owned slots holding it stay valid. A replacement new to the code comes
  with it: no `AddType` before it. Any type may replace
  it: an ancestor, a sibling, an unrelated type, or a direct subtype, which
  takes the type's place in the chain. A deeper descendant is an
  `InvalidFixup`: the subtypes between them would be reparented onto it, and
  it descends from them. Its levels shared with `with`'s chain keep their
  rows; its other levels go, and `with`'s other levels are added. Values
  follow paths: a field both types have, with the same descriptor, keeps its
  value; a field only the old type has goes; a field only `with` has takes
  its default, or its initializer. A field both have with different
  descriptors is declared like a retype: a converter, or a reset, which
  leaves the entities following `with`'s default. One left undeclared is an
  `InvalidFixup` naming it: the developer declared the replacement, so an
  incomplete one fails like a typo, never reaching the policy. Every subtype
  of the type is reparented onto `with` the same way. Every field targeting
  the type, of any kind, Embeds included, targets `with`. A native subtype's
  own field named the same as one of `with`'s is an `InvalidFixup`; an
  editor-created one's is a name clash, set aside like any break.
  Initializers, converters and resets are keyed by field name; a name that
  fits none of them is an `InvalidFixup`. A field declared with an ancestor
  of the type, holding one of its entities, must accept `with` too: an
  `Engine` slot holding a turbo engine replaced by an unrelated type fails
  the migration, naming the entity, when it's checked before writing.
- `MoveTable(type)`: its table moves to the name the code gives it.
- `Reparent(type, initializer?)`: the type moves under the parent the code
  gives it. A level it gains holds fields every existing entity already
  should have, so it's filled, not added: following its defaults, or by the
  initializer. It's called for every entity of the type and its subclasses,
  receives the entity's values, and sets fields of the levels gained, and only
  those. One initializer fills every level one `Reparent` inserts. A level the
  type loses takes its values with it, unless a fixup before moves them.
- `AddUniqueGroup(type, fields)`, `DropUniqueGroup(type, fields)`.
- `AddField(type, field, initializer)`: gives existing entities a value other
  than the default.
- `DropField(type, field)`.
- `RenameField(type, old, new)`: the shorthand for moving the values from the
  old name to the new, the old field dropped, the new one added.
- `MoveField(from, field, to, initializer?)`: a field's declaration moved up
  or down its own chain. Its path doesn't change, so its values follow on
  their own. Moved up, entities that never had the field gain it: those of
  `to` and its subclasses that aren't of `from`. They take its default, or
  the initializer, as with `AddField`. Moved down, nobody
  gains it, so an initializer there is an `InvalidFixup`.
- `MoveValues(type, from, to)`: values moved between any two paths of an
  aggregate: across levels, into or out of an Embed, across an owned boundary.
  Data only: the schema changes it goes with are declared next to it. Both
  paths must exist when it runs, like any name a fixup gives: a field it
  moves into is added before it, with `AddField` or `RenameField`. Past an
  owned slot, a field of any type extending the target counts.
- `RetypeField::converting(type, field, converter)`: a retype always has a
  converter, even where the old and new stored forms look alike. Nothing
  converts from one type to another implicitly.
- `RetypeField::reset(type, field)`: a readable name for `DropField` followed
  by the field added again, following its default.
- `MakeNotNullable(type, field)`: the field, or a Collection's items, can no
  longer hold null. A singular null becomes untouched, so it follows the new
  default. A null item is removed, and the Collection gets shorter. Every
  other value stays. The unsafe policy chooses it; a step declares it to
  accept that rule on a database whose content matters.

A general transformation covers what no shorthand does: splitting a field,
merging several into one, deriving one from others. It declares its effect on
the schemas as the shorthands it amounts to: splitting `name` drops `name`
and adds `firstName` and `lastName`.

A fixup names things as they are when it runs. Within a step, fixups run in
the order listed.

Fixups come in two kinds. A data fixup moves or reshapes values and changes
no schema: `MoveValues`, a converter, a general transformation. A schema fixup
explains a declaration: `AddField`, `DropField`, `RenameType`, `Reparent`, and
the rest. A shorthand like `RenameField` or `RetypeField` is both, for
readability.

Data follows paths: a value survives when its path still exists in the new
schemas, and goes when it doesn't. Data fixups change paths; the new schemas
decide the rest. So `DropField` deletes nothing by itself: a value goes
because its path is gone. `MoveField` from `Car` up to `Vehicle` keeps every
car's value, since a car still has the path. Moved down from `Vehicle` to
`Car`, a vehicle that isn't a car loses its value, since it no longer has the
path.

Each fixup runs over a whole aggregate at once, at every place its field or
type lives, before the next fixup starts. So the declared order is the order
everything runs in, across all types.

A general transformation is declared on a type, and runs at every place that
type lives: its entities and its subclasses', owned or standalone, and its
Embed sites. At each, it receives the values rooted there: its own fields and
everything it owns, nested. So a car's transformation can move a value down
into `car.engine`; moving one up from an engine into its car takes a
transformation on the car.

### How an aggregate moves

For each root entity:

1. Its values are read with the old schemas, owned objects included. Content
   is dense, so they're every value in effective form under the old
   defaults, each marked touched or not by its flag.
2. Each fixup's data side runs, in order, over the whole aggregate. It finds
   where its field lives from the schemas as the fixups before it left them.
   Renames and moves change paths, keeping each value's mark and each owned
   object's id. Converters replace values.
3. The aggregate is written with the new schemas, the way a save writes it.

Only two kinds of value are compared with the new defaults at the end, the
way a save compares: touched values, and converters' output.

- Touched and not converted: stays touched, unless it now equals its default.
- Converted with `Conversion::of()`: touched only where it differs from the
  new default.
- Converted with `Conversion::default()`: untouched.
- Untouched and not converted: stays untouched, and follows whatever the new
  default is. Without this, a default changed in the same release as a
  migration would pin every untouched entity to the old default.

A fixup on a field applies wherever that field's values live: in entities of
the type declaring it and of every subclass, standalone or owned, and at every
Embed site of the type, singular or as Collection items. The field is one
declaration everywhere, so it's declared once.

### The data API

```php
interface FieldConverter {
    public function convert(Captured $old): Conversion;
}

final class Captured {
    public function value(): mixed;      // at the field itself: a scalar, a Reference's id, an owned
                                         // object's identifier, a Collection's item count; null for an Embed
    public function isDefault(): bool;   // whether it was following the old default
    public function under(): Values;     // the values below it, paths relative to the field
}

final class Conversion {
    public static function of(mixed $value, array $under = []): self;
    public static function default(): self;
}

interface FieldInitializer {             // AddField(type, field, initializer)
    public function initialize(Values $values): Conversion;
}

interface LevelInitializer {             // Reparent(type, initializer)
    public function initialize(MigratingValues $values): void;
}

interface Transformation {               // Transform(type, transformation)
    /** @return list<Fixup> The schema fixups it amounts to. */
    public function declares(): array;
    public function transform(MigratingValues $values): void;
}
```

`Values` is read-only: `get`, `has`, `isDefault`, `paths`. `MigratingValues`
adds `set(path, Conversion)`, `remove` and `move`, scoped to one call.
Converters, initializers and transformations are always classes, never
closures: named, testable, and readable in a step. Every output is checked
before anything is written; see "Checking what's written".

### Checking what's written

Before an aggregate is written, `StoredForm` checks every value about to be
written, touched or converted, against the field at its path in the new
schemas. One that doesn't fit fails the migration, naming the entity and the
path: `Moving entity 0193… failed: "cost" must hold a string of 255
characters at most, got 150.` The database alone isn't enough: MySQL stores
the int 150 in a string column as "150" without complaint.

The rules, kind by kind, are what a save produces:

- String: a string of 255 characters at most. Text: any string.
- Int: an int. Float: a float, or an int, widened. Bool: a bool.
- Decimal: a number as a string, put in canonical form: `"12.5"` is written
  `"12.500000"`. More than six decimal places fails.
- Date and DateTime: exactly `Y-m-d` and `Y-m-d H:i:s`, UTC.
- Enum: the backing value of a case of the enum in code.
- ValueObject: a string its class's `fromStorage()` accepts, 255 characters at
  most when it's indexable.
- Reference: an id. The foreign key says the entity exists.
- OwningReference: the identifier of the target type or a type extending it.
- Collection: the item count, an int. Its items follow their own rules.
- Null only where the field, or the item, is `Nullable`.

A value a fixup wrote where the new schemas have no field fails too: it's
almost always a typo in a converter's `under` paths. A value only carried
along to such a path is dropped, as "data follows paths" says.

An untouched value isn't checked: it's never written. The entity follows the
new default there, and the compiler checked that default.

Once every aggregate has moved, still in the same transaction, each Reference
column is checked against the type of the entity it points at: one query per
column. Nothing in the database says a referenced entity's type, and only
then does every entity carry its new identifier. A Reference pointing at the
wrong type fails the migration, naming both entities.

An initializer runs at every place its type lives, including objects that
only exist as defaults: an owned object never touched, an Embed with nothing
set, an entity with every value a default. Whatever it returns there is
stored, and stops following the default. An initializer that wants such a
place to stay a default says so itself:

```php
final class SerialFromHorsepower implements FieldInitializer
{
    public function initialize(Values $values): Conversion {
        // Still the default engine: let its serial follow the default too.
        if ($values->isDefault('horsepower')) {
            return Conversion::default();
        }

        return Conversion::of(sprintf('SN-%d', $values->get('horsepower')));
    }
}
```

### Values in, values out

Converters and transformations work on stored form, both ways: strings, ints,
canonical decimals, dates as text, identifiers. Never PHP objects: a database
several steps behind passes through states whose classes may no longer exist.

What a converter receives is the field's capture: the value at its path (a
scalar, a Reference's id, an owned object's identifier, a Collection's item
count) and the values under it, relative to the field. It returns the same
shape for the new field.

A converter or transformation receives each entity's effective values: what
it touched, and what it resolved to otherwise, from the old defaults. These
are still stored while the migration runs. The capture also says whether each
value was touched or the old default. A converter returns a `Conversion`:
`Conversion::of($value)`, or `Conversion::default()` to leave the entity
following the new default. Never a magic value standing for "default". Nothing is implicit: every
entity goes through the converter, and the converter decides whether a
default carries over, converted, or becomes the new default.

Its output is stored only where it differs from what the new schema resolves
to, the same comparison a save makes. So an entity keeps showing what it
showed, converted, and stays untouched wherever the conversion lands on the
new default.

### NoType

Every change that carries data forward captures the old value first, as
`NoType`, then rebuilds the new value from it. A converter's input is always
this capture, never the old field itself, so it never depends on where the
old value lived: a column, a flattened Embed, a Collection table or an owned
entity's rows. The capture is the entity's touched values under the field's
old path, in their stored form, read with the old schema.

A capture can also be kept, in a **`NoType` record** (built; the editor
operations resolving one are not built yet): what
broke on an editor-created type, set aside until an admin decides (see
"When native code breaks an editor-created type"). Only an editor-created
type holds records: a native class naming a dropped class already fails
when it's read.

Records aren't fields. They sit in a list of their own on the type's
schema, next to its fields. So loads, queries, validators and saves, which
only ever read the fields, never meet one, and a record can't clash with a
field name. Every record has **its id**, generated when the break happens,
like an entity's. It's the record's identity: an old name and origin aren't
unique over time, since the same field can break twice before the first
record is resolved. A record is of one of two kinds:

- **A broken field**: one field of the type that broke, on the type that
  loses it. Every entity's values for that field are captured under it: one
  record, many captures. It holds:
  - **its old name and its full old descriptor**: kind, target, items,
    nullability. What the values were, for a conversion and for display;
  - **its origin**: the type that declared the field. For a cut chain, the
    ancestor cut off; for a clash or a dropped target, the type itself;
  - **its reason**: target gone, chain cut, or name clash.
- **A lost ancestor**: one CTI level cut out of the type's chain. It holds
  the identifier of the ancestor lost, why (dropped, or no longer
  `editorExtensible`), and the parent the type took instead, or none. It has
  no captures: a level's values belong to its fields, and each of those is a
  broken-field record whose origin names the level. A level with no fields
  still leaves this record, so the chain change is never silent.

One lost level gives each editor-created type that lost it its own records:
`ed:boat` and `ed:plane` both extending a dropped `Vehicle` each get theirs. A
subtype inherits its ancestors' records the way it inherits their fields:
`ed:speedboat extends ed:boat` shows `ed:boat`'s, and its entities' captures
go under them.

A type holding any record is broken. The schema editor shows each
broken-field record among the type's fields, for what it was, marked
broken, with how many entities hold a value, grouped under the lost
ancestor it came from when there's one. Its fixes are editor operations:

- a broken field: convert it into a field, from its captures, naming the
  field then; or drop it. When the lost origin comes back, or the admin
  gives the type a parent with matching fields, the editor can offer to turn
  records back into fields;
- a lost ancestor: reparent the type, or acknowledge the new parent, which
  removes the record.

The captures live in one side table, `notype_captures`, the way stored
defaults live next to schemas:

- `entity_id`: the entity the broken field lived on, a root or an owned
  object. A foreign key to `entities.id` with `ON DELETE CASCADE`: deleting
  the entity deletes its captures.
- `record_id`: the broken-field record it belongs to. Indexed, so the editor
  counts the entities holding a value, and dropping a record deletes all its
  captures at once.
- `captured`: the captures, as JSON.

One row per entity and record: (`entity_id`, `record_id`) is the key.

An entity can hold the broken field more than once: when the type it
belonged to is used as an Embed, the entity may embed it in two fields, or
in a Collection of them. So the JSON holds one capture per site inside the
entity, keyed by where the type sits: `""` for the entity itself, `chassis`
for an Embed field, `labels.0` for a Collection item. An Embed item has no
identity, so `labels.0` is its position when the field broke: a save that
reorders the Collection before a conversion leaves the captures on the
wrong item, and a conversion places them by position, as captured. An Embed
item can't own anything for the same reason.

A capture has the shape a converter receives (`Captured`: the value at the
field, and the values under it), so converting a record back into a field
uses the same converters. It holds three parts, each keyed by path relative
to the field, `""` for the field itself, joined the way paths always are:

- `values`: every value at and under the field, in stored form, defaults
  included, since content is dense;
- `touched`: the paths that were touched, so a conversion still tells a set
  value from a default;
- `ownedIds`: the id of every owned object captured, by its slot's path.

Kind by kind, `values` holds:

| Kind | `values` |
|---|---|
| Scalar, ValueObject | `{"": 5}` |
| Reference | `{"": "0193…"}`: the target's id, with no foreign key. A conversion checks the target still exists. |
| Embed | Only its fields: `{":weight": 900, ":code": "C"}`; a nested Embed continues, `":frame:depth"`. |
| Collection of values | The count, then each item: `{"": 2, ".0": "red", ".1": "fast"}` |
| Collection of Embeds | `{"": 1, ".0.weight": 900, ".0.code": "C"}` |
| Owned object | Its identifier, then everything under it: `{"": "ed:engine", ".horsepower": 300, ".turbo": "ed:turbo", ".turbo.boost": 7}`, with `ownedIds` `{"": "0193…", ".turbo": "0194…"}` |
| Owned Collection | `{"": 2, ".0": "ed:engine", ".0.horsepower": 300, ".1": null}`, with `ownedIds` per item |

What happens to what was stored:

- The broken field's columns and Collection tables lose their values to the
  capture, then go in contract, as any dropped field's do.
- An owned object under the broken field is captured whole, its id and the
  ids of everything it owns included, and its entity rows are deleted. It
  can't stay: a dropped target's tables are gone, and an owned row always
  has a field owning it. A conversion back into a field recreates owned
  objects with the same ids. A Reference from another aggregate pointing at
  one becomes null when the row goes, as the foreign key says; a recovery
  brings the object back, not that Reference.
- A broken-field record lives on the type that loses the field. Entities of
  its subtypes had the field too: their captures go under the same record,
  and the editor shows it on the subtypes as inherited.
- A cut chain gives one lost-ancestor record per level cut off, and one
  broken-field record per field lost, with captures for every entity of the
  type and its subtypes.

Content itself is never JSON: every value has its column, so a query can
use it. A capture isn't content. Nothing queries it, and no native object
ever loads it. Only a conversion and the editor read it, and later a
`DynamicEntity`, to show it.

### How data moves

Data moves at the value level, one entity at a time. For each entity a change
affects, `EntityRowsReader` reads its touched values with the old schema. They
are transformed: keys renamed, old values captured and converted, dropped keys
removed. `EntityRowsBuilder` builds its rows with the new schema, and they
replace the old rows, the same way a save does. One general mechanism covers
every kind of field and change. A faster SQL path for a simple case may come
later, as an optimization that must give the same result.

MySQL commits every DDL statement on its own, so a migration can't be one
transaction. It runs in three steps instead:

1. **Expand.** Add every new table, column and index. The old ones stay.
   Every value column is nullable, so old and new shapes coexist in one
   table. A column keeping its name but changing its type is renamed out of
   the way first, `weight` to `_old__weight`, then added again in its new
   type; its indexes and foreign keys go, since they're no data. In MySQL 8
   both are usually instant: nothing is copied. A table whose name a new one
   needs is renamed the same way, to `_old__<table>`. The DDL comes from
   comparing the database as it is with the target, so running a failed
   migration again doesn't trip over what it already did.
2. **Move.** Move every entity's data and commit the new schemas, in one
   transaction: data changes are transactional, and the schema stores share
   the database. Each aggregate is read with the old schemas, a renamed
   column's value read from its `_old__` name, and written in the new shape.
   Either everything moves and the new schemas are committed, with the
   applied steps' ids, or nothing does.
3. **Contract.** Drop every `_old__` column and table, and whatever the new
   layout no longer has. If this fails, what's left is unused and nullable,
   and the next run drops it.

Every point a migration can stop at is recognizable from the database alone:
`_old__` names with the old schemas still stored mean expand was cut short,
and the next run carries on; with the new schemas stored, contract is pending,
and the next run finishes it. `_old__` follows the system-name rule, so it
never collides with a field. It adds six characters, so the layout caps every
table and column name at 58, keeping the renamed one within MySQL's 64.

From expand to contract, the stored schemas and the tables don't match. A read
would see a moved-aside column as null, and a write would fail or land in a
half-moved shape. So `MigrationState`, one row in `migration_state`, marks a
migration started before expand and finished after contract. While it's
started, `EntityReader` and `EntityWriter` throw `MigrationUnfinished`. A
migration that fails stays started: entities stay unreadable until a
migration finishes. The migration's own writer skips the check. Each reader
and writer queries it once, on its first read or write, and not again once it
found none started. `Persistence` checks it first, once per request (see
"Booting").

A finished migration also stores the code's `StateKey` in that row: a hash of
every native type's fingerprint and every declared step id. Step ids count,
since a step can move data with no schema change. While the code's key differs
from the stored one, a migration is pending.

One transaction for all the data is long on a large database. That's
acceptable for now. If it isn't one day, the fix is batches with a record of
which entities have moved. Moving only the aggregates a change affects (see
"What a change costs") keeps it short for most changes.

## Booting

`Persistence::boot()` wires the persistence layer: registries, stores, the
reader, the writer and the loader. It touches neither the database nor any
class. A project hands it its modules, a cache directory, when to migrate,
and the policy:

```php
$persistence = Persistence::boot(
    connection: $connection,
    modules: [new AppModule()],
    cacheDirectory: __DIR__ . '/var/cache',
    migrateOn: MigrateOn::Command,       // MigrateOn::Request in development
    policy: new StrictPolicy(),          // the default
);

$persistence->status();                  // PersistenceStatus::Ready, MigrationPending or MigrationUnfinished
$persistence->migrate();                 // what a deploy command calls

$session = $persistence->as($actor);     // see "Permissions"
$session->load($id);
$session->save($car);
$session->delete($car);
```

### Modules

A `Module` groups native entity classes with the migration steps that
brought them where they are: `name()`, `classes()`, `steps()`. The CMS's own
types are `CoreModule`, always booted first. Modules migrate in boot order.
Each step is recorded under its module's name, `app:rename-price`, so two
modules never collide. A fixup may name any module's types: they form one set
of schemas.

The value objects, enums and validator rules a module's classes use are found
through their fields and validators, never listed. Each is registered under
the identifier its `#[StoredAs]` declares, or `default:` and its short class
name, snake-cased: the same rule as an `#[Entity]` identifier.

### When a request may read and write

Every request checks, once, on its first `status()`, `load()`, `save()` or
`delete()`, whether the database matches the code:

- a migration started and not finished: `MigrationUnfinished`;
- the code's state key differs from the stored one, or none is stored:
  `MigrationPending`;
- otherwise: ready.

Both exceptions extend `PersistenceUnavailable`, so a project catches one
type to show a "back soon" page. `status()` lets it decide before touching
anything. Pages that need no entities keep working. Any pending change blocks
every read and write, whichever types it touches. Blocking only the affected
types may come later, on the same fingerprints.

`MigrateOn` decides what runs a pending migration. With `Command`, only an
explicit `migrate()` does, from a deploy script: nothing heavy runs in a
visitor's request, and a blocked migration fails where the developer deploys.
With `Request`, the first check finding one pending runs it: for development.

### The code's state, cached

Computing the code's state key reads every class and compiles it: about
0.14 ms a type, so 15 to 30 ms a request for a real site. So `CodeStateCache`
keeps it in a JSON file in the cache directory, with the classes to register,
and the modification time and size of every file it came from: each module's,
each entity class's and its ancestors', and each registered class's. A
request stats those files and reads that one file. A file changed, a module
added or removed: it's read and compiled again, and rewritten. JSON, never
PHP: opcache with timestamp checks off would keep serving a rewritten PHP
file's old content.

A file outside that set can still change a default: a helper class a default
instance's constructor calls. The cache doesn't see that. A changed default is
never a migration difference, so nothing breaks: the new default is stored on
the next migration. `migrate()` never trusts the cache: it reads every class,
and rewrites the cache from what it found.

Without a cache directory, every request's first check reads every class.
That's correct, and fine for tests and small sites.

## Databases

Persistence runs on MySQL 8 and MariaDB 10.6 in production, and on SQLite for
tests, demos and small sites. SQLite lets one writer in at a time, so it's
never for production with several editors at once. Any other database fails
at the first check, naming it. PostgreSQL isn't supported, but nothing rules
it out: adding it is a new platform class.

Everything the databases do differently sits in one `DatabasePlatform`
each, picked by `Platforms::of()`:

| | MySQL, MariaDB | SQLite |
|---|---|---|
| Readying a connection | nothing | foreign keys on, a busy timeout |
| One migration at a time | a named lock, `GET_LOCK` | a lock file next to the database; none in memory |
| Before a write's transaction | read committed | nothing: only serializable exists |
| Locking an aggregate's root | `SELECT … FOR UPDATE` | a write to its `entities` row, which takes the database's write lock |
| Schema changes | run as they are | foreign keys off while Doctrine rebuilds tables, every key checked after |

Every other statement is plain SQL all of them run, and every table and
column name in it is quoted by Doctrine: a project's table or field may be
named like a keyword. Expand and contract are table diffs Doctrine writes for
each database: an `ALTER` on MySQL, a rebuilt table on SQLite. A
`MigrationState` row is updated, or inserted when there's none, instead of
upserted: only a migration writes it, holding the migration lock.

SQLite stores a Decimal as a floating-point number, so it keeps 15
significant digits; reading writes it in canonical form again. A Collection
item's `_position` is a `BIGINT`, not an `INTEGER`: Doctrine reads an
`INTEGER` back from SQLite as auto-incremented whenever it's the only integer
in the primary key, and every migration would then see the column changed.

The integration tests run on MySQL by default, and on SQLite with
`COCONUT_TEST_DB=sqlite`. MariaDB needs a server to run them against (not set
up yet).
