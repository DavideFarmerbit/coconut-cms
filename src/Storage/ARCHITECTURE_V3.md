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
field descriptors, permissions, validators, unique groups, and default values. Two
separate producers build this shape, one per source. A third stage, the compiler,
consumes it.

The compiler does every cross-cutting computation: dependency resolution, cycle
detection, the default-value pin diff, and OwningReference target validation. It
outputs the final `EntitySchema`.

`EntitySchema` holds only what gets persisted: identifier, parent, table,
editorExtensible, fields, fieldPermissions, fieldValidators, uniqueGroups,
prototypeValidators, and the pinned (sparse) default values. No object factory. No
dense, unpinned default values. Those exist only as the producers' intermediate
output, never in the final schema.

*Not yet implemented: the current `EntityRegistrar`/`EntitySchema` code still
carries `defaultInstanceFactory` and dense `defaultValues` directly. Both need to
move into an intermediate shape once the compiler stage exists.*

## The registry is a query, not a batch

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

A stale pin is not just a stale value: it can flip whether a field is pinned at
all. If `Engine`'s own default horsepower is 300, and `AudiEngine` also defaults to
300, `AudiEngine`'s pin correctly stores nothing for horsepower: it matches the
baseline. If `Engine`'s default later changes to 100, `AudiEngine`'s dependency
fingerprint no longer matches, so its schema recompiles, and the pin diff now finds
a real difference, 300 against the new baseline of 100, and starts storing
horsepower explicitly. Recompiling on a fingerprint mismatch always means rerunning
the full pin diff, never reusing a cached pin's shape against a new baseline.

## Default-value pinning

Every field's value is diffed against a baseline. Only the difference gets stored.

- A field declared at this schema's own level has no baseline: always store it.
- A field inherited from a parent schema: compare against the parent schema's own
  value for the same field. Store only if different.
- Scalar, ValueObject, Reference: the raw value, never recursed into. Reference is
  never recursed into at all, since it doesn't own what it points to.
- Embed: recurse into the embedded object's own fields, read through the *declared*
  target class, never the runtime object's actual class. Type erasure: a subclass
  assigned there only contributes the declared class's own fields.
- OwningReference: recurse into the referenced object's own fields, read through
  its *actual* concrete class. Polymorphic: the concrete class itself is recorded
  alongside the diff. If the concrete class differs from the baseline's, there is no
  comparable baseline for the fields; diff against the new class's own intrinsic
  default instead, and always store the field.
- Collection: compared as a whole against the baseline collection, never diffed
  per item. Any difference, in size or in any item, stores the entire collection.
  Items inside, when stored, still use the rules above individually.
