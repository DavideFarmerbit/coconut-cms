# Storage/Editor Architecture — v3

Status: a standalone technical description of the system's current, decided shape.
Updated alongside implementation, one decision at a time, so this document never
outpaces the code the way earlier rounds did. A decision recorded here may still be
ahead of the code; where that's true, it's noted inline rather than left implicit.

Scope:

- **Schemas:** reading native classes, compiling them, storing schemas and
  their default values, and catching stale ones.
- **Content:** where an entity's values are stored, identity, and saving,
  loading and deleting native entities.

Not covered yet:

- migrations, when a schema changes after content is stored;
- editor-created entities, decided in `PROPOSAL_FOR_V3_DYNAMIC_ENTITIES.md`;
- namespaces, undo and permissions, for which `archive/ARCHITECTURE_V2.md` and
  `archive/CLEANUP_FOR_V2_ROUND17.md` still hold the latest design.

Everything in `archive/` is superseded: the v1 and v2 designs, their roadmaps,
and their cleanup rounds. Read it for history, or for an area this document
doesn't cover yet. Where the two disagree, this document wins.

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
A Scalar's property must be declared as what its `ScalarType` holds, nullable
or not: `string` for String, Text and Decimal, `int`, `float`, `bool`,
`DateTimeImmutable` for Date and DateTime, a backed enum for Enum. An Enum's
class is that declared type, never an attribute argument. Loading puts stored
values back into the property, so a mismatch would otherwise only fail once
real data is read. A Collection still names its `itemTarget`: `array` says nothing about its
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
  level first. A value lands on the level whose class declares its field.
  Touched, it's stored and flagged. Untouched, it's null and unflagged, and
  resolves to its default.
- A Collection gets rows only once touched, and then all of them: one per
  item, plus one state row per Embed item.
- An owned object gets rows of its own once anything at or under it is
  touched, its class included. Its `owner` is the entity owning it directly,
  `owner_field` the path it's held at, `position` its index in an Owned
  Collection or -1. An empty slot gets no row. From then on its path is
  flagged touched, so it stops following the default, even if its class
  still matches it: the same as any other value set by the instance.

Each `Row` says how to bind its values, from their own PHP kind: PDO would
otherwise send `false` as an empty string, which no boolean column accepts.

## Loading an entity

Loading is split in two. `EntityReader` reads any entity, native or
editor-created, and never touches a PHP class: `EntityRowsReader` reads its
rows into its touched values (only flagged columns count, each back in its
stored form: MySQL gives a boolean back as `0` or `1`), with an owned
object's values from its own rows, under its path. The resolver then resolves
the whole tree on `Subject::ofInstance()`: anything untouched comes from the
type's defaults as they are now, not as they were when the entity was saved.
It returns a `LoadedEntity`: that tree, the ids of its owned objects, and the
ids its References hold.

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

Owned objects load with their owner. One never touched is built from its
default and has no id until it's touched and saved. A Reference loads as a
lazy ghost of its target's concrete class, looked up for every referenced id
in one query, since a ghost must be created as its final class. Its fields
load when first read; its `#[Id]` is readable before that. The `IdentityMap`
keeps one object per id: loading an id twice, or reaching it through two
References, gives the same object.

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
   replaced by another, one dropped from an Owned Collection, and one back at
   its defaults: it has no rows to keep, so it follows its default again.
5. Every wanted level, side table and Collection row is inserted.

An owned object keeps its id while it's the same object, from the
`IdentityMap`. A new object gets a new id, even in the same place.
`NativeValueReader` tells the native writer which object sits at each owned
path, the same way `NativeObjectBuilder` does when building one.

The `IdentityMap` and `#[Id]` properties change only once the transaction
commits. Every object written is remembered with its id. Every entity deleted
is forgotten, and its object, if still around, has its `#[Id]` set back to
null. A failed save leaves both as they were.

Deleting an entity deletes everything it owns, deepest first. A Reference to
it is set to null by its foreign key, and loads as null.

## Migrations

A migration brings stored content in line with schemas that changed after it
was stored. `Migrator::migrate()` runs one, from the native classes read with
`NativeEntityReader::readAll()` and every declared `MigrationSteps`; a fresh
database is a migration from nothing. One migration runs at a time: each
holds a MySQL lock.

**Built**, except where noted inline: storing a `NoType` value; migrating
editor-created types' content and stored values, which stops a migration
that would need it; and checking every output against the new field's stored
form before writing, where for now the database's own strict mode rejects
what doesn't fit, the error naming the entity that failed.

### What a change costs

Content is sparse: an untouched value is null and unflagged, and resolves
live. So most changes cost stored content nothing:

- An added type, field, Collection or owned field gets new columns, tables or
  flags, all empty. Its content follows its default.
- A changed default needs nothing: untouched content follows it live.

Only these touch stored data: a removed field or type, a rename, a retype, and
a reparent or Embed target change that removes a level or columns.

### Policies

A migration policy decides what happens to a difference no fixup declares:

- **Strict**, the default: every difference but an addition must be declared.
  Otherwise the migration throws, and the site stays blocked until it's fixed.
  An addition may still be declared, to give existing entities a value other
  than the default.
- **Developer**: a difference that can't lose stored data applies by itself.
  One that can must still be declared, or the migration throws.
- **Unsafe**: everything applies as best effort. An undeclared rename becomes
  a drop and an add; an undeclared retype resets to the default.

A policy turns each difference left unexplained into the fixup that resolves
it, or into nothing, which blocks the migration. Those fixups run after the
declared steps, and nothing may differ afterwards:

- strict resolves the three additions: a type, a field, a unique group;
- developer adds every lossless change: a table moved, a unique group dropped,
  and a parent changed only by inserting levels, the new chain holding the
  whole old one;
- unsafe resolves everything: a removal is dropped, a change is reset, and a
  rename is a drop and an add.

Other policies implement the same interface. Boot code passes the policy as an
object, and strict applies when it passes none. So a looser policy is always a
visible line of code, never a setting someone can leave on in production.

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
- a changed `editorExtensible`: an editor-created type whose parent stops
  allowing it falls back to having no parent until it's fixed, and nothing
  stored changes.

### Defaults normalize on the next save

A changed default needs no declaration under any policy. A touched value that now equals the new default stays touched until
the entity is next saved. Saving compares again, so it becomes untouched then.
Until that save, it keeps its value through any further default change.

This is intentional for now. Revisit later: a migration step could re-save
every affected entity's touched values against the new defaults. It needs no
new machinery, and changes nothing already stored.

### What a migration touches

A native migration compares the native types stored with the native classes
in code. Each stored schema records its source, native or editor-created, so
a stored type missing from the code is a removed native type only when it's
native.

Editor-created schemas are still updated by a native migration wherever a
declared fixup makes the change obvious: a renamed type used as their parent
or field target, a renamed field they inherit, a field or type dropped with a
named substitute. Editor-created schemas are their own source of truth, so
fixing them directly is allowed. Their content, and their stored values for
inherited fields, move the same way native content does.

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
- `MoveField(from, field, to)`: a field's declaration moved up or down its own
  chain. Its path doesn't change, so its values follow on their own.
- `MoveValues(type, from, to)`: values moved between any two paths of an
  aggregate: across levels, into or out of an Embed, across an owned boundary.
  Data only: the schema changes it goes with are declared next to it.
- `RetypeField::converting(type, field, converter)`: a retype always has a
  converter, even where the old and new stored forms look alike. Nothing
  converts from one type to another implicitly.
- `RetypeField::reset(type, field)`: a readable name for `DropField` followed
  by the field added again, following its default.

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

1. Its touched values are read with the old schemas, owned objects included,
   and resolved against the old defaults: every value in effective form,
   each marked touched or not.
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
against the new field's stored form before anything is written: one that
doesn't fit fails the migration, naming the type, the field and the entity.

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

Storing a `NoType` value, for a field whose old value nothing converts in the
same migration, is decided later, on the same capture.

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
   Every content column is nullable, so old and new shapes coexist in one
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

One transaction for all the data is long on a large database. That's
acceptable for now. If it isn't one day, the fix is batches with a record of
which entities have moved.
