import type {DefaultPath} from './DefaultPath.ts';

/** A default a type sets itself, at its path, in stored form: what setDefault pins. */
export interface PinnedDefault {
    readonly path: DefaultPath;
    readonly value: unknown;
}
