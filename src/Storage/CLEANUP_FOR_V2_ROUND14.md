# Storage / Editor v2 — Cleanup Before Building (Round 14)

**Status: in progress.** A fourteenth audit pass over `ARCHITECTURE_V2.md`/`ROADMAP_V2.md`.
Checked the two documents against each other for inconsistencies, missing pieces, and design
flaws. Also checked the standalone-document rule ([[feedback_v2_docs_standalone]]): no stray
references to pre-rewrite docs/concepts, and the roadmap cites only the architecture — clean,
aside from one note below that isn't being treated as a violation. Two items below. Working one
at a time per [[feedback_coconut_cms_development_workflow]]. Ordered by how much each one
changes what gets built.

## Real inconsistencies

### 1. `entities.owner`'s sentinel default has no row for the FK to point at — **resolved (2026-10-13)**

"Global entity identity" (line 186) declares `owner` as "not nullable, self-referencing FK to
`entities.id`, `ON DELETE RESTRICT`, defaulting to the nil UUID... when the row has no real
owner." A real database FK constraint requires the referenced value to match an existing row's
`id`. Neither document ever has anything insert a row into `entities` with
`id = 00000000-0000-0000-0000-000000000000` — there's no sentinel row for the nil UUID to
reference.

Since every standalone/root row gets `owner = <nil UUID>`, and standalone rows are the *only*
kind that exist until Phase 3.3 introduces Owned relationships, this isn't a rare edge case: as
specified, the very first insert in `ROADMAP_V2.md` Phase 1.2 (lines 73-76, which tests "every
row gets the sentinel pair") would violate the FK constraint immediately.

Open question: does this need an actual seeded `entities` row for the sentinel, or should
`owner`'s FK be qualified somehow (deferred, partial index, app-level enforcement instead of a
real constraint)? The latter would cut against "Enforcement is always a real database
constraint, never an application-level check" (line 456-458), which the document leans on
elsewhere to justify not needing a race-prone pre-check — so a seeded sentinel row looks like the
smaller change, but worth deciding deliberately rather than defaulting to it.

**Resolved: the nil UUID resolves to one real, seeded row — a reserved native class, `Cms`,
whose own `owner`/`owner_field` point at itself.** Discussed and rejected: making `owner`
nullable instead, which would undo Round 13 item 3's fix (a `NULL` default breaks the
`Unique`-group guarantee for standalone rows via SQL's `NULL != NULL`, which is exactly why
`owner` was made `NOT NULL` with a sentinel in the first place). A bare sentinel row with no
resolvable type was also considered and rejected: it would need its own reserved
`concrete_identifier` value, and every generic walk over `entities` (`Query`, admin list
views, the dangling-reference auditor) would need to learn to exclude it. Making it a real,
ordinary native class instead means nothing needs to learn to skip it — a list view for any
*other* prototype never returns it, and a list view for `Cms` itself correctly returns
exactly one row.

Added to "Global entity identity" (new paragraph after the `entities` column list) and
"Reserved sentinel values" (reworded "no real row will ever get" to account for the one
deliberate exception). Three details made explicit in the writeup, since each would otherwise
be left for a reader to work out on their own: `Cms`'s `id` is a fixed constant seeded once at
registration/schema-sync time, the one deliberate exception to every other entity's
freshly-generated, round-trip-free `id`; the self-reference is never flagged as a cycle by
`WriteExecutor`, since that check builds edges from `TempId` placeholders and `Cms`'s seed
operation names its own `id` as an already-known literal, not a placeholder, so there's no
edge to find a cycle in; and `owner_field = ""` staying reserved (never a real declared field
name) is what keeps every standalone row sharing `(owner=Cms, owner_field="")` from becoming a
backdoor `OwningReference`-style relationship — it's still a pure uniqueness-index trick, not a
real relationship, even though it now points at a real row. `Cms` starts out with no fields of
its own; nothing currently needs it to hold anything, though its own Owned fields are exactly
where CMS-level singleton data would land later if a need for that shows up.

`ROADMAP_V2.md` Phase 1.2 got a new bullet seeding `Cms` as part of the same unconditional
`entities`-table-build step (before any other class's table, since every other row's `owner`
FK needs it to already exist), and its "Done when" clause now asserts the FK is genuinely
enforced — a standalone row's insert succeeds because `Cms`'s row already exists at that id,
not because the constraint happens to be unchecked.

### 2. Revoking `editorExtensible` is handled as if it makes the parent link unresolvable, but nothing in the design actually makes that true

"Reparenting" (lines 976-1008) states that both "revoking `#[Entity]`'s `editorExtensible` flag"
and "deleting a prototype with live editor-created subclasses" invalidate the parent link of
every direct editor-created subclass, relying on `chainOf()`'s truncation behavior: "doesn't
throw when a stored parent identifier fails to resolve — it truncates the chain there" (lines
962-963).

Truncation only fires when an identifier becomes genuinely unresolvable. Deleting a prototype
produces exactly that: the identifier stops resolving. Revoking `editorExtensible` does not —
the native class still exists, `class_exists()` still succeeds, nothing about the flag's value
affects whether the identifier resolves. The `editorExtensible` check itself is also explicitly
scoped to attach-time only: "This check only applies when the candidate parent is native... The
check reads the native parent's own attribute at the moment the attachment is attempted" (lines
911-917) — nothing re-reads it on an ongoing `chainOf()` walk.

So the "native-triggered: ... needs review" handling both documents describe for this trigger
(`ARCHITECTURE_V2.md` lines 984-992, `ROADMAP_V2.md` lines 696-726) has no mechanism that would
actually invoke it. As specified, revoking `editorExtensible` on a class with existing attached
subclasses has no effect on them at all — they keep resolving normally, deploy step or not.

Open question: does `editorExtensible` revocation need to actually do something to already-attached
subclasses (e.g. `chainOf()` or the deploy step explicitly re-checking it for existing links, not
just at attach-time), or was grandfathering existing subclasses the actual intent all along —
in which case the "native-triggered: needs review" bullet is describing a trigger that should be
dropped, and only prototype deletion keeps that handling?
