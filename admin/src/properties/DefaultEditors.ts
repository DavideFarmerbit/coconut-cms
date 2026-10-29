import type {PropertyEditors} from '../public/properties/PropertyEditors.ts';
import {BoolEditor} from './editors/BoolEditor.ts';
import {CollectionEditor} from './editors/CollectionEditor.ts';
import {DateEditor} from './editors/DateEditor.ts';
import {EmbedEditor} from './editors/EmbedEditor.ts';
import {EnumEditor} from './editors/EnumEditor.ts';
import {NumberEditor} from './editors/NumberEditor.ts';
import {OwnedEditor} from './editors/OwnedEditor.ts';
import {ReferenceEditor} from './editors/ReferenceEditor.ts';
import {TextEditor} from './editors/TextEditor.ts';

/** The admin's own property editors: a default for every kind, and for each scalar type. */
export class DefaultEditors {
    /** Registers them with `editors`, the way any customization registers. */
    public static register(editors: PropertyEditors): void {
        editors.register({kind: 'scalar'}, TextEditor);
        editors.register({scalar: 'int'}, NumberEditor);
        editors.register({scalar: 'float'}, NumberEditor);
        editors.register({scalar: 'decimal'}, NumberEditor);
        editors.register({scalar: 'bool'}, BoolEditor);
        editors.register({scalar: 'date'}, DateEditor);
        editors.register({scalar: 'date_time'}, DateEditor);
        editors.register({scalar: 'enum'}, EnumEditor);
        editors.register({kind: 'value_object'}, TextEditor);
        editors.register({kind: 'embed'}, EmbedEditor);
        editors.register({kind: 'owning_reference'}, OwnedEditor);
        editors.register({kind: 'reference'}, ReferenceEditor);
        editors.register({kind: 'collection'}, CollectionEditor);
    }
}
