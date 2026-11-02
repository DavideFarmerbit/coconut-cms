import type {TypeCatalog} from '../types/TypeCatalog.ts';
import type {EditorDefinition} from './EditorDefinition.ts';

/**
 * The editors of one kind of thing, entries or types, by the entity type
 * each is for. A type takes the editor registered for it, else the one for
 * its nearest ancestor: one for `game:item` edits a `game:weapon` too,
 * unless `game:weapon` has its own.
 */
export class EditorRegistry {
    private readonly definitions = new Map<string, EditorDefinition>();

    /** @param kind What it edits, for error messages: "entries", "types". */
    public constructor(private readonly kind: string) {
    }

    /** Registers `definition`. Throws when another editor is registered for its type. */
    public register(definition: EditorDefinition): void {
        if (this.definitions.has(definition.type)) {
            throw new Error(`Two editors of ${this.kind} are registered for "${definition.type}".`);
        }

        this.definitions.set(definition.type, definition);
    }

    /**
     * The editor registered for `type`, or its nearest ancestor, as `catalog`
     * knows them; null when none is: then the admin's generic one edits it.
     */
    public of(type: string, catalog: TypeCatalog): EditorDefinition | null {
        for (const each of [type, ...catalog.ancestorsOf(type).map((ancestor) => ancestor.identifier)]) {
            const definition = this.definitions.get(each);
            if (definition !== undefined) {
                return definition;
            }
        }

        return null;
    }

    /** Every editor registered, in the order registered. */
    public all(): readonly EditorDefinition[] {
        return [...this.definitions.values()];
    }
}
