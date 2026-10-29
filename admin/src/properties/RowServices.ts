import type {ApiClient} from '../public/api/ApiClient.ts';
import type {EntitySession} from '../public/entities/EntitySession.ts';
import type {EntityStore} from '../public/entities/EntityStore.ts';
import type {CollapseMemory} from '../public/properties/CollapseMemory.ts';
import type {PropertyEditors} from '../public/properties/PropertyEditors.ts';
import type {ObjectValue} from '../public/properties/RecordValues.ts';
import type {TypeCatalog} from '../public/types/TypeCatalog.ts';

/** What a row is built with: its view's session and settings, and how it builds the rows under it. */
export interface RowServices {
    readonly session: EntitySession;
    readonly catalog: TypeCatalog;
    readonly api: ApiClient;
    readonly editors: PropertyEditors;
    readonly entities: EntityStore;
    /** What's collapsed, by path. */
    readonly collapse: CollapseMemory;

    /** The row of the property at `path`. */
    row(path: string): HTMLElement;

    /** The rows of `object`'s fields, at `prefix`, joined to it by `join`. */
    fields(prefix: string, object: ObjectValue, join: string): HTMLElement;

    /** The rows a plain parent row at `path` holds: those its view's filter shows under it. */
    shownUnder(path: string): HTMLElement;
}
