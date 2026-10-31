# API Architecture

Status: a standalone description of how everything outside the backend talks
to it: the admin app, the command line, and later other clients. Updated one
decision at a time.

Built: the operations core (registry, input reader, result writer, errors,
HTTP and command-line transports), the read operations (`types.list`,
`types.get`, `entities.get`, `entities.query`), and the write operations
for native types (`entities.create`, `entities.update`, `entities.delete`),
changing editor-created types (`schema.preview`, `schema.save`,
`schema.conversions`), and authentication (sign-in operations, CSRF,
`--as`). Not built yet: API tokens. Each is noted inline.

Scope:

- **Operations:** every capability the backend offers, named and typed, with
  one contract for every transport.
- **Generic entity access:** reading and writing any entity's values by its
  schema, native or editor-created.
- **Changing types:** saving an admin's changes to editor-created types.
- **Authentication:** how a request or a command becomes an `Actor`.

- **Mounting:** how a project serves the API, and the command line.

The admin app has its own document: `../Admin/ARCHITECTURE.md`.

## Everything goes through operations

The backend is always reached through operations, never through a page or a
handler of its own. An operation is one capability: query entities, save
one, read a schema, check a migration. HTTP and the command line are thin
transports over the same operations. So whatever the admin app can do, a
command can do too, with the same input, the same checks and the same
result.

An operation is a class, registered under an identifier, "namespace:key",
the same form entity types and services use:

```php
#[Operation('coconut-cms:entities.query')]
final readonly class QueryEntities
{
    public function run(QueryEntitiesInput $input, OperationContext $context): EntityPage { ... }
}
```

The attribute is the only mark: PHP can't type a generic `run()`, so an
interface would add nothing. Boot checks each class instead: the
attribute, a constructor with no required arguments, and a `run()` taking
an `OperationInput` and the `OperationContext` and returning an
`OperationResult`. A class failing a check stops the boot, naming what's
wrong.

- Over HTTP: `POST /api/coconut-cms:entities.query`, with a JSON body.
- On the command line: `coconut coconut-cms:entities.query --type=...`.

Operations are declared by modules, next to their entity classes: the core
module ships the built-in ones, and a project module adds its own. By
convention, an operation's namespace is its module's name; nothing enforces
it. Two operations with one identifier are rejected at boot.

### Input

An operation's input is a readonly class with typed constructor parameters:

```php
final readonly class QueryEntitiesInput implements OperationInput
{
    /** @param list<ConditionInput> $where */
    public function __construct(
        public string $type,
        public array $where = [],
        public int $limit = 20,
        public ?string $after = null,
    ) {}
}
```

One shared reader builds it from a JSON body or from command-line arguments,
by reflection over the constructor: strings, ints, floats, bools, backed
enums, nested input objects, and lists and maps of them. A list's item
type is read from its `@param`: `list<T>`, `T[]` or `array<int, T>`; a
map's from `array<string, T>`, a JSON object with keys of its own. A class
name is resolved through the file's imports. `mixed` takes any JSON value.
A value missing, unknown or of the wrong type fails before the operation
runs, naming its path: `where.0.path`. Every error is reported at once. An
operation never sees untyped arrays as input, except where its `@param`
says `mixed`.

Each input class is checked at boot too: a parameter of a type no input can
hold, a union, an array with no `@param`, stops the boot.

### Result

An operation returns a readonly object implementing `OperationResult`. One
shared writer turns its public properties into JSON, by the same rules in
reverse: a backed enum as its value, a list as a JSON array. An array whose
`@param` says `array<string, T>` is always a JSON object, even empty. What
a result holds may be any readonly object, written the same way. The
command line prints the same JSON, or a table for a result implementing
`ForHumans`.

### Context

`OperationContext` gives an operation:

- the caller's `Actor`, and a `PersistenceSession` for it;
- the `Persistence` itself, for what isn't about entities, such as checking
  a migration;
- the project's `Authenticator`, for signing in and out;
- the project's `Kernel`, its services bound by `Identifier`: what an
  operation needs beyond persistence, such as a mailer or the user
  library. `$context->kernel->get(Identifier::of('demo', 'auth'))` gives an
  `object`; the operation narrows its type itself.

An operation is built with no constructor arguments for each call: what it
uses comes through `run()`, its input and its context. The transport builds
the context from whoever is calling (see "Authentication").

### Errors

Every transport reports a failure the same way:

| Thrown | Code | HTTP | Exit | Details |
|---|---|---|---|---|
| `InvalidInput` | `invalid_input` | 400 | 2 | `errors`: a message per path |
| `InvalidOperation` | `invalid_input` | 400 | 2 | `errors`: the message at `operations.N`, the operation as sent |
| `SignInFailed` | `sign_in_failed` | 401 | 7 | none: one message, whatever was wrong |
| `PermissionDenied` | `permission_denied` | 403 | 3 | `type`, `action`, `paths` |
| `Forbidden`, `SchemaEditingDenied` | `permission_denied` | 403 | 3 | none: the operation refuses its caller outright |
| `CsrfRejected` | `csrf_failed` | 403 | 9 | none: reload the page for a new token |
| `NotFound` | `not_found` | 404 | 4 | `kind` and `key`: what's missing |
| `StaleSchemaRevision` | `stale_revision` | 409 | 10 | `sent`, `current`: the revisions |
| `ValidationFailed` | `validation_failed` | 422 | 5 | `type`, `errors`: messages by path, for a form |
| `SchemaSaveRefused` | `schema_save_refused` | 422 | 11 | `reason`, then `blocked`, `breaks` or `failures` |
| `TooManyAttempts` | `too_many_requests` | 429 | 8 | none |
| `PersistenceUnavailable` | `unavailable` | 503 | 6 | `reason`: `migration_pending` or `migration_unfinished` |
| anything else | `internal` | 500 | 1 | the message only in debug mode |

Over HTTP, the body is `{"result": ...}` on success, or `{"error": {"code",
"message", "details"}}` on failure. A command exits with the status in the
table, and prints the same error. An operation throws `InvalidInput` itself
for what only it can check, such as a query's limit.

## Generic entity access

The admin lists and edits any type, so it can't know each type's PHP class.
It reads a type's schema to know what to show, and reads and writes an
entity's values by path. The same operations serve native and
editor-created types.

### Reading a schema

`coconut-cms:types.list` gives every type the caller may read, sorted by
identifier; a type it may not read isn't listed. It gives them as
`{"types": [...], "revision": 3}`: the revision is the schema revision the
types are at. A schema save sends it back. `coconut-cms:types.get`
gives one, refusing a type it may not read. A type reads as:

```json
{
  "identifier": "shop:car", "parent": "shop:vehicle", "editorExtensible": false,
  "may": {"read": true, "create": true, "update": true, "delete": false},
  "fields": {
    "price": {"read": true, "write": false, "value": {"kind": "scalar", "nullable": false,
      "scalarType": "int", "enumValues": null, "target": null, "item": null}},
    "wheels": {"read": true, "write": true, "value": {"kind": "collection", "nullable": false,
      "item": {"kind": "embed", "target": "shop:wheel", ...}}}
  }
}
```

- `fields` holds the type's own fields; an inherited one is on the
  ancestor declaring it.
- A field the caller may not read is still listed, with `read` false: its
  name isn't secret, its values are.
- An enum scalar lists its values; an Embed, a Reference or an owned object
  its target type; a value object its registered identifier, never its
  class.
- `defaults` gives what a new entity of the type holds, inherited fields
  included, in the form `entities.get` gives values, with no ids: what an
  editor starts a new object or item from. A field the caller may not read
  is left out of it too.

Not built yet: rendering hints, attributes on a native property changing
how the admin shows it.

### Reading an entity

`coconut-cms:entities.get` reads one standalone entity by id. Reads work at
the values level: the session reads the stored values, as for any type,
and builds no PHP object. So they serve editor-created types too, with the
same rules as a native load:

- a type the caller may not read is refused;
- a field the caller may not read is left out, never sent as null;
- an owned entity is read through its owner: asked for by its own id, it's
  not found.

An entity reads as its type, its id, and its values by field:

- a scalar, an enum or a value object in stored form, the form JSON holds;
- an Embed, or an owned object, as a nested `{type, id, values}`, an
  Embed's id null;
- a Reference as `{type, id}`, so the admin can open its target, or null
  when its target is gone;
- a Collection as a list of its items, in the same forms.

### Querying entities

`coconut-cms:entities.query` gives a page of a type's standalone entities,
its subtypes' included:

```json
{
  "type": "shop:car",
  "where": [{"path": "price", "operator": "greater", "values": [100]}],
  "orderBy": [{"path": "name", "direction": "descending"}],
  "limit": 20,
  "after": null
}
```

- `where` and `orderBy` name paths the way a query does: "price",
  "chassis:weight", "maker.name". Operators: `equal`, `notEqual`,
  `greater`, `greaterOrEqual`, `less`, `lessOrEqual`, `in`, `isNull`,
  `isNotNull`, `like`.
- Values are in stored form, as `entities.get` gives them: an id for a
  Reference. Each is checked against its field, the way a migration's
  values are.
- `limit` goes from 1 to 100. The result holds `items`, each read as
  `entities.get` reads it, and `nextCursor`, which `after` takes for the
  next page; null on the last.
- A subtype the caller may not read is left out. A path through a field it
  may not read fails as if the field didn't exist.

Not built yet: conditions on a Collection's items (`whereHas`,
`whereDoesntHave`, `whereCount`).

### Writing an entity

Three operations write, each sending changes by path, never the whole
entity:

- `coconut-cms:entities.create {type, changes}`: a new standalone entity,
  from its type's defaults.
- `coconut-cms:entities.update {id, changes}`: a standalone entity loaded
  as the caller. An owned one is changed through its owner's paths: asked
  for by its own id, it's not found.
- `coconut-cms:entities.delete {id}`: a standalone entity, with everything
  it owns.

Create and update give the entity back as `entities.get` reads it, so a
form can refresh. `changes` maps paths to values in stored form, the form
a read gives:

```json
{"name": "Racer", "chassis:weight": 1200, "engine.horsepower": 300,
 "engine": {"type": "shop:turbo_engine", "values": {"boost": 2}},
 "tags": ["red", "fast"], "maker": "01a1…"}
```

- A scalar or a value object as stored; a Reference as its target's id, a
  standalone entity of the field's type.
- An Embed as `{"values": {...}}`, a new one from its type's defaults.
- An owned slot as `{"type": ..., "values": {...}}`, a new owned object of
  that type from its defaults; with the id of the object already there,
  `{"type", "id", "values"}` keeps it and changes its values. Null empties
  it.
- A Collection as a whole list, each item in the same forms. An owned item
  sent with the id of one of its items keeps it; one without is new. A path
  reaches inside one item: `"wheels.1.size"`, `"spares.0.horsepower"`.
- `values` maps paths from that object, in the same forms.

Changes apply in order, so `"engine"` set to a new object can be followed
by `"engine.horsepower"`. On the command line, past `--changes.` the rest
of the option is the path: `--changes.engine.horsepower=300`, and an
object is given as JSON: `--changes.engine='{"type": "shop:engine"}'`.

The edited entity is saved through the same permission, validation and
hidden-field rules as any save. A change to a path that's missing,
unreadable, or of the wrong type is `invalid_input` at `changes.<path>`.

A native entity is edited through its object, the way PHP code edits it:

1. It's loaded as the caller, as any native load.
2. Each change is turned from stored form into the property's PHP form: an
   enum's case, a `DateTimeImmutable`, a value object through
   `fromStorage()`, a Reference's loaded target once its type is checked, a
   new owned object or null, a Collection's whole new list. Then:
   - a field declaring a setter gets it called:
     `#[Scalar(ScalarType::String), Setter('rename')]` makes a change of
     `name` call `$car->rename($value)`. A setter may refuse a value by
     throwing, change it, or change other fields with it;
   - any other field's property is set.
   A change inside an owned object, `engine.horsepower`, calls the setter on
   the owned object.
3. Once every change is applied, each object changed that implements
   `AfterEdit` gets `afterEdit($paths)`, with the paths changed relative to
   it: for a rule spanning fields, such as a new country resetting the
   region. It may change fields or refuse the edit. An owned object gets
   its own paths, its owner every path. Unreal's
   `PostEditChangeProperty()` plays the same part.
4. It's saved through the native writer.

Setters and `afterEdit()` are called the way PHP code calls them, on the
object itself: a subclass overriding one gets its own version, whatever
path reached the object. So:

- **A setter** is named only on the property, by the class declaring the
  field. Boot checks that the method exists and takes one required
  parameter accepting the property's type. It's called from the declaring
  class's scope, so any visibility works, with PHP's rules: a public or
  protected setter is overridden by a subclass's method; a private one
  always runs as the declaring class wrote it. The base class's author
  chooses.
- **`AfterEdit`** is an interface, one method per class chain, with PHP's
  override rules. A class overriding an ancestor's `afterEdit()` decides
  whether to call `parent::afterEdit()`; nothing forces it, since skipping
  the parent's rule can be right. If a parent starts implementing it after
  a child already has `afterEdit()`, the child's method replaces the
  parent's unless it calls it.

A setter or `afterEdit()` refuses an edit by throwing `EditRefused`, with a
message: it answers `validation_failed`, naming the path. Any other
exception is `internal`, so a bug never shows as a form message.

Getters aren't part of editing: a value read through a method could differ
from what's stored, and sending it back would change what's stored. They
may come with scripting.

An editor-created entity has no class of its own (see "Dynamic entities"
in `../Storage/ARCHITECTURE_V3.md`). Every edit goes through a
`DynamicEntity`, whatever the type: `entities.create` makes one with
`newDynamic()`, `entities.update` and `entities.delete` load one with
`loadDynamic()`. `StoredFormEditor` turns each change into its PHP form and
calls `set()`. So a native type and an editor-created subtype of it edit
alike. Each change goes to the level declaring its field:

- a native level's field goes through the native view, the object of the
  entity's last native class: its setters and `afterEdit()` run;
- an editor level's field goes into the entity's bag, directly: no PHP code
  runs for it.

`afterEdit()` on the native view gets every path changed, the bag's
included, so a native rule can react to editor fields. A type with no
native ancestor runs no hook; editor-defined rules may come with scripting.

## Changing types

`coconut-cms:schema.save` saves an admin's changes to editor-created types,
as a migration (see the storage document, "Editor saves are migrations").
`coconut-cms:schema.preview` takes the same input and changes nothing. Both
need the schema-editor role the project names at `Persistence::boot()`. The
input:

```json
{"revision": 3, "acceptBreaks": false, "operations": [
  {"op": "renameField", "type": "shop:boat", "old": "length", "new": "size"},
  {"op": "addField", "type": "shop:boat",
   "field": {"name": "masts", "kind": "scalar", "scalarType": "int", "nullable": false},
   "default": 1,
   "initializer": {"kind": "coconut-cms:constant", "value": 2}}
]}
```

- `revision` is the one `types.list` gave. A save on another one is
  refused with `stale_revision`.
- `operations` are the admin's operations, in the order made, undone ones
  included: the server normalizes them. `op` names each one:
  `createType` (`type`, `parent`), `renameType` (`old`, `new`),
  `deleteType` (`type`), `reparent` (`type`, `parent`, `initializer`: a
  level initializer), `addField` (`type`, `field`, `default`,
  `initializer`), `removeField` (`type`, `field`), `renameField` (`type`,
  `old`, `new`), `moveField` (`type`, `field`, `to`, `default`,
  `initializer`), `makeRequired` (`type`, `field`), `retypeField` (`type`,
  `field`, `converter`, `default`), `setDefault` (`type`, `path`, `value`),
  `inheritDefault` (`type`, `path`). An unknown key is refused.
- A field is described as `types.get` describes what a field holds, with
  its `name`. An enum Scalar names its enum by identifier, `"enum":
  "shop:color"`; a value object, its `target`, the same way. A
  Collection's `item` has no name.
- A default is in stored form, as `entities.update` writes values. No
  `default` key gives none; `"default": null` pins null.
- A converter or initializer is `{"kind": <identifier>, ...its
  parameters}`. Modules register them (see the storage document); the
  built-ins are `coconut-cms:constant` (`value`), `coconut-cms:copy`
  (`path`), `coconut-cms:number-to-text`, `coconut-cms:text-to-number`
  (`onFailure`), `coconut-cms:text-to-enum` (`onFailure`),
  `coconut-cms:date-to-date-time`. What a converter gives, such as the
  number type or the enum, is the new field's: never a parameter.
- `acceptBreaks` says the admin saw the breaks the preview listed.

Each error names its path: `operations.2.field.kind`. An operation that
can't apply where it stands is refused at `operations.N`.

Both answer the same report: `revision` (the new one, once saved),
`operations` (normalized, in the same form: what runs), `fixups`
(described), `breaks` (`type`, `description`, `setAside`: the fixup, or
null when blocked), `blocked`, `failures` (why each entry can't move, by
its id), and `moved`. A save the policy blocks, with breaks not accepted,
or with entries that can't move is refused with `schema_save_refused`:
`reason` is `blocked`, `breaks_not_accepted` or `entries_fail`, beside the
same lists.

`coconut-cms:schema.conversions` lists the converters and initializers the
modules register, for the editor to offer, in three groups: `converters`
for a retype, `initializers` for a field added or moved up, and
`levelInitializers` for a reparent. Each gives its `kind` (the identifier
to send), `from` (the fields a converter takes; null otherwise), `to` (the
fields it gives or fills; null for a level initializer), and `parameters`:
each
one's `name`, `type` (`string`, `int`, `float`, `bool`, `mixed`, `enum`
with its `choices`, or `registered_enum`), `nullable`, `optional` and
`default`. A signature has the keys `types.get` describes a field with,
`{"kind", "scalarType", "target", "item"}`, where null matches any; or it's
a union, `{"oneOf": [...]}`, matching any of its signatures. The editor can
match a signature against a field itself, with those rules, or ask this
operation to. The input may give `from` and
`to`, what a field holds now and will hold, as `types.get` describes it,
without a name. Then only what fits is listed: with `from`, converters
taking it and giving `to`; with `to` alone, converters giving it and
initializers filling it. Level initializers are listed only when neither
is given.

## Authentication

The package defines the seam, `Authenticator`, and depends on no user
library. A project implements it over the one it picks, and gives it at
boot; this project's adapts `delight-im/auth`, a dependency of the project,
not of the package. The interface has four methods:

- `actorFor(Request)`: the caller, a `User` signed in or an
  `AnonymousActor` holding no role;
- `signIn(login, password, remember)`: signs the current caller in, by
  email or username as the project allows. It throws `SignInFailed`, one
  message whatever was wrong, so a caller can't tell which users exist; or
  `TooManyAttempts` while signing in is throttled;
- `signOut()`;
- `userNamed(login)`: a user without signing in, for `--as`.

A `User` is the package's actor for a signed-in caller: its id, a name to
show, and its roles by the names permissions use. Until a project has users,
the package's `AnonymousAuthenticator` signs nobody in.

- **Roles.** `delight-im/auth` keeps a user's roles as bits. The project's
  adapter names each bit, as the project configures; an `Actor` asks
  `hasRole('editor')`.
- **Operations.** `coconut-cms:auth.signIn {login, password, remember}`,
  `coconut-cms:auth.signOut`, and `coconut-cms:auth.me`, which the admin
  asks first, each give `{"user": {id, name, roles}}`, or `{"user": null}`
  for nobody.
- **The admin app** signs in on the same site and holds the session cookie.
  Every API call over HTTP sends the session's CSRF token in the
  `X-CSRF-Token` header, or is refused with `csrf_failed`. The page serving
  the client hands the token over: `$api->csrfToken()`, which the admin page
  embeds. No operation gives it out. The package's `SessionCsrf` keeps it in
  the PHP session; signing in or out keeps it.
- **Other HTTP clients**, scripts and integrations, will send an API token
  tied to a user, in a header, in place of the cookie and the CSRF token.
  Not built yet, and not needed by the admin.
- **The command line** runs as `SystemActor`: whoever can run it already
  holds the server and its database. `--as=<login>` acts as that user
  instead, to see what they see through the same permission rules.
- **An anonymous caller** holds no role. Each operation's permission checks
  decide what it may do. An operation its caller may not run at all throws
  `Forbidden`. The admin app requires signing in.

## Mounting

A project boots the API once per request, next to persistence, and hands it
its router:

```php
$persistence = Persistence::boot($connection, $modules, ...);
$kernel = new Kernel();   // the project's services, for its operations
$api = Api::boot($persistence, new DelightAuthenticator($auth, $pdo), $kernel, debug: false);
$api->routes($router, prefix: '/api');   // POST /api/{operation}
```

`Api::boot()` also takes a `CsrfGuard`, the session-backed `SessionCsrf`
unless given.

Operations come from modules: `Module` has `operations()`, next to
`classes()` and `steps()`, listing its operation classes. The Api takes the
modules from persistence, the core module first; the core module ships the
built-in ones. Booting checks every operation and touches no database.

The command line is the package's `coconut` binary, `vendor/bin/coconut`. It
boots the project from a file the project provides, `coconut.php` in the
directory it's run from, or the file `COCONUT_BOOT` names. That file
returns the same `Api`. Then:

- `vendor/bin/coconut` lists every operation, with its input.
- `vendor/bin/coconut coconut-cms:entities.query --type=shop:car --limit=5`
  runs one. Each `--path=value` sets the input at a dotted path, a number
  for a list's index: `--where.0.path=price --where.0.operator=greater
  --where.0.values.0=100`. A value is read from its text: "5" for an int,
  "true" for a bool; a `mixed` value as JSON when it is JSON, "200" as 200,
  as text otherwise.
- `--json='{...}'` gives the whole input as JSON instead, the way HTTP
  sends it.
- `--output=json` prints JSON even for a result shown as a table.
