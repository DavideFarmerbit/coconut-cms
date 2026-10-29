import type {ApiClient} from '../public/api/ApiClient.ts';
import {EntityLabels} from '../public/properties/EntityLabels.ts';
import type {ReferenceValue} from '../public/properties/RecordValues.ts';
import type {TypeCatalog} from '../public/types/TypeCatalog.ts';

/** What coconut-cms:entities.get gives, as far as a label needs it. */
interface EntityData {
    readonly type: string;
    readonly id: string;
    readonly values: Readonly<Record<string, unknown>>;
}

/**
 * The labels of References' targets, each read once per editor however many
 * properties point at it. A target that can't be read is called by its id.
 */
export class ReferenceLabels {
    private readonly labels = new Map<string, Promise<string>>();

    public constructor(
        private readonly api: ApiClient,
        private readonly catalog: TypeCatalog,
    ) {
    }

    /** What `reference`'s target is called. */
    public of(reference: ReferenceValue): Promise<string> {
        let label = this.labels.get(reference.id);
        if (label === undefined) {
            label = this.api.call<EntityData>('coconut-cms:entities.get', {id: reference.id})
                .then((entity) => EntityLabels.of(this.catalog, entity.type, entity.id, entity.values))
                .catch(() => reference.id);
            this.labels.set(reference.id, label);
        }

        return label;
    }
}
