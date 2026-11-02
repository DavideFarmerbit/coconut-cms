import type {FieldId, TypeId} from './DraftIds.ts';
import type {FieldRecord} from './FieldRecord.ts';
import type {TypeRecord} from './TypeRecord.ts';

/**
 * Where every type and field is at one point of a save's operations, as
 * the server will have them: their names, parents and owners. Starts as
 * stored; the writer moves it along with each operation it writes, so
 * each names things as they are when it runs.
 */
export class ChainState {
    private readonly identifiers = new Map<TypeId, string>();
    private readonly parents = new Map<TypeId, TypeId | null>();
    private readonly created = new Set<TypeId>();
    private readonly owners = new Map<FieldId, TypeId>();
    private readonly names = new Map<FieldId, string>();

    /** The state of `types` and `fields` as stored: what has no stored state isn't there yet. */
    public static stored(types: readonly TypeRecord[], fields: readonly FieldRecord[]): ChainState {
        const state = new ChainState();
        for (const type of types) {
            if (type.was !== null) {
                state.identifiers.set(type.id, type.was.identifier);
                state.parents.set(type.id, type.was.parent);
            }
        }
        for (const field of fields) {
            if (field.was !== null) {
                state.owners.set(field.id, field.was.owner);
                state.names.set(field.id, field.was.name);
            }
        }

        return state;
    }

    /** The type `id`'s identifier now. */
    public typeName(id: TypeId): string {
        return this.identifiers.get(id) ?? ChainState.missing(id);
    }

    /** The field `id`'s name now. */
    public fieldName(id: FieldId): string {
        return this.names.get(id) ?? ChainState.missing(id);
    }

    /** The type declaring the field `id` now. */
    public ownerOf(id: FieldId): TypeId {
        return this.owners.get(id) ?? ChainState.missing(id);
    }

    /** Whether the type `id` is one the save creates: it has no entries. */
    public isCreated(id: TypeId): boolean {
        return this.created.has(id);
    }

    /** Whether `type` is `ancestor`, or extends it at any depth, now. */
    public isA(type: TypeId, ancestor: TypeId): boolean {
        for (let current: TypeId | null = type; current !== null; current = this.parents.get(current) ?? null) {
            if (current === ancestor) {
                return true;
            }
        }

        return false;
    }

    /** Whether `a` and `b` are on one chain now: a field moves only between such. */
    public related(a: TypeId, b: TypeId): boolean {
        return this.isA(a, b) || this.isA(b, a);
    }

    /** Renames the type `id`. */
    public renameType(id: TypeId, identifier: string): void {
        this.identifiers.set(id, identifier);
    }

    /** Renames the field `id`. */
    public renameField(id: FieldId, name: string): void {
        this.names.set(id, name);
    }

    /** Adds the type `id`, created by the save. */
    public createType(id: TypeId, identifier: string, parent: TypeId | null): void {
        this.identifiers.set(id, identifier);
        this.parents.set(id, parent);
        this.created.add(id);
    }

    /** Moves the type `id` under `parent`. */
    public reparent(id: TypeId, parent: TypeId | null): void {
        this.parents.set(id, parent);
    }

    /** Adds the field `id`, or moves it, to `owner`. */
    public place(id: FieldId, owner: TypeId, name: string): void {
        this.owners.set(id, owner);
        this.names.set(id, name);
    }

    private static missing(id: string): never {
        throw new Error(`"${id}" isn't there at this point of the save.`);
    }
}
