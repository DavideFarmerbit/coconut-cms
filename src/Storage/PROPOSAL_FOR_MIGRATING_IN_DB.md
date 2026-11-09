# Proposal: Migrating in the Database

A structural rewrite of how schema changes reach stored content. It replaces the parts of
`PROPOSAL_FOR_SAVING.md` that move content: "Migrating", the change history, the
translator and stages 3 to 8. The parts about saving entities (batches, dependencies,
results, the save log) stay there.

## Why

Today a migration moves content by aggregate: it loads each aggregate whole, changes it in
memory, and writes it whole again. Every row of the aggregate is read and written, even the
ones the change doesn't touch. The first proposal kept that unit and spread it out in time:
a change went live at save, rows caught up later, and a load converted a row still behind.
That gave three problems:

- **Owned objects a change creates don't exist yet.** They have no rows, so no id, and
  queries don't find them. A Reference can't point at them. This breaks the site.
- **Queries miss rows that are behind.** A query on a new field doesn't find a row the
  backfill hasn't reached.
- **The cost is the aggregate.** Most of the work is moving data between the database and
  PHP, data the change never names included.

## The idea

**A change is a program that runs in the database, and it goes live only after its data is
in place.**

- The change is written once, in the change language. The system compiles it to SQL, and
  the database applies it in batches. PHP never loads an aggregate to migrate it.
- The new columns, tables and owned objects are filled while the site still uses the old
  schema. When every row has its new data, a short cut-over makes the change live.
- After the cut-over no row is behind. A load never converts a row, queries are always
  complete, and every owned object exists, with its id.

The cost: a change is live after its data pass, not at save. With SQL that takes seconds
for 200,000 rows, minutes for millions. The site never waits, except for the cut-over lock.

## The standard

An e-commerce site migrating in production under load. Every step is crash-safe, and a
change stopped at any point resumes or is abandoned before its cut-over, never left half
live.

## The change history

**One file per module.** Each module keeps its changes in one file, in order, for example
`migrations.fix`. The whole history of a module is in one place. Changes from different
modules are ordered by their dependencies. Two branches adding a change both append to the
file, and git reports the conflict: the second change was built on a version that's no
longer the last, which is a real conflict.

**One table per database.** The `changes` table records every change applied here, native
or editor-made, in order, with its status. Editor changes live only in this table: they
have no file.

**A change is before → steps → after.** Native or editor-made, a change is the same thing
to the database:

- **before:** the version it starts from, by hash. It's the after of the change before it,
  or the module's baseline.
- **after:** the types it touches, as they are once it's applied, stored with
  `EntitySchemaCodec`. That holds every field, default, rule, permission and unique group,
  so no syntax is needed for them.
- **steps:** the schema operations, thin (`add field shop:product.price`), and the data
  steps. Every step reads its target from its own change's after, never from the newest
  schema. That's how the fixups already work; today they read the newest schema instead.
  A database skipping releases replays each change against its own after, so a field
  retyped twice in two releases is converted twice, correctly.
- its policy choices and its set-asides, recorded as made, so replaying it never asks the
  policy again.

Nothing is stored between changes: each version is the previous one with one change's after
laid over it.

**One step per field per change.** Two steps touching one field in one change would both
read the same after: an editor save retyping `price` to int then to text would convert
straight to text in its first step. So the planner compares a change's before and after by
field id and gives one step per field: `price` int → text. A native change is planned the
same way. `FixupAtStep` goes.

**The stored schemas are the newest live version.** They're written in the cut-over's
transaction, never on their own, and checked against the history's hash. A mismatch is an
error, never served.

**Old versions** are rebuilt from the baseline and the afters. The shape of type T at
version v is the after of the last change up to v that touched T, else the baseline's. A
deleted type is covered: the before of the change deleting it is the after of the change
before. Old schemas load without their classes: a value object or an enum whose class is
gone is kept by name and columns, which is all a shape needs.

**Purging.** One rule, for native and editor changes alike: a change's after can be dropped
when no database that will replay it is still behind it, and no request in the
compatibility window was built before it.

- **In each database,** the purge command applies the rule. For a single site, that's every
  change older than the window. An editor change exported to another database stays until
  that database has imported it.
- **In the module file,** a native change also ships to every site, so its copy there goes
  only when every site has passed it, and only the developer knows that. Dropping changes
  from the file is a **squash**: they're replaced by a baseline, the full schema at that
  version. A site older than the baseline upgrades in two hops: first through a release that
  still has the changes, then on. Django and Laravel have the same rule.

## The change language

**One tree, stored as JSON.** Each node is an operation and its arguments. People write a
text form, which a parser turns into the tree. The editor builds the tree from its UI and
shows it back as text, so diffs are readable.

**The whole change is in the language:** schema operations (add, drop, rename, move,
retype a field; add, drop, rename, replace, reparent a type; unique groups; rules) and data
steps. The operations name what changes; what it becomes is in the change's after.

**A data step reads `old` and writes `new`.** `old` is the shape before the change, `new`
the shape after it. The schema operations go around the step, so their order inside a
change doesn't matter to the data. A step runs `in` a type: its paths start there and cross
into Embeds and owned objects, and it runs at every place the type lives.

```text
data in shop:product {
  set new.price = {
    amount:   old.priceAmount,
    currency: match upper(trim(old.priceCurrency)) {
      "EUR", "€" => EUR
      "USD", "$" => USD
      else       => fail("unknown currency: " ~ old.priceCurrency)
    }
  }
  let name = trim(old.supplierName ?? "")
  set new.supplier = if name == "" then null
                     else new shop:supplier { name: name, email: lower(old.supplierEmail) }
}
```

**Checked when the change is saved,** before anything runs:

- types, and nulls: a field that can be null must be handled
- the fields each step writes, which the change must allow
- **dropped data is accounted for:** when a change drops a field holding data, every place
  that field lives must be read by a step or named in a `discard`
- a plain copy (`email: old.supplier.email`) is seen as one, so a query on the old path is
  rewritten to the new one. A computed value doesn't rewrite queries.

**Pure.** A step can't read the clock, random numbers, the database, files or the network.
The same input always gives the same output. Owned objects a step creates get their ids
from the engine, not from the step.

**Built-ins are defined once and never change.** A function whose behaviour must change
gets a new name. Each change records the language version it needs.

**Two backends.**

- **SQL** applies changes to the database. It's the main path.
- **PHP** translates requests in memory (old input) and runs column jobs.

Each built-in has a conformance test running it in PHP and on every supported database, and
comparing the results. SQL `TRIM` removes spaces only, PHP `trim` also tabs and newlines;
`LOWER` on non-ASCII text depends on the collation; decimal division rounds differently. The
definition picks one meaning, and both backends implement it. A built-in that can't match
exactly is PHP only.

**Column jobs, for logic SQL can't express.** A function with no exact SQL form, or a PHP
class with `#[FixupLogic('vendor:name')]`, runs as a column job: PHP reads only the columns
the step reads, by id range, and writes only the columns it writes. Never an aggregate.

- The change records the class's name, its parameters and a hash of its source file. A
  different hash refuses the job until the developer accepts the new class with a command.
- The hash misses edits in code the class calls. Developers must not change what an old
  class does while a change still needs it.
- `editor: true` lets the editor offer the class.
- Column jobs are the last resort. They can't become triggers, so they sync by catch-up.

## The syntax

**A module file.** A change is named, unique in its module. The file's order is the
history's order. `//` starts a comment.

```text
module shop
requires coconut-cms

change product-structure {
  ...
}

change contact-out-of-supplier draft {
  ...
}
```

**Types.**

| Kind | Syntax |
|---|---|
| Scalars | `string`, `text`, `int`, `bool`, `float`, `decimal`, `date`, `datetime` |
| Enum | `enum shop:currency` |
| Value object | `value shop:rgb` |
| Embed | `embed shop:money` |
| Owned object | `owns shop:supplier` |
| Reference | `ref shop:brand` |
| Type reference | `type shop:vehicle` |
| Collection | `list text`, `list owns shop:wheel` |
| Nullable | `owns shop:supplier?` |

Types are written in data steps (`100 as decimal`) and in what a step reads and sets. A
field's whole definition, its default, rules and permissions included, is in the change's
after, never written by hand.

**Schema operations** name what changes. What it becomes is in the change's after.

```text
add type shop:money
add type shop:electric-car
add field shop:product.price
drop field shop:product.priceAmount
rename field shop:product.cost to price
move field shop:car.tags to shop:vehicle
retype field shop:product.cabins
rename type shop:engine to shop:motor
reparent type shop:car to shop:vehicle
replace type shop:van with shop:truck
drop type shop:dimensions
add unique shop:product (sku)
drop unique shop:product (sku)
discard shop:order.supplier.email
```

**Data steps** have four statements: `let`, `set`, `if`/`else` and `fail`. No loops, so
every step ends, and SQL compilation stays possible.

**Expressions.**

| What | Syntax |
|---|---|
| Paths | `old.price.amount`; `old.supplier?.email` is null when `supplier` is |
| Null | `a ?? b` |
| Arithmetic | `+ - * / %` |
| Text joining | `a ~ b` |
| Comparison and logic | `== != < <= > >=`, `and`, `or`, `not` |
| Conditions | `if c then a else b` |
| Match | `match x { "EUR", "€" => EUR  else => fail("…") }` |
| An Embed value | `{ amount: 10, currency: EUR }` |
| A new owned object | `new shop:supplier { name: "Acme" }` |
| Lists | `old.items.map(i => i.size)`, `.filter(…)`, `.first()`, `.count()`, `.join(", ")` |
| Default | `default` as a value, `isDefault(old.x)` as a test |
| Failure | `fail("…")` wherever a value fits; `try(expr, fallback)` |
| Literals | `12`, `12.50`, `"text"`, `true`, `null`, `@2024-01-31`, `@2024-01-31T10:00:00Z` |
| Enum case | `EUR`, where the expected type says which enum |
| A type stated | `100 as decimal`, `EUR as enum shop:currency`, `[] as list text` |
| Quoted names | `` old.`odd name` ``, `` `my-vendor:a b` ``: any field or type name |

`old.items.map(f)` is `map(old.items, f)`. The tree stores the second form:

```json
{"set": "supplier", "to": {"if": {"call": "eq", "args": [{"let": "name"}, ""]},
  "then": null,
  "else": {"new": "shop:supplier", "fields": {"name": {"let": "name"}}}}}
```

**Decided:**

- **`~` joins text.** `+` is arithmetic only, so `"1" + 2` is a type error, never a guess.
- **Number literals take their type from where they're used.** `price * 100` on a decimal is
  decimal and exact; on a float, float. No suffixes.
- **Field ids are computed,** from the module, the change name, the type and the field
  name. Every database gives a new field the same id and column, and the file names fields
  by name only. An editor change computes them the same way from its change's id.
- **A step never reads `new`.** A value needed twice goes in a `let`. The order of `set`
  statements never matters.
- **Value objects are copied whole.** SQL doesn't know their parts. Anything more is a
  column job.

**Settled while building stage 1:**

- **No `c ? a : b`.** `if c then a else b` says the same, and `?` already means three other
  things: `?.`, `??` and a nullable type.
- **No `shop:currency.EUR`.** A type name can hold dots and hyphens
  (`coconut-cms:assets.frontend`), so it can't sit in an expression unquoted. A bare case
  takes its enum from where it's used; elsewhere `EUR as enum shop:currency` says it.
- **A null check narrows.** After `old.email == null`, the else branch knows `old.email`
  isn't null: `if old.email == null then null else lower(old.email)`. So do `!=`, `and`,
  `or` and `not`.
- **An int divided by an int is a decimal,** exact: `7 / 2` is `3.5`. `%` stays an int.
- **A text goes into a string field** with its length checked when it runs: one too long
  fails the aggregate.
- **A bare name is a variable before it's an enum case.** A name that's both, in one place,
  is an error: rename the let.
- **An Embed or owned object copied whole from old** must have the same fields before and
  after the change. Otherwise its fields are set one by one.

**The first built-ins** cover what today's built-in converters do, so nothing is lost:

| Today | In the language |
|---|---|
| `ConstantValue`, `CopyOfField` | a literal, a path |
| `MapValues`, `TextToEnum`, `EnumToText` | `match`, `toEnum(x)`, `toText(x)` |
| `NumberToNumber` with `Rounding` | `toInt(x, rounding)`, `toFloat(x)`, `toDecimal(x)`, `round(x, places, rounding)` |
| `NumberToText`, `TextToNumber` with `OnFailure` | `toText(x)`, and `toInt(x)` and the others failing on bad text, in `try(…, default)` |
| `TextToText` with `OnTooLong` | `truncate(x, n)`, or a `fail` |
| `DateTimeToDate`, `DateToDateTime` | `toDate(x)`, `toDateTime(x)` |
| `FirstItem`, `ToSingleItem` | `.first()`, and list literals `[x]` |

Plus `trim`, `lower`, `upper`, `length`, `substring`, `replace`, `contains`, `startsWith`;
`abs`, `floor`, `ceil`, `min`, `max`; `year`, `month`, `day`; `map`, `filter`, `first`,
`count`, `join`, `sum`, `any`.

Their meanings, where PHP and SQL differ:

- **`trim` removes all whitespace,** not only spaces as SQL `TRIM` does.
- **`lower` and `upper` follow Unicode.** The conformance tests list the letters a database
  treats differently, such as the Turkish `İ`.
- **`round` always states its mode,** as `Rounding` does today. SQL implements each mode
  explicitly instead of trusting `ROUND`, which rounds floats half to even on MySQL.
- **`split` and regular expressions are PHP only.** A step using them is a column job.

**The code** lives in `Persistence\Migration\Language`: `Syntax` (lexer, parser, printer),
`Tree` (the nodes and their JSON form), `Types` (the checker), `Php` (the PHP backend), and
`Builtins` (one definition per built-in).

## Running a change

| Phase | What happens | The site |
|---|---|---|
| Recorded | Saved in the history, queued per type | Old shape |
| Expand | New tables and columns. Every DDL states `ALGORITHM=INSTANT`, or `INPLACE, LOCK=NONE` | Old shape |
| Sync | Triggers compiled from the data steps keep the new columns current on every write. Column jobs use the `written` catch-up instead | Old shape |
| Pass | The data steps, in batches of id ranges, with a checkpoint per batch. Owned objects are created here, with real ids | Old shape |
| Check | Rule queries, and `fail()` queries finding the rows a step would fail. Those rows go through the policy, or the change stops | Old shape |
| Cut-over | A short lock on the changed types: last rows, the new stored schema, the change live | New shape |
| Contract, later | Old columns and triggers dropped, after a grace period | New shape |

**Crash safety.** Each phase is idempotent and recorded on the change's row, with a lease
row so one process runs it. A crash resumes from the last checkpoint. Before the cut-over a
change can be abandoned: its triggers and new columns are dropped, and nothing used them.

**One change at a time per type.** A second change on the same types waits in the queue.
Changes on other types run alongside.

**A failing row.** A step failing anywhere in an aggregate fails that aggregate whole. Its
values are set aside, as the policies already do, and the rest continue.

**Status:** `recorded`, `expanding`, `passing`, `checking`, `live`, `contracted`, or
`abandoned`. Progress is counted when asked, never stored.

## Measured: triggers

MySQL 8.4 on a laptop, 200,000 products. A save is what `EntityWriter` does: the `entities`
row updated, the level row deleted and inserted. The triggers fill two new columns on the
row (a currency `match` and a label), and keep an owned supplier current: created, updated,
or deleted when the name goes empty.

| Saves | p50 | p99 | Max | Saves in 8 s |
|---|---:|---:|---:|---:|
| One process, no triggers | 0.18 ms | 2.2 ms | | |
| One process, same-row trigger | 0.18 ms | 2.7 ms | | |
| One process, both triggers | 0.24 ms | 3.0 ms | | |
| Four processes, no triggers | 0.34 ms | 4.3 ms | 30 ms | 42,105 |
| Four processes, both triggers | 0.49 ms | 5.2 ms | 62 ms | 29,983 |
| Four processes, both triggers, pass running | 0.55 ms | 25.5 ms | 126 ms | 18,329 |

- Expand with `ALGORITHM=INSTANT`: 13 ms on 200,000 rows.
- The pass: 200 batches of 1,000 in 7.9 s, while four processes saved. The first
  proposal's SQL backfill took 14.0 s in batches of 500, filling one column; this pass
  filled two and created 143,000 owned objects. Neither is the aggregate path in PHP, which
  was never measured at this size.
- **Correct:** after the pass, with saves racing it, every product's new columns matched
  its old ones, every named product had exactly one supplier with the right values, no
  unnamed product had one, and no supplier lacked its row.
- **Stable ids:** 2,126 saves keeping a name kept their supplier's id, 0 changed. The slot
  (owner, owner field, position) finds the existing object, so the delete and insert of a
  save don't make a new one.
- A deleted product took its supplier with it, through the foreign key.
- **Deadlocks:** saves racing the pass hit 31 deadlocks in 18,329 saves, all succeeding on
  retry. The save must retry a deadlock. Today `EntityWriter` doesn't.
- The test was saves only, at full speed. A real save writes more rows, so a trigger's share
  of it is smaller. Throughput fell 29% under full contention, while the change ran only.

SQLite 3.53: the same triggers work and pass the same checks, written differently. A
`BEFORE` trigger can't set `NEW`, so an `AFTER` trigger updates the row; triggers have no
`IF`, so each branch is its own trigger with a `WHEN`. Ids come from a PHP function the
connection registers. 0.011 ms a save without triggers, 0.067 ms with them.

MariaDB isn't measured yet.

## Editor changes

The admin saves a change. It's recorded, and the save request runs the phases within its
time budget. A command run by cron carries on past it, and a missing cron is reported. The
admin sees the progress, and the editor shows the new shape when the change is live.
Content saves continue in the old shape meanwhile. An admin can go on editing the draft of
the next change.

PHP classes don't see editor changes. A class holds only the fields it declares, which the
editor can't change; editor fields live in the `DynamicEntity` bag. A change giving an
editor-created type another native ancestor changes its native view class at the cut-over.

## Native deploys

**The change is made on the developer's machine.** `migrate --make` compiles the new
classes, compares them with the history's newest version, runs the planner, completes every
operation, and writes the change into the module's file, with a data step left to fill in
where the planner can't decide. The developer reviews it and commits it.

**Prepare needs data, not code.** It reads the stored schemas and the module files. From
those it computes the new schema, the new layout, the DDL, the triggers, the pass and the
checks. It runs on the old release's engine, with the new release's files not yet live.

- Column jobs are the exception: their class must already be on the server, so it ships in
  an earlier release. It's new code nothing calls yet.
- The change states its language version. An engine too old to know it can't prepare it: the
  engine update ships first, or the deploy falls back to maintenance.

**The deploy, with an instant switch of the source folder:**

1. `migrate --prepare` while the old release serves: expand, sync, pass, check.
2. `migrate --cutover`, then the switch, in one command.
3. The new release serves. Its data is already in the new shape.
4. Contract, later.

**Boot check.** The new release compiles its classes and compares their hash with the live
version. A prepared change not yet live: it cuts over first. A change not prepared: it
serves maintenance. A class edited without its change: it refuses to start, naming the
types. `check()` in CI finds this first.

**Stragglers.** A process knows the version its code matches. If the live version is newer,
it may not read or write the types changed since, and gets "code outdated, retry". Writes
check it exactly, under the save's lock; reads check it once per request. This hits:

- requests running at the switch, which finish on the old code
- OPcache and the realpath cache, which can serve old files after a symlink switch; the
  deploy docs require nginx to pass `$realpath_root`, or PHP-FPM to reload
- long-running workers, which the deploy must restart

**FTP.** An upload replaces files one at a time, so the site runs mixed files during it. That
breaks any PHP application, and it's handled the way WordPress does: maintenance.

- Done well: upload the module files first, which the old code ignores; prepare from the
  admin or by cron; set maintenance and upload the code; the first request on the new code
  cuts over and clears maintenance. Down for the upload only.
- Done badly, everything at once: the new code finds a change not prepared and serves 503
  with `Retry-After` while prepare runs. Down longer, but the data is never at risk.

## Local development

Nobody else writes to a local database, so the ceremony goes:

- `migrate` runs every phase back to back: no triggers, no catch-up, no waiting.
- The newest change is a **draft** while it isn't committed: the last entry in the module's
  file, marked as one. Each `migrate --make` rewrites it from the last committed version to
  the current classes. The local database moves forward by the difference.
- `migrate --reset-draft` restores the local database to the committed version, from a
  snapshot taken when the draft started. Moving data back is allowed locally only.
- The local `migrate` runs the same SQL as production, so a wrong step fails here first.

## Old input

Every API request names its schema version.

- **Old input:** a request built on an older version, such as an admin who opened a product
  before a cut-over and saves after it, is translated forwards in memory by the PHP backend.
- **No old output.** A request reading on an older version gets "schema changed, reload".
  Nothing is translated backwards, so changes have no reverses. An integration that must
  not break shouldn't depend on content shapes directly.
- Stored data never moves back.

## What it keeps and what it drops

**Keeps:**

- field identity and opaque column names: renames and moves within a level cost nothing
- the fixup kinds, the planner, policies and set-asides
- dense content: defaults stored as values, owned defaults as rows. An added scalar field
  with a default costs no row write, through `ALGORITHM=INSTANT`
- the `written` tokens, for the catch-up
- `AggregateValues` and the value-moving code, as the **reference implementation**: the SQL
  backend's tests run each change both ways and compare the results

**Drops:**

- the stage 3 reader: `PendingConversion`, `ContentChange`, the marker tables, and
  `EntityReader` and `EntityWriter` taking a conversion
- `version` and `version_written` on `entities`, which were planned
- "a change is live at save", the backfill after it, and rows left behind
- `Migrator`'s move in one transaction, as the way changes reach content
- `FixupAtStep`
- converters, initializers and transformations as PHP interfaces: steps in the language, or
  `#[FixupLogic]` column jobs

## Stages

Each stage works on its own and has its tests.

1. **The language core.** Done for data steps: the tree and its JSON, the parser and
   printer, the type checker, the PHP backend, and the built-ins with their conformance
   cases. Type renames between the two shapes come with stage 2's schema operations.
2. **Changes as before → steps → after.** A change holds its before's hash, the after of
   the types it touches, and its steps: thin schema operations and data steps. Fixups read
   their target from their own change's after. The planner gives one step per field per
   change, by field id, and `FixupAtStep` goes. Type renames between `old` and `new`, the
   dropped-data check, and `migrate --make` writing the module file, with `todo` data steps
   where a person must decide.
3. **The history.** The `changes` table, module files and baselines, stored schemas as the
   newest live version, old versions rebuilt from the afters, hash checks, old schemas
   without classes, and the purge command.
4. **The SQL backend.** Steps compiled to SQL on MySQL and SQLite, owned objects created by
   slot, and the differential tests against `AggregateValues`.
5. **The runner.** Expand, the pass with checkpoints, the checks, the cut-over lock,
   contract, the lease, and a test stopping at every phase. Local mode and the draft.
6. **Sync.** Triggers compiled from the steps, the `written` catch-up for column jobs, and
   the save retrying deadlocks.
7. **Editor saves through the runner,** with progress in the admin and the queue per type.
8. **Native deploys.** `--prepare`, `--cutover`, the boot check, stragglers, maintenance,
   and the FTP flow.
9. **Column jobs** and `#[FixupLogic]` classes with their hash.
10. **Old input.**
11. **Removing what it drops,** once nothing uses it.

The later stages of `PROPOSAL_FOR_SAVING.md` (narrowing, batches of entities, schema
batches, the admin's mechanic, diff writes, atomic operations) follow unchanged.

## Open points

1. **MariaDB.** Triggers, `ALGORITHM` and the SQL each built-in compiles to aren't measured
   there yet.
2. **The syntax's remaining details.** Writing into an existing Collection's items, a
   step on an owned Collection's items, and rules in the language.
3. **The built-ins.** Which ones, with which exact meaning, and which are PHP only.
4. **DDL that can't run online.** A shadow table, as gh-ost does, or refuse?
5. **Rules in SQL.** Which rules compile to check queries. A rule that can't is checked by a
   column job.
6. **Squashing module files.** The command writing a baseline, and how a site older than it
   is told to upgrade in two hops.
7. **Changes across modules.** How a change in one module that depends on another module's
   change is ordered.
8. **Triggers on large tables, and pacing.** A pass of millions of rows keeps triggers for
   its whole length, and deadlocks rise with contention. The pass must pace itself, as
   gh-ost does: slow down when save latency or replica lag rises, even if it takes longer.
   Measured here, saves' p99 went from 5 ms to 25 ms during an unpaced pass. Batch size,
   the signals and their limits need measuring.
9. **Contract timing.** How long old columns stay after the cut-over.
10. **Owned Collections in triggers.** A default Collection of owned objects created by a
    trigger needs one row per item. Not tested yet.
11. **Undo.** Left out for now. If it comes back, it's a new forward change, and the old
    columns kept until contract are its natural source.

## Prior art

- **Online schema changes:** gh-ost, pt-online-schema-change (triggers keeping a copy
  current), expand and contract.
- **Migrations recorded and checked:** Django's `makemigrations`, Flyway and Liquibase
  checksums.
- **A small language compiled to PHP:** Twig.
- **Maintenance during an update:** WordPress's `.maintenance`.
- **Atomic deploys:** Deployer and Capistrano, with `$realpath_root`.
