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
them into actual columns, and writes the result to `SchemaFieldValueStore` (see
below) rather than ever carrying them on `EntitySchema` itself.

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

## Flattening fields into columns

`ColumnFlattener` turns one class's own fields into the columns that end up on
its actual table, recursing through Embed with the usual column-name prefixing.

OwningReference and Collection never contribute a column: an OwningReference is
found through the owned row's own `owner`/`owner_field`, and a Collection's
items live in their own child table, one row per item. Both come back as
"external" instead, naming whichever other schema needs its own separate
tracking, so a migration consumer knows to follow them rather than expecting a
column that will never exist.

Resolving an Embed target's own hierarchy fields is an injected lookup, never
hardcoded to native reflection. `SchemaCompiler` builds that lookup itself, once
per batch, from its own already-read blueprints, falling back to `SchemaRegistry`
only for a dependency outside the current batch. A target being editor-created
is one reason this can't be hardcoded; the other, just as real, is that
`SchemaCompiler` already read every native class in its own batch once, at the
top of `compile()` — a hardcoded lookup inside `ColumnFlattener` would read an
Embed target a second time for no reason.

## One field value store, not two

Native and editor-created identifiers need the exact same two things: "what's
the effective value of this field right now," and "what did it last resolve to,
so a migration can detect drift." Building two separate mechanisms for this (one
reflection-backed, one override-table-backed) was a mistake made earlier in this
round and corrected: `SchemaFieldValueStore` is the one mechanism for both.

- `save($identifier, $values)` / `load($identifier)`: a plain `(identifier,
  field) -> value` store, written by the compiler for native (after flattening
  a freshly-read blueprint) and directly by an admin save for editor-created.
- `diff($identifier, $currentValues)`: added/removed/changed against whatever
  was last saved, column for column, not "something in this subtree differs."
- `resolve($scopesInOrder, $field)`: the first explicit value found, checking
  scopes from most to least specific. Building that order — an owning field
  site before its target type, a type before its own parent type — is the
  caller's job, using `EntitySchema`/`FieldDescriptor` to know the chain; the
  store itself never special-cases native vs. editor-created, or type-extends-
  type vs. field-owns-type. It only ever checks what it's told, in order.
