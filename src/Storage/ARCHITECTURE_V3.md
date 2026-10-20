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
(already compiled this request); if it isn't there, that's an error, not a
trigger to compile it on the caller's behalf. Whatever gathers a batch (a
hot-path recompile for native, an admin save for editor) is responsible for
including everything it depends on.

## The registry is a query, not a batch

*Not yet implemented: the current `SchemaRegistry` is a plain in-memory
`register()`/`has()`/`get()` cache, nothing more. `SchemaCompiler` is the only
thing that ever populates it, and a dependency missing from both the current
batch and the registry is an error, never a trigger to recursively compile it.
The querying/fingerprint behavior below is the intended shape once a persisted
`EntitySchema` store exists to query against; it isn't live yet.*

Schemas are not computed all at once. `SchemaRegistry` exposes a per-identifier
query: resolve one schema, recursively resolving whatever it depends on along the
way.

Resolving `X`'s dependencies means calling the same query again for its parent,
every Embed target, and every concrete class recorded under an OwningReference
value. The query doesn't care whether a dependency turns out to be native or
editor-created; it always gets back a compiled `EntitySchema`.

Cycle detection tracks what's currently being resolved on the current call stack,
the same three-state (visiting/visited) rule as a batch topological sort, just
driven by recursion instead of a loop over a precomputed set.

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

## Dependency fingerprints protect against stale caches

Every compiled schema records the fingerprint of each dependency it was built
against: a native dependency's declaring file's mtime/hash, or an editor-created
dependency's own version/`updated_at` column.

`SchemaRegistry::get()` validates these fingerprints before trusting a cache hit.
If a dependency's current fingerprint doesn't match what was recorded, the schema
is stale, regardless of whether it was ever directly edited. Recompile it the same
way as any other miss.

This is why an editor-created entity that embeds a native class gets invalidated
correctly when that native class changes, with no reverse-dependency index needed.
The check only ever looks at direct dependencies; it never has to search for
everything that might depend on the thing that changed.

## Reading a native class's default values into plain data

`NativeEntityReader` reads a native class's default instance into plain,
dense data in the same reflection pass that builds its field descriptors, rather
than walking the class a second time just for values.

- Scalar, ValueObject, Reference: the raw value, never recursed into. Reference
  carries no recursion either way, since it doesn't own what it points to.
- Embed: recurse into the embedded object's own fields, read through the
  *declared* target class, never the runtime object's actual class. Type
  erasure: a subclass assigned there only contributes the declared class's own
  fields.
- OwningReference: recurse into the referenced object's own fields, read through
  its *actual* concrete class. Polymorphic: the concrete class itself is
  recorded alongside its fields, as `{class, fields}`.
- Collection: each item follows the rule for its own item kind, recursively.

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
schemas first, and the registry only for something outside the batch. So a
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
  `(identifier, FieldPath) -> value` store, written by the compiler for native
  and directly by an admin save for editor-created. It knows nothing about
  schemas.
- `diff($identifier, $currentValues)`: added/removed/changed against whatever
  was last saved, path for path, not "something in this subtree differs."

## Resolving a value

`FieldValueResolver::resolve($identifier, $path)` finds a path's effective
value. It needs schemas, so it lives outside the store, which only reads and
writes rows. It is not a repository concern either: the compiler, migrations
and the editor all need the same answer.

It works through sources: (identifier, key prefix) pairs, checked in order.
The first source holding the key wins. A stored null is a value, not a miss.
An identifier's own sources are itself, then each of its parents, with no
prefix. Each step down the path extends them:

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
- `resolveFallback($identifier, $path)`: what `resolve()` would give if
  `$identifier` held nothing at `$path`. Everything else it holds still
  counts, e.g. the class its OwningReference holds.
- `resolveWithoutSubtree($identifier, $path, $subtree)`: the same, with
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
