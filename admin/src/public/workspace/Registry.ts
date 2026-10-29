/**
 * Every definition of one kind, by id: what admin.tools and admin.tabTypes
 * are. The admin's own tools register here the way a plugin's do. Two
 * definitions with one id are refused.
 */
export class Registry<Id extends string, Definition extends {readonly id: Id}> {
    private readonly definitions = new Map<Id, Definition>();

    /** @param kind What it holds, for error messages: "tool", "tab type". */
    public constructor(private readonly kind: string) {
    }

    /** Registers `definition`. Throws when its id is taken. */
    public register(definition: Definition): void {
        if (this.definitions.has(definition.id)) {
            throw new Error(`Two of the admin's ${this.kind}s are named "${definition.id}".`);
        }

        this.definitions.set(definition.id, definition);
    }

    /** The definition named `id`; undefined when there's none. */
    public get(id: Id): Definition | undefined {
        return this.definitions.get(id);
    }

    /** Every definition, in the order registered. */
    public all(): readonly Definition[] {
        return [...this.definitions.values()];
    }

    /** Every id registered. */
    public ids(): ReadonlySet<Id> {
        return new Set(this.definitions.keys());
    }
}
