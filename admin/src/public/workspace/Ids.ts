/**
 * The workspace's ids, each its own type: a tool's id can't be passed where a
 * tab's is expected. A tool's and a tab type's id are identifiers,
 * "namespace:key", stable across releases, since saved layouts hold them.
 */
declare const brand: unique symbol;

/** A registered tool's id: "coconut-cms:types". */
export type ToolId = `${string}:${string}` & {readonly [brand]: 'ToolId'};

/** A registered tab type's id: "coconut-cms:type". */
export type TabTypeId = `${string}:${string}` & {readonly [brand]: 'TabTypeId'};

/** An open tab's id, made when it opens. */
export type TabId = string & {readonly [brand]: 'TabId'};

/** `id` as a ToolId. */
export function toolId(id: `${string}:${string}`): ToolId {
    return id as ToolId;
}

/** `id` as a TabTypeId. */
export function tabTypeId(id: `${string}:${string}`): TabTypeId {
    return id as TabTypeId;
}

/** `id` as a TabId. */
export function tabId(id: string): TabId {
    return id as TabId;
}
