# API Architecture

Status: a standalone description of how everything outside the backend talks
to it: the admin app, the command line, and later other clients. Updated one
decision at a time. Nothing here is built yet; where that changes, it's noted
inline.

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
final readonly class QueryEntities implements Operation
{
    public function run(QueryEntitiesInput $input, OperationContext $context): EntityPage { ... }
}
```

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
by reflection over the constructor: scalars, enums, nested input objects,
and lists of them, a list's item type read from its `@param`. A value
missing, misspelled or of the wrong type fails before the operation runs,
naming its path: `where.0.field`. An operation never sees arrays as input,
only its typed input.

### Result

An operation returns a readonly result object. One shared writer turns it
into JSON, by the same rules in reverse. The command line prints the same
JSON, or a table for a result implementing `ForHumans`.

### Context

`OperationContext` gives an operation a `PersistenceSession` for the
caller's actor, and the `Persistence` itself for what isn't about entities,
such as checking a migration. The transport builds it from whoever is
calling (see "Authentication").

### Errors

Every transport reports a failure the same way:

| Thrown | Code | HTTP | Details |
|---|---|---|---|
| bad input | `invalid_input` | 400 | a message per path |
| `PermissionDenied` | `permission_denied` | 403 | the type and action, or the field paths |
| not found | `not_found` | 404 | what's missing |
| `ValidationFailed` | `validation_failed` | 422 | errors by path, for a form |
| `PersistenceUnavailable` | `unavailable` | 503 | a migration pending or unfinished |
| anything else | `internal` | 500 | a message in debug mode only |

Over HTTP, the body is `{"result": ...}` on success, or `{"error": {"code",
"message", "details"}}` on failure. A command exits with a non-zero status
for each code, and prints the same error.

## Generic entity access

The admin lists and edits any type, so it can't know each type's PHP class.
It reads a type's schema to know what to show, and reads and writes an
entity's values by path. The same operations serve native and
editor-created types.

### Reading a schema

`coconut-cms:types.list` and `coconut-cms:types.get` give a type's
descriptors in the shape the schema codec stores: fields, their kinds,
targets, nullability and defaults, and the permissions as they apply to the
caller. Rendering hints, attributes on a native property changing how the
admin shows it, are for later.

### Reading an entity

An entity reads as its type, its id, and its values by field, by the rules
`EntityView` already follows:

- a scalar, an enum or a value object in stored form, the form JSON holds;
- an Embed, or an owned object, as a nested object with its own type; an
  owned one also with its id;
- a Reference as `{type, id}`, so the admin can open its target;
- a field the caller may not read is left out, never sent as null.

### Writing an entity

A save sends changes by path, never the whole entity:

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
`Actor`, or into an anonymous one holding no role. The package depends on no
user library. A project picks its implementation at boot; this project's
adapts `delight-im/auth`, a dependency of the project, not of the package.

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
$api = Api::boot($persistence, $modules, new DelightAuthenticator($auth, $roleNames));
$api->routes($router, prefix: '/api');   // POST /api/{operation}
```

Operations come from modules: `Module` gains `operations()`, next to
`classes()` and `steps()`. The core module ships the built-in ones.

The command line is the package's `coconut` binary, `vendor/bin/coconut`. It
boots the project from a file the project provides, `coconut.php`, which
returns the same `Api`, and runs the operation named: `vendor/bin/coconut
coconut-cms:entities.query --type=shop:car`.
