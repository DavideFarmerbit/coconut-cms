# Storage / Editor v2 — Cleanup Before Building (Round 16)

**Status: done (2026-10-13).** One item, surfaced while verifying Round 15's own edits held
up against the rest of the document — not something Round 15 caused, but adjacent material
its own wording had to touch (see `CLEANUP_FOR_V2_ROUND15.md` item 1's enumerated stamping
walks). Resolved. Worked per [[feedback_coconut_cms_development_workflow]].

## Real inconsistencies

### 1. Prototype-level `rename()` is never roadmap-tested, and its own described fixup list may itself be wrong — **resolved (2026-10-13)**

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

**Resolved: `owner_field` is wrong in both places and is dropped from both; the missing
roadmap proof is added to Phase 6.2, alongside field-level `rename()`, not as its own
bullet.**

`owner_field` holds a field name, not an identifier, scoped to whatever class declares the
`OwningReference`/Owned-`Collection` field — renaming a prototype's own identifier string
renames no field, on any class, anywhere, so there's nothing for `owner_field` to need
fixing up over. The correct fixup list for prototype-level `rename()` has three items:
`prototypes.parent`, `entities.concrete_identifier`, and every other prototype's
`reference()`/`embed()`/`collection()` pointer naming the old identifier. Substitution's
citation of this list is wrong the same way, since it reuses the list verbatim.

Prototype-level `rename()` gets its roadmap proof in Phase 6.2, not its own bullet, since
"Rename — no attributes, ever, for either kind" already groups field-level and
prototype-level rename under one mechanism — an explicit mapping, never inferred, never an
attribute — and Phase 6.2 is where field-level `rename()` already gets that same proof, not
Phase 6.3 (reparenting) or 6.4 (deletion/substitution), which cover structural changes to
the CTI chain rather than an identifier rename.

Folded into `ARCHITECTURE_V2.md`'s "Rename" section (the prototype-rename paragraph, and
substitution's citation of its fixup list) and `ROADMAP_V2.md` Phase 6.2 (a new clause on
the operation itself, and a new "Done when" assertion proving the three-item fixup end to
end and proving `owner_field` stays untouched).
