# Admin Architecture

Status: a standalone description of the admin app, the client side of the
CMS. Updated one decision at a time. Nothing here is built yet; where that
changes, it's noted inline.

The admin is a client-side app. It talks to the backend only through the API
(`../Api/ARCHITECTURE.md`): every list, form and save is an operation, so the
admin can do nothing the command line can't. It's written in strict
TypeScript, with SCSS, built by Vite, and uses no runtime framework.

## Mounting

A project serves the admin from its router, next to the API:

```php
$admin = Admin::boot($api, theme: '/css/admin-theme.css');
$admin->routes($router, prefix: '/admin');
```

The admin is a single page. `routes()` registers what serves it:

- `GET /admin`: the page starting the app. It holds the manifest, as JSON
  (see "Registration"), the CSRF token, an import map (see "Plugins"), and
  the tags loading the admin's built files and the theme;
- `GET /admin/assets/...`: the admin's built files, streamed from the
  package.

The project picks the prefix. Everything after the page loads goes through
the API.

## Assets

The package ships the admin built: its own `package.json`, TypeScript and
Vite build give `dist/admin/` and a Vite manifest. A project needs neither
Node nor TypeScript to run it. Its files are served by the admin's route, or
copied into `public/` for the web server by `vendor/bin/coconut
coconut-cms:assets.publish`, for production.

A project may still build the admin from its sources, with its own Vite, if
it wants one bundle with its plugins. The prebuilt admin is the default, not
the only way.

## Themes

Every major style value is a CSS custom property: colors, spacing, sizes,
radii, fonts, shadows. The base stylesheet defines them all, and every rule
uses them. A theme is one stylesheet setting some of them again, loaded after
the base: the project gives its URL at boot. Swapping a theme needs no
build. Plugins use the same properties, so they follow the theme too.

## Plugins

The admin is built so plugins stand on the same footing as its own tools.

- **One public API.** The admin exposes one module as its plugin surface,
  `@coconut-cms/admin`: the registries (tools, tab types, commands, menus),
  the hooks, the event bus, and reusable UI pieces such as form fields, the
  details panel and lists. Everything else is internal and may change. The
  public module is versioned.
- **One running copy.** The admin page declares an import map, so
  `@coconut-cms/admin` resolves to the admin's own running module. A plugin's
  build marks it external and never bundles a copy: its `import { tools }
  from '@coconut-cms/admin'` reaches the admin's registries, not its own.
- **Types.** The package ships the public API's declaration files, so a
  plugin is checked by strict TypeScript against it.
- **Loading.** A module lists its plugins' script URLs in the manifest, built
  by its own Vite. The admin starts its core, then imports each plugin, which
  registers what it adds through the public API.
- **The admin's own tools use the same API.** The type list, the entity list
  and the details panel are registered the way a plugin registers its tools,
  so the public API is used from the first day, not designed in theory.

## The shell

The shell follows the concepts of a first draft (kept outside the package,
not its code): an IntelliJ-style window manager. Its details are settled
when it's built.

- A menubar; stripes on the left and right holding tool icons, in three
  sections each; up to six docked panels in three resizable containers, left,
  right and bottom; and a canvas holding tab groups.
- One serializable layout state is the only source of truth. Reducers
  changing it, and the resolver turning preferred sizes into the sizes shown,
  are pure functions, tested without a DOM.
- Data and code apart: a tool, a tab type, a command or a menu is a manifest,
  JSON a module's PHP can emit too; the component showing it is registered by
  name in TypeScript.
- Views stay alive when hidden, moved or switched: their DOM, scroll and
  state survive. Each view has its own state, kept with the layout.
- The layout is kept in the browser at first, and per user through an
  operation later (where it's kept is open). On load it's reconciled with
  what's registered now: a tool gone is dropped, a new one is placed where
  it asks.
