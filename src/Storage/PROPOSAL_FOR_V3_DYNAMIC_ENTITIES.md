# Storage v3: Proposal: Dynamic Entities

**Status: built (2026-10-30).** Every step of the build plan is built,
and described in `ARCHITECTURE_V3.md`: "Loading an entity", "Saving an
entity" and "Dynamic entities". This proposal stays as the record of the
decisions and their reasons.

## The problem

An editor-created entity type is a prototype an admin builds in the editor.
It may extend a native class, such as `EditorEngine` on top of `Engine`, or
have no native ancestor at all. Native code reaches these entities two ways:

- without asking for it, through a native property typed with a native
  class: an owned `Engine $engine` holding an `EditorEngine`, an Embed, a
  Reference;
- on purpose, when it wants the whole entity: the admin, the editor, generic
  tooling.

## Decided

### No generated PHP classes

An editor-created type is never turned into a PHP class: no generated file,
no `eval`. Its values live in data only.

### Two layers of one mechanism

**The native view is mandatory.** Wherever a PHP type is declared, the object
must be an instance of it. So inside a native object graph, an
editor-created entity loads as its **last native class**: the nearest native
class in its chain. An `EditorEngine` in an `Engine` property loads as an
`Engine`. It's the same type erasure an Embed already has.

**`DynamicEntity` is the view plus the rest.** It holds:

- its identifier;
- optionally, the native view: the last native class's object, so its
  methods are callable;
- a bag for every field the native view doesn't declare.

The bag holds plain values, value objects, nested `DynamicEntity`s for
editor-created Embeds and owned objects, and native objects where the target
is native.

Reading a field reads the native view when its class declares the field, and
the bag otherwise. Each field has one source of truth, and is never held
twice.

A fully editor-created entity, with no native ancestor, has no native view.
Everything is in the bag.

### When each is used

Native code gets the native view wherever a type is declared. Code that
wants the whole entity asks for it explicitly, with something like
`loadDynamic($id)`.

### One reader output feeds both

Both layers are built from the same `LoadedEntity` that `EntityReader`
returns: the identifier, the resolved tree, the owned ids. The native builder
takes the fields of the native levels. The bag takes the rest. This is why
shared code names types by identifier and native code lives in
`Entity/Native/`: `DynamicEntity` plugs in at that line.

A `DynamicEntity` is an ordinary PHP class, so it can be a lazy ghost too.
References to editor-created entities work the same way as native ones.

### Defaults come from the real type

A view still resolves on the real type: an `Engine` view of an `EditorEngine`
resolves on `Subject::ofInstance(EditorEngine, ...)`, because `EditorEngine`
can override `Engine`'s defaults. Only the built fields stop at `Engine`.
Deciding what's touched on save compares against the real type the same way.

### Saving a view never touches what it can't see

A saved view writes only the levels its class covers. It leaves the
editor-created levels' rows exactly as they are, and never rewrites
`concrete_identifier` to its own class.

To know this, an object must remember what it's a view of. So the
`IdentityMap` stores the concrete identifier next to the id.

## What this asks of what's built

- **`EntityWriter`.** It deletes every owned entity a save doesn't want,
  and rewrites every row. A view can't see fields declared on
  editor-created levels, so a save must keep them, and what's owned under
  them. The writer fills them from what's stored, under the lock: see "A
  view keeps what it can't see".
- **`NativeObjectBuilder` and `NativeEntityLoader`.** They create objects
  from the identifier stored for an owned object or a Reference target. An
  editor-created identifier is not a class: both must create its last native
  class instead.
- **`IdentityMap`.** It gains the concrete identifier, next to the id.

### Reading is an EntityView (decided)

`DynamicEntity` implements `EntityView`, the read-only view of an entity's
values that validators already receive: `type()`, `isA()`, `has()`, `get()`.
So code that only reads, a validator, a template, an export, takes an
`EntityView`, and works on a `DynamicEntity` and on a save's snapshot alike.
`DynamicEntity` adds setting fields, saving, and its native view.

`get()` gives a value as a property holds it: an enum case, a
`DateTimeImmutable`, a value object. An Embed or an owned object comes back as
another `EntityView`, always, even where its type is native: one shape for a
field, whatever its type's source. Native code wanting the native object asks
`DynamicEntity` for it. A path reaches through them: `get('engine.horsepower')`.

### Writing (decided)

`set(path, value)` takes a value in the form `get()` gives: an enum case,
a `DateTimeImmutable`, a value object; an Embed or an owned object as an
`EntityView`, a `DynamicEntity` or a native object where its type is
native; a Reference as its target, in either form. One shape for reading
and writing.

A value is checked when it's set, the way PHP checks a typed property: its
kind, its scalar or enum type, whether it may be null, and that an Embed,
an owned object or a Reference's target is the field's target type or a
subtype of it. A wrong value throws `InvalidValue`, naming the path, where
it was set. A native object gets the same checks from PHP itself, so both
fail alike.

Validation rules stay on save, as for native entities: validators and
uniqueness check the whole aggregate then.

A field declared on a native level is set through the native view, so its
setter runs: the class guards its own field. A field declared on an
editor-created level goes into the bag. `afterEdit()` runs on save, given
every path changed, the bag's included.

### One shape for the whole entity (decided)

`loadDynamic(id)` always gives a `DynamicEntity`, whatever the type. For a
fully native entity, its native view is the whole object, and its bag is
empty. Code that wants any entity whole, the admin's API, the schema
editor, an export, gets one shape, and keeps working when a native type
gains an editor-created subtype.

There's one object per entity. `loadDynamic(id)` wraps the very object
`load(id)` gives, from the `IdentityMap`, and there's one `DynamicEntity`
per id too: native code and generic code editing an entity in one request
see each other's changes. Saving either saves what it holds: the native
object its native levels, as above; the `DynamicEntity` its native levels
through its view, and its editor-created levels from its bag.

## Build plan

Each step is built and tested before the next. The tests make
editor-created types the way the migration tests do, with
`MigratorEditorTypes`, until editor saves exist.

1. **Native views load.** Built (2026-10-30), now in `ARCHITECTURE_V3.md`
   "Loading an entity": `NativeViewClasses` gives an identifier's last
   native class, the loader, the builder and References build it, the
   `IdentityMap` keeps each entity's real type, and `load()` of an entity
   with no native ancestor throws, naming `loadDynamic()`.
2. **A native view saves what it sees.** Built (2026-10-30), now in
   `ARCHITECTURE_V3.md` "Saving an entity": `EntityWriter::writeWith()`
   and `UnseenValues`. See "A view keeps what it can't see".
3. **`DynamicEntity` reads.** Built (2026-10-30), now in
   `ARCHITECTURE_V3.md` "Dynamic entities": `loadDynamic(id)`,
   `DynamicEntities`, the view and the bag, one per entity and per object.
4. **`DynamicEntity` writes.** Built (2026-10-30), now in
   `ARCHITECTURE_V3.md` "Dynamic entities": `set()`, `DynamicValues`,
   `InvalidValue`, `Setters` shared with `NativeEditor`.
5. **`DynamicEntity` saves.** Built (2026-10-30), now in
   `ARCHITECTURE_V3.md` "Dynamic entities": `DynamicEntityWriter`,
   `save()` taking a `DynamicEntity`.
6. **`newDynamic(type)`.** Built (2026-10-30), now in `ARCHITECTURE_V3.md`
   "Dynamic entities".
7. **The API edits through it.** Built (2026-10-30), now in
   `ARCHITECTURE_V3.md` "Dynamic entities": `StoredFormEditor` replaces
   `NativeEditor`; `entities.create`, `entities.update` and
   `entities.delete` work for editor-created types too.

### A view keeps what it can't see (decided)

The writer fills what a view can't see from what's stored, then writes
the whole aggregate as for any save. One write path: the rows builder and
the reconcile don't change.

The rule holds for each entity in the aggregate, the root and every owned
object, at any depth. An entity known by id whose real type goes deeper
than its object's class gets every path that class can't see from what's
stored for that id: the values of its editor-created levels, and the owned
objects under their fields, with their ids and everything under them. It
happens after the aggregate is locked, so no other save comes between the
read and the write. The entity is written as its real type: its
`concrete_identifier` is never rewritten to its object's class.

It goes by entity id, not by path. A `Boat` holding an `EditorBoatEngine`
in `Engine $engine` loads it as a `BoatEngine`, its last native class.
Native code changing the engine's horsepower and saving the boat keeps
the engine's editor-created fields, and whatever they own. An engine moved
within an owned Collection keeps them too. An engine replaced by a
`new BoatEngine()` is a new entity of a native type: the old one goes, with
what it held, as any owned object replaced.

### One editing path for the API (decided)

Every API edit goes through a `DynamicEntity`, native types included.
`entities.update` loads one with `loadDynamic()`, `entities.create` makes
one with `newDynamic()`. The editor turns each change, a path and a value
in stored form, into its PHP form, and calls `set()`. `set()` does the
rest: the checks, the setter on a native level, the bag on an
editor-created one; `afterEdit()` runs on save. So a native type and an
editor-created subtype of it behave alike through the API, and setters
and `afterEdit()` are called in one place. `NativeEditor` keeps only the
stored-form conversion; what it does with setters and `afterEdit()` moves
into `DynamicEntity`.

### A new entity (decided)

`newDynamic(type)`, on a `PersistenceSession`, gives a new `DynamicEntity`
of any type, native or editor-created. Its lifetime is a `new Car()`'s:

- until its first save it has no id, and the `IdentityMap` doesn't know
  it. Dropped unsaved, nothing was stored;
- its first save gives it an id. From then on it's the one object for that
  id: `loadDynamic(id)` gives it back, and `load(id)` its native view;
- the owned objects in it are new too, and get their ids on that save.

Its values are its type's defaults, resolved on the real type: an
editor-created type's own defaults override its native ancestors'. Nothing
is touched until something is set.

Its native view is built the way loading builds one. A clone of the last
native class's default instance gives the object its non-field state;
then every field is set from the defaults resolved on the real type. So a
default the editor-created type overrides on a native field is in the view:
an `EditorEngine` setting `Engine`'s horsepower to 300 gives a view whose
`$horsepower` is 300. The constructor doesn't run per object, as on
loading: state each object needs is copied by `__clone()`, and a value
like a creation date comes from an initializer or `afterEdit()`.
