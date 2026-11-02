import {ChainState} from './ChainState.ts';
import type {Conversion} from './Conversion.ts';
import {DefaultPath} from './DefaultPath.ts';
import type {FieldId, TypeId} from './DraftIds.ts';
import {EditRefused} from './EditRefused.ts';
import type {FieldRecord} from './FieldRecord.ts';
import type {FieldState} from './FieldState.ts';
import type {PinnedDefault} from './PinnedDefault.ts';
import type {SaveProblem} from './SaveProblem.ts';
import type {SchemaDraft} from './SchemaDraft.ts';
import type {SchemaOperationData} from './SchemaOperationData.ts';
import type {TypeRecord} from './TypeRecord.ts';
import type {TypeState} from './TypeState.ts';
import type {WrittenSave} from './WrittenSave.ts';

/** A stored type or field the draft keeps: both its states are there. */
type Kept<T, S> = T & {readonly was: S; readonly now: S};

/**
 * Writes a schema draft as the operations of one editor save: what its
 * editor-made objects became since they loaded, whatever the edits that
 * got them there. Phases run in a fixed order, each making the next
 * possible:
 *
 * 1. clear what would block a later step: cross-field rules over own fields
 *    that change, unique groups going away;
 * 2. free names: removed fields, then deleted types, subtypes first;
 * 3. rename: every type at once, then every field of one type at once;
 * 4. create types, parents first;
 * 5. retype fields, or change only their nullability;
 * 6. move fields along the chain they're on, reparent types parents first,
 *    move the rest, then delete the types that waited on those;
 * 7. add fields;
 * 8. set rules, permissions and unique groups, with the final names;
 * 9. set or inherit defaults, with the final names.
 *
 * What it can't write yet is a SaveProblem: a retype with no conversion
 * chosen, a field needing a default, a move between unrelated types.
 */
export class DraftWriter {
    private readonly operations: SchemaOperationData[] = [];
    private readonly problems: SaveProblem[] = [];
    /** Where things are as the operations written so far leave them. */
    private readonly chain: ChainState;
    /** Every editor-made type, deleted and created ones included. */
    private readonly types: readonly TypeRecord[];
    /** Every field of an editor-made type, removed and added ones included. */
    private readonly fields: readonly FieldRecord[];
    /** Defaults an addField, moveField or retypeField gave: no setDefault repeats them. By type and path key. */
    private readonly given = new Set<string>();
    /** Types whose cross-field rules were cleared first. */
    private readonly cleared = new Set<TypeId>();
    /** Fields written a retypeField: the server cleared their rules. */
    private readonly retyped = new Set<FieldId>();

    private constructor(private readonly draft: SchemaDraft) {
        const everything = draft.everything();
        this.types = everything.types.filter((type) => type.source === 'editor');
        // One added and removed again was never there.
        this.fields = everything.fields.filter((field) => {
            const state = field.was ?? field.now;

            return state !== null && draft.type(state.owner)?.source === 'editor';
        });
        this.chain = ChainState.stored(everything.types, everything.fields);
    }

    /** `draft` written as one save's operations, with what keeps it from being sent. */
    public static write(draft: SchemaDraft): WrittenSave {
        const writer = new DraftWriter(draft);
        const late = writer.lateDeletions();
        writer.clear();
        writer.free(late);
        writer.rename();
        writer.create();
        writer.reshape();
        writer.move(late);
        writer.add();
        writer.setRulesAndPermissions();
        writer.setDefaults();

        return {revision: draft.revision, operations: writer.operations, problems: writer.problems};
    }

    /*================================================================================================================*/
    // Phases

    /** Clears what a later step would trip on: cross-field rules over own fields that change, unique groups going away. */
    private clear(): void {
        for (const type of this.keptTypes()) {
            if (type.was.prototypeRules.length > 0 && (!DraftWriter.equal(type.was.prototypeRules, type.now.prototypeRules) || this.ownFieldsChange(type))) {
                this.write({op: 'setPrototypeRules', type: this.chain.typeName(type.id), rules: []});
                this.cleared.add(type.id);
            }
        }

        for (const type of this.keptTypes()) {
            for (const group of type.was.uniqueGroups) {
                const kept = type.now.uniqueGroups.some((each) => DraftWriter.sameFields(each, group));
                // A group over a field removed or moved away goes with it.
                const goesAnyway = group.some((field) => this.draft.field(field)!.now?.owner !== type.id);
                if (!kept && !goesAnyway) {
                    this.write({op: 'removeUniqueGroup', type: this.chain.typeName(type.id), fields: group.map((field) => this.chain.fieldName(field))});
                }
            }
        }
    }

    /** Removes fields and deletes the types that wait on nothing, subtypes first: their names are free from here on. */
    private free(late: ReadonlySet<TypeId>): void {
        for (const field of this.fields) {
            const owner = field.was === null ? null : this.draft.type(field.was.owner)!;
            if (owner === null || field.now !== null || (owner.now === null && !late.has(owner.id))) {
                continue;
            }
            this.write({op: 'removeField', type: this.chain.typeName(owner.id), field: this.chain.fieldName(field.id)});
        }

        for (const type of this.deletedDeepestFirst().filter((each) => !late.has(each.id))) {
            this.write({op: 'deleteType', type: this.chain.typeName(type.id)});
        }
    }

    /** Renames every type at once, then the fields of each type at once: swaps and loops need nothing else. */
    private rename(): void {
        const types = this.keptTypes().filter((type) => type.was.identifier !== type.now.identifier);
        if (types.length > 0) {
            this.write({op: 'renameTypes', renames: Object.fromEntries(types.map((type) => [type.was.identifier, type.now.identifier]))});
            types.forEach((type) => this.chain.renameType(type.id, type.now.identifier));
        }

        const byOwner = new Map<TypeId, Kept<FieldRecord, FieldState>[]>();
        for (const field of this.keptFields().filter((each) => each.was.name !== each.now.name)) {
            const owner = this.chain.ownerOf(field.id);
            byOwner.set(owner, [...byOwner.get(owner) ?? [], field]);
        }
        for (const [owner, fields] of byOwner) {
            this.write({op: 'renameFields', type: this.chain.typeName(owner), renames: Object.fromEntries(fields.map((field) => [field.was.name, field.now.name]))});
            fields.forEach((field) => this.chain.renameField(field.id, field.now.name));
        }
    }

    /** Creates each new type, its parent first when that's new too. */
    private create(): void {
        const pending = new Set(this.types.filter((type) => type.was === null && type.now !== null).map((type) => type.id));
        const createOne = (id: TypeId): void => {
            if (!pending.delete(id)) {
                return;
            }
            const now = this.draft.type(id)!.now!;
            if (now.parent !== null) {
                createOne(now.parent);
            }
            this.write({op: 'createType', type: now.identifier, parent: now.parent === null ? null : this.chain.typeName(now.parent)});
            this.chain.createType(id, now.identifier, now.parent);
        };
        [...pending].forEach(createOne);
    }

    /** Retypes each stored field holding something else, or only makes it optional or required. */
    private reshape(): void {
        for (const field of this.keptFields()) {
            const change = this.draft.shapeChange(field.id);
            const owner = this.chain.ownerOf(field.id);
            if (change === null) {
                continue;
            }
            if (change !== 'retype') {
                this.write({op: change === 'optional' ? 'makeOptional' : 'makeRequired', type: this.chain.typeName(owner), field: this.chain.fieldName(field.id)});
                continue;
            }

            // Retyped, its rules are cleared, written or not yet.
            this.retyped.add(field.id);
            const choice = field.choices.retype;
            if (choice === null) {
                this.problem(`Choose how "${field.now.name}" converts its stored values, or reset them.`, null, field.id);
                continue;
            }

            // The default its owner pins now; a field moved away takes the one it moves to with it.
            const own = this.pinOf(owner, field.id);
            const pinned = own ?? this.pinOf(field.now.owner, field.id);
            if (choice === 'reset' && pinned === undefined && field.now.shape.kind !== 'embed') {
                this.problem(`"${field.now.name}" needs a default: its stored values are reset.`, null, field.id);
                continue;
            }

            this.write({
                op: 'retypeField',
                type: this.chain.typeName(owner),
                field: {name: this.chain.fieldName(field.id), ...field.now.shape},
                ...(choice === 'reset' ? {} : {converter: DraftWriter.conversion(choice)}),
                ...(pinned === undefined ? {} : {default: pinned.value}),
            });
            if (own !== undefined) {
                this.given.add(DraftWriter.givenKey(owner, DefaultPath.of(field.id)));
            }
        }
    }

    /**
     * Moves the fields that move along the chain they're on now, reparents
     * types, parents first along the final tree so no step makes a loop,
     * moves the fields that needed that, and deletes the types that waited
     * on it all.
     */
    private move(late: ReadonlySet<TypeId>): void {
        const moved = this.keptFields().filter((field) => field.was.owner !== field.now.owner);
        const waiting = moved.filter((field) => !this.chain.related(this.chain.ownerOf(field.id), field.now.owner));
        moved.filter((field) => !waiting.includes(field)).forEach((field) => this.moveField(field));

        const reparented = this.keptTypes().filter((type) => type.was.parent !== type.now.parent);
        reparented.sort((a, b) => this.draft.chainOf(a.id).length - this.draft.chainOf(b.id).length);
        for (const type of reparented) {
            const initializer = type.choices.levelInitializer;
            this.write({
                op: 'reparent',
                type: this.chain.typeName(type.id),
                parent: type.now.parent === null ? null : this.chain.typeName(type.now.parent),
                ...(initializer === null ? {} : {initializer: DraftWriter.conversion(initializer)}),
            });
            this.chain.reparent(type.id, type.now.parent);
        }

        for (const field of waiting) {
            if (this.chain.related(this.chain.ownerOf(field.id), field.now.owner)) {
                this.moveField(field);
            } else {
                const from = this.chain.typeName(this.chain.ownerOf(field.id));
                this.problem(`"${field.now.name}" moves from "${from}" to "${this.draft.type(field.now.owner)!.now!.identifier}", which aren't one chain before or after types move.`, null, field.id);
            }
        }

        for (const type of this.deletedDeepestFirst().filter((each) => late.has(each.id))) {
            this.write({op: 'deleteType', type: this.chain.typeName(type.id)});
        }
    }

    /** Adds each new field, with the default its type pins and what existing entries get. */
    private add(): void {
        for (const type of this.types.filter((each) => each.now !== null)) {
            for (const field of this.draft.fieldsOf(type.id).filter((each) => each.was === null)) {
                const now = field.now!;
                const pinned = this.pinOf(type.id, field.id);
                if (pinned === undefined && now.shape.kind !== 'embed') {
                    this.problem(`"${now.name}" needs a default: what new entries hold.`, null, field.id);
                    continue;
                }

                // A type the save creates has no entries to fill.
                const initializer = this.chain.isCreated(type.id) ? null : field.choices.initializer;
                this.write({
                    op: 'addField',
                    type: type.now!.identifier,
                    field: {name: now.name, ...now.shape},
                    ...(pinned === undefined ? {} : {default: pinned.value}),
                    ...(initializer === null ? {} : {initializer: DraftWriter.conversion(initializer)}),
                });
                this.chain.place(field.id, type.id, now.name);
                if (pinned !== undefined) {
                    this.given.add(DraftWriter.givenKey(type.id, pinned.path));
                }
            }
        }
    }

    /** Sets each rule list, permission and unique group that isn't what the server holds after the steps before. */
    private setRulesAndPermissions(): void {
        for (const field of this.fields.filter((each) => each.now !== null)) {
            const now = field.now!;
            const type = this.draft.type(now.owner)!.now!.identifier;
            const rules = field.was === null || this.retyped.has(field.id) ? [] : field.was.rules;
            if (!DraftWriter.equal(rules, now.rules)) {
                this.write({op: 'setFieldRules', type, field: now.name, rules: now.rules});
            }
            if (!DraftWriter.equal(field.was?.permission ?? null, now.permission)) {
                this.write({op: 'setFieldPermission', type, field: now.name, read: now.permission?.read ?? null, write: now.permission?.write ?? null});
            }
        }

        for (const type of this.types.filter((each) => each.now !== null)) {
            const now = type.now!;
            const roles = now.typePermission;
            if (!DraftWriter.equal(type.was?.typePermission ?? null, roles)) {
                this.write({op: 'setTypePermission', type: now.identifier, read: roles?.read ?? null, create: roles?.create ?? null, update: roles?.update ?? null, delete: roles?.delete ?? null});
            }

            const rules = type.was === null || this.cleared.has(type.id) ? [] : type.was.prototypeRules;
            if (!DraftWriter.equal(rules, now.prototypeRules)) {
                this.write({op: 'setPrototypeRules', type: now.identifier, rules: now.prototypeRules});
            }

            const kept = (type.was?.uniqueGroups ?? []).filter((group) => group.every((field) => this.draft.field(field)!.now?.owner === type.id));
            for (const group of now.uniqueGroups.filter((each) => !kept.some((old) => DraftWriter.sameFields(old, each)))) {
                this.write({op: 'addUniqueGroup', type: now.identifier, fields: group.map((field) => this.draft.field(field)!.now!.name)});
            }
        }
    }

    /**
     * Pins each default that changed, or that goes through a field retyped
     * or moved, unless an operation gave it; inherits each one gone that
     * the type still reaches. One it no longer reaches went with its field.
     */
    private setDefaults(): void {
        for (const type of this.types.filter((each) => each.now !== null)) {
            const now = type.now!;
            const was = type.was?.defaults ?? new Map<string, PinnedDefault>();
            for (const [key, pinned] of now.defaults) {
                const before = was.get(key);
                const same = before !== undefined && DraftWriter.equal(before.value, pinned.value) && !this.reshaped(pinned.path);
                if (!same && !this.given.has(DraftWriter.givenKey(type.id, pinned.path))) {
                    this.write({op: 'setDefault', type: now.identifier, path: this.draft.pathText(pinned.path), value: pinned.value});
                }
            }

            for (const [key, pinned] of was) {
                if (!now.defaults.has(key) && this.reaches(type.id, pinned.path)) {
                    this.write({op: 'inheritDefault', type: now.identifier, path: this.draft.pathText(pinned.path)});
                }
            }
        }
    }

    // ~Phases
    /*================================================================================================================*/
    // Helpers

    /** Writes the moveField taking `field` to where it ends, from wherever it is now. */
    private moveField(field: Kept<FieldRecord, FieldState>): void {
        const from = this.chain.ownerOf(field.id);
        const to = field.now.owner;
        const pinned = this.pinOf(to, field.id);
        if (pinned === undefined && field.now.shape.kind !== 'embed') {
            this.problem(`"${field.now.name}" needs a default on "${this.draft.type(to)!.now!.identifier}", where it moves.`, null, field.id);

            return;
        }

        // Only a move up has entries gaining it, and only when both types have entries.
        const up = this.chain.isA(from, to);
        const initializer = up && !this.chain.isCreated(from) && !this.chain.isCreated(to) ? field.choices.initializer : null;
        this.write({
            op: 'moveField',
            type: this.chain.typeName(from),
            field: this.chain.fieldName(field.id),
            to: this.chain.typeName(to),
            ...(pinned === undefined ? {} : {default: pinned.value}),
            ...(initializer === null ? {} : {initializer: DraftWriter.conversion(initializer)}),
        });
        this.chain.place(field.id, to, this.chain.fieldName(field.id));
        if (pinned !== undefined) {
            this.given.add(DraftWriter.givenKey(to, pinned.path));
        }
    }

    /**
     * The deleted types that wait for a move or a reparent: a field of
     * theirs moves to another type, or a subtype of theirs stays. Deleted
     * first, they'd take it with them.
     */
    private lateDeletions(): Set<TypeId> {
        const late = new Set<TypeId>();
        for (const type of this.deletedDeepestFirst()) {
            const fieldLeaves = this.fields.some((field) => field.was?.owner === type.id && field.now !== null);
            const subtypeStays = this.types.some((each) => each.was?.parent === type.id && (each.now !== null || late.has(each.id)));
            if (fieldLeaves || subtypeStays) {
                late.add(type.id);
            }
        }

        return late;
    }

    /** Whether any of `type`'s own fields changes: what its cross-field rules are checked against. */
    private ownFieldsChange(type: TypeRecord): boolean {
        return this.fields.some((field) => {
            const own = field.was?.owner === type.id || field.now?.owner === type.id;
            const changes = field.was === null || field.now === null || field.was.name !== field.now.name || field.was.owner !== field.now.owner || this.draft.shapeChange(field.id) !== null;

            return own && changes;
        });
    }

    /** Whether `path` goes through a field written a retype, or moved. */
    private reshaped(path: DefaultPath): boolean {
        return path.segments.some((segment) => {
            if (typeof segment === 'number') {
                return false;
            }
            const field = this.draft.field(segment)!;

            return this.retyped.has(segment) || (field.was !== null && field.now !== null && field.was.owner !== field.now.owner);
        });
    }

    /** Whether the type `id` reaches `path` once saved: the server keeps a default only there. */
    private reaches(id: TypeId, path: DefaultPath): boolean {
        // Its first field is the type's own or inherited, never a subtype's.
        if (!this.draft.chainOf(id).some((type) => type.now!.fields.includes(path.head))) {
            return false;
        }
        try {
            return this.draft.pathOf(id, this.draft.pathText(path)).key === path.key;
        } catch (error) {
            if (error instanceof EditRefused) {
                return false;
            }
            throw error;
        }
    }

    /** The default the type `id` pins now at the field `field` itself; undefined when it pins none there. */
    private pinOf(id: TypeId, field: FieldId): PinnedDefault | undefined {
        return this.draft.type(id)?.now?.defaults.get(DefaultPath.of(field).key);
    }

    /** Every stored editor-made type the draft keeps. */
    private keptTypes(): Kept<TypeRecord, TypeState>[] {
        return this.types.filter((type): type is Kept<TypeRecord, TypeState> => type.was !== null && type.now !== null);
    }

    /** Every stored field of an editor-made type the draft keeps. */
    private keptFields(): Kept<FieldRecord, FieldState>[] {
        return this.fields.filter((field): field is Kept<FieldRecord, FieldState> => field.was !== null && field.now !== null);
    }

    /** Every stored type the draft deletes, each before the type it extended. */
    private deletedDeepestFirst(): TypeRecord[] {
        const depth = (type: TypeRecord): number => {
            let count = 0;
            for (let parent = type.was!.parent; parent !== null; parent = this.draft.type(parent)!.was!.parent) {
                count++;
            }

            return count;
        };

        return this.types.filter((type) => type.was !== null && type.now === null).sort((a, b) => depth(b) - depth(a));
    }

    private write(operation: SchemaOperationData): void {
        this.operations.push(operation);
    }

    private problem(message: string, type: TypeId | null, field: FieldId | null): void {
        this.problems.push({message, type, field});
    }

    /** `conversion` as an operation names it: its identifier as `kind`, beside its parameters. */
    private static conversion(conversion: Conversion): Record<string, unknown> {
        return {...conversion.parameters, kind: conversion.kind};
    }

    /** What tells a default `type` pins at `path` from any other. */
    private static givenKey(type: TypeId, path: DefaultPath): string {
        return `${type} ${path.key}`;
    }

    /** Whether `a` and `b` are over the same fields, in any order. */
    private static sameFields(a: readonly FieldId[], b: readonly FieldId[]): boolean {
        return a.length === b.length && a.every((field) => b.includes(field));
    }

    /** Whether `a` and `b` are equal values: lists item by item, objects key by key, in any key order. */
    private static equal(a: unknown, b: unknown): boolean {
        if (a === b) {
            return true;
        }
        if (typeof a !== 'object' || typeof b !== 'object' || a === null || b === null || Array.isArray(a) !== Array.isArray(b)) {
            return false;
        }
        const keys = Object.keys(a);

        return keys.length === Object.keys(b).length && keys.every((key) => DraftWriter.equal((a as Record<string, unknown>)[key], (b as Record<string, unknown>)[key]));
    }

    // ~Helpers
}
