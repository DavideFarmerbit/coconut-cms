import {EditHistory} from '../../public/edits/EditHistory.ts';
import type {FieldRoles, FieldShape, RuleEntry, SchemaDefinitionList, TypeRoles} from '../SchemaDefinitions.ts';
import type {Conversion} from './Conversion.ts';
import {DefaultPath} from './DefaultPath.ts';
import {DraftEdit, type RecordSwap} from './DraftEdit.ts';
import {DraftIds, type FieldId, type TypeId} from './DraftIds.ts';
import {type DraftListener, DraftRecords} from './DraftRecords.ts';
import {EditRefused} from './EditRefused.ts';
import {NO_FIELD_CHOICES} from './FieldChoices.ts';
import type {FieldRecord} from './FieldRecord.ts';
import {FieldShapes, type NullabilityChange} from './FieldShapes.ts';
import type {FieldState} from './FieldState.ts';
import type {PinnedDefault} from './PinnedDefault.ts';
import {NO_TYPE_CHOICES} from './TypeChoices.ts';
import type {TypeRecord} from './TypeRecord.ts';
import type {TypeState} from './TypeState.ts';

/** A default given to a field an edit adds or retypes, in stored form: wrapped, so "none" is never a magic value. */
export interface GivenDefault {
    readonly value: unknown;
}

/**
 * What the schema editor edits: every type as a type object and every field
 * as a field object, native ones only read, each knowing what it was when
 * loaded and what it is now. Shared by every view of it, with one history:
 * each edit replaces the objects it changes, so undo puts them back. A
 * save writes what the objects became since they loaded, whatever the
 * edits that got them there. Every edit is checked as the server would:
 * one it would refuse throws EditRefused, and nothing changes.
 */
export class SchemaDraft {
    /** Every edit made, undoable, whichever view made it. */
    public readonly history = new EditHistory();

    private constructor(
        /** The schema revision it was loaded at: what its save sends back. */
        public readonly revision: number,
        private readonly records: DraftRecords,
        private readonly ids: DraftIds,
    ) {
    }

    /** The draft of the types `list` gives, as schema.get gave them. */
    public static load(list: SchemaDefinitionList): SchemaDraft {
        const records = new DraftRecords();
        const ids = new DraftIds();
        const draft = new SchemaDraft(list.revision, records, ids);

        const typeIds = new Map(list.types.map((type) => [type.identifier, ids.type()] as const));
        const typeIdOf = (identifier: string): TypeId => typeIds.get(identifier) ?? SchemaDraft.unknownType(identifier);
        for (const type of list.types) {
            const owner = typeIdOf(type.identifier);
            const fields = new Map<string, FieldId>();
            for (const [name, shape] of Object.entries(type.fields)) {
                const id = ids.field();
                const state: FieldState = {owner, name, shape, rules: type.fieldRules[name] ?? [], permission: type.fieldPermissions[name] ?? null};
                records.putField(id, {id, was: state, now: state, choices: NO_FIELD_CHOICES});
                fields.set(name, id);
            }

            const state: TypeState = {
                identifier: type.identifier,
                parent: type.parent === null ? null : typeIdOf(type.parent),
                editorExtensible: type.editorExtensible,
                fields: [...fields.values()],
                defaults: new Map(),
                prototypeRules: type.prototypeRules,
                typePermission: type.typePermission,
                uniqueGroups: type.uniqueGroups.map((group) => group.map((name) => fields.get(name) ?? SchemaDraft.unknownField(type.identifier, name))),
            };
            records.putType(owner, {id: owner, source: type.source, was: state, now: state, choices: NO_TYPE_CHOICES});
        }

        // Its paths name fields of any type, so they're read once every object is in.
        for (const type of list.types) {
            const id = typeIdOf(type.identifier);
            const defaults = new Map<string, PinnedDefault>();
            for (const [text, value] of Object.entries(type.defaults)) {
                const path = draft.pathOf(id, text);
                defaults.set(path.key, {path, value});
            }

            const record = records.type(id)!;
            const state = {...record.now!, defaults: draft.folded(defaults)};
            records.putType(id, {...record, was: state, now: state});
        }

        return draft;
    }

    /*================================================================================================================*/
    // Reading

    /** The type object `id`, deleted or not; undefined when there's none. */
    public type(id: TypeId): TypeRecord | undefined {
        return this.records.type(id);
    }

    /** The field object `id`, removed or not; undefined when there's none. */
    public field(id: FieldId): FieldRecord | undefined {
        return this.records.field(id);
    }

    /** Every type object there is now, in the order made. */
    public types(): readonly TypeRecord[] {
        return this.records.allTypes().filter((type) => type.now !== null);
    }

    /** Every type and field object, deleted and removed ones included: what a save compares. */
    public everything(): {readonly types: readonly TypeRecord[]; readonly fields: readonly FieldRecord[]} {
        return {types: this.records.allTypes(), fields: this.records.allFields()};
    }

    /** The type named `identifier` now; undefined when none is. */
    public typeNamed(identifier: string): TypeRecord | undefined {
        return this.types().find((type) => type.now!.identifier === identifier);
    }

    /** `id`'s own fields now, in order. */
    public fieldsOf(id: TypeId): readonly FieldRecord[] {
        return (this.records.type(id)?.now?.fields ?? []).map((field) => this.records.field(field)!);
    }

    /** `id`, then its parent, up to its root. */
    public chainOf(id: TypeId): readonly TypeRecord[] {
        const chain: TypeRecord[] = [];
        for (let current: TypeId | null = id; current !== null; current = this.records.type(current)?.now?.parent ?? null) {
            const type = this.records.type(current);
            if (type?.now === null || type === undefined) {
                break;
            }
            chain.push(type);
        }

        return chain;
    }

    /** Every type extending `id` now, at any depth. */
    public descendantsOf(id: TypeId): readonly TypeRecord[] {
        const direct = this.types().filter((type) => type.now!.parent === id);

        return direct.flatMap((type) => [type, ...this.descendantsOf(type.id)]);
    }

    /** The field named `name` on `id`, its own or an ancestor's; undefined when there's none. */
    public fieldNamed(id: TypeId, name: string): FieldRecord | undefined {
        return this.chainOf(id).flatMap((type) => this.fieldsOf(type.id)).find((field) => field.now!.name === name);
    }

    /** The field `text`, a stored path, starts from on `id`, read into the objects it goes through. Throws when it reaches none. */
    public pathOf(id: TypeId, text: string): DefaultPath {
        const segments: (FieldId | number)[] = [];
        let owner: TypeId | null = id;
        let item: FieldShape | null = null;
        for (const token of text.split(/[:.]/)) {
            if (/^\d+$/.test(token)) {
                segments.push(Number(token));
                owner = item?.target !== undefined && (item.kind === 'embed' || item.kind === 'owning_reference') ? this.typeNamed(item.target)?.id ?? null : null;
                item = null;
                continue;
            }

            const field: FieldRecord | undefined = owner === null ? undefined : this.reachableField(owner, token);
            if (field === undefined) {
                throw new EditRefused(`"${text}" reaches no field "${token}".`);
            }

            segments.push(field.id);
            const shape: FieldShape = field.now!.shape;
            owner = shape.target !== undefined && (shape.kind === 'embed' || shape.kind === 'owning_reference') ? this.typeNamed(shape.target)?.id ?? null : null;
            item = shape.kind === 'collection' ? shape.item ?? null : null;
        }

        return DefaultPath.of(...segments);
    }

    /** `path` as a save writes it, with the names its fields have now: "sail:area", "spares.0.size". */
    public pathText(path: DefaultPath): string {
        let text = '';
        let join = '';
        for (const segment of path.segments) {
            if (typeof segment === 'number') {
                text += `.${segment}`;
                join = '.';
                continue;
            }

            const field = this.records.field(segment)!.now ?? this.records.field(segment)!.was!;
            text += `${join}${field.name}`;
            join = field.shape.kind === 'embed' ? ':' : '.';
        }

        return text;
    }

    /**
     * How the stored field `id`'s shape changed since it loaded, the types it
     * names read as they're called now: only its nullability, a retype, or
     * null for nothing, or for a field added or removed.
     */
    public shapeChange(id: FieldId): NullabilityChange | 'retype' | null {
        const field = this.records.field(id);
        if (field?.was === null || field?.now === null || field === undefined) {
            return null;
        }

        const was = FieldShapes.retargeted(field.was.shape, (identifier) => {
            const type = this.records.allTypes().find((each) => each.was?.identifier === identifier);

            return type?.now?.identifier ?? identifier;
        });
        if (FieldShapes.same(was, field.now.shape)) {
            return null;
        }

        return FieldShapes.nullabilityChange(was, field.now.shape) ?? 'retype';
    }

    /** Tells `listener` after every edit, done, undone or redone. */
    public onChange(listener: DraftListener): void {
        this.records.onChange(listener);
    }

    /** Stops telling `listener`. */
    public offChange(listener: DraftListener): void {
        this.records.offChange(listener);
    }

    // ~Reading
    /*================================================================================================================*/
    // Types

    /** Creates a type named `identifier`, extending `parent`, or a root for null: its id. */
    public createType(identifier: string, parent: TypeId | null): TypeId {
        this.assertFreeIdentifier(identifier);
        if (this.records.allTypes().some((type) => type.was?.identifier === identifier)) {
            throw new EditRefused(`"${identifier}" named another type when the draft loaded: save its rename or delete first.`);
        }
        if (parent !== null) {
            this.assertExtensible(parent);
        }

        const id = this.ids.type();
        const now: TypeState = {identifier, parent, editorExtensible: true, fields: [], defaults: new Map(), prototypeRules: [], typePermission: null, uniqueGroups: []};
        this.commit(`Create ${identifier}`, [{kind: 'type', id, before: undefined, after: {id, source: 'editor', was: null, now, choices: NO_TYPE_CHOICES}}]);

        return id;
    }

    /** Renames the type `id` to `identifier`: every field naming it follows. */
    public renameType(id: TypeId, identifier: string): void {
        const type = this.editableType(id);
        if (type.now.identifier === identifier) {
            return;
        }
        this.assertFreeIdentifier(identifier);
        if (this.records.allTypes().some((each) => each.now === null && each.was?.identifier === identifier)) {
            throw new EditRefused(`"${identifier}" named a type this draft deletes: save the delete first.`);
        }

        const old = type.now.identifier;
        const rename = (target: string): string => target === old ? identifier : target;
        const retargeted = this.records.allFields()
            .filter((field) => field.now !== null && !FieldShapes.same(field.now.shape, FieldShapes.retargeted(field.now.shape, rename)))
            .map((field) => this.fieldSwap({...field, now: {...field.now!, shape: FieldShapes.retargeted(field.now!.shape, rename)}}));
        const renamedSelf = {...type, now: {...type.now, identifier, defaults: this.pinsNaming(type.now.defaults, rename)}};
        const repinned = this.types()
            .filter((each) => each.id !== id && each.source === 'editor')
            .map((each) => ({each, defaults: this.pinsNaming(each.now!.defaults, rename)}))
            .filter(({each, defaults}) => [...defaults].some(([key, pinned]) => pinned !== each.now!.defaults.get(key)))
            .map(({each, defaults}) => this.typeSwap({...each, now: {...each.now!, defaults}}));
        this.commit(`Rename ${old}`, [this.typeSwap(renamedSelf), ...repinned, ...retargeted]);
    }

    /** Deletes the type `id` and its own fields. One extending it is moved or deleted first. */
    public deleteType(id: TypeId): void {
        const type = this.editableType(id);
        const subtype = this.types().find((each) => each.now!.parent === id);
        if (subtype !== undefined) {
            throw new EditRefused(`"${subtype.now!.identifier}" extends "${type.now.identifier}": move or delete it first.`);
        }

        this.commit(`Delete ${type.now.identifier}`, [
            this.typeSwap({...type, now: null}),
            ...this.fieldsOf(id).map((field) => this.fieldSwap({...field, now: null})),
        ]);
    }

    /**
     * Moves the type `id` under `parent`, or makes it a root for null. It
     * and its subtypes lose the defaults they set through fields they no
     * longer inherit.
     */
    public reparent(id: TypeId, parent: TypeId | null): void {
        const type = this.editableType(id);
        if (type.now.parent === parent) {
            return;
        }
        const moving = [type, ...this.descendantsOf(id)];
        const gained = parent === null ? [] : this.chainOf(parent).flatMap((each) => this.fieldsOf(each.id));
        if (parent !== null) {
            this.assertExtensible(parent);
            if (parent === id || moving.some((each) => each.id === parent)) {
                throw new EditRefused(`"${type.now.identifier}" can't extend itself or one of its own subtypes.`);
            }
            const clash = moving.flatMap((each) => this.fieldsOf(each.id)).find((field) => gained.some((each) => each.now!.name === field.now!.name));
            if (clash !== undefined) {
                throw new EditRefused(`"${this.records.type(parent)!.now!.identifier}" or a type above it already has a field "${clash.now!.name}".`);
            }
        }

        const swaps: RecordSwap[] = [];
        for (const each of moving) {
            // What it reaches afterwards: its chain from itself up to `id`, then the new parent's.
            const chain = this.chainOf(each.id);
            const kept = new Set([...chain.slice(0, chain.findIndex((link) => link.id === id) + 1).flatMap((link) => link.now!.fields), ...gained.map((field) => field.id)]);
            const defaults = new Map([...each.now!.defaults].filter(([, pinned]) => kept.has(pinned.path.head)));
            if (each.id === id) {
                swaps.push(this.typeSwap({...each, now: {...each.now!, parent, defaults}}));
            } else if (defaults.size !== each.now!.defaults.size) {
                swaps.push(this.typeSwap({...each, now: {...each.now!, defaults}}));
            }
        }
        this.commit(`Move ${type.now.identifier}`, swaps);
    }

    /** Chooses what fills the levels the reparented type `id`'s entries gain; null for their defaults. */
    public chooseLevelInitializer(id: TypeId, initializer: Conversion | null): void {
        const type = this.editableType(id);
        this.commit(`Choose how ${type.now.identifier} fills what it gains`, [this.typeSwap({...type, choices: {...type.choices, levelInitializer: initializer}})]);
    }

    /** Gives the type `id` its own roles per action; null for none. */
    public setTypePermission(id: TypeId, roles: TypeRoles | null): void {
        const type = this.editableType(id);
        this.commit(`Change who may use ${type.now.identifier}`, [this.typeSwap({...type, now: {...type.now, typePermission: roles}})]);
    }

    /** Gives the type `id` its whole list of cross-field rules. */
    public setPrototypeRules(id: TypeId, rules: readonly RuleEntry[]): void {
        const type = this.editableType(id);
        this.commit(`Change ${type.now.identifier}'s rules`, [this.typeSwap({...type, now: {...type.now, prototypeRules: rules}})]);
    }

    /** Adds a unique group over `fields`, the type `id`'s own. */
    public addUniqueGroup(id: TypeId, fields: readonly FieldId[]): void {
        const type = this.editableType(id);
        if (fields.length === 0 || fields.some((field) => !type.now.fields.includes(field))) {
            throw new EditRefused('A unique group is over one or more of the type\'s own fields.');
        }
        if (type.now.uniqueGroups.some((group) => SchemaDraft.sameFields(group, fields))) {
            throw new EditRefused(`"${type.now.identifier}" already has that unique group.`);
        }

        this.commit(`Add a unique group to ${type.now.identifier}`, [this.typeSwap({...type, now: {...type.now, uniqueGroups: [...type.now.uniqueGroups, [...fields]]}})]);
    }

    /** Removes the type `id`'s unique group over `fields`. */
    public removeUniqueGroup(id: TypeId, fields: readonly FieldId[]): void {
        const type = this.editableType(id);
        const groups = type.now.uniqueGroups.filter((group) => !SchemaDraft.sameFields(group, fields));
        if (groups.length === type.now.uniqueGroups.length) {
            return;
        }

        this.commit(`Remove a unique group from ${type.now.identifier}`, [this.typeSwap({...type, now: {...type.now, uniqueGroups: groups}})]);
    }

    /** Pins `value`, in stored form, as the type `id`'s own default at `path`. */
    public setDefault(id: TypeId, path: DefaultPath, value: unknown): void {
        const type = this.editableType(id);
        if (this.fieldNamedById(id, path.head) === undefined) {
            throw new EditRefused(`"${type.now.identifier}" has no such field.`);
        }

        const defaults = SchemaDraft.withoutUnder(type.now.defaults, path);
        defaults.set(path.key, {path, value});
        this.commit(`Set ${this.pathText(path)}'s default`, [this.typeSwap({...type, now: {...type.now, defaults}})]);
    }

    /** Lets the type `id` follow its ancestors again at `path`, and under it. */
    public inheritDefault(id: TypeId, path: DefaultPath): void {
        const type = this.editableType(id);
        this.commit(`Inherit ${this.pathText(path)}'s default`, [this.typeSwap({...type, now: {...type.now, defaults: SchemaDraft.withoutUnder(type.now.defaults, path)}})]);
    }

    // ~Types
    /*================================================================================================================*/
    // Fields

    /** Adds a field named `name`, holding `shape`, to the type `id`: its id. Every kind but an Embed takes a default. */
    public addField(id: TypeId, name: string, shape: FieldShape, given: GivenDefault | null): FieldId {
        const type = this.editableType(id);
        this.assertFreeFieldName(id, name);
        SchemaDraft.assertDefault(shape, given, name);

        const field = this.ids.field();
        const defaults = new Map(type.now.defaults);
        if (given !== null) {
            const path = DefaultPath.of(field);
            defaults.set(path.key, {path, value: given.value});
        }

        this.commit(`Add ${name} to ${type.now.identifier}`, [
            {kind: 'field', id: field, before: undefined, after: {id: field, was: null, now: {owner: id, name, shape, rules: [], permission: null}, choices: NO_FIELD_CHOICES}},
            this.typeSwap({...type, now: {...type.now, fields: [...type.now.fields, field], defaults}}),
        ]);

        return field;
    }

    /** Removes the field `id`, with every default set through it and every unique group over it. */
    public removeField(id: FieldId): void {
        const field = this.editableField(id);
        const owner = this.editableType(field.now.owner);

        this.commit(`Remove ${field.now.name}`, [
            this.fieldSwap({...field, now: null}),
            ...this.defaultsDropped((path) => path.passes(id), [owner.id]),
            this.typeSwap({
                ...owner,
                now: {
                    ...owner.now,
                    fields: owner.now.fields.filter((each) => each !== id),
                    defaults: SchemaDraft.withoutPassing(owner.now.defaults, id),
                    uniqueGroups: owner.now.uniqueGroups.filter((group) => !group.includes(id)),
                },
            }),
        ]);
    }

    /** Renames the field `id` to `name`: every default and unique group holding it follows, by its id. */
    public renameField(id: FieldId, name: string): void {
        const field = this.editableField(id);
        if (field.now.name === name) {
            return;
        }
        this.assertFreeFieldName(field.now.owner, name, id);
        this.commit(`Rename ${field.now.name}`, [this.fieldSwap({...field, now: {...field.now, name}})]);
    }

    /**
     * Makes the field `id` hold `shape`. Its rules are cleared: they were
     * written for what it held. Every default set through it goes; `given`
     * is its new one. How its stored values reach the new shape is chosen
     * again, with chooseRetype().
     */
    public retypeField(id: FieldId, shape: FieldShape, given: GivenDefault | null): void {
        const field = this.editableField(id);
        const owner = this.editableType(field.now.owner);
        SchemaDraft.assertDefault(shape, given, field.now.name);

        const defaults = SchemaDraft.withoutPassing(owner.now.defaults, id);
        if (given !== null) {
            const path = DefaultPath.of(id);
            defaults.set(path.key, {path, value: given.value});
        }

        this.commit(`Retype ${field.now.name}`, [
            this.fieldSwap({...field, now: {...field.now, shape, rules: []}, choices: {...field.choices, retype: null}}),
            ...this.defaultsDropped((path) => path.passes(id), [owner.id]),
            this.typeSwap({...owner, now: {...owner.now, defaults}}),
        ]);
    }

    /** Chooses how the retyped field `id`'s stored values reach its new shape: a converter, "reset" to drop them, or null for not chosen. */
    public chooseRetype(id: FieldId, choice: Conversion | 'reset' | null): void {
        const field = this.editableField(id);
        this.commit(`Choose how ${field.now.name} converts`, [this.fieldSwap({...field, choices: {...field.choices, retype: choice}})]);
    }

    /** Chooses what existing entries gaining the field `id` get instead of its default; null for the default. */
    public chooseInitializer(id: FieldId, initializer: Conversion | null): void {
        const field = this.editableField(id);
        this.commit(`Choose how ${field.now.name} starts`, [this.fieldSwap({...field, choices: {...field.choices, initializer}})]);
    }

    /**
     * Moves the field `id` to `to`, up or down its owner's chain. A type no
     * longer holding it loses the defaults it set through it; `given` is
     * the default `to` sets, every kind but an Embed taking one.
     */
    public moveField(id: FieldId, to: TypeId, given: GivenDefault | null): void {
        const field = this.editableField(id);
        const from = this.editableType(field.now.owner);
        const target = this.editableType(to);
        if (from.id === to) {
            return;
        }
        const related = this.chainOf(to).some((type) => type.id === from.id) || this.chainOf(from.id).some((type) => type.id === to);
        if (!related) {
            throw new EditRefused(`"${from.now.identifier}" and "${target.now.identifier}" aren't one chain: a field moves only up or down its own.`);
        }
        SchemaDraft.assertDefault(field.now.shape, given, field.now.name);
        this.assertFreeFieldName(to, field.now.name, id);

        // Moved down, only `to` and its subtypes still hold it.
        const holding = new Set([to, ...this.descendantsOf(to).map((type) => type.id)]);
        const losing = this.types().filter((type) => !holding.has(type.id)).map((type) => type.id);
        const targetDefaults = new Map(target.now.defaults);
        if (given !== null) {
            const path = DefaultPath.of(id);
            targetDefaults.set(path.key, {path, value: given.value});
        }

        this.commit(`Move ${field.now.name} to ${target.now.identifier}`, [
            this.fieldSwap({...field, now: {...field.now, owner: to}}),
            ...this.defaultsDropped((path) => path.passes(id), [from.id, to], losing),
            this.typeSwap({
                ...from,
                now: {
                    ...from.now,
                    fields: from.now.fields.filter((each) => each !== id),
                    uniqueGroups: from.now.uniqueGroups.filter((group) => !group.includes(id)),
                    defaults: holding.has(from.id) ? from.now.defaults : SchemaDraft.withoutPassing(from.now.defaults, id),
                },
            }),
            this.typeSwap({...target, now: {...target.now, fields: [...target.now.fields, id], defaults: targetDefaults}}),
        ]);
    }

    /** Gives the field `id` its whole list of rules. */
    public setFieldRules(id: FieldId, rules: readonly RuleEntry[]): void {
        const field = this.editableField(id);
        this.commit(`Change ${field.now.name}'s rules`, [this.fieldSwap({...field, now: {...field.now, rules}})]);
    }

    /** Gives the field `id` its roles; null leaves it open. */
    public setFieldPermission(id: FieldId, roles: FieldRoles | null): void {
        const field = this.editableField(id);
        this.commit(`Change who may use ${field.now.name}`, [this.fieldSwap({...field, now: {...field.now, permission: roles}})]);
    }

    // ~Fields
    /*================================================================================================================*/
    // Checks and records

    /** Runs the edit replacing `swaps`' objects, as one undoable step named `label`. */
    private commit(label: string, swaps: readonly RecordSwap[]): void {
        this.history.do(new DraftEdit(label, this.records, swaps));
    }

    /** `after` replacing the type object it was. */
    private typeSwap(after: TypeRecord): RecordSwap {
        return {kind: 'type', id: after.id, before: this.records.type(after.id), after};
    }

    /** `after` replacing the field object it was. */
    private fieldSwap(after: FieldRecord): RecordSwap {
        return {kind: 'field', id: after.id, before: this.records.field(after.id), after};
    }

    /**
     * The swaps dropping, from every type `among` names, or every type when
     * none, each default whose path `drops`, `skipping` the types the edit
     * replaces itself.
     */
    private defaultsDropped(drops: (path: DefaultPath) => boolean, skipping: readonly TypeId[], among: readonly TypeId[] | null = null): RecordSwap[] {
        return this.types()
            .filter((type) => !skipping.includes(type.id) && (among === null || among.includes(type.id)))
            .filter((type) => [...type.now!.defaults.values()].some((pinned) => drops(pinned.path)))
            .map((type) => this.typeSwap({...type, now: {...type.now!, defaults: new Map([...type.now!.defaults].filter(([, pinned]) => !drops(pinned.path)))}}));
    }

    /** The type `id`, there now and made by the editor: only those change. */
    private editableType(id: TypeId): TypeRecord & {readonly now: TypeState} {
        const type = this.records.type(id);
        if (type?.now === null || type === undefined) {
            throw new EditRefused('There is no such type.');
        }
        if (type.source !== 'editor') {
            throw new EditRefused(`"${type.now.identifier}" is declared by the code: the editor changes editor-made types only.`);
        }

        return type as TypeRecord & {readonly now: TypeState};
    }

    /** The field `id`, there now, its owner made by the editor. */
    private editableField(id: FieldId): FieldRecord & {readonly now: FieldState} {
        const field = this.records.field(id);
        if (field?.now === null || field === undefined) {
            throw new EditRefused('There is no such field.');
        }
        this.editableType(field.now.owner);

        return field as FieldRecord & {readonly now: FieldState};
    }

    /** The field `field` on `id`, its own or an ancestor's; undefined when it holds none such. */
    private fieldNamedById(id: TypeId, field: FieldId): FieldRecord | undefined {
        return this.chainOf(id).flatMap((type) => this.fieldsOf(type.id)).find((each) => each.id === field);
    }

    /** The field named `name` reached through `owner`: its own or an ancestor's, else a subtype's, for an owned slot pinned to one. */
    private reachableField(owner: TypeId, name: string): FieldRecord | undefined {
        return this.fieldNamed(owner, name)
            ?? this.descendantsOf(owner).flatMap((type) => this.fieldsOf(type.id)).find((field) => field.now!.name === name);
    }

    /** Throws unless `identifier` is a "namespace:key" no type has now. */
    private assertFreeIdentifier(identifier: string): void {
        if (!/^[^:\s]+:[^:\s]+$/.test(identifier)) {
            throw new EditRefused(`"${identifier}" is no "namespace:key" identifier.`);
        }
        if (this.typeNamed(identifier) !== undefined) {
            throw new EditRefused(`A type "${identifier}" already exists.`);
        }
    }

    /** Throws unless the type `id` may be extended by an editor-made one. */
    private assertExtensible(id: TypeId): void {
        const parent = this.records.type(id)?.now;
        if (parent === null || parent === undefined) {
            throw new EditRefused('There is no such type.');
        }
        if (!parent.editorExtensible) {
            throw new EditRefused(`"${parent.identifier}" doesn't allow editor-made subtypes.`);
        }
    }

    /** Throws unless `name` is a field name the server takes, free on `id`'s chain and its subtypes, `except` the field being renamed or moved. */
    private assertFreeFieldName(id: TypeId, name: string, except: FieldId | null = null): void {
        if (name === '' || /[:.]/.test(name) || /^\d+$/.test(name) || name.startsWith('_') || name.includes('__')) {
            throw new EditRefused(`"${name}" is no field name: not empty, no ":" or ".", not all digits, no leading "_" or "__".`);
        }

        const holders = [...this.chainOf(id), ...this.descendantsOf(id)];
        const taken = holders.flatMap((type) => this.fieldsOf(type.id)).find((field) => field.id !== except && field.now!.name === name);
        if (taken !== undefined) {
            throw new EditRefused(`"${this.records.type(taken.now!.owner)!.now!.identifier}" already has a field "${name}".`);
        }
    }

    /**
     * `defaults` with each one set through a type reference naming a type
     * `rename` renames named as renamed: one type, a Collection's list of
     * them, or one item of such a Collection. The others are kept as they are.
     */
    private pinsNaming(defaults: ReadonlyMap<string, PinnedDefault>, rename: (identifier: string) => string): Map<string, PinnedDefault> {
        const renamed = new Map(defaults);
        for (const [key, pinned] of defaults) {
            const segments = pinned.path.segments;
            const last = segments[segments.length - 1]!;
            const fieldId = typeof last === 'number' ? segments[segments.length - 2] : last;
            const shape = typeof fieldId === 'string' ? this.records.field(fieldId)?.now?.shape : undefined;
            const names = shape?.kind === 'type_reference' || (shape?.kind === 'collection' && shape.item?.kind === 'type_reference');
            if (!names) {
                continue;
            }

            const value = typeof pinned.value === 'string' ? rename(pinned.value) : Array.isArray(pinned.value) ? pinned.value.map((each: unknown) => typeof each === 'string' ? rename(each) : each) : pinned.value;
            if (JSON.stringify(value) !== JSON.stringify(pinned.value)) {
                renamed.set(key, {path: pinned.path, value});
            }
        }

        return renamed;
    }

    /**
     * `defaults` as stored, with each Collection of plain items held as one
     * list, as an edit pins it: stored, it's a count at the field's path and
     * an item under it per index. One of Embeds or owned objects stays as
     * stored, by path.
     */
    private folded(defaults: ReadonlyMap<string, PinnedDefault>): Map<string, PinnedDefault> {
        const folded = new Map(defaults);
        for (const pinned of defaults.values()) {
            const last = pinned.path.segments[pinned.path.segments.length - 1]!;
            const shape = typeof last === 'number' ? undefined : this.records.field(last)?.now?.shape;
            const item = shape?.kind === 'collection' ? shape.item?.kind : undefined;
            if (item === undefined || item === 'embed' || item === 'owning_reference' || typeof pinned.value !== 'number') {
                continue;
            }

            const items = Array.from({length: pinned.value}, (_, index) => {
                const key = DefaultPath.of(...pinned.path.segments, index).key;
                const value = defaults.get(key)?.value ?? null;
                folded.delete(key);

                return value;
            });
            folded.set(pinned.path.key, {path: pinned.path, value: items});
        }

        return folded;
    }

    /** Throws unless `given` fits a field holding `shape`: every kind but an Embed needs one, a Collection a list. */
    private static assertDefault(shape: FieldShape, given: GivenDefault | null, name: string): void {
        if (shape.kind === 'embed') {
            if (given !== null) {
                throw new EditRefused(`"${name}" is an Embed: its defaults are its fields', set by path.`);
            }

            return;
        }
        if (given === null) {
            throw new EditRefused(`"${name}" needs a default: what new entries hold.`);
        }
        if (shape.kind === 'collection') {
            if (!Array.isArray(given.value)) {
                throw new EditRefused(`"${name}" is a Collection: its default is a list of its items, [] for none.`);
            }
            const item = shape.item?.kind;
            if (given.value.length > 0 && (item === 'embed' || item === 'owning_reference')) {
                throw new EditRefused(`"${name}" holds Embeds or owned objects: a default with items isn't offered yet, only [].`);
            }
        }
    }

    /** `defaults` without the ones at `path` or under it. */
    private static withoutUnder(defaults: ReadonlyMap<string, PinnedDefault>, path: DefaultPath): Map<string, PinnedDefault> {
        return new Map([...defaults].filter(([, pinned]) => !pinned.path.startsWith(path)));
    }

    /** `defaults` without the ones set through the field `field`. */
    private static withoutPassing(defaults: ReadonlyMap<string, PinnedDefault>, field: FieldId): Map<string, PinnedDefault> {
        return new Map([...defaults].filter(([, pinned]) => !pinned.path.passes(field)));
    }

    /** Whether `a` and `b` are over the same fields, in any order. */
    private static sameFields(a: readonly FieldId[], b: readonly FieldId[]): boolean {
        return a.length === b.length && a.every((field) => b.includes(field));
    }

    private static unknownType(identifier: string): never {
        throw new Error(`schema.get named a type it didn't give: "${identifier}".`);
    }

    private static unknownField(type: string, name: string): never {
        throw new Error(`"${type}"'s unique group names a field it doesn't have: "${name}".`);
    }

    // ~Checks and records
}
