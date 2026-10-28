# API Architecture

Status: a standalone description of how everything outside the backend talks
to it: the admin app, the command line, and later other clients. Updated one
decision at a time.

Built: the operations core (registry, input reader, result writer, errors,
HTTP and command-line transports), and the read operations: `types.list`,
`types.get`, `entities.get`, `entities.query`. Not built yet: writing
entities, signing in, API tokens. Each is noted inline.

Scope:

- **Operations:** every capability the backend offers, named and typed, with
  one contract for every transport.
- **Generic entity access:** reading and writing any entity's values by its
  schema, native or editor-created.
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
enums, nested input objects, and lists of them. A list's item type is read
from its `@param`: `list<T>`, `T[]` or `array<int, T>`, with a class name
resolved through the file's imports. `list<mixed>` takes any JSON value. A
value missing, unknown or of the wrong type fails before the operation
runs, naming its path: `where.0.path`. Every error is reported at once. An
operation never sees untyped arrays as input, except a `list<mixed>`.

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

`OperationContext` gives an operation the caller's `Actor`, a
`PersistenceSession` for it, and the `Persistence` itself for what isn't
about entities, such as checking a migration. The transport builds it from
whoever is calling (see "Authentication").

### Errors

Every transport reports a failure the same way:

| Thrown | Code | HTTP | Details |
|---|---|---|---|
| Thrown | Code | HTTP | Exit | Details |
|---|---|---|---|---|
| `InvalidInput` | `invalid_input` | 400 | 2 | `errors`: a message per path |
| `PermissionDenied` | `permission_denied` | 403 | 3 | `type`, `action`, `paths` |
| `NotFound` | `not_found` | 404 | 4 | `kind` and `key`: what's missing |
| `ValidationFailed` | `validation_failed` | 422 | 5 | `type`, `errors`: messages by path, for a form |
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
identifier; a type it may not read isn't listed. `coconut-cms:types.get`
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
  its target type. A value object's class isn't told.

Not built yet: defaults, which a form creating an entity needs, and
rendering hints, attributes on a native property changing how the admin
shows it.

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

Not built yet. A save sends changes by path, never the whole entity:

```json
{"name": "Racer", "engine.horsepower": 300, "tags": ["red", "fast"]}
```

The backend loads the entity as the caller, applies the changes, and saves it
through the same permission, validation and hidden-field rules as any save.
A change to a path that's missing, unreadable, or of the wrong type is
`invalid_input`, naming the path. A new entity starts from its type's
defaults.

- An owned slot set to `{"type": "shop:turbo"}` gets a new owned object, from
  that type's defaults; set to `null`, it's emptied.
- A Collection is sent whole, as a list. An owned item sent back with its id
  keeps it; one without is new.

A native entity is edited through its object, the way PHP code edits it:

1. It's loaded as the caller, as any native load.
2. Each change is turned from stored form into the property's PHP form, then:
   - a field declaring a setter gets it called:
     `#[Scalar(ScalarType::String), Setter('rename')]` makes a change of
     `name` call `$car->rename($value)`. A setter may refuse a value by
     throwing, change it, or change other fields with it;
   - any other field's property is set.
   A change inside an owned object calls the setter its own class declares.
3. Once every change is applied, a method marked `#[AfterEdit]` is called
   with the paths changed: for a rule spanning fields, such as a new
   country resetting the region. It may change fields or refuse the edit.
   Unreal's `PostEditChangeProperty()` plays the same part.
4. It's saved through the native writer.

Getters aren't part of editing: a value read through a method could differ
from what's stored, and sending it back would change what's stored. They
may come with scripting.

An editor-created entity has no class, so its values are changed directly
(see `DynamicEntity` in `../Storage/PROPOSAL_FOR_V3_DYNAMIC_ENTITIES.md`). It
comes with editor saves: editor-created types are made by them.

## Authentication

The package defines the seam: an `Authenticator` turns a request into an
`Actor`, or into an `AnonymousActor` holding no role. The package depends on
no user library. A project picks its implementation at boot; this project's
adapts `delight-im/auth`, a dependency of the project, not of the package.
Until a project has one, the package's `AnonymousAuthenticator` makes every
HTTP caller anonymous.

Built: the seam and the command line's `SystemActor`. Not built yet:
sessions, CSRF, signing in and out, `--as`, the `delight-im/auth` adapter.

- **Roles.** `delight-im/auth` keeps a user's roles as bits; an `Actor` asks
  `hasRole('editor')`. The project's adapter maps each bit to a role name, as
  the project configures.
- **The admin app** signs in on the same site and holds a session cookie.
  Every API call is a `POST`, so each carries a CSRF token too, handed to the
  app when it loads. Signing in and out are operations themselves,
  `coconut-cms:auth.signIn` and `coconut-cms:auth.signOut`.
- **Other HTTP clients**, scripts and integrations, will send an API token
  tied to a user, in a header (not built yet, and not needed by the admin).
- **The command line** runs as `SystemActor`: whoever can run it already
  holds the server and its database. `--as=<user>` acts as that user instead,
  to see what they see through the same permission rules.
- **An anonymous caller** holds no role. Each operation's permission checks
  decide what it may do. The admin app requires signing in.

## Mounting

A project boots the API once per request, next to persistence, and hands it
its router:

```php
$persistence = Persistence::boot($connection, $modules, ...);
$api = Api::boot($persistence, new DelightAuthenticator($auth, $roleNames), debug: false);
$api->routes($router, prefix: '/api');   // POST /api/{operation}
```

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
