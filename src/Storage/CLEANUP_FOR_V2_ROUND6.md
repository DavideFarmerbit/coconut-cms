# Storage / Editor v2 — Cleanup Before Building (Round 6)

**Status: open.** A sixth audit pass over `ARCHITECTURE_V2.md`/`ROADMAP_V2.md`, done after
Round 5's items were folded back into both documents, this time cross-referencing
`AUDIT.md` directly against the claim in `ARCHITECTURE_V2.md`'s own opening paragraph that
"most of its open items are resolved here." Ordered by how much it changes what gets
built, not alphabetically. Each item below is still open — gets a **Decided** note and
folded into both documents as we close it, one at a time, the same way Rounds 1-5 did.

## Missing pieces

### 1. `AUDIT.md` item 13 — `EntityManager`/`SchemaEditor` table-map desync — had no resolution anywhere in either document — **resolved (2026-10-05)**

`AUDIT.md` §13 is, by its own text, the biggest single bug the old system had: `EntityManager::$tables`
is frozen at construction while `SchemaEditor::$tables` is a separate, mutable map, so a
`SchemaEditor` mutation made earlier in a request is invisible to any `Repository`/`Query`
already in hand, and rebuilding `EntityManager` to pick up the change silently discards its
`IdentityMap`. The audit's own framing: "not 'undo is incomplete for this,' but 'using both
halves back to back doesn't work at all' without a manual rebuild step nobody has designed
yet."

`ARCHITECTURE_V2.md` described `PrototypeRegistry` as the one generic interface
(`fieldsOf()`/`instantiate()`/`chainOf()`) that `Repository`, `ChangesetFlusher`, `Query`,
and `SchemaEditor` all consult — but never stated whether that registry resolves live,
per-call, or is snapshotted once and handed around (reproducing the exact bug). It also
never revisited whether rebuilding/refreshing a registry is expected to preserve or discard
the `IdentityMap`.

Reading the actual old `Storage\Schema\PrototypeRegistry` resolved both open sub-questions
at once, and the answer turned out to already exist, half-built:

- **Dispatch**: `Storage\Schema\PrototypeRegistry::isEditorCreated()` already does this,
  correctly, with no stored flag: `return !class_exists($identifier)`. A native identifier
  is a real PHP class-string, so that one test is free and authoritative. Every other
  method (`ownFieldsOf()`, `parentOf()`, `instantiate()`, ...) branches on it inline.
- **Caching**: `EntityManager.php`'s own docblock on its `PrototypeRegistry` property says
  it outright — "safe since `PrototypeRegistry` is stateless, every method reads the
  database directly." There is no cache in that class at all: `fieldsOf()`/`instantiate()`/
  `chainOf()` were already immune to this bug, for both identifier kinds, before this round
  started.

So the AUDIT finding doesn't live in `PrototypeRegistry` at all — it lives in a narrower,
separate thing `EntityManager` keeps on the side: `private readonly array $tables`,
`identifier => table name`, injected once at construction from `EntityRegistrar::register()`'s
output, with `tableOf()` just throwing if an identifier isn't in it. That map is genuinely
correct to freeze *for native classes* (table names can't change without a redeploy) — the
bug is that nothing ever taught it to fall back to a live lookup for an editor-created
identifier the way `fieldsOf()` already does.

**Decided**: no new mechanism, just the same split `PrototypeRegistry` already proved out
for shape, generalized to table-name resolution too — a native identifier's table name stays
decided once at registration time and frozen for the process's life; an editor-created
identifier's table name resolves live, off the same stored row already queried for its
parent, through the same `class_exists()` test. Folded into `ARCHITECTURE_V2.md` ("Shape
comes from a neutral descriptor," two new paragraphs) and `ROADMAP_V2.md` (Phase 1.1's
`PrototypeRegistry`/`EntityRegistrar::register()` bullets, and Phase 1.2's throwaway-second-
identifier-kind bullet and "Done when," extended to cover table-name resolution explicitly,
not just `fieldsOf()`/`instantiate()`) — described there in the resolved design's own terms,
with no back-reference to the old class or to this audit item, per the standalone
requirement both documents are held to now (see [[feedback_v2_docs_standalone]]).

### 2. Flipping `unique` on an already-existing field is still unaddressed — **resolved (2026-10-05)**

`AUDIT.md` §6 named three un-migratable toggles on an existing field: `queryable`, `unique`,
`Ownership`. V2 explicitly closes two of them — `queryable` is retired outright ("No blobs"),
`Ownership` flipping is covered by the Shared↔Owned retype case ("Migrations and schema
mutation"). `unique` was never mentioned again anywhere after Phase 1.3 introduced it
("Uniqueness: `unique` flag, real `UNIQUE` constraint, friendly pre-check"). `SchemaEditor`'s
mutation set for editor-created fields is explicitly closed to `addField()`/`dropField()`/
`rename()`/`retype()` ("Nothing else, ever, at the field level") — none of those four was "add
or remove a uniqueness constraint on a field that already has data," and retype is about
changing a field's *kind*, not a constraint on top of the same kind.

This turned into the single longest discussion of this round, because the first proposed fix
(a narrow `setUnique()` operation, parallel to add/drop/rename/retype) kept surfacing bigger
problems the moment it was pushed on: how a flag-only change should be allowed to interact
with a simultaneous kind change; how backfilling a newly-required field could ever stay
consistent with a uniqueness guarantee once more than one row needs a value; whether the same
flag means the same thing when a shape is reused as a standalone entity versus an `#[Embed]`
target versus an Owned relationship; and a direct objection to having two different places
(`FieldDescriptor::$unique` and `PrototypeValidator`) that could each independently claim to
express "this must be unique." Working through each one in turn converged on a single,
coherent model, below. Order follows the actual discussion, since later decisions depend on
earlier ones.

**`unique` stops being a `FieldDescriptor` flag and becomes a validator kind.** Single-field
uniqueness is just the one-field case of "these named fields of this shape, together, must be
unique" — no different in kind from a multi-field group. This lives in the same family as
`FieldValidator`/`PrototypeValidator`, not as a separate attribute system, specifically so it
doesn't become a second, independently-maintained place a reader has to check alongside
`PrototypeValidator` to know everything a shape requires — the exact objection that started
this half of the discussion. A shape may declare more than one independent `Unique` group at
once (e.g. `[sku]` and, separately, `[street, city, zip]`) — nothing restricts it to one.

**Enforcement is a real database constraint, never an application-level check, because the
latter is a race two concurrent writers can both slip through.** Because the framework
recognizes `Unique` as a specific, structurally-understood kind rather than opaque validator
logic it can't see inside, it treats a declared group as schema work: at
registration/schema-sync time it adds a real composite (or single-column) `UNIQUE` index to
whatever table the named fields physically land on; at write time it relies on that index for
atomic enforcement and translates any violation into a friendly, named refusal, rather than
running its own "check for a duplicate, then write" pass first. This is always achievable for
a `Unique` group specifically, because by construction it only ever names fields of the one
shape declaring it — never a different entity's own columns (see the explicit exclusion
below, which is what keeps this guarantee true).

**Where the constraint physically lands, by how the declaring shape is used:**
- **Standalone / Shared-referenced / Owned** (singular or collection): every such row of a
  concrete type lives in the one shared physical table every chain is rooted through, so the
  constraint is global — every standalone row and every Owned row under every relationship,
  compared together. Deliberate, not a compromise: there is no narrower storage to scope it
  to, since the whole point of the shared `entities` table is exactly to avoid a dedicated
  table per relationship.
- **`#[Embed]` target**: each embedding site gets its own, independently-flattened copy of
  the columns (`House.homeAddress_street` and `Order.deliveryAddress_street` are unrelated
  columns, possibly on unrelated tables). A declared group materializes as an *independent*
  composite index *at each site*, never spanning sites — propagated to every embedding table
  the same way rename/retype already fan out, but each site's resulting constraint stands
  alone.
- **Collection cardinality** (Owned collection, Shared pivot, or a non-entity/`#[Embed]`
  collection's own dedicated child table): every owner's items share one physical table, so
  the table's own scoping column (`owner`/`owner_field`, or the dedicated table's `ownerId`)
  is folded into the materialized index automatically, alongside whatever fields were named —
  otherwise the constraint would wrongly span every different owner's collection system-wide
  instead of staying scoped to one owner's own items.

**Two structural exclusions, both rejected at registration time — same posture as every other
structural violation already in this design** (Embed-of-Embed cycles, a `Reference`/
`Collection` field inside an `#[Embed]` target):
- **A `Unique` group can never name a field reached through a `Reference`.** The compared
  columns would live on the *referenced* row's own table, not on any table the constraint
  could be declared against — and a `Reference` is pointer semantics to something with its own
  independent life (see "FK `ON DELETE` policy"), so constraining it from the referrer's side
  is the wrong model regardless. A rule that genuinely needs to compare what a collection
  *references* is not a gap left open by this exclusion: it's an ordinary `PrototypeValidator`
  rule instead, and it turns out to need nothing new to be safe — candidate-state assembly
  already hydrates reference values into real objects before validation runs, so the rule can
  read the referenced objects' own fields directly without any new database-access mechanism;
  and the race a database constraint would otherwise prevent is already closed by the existing
  concurrent-write protection, since any write touching a collection's own membership already
  requires the owning entity's current `expectedOperationId`, serializing concurrent edits to
  the same collection for an unrelated reason. Nothing left unresolved here, once connected.
- **A `Unique` group can never span fields declared at different levels of a chain.** A native
  CTI parent's own fields and a descendant's own newly-declared fields live on two different
  physical tables joined through `entities`, the same structural reason a `Reference`-reached
  field can't be named — one `UNIQUE` index is always exactly one table. A descendant is free
  to declare its own group purely over its own fields; the two never combine into one
  constraint.

**Non-retype field operations never produce a new value, only accept-or-refuse against what's
already there.** Renaming a field is a pure metadata change, unaffected regardless. Adding or
removing `Unique` membership with no kind change underneath never touches existing data: it
runs a friendly pre-check over the current live values (refusing, naming the collision, if
any duplicate already exists) and then issues the real `ALTER ... ADD UNIQUE`; removing
membership is always safe, no check needed. Retype remains the only field-level operation that
ever produces a new value at all.

**Retype's existing converter needs no new mechanism, only a check appended after it runs.**
The converter was already specified correctly: one object, invoked once per existing *entity
row of the table being retyped* — never once per item inside a single row's own collection.
For a singular field, that one call's argument is the one row's own captured `NoType` value;
for a `Collection`-cardinality field, it's still a single argument, the whole assembled array
of that one row's own items (a dense entry per position, an empty placeholder standing in for
any gap a sparse Owned collection left behind). This distinction matters here specifically,
and almost got lost in an earlier pass at writing this up: "per row" must mean per row of the
table, never per item of one row's own collection, or the distinctness check below would be
checking the wrong thing entirely. What's new: once the converter's run across every affected
row, if the target field participates in a `Unique` group the framework collects every *row's*
produced value — one per row, across the table, never per item inside any one row's own
collection — and checks that set for pairwise distinctness (and against any existing live
values) before committing anything, refusing the entire retype, naming the collision, if it
isn't distinct. The converter itself stays exactly as already specified; producing genuinely
distinct values (e.g. deriving from the row's own already-unique id) is the person writing the
converter's responsibility, not something the framework orchestrates. Plain new-field backfill — today a single `#[DefaultInstance]` value
reused identically for every row, no converter option at all — gains the same optional
per-row converter retype already has, required whenever the field being backfilled
participates in a `Unique` group and more than one row needs a value, since the reused-default
path can never be distinct past the first row. An optional, unique field skips all of this
regardless of row count: backfilling to `NULL` is always safe, since standard SQL `UNIQUE`
semantics never treat two `NULL`s as colliding.

**Cleanup on rename or removal is mostly already free, with one genuinely new case.** Dropping
the whole table (prototype/class deletion) or the sole field of a single-field group drops the
index with it, no extra mechanism. Renaming a field or the declaring class needs the index's
own column/name reference renamed as part of the same already-decided rename-propagation, not
a new mechanism, just one more artifact that fan-out needs to reach. The one new case: dropping
one field out of a *multi*-field group while the rest remain is refused outright unless the
group itself is explicitly narrowed or removed in the same operation — silently shrinking a
three-field guarantee down to two as a side effect of an unrelated field removal is exactly the
kind of silent consequence this design refuses everywhere else.

Folded into `ARCHITECTURE_V2.md` (a new "Uniqueness" section between "References and
collections" and "Defaulted instances," plus the backfill-converter extension inside
"Defaulted instances" and the batch-distinctness check inside "Migrations and schema
mutation"'s retype/`NoType` material) and `ROADMAP_V2.md` (Phase 1.3's uniqueness bullet
reworded for the new mechanism, with scoping/exclusion cases added to the "Done when" of the
later phases that introduce the features they depend on — Phase 2 for `#[Embed]` per-site
scoping, Phase 3.1 for the cross-chain-level exclusion, Phase 3.2 for the `Reference`
exclusion, Phase 3.3/3.4 for collection owner-scoping, Phase 6.2 for the retype-distinctness
check). Described in each document in the resolved design's own terms, per the standalone
requirement (see [[feedback_v2_docs_standalone]]).

### 3. `PrototypeValidator` and the client-side `describe()` validation tiers are described but never scheduled

"Validation" introduces `PrototypeValidator` (a separate entity-level interface for
cross-field rules) and a three-tier client-side pre-validation scheme behind one `describe()`
method, alongside `FieldValidator`. `ROADMAP_V2.md` schedules only `FieldValidator` (Phase
1.3). Neither `PrototypeValidator` nor the `describe()`/client-tier work appears in any phase,
and neither gets the explicit "deferred to `Editor\`" treatment Draft got in Round 5 item 3.
Right now a reader can't tell whether these were meant to land in Phase 1.3 alongside
`FieldValidator` and simply got left off the bullet list, or whether they're intentionally
later/out-of-scope and just missing the pointer saying so.

**Open:**
- `PrototypeValidator`: which phase? It doesn't depend on anything past Phase 1 (it's a
  cross-field check against "the whole candidate state," no relationship/embed machinery
  needed for the simplest case) — candidate for folding into Phase 1.3, or does it want to
  wait until native CTI extension / relationships exist so cross-field rules spanning those
  have something to test against?
- `describe()`/client-side tiers: is this `Persistence\Schema\`'s concern at all (a method on
  `FieldValidator` that `Editor\` later consumes), making it a backend roadmap item, or is it
  entirely `Editor\`'s own design pass like Draft and `LocalCommand`/`RemoteCommand`? The doc
  currently reads like the former (it's inside "Validation," not inside the `Editor\`-deferred
  bullet list) but the roadmap treats it like the latter (silence).

### 4. Owned-collection "remove slot" vs. "clear slot" mechanics have no named owner

"References and collections" establishes that `<field>_count` must distinguish "remove this
slot" (count shrinks, later positions shift down) from "clear this slot's content" (count
unchanged, slot survives empty) — and Phase 3.3's "Done when" tests both behaviors
explicitly. Neither document ever states which component performs the position-shift/
count-decrement arithmetic on an ordinary content edit — contrast with how precisely the
schema-mutation-time cascade (`dropField()` on the relationship) is specified elsewhere.

**Open:**
- Is this `Persistence\Entity\Repository`'s job (an "edit this Owned collection" primitive
  that knows how to shift positions and adjust the count), or does it belong to
  `Persistence\Changeset\` alongside the other collection-shape bookkeeping?
- Does inserting into the middle of an Owned (or Shared, or non-entity) collection need the
  same explicit treatment (shift everything after the insertion point), or is that already
  obviously symmetric enough not to need its own paragraph?
