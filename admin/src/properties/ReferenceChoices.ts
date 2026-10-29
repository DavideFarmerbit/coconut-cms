import type {ChoicePage, ChoiceSource} from '../dom/ChoiceSource.ts';
import type {ApiClient} from '../public/api/ApiClient.ts';
import {EntityLabels} from '../public/properties/EntityLabels.ts';
import type {ReferenceValue} from '../public/properties/RecordValues.ts';
import type {TypeCatalog} from '../public/types/TypeCatalog.ts';

/** One page of coconut-cms:entities.query, as far as the choices read it. */
interface EntityPage {
    readonly items: readonly {readonly type: string; readonly id: string; readonly values: Readonly<Record<string, unknown>>}[];
    readonly nextCursor: string | null;
}

/**
 * The choices of a Reference's target: the target type's standalone
 * entities, subtypes included, by label, a page at a time, narrowed by a
 * search on the label field when it's a String. Each choice's key is the
 * entity's id.
 */
export class ReferenceChoices implements ChoiceSource<ReferenceValue> {
    /** How many entities a page lists. */
    private static readonly PAGE = 20;

    public readonly searchable: boolean;
    public readonly searchDelay = 250;
    private readonly labelField: string | null;
    private cursor: string | null = null;

    public constructor(
        private readonly api: ApiClient,
        private readonly catalog: TypeCatalog,
        private readonly type: string,
    ) {
        this.labelField = EntityLabels.fieldOf(catalog, type);
        this.searchable = this.labelField !== null;
    }

    public async page(search: string, next: boolean): Promise<ChoicePage<ReferenceValue>> {
        if (!next) {
            this.cursor = null;
        }

        const text = search.trim();
        const page = await this.api.call<EntityPage>('coconut-cms:entities.query', {
            type: this.type,
            limit: ReferenceChoices.PAGE,
            ...(this.cursor === null ? {} : {after: this.cursor}),
            ...(this.labelField === null ? {} : {orderBy: [{path: this.labelField}]}),
            ...(this.labelField === null || text === '' ? {} : {where: [{path: this.labelField, operator: 'like', values: [`%${text}%`]}]}),
        });
        this.cursor = page.nextCursor;

        return {
            choices: page.items.map((entity) => ({
                key: entity.id,
                label: EntityLabels.of(this.catalog, entity.type, entity.id, entity.values),
                value: {type: entity.type, id: entity.id},
            })),
            hasMore: page.nextCursor !== null,
        };
    }
}
