import type {EntityData} from './EntityData.ts';

/** What the EntityStore tells its listeners: an entity created or updated, with what the API gave back, or one deleted. */
export type EntityChange =
    | {readonly kind: 'created' | 'updated'; readonly entity: EntityData}
    | {readonly kind: 'deleted'; readonly id: string};

/** Told of each EntityChange. */
export type EntityListener = (change: EntityChange) => void;
