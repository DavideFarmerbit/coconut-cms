# Admin Architecture

Status: a standalone description of the admin app, the client side of the
CMS. Updated one decision at a time.

Built: the build, the page with its import map and config, the public
module, the base and light themes, the sign-in screen, the shell with its
panels, splitters and one tab group, tool and tab type registration, and
the Types tool, loading modules' admin code, and editing native entities'
properties. Not built yet: dragging icons and tabs, menus from manifests,
uploaded and browser plugins, the layout kept per user on the server,
edits saved elsewhere while an entity is edited. Each is noted inline.

The admin is a client-side app. It talks to the backend only through the API
(`../Api/ARCHITECTURE.md`): every list, form and save is an operation, so the
admin can do nothing the command line can't. It's written in strict
TypeScript, with SCSS, built by Vite, and uses no runtime framework. Its
sources are in the package's `admin/`, next to the PHP's `src/`.

## Mounting

A project serves the admin from its router, next to the API:

```php
$admin = Admin::boot($api, buildDir: __DIR__ . '/public/dist/admin', theme: BundledTheme::Light);
$admin->routes($router, prefix: '/admin');
```

`buildDir` is where the admin was built, on disk. `Admin::boot()` also
takes `assetsUrl`, where the web server serves that folder (`/dist/admin/`),
and `apiUrl`, where the API is mounted (`/api`). A theme is a bundled one or
a stylesheet's URL.

The admin is a single page. `routes()` registers `GET /admin`, the page
starting the app, never cached. It holds:

- the base stylesheet, then the theme, then the modules' stylesheets;
- an import map resolving `@coconut-cms/admin` (see "Plugins");
- the app's config, as JSON in `<script id="coconut-admin-config">`: the
  API's URL, the session's CSRF token, and each module's admin code entry;
- the app's module script.

The admin's built files are served by the web server, from
`public/dist/admin/` (see "Assets"). When they aren't there, the page says
how to build them. The project picks the prefix. Everything after the page
loads goes through the API.

## Signing in

The app asks `coconut-cms:auth.me` first. Nobody signed in gets the sign-in
form, an email or username, a password and "remember me", which calls
`coconut-cms:auth.signIn` and tells why it failed. A signed-in user gets
the shell; the menubar names them, and signs them out.

## Assets

Nothing generated is committed to git, so the package ships the admin's
sources only, with its own `package.json`, TypeScript and Vite config. A
project builds it, with every module's admin code, with one command, into
`public/dist/admin/`; the web server serves those files. A project needs
Node for this, and installs the package's Node dependencies once:

```
npm --prefix vendor/xylo-is-coding/coconut-cms install
npm --prefix vendor/xylo-is-coding/coconut-cms run build:admin -- --project "$PWD"
```

The build (`admin/build.mjs`):

1. asks the project which modules have admin code, through
   `vendor/bin/coconut coconut-cms:admin.code`, an operation for the
   command line only;
2. builds the admin's four entries, the app, the public module, the base
   stylesheet and the light theme, with each module's source entry, in one
   Vite build;
3. copies each module's built folder into `plugins/<module>/`;
4. writes `plugins.json`: each module's entry script and stylesheets, which
   the page reads, leaving out a module no longer booted.

The build's URLs are relative, so the output can be served from any
folder. Without `--project`, it builds the admin alone, into the package's
`dist/admin`. In the package, `npm run check:admin` type-checks, and
`npm test` runs the admin's tests. A project type-checks its own admin code
with a `tsconfig.json` mapping `@coconut-cms/admin` to the package's
`admin/src/public/index.ts`.

Each build under `public/dist/` keeps its own folder and empties only that
one: the admin's is `public/dist/admin/`, the project's own assets go in
another, such as `public/dist/app/`. `public/dist/` is ignored by git.

A project may still build the admin from its sources with its own Vite, if
it wants one bundle with its plugins. Later, CI may build the admin for each
release, and an operation fetch it into `public/dist/admin/`, so a project
needs no Node; nothing else changes.

## Themes

Every major style value is a CSS custom property: colors, spacing, sizes,
radii, fonts, shadows. The base stylesheet defines them all, and every rule
uses them. A theme is one stylesheet setting some of them again, loaded after
the base: the project gives its URL at boot. Swapping a theme needs no
build. Plugins use the same properties, so they follow the theme too.

The base theme follows JetBrains' Islands Dark: tool windows and the canvas
sit as separate rounded "islands" with gaps between them, over a darker
frame. The frame color, each island's background, corner radius and the
gaps are properties too, so a theme can tighten or drop them. A light
sample theme ships next to the base.

## Plugins

There are two kinds of plugins, apart by how far they're trusted. Neither
is built yet.

- **Server plugins** are modules: PHP entity classes, operations and
  migration steps, and optionally admin code. They're active for every
  user. They come two ways: through Composer, by the project's developers,
  or uploaded through the admin, as WordPress does, by a user holding the
  right to. Uploading one runs new PHP on the server, so it needs that
  right, and the project may turn uploads off. Their admin code is trusted
  like the admin's own: it runs in the page and uses the public module
  below.
- **Browser plugins** are installed by one user, from inside the admin, and
  add nothing on the server: they use the operations there are, with that
  user's rights, as Figma's plugins do. They're sandboxed: each view is an
  iframe with no access to the page, the session or the CSRF token, talking
  to the admin through messages only. A browser plugin is described by a
  manifest at a URL: what it adds, its entry page, and the operations it
  asks to call, which the user agrees to when installing it. The admin runs
  only those operations for it, with the user's rights, never more.

A server plugin is one package, however it comes. Its `plugin.json` gives
its name (its module's name), its version, the coconut-cms versions it
works with, its `Module` class, its PSR-4 mapping, its PHP dependencies and
its admin entries.

- **Installed through Composer**, its dependencies are resolved with the
  project's, and the project builds its admin code, as it builds the admin.
  This is the better way wherever there's a team: the plugin is in
  `composer.lock` and git, every environment runs the same code, a change is
  reviewed and tested before it ships, its migration goes through the
  normal deploy, and production needs no code directory the web server can
  write.
- **Uploaded**, as a zip, it brings everything with it: its dependencies in
  its own `vendor/`, their namespaces prefixed by its author so two plugins
  never clash, and its admin code built in `dist/`. The CMS refuses an
  upload missing either. This is for a site with no developer around.
  Uploads are a project setting: a team's project turns them off in
  production.

A plugin first uploaded can move to Composer later, unchanged.

What's active follows where each kind is controlled:

- **A Composer plugin is active by being in the code:** the project lists
  its module, as it lists its own. The repo decides what runs, and a deploy
  changes it. The admin shows these plugins as managed by the project, with
  nothing to switch on or off.
- **An uploaded plugin is kept by the CMS:** its files are unpacked into a
  folder outside `public/` and `vendor/`, `storage/plugins/<name>/`, and a
  table records which are installed, their versions, and which are active.
  At boot, the active ones' modules follow the project's, and their PSR-4
  mappings are registered. Reading that table is cached with the code's
  state, so most requests don't pay for it.

An uploaded plugin's life, each step an operation:

1. **Upload** installs it, inactive. The zip is checked first: a valid
   `plugin.json`, a compatible coconut-cms version, its `vendor/` and
   `dist/`, a name no other module has. Nothing changes for the site.
2. **Activating** shows a dry run first: `Persistence::check()` with its
   module added, giving the types it adds, what changes, and whether
   anything blocks under the project's policy.
3. **Confirming** activates it and migrates, in one operation. A failed
   migration leaves it inactive; a dry run that blocks refuses it, saying
   why. The migration must run there: a site with uploads has no deploy
   command to run it.
4. **Updating** uploads a newer version: the same dry run with its new code
   and steps, then the files swapped and the migration run, once confirmed.

Switching a plugin off never deletes content:

- **Deactivating keeps its content, dormant.** Its types stay in the
  schema store, with their tables and rows, marked dormant. Nothing reads,
  writes, queries or migrates them while they are, and every migration
  leaves them out, so they don't count as types removed. Reactivating the
  same version brings them back with no migration; a newer version migrates
  from where they were. Deactivating is refused while anything active
  depends on its types, an editor-created type extending one or a field
  targeting one, another plugin's: the refusal lists them.
- **Deleting drops its content, after a dry run** showing how many entities
  of each type go and what depends on them. Confirmed, it runs a
  `DropType` for each of its types, as one migration, under the project's
  policy: the fields losing their target and the chains cut are blocked,
  or set aside as NoType records, as in any migration. Then its files go.

Dormancy is persistence's: a stored schema marked dormant, left out of
compiling, comparing and moving.

One role, named by the project in its plugin settings, holds the right to
upload, activate, update and remove plugins: `PluginSettings(uploads: true,
managerRole: 'admin')` or the like, given at boot. With uploads off, these
operations don't exist.

The rest of this section is about server plugins' admin code. The admin is
built so it stands on the same footing as its own tools.

- **One public API.** The admin exposes one module as its plugin surface,
  `@coconut-cms/admin`: the registries (tools, tab types, commands, menus),
  the hooks, the event bus, and reusable UI pieces such as form fields, the
  details panel and lists. Everything else is internal and may change. The
  public module is versioned by `ADMIN_API_VERSION`. Built so far: `admin`,
  the running `AdminRuntime` (its `tools` and `tabTypes` registries, its
  `api` client and its signed-in `user`); `ApiClient`, `ApiFailure`,
  `ErrorCode`; the workspace's types (`ToolDefinition`, `TabTypeDefinition`,
  `PanelView`, `ViewContext`, `ViewState`, `Workspace`, the typed ids and
  placements); and the `User` and `Me` types.
- **One running copy.** The admin page declares an import map, so
  `@coconut-cms/admin` resolves to the admin's own running module. A plugin's
  build marks it external and never bundles a copy: its `import { tools }
  from '@coconut-cms/admin'` reaches the admin's registries, not its own.
  The build keeps the public module's exports whole; the app imports the
  same shared chunk, so both reach one instance of each.
- **Types.** The package ships the public API's declaration files, so a
  plugin is checked by strict TypeScript against it. Not built yet.
- **Loading.** A module declares its admin code in `adminCode()`:
  - `AdminCode::source($file)`: a TypeScript entry, built with the admin
    into one build. There, `@coconut-cms/admin` resolves to the admin's own
    source, so the module shares its code. What a project's own module, or
    a plugin leaving its build to the project, declares.
  - `AdminCode::built($folder)`: a folder its author built with any
    toolchain, with an `admin-plugin.json` naming its entry script and
    stylesheets. Copied as it is; its `@coconut-cms/admin` stays an
    external import, which the page's import map resolves to the running
    admin. For a plugin whose build can't be left to the project.

  Either entry's default export is a class implementing `AdminPlugin`, with
  `register(admin)`. The admin registers its own tools, then imports each
  module's entry in boot order, builds its plugin and lets it register,
  before the workspace starts, so every tool is there when the saved layout
  is reconciled. A plugin failing to load or register is skipped and logged;
  the admin starts without it. Its stylesheets load with the page. A
  module's classes are prefixed with its name: every stylesheet shares the
  page.
- **The admin's own tools use the same API.** The type list, the entity list
  and the details panel are registered the way a plugin registers its tools,
  so the public API is used from the first day, not designed in theory.

## The shell

The shell follows the concepts of a first draft (kept outside the package,
not its code): an IntelliJ-style window manager.

- A menubar; stripes on the left and right holding tool icons, in three
  sections each (`topTop`, `topBottom`, `bottom`); up to six docked panels in
  three resizable containers, left, right and bottom; and a canvas holding
  tabs. A side's two top sections open in that side's container, upper then
  lower; both bottom sections open in the bottom container, left then right.
  Each section holds one open panel at most.
- Panels and the canvas are islands. An open container takes its size plus
  the island gap; a closed one takes no room.
- Data and code apart, as classes: a tool is a definition, its id, title,
  SVG icon and default placement, with the `PanelView` class its panel
  shows; a tab type is an id with its view class. The shell builds a view
  with `new View(context)`, the context giving the API client, the view's
  own saved state, the workspace (to open tabs) and a tab's parameters. A
  tool or tab type that can't be built shows a message in its place.
- Views stay alive when hidden, moved or switched: their element and state
  survive, parked off the layout while hidden; a move uses `moveBefore()`
  where the browser has it, and keeps scroll offsets otherwise. A tab's
  view is disposed when the tab closes.
- Opening a tab of a type with a key already open shows that tab instead.
  Otherwise `Workspace.openTab()` opens it one of two ways: `reuse`
  replaces the tab of that type shown most recently, in place, disposing of
  its view; `new` adds another tab. The layout keeps every tab in the order
  last shown, to know which tab a reuse replaces.
- `ContextMenu` shows a floating menu at a point or under an element: a
  right-click's choices, a dropdown's. One is open at a time.
- `Tooltips` replaces the browser's tooltips: an element's
  `data-tooltip` shows in a themed box 150ms after the pointer rests on it,
  or on keyboard focus. The element chooses where, with
  `data-tooltip-placement` (`top` unless set): `top`, `bottom`, `left` or
  `right`, centered along that side; or `top-start` or `bottom-start`, its
  left edge on the element's. The box flips to the opposite side where
  there's no room, and shifts to stay inside the window.
  `Tooltips.attributes(text, placement)` gives both attributes. Once one
  shows, the next shows at once. The admin sets no `title` attribute.
  Where they show now:
  - field type icons, field access icons and the header's rights: `top`;
  - stripe buttons: toward the canvas, `right` on the left stripe, `left`
    on the right one;
  - a tab's title and a panel's hide button: `bottom-start`.

  A field's type name, a tab's close button and the Types panel's entries
  have none.

The code is in three layers, each depending only on the ones before:

- **Model** (`admin/src/shell/model/`), pure and unit-tested:
  - `Layout`, immutable, holds the whole state: each section's icons and
    open tool, each container's preferred size and split, the tabs, and each
    view's saved state. Its methods are the reducers, each returning a new
    `Layout`: `toggleTool`, `closeTool`, `openTab`, `activateTab`,
    `closeTab`, `resized`, `split`, `withViewState`, `reconciled`.
  - `SizeResolver` turns preferred sizes into the sizes shown, for the
    workspace's size now: the canvas gives way first, down to its minimum
    (320 by 200); then the containers shrink, each in proportion to its room
    above its minimum (160). A container squeezed below its preferred size
    can only be dragged smaller.
  - `LayoutStore` holds the current layout, the only place it changes: it
    tells its listeners, then saves a moment later.
- **Registry** (`admin/src/public/workspace/`), part of the public module:
  `admin.tools` and `admin.tabTypes`, the definitions, `PanelView`,
  `ViewContext`, typed ids and placements.
- **UI** (`admin/src/shell/ui/`): `Shell`, `MenuBar`, `Stripe`,
  `WorkspaceArea`, `PanelContainer`, `PanelFrame`, `TabGroup`, `ViewHost`
  and `Splitter`. Each part shows its own slice of the layout it's given; a
  click changes the layout through the store, never the DOM directly.
  During a drag, a splitter only shows the new size; the layout changes
  once, when it's released.

The layout is kept in the browser's localStorage, one per user, versioned.
On load it's reconciled with what's registered now: a tool gone is
dropped, a new one is placed where it asks, a tab of a type gone is closed,
and saved state no view owns is dropped. Keeping it per user on the server,
through an operation, comes later.

Not built yet: dragging icons between sections and reordering tabs, menus
and commands from manifests, splitting the canvas into several tab groups,
going back and forward within a tab.

## Properties

How the admin shows and edits entities, after Unreal's editor: an object
in memory, edited from several places at once, with one unsaved state, and
details views showing its properties. It's part of the public module, so
projects and plugins show entities the same way, and nobody writes their
own. Built for native entities: the store, sessions, views, filters,
editors, the dropdown, and lists following the store. Not built yet:
edits saved elsewhere meanwhile, a "New" button; noted inline.

The code is in three layers. Data knows nothing of the DOM; views know
nothing of the API.

- **Data:** `EntityStore` and `EntitySession`. They decide an entity's
  lifetime: loading, editing, errors, saving.
- **Views:** `PropertyRow`, `PropertyGrid` and `EntityEditor`. They show a
  session and change it. A view decides only how an entity looks.
- **Editors:** the value widgets, registered in `admin.properties`. A view
  picks a property's editor from it.

### The store

`EntityStore` holds the entities the admin works with. There is one per
admin, given to every view as `context.entities`.

- It reads, queries, creates, updates and deletes entities through the
  API, and keeps what it read.
- It tells its listeners of each change: an entity created, updated or
  deleted, with what the API gave back. A Reference's label comes from the
  store, as last read or saved, and the Reference picker queries through
  it.
- `EntityList` is a query's entities, a page at a time, through the
  store, with no DOM: a list view shows it and turns its pages. It reads
  its page again when an entity of its type, subtypes included, is
  created, or one it shows is updated or deleted. So a rename shows in
  every list. The project's Cars tool is one.
- It gives out the sessions. There is one session per entity: two views of
  one entity share it, and see each other's edits as they're made.

A view takes a session with `entities.edit(id)`, which gives a hold on it.
The view lets it go with `hold.release()`. The store keeps a session while
any hold on it stands, and frees it once the last one is released.
Releasing the last hold on a session with unsaved changes asks first:
Save, Discard or Cancel. Cancel keeps the hold, and `release()` answers
false. So a tab's `canClose()` is its hold's `release()`. Leaving the page
asks first while any session has unsaved changes.

`entities.create(type)` gives a hold on a new session: an entity of `type`
not saved yet, with no id, starting from the type's defaults. Its first
save creates the entity. The session then takes its id and is shared like
any other. A "New" button comes with dynamic entities (not built yet).

Edits saved by someone else meanwhile aren't seen yet (not built yet). The
store will hear of a newer version, and the session will show it is stale.

### The session

`EntitySession` is one entity being edited. It has no DOM, and every view
of the entity goes through it.

- Its state: loading, ready, or failed with a message. Its type, its id
  (null while new), its label.
- Reading and changing it, by path: `describe(path)`, the property's
  field, what it holds, the type holding it, whether it's writable;
  `valueAt(path)`; `set(path, value)`, and `replace(path, value)`, a set
  told as a change of shape; `itemsAt(path)` for a Collection's
  `CollectionItems`; `resetAt(path)` to what was saved,
  `resetToDefaultAt(path)` and `defaultAt(path)`. An owned object is
  described by the class it holds, so a subtype's own fields are found.
- Where a path stands: `statusAt(path)` (new, moved from another index,
  changed) and `errorAt(path)`; an error about the whole entity, `isDirty`,
  `isSaving`. A new entity stands against its type's defaults: until its
  first save, resetting a property puts its default back.
- `save()` and `revert()`. `save()` answers whether it saved; when it
  fails, the errors are at their paths.
- Its change events name what changed: values at paths, the shape at a
  path (items added, an owned object's class picked), or its state (dirty,
  saving, errors, reloaded after a save or a revert). Each names the view
  that made it, when one did.

A fully custom view, a card, a table row, a chart, needs only the session.
It gets dirty tracking, errors and saving with it.

### The views

- `PropertyRow` shows one property of a session, at a path: `engine`,
  `wheels.1.size`. It can go anywhere in a custom layout, like Unreal's
  single property view.
- `PropertyGrid` shows many: by default every field, grouped by the type
  declaring it, like Unreal's details view. A `PropertyFilter` narrows
  it; a group with nothing to show isn't shown.
- `EntityEditor` is the default composition, for tabs: a header with the
  label, the type, the id, an unsaved mark, Save and Revert, then a grid of
  the whole entity. `EntityEditor.edit(context, id)` and
  `EntityEditor.create(context, type)` take a hold for it. A tab's view
  hands its `canClose()` to the shell, which releases the hold, and calls
  its `dispose()` when it goes. A view that wants another look uses the
  grid or the rows alone.

Each view follows the session's change events, row by row. A row shows a
value changed elsewhere by building itself again; the row that made the
change keeps its widget, so typing is never interrupted. A change of shape
builds the row at its path again, with what's under it. After each change,
every row marks again where it stands. Only a reload, after loading,
saving or reverting, shows the whole view again: anything may differ then.
A view disposes of itself when it goes, and stops following the session.

Which properties are collapsed is the view's to keep: a grid takes where to
remember them. `EntityEditor` keeps them in its view's state, per entity
type, like the closed groups.

### Filters

A `PropertyFilter` decides which properties a grid shows:
`new PropertyGrid(context, session, {filter})`. It's a class with one
method, `accepts(property)`, given the property's path, its field, what it
holds, the type holding it and whether it's writable. `PropertyFilters`
gives the built-in ones:

- `writable()`;
- `ofKind(...kinds)`: Collections, References…;
- `holding(type)`: a value object type (every `shop:color`), an entity
  type held, or a scalar type (`int`). A Collection's items are accepted
  one by one;
- `under(path)`: the properties under a path, not the one at it: an owned
  object's fields;
- `paths(...paths)`;
- `all(...filters)` and `any(...filters)`, combining others.

A property shows when the filter accepts it or one of its ancestors:
accepting a property accepts what's in it. The ancestors of a property
shown stay as plain parent rows, a name and a chevron with no widget, so a
match deep in an object keeps its place, as in Unreal's details search.

### Editors and rows

Customizing: `admin.properties.register(match, EditorClass)`, where the
class implements `PropertyEditor`, built with a `PropertyContext`: the
property's path, its value description, its slot to read and set its
value, whether it's writable, the catalog, the API client and the entity
store, a Collection's
`CollectionItems`, `replace(value)`, and helpers to show an object's
fields or a Collection's items by the usual rules. The admin registers its
defaults first, so a plugin's registrations replace them. An editor never
rebuilds the view. A value set through its slot keeps its widget. A value
whose widget shows more than an input, a Reference's label, an owned
object's class, goes through `replace(value)`: its row is built again. An
item added comes back from the session as a change of shape too.

- **A property is the unit.** A property view shows one field's value at a
  path: its name, its value widget, and its children for values that have
  them. A row reads: a chevron collapsing its children, when it has some,
  remembered per entity type; its field's type icon, the same as the type
  inspector's; its name, indented by depth; its value, every value in one
  column; a Collection item's actions, move up, move down, duplicate and
  remove, in the row, dimmed until it's pointed at; then two resets.
  "Reset to default", Unreal's hooked arrow, shows while the value differs
  from what a new object holds there; an owned object keeps its id, only
  its values reset. "Reset to saved", a circling arrow, shows once the
  value differs from what was saved. A Collection's item has no default.
  Rows are parted by a line; a row holding others is tinted,
  its children behind a guide line; a category is a bar; an owned object's
  type is a "Class" picker, set apart from values. Anything can be shown: one field, a nested Embed, a whole entity,
  which is its fields' properties. Where it shows is up to its user: the
  project's Cars tool opens a tab per car, showing the whole entity.
- **Each kind has a default:**
  - a value: a text box for a String, a number box for an Int or a Float,
    a checkbox for a Bool, a date box for a Date, a dropdown for an Enum,
    its stored text for a value object;
  - an Embed: a collapsible group of its fields' properties, by the same
    rules, like a struct;
  - an owned object: shown as an edit-inline instanced subobject. Its
    header has a type dropdown, the field's target, its subtypes, and
    "None" when the field may be empty, and its properties show expanded
    beneath it. The dropdown is the admin's dropdown, below. Picking
    another type replaces the object with a new one of that type, from its
    defaults; "None" empties the slot;
  - a Reference: its target's label, an open button, and a picker.
    Clicking the label opens the picker too. The label is the target's
    `name`, `title` or `label` field, else its first String field, else its
    id; a view loads every target's label at once. The picker is the
    admin's dropdown over the target type's standalone entities, subtypes
    included, by label, paged, narrowed by a search on the label field when
    it's a String, with "None" to clear it. The open button does
    nothing yet: what opens depends on the target's type, the way an asset
    pointer opens its asset's own editor, a page in a page editor. A
    registry of editors by type comes later;
  - a Collection, like Unreal's arrays: a header with its name, its item
    count, how many saved items were removed, add and clear; then a row
    per item, `[0]`, `[1]`…, with its item's widget and its actions. Dragging
    to reorder comes with the shell's drag engine (not built yet). A value
    or an Embed is added from its default; an owned item asks for its type
    when its target has subtypes. A duplicate is a new item: every owned
    object in it has no id.
  - Items change through the context's `CollectionItems`: insert, remove,
    move, duplicate, clear. Each operation keeps each item's origin, its
    index when saved, or none for a new item. So each item shows where it
    stands: "new", or "from [2]" when it moved. An item is changed only when
    its value differs from its own saved value, not from the value saved at
    its index. A custom Collection editor uses the same operations.
- **The admin's dropdown** replaces the browser's select wherever a choice
  is picked: one `ChoicePicker`, with different content. It shows a search
  box, then the choices; the current one is marked. Arrow keys move
  through them, past the last into the next page, and Enter picks; a click
  picks too. Its choices come from a `ChoiceSource`, a page at a time:
  `LocalChoices` filters a list at hand (types), `ReferenceChoices` queries
  entities. Choices like "None" are pinned first, whatever the search. It
  follows its field when the view scrolls, and closes once the field
  scrolls out of sight.
- **Customizations replace a default,** registered in `admin.properties`.
  Several can match one field; the most specific wins:
  1. this exact field of this type: `shop:car`'s `color`;
  2. the type of value it holds: a value object type (`shop:color`, as
     Unreal customizes FColor with a color picker), an entity type held as
     an Embed or owned object (`shop:address`), or a scalar type (`int`);
  3. the admin's default for its kind.

  So a widget for `shop:car`'s `color` wins over a color picker for every
  `shop:color`. A customization replaces the value widget only, keeping
  the name, or, for a value with children, how they're laid out too.
- **Editors work in stored form,** the form a read gives and an update
  takes. Each sets its value at its path; the session collects the
  changes, and saves them as changes by path.
- **A whole entity,** in `EntityEditor`, shows a header, its label, its
  type, its id, an unsaved-changes mark, Save and Revert, then its fields
  grouped by the type declaring them, like Unreal's categories: a
  collapsible group per level,
  the root first, so the fields every subtype shares sit in the same place.
  A type with no ancestor shows no group header. Which groups are closed is
  remembered per type. Fields go in declaration order; one the user can't
  write is disabled, one they can't read isn't shown. Names are indented by
  depth, values line up in one column. The view's tab is titled with the
  entity's label, again after each save.
- **New values start from defaults:** a new owned object, Embed or item
  takes its type's defaults, which `coconut-cms:types.list` gives with each
  type; a value starts empty, at zero or at an enum's first value. Later, rendering
  hints on native properties may give a field its own category or label,
  as Unreal's `Category` and `DisplayName` do.
- **Saving is explicit,** as in Unreal's asset editors. The session does
  it; views show it:
  - edits wait at their paths; the view shows it has unsaved changes, and
    marks each changed property. A property is compared with what was
    saved, so a change undone is clean again: a value typed back, items
    moved back;
  - Save sends them all in one `entities.update`, so setters, `afterEdit`
    and validation run once over the whole edit; Cmd or Ctrl and S saves.
    An edit inside an item goes at its path, `wheels.1.size`. A Collection
    with items added, removed or moved goes whole, owned items keeping
    their ids, so a move never recreates them. Revert drops every change;
    resetting a property drops its own;
  - after a save, every view shows what the create or update gave back:
    setters and `afterEdit` may have changed other fields. The store tells
    the other views of the entity, a list showing it, a Reference's label;
  - an error shows at its property, `changes.<path>` or a validation
    error's path, until that property is edited; one about the whole
    entity shows at the top. A failed save keeps the changes, to fix;
  - while a session has unsaved changes, every tab showing it has a mark,
    and a reusing open never replaces one. Releasing its last hold asks to
    save, discard or cancel, as above. A tab's view tells the shell
    through its context's `markDirty()` and its own `canClose()`.

## The Types tool

The admin's first tool, registered the way a plugin registers its own. It
browses the entity types the user may read; browsing entities comes later,
with their first uses.

- **The panel** lists the types in a collapsible group per namespace, each
  type by its key. Which groups are closed, and which type was opened last,
  are kept in the view's state.
- **Opening a type** shows it in the type inspector. A click reuses the
  inspector tab shown most recently; Cmd or Ctrl and a click, a middle
  click, or "Open in new tab" in the right-click menu open another tab.
  Every type named anywhere in the tool is a link behaving this way.
- **The inspector** shows a type whole:
  - a header: the identifier, a "Subtypes" dropdown listing its direct
    subtypes, what it extends, and a row of the user's rights on its
    entities (read, create, update, delete), each an icon and a word,
    dimmed and struck through when not held;
  - its own fields, then, under "From" and each ancestor's identifier, that
    ancestor's fields, up to the root;
  - per field: its type icon, its name, its type's name (`int`, or the
    entity type it holds as a link), and an eye and a pencil for reading
    and writing it.
- **The type catalog** (`admin.typeCatalog()` in the public module) loads
  `coconut-cms:types.list` once per page and works out ancestors, subtypes
  and namespaces. A readable type's ancestors are readable too, so the
  chain is always whole.

A type has no icon of its own: how a field holds it (embedded, owned,
referenced) is the field's, not the type's. A field's icon is stacked from
independent layers, after Unreal's pins, so no combination is drawn whole:

- **frame**, from multiplicity: a circle for one value, a 3 by 3 grid for a
  Collection;
- **glyph**, from how the value is held, in the frame's center: a dot for a
  value, a square for an Embed (outlined in a grid, where a filled one reads
  as a cell), a diamond for an owned object (UML's composition), an arrow
  for a Reference;
- **badge**, a slashed circle in the corner, when the value, or a
  Collection's item, may be empty;
- **color**, from the value's type, drawn in `currentColor` set to a theme
  token: `--cc-type-bool`, `-int`, `-float` (decimals too), `-string` (text
  too), `-date` (date and time too), `-enum`, `-value-object`, `-entity`.

Its tooltip says it all in words: "An owned default:demo_turbo or subtypes,
saved and deleted with the entity; may be empty."
