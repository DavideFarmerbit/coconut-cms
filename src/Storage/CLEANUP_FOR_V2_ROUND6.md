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

### 2. Flipping `unique` on an already-existing field is still unaddressed

`AUDIT.md` §6 named three un-migratable toggles on an existing field: `queryable`, `unique`,
`Ownership`. V2 explicitly closes two of them — `queryable` is retired outright ("No blobs"),
`Ownership` flipping is covered by the Shared↔Owned retype case ("Migrations and schema
mutation"). `unique` is never mentioned again anywhere after Phase 1.3 introduces it
("Uniqueness: `unique` flag, real `UNIQUE` constraint, friendly pre-check"). `SchemaEditor`'s
mutation set for editor-created fields is explicitly closed to `addField()`/`dropField()`/
`rename()`/`retype()` ("Nothing else, ever, at the field level") — none of those four is "add
or remove a uniqueness constraint on a field that already has data," and retype is about
changing a field's *kind*, not a constraint on top of the same kind.

**Open:**
- Does an editor-created field ever need to gain/lose uniqueness after creation, or is this
  deliberately out of scope (same posture as "no index-toggling operation yet" for the
  `queryable`-retirement discussion)?
- If in scope: adding `unique` to a populated column needs the same "friendly pre-check" Phase
  1.3 already has for creation, just run against existing rows instead of an empty table — is
  that the whole mechanism, or does it need its own capture/duplicate-resolution path the way
  retype does?

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
