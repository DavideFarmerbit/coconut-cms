import type {FieldId, TypeId} from './DraftIds.ts';
import type {FieldRecord} from './FieldRecord.ts';
import type {SchemaDraft} from './SchemaDraft.ts';
import type {TypeRecord} from './TypeRecord.ts';

/** What the draft changes on one editor-made type, in words. */
export interface TypeChanges {
    readonly type: TypeId;
    /** Its identifier now; the one it had, for a type deleted. */
    readonly title: string;
    /** Each change, as a short sentence. */
    readonly changes: readonly string[];
}

/**
 * What a draft changes since it loaded, type by type, in words: what the
 * Schema changes panel lists. It compares each object's `was` with its
 * `now`, so a change undone, or made and made back, isn't there.
 */
export class DraftChanges {
    private constructor(private readonly draft: SchemaDraft) {
    }

    /** Every editor-made type `draft` changes, in the order made, with its changes. */
    public static of(draft: SchemaDraft): TypeChanges[] {
        const reader = new DraftChanges(draft);

        return draft.everything().types
            .filter((type) => type.source === 'editor' && (type.was !== null || type.now !== null))
            .map((type) => ({type: type.id, title: (type.now ?? type.was)!.identifier, changes: reader.changesOf(type)}))
            .filter((each) => each.changes.length > 0);
    }

    /** What changes on `type`: itself, then its fields. A type deleted is only that. */
    private changesOf(type: TypeRecord): string[] {
        const {was, now} = type;
        if (now === null) {
            return ['Deleted, with its own fields.'];
        }
        if (was === null) {
            return [this.created(type), ...this.fieldChanges(type)];
        }

        const changes: string[] = [];
        if (was.identifier !== now.identifier) {
            changes.push(`Renamed from ${was.identifier}.`);
        }
        if (was.parent !== now.parent) {
            changes.push(now.parent === null ? 'Made a root type.' : `Moved under ${this.identifierOf(now.parent)}.`);
        }
        if (!DraftChanges.equal(was.typePermission, now.typePermission)) {
            changes.push('Permissions changed.');
        }
        if (!DraftChanges.equal(was.prototypeRules, now.prototypeRules)) {
            changes.push('Cross-field rules changed.');
        }
        for (const group of now.uniqueGroups.filter((each) => !was.uniqueGroups.some((old) => DraftChanges.sameFields(old, each)))) {
            changes.push(`Unique group added over ${this.namesOf(group)}.`);
        }
        for (const group of was.uniqueGroups.filter((each) => !now.uniqueGroups.some((kept) => DraftChanges.sameFields(kept, each)))) {
            changes.push(`Unique group removed over ${this.namesOf(group)}.`);
        }
        for (const [key, pinned] of now.defaults) {
            // A field added comes with its default: adding it says so.
            const added = this.draft.field(pinned.path.head)?.was === null;
            const before = was.defaults.get(key);
            if (!added && (before === undefined || !DraftChanges.equal(before.value, pinned.value))) {
                changes.push(`Default of ${this.draft.pathText(pinned.path)} set to ${JSON.stringify(pinned.value)}.`);
            }
        }
        for (const [key, pinned] of was.defaults) {
            if (!now.defaults.has(key)) {
                changes.push(`Default of ${this.draft.pathText(pinned.path)} no longer set here.`);
            }
        }

        return [...changes, ...this.fieldChanges(type)];
    }

    /** How a type the draft created reads. */
    private created(type: TypeRecord): string {
        const parent = type.now!.parent;

        return parent === null ? 'Created, as a root type.' : `Created, extending ${this.identifierOf(parent)}.`;
    }

    /** What changes on the fields `type` declares now, or declared when loaded. */
    private fieldChanges(type: TypeRecord): string[] {
        const fields = this.draft.everything().fields.filter((field) => field.now?.owner === type.id || (field.now === null && field.was?.owner === type.id));

        return fields.flatMap((field) => this.fieldChangesOf(field));
    }

    /** What changes on `field`. */
    private fieldChangesOf(field: FieldRecord): string[] {
        const {was, now} = field;
        if (was === null) {
            return now === null ? [] : [`Field ${now.name} added.`];
        }
        if (now === null) {
            return [`Field ${was.name} removed.`];
        }

        const changes: string[] = [];
        if (was.name !== now.name) {
            changes.push(`Field ${was.name} renamed to ${now.name}.`);
        }
        if (was.owner !== now.owner) {
            changes.push(`Field ${now.name} moved here from ${this.identifierOf(was.owner)}.`);
        }
        const shape = this.draft.shapeChange(field.id);
        if (shape !== null) {
            changes.push(shape === 'retype' ? `Field ${now.name} retyped.` : `Field ${now.name} made ${shape}.`);
        }
        if (!DraftChanges.equal(was.rules, now.rules)) {
            changes.push(`Field ${now.name}'s rules changed.`);
        }
        if (!DraftChanges.equal(was.permission, now.permission)) {
            changes.push(`Field ${now.name}'s permissions changed.`);
        }

        return changes;
    }

    /** The type `id`'s identifier now, or the one it had. */
    private identifierOf(id: TypeId): string {
        const type = this.draft.type(id);

        return (type?.now ?? type?.was)?.identifier ?? id;
    }

    /** The names of `fields`, as they're called now, joined. */
    private namesOf(fields: readonly FieldId[]): string {
        return fields.map((id) => {
            const field = this.draft.field(id);

            return (field?.now ?? field?.was)?.name ?? id;
        }).join(', ');
    }

    /** Whether `a` and `b` are over the same fields, in any order. */
    private static sameFields(a: readonly FieldId[], b: readonly FieldId[]): boolean {
        return a.length === b.length && a.every((field) => b.includes(field));
    }

    /** Whether `a` and `b` are equal values, compared as their JSON. */
    private static equal(a: unknown, b: unknown): boolean {
        return JSON.stringify(a) === JSON.stringify(b);
    }
}
