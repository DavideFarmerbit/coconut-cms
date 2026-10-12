# Storage / Editor v2 — Cleanup Before Building (Round 10)

**Status: open (2026-10-12).** A tenth audit pass over `ARCHITECTURE_V2.md`/`ROADMAP_V2.md`,
checking the two documents against each other for inconsistencies, missing pieces, and
design flaws, and separately checking both against the standalone-document rule
([[feedback_v2_docs_standalone]]) for stray references to pre-rewrite docs/concepts, and
for the architecture-never-names-roadmap direction of that same rule. Nine items below, none
resolved yet. Work one item at a time per [[feedback_coconut_cms_development_workflow]].
Ordered by how much it changes what gets built, not alphabetically.

## Missing pieces

### 1. Owned-collection items have no way to express owner-scoped uniqueness

"Uniqueness" is explicit and consistent (confirmed against `ROADMAP_V2.md` Phase 3.3's own
"Done when") that an Owned-collection item's `Unique` group is always global, never scoped to
its owner — because the group's named fields live on the item's own CTI-chain table, which has
no `owner`/`owner_field` column of its own to fold into a composite index (those columns live
only on `entities`). Only a *non-entity* collection's dedicated child table gets owner-scoped
uniqueness, because that table already carries `ownerId` directly.

This isn't a contradiction — it's deliberate and the stated reasoning holds — but it's a real
practical gap. Common CMS shapes need exactly the scoping this can't express: a variant's SKU
unique *within* a product, a line item unique *within* an order. As written, the only way to
get owner-scoped uniqueness on something that otherwise deserves to be a real Entity (its own
identity, independently queryable, referenceable) is to not make it an Entity — which fights
"Entity vs. Value Object vs. Embed"'s own test for when something must be a full Entity.

**Needs a decision**: accept the global-only limitation as-is (and say so plainly, so nobody
hits this expecting scoped uniqueness to work), or find a structural way to fold `owner`/
`owner_field` into an Owned-collection item's own constraint (e.g. a denormalized owner-scoping
column on the item's own table, maintained alongside `entities.owner`).

## Real inconsistencies

### 2. `collection()`'s itemDescriptor restriction contradicts the `NoType` conversion mechanics

"Shape comes from a neutral descriptor" states: "`collection()`'s own `itemDescriptor` is any
of the other five factories' output, never another `collection()` or `NoType`." But
"Migrations and schema mutation" and "Uniqueness" both describe a collection's item kind
legitimately *becoming* `NoType` in place (the pivot/child table's value column "converts its
value column(s) to `NoType` the same way" a singular field does) — a real, queryable,
retypeable runtime state, not a forbidden one.

**Needs a decision**: the "never `NoType`" line should get the same carve-out `NoType` already
has elsewhere ("no public factory... only produced by the framework's own capture/retype
machinery") — i.e. clarify that the restriction is on what a developer can pass to the public
`collection()` factory, not on what `fieldsOf()` can later report once the framework's own
machinery has degraded a field.

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
