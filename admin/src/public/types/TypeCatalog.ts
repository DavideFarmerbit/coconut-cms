import type {TypeDescription} from './TypeDescription.ts';

/**
 * Every entity type the user may read, as coconut-cms:types.list gave
 * them, with their hierarchy worked out: a type's ancestors, its subtypes,
 * the types of a namespace. A readable type's ancestors are readable too,
 * so the whole chain is always here. Reached through admin.typeCatalog().
 */
export class TypeCatalog {
    private constructor(private readonly types: ReadonlyMap<string, TypeDescription>) {
    }

    /** The catalog of `types`. */
    public static of(types: readonly TypeDescription[]): TypeCatalog {
        return new TypeCatalog(new Map(types.map((type) => [type.identifier, type])));
    }

    /** The namespace of `identifier`: "default" for "default:demo_car". */
    public static namespaceOf(identifier: string): string {
        return identifier.slice(0, Math.max(0, identifier.indexOf(':')));
    }

    /** The key of `identifier`: "demo_car" for "default:demo_car". */
    public static keyOf(identifier: string): string {
        return identifier.slice(identifier.indexOf(':') + 1);
    }

    /** The type `identifier` names; undefined when the user may not read it, or there's none. */
    public get(identifier: string): TypeDescription | undefined {
        return this.types.get(identifier);
    }

    /** Every type, sorted by identifier. */
    public all(): readonly TypeDescription[] {
        return [...this.types.values()].sort((a, b) => a.identifier.localeCompare(b.identifier));
    }

    /** Every type, by namespace, namespaces and types sorted. */
    public byNamespace(): ReadonlyMap<string, readonly TypeDescription[]> {
        const groups = new Map<string, TypeDescription[]>();
        for (const type of this.all()) {
            const namespace = TypeCatalog.namespaceOf(type.identifier);
            groups.set(namespace, [...(groups.get(namespace) ?? []), type]);
        }

        return new Map([...groups.entries()].sort(([a], [b]) => a.localeCompare(b)));
    }

    /** `identifier`'s ancestors, its parent first, up to its root. */
    public ancestorsOf(identifier: string): readonly TypeDescription[] {
        const ancestors: TypeDescription[] = [];
        for (let parent = this.types.get(identifier)?.parent ?? null; parent !== null; parent = this.types.get(parent)?.parent ?? null) {
            const type = this.types.get(parent);
            if (type === undefined || ancestors.includes(type)) {
                break;
            }
            ancestors.push(type);
        }

        return ancestors;
    }

    /** The types extending `identifier` directly, sorted. */
    public subtypesOf(identifier: string): readonly TypeDescription[] {
        return this.all().filter((type) => type.parent === identifier);
    }
}
