import type {ValueDescription} from '../types/TypeDescription.ts';
import type {PropertyEditorClass} from './PropertyEditor.ts';
import type {PropertyMatch} from './PropertyMatch.ts';

/**
 * Every property editor, by what it's registered for: admin.properties. The
 * admin registers its defaults by kind; projects and plugins register
 * customizations. For a property, the most specific match wins: its field,
 * then the type of value it holds, then its scalar type, then its kind.
 * Registering again for the same match replaces the editor.
 */
export class PropertyEditors {
    private readonly fields = new Map<string, PropertyEditorClass>();
    private readonly valueTypes = new Map<string, PropertyEditorClass>();
    private readonly scalars = new Map<string, PropertyEditorClass>();
    private readonly kinds = new Map<string, PropertyEditorClass>();

    /** Shows what `match` matches with `editor`. */
    public register(match: PropertyMatch, editor: PropertyEditorClass): void {
        if ('field' in match) {
            this.fields.set(PropertyEditors.fieldKey(match.type, match.field), editor);
        } else if ('valueType' in match) {
            this.valueTypes.set(match.valueType, editor);
        } else if ('scalar' in match) {
            this.scalars.set(match.scalar, editor);
        } else {
            this.kinds.set(match.kind, editor);
        }
    }

    /**
     * The editor of a property holding `value`: for field `field` of a type
     * whose chain, itself first, is `holderChain`; or for a Collection's item,
     * with no field. Undefined when nothing matches, not even its kind.
     */
    public resolve(holderChain: readonly string[], field: string | null, value: ValueDescription): PropertyEditorClass | undefined {
        if (field !== null) {
            for (const type of holderChain) {
                const editor = this.fields.get(PropertyEditors.fieldKey(type, field));
                if (editor !== undefined) {
                    return editor;
                }
            }
        }

        const byValueType = value.kind === 'collection' || value.target === null ? undefined : this.valueTypes.get(value.target);
        const byScalar = value.kind === 'scalar' && value.scalarType !== null ? this.scalars.get(value.scalarType) : undefined;

        return byValueType ?? byScalar ?? this.kinds.get(value.kind);
    }

    /** The key of field `field` of `type`. */
    private static fieldKey(type: string, field: string): string {
        return `${type}#${field}`;
    }
}
