# Storage v3: Proposal: Dynamic Entities

**Status: decided, not built (2026-10-22).** Native entities save and load
(see "Saving an entity" and "Loading an entity" in `ARCHITECTURE_V3.md`).
This records what was decided for editor-created entities, so it survives
until it's built. Move each part into `ARCHITECTURE_V3.md` once it's built.

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

- **`EntityWriter`'s reconcile.** It deletes every owned entity a save
  doesn't want. A view can't see owned fields declared on editor-created
  levels, so it must not delete what's under them. A view's save limits the
  reconcile to the owned paths on its own levels, the same way it limits the
  level rows it rewrites.
- **`NativeObjectBuilder` and `NativeEntityLoader`.** They create objects
  from the identifier stored for an owned object or a Reference target. An
  editor-created identifier is not a class: both must create its last native
  class instead.
- **`IdentityMap`.** It gains the concrete identifier, next to the id.

## Still open

- `DynamicEntity`'s API: reading and setting fields, and checking a set
  value against the field's descriptor.
- Whether `loadDynamic()` on a fully native entity gives a `DynamicEntity`
  with an empty bag, or the native object.
- How an editor-created entity is created and given its defaults, before it
  was ever saved.
