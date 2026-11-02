import type {ServiceContext} from './ServiceContext.ts';

/**
 * A kind of service: a class with a static create(). Asked for by it, the
 * first time for its user, the admin creates the one every view shares.
 *
 *     class Orders {
 *         public static create(context: ServiceContext): Orders {
 *             return new Orders(context.api);
 *         }
 *     }
 *     const orders = context.service(Orders);
 */
export interface ServiceType<T> {
    /** Its class name: what an error names it by. */
    readonly name: string;

    /** The one instance for the user signed in now, built from `context`. */
    create(context: ServiceContext): T;
}
