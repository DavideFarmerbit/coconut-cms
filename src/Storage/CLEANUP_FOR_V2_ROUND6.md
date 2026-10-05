# Storage / Editor v2 — Cleanup Before Building (Round 6)

**Status: open.** A sixth audit pass over `ARCHITECTURE_V2.md`/`ROADMAP_V2.md`, done after
Round 5's items were folded back into both documents, this time cross-referencing
`AUDIT.md` directly against the claim in `ARCHITECTURE_V2.md`'s own opening paragraph that
"most of its open items are resolved here." Ordered by how much it changes what gets
built, not alphabetically. Each item below is still open — gets a **Decided** note and
folded into both documents as we close it, one at a time, the same way Rounds 1-5 did.

## Missing pieces

### 1. `AUDIT.md` item 13 — `EntityManager`/`SchemaEditor` table-map desync — has no resolution anywhere in either document

`AUDIT.md` §13 is, by its own text, the biggest single bug the old system had: `EntityManager::$tables`
is frozen at construction while `SchemaEditor::$tables` is a separate, mutable map, so a
`SchemaEditor` mutation made earlier in a request is invisible to any `Repository`/`Query`
already in hand, and rebuilding `EntityManager` to pick up the change silently discards its
`IdentityMap`. The audit's own framing: "not 'undo is incomplete for this,' but 'using both
halves back to back doesn't work at all' without a manual rebuild step nobody has designed
yet."

`ARCHITECTURE_V2.md` describes `PrototypeRegistry` as the one generic interface
(`fieldsOf()`/`instantiate()`/`chainOf()`) that `Repository`, `ChangesetFlusher`, `Query`,
and `SchemaEditor` all consult — but never states whether that registry resolves live,
per-call (so a `SchemaEditor` write is immediately visible to everything else in the same
request) or is snapshotted once and handed around (reproducing the exact bug). It also never
revisits whether rebuilding/refreshing a registry is expected to preserve or discard the
`IdentityMap`. I grepped both documents and all `CLEANUP_FOR_V2*.md` rounds for "table map,"
"cache," "snapshot," "stale," and "refresh" in this context — nothing addresses it. Given
the severity AUDIT.md assigns this item, and the opening paragraph's claim that "most" audit
items are resolved, this is the one glaring exception that needs an explicit answer, not a
silent carry-over.

**Open:**
- **How does `PrototypeRegistry` actually hold/route between the two identifier kinds in
  the first place?** Phase 1.1 says "one implementation for native classes via reflection,
  written so a second, editor-created implementation can be added in Phase 6 with zero
  change to any caller," and Phase 6.1 calls editor-created "the second identifier kind the
  registry interface has supported since Phase 1" — both say a caller never needs to know
  which kind it's holding, but neither says *how* a single call resolves that: is a native
  identifier always self-describing as native (e.g. always a `::class` string) so one object
  can branch on the identifier's own shape and consult reflection or the DB accordingly, or
  is there some other dispatch nobody's designed? This has to be answered before "does it
  cache" is even a well-posed question about *one* component rather than an unknown number
  of them.
- Does `PrototypeRegistry` resolve per-call against live, DB-backed state for the
  editor-created identifier kind (making this a non-issue by construction, since there's
  nothing to go stale), or does something still cache a resolved shape/table-map per
  request or per process?
- If per-call/live, does `IdentityMap` need any awareness of a `SchemaEditor` mutation that
  changes the shape of an identifier it's already holding instances of (e.g. a field just got
  dropped out from under an already-loaded entity)?
- Where does this get written down — a new subsection of "Namespaces and migration path" or
  "Shape comes from a neutral descriptor," or a new short section of its own?

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
