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

**Every change is complete.** It states its schema operations, its data steps, its policy
choices and its set-asides, all in the language. Applied to version n, it gives version
n + 1, with nothing else to look up: no class, no target schema. Replaying it never asks the
policy again.

**The stored schemas are the newest live version.** They're written in the cut-over's
transaction, never on their own, and checked against the history's hash. A mismatch is an
error, never served.

**Old versions.** A change keeps a copy of each type it touches, as it was before. Any
version back to the oldest copy kept can be rebuilt:

- **The rebuild rule.** The shape of type T at version v: find the changes after v that
  touched T, and take the oldest one's before copy of T. No change between v and that one
  touched T, so its copy is T as at v. If no change after v touched T, the stored schema has
  it. A deleted type is covered, since the change deleting it touches it.
- **Old schemas load without their classes.** A value object or an enum whose class is gone
  is kept by name and columns. Rebuilding a shape needs only those.
- A command purges the copies older than a date the admin chooses: the compatibility window.

## The change language

**One tree, stored as JSON.** Each node is an operation and its arguments. People write a
text form, which a parser turns into the tree. The editor builds the tree from its UI and
shows it back as text, so diffs are readable.

**The whole change is in the language:** schema operations (add, drop, rename, move,
retype a field; add, drop, rename, replace, reparent a type; unique groups; rules) and data
steps.

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
- a plain copy (`email: old.supplier.email`) is seen as one: it rewrites queries and has an
  exact reverse. A computed value doesn't rewrite queries, and its reverse is lossy unless
  one is written.

**Pure.** A step can't read the clock, random numbers, the database, files or the network.
The same input always gives the same output. Owned objects a step creates get their ids
from the engine, not from the step.

**Built-ins are defined once and never change.** A function whose behaviour must change
gets a new name. Each change records the language version it needs.

**Two backends.**

- **SQL** applies changes to the database. It's the main path.
- **PHP** translates requests in memory (old input, old output) and runs column jobs.

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

## Old input and old output

Every API request names its schema version.

- **Old input:** a request built on an older version, such as an admin who opened a product
  before a cut-over and saves after it, is translated forwards in memory by the PHP backend.
- **Old output:** an answer translated back to the client's version, within the
  compatibility window. Past it, or through a lossy step, the answer is "schema changed,
  reload".
- **Undo** is a new forward change built from the reverses of the one undone. Stored data
  never moves back.

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

1. **The language core.** The tree, the text parser and printer, the type checker, the PHP
   backend, and the first built-ins with their conformance harness.
2. **Complete changes.** Every schema operation as a statement, data steps with `old` and
   `new`, the planner completing them, the dropped-data check, and `migrate --make`
   writing the module file.
3. **The history.** The `changes` table, module files, stored schemas as the newest live
   version, before copies, the rebuild rule, hash checks, and old schemas without classes.
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
10. **Old input and old output.**
11. **Removing what it drops,** once nothing uses it.

The later stages of `PROPOSAL_FOR_SAVING.md` (narrowing, batches of entities, schema
batches, the admin's mechanic, diff writes, atomic operations) follow unchanged.

## Open points

1. **MariaDB.** Triggers, `ALGORITHM` and the SQL each built-in compiles to aren't measured
   there yet.
2. **The syntax.** Its operations, types, functions, conditions, `let`, lists, and how a
   step reads and writes Collections.
3. **The built-ins.** Which ones, with which exact meaning, and which are PHP only.
4. **DDL that can't run online.** A shadow table, as gh-ost does, or refuse?
5. **Rules in SQL.** Which rules compile to check queries. A rule that can't is checked by a
   column job.
6. **Squashing module files.** How old entries are folded once every database has them.
7. **Changes across modules.** How a change in one module that depends on another module's
   change is ordered.
8. **Triggers on large tables, and pacing.** A pass of millions of rows keeps triggers for
   its whole length, and deadlocks rise with contention. The pass must pace itself, as
   gh-ost does: slow down when save latency or replica lag rises, even if it takes longer.
   Measured here, saves' p99 went from 5 ms to 25 ms during an unpaced pass. Batch size,
   the signals and their limits need measuring.
9. **Contract timing.** How long old columns stay, and how a removal is undone meanwhile.
10. **Owned Collections in triggers.** A default Collection of owned objects created by a
    trigger needs one row per item. Not tested yet.

## Prior art

- **Online schema changes:** gh-ost, pt-online-schema-change (triggers keeping a copy
  current), expand and contract.
- **Migrations recorded and checked:** Django's `makemigrations`, Flyway and Liquibase
  checksums.
- **A small language compiled to PHP:** Twig.
- **Maintenance during an update:** WordPress's `.maintenance`.
- **Atomic deploys:** Deployer and Capistrano, with `$realpath_root`.
