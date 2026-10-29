# Admin Architecture

Status: a standalone description of the admin app, the client side of the
CMS. Updated one decision at a time.

Built: the build, the page with its import map and config, the public
module, the base and light themes, the sign-in screen, the shell with its
panels, splitters and one tab group, tool and tab type registration, and
the Types tool. Not built yet: dragging icons and tabs, menus from
manifests, plugin loading, the layout kept per user on the server. Each is
noted inline.

The admin is a client-side app. It talks to the backend only through the API
(`../Api/ARCHITECTURE.md`): every list, form and save is an operation, so the
admin can do nothing the command line can't. It's written in strict
TypeScript, with SCSS, built by Vite, and uses no runtime framework. Its
sources are in the package's `admin/`, next to the PHP's `src/`.

## Mounting

A project serves the admin from its router, next to the API:

```php
$admin = Admin::boot($api, manifest: __DIR__ . '/public/dist/admin/.vite/manifest.json', theme: BundledTheme::Light);
$admin->routes($router, prefix: '/admin');
```

`Admin::boot()` also takes `assetsUrl`, where the web server serves the
built admin (`/dist/admin/`), and `apiUrl`, where the API is mounted
(`/api`). A theme is a bundled one or a stylesheet's URL.

The admin is a single page. `routes()` registers `GET /admin`, the page
starting the app, never cached. It holds:

- the base stylesheet, then the theme;
- an import map resolving `@coconut-cms/admin` (see "Plugins");
- the app's config, as JSON in `<script id="coconut-admin-config">`: the
  API's URL and the session's CSRF token;
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
project builds it with one command, into `public/dist/admin/`, with a Vite
manifest; the web server serves those files. A project needs Node for
this, and installs the package's Node dependencies once:

```
npm --prefix vendor/xylo-is-coding/coconut-cms install
npm --prefix vendor/xylo-is-coding/coconut-cms run build:admin -- --outDir "$PWD/public/dist/admin"
```

The build has four entries: the app, the public module, the base
stylesheet and the light theme. The build's URLs are relative, so the
output can be served from any folder. In the package, `npm run check:admin`
type-checks, and `npm test` runs the admin's tests.

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

The admin is built so plugins stand on the same footing as its own tools.

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
- **Loading.** A module lists its plugins' script URLs in the manifest, built
  by its own Vite. The admin starts its core, then imports each plugin, which
  registers what it adds through the public API. Not built yet.
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
  or on keyboard focus; once one shows, the next shows at once. The admin
  sets no `title` attribute.

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
