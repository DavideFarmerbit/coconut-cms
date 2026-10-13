# Storage / Editor v2 — Cleanup Before Building (Round 16)

**Status: in progress.** One item, surfaced while verifying Round 15's own edits held up
against the rest of the document — not something Round 15 caused, but adjacent material its
own wording had to touch (see `CLEANUP_FOR_V2_ROUND15.md` item 1's enumerated stamping
walks). Not yet resolved.

## Real inconsistencies

### 1. Prototype-level `rename()` is never roadmap-tested, and its own described fixup list may itself be wrong

"Rename — no attributes, ever, for either kind" (`ARCHITECTURE_V2.md` line 697) says:

> Prototype-level rename works the same way and fixes up every stored reference to the old
> identifier: `prototypes.parent`, `entities.concrete_identifier`, every other prototype's
> `reference()`/`embed()`/`collection()` pointing at it, and any `owner_field` values.

Three of those four make sense: `prototypes.parent`, `entities.concrete_identifier`, and
every `reference()`/`embed()`/`collection()` pointer all genuinely *store the identifier
string* somewhere, so a rename of that identifier has to fix each of them up.
`owner_field` doesn't store an identifier at all — "Global entity identity" defines it as
holding a *field name* ("`owner_field`... disambiguates which Owned relationship put this
row here"), scoped to whatever class *declares* the `OwningReference`/Owned-`Collection`
field. Renaming a prototype's own identifier string doesn't rename any field, on any class,
anywhere — so there's nothing for `owner_field` to need fixing up over.

The one place `owner_field` genuinely *does* need fixing up is a few paragraphs later, for a
completely different operation: "Renaming a field that declares an `OwningReference` or an
Owned `Collection` updates `entities.owner_field` for every existing row at that
relationship." That's a *field* rename, not a *prototype-identifier* rename. This reads like
that field-rename consequence got carried into the prototype-rename paragraph's own list by
mistake — the same kind of leftover an earlier LLM-drafted pass produced elsewhere in this
document's history (Round 9's "closed menu" framing, Round 12's stale "never/conditional/
always classification" reference). Substitution (`deletePrototype()` with a replacement)
inherits the same four-item list verbatim where it says it's "reusing the exact
reference-fixup list prototype-rename already performs" — so if this is wrong, it's wrong in
two places, not one.

Separately, and regardless of which four-or-three-item version is correct:
**`ROADMAP_V2.md` never names or tests prototype-level `rename()` at all.** Phase 6.2's own
`rename()` bullet and every one of its "Done when" assertions are about *field*-level
rename — propagating to `#[Embed]` sites, updating `owner_field` for an `OwningReference`
field's own rename, renaming a collection's dedicated table. Nothing anywhere exercises
renaming a *prototype's own identifier* and checking that `prototypes.parent`,
`entities.concrete_identifier`, and every referencing prototype's own pointer actually get
fixed up.

Open questions:
1. Is `owner_field` values" genuinely wrong in both places it appears (prototype-rename's
   own paragraph, and substitution's citation of it), and should it be dropped from both?
2. Once the correct fixup list is settled, which phase should prove prototype-level
   `rename()` end to end — 6.2, alongside field-level `rename()`, or its own bullet since
   it's a structurally different operation (fixing up other prototypes' own stored pointers,
   never the renamed prototype's own data)?
