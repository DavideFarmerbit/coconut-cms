import type {EntityData} from './EntityData.ts';

/** How a condition compares a field's value, as coconut-cms:entities.query names it. */
export type ConditionOperator = 'equal' | 'notEqual' | 'greater' | 'greaterOrEqual' | 'less' | 'lessOrEqual' | 'in' | 'isNull' | 'isNotNull' | 'like';

/** One condition of a query: the field's path, how it compares, the values in stored form. */
export interface EntityCondition {
    readonly path: string;
    readonly operator: ConditionOperator;
    readonly values?: readonly unknown[];
}

/** One sort of a query: the field's path, and its direction, ascending unless said. */
export interface EntitySort {
    readonly path: string;
    readonly direction?: 'ascending' | 'descending';
}

/** What coconut-cms:entities.query takes: a type, its subtypes' entities found too. */
export interface EntityQuery {
    readonly type: string;
    /** All must hold. */
    readonly where?: readonly EntityCondition[];
    /** First first; ties go by id, oldest first. */
    readonly orderBy?: readonly EntitySort[];
    /** At most this many a page. */
    readonly limit?: number;
    /** The nextCursor of the page before; left out for the first. */
    readonly after?: string;
}

/** One page of a query's entities, in order. */
export interface EntityPage {
    readonly items: readonly EntityData[];
    /** What `after` takes for the next page; null on the last. */
    readonly nextCursor: string | null;
}
