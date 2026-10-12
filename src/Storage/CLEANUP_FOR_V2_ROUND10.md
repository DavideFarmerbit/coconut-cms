# Storage / Editor v2 — Cleanup Before Building (Round 10)

**Status: in progress (2026-10-12).** A tenth audit pass over `ARCHITECTURE_V2.md`/
`ROADMAP_V2.md`, checking the two documents against each other for inconsistencies, missing
pieces, and design flaws, and separately checking both against the standalone-document rule
([[feedback_v2_docs_standalone]]) for stray references to pre-rewrite docs/concepts, and
for the architecture-never-names-roadmap direction of that same rule. Nine items below; item
1 resolved, eight still open. Work one item at a time per
[[feedback_coconut_cms_development_workflow]]. Ordered by how much it changes what gets
built, not alphabetically.

## Missing pieces

### 1. Owned-collection items have no way to express owner-scoped uniqueness — **resolved (2026-10-12)**

"Uniqueness" was explicit and consistent (confirmed against `ROADMAP_V2.md` Phase 3.3's own
"Done when") that an Owned-collection item's `Unique` group is always global, never scoped to
its owner — because the group's named fields live on the item's own CTI-chain table, which has
no `owner`/`owner_field` column of its own to fold into a composite index (those columns live
only on `entities`). Only a *non-entity* collection's dedicated child table got owner-scoped
uniqueness, because that table already carries `ownerId` directly.

This wasn't a contradiction — it was deliberate and the stated reasoning held — but it was a
real practical gap. Common CMS shapes need exactly the scoping this couldn't express: a
variant's SKU unique *within* a product, a line item unique *within* an order. As written, the
only way to get owner-scoped uniqueness on something that otherwise deserves to be a real
Entity (its own identity, independently queryable, referenceable) was to not make it an
Entity — fighting "Entity vs. Value Object vs. Embed"'s own test for when something must be a
full Entity.

**Resolved: a `Unique` group on an Owned-collection item is automatically owner-scoped — not
a choice.** Three options were on the table: a denormalized scope key on the item's own
table, folded into the index; an application-level pre-check made safe by the existing
`expectedOperationId` serialization of an Owned subtree; or just documenting the limitation.
Went with denormalization — it's the only one that keeps "Uniqueness"'s existing rule intact:
a real database constraint, never an application-level check.

A first pass made this an opt-in flag per group. Dropped that in favor of making it automatic,
once the parallel to Embed was pointed out: a non-entity collection's own `Unique` group
*already* folds `ownerId` into its index unconditionally, no flag, no choice — "Collection
cardinality, non-entity only" never offered a global alternative for it. Owned and Embed only
differ in type-erasure vs. polymorphism; the ownership semantics ("this row has no life apart
from its owner") are identical, so there was no real reason for Owned-collection to behave
differently from the Embed-collection case it already sat next to. The one counter-case
considered — a field that needs to stay unique *across* different owners, e.g. a globally
unique code — doesn't actually need an opt-out: needing cross-owner uniqueness is itself the
same signal "Entity vs. Value Object vs. Embed" already uses for "this needs its own
independent identity," so it's a sign the field belongs on a standalone entity referenced by a
`Reference`, not on something Owned. Closing that door is deliberate, not a gap.

A second pass simplified the mechanics further, after two follow-up questions. First: is it
worth adding the two columns to *every* entity's own table, unconditionally, rather than only
when a shape first becomes an Owned-`Collection` item somewhere? Yes — it removes the
add-on-first-use/drop-on-last-use trigger entirely. If `owner`/`owner_field` exist on every
CTI-chain table from the moment that table is built (not just `entities`), every row already
carries the correct value — real or sentinel — from its own creation, forever. Declaring (or
un-declaring) owner-scoping on a `Unique` group then only ever changes the index definition,
never the columns: no `ALTER ADD/DROP COLUMN`, no backfill, ever. Cost: two small columns on
every table, always populated — cheap, and the same kind of uniform cost this design already
pays elsewhere (the `entities` join on every read, `<field>_count` on every Owned-collection
field regardless of required/optional).

A database trigger was considered, to keep the copy in sync without touching application
code at all. Rejected: the insert-time half is a portable same-key lookup (`BEFORE INSERT`,
look up `entities` by `id`), but it saves nothing, since `Repository`/`WriteExecutor` already
compute `owner`/`owner_field` in the same call that writes the `entities` row — writing them
a second time costs nothing extra. The rename-time half isn't portably doable as a trigger at
all: a trigger on `entities` would need to resolve `concrete_identifier` to a table name and
run dynamic SQL against it, which MySQL triggers can't do. Since the rename half has to stay
application code regardless, splitting the two into trigger-plus-code would leave two
different mechanisms maintaining one pair of columns, instead of one — introducing triggers
as a wholly new category of tool this design otherwise never uses, for a net increase in
moving parts rather than a decrease.

Second: the "never changed afterward" claim was wrong. `owner` never changes, correctly —
nothing in this design moves a row to a different owner. But `owner_field` does change: when
the declaring `OwningReference`/Owned-`Collection` field itself is renamed, "Migrations and
schema mutation" already updates `entities.owner_field` for every existing row at that
relationship. The item's own denormalized copy is a second place holding that same value, so
the same rename operation now updates both, in one step — skipped, the copy would silently go
stale and an owner-scoped `Unique` group would start comparing against the wrong bucket.

The reserved `owner` value is the nil UUID (`00000000-0000-0000-0000-000000000000`); the
reserved `owner_field` value is the empty string `""`, since a real field name can never be
empty.

A third pass went further, after the index-level trigger left over from the second pass —
rebuilding a `Unique` group's index whenever some *other* class's field declaration starts
or stops pointing at this shape as an Owned-`Collection` item — was called out as a genuinely
odd thing for anything to have to listen for. Dropped that cross-schema check entirely:
every `Unique` group, standalone, Shared-referenced, Owned singular, or Owned collection,
with no exception, always folds `owner`/`owner_field` into its own index, decided once when
the group is declared. What that protects against now falls out of the data instead of a
schema-level choice: a standalone/Shared row shares the sentinel pair with every other row of
its kind (global, same as before); an Owned-singular row is the only row at its own
`(owner, owner_field)` pair, so the check is real but can never fire across different owners;
an Owned-collection item shares its pair with its siblings, so the check is genuinely
protective there, same as always. The "closing one door on purpose" tradeoff from the first
pass now applies to Owned singular too, not just collection — a field needing cross-owner
uniqueness on anything Owned, any cardinality, is a sign it needs independent identity, same
remedy as before. Checked this against Embed on the way: Embed-singular's own long-standing
"global" behavior isn't a deliberately protective mechanic worth matching — it's a structural
accident of flattening onto the owner's own shared row, with no owner dimension available to
scope by even in principle — so it argued for keeping Owned-singular global on its own
separate merits (a single slot has nothing to compare against within one owner, so scoping it
would make the check permanently vacuous), not for matching Owned to Embed. "Owned is owned"
won anyway: cardinality shouldn't decide whether something Owned gets cross-owner protection,
and dropping that last distinction removes the cross-schema check completely, with nothing
needed in its place.

Folded into `ARCHITECTURE_V2.md`'s "Global entity identity" (the universal `owner`/
`owner_field` copy, written by `Repository`/`WriteExecutor`, synced on rename) and
"Uniqueness" (the two previously-separate bullets collapsed into one — every row with its
own `entities` entry always folds `owner`/`owner_field` in, no exception — and the
now-obsolete "index changes shape" paragraph removed outright; the rename paragraph under
"Migrations and schema mutation" still updates the item's own copy) and `ROADMAP_V2.md` Phase
1.2 (the columns and sentinel values built from day one, before anything is Owned), Phase 1.3
(proving the sentinel pair doesn't weaken a plain standalone group), Phase 3.3 (proving the
collection case protective and the singular case deliberately, provably vacuous — not
assumed), and Phase 6.2 (no retrofit needed on `addField()`, since the index was never
missing the columns; renaming an `OwningReference`/Owned-`Collection` field still syncs both
copies in one operation).

## Real inconsistencies

### 2. `collection()`'s itemDescriptor restriction contradicts the `NoType` conversion mechanics — **resolved (2026-10-12)**

"Shape comes from a neutral descriptor" stated: "`collection()`'s own `itemDescriptor` is any
of the other five factories' output, never another `collection()` or `NoType`." But
"Migrations and schema mutation" and "Uniqueness" both describe a collection's item kind
legitimately *becoming* `NoType` in place (the pivot/child table's value column "converts its
value column(s) to `NoType` the same way" a singular field does) — a real, queryable,
retypeable runtime state, not a forbidden one.

**Resolved: gave the line the same carve-out `NoType` already has elsewhere** ("no public
factory... only produced by the framework's own capture/retype machinery"). The restriction is
on what a developer can pass to the public `collection()` factory, never on what `fieldsOf()`
can later report once the framework's own capture/retype machinery has degraded a collection's
item kind to `NoType` in place.

### 3. `RolePermission` is named once and never defined

"Namespaces and migration path" lists `Persistence\Permission\`'s contents as "`Actor`,
`SchemaPermission`, `FieldPermission`, `HistoryPermission`, `RolePermission`, and any other
auth-level class." `RolePermission` doesn't appear anywhere else in `ARCHITECTURE_V2.md` — not
in "Permissions", which only describes `Actor`, `SchemaPermission`, `FieldPermission`, and
`HistoryPermission` — and doesn't appear anywhere in `ROADMAP_V2.md` either.

**Needs a decision**: either drop `RolePermission` as a leftover, or actually specify what it
is and where it's used.

### 4. The "Deferred" section's converter-API claim is stale

"Deferred — not resolved in this document" lists "Exact attribute/API surface for
`#[DefaultInstance]`, converter classes, rename/retype invocation parameters" as unresolved.
But "Migrations and schema mutation" already fully specifies both converter interfaces by
method signature: `FieldRetypeConverter` (`convert(mixed $captured): mixed`, `from()`/`to():
FieldRetypeSignature`) and `EntityRetypeConverter` (`convert(array $capturedFieldTree):
object`, `from()`/`to(): string`). That's a decided method-level API, not an open question.

**Needs a decision**: narrow the "Deferred" bullet to what's actually still open (e.g.
`#[DefaultInstance]`'s own attribute parameters, native migration-tool invocation parameter
names) and drop converter classes from it, or explain what's still genuinely unresolved about
them if something is.

### 5. `Unique`'s relationship to `PrototypeValidator` is stated two different ways

`ARCHITECTURE_V2.md`'s "Shape comes from a neutral descriptor" lists `Unique` among the things
that "stay their own independent attribute rather than folding into a kind marker's own
parameters" — alongside, not inside, `PrototypeValidator`. `ROADMAP_V2.md` Phase 1.3 and 6.1
instead call it "one of `PrototypeValidator`'s built-in kinds" and have an admin "attach a
`PrototypeValidator` rule... picked from the closed menu... `Unique` is the first one exercised
here" — which reads as `Unique` being attached *through* the `PrototypeValidator` mechanism,
not alongside it.

**Needs a decision**: state plainly whether `#[Unique]` is its own attribute/mechanism that
merely shares the editor's "closed menu" affordance with `PrototypeValidator` for UI purposes,
or whether it's genuinely implemented as a `PrototypeValidator` kind — then make both documents
say the same thing.

## Documentation hygiene

### 6. `ARCHITECTURE_V2.md` names and narrates the old `Storage\` namespace

"Namespaces and migration path": "**The old `Storage\` namespace is left untouched during the
rewrite, not migrated, extended, or deleted.** It keeps working exactly as it does today and
stays available as a running reference to consult while the new `Persistence\`/`Editor\` code
is built — deliberately not reused or built on top of, to keep the rewrite a clean-room effort
rather than dragging the old entanglement forward." This explains the migration's relationship
to old code rather than describing the new system's own shape — it reads like
`CLEANUP_FOR_V2_ROUNDn.md` material, not `ARCHITECTURE_V2.md` material.

**Needs a decision**: either drop this paragraph from `ARCHITECTURE_V2.md` entirely (the
migration-coexistence fact belongs in a cleanup round, not the standalone architecture), or
restate only the part that's a real constraint on the new system (if any) without narrating
what happens to the old namespace.

### 7. "Unchanged from the original design"

"The goal" opens with "Unchanged from the original design: an admin editor in the spirit of
Unreal's UObject/Details-panel system..." — names an external/prior design instead of just
stating the goal.

**Needs a decision**: reword to state the goal directly, with no "unchanged from" framing.

### 8. The `queryable` paragraph narrates a retired pre-rewrite concept

"No blobs": "`queryable` is retired outright, not carried forward in any reduced form. Its
only job was the storage-tier switch — every field being filterable/sortable via SQL depended
on it being `true`. With no second tier to switch into, every field is already a real,
filterable/sortable column regardless, so there's no correctness gap left for a flag to
cover." This narrates a pre-rewrite concept (`queryable`, the blob storage-tier switch) and its
retirement, rather than just stating that every field is already real and filterable.

**Needs a decision**: reword to state directly that every field is a real, filterable/sortable
column (which is already the standalone fact that matters), without naming or explaining the
retired `queryable` flag.

### 9. `ARCHITECTURE_V2.md` names `ROADMAP_V2.md`, violating the one-directional citation rule

Two instances: "Namespaces and migration path" — "...it's the UI-facing surface built on top
of this system, not part of the persistence system itself, a separate later track per
`ROADMAP_V2.md`." And "Content write path" — "...this is also why the mechanism can't exist
before the undo log does; `ROADMAP_V2.md` sequences it accordingly." Per
[[feedback_v2_docs_standalone]], the roadmap cites the architecture, never the reverse — both
facts here (Editor is a separate later track; the undo-log ordering is a sequencing constraint,
not a structural one) can be stated without naming the roadmap file.

**Needs a decision**: reword both passages to state the fact itself (Editor's namespace
independence; the ordering dependency between concurrent-write protection and the undo log)
without citing `ROADMAP_V2.md`.
