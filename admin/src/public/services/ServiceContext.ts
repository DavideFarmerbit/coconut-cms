import type {ApiClient} from '../api/ApiClient.ts';
import type {Auth} from '../auth/Auth.ts';
import type {User} from '../auth/User.ts';
import type {ServiceType} from './ServiceType.ts';

/** What a service is created with. */
export interface ServiceContext {
    /** The client every call to the backend goes through. */
    readonly api: ApiClient;
    /** Who's signed in: to register a sign-out guard. */
    readonly auth: Auth;
    /** The user it's created for: it's disposed of when they sign out. */
    readonly user: User;

    /** Another service, for the same user. */
    service<T>(type: ServiceType<T>): T;
}
